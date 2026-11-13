#include <string.h>

#include "bf_core.h"

#define BF_UNLIKELY(x) __builtin_expect(!!(x), 0)
#define BF_TAG(h)      ((h) & UINT64_C(0xFFFFFFFF00000000))

/* Read-only stand-in until the first keyed group: every probe of it finds an empty slot. */
static const uint64_t bf_no_slots[1] = { 0 };

#if defined(BF_FUZZ_HASH_SHIM)
bool bf_fuzz_identical_hash;
uint32_t bf_fuzz_group_batch = BF_GB_MAX;
size_t bf_fuzz_group_min_slots = BF_GB_SLOTS_MIN;
/*
 * Hash 0, the stored hash of a NULL group: one that wrongly entered the slots would sit in the home slot of every key
 * and carry its tag, so a lookup of int key 0 or of "" would reach it and merge into it.
 */
# define BF_SHIM(h) (bf_fuzz_identical_hash ? UINT64_C(0) : (h))
#else
# define BF_SHIM(h) (h)
#endif

static inline bool bf_groups_empty(const bf_groups *g)
{
	return g->slots == (const uint64_t *)bf_no_slots;
}

/* Per-group array bytes: hash, the key (ikey, or off and len), count, and the sum when there is one. */
static uint64_t bf_groups_row_bytes(const bf_groups *g)
{
	return 8 + (g->int_keys ? 8 : 8 + 4) + 8 + (g->has_sum ? 8 : 0);
}

/* Whether the capacities after a growth stay within maxGroupBytes (SPEC 6.4); overflow is refused. */
static bool bf_groups_within(const bf_run *r, size_t nslots, uint32_t cap, size_t arena_cap)
{
	uint64_t total, part;

	if (__builtin_mul_overflow((uint64_t)nslots, (uint64_t)sizeof(uint64_t), &total)
		|| __builtin_mul_overflow((uint64_t)cap, bf_groups_row_bytes(&r->groups), &part)
		|| __builtin_add_overflow(total, part, &total)
		|| __builtin_add_overflow(total, (uint64_t)arena_cap, &total)) {
		return false;
	}
	return total <= r->max_group_bytes;
}

/* Limit, then memory_limit preflight, for a growth whose new parts total bytes. Row context: the row is committed. */
static int bf_groups_preflight(bf_run *r, size_t nslots, uint32_t cap, size_t arena_cap, size_t bytes)
{
	if (!bf_groups_within(r, nslots, cap, arena_cap)) {
		return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
	}
	if (!r->host->mem_fits(r->host->ud, bytes)) {
		return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
	}
	return 0;
}

static inline size_t bf_groups_nslots(const bf_groups *g)
{
	return bf_groups_empty(g) ? 0 : g->mask + 1;
}

static inline void bf_groups_place(uint64_t *slots, size_t mask, uint64_t h, uint32_t gid)
{
	size_t i = (size_t)h & mask;

	while (slots[i] != 0) {
		i = (i + 1) & mask;
	}
	slots[i] = BF_TAG(h) | ((uint64_t)gid + 1);
}

/* Slots start at 1024 and double at load 3/4; rehashed from the stored full hashes. */
static int bf_groups_grow_slots(bf_run *r)
{
	bf_groups *g = &r->groups;
	const size_t old = bf_groups_nslots(g);
	size_t nslots, bytes;
	uint64_t *ns;

	if (old > SIZE_MAX / 2 / sizeof(uint64_t)) {
		return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
	}
	nslots = old == 0 ? BF_GROUP_SLOTS0 : old * 2;
	bytes = nslots * sizeof(uint64_t);
	if (bf_groups_preflight(r, nslots, g->cap, g->arena_cap, bytes) != 0) {
		return -1;
	}
	ns = r->host->alloc(r->host->ud, bytes);
	memset(ns, 0, bytes);
	for (uint32_t gid = 0; gid < g->n; gid++) {
		if ((int64_t)gid != g->null_gid) {
			bf_groups_place(ns, nslots - 1, g->hash[gid], gid);
		}
	}
	if (old != 0) {
		r->host->free(r->host->ud, g->slots);
	}
	g->slots = ns;
	g->mask = nslots - 1;
	return 0;
}

/* One group array's growth: memory_limit preflight of its own block, then the realloc. NULL when refused. */
static void *bf_groups_realloc(bf_run *r, void *p, size_t bytes)
{
	const bf_host *h = r->host;

	if (!h->mem_fits(h->ud, bytes)) {
		bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
		return NULL;
	}
	return h->realloc(h->ud, p, bytes);
}

/*
 * Group arrays start at 256 entries, double, and clamp to maxGroups; all grow together. maxGroupBytes is checked on
 * their total; memory_limit on each array as it is reallocated, since ZendMM may place each in a chunk of its own. A
 * refusal leaves the arrays grown so far larger than cap, which stays.
 */
static int bf_groups_grow_arrays(bf_run *r)
{
	bf_groups *g = &r->groups;
	uint32_t ncap = g->cap == 0 ? BF_GROUP_CAP0 : (g->cap > UINT32_MAX / 2 ? UINT32_MAX : g->cap * 2);
	size_t n;
	void *p;

	if (ncap > r->max_groups) {
		ncap = r->max_groups;
	}
	n = (size_t)ncap;
	if (!bf_groups_within(r, bf_groups_nslots(g), ncap, g->arena_cap)) {
		return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
	}
	if ((p = bf_groups_realloc(r, g->hash, n * sizeof(uint64_t))) == NULL) {
		return -1;
	}
	g->hash = p;
	if (g->int_keys) {
		if ((p = bf_groups_realloc(r, g->ikey, n * sizeof(int64_t))) == NULL) {
			return -1;
		}
		g->ikey = p;
	} else {
		if ((p = bf_groups_realloc(r, g->off, n * sizeof(uint64_t))) == NULL) {
			return -1;
		}
		g->off = p;
		if ((p = bf_groups_realloc(r, g->len, n * sizeof(uint32_t))) == NULL) {
			return -1;
		}
		g->len = p;
	}
	if ((p = bf_groups_realloc(r, g->count, n * sizeof(uint64_t))) == NULL) {
		return -1;
	}
	g->count = p;
	if (g->has_sum && g->sum_int) {
		if ((p = bf_groups_realloc(r, g->isum, n * sizeof(int64_t))) == NULL) {
			return -1;
		}
		g->isum = p;
	} else if (g->has_sum) {
		if ((p = bf_groups_realloc(r, g->fsum, n * sizeof(double))) == NULL) {
			return -1;
		}
		g->fsum = p;
	}
	g->cap = ncap;
	return 0;
}

/* The key arena starts at 65,536 bytes and doubles until the key fits. */
static int bf_groups_grow_arena(bf_run *r, size_t need)
{
	bf_groups *g = &r->groups;
	size_t ncap = g->arena_cap == 0 ? BF_GROUP_ARENA0 : g->arena_cap;

	while (ncap < need) {
		if (ncap > SIZE_MAX / 2) {
			return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
		}
		ncap *= 2;
	}
	if (bf_groups_preflight(r, bf_groups_nslots(g), g->cap, ncap, ncap) != 0) {
		return -1;
	}
	g->arena = r->host->realloc(r->host->ud, g->arena, ncap);
	g->arena_cap = ncap;
	return 0;
}

static int bf_groups_insert(bf_run *r, uint64_t h, const bf_val *v, uint32_t *out)
{
	bf_groups *g = &r->groups;
	uint32_t gid;

	if (g->n >= r->max_groups) {
		return bf_fail_row(r, BF_E_MAX_GROUPS, -1, r->max_groups, 0);
	}
	if (!v->is_null && (bf_groups_empty(g) || ((uint64_t)g->keyed + 1) * 4 > ((uint64_t)g->mask + 1) * 3)
		&& bf_groups_grow_slots(r) != 0) {
		return -1;
	}
	if (g->n == g->cap && bf_groups_grow_arrays(r) != 0) {
		return -1;
	}
	if (!g->int_keys && !v->is_null && v->n > g->arena_cap - g->arena_len) {
		size_t need;

		if (__builtin_add_overflow(g->arena_len, v->n, &need)) {
			return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
		}
		if (bf_groups_grow_arena(r, need) != 0) {
			return -1;
		}
	}
	gid = g->n++;
	g->hash[gid] = h;
	g->count[gid] = 0;
	if (g->has_sum && g->sum_int) {
		g->isum[gid] = 0;
	} else if (g->has_sum) {
		g->fsum[gid] = 0.0;
	}
	if (g->int_keys) {
		g->ikey[gid] = v->is_null ? 0 : v->i;
	} else {
		g->off[gid] = g->arena_len;
		g->len[gid] = v->is_null ? 0 : (uint32_t)v->n;
		if (!v->is_null && v->n > 0) {
			memcpy(g->arena + g->arena_len, v->s, v->n);
			g->arena_len += v->n;
		}
	}
	if (v->is_null) {
		g->null_gid = (int64_t)gid;
	} else {
		g->keyed++;
		bf_groups_place(g->slots, g->mask, h, gid);
	}
	*out = gid;
	return 0;
}

void bf_groups_prepare(bf_run *r)
{
	bf_groups *g = &r->groups;

	g->slots = (uint64_t *)bf_no_slots;
	g->mask = 0;
	g->null_gid = -1;
	g->int_keys = r->cols[r->group_col].type == BF_INT64;
	g->has_sum = r->sum_col >= 0;
	g->sum_int = g->has_sum && r->cols[r->sum_col].type == BF_INT64;
}

int bf_group_of(bf_run *r, const bf_val *v, uint32_t *gid)
{
	bf_groups *g = &r->groups;
	uint64_t h, s;
	size_t i;

	if (v->is_null) {
		if (g->null_gid >= 0) {
			*gid = (uint32_t)g->null_gid;
			return 0;
		}
		return bf_groups_insert(r, 0, v, gid);
	}
	if (g->int_keys) {
		h = BF_SHIM(bf_siphash13_u64(r->hash_key, (uint64_t)v->i));
		for (i = (size_t)h & g->mask; (s = g->slots[i]) != 0; i = (i + 1) & g->mask) {
			if (BF_TAG(s) == BF_TAG(h) && g->ikey[(uint32_t)s - 1] == v->i) {
				*gid = (uint32_t)s - 1;
				return 0;
			}
		}
	} else {
		h = BF_SHIM(bf_siphash13(r->hash_key, v->s, v->n));
		for (i = (size_t)h & g->mask; (s = g->slots[i]) != 0; i = (i + 1) & g->mask) {
			const uint32_t k = (uint32_t)s - 1;

			if (BF_TAG(s) == BF_TAG(h) && g->len[k] == v->n && (v->n == 0 || memcmp(g->arena + g->off[k], v->s, v->n) == 0)) {
				*gid = k;
				return 0;
			}
		}
	}
	return bf_groups_insert(r, h, v, gid);
}

uint64_t bf_group_hash(const bf_run *r, const bf_val *v)
{
	return BF_SHIM(r->groups.int_keys ? bf_siphash13_u64(r->hash_key, (uint64_t)v->i) : bf_siphash13(r->hash_key, v->s, v->n));
}

int bf_group_probe(bf_run *r, const bf_val *v, uint64_t h, uint32_t *gid)
{
	bf_groups *g = &r->groups;
	uint64_t s;
	size_t i;

	if (g->int_keys) {
		for (i = (size_t)h & g->mask; (s = g->slots[i]) != 0; i = (i + 1) & g->mask) {
			if (BF_TAG(s) == BF_TAG(h) && g->ikey[(uint32_t)s - 1] == v->i) {
				*gid = (uint32_t)s - 1;
				return 0;
			}
		}
	} else {
		for (i = (size_t)h & g->mask; (s = g->slots[i]) != 0; i = (i + 1) & g->mask) {
			const uint32_t k = (uint32_t)s - 1;

			if (BF_TAG(s) == BF_TAG(h) && g->len[k] == v->n && (v->n == 0 || memcmp(g->arena + g->off[k], v->s, v->n) == 0)) {
				*gid = k;
				return 0;
			}
		}
	}
	return bf_groups_insert(r, h, v, gid);
}

void bf_groups_release(bf_run *r)
{
	bf_groups *g = &r->groups;
	void *const parts[] = { g->hash, g->ikey, g->off, g->len, g->count, g->isum, g->fsum, g->arena };

	if (g->slots != NULL && !bf_groups_empty(g)) {
		r->host->free(r->host->ud, g->slots);
	}
	for (size_t i = 0; i < sizeof(parts) / sizeof(parts[0]); i++) {
		if (parts[i] != NULL) {
			r->host->free(r->host->ud, parts[i]);
		}
	}
	memset(g, 0, sizeof(*g));
}

/* ---- groupAgg table (SPEC 6.3): the same slots and growth over the encoded key tuple ---- */

static inline bool bf_tuples_empty(const bf_tuples *t)
{
	return t->slots == (const uint64_t *)bf_no_slots;
}

static inline size_t bf_tuples_nslots(const bf_tuples *t)
{
	return bf_tuples_empty(t) ? 0 : t->mask + 1;
}

/* Per-group array bytes: hash 8, off 8, len 4, count 8, and 16 per aggregate pair. */
static uint64_t bf_tuples_row_bytes(const bf_run *r)
{
	return 8 + 8 + 4 + 8 + 16 * (uint64_t)r->naggs;
}

static bool bf_tuples_within(const bf_run *r, size_t nslots, uint32_t cap, size_t arena_cap)
{
	uint64_t total, part;

	if (__builtin_mul_overflow((uint64_t)nslots, (uint64_t)sizeof(uint64_t), &total)
		|| __builtin_mul_overflow((uint64_t)cap, bf_tuples_row_bytes(r), &part)
		|| __builtin_add_overflow(total, part, &total)
		|| __builtin_add_overflow(total, (uint64_t)arena_cap, &total)) {
		return false;
	}
	return total <= r->max_group_bytes;
}

static int bf_tuples_preflight(bf_run *r, size_t nslots, uint32_t cap, size_t arena_cap, size_t bytes)
{
	if (!bf_tuples_within(r, nslots, cap, arena_cap)) {
		return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
	}
	if (!r->host->mem_fits(r->host->ud, bytes)) {
		return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
	}
	return 0;
}

static int bf_tuples_grow_slots(bf_run *r)
{
	bf_tuples *t = &r->tuples;
	const size_t old = bf_tuples_nslots(t);
	size_t nslots, bytes;
	uint64_t *ns;

	if (old > SIZE_MAX / 2 / sizeof(uint64_t)) {
		return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
	}
	nslots = old == 0 ? BF_GROUP_SLOTS0 : old * 2;
	bytes = nslots * sizeof(uint64_t);
	if (bf_tuples_preflight(r, nslots, t->cap, t->arena_cap, bytes) != 0) {
		return -1;
	}
	ns = r->host->alloc(r->host->ud, bytes);
	memset(ns, 0, bytes);
	for (uint32_t gid = 0; gid < t->n; gid++) {
		bf_groups_place(ns, nslots - 1, t->hash[gid], gid);
	}
	if (old != 0) {
		r->host->free(r->host->ud, t->slots);
	}
	t->slots = ns;
	t->mask = nslots - 1;
	return 0;
}

/* All per-group arrays grow together from 256 entries, doubling, clamped to maxGroups; maxGroupBytes on their total,
   memory_limit on each array as it is reallocated (bf_groups_grow_arrays). */
static int bf_tuples_grow_arrays(bf_run *r)
{
	bf_tuples *t = &r->tuples;
	uint32_t ncap = t->cap == 0 ? BF_GROUP_CAP0 : (t->cap > UINT32_MAX / 2 ? UINT32_MAX : t->cap * 2);
	size_t n;
	void *p;

	if (ncap > r->max_groups) {
		ncap = r->max_groups;
	}
	n = (size_t)ncap;
	if (!bf_tuples_within(r, bf_tuples_nslots(t), ncap, t->arena_cap)) {
		return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
	}
	if ((p = bf_groups_realloc(r, t->hash, n * sizeof(uint64_t))) == NULL) {
		return -1;
	}
	t->hash = p;
	if ((p = bf_groups_realloc(r, t->off, n * sizeof(uint64_t))) == NULL) {
		return -1;
	}
	t->off = p;
	if ((p = bf_groups_realloc(r, t->len, n * sizeof(uint32_t))) == NULL) {
		return -1;
	}
	t->len = p;
	if ((p = bf_groups_realloc(r, t->count, n * sizeof(uint64_t))) == NULL) {
		return -1;
	}
	t->count = p;
	if (r->naggs > 0) {
		if ((p = bf_groups_realloc(r, t->state, n * r->naggs * sizeof(bf_agg_state))) == NULL) {
			return -1;
		}
		t->state = p;
	}
	t->cap = ncap;
	return 0;
}

static int bf_tuples_grow_arena(bf_run *r, size_t need)
{
	bf_tuples *t = &r->tuples;
	size_t ncap = t->arena_cap == 0 ? BF_GROUP_ARENA0 : t->arena_cap;

	while (ncap < need) {
		if (ncap > SIZE_MAX / 2) {
			return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
		}
		ncap *= 2;
	}
	if (bf_tuples_preflight(r, bf_tuples_nslots(t), t->cap, ncap, ncap) != 0) {
		return -1;
	}
	t->arena = r->host->realloc(r->host->ud, t->arena, ncap);
	t->arena_cap = ncap;
	return 0;
}

/*
 * Copies of the three growth functions for bf_tuples_insert_x, inlined there: so the CSV functions keep one caller each
 * and are inlined into bf_tuple_of as before, and no identical-function folding can merge a copy with its original.
 */
static inline __attribute__((always_inline)) int bf_tuples_grow_slots_x(bf_run *r)
{
	bf_tuples *t = &r->tuples;
	const size_t old = bf_tuples_nslots(t);
	size_t nslots, bytes;
	uint64_t *ns;

	if (old > SIZE_MAX / 2 / sizeof(uint64_t)) {
		return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
	}
	nslots = old == 0 ? BF_GROUP_SLOTS0 : old * 2;
	bytes = nslots * sizeof(uint64_t);
	if (bf_tuples_preflight(r, nslots, t->cap, t->arena_cap, bytes) != 0) {
		return -1;
	}
	ns = r->host->alloc(r->host->ud, bytes);
	memset(ns, 0, bytes);
	for (uint32_t gid = 0; gid < t->n; gid++) {
		bf_groups_place(ns, nslots - 1, t->hash[gid], gid);
	}
	if (old != 0) {
		r->host->free(r->host->ud, t->slots);
	}
	t->slots = ns;
	t->mask = nslots - 1;
	return 0;
}

/* Unlike bf_tuples_grow_arrays, each array is preflighted as it is reallocated: ZendMM may place each in a chunk of
   its own, so one sum can price too little. A refusal leaves the arrays grown so far larger than cap, which stays. */
static inline __attribute__((always_inline)) int bf_tuples_grow_arrays_x(bf_run *r)
{
	bf_tuples *t = &r->tuples;
	uint32_t ncap = t->cap == 0 ? BF_GROUP_CAP0 : (t->cap > UINT32_MAX / 2 ? UINT32_MAX : t->cap * 2);
	const bf_host *h = r->host;
	size_t n;

	if (ncap > r->max_groups) {
		ncap = r->max_groups;
	}
	n = (size_t)ncap;
	if (bf_tuples_preflight(r, bf_tuples_nslots(t), ncap, t->arena_cap, n * sizeof(uint64_t)) != 0) {
		return -1;
	}
	t->hash = h->realloc(h->ud, t->hash, n * sizeof(uint64_t));
	if (!h->mem_fits(h->ud, n * sizeof(uint64_t))) {
		return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
	}
	t->off = h->realloc(h->ud, t->off, n * sizeof(uint64_t));
	if (!h->mem_fits(h->ud, n * sizeof(uint32_t))) {
		return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
	}
	t->len = h->realloc(h->ud, t->len, n * sizeof(uint32_t));
	if (!h->mem_fits(h->ud, n * sizeof(uint64_t))) {
		return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
	}
	t->count = h->realloc(h->ud, t->count, n * sizeof(uint64_t));
	if (r->naggs > 0) {
		if (!h->mem_fits(h->ud, n * r->naggs * sizeof(bf_agg_state))) {
			return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
		}
		t->state = h->realloc(h->ud, t->state, n * r->naggs * sizeof(bf_agg_state));
	}
	t->cap = ncap;
	return 0;
}

static inline __attribute__((always_inline)) int bf_tuples_grow_arena_x(bf_run *r, size_t need)
{
	bf_tuples *t = &r->tuples;
	size_t ncap = t->arena_cap == 0 ? BF_GROUP_ARENA0 : t->arena_cap;

	while (ncap < need) {
		if (ncap > SIZE_MAX / 2) {
			return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
		}
		ncap *= 2;
	}
	if (bf_tuples_preflight(r, bf_tuples_nslots(t), t->cap, ncap, ncap) != 0) {
		return -1;
	}
	t->arena = r->host->realloc(r->host->ud, t->arena, ncap);
	t->arena_cap = ncap;
	return 0;
}

static inline uint8_t bf_tuple_tag(const bf_run *r, const bf_val *v, uint32_t k)
{
	return v->is_null ? BF_TUPLE_NULL : (r->cols[r->gkeys[k]].type == BF_INT64 ? BF_TUPLE_INT : BF_TUPLE_STR);
}

/*
 * The table-run copies of the tuple lookup (table true): a non-NULL value of a dictionary column is its id, tag 3
 * (SPEC 6.6). The CSV functions keep their own bodies, so their code is unchanged.
 */
static inline __attribute__((always_inline)) uint8_t bf_tuple_tag_x(const bf_run *r, const bf_val *v, uint32_t k,
	const bool table)
{
	return table && !v->is_null && v->id != 0 ? BF_TUPLE_DICT : bf_tuple_tag(r, v, k);
}

/* Whether the stored encoding e equals the row's key parts, compared part by part without building it. */
static inline __attribute__((always_inline)) bool bf_tuple_equals_x(const bf_run *r, const unsigned char *e,
	const bool table)
{
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		const bf_val *v = &r->vals[r->gkey_slot[k]];
		const uint8_t tag = bf_tuple_tag_x(r, v, k, table);

		if (*e++ != tag) {
			return false;
		}
		if (tag == BF_TUPLE_INT) {
			if (bf_get_le(e, 8) != (uint64_t)v->i) {
				return false;
			}
			e += 8;
		} else if (tag == BF_TUPLE_STR) {
			if (bf_get_le(e, 4) != (uint64_t)v->n || (v->n > 0 && memcmp(e + 4, v->s, v->n) != 0)) {
				return false;
			}
			e += 4 + v->n;
		} else if (table && tag == BF_TUPLE_DICT) {
			if (bf_get_le(e, 4) != v->id) {
				return false;
			}
			e += 4;
		}
	}
	return true;
}

static bool bf_tuple_equals(const bf_run *r, const unsigned char *e)
{
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		const bf_val *v = &r->vals[r->gkey_slot[k]];
		const uint8_t tag = bf_tuple_tag(r, v, k);

		if (*e++ != tag) {
			return false;
		}
		if (tag == BF_TUPLE_INT) {
			if (bf_get_le(e, 8) != (uint64_t)v->i) {
				return false;
			}
			e += 8;
		} else if (tag == BF_TUPLE_STR) {
			if (bf_get_le(e, 4) != (uint64_t)v->n || (v->n > 0 && memcmp(e + 4, v->s, v->n) != 0)) {
				return false;
			}
			e += 4 + v->n;
		}
	}
	return true;
}

static inline __attribute__((always_inline)) int bf_tuples_insert_x(bf_run *r, uint64_t h, size_t enc_len,
	uint32_t *out, const bool table)
{
	bf_tuples *t = &r->tuples;
	unsigned char *e;
	uint32_t gid;

	if (t->n >= r->max_groups) {
		return bf_fail_row(r, BF_E_MAX_GROUPS, -1, r->max_groups, 0);
	}
	if ((bf_tuples_empty(t) || ((uint64_t)t->n + 1) * 4 > ((uint64_t)t->mask + 1) * 3) && bf_tuples_grow_slots_x(r) != 0) {
		return -1;
	}
	if (t->n == t->cap && bf_tuples_grow_arrays_x(r) != 0) {
		return -1;
	}
	if (enc_len > t->arena_cap - t->arena_len) {
		size_t need;

		if (__builtin_add_overflow(t->arena_len, enc_len, &need)) {
			return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
		}
		if (bf_tuples_grow_arena_x(r, need) != 0) {
			return -1;
		}
	}
	gid = t->n++;
	t->hash[gid] = h;
	t->off[gid] = t->arena_len;
	t->len[gid] = (uint32_t)enc_len;
	t->count[gid] = 0;
	if (r->naggs > 0) {
		memset(t->state + (size_t)gid * r->naggs, 0, r->naggs * sizeof(bf_agg_state));
	}
	e = (unsigned char *)t->arena + t->arena_len;
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		const bf_val *v = &r->vals[r->gkey_slot[k]];
		const uint8_t tag = bf_tuple_tag_x(r, v, k, table);

		*e++ = tag;
		if (tag == BF_TUPLE_INT) {
			bf_put_le(e, (uint64_t)v->i, 8);
			e += 8;
		} else if (tag == BF_TUPLE_STR) {
			bf_put_le(e, (uint64_t)v->n, 4);
			if (v->n > 0) {
				memcpy(e + 4, v->s, v->n);
			}
			e += 4 + v->n;
		} else if (table && tag == BF_TUPLE_DICT) {
			bf_put_le(e, v->id, 4);
			e += 4;
		}
	}
	t->arena_len += enc_len;
	bf_groups_place(t->slots, t->mask, h, gid);
	*out = gid;
	return 0;
}

static int bf_tuples_insert(bf_run *r, uint64_t h, size_t enc_len, uint32_t *out)
{
	bf_tuples *t = &r->tuples;
	unsigned char *e;
	uint32_t gid;

	if (t->n >= r->max_groups) {
		return bf_fail_row(r, BF_E_MAX_GROUPS, -1, r->max_groups, 0);
	}
	if ((bf_tuples_empty(t) || ((uint64_t)t->n + 1) * 4 > ((uint64_t)t->mask + 1) * 3) && bf_tuples_grow_slots(r) != 0) {
		return -1;
	}
	if (t->n == t->cap && bf_tuples_grow_arrays(r) != 0) {
		return -1;
	}
	if (enc_len > t->arena_cap - t->arena_len) {
		size_t need;

		if (__builtin_add_overflow(t->arena_len, enc_len, &need)) {
			return bf_fail_row(r, BF_E_GROUP_BYTES, -1, 0, 0);
		}
		if (bf_tuples_grow_arena(r, need) != 0) {
			return -1;
		}
	}
	gid = t->n++;
	t->hash[gid] = h;
	t->off[gid] = t->arena_len;
	t->len[gid] = (uint32_t)enc_len;
	t->count[gid] = 0;
	if (r->naggs > 0) {
		memset(t->state + (size_t)gid * r->naggs, 0, r->naggs * sizeof(bf_agg_state));
	}
	e = (unsigned char *)t->arena + t->arena_len;
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		const bf_val *v = &r->vals[r->gkey_slot[k]];
		const uint8_t tag = bf_tuple_tag(r, v, k);

		*e++ = tag;
		if (tag == BF_TUPLE_INT) {
			bf_put_le(e, (uint64_t)v->i, 8);
			e += 8;
		} else if (tag == BF_TUPLE_STR) {
			bf_put_le(e, (uint64_t)v->n, 4);
			if (v->n > 0) {
				memcpy(e + 4, v->s, v->n);
			}
			e += 4 + v->n;
		}
	}
	t->arena_len += enc_len;
	bf_groups_place(t->slots, t->mask, h, gid);
	*out = gid;
	return 0;
}

void bf_tuples_prepare(bf_run *r)
{
	r->tuples.slots = (uint64_t *)bf_no_slots;
	r->tuples.mask = 0;
}

static inline __attribute__((always_inline)) int bf_tuple_of_x(bf_run *r, uint32_t *gid, const bool table)
{
	bf_tuples *t = &r->tuples;
	bf_sip sip;
	size_t enc_len = 0;
	uint64_t h, s;

	/* SipHash over the encoding of SPEC 6.3, part by part; no per-row copy of the tuple. */
	bf_sip_init(&sip, r->hash_key);
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		const bf_val *v = &r->vals[r->gkey_slot[k]];
		const uint8_t tag = bf_tuple_tag_x(r, v, k, table);
		unsigned char le[8];

		bf_sip_update(&sip, &tag, 1);
		enc_len += 1;
		if (tag == BF_TUPLE_INT) {
			bf_put_le(le, (uint64_t)v->i, 8);
			bf_sip_update(&sip, le, 8);
			enc_len += 8;
		} else if (tag == BF_TUPLE_STR) {
			bf_put_le(le, (uint64_t)v->n, 4);
			bf_sip_update(&sip, le, 4);
			bf_sip_update(&sip, v->s, v->n);
			enc_len += 4 + v->n;
		} else if (table && tag == BF_TUPLE_DICT) {
			bf_put_le(le, v->id, 4);
			bf_sip_update(&sip, le, 4);
			enc_len += 4;
		}
	}
	h = BF_SHIM(bf_sip_final(&sip));
	for (size_t i = (size_t)h & t->mask; (s = t->slots[i]) != 0; i = (i + 1) & t->mask) {
		const uint32_t g = (uint32_t)s - 1;

		const unsigned char *e = (const unsigned char *)t->arena + t->off[g];

		if (BF_TAG(s) == BF_TAG(h) && t->len[g] == enc_len && (table ? bf_tuple_equals_x(r, e, true) : bf_tuple_equals(r, e))) {
			*gid = g;
			return 0;
		}
	}
	return table ? bf_tuples_insert_x(r, h, enc_len, gid, true) : bf_tuples_insert(r, h, enc_len, gid);
}

int bf_tuple_of(bf_run *r, uint32_t *gid)
{
	bf_tuples *t = &r->tuples;
	bf_sip sip;
	size_t enc_len = 0;
	uint64_t h, s;

	/* SipHash over the encoding of SPEC 6.3, part by part; no per-row copy of the tuple. */
	bf_sip_init(&sip, r->hash_key);
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		const bf_val *v = &r->vals[r->gkey_slot[k]];
		const uint8_t tag = bf_tuple_tag(r, v, k);
		unsigned char le[8];

		bf_sip_update(&sip, &tag, 1);
		enc_len += 1;
		if (tag == BF_TUPLE_INT) {
			bf_put_le(le, (uint64_t)v->i, 8);
			bf_sip_update(&sip, le, 8);
			enc_len += 8;
		} else if (tag == BF_TUPLE_STR) {
			bf_put_le(le, (uint64_t)v->n, 4);
			bf_sip_update(&sip, le, 4);
			bf_sip_update(&sip, v->s, v->n);
			enc_len += 4 + v->n;
		}
	}
	h = BF_SHIM(bf_sip_final(&sip));
	for (size_t i = (size_t)h & t->mask; (s = t->slots[i]) != 0; i = (i + 1) & t->mask) {
		const uint32_t g = (uint32_t)s - 1;

		if (BF_TAG(s) == BF_TAG(h) && t->len[g] == enc_len && bf_tuple_equals(r, (const unsigned char *)t->arena + t->off[g])) {
			*gid = g;
			return 0;
		}
	}
	return bf_tuples_insert(r, h, enc_len, gid);
}

int bf_tuple_of_table(bf_run *r, uint32_t *gid)
{
	return bf_tuple_of_x(r, gid, true);
}

void bf_tuples_release(bf_run *r)
{
	bf_tuples *t = &r->tuples;
	void *const parts[] = { t->hash, t->off, t->len, t->count, t->state, t->arena };

	if (t->slots != NULL && !bf_tuples_empty(t)) {
		r->host->free(r->host->ud, t->slots);
	}
	for (size_t i = 0; i < sizeof(parts) / sizeof(parts[0]); i++) {
		if (parts[i] != NULL) {
			r->host->free(r->host->ud, parts[i]);
		}
	}
	memset(t, 0, sizeof(*t));
}

uint64_t bf_groups_bytes(const bf_groups *g)
{
	return (uint64_t)bf_groups_nslots(g) * sizeof(uint64_t) + (uint64_t)g->cap * bf_groups_row_bytes(g) + g->arena_cap;
}

int64_t bf_groups_find(const bf_groups *g, const uint64_t key[2], const bf_val *v)
{
	uint64_t h, s;
	size_t i;

	if (g->int_keys) {
		h = BF_SHIM(bf_siphash13_u64(key, (uint64_t)v->i));
		for (i = (size_t)h & g->mask; (s = g->slots[i]) != 0; i = (i + 1) & g->mask) {
			if (BF_TAG(s) == BF_TAG(h) && g->ikey[(uint32_t)s - 1] == v->i) {
				return (int64_t)(uint32_t)(s - 1);
			}
		}
		return -1;
	}
	h = BF_SHIM(bf_siphash13(key, v->s, v->n));
	for (i = (size_t)h & g->mask; (s = g->slots[i]) != 0; i = (i + 1) & g->mask) {
		const uint32_t k = (uint32_t)s - 1;

		if (BF_TAG(s) == BF_TAG(h) && g->len[k] == v->n && (v->n == 0 || memcmp(g->arena + g->off[k], v->s, v->n) == 0)) {
			return (int64_t)k;
		}
	}
	return -1;
}
