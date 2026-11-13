#include <math.h>
#include <stdlib.h>
#include <string.h>

#include "bf_core.h"

#define BF_LIKELY(x)   __builtin_expect(!!(x), 1)
#define BF_UNLIKELY(x) __builtin_expect(!!(x), 0)

/* Referenced columns in schema order, each with its file position; assigns predicate slots. */
static int bf_prepare(bf_run *r)
{
	bool *referenced;
	uint32_t slot = 0;
	int32_t *slot_of_col;

	if (bf_run_alloc(r, (void **)&referenced, r->ncols) != 0) {
		return -1;
	}
	/* toArray and a cursor reference every schema column (SPEC 5.5), so their slots are the schema order. */
	memset(referenced, r->rows, r->ncols);
	for (uint32_t i = 0; i < r->npreds; i++) {
		const bf_pred_kind k = r->pred_src[i].kind;

		referenced[r->pred_src[i].col] = true;
		r->preds[i] = r->pred_src[i];
		/* A NULL value passes IS NULL, and a set whose list held null. */
		r->preds[i].null_match = k == BF_P_IS_NULL
			|| ((k == BF_P_INT_IN || k == BF_P_FLOAT_IN || k == BF_P_BOOL_IN || k == BF_P_STR_IN) && r->pred_src[i].b);
	}
	if (r->sum_col >= 0) {
		referenced[r->sum_col] = true;
	}
	if (r->group_col >= 0) {
		referenced[r->group_col] = true;
	}
	for (uint32_t k = 0; k < r->naggs; k++) {
		referenced[r->aggs[k].col] = true;
	}
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		referenced[r->gkeys[k]] = true;
	}
	r->nrefs = 0;
	for (uint32_t c = 0; c < r->ncols; c++) {
		r->nrefs += referenced[c];
	}
	if (bf_run_alloc(r, (void **)&slot_of_col, r->ncols * sizeof(int32_t)) != 0) {
		r->host->free(r->host->ud, referenced);
		return -1;
	}
	if (r->nrefs > 0
		&& (bf_run_alloc(r, (void **)&r->refs, r->nrefs * sizeof(bf_ref)) != 0
			|| bf_run_alloc(r, (void **)&r->vals, r->nrefs * sizeof(bf_val)) != 0)) {
		r->host->free(r->host->ud, referenced);
		r->host->free(r->host->ud, slot_of_col);
		return -1;
	}
	for (uint32_t c = 0; c < r->ncols; c++) {
		if (referenced[c]) {
			slot_of_col[c] = (int32_t)slot;
			r->refs[slot].column = c;
			r->refs[slot].file_idx = (uint32_t)r->col_file[c];
			r->refs[slot].type = r->cols[c].type;
			slot++;
		}
	}
	for (uint32_t i = 0; i < r->npreds; i++) {
		r->preds[i].slot = (uint32_t)slot_of_col[r->preds[i].col];
	}
	r->sum_slot = r->sum_col >= 0 ? slot_of_col[r->sum_col] : -1;
	r->group_slot = r->group_col >= 0 ? slot_of_col[r->group_col] : -1;
	if (r->group_col >= 0) {
		bf_groups_prepare(r);
	}
	r->agg_mode = r->naggs > 0 || r->ngkeys > 0;
	for (uint32_t k = 0; k < r->naggs; k++) {
		r->agg_slot[k] = (uint32_t)slot_of_col[r->aggs[k].col];
		r->agg_int[k] = r->cols[r->aggs[k].col].type == BF_INT64;
	}
	for (uint32_t k = 0; k < r->ngkeys; k++) {
		r->gkey_slot[k] = (uint32_t)slot_of_col[r->gkeys[k]];
	}
	if (r->ngkeys > 0) {
		bf_tuples_prepare(r);
	}
	r->host->free(r->host->ud, referenced);
	/* SPEC 6.2: past the last referenced file column, a row's delimiters are counted by popcount when enough fields are left. */
	{
		size_t last = 0, min = BF_TAIL_MIN;

		for (uint32_t k = 0; k < r->nrefs; k++) {
			last = r->refs[k].file_idx + 1 > last ? r->refs[k].file_idx + 1 : last;
		}
		last = last > 0 ? last : 1;
#ifdef BF_FUZZ_HASH_SHIM
		min = bf_fuzz_tail_min;
#endif
		r->gb_batch = BF_GB_MAX;
		r->gb_min_slots = BF_GB_SLOTS_MIN;
#ifdef BF_FUZZ_HASH_SHIM
		r->gb_batch = bf_fuzz_group_batch;
		r->gb_min_slots = bf_fuzz_group_min_slots;
#endif
		r->tail_fn = bf_tail_for(r->masks_fn);
		r->tail_from = r->tail_fn != NULL && last + min <= r->nfile ? last : SIZE_MAX;
	}
	r->host->free(r->host->ud, slot_of_col);
	return 0;
}

/* Validates every referenced column of the current row, in schema order, match or not. Inlined into each row loop. */
static inline __attribute__((always_inline)) bool bf_decode_row(bf_run *r)
{
	for (uint32_t slot = 0; slot < r->nrefs; slot++) {
		const bf_ref *ref = &r->refs[slot];
		bf_field *f = &r->fields[ref->file_idx];
		bf_val *v = &r->vals[slot];
		size_t n;
		int kind;

		if (f->len == 0 && !f->quoted) {
			v->is_null = true;
			continue;
		}
		v->is_null = false;
		n = BF_UNLIKELY(f->doubled) ? bf_unquote_inplace(f->p, f->len) : f->len;
		if (BF_UNLIKELY(n > r->max_field_bytes)) {
			bf_fail_row(r, BF_E_FIELD_TOO_LONG, (int32_t)ref->column, 0, 0);
			return false;
		}
		if (ref->type == BF_STRING) {
			if (BF_UNLIKELY(!bf_utf8_valid(f->p, n))) {
				bf_fail_row(r, BF_E_BAD_UTF8, (int32_t)ref->column, 0, 0);
				return false;
			}
			v->s = f->p;
			v->n = n;
			continue;
		}
		/* A doubled quote leaves a quote byte in the field, which no numeric or bool grammar accepts. */
		if (BF_UNLIKELY(n == 0)) {
			kind = BF_KIND_QUOTED_EMPTY + 1;
		} else if (ref->type == BF_INT64) {
			kind = bf_parse_int64(f->p, n, &v->i);
		} else if (ref->type == BF_FLOAT64) {
			kind = bf_parse_float64(r->host, f->p, n, &v->d);
		} else if (n == 4 && memcmp(f->p, "true", 4) == 0) {
			v->b = 1;
			kind = 0;
		} else if (n == 5 && memcmp(f->p, "false", 5) == 0) {
			v->b = 0;
			kind = 0;
		} else {
			kind = BF_KIND_MALFORMED + 1;
		}
		if (BF_UNLIKELY(kind != 0)) {
			bf_fail_row(r, BF_E_BAD_VALUE, (int32_t)ref->column, (uint64_t)(kind - 1), 0);
			return false;
		}
	}
	return true;
}

/* Binary searches over the sorted distinct sets of SPEC 5.7; no hash is involved. */
static inline bool bf_in_int(const int64_t *set, size_t n, int64_t x)
{
	size_t lo = 0, hi = n;

	while (lo < hi) {
		size_t mid = lo + (hi - lo) / 2;

		if (set[mid] < x) {
			lo = mid + 1;
		} else {
			hi = mid;
		}
	}
	return lo < n && set[lo] == x;
}

/* IEEE comparisons: a -0.0 value equals the 0.0 stored for -0.0 and 0.0 alike. */
static inline bool bf_in_float(const double *set, size_t n, double x)
{
	size_t lo = 0, hi = n;

	while (lo < hi) {
		size_t mid = lo + (hi - lo) / 2;

		if (set[mid] < x) {
			lo = mid + 1;
		} else {
			hi = mid;
		}
	}
	return lo < n && set[lo] == x;
}

/* Set order: the shorter string first, then memcmp over equal lengths, so a length miss skips memcmp as STR_EQ does. */
static int bf_bytes_cmp(const char *a, size_t an, const char *b, size_t bn)
{
	if (an != bn) {
		return an < bn ? -1 : 1;
	}
	return memcmp(a, b, an);
}

static inline bool bf_in_str(const uint64_t *off, size_t n, const char *x, size_t xn)
{
	const char *bytes = (const char *)(off + n + 1);
	size_t lo = 0, hi = n;

	while (lo < hi) {
		size_t mid = lo + (hi - lo) / 2;
		int c = bf_bytes_cmp(bytes + off[mid], (size_t)(off[mid + 1] - off[mid]), x, xn);

		if (c == 0) {
			return true;
		}
		if (c < 0) {
			lo = mid + 1;
		} else {
			hi = mid;
		}
	}
	return false;
}

/* The extended predicate kinds, each true when v (not NULL) passes; inlined into the full row loops only. */
static inline __attribute__((always_inline)) bool bf_match_m2b(const bf_pred *p, const bf_val *v)
{
	switch (p->kind) {
		case BF_P_NOT_NULL:
			return true;
		case BF_P_INT_NE:
			return v->i != p->i;
		case BF_P_INT_GTE:
			return v->i >= p->i;
		case BF_P_INT_LT:
			return v->i < p->i;
		case BF_P_INT_LTE:
			return v->i <= p->i;
		case BF_P_INT_IN:
			return bf_in_int(p->set, p->n, v->i);
		case BF_P_FLOAT_NE:
			return v->d != p->d;
		case BF_P_FLOAT_GTE:
			return v->d >= p->d;
		case BF_P_FLOAT_LT:
			return v->d < p->d;
		case BF_P_FLOAT_LTE:
			return v->d <= p->d;
		case BF_P_FLOAT_IN:
			return bf_in_float(p->set, p->n, v->d);
		case BF_P_BOOL_NE:
			return v->b != (uint8_t)p->b;
		case BF_P_BOOL_IN:
			return ((p->i >> v->b) & 1) != 0;
		case BF_P_STR_NE:
			return v->n != p->n || memcmp(v->s, p->s, p->n) != 0;
		case BF_P_STR_IN:
			return bf_in_str(p->set, p->n, v->s, v->n);
		default:
			return true;
	}
}

/*
 * Fused predicates over the decoded values, in declaration order. With full false (a basic plan, which holds
 * only the basic kinds) the instantiation has no code for the extended kinds.
 */
static inline __attribute__((always_inline)) bool bf_match(const bf_pred *preds, uint32_t n, const bf_val *vals,
	const bool full)
{
	for (uint32_t i = 0; i < n; i++) {
		const bf_pred *p = &preds[i];
		const bf_val *v = &vals[p->slot];

		if (v->is_null) {
			if (!p->null_match) {
				return false;
			}
			continue;
		}
		switch (p->kind) {
			case BF_P_IS_NULL:
				return false;
			case BF_P_INT_EQ:
				if (v->i != p->i) { return false; }
				break;
			case BF_P_INT_GT:
				if (v->i <= p->i) { return false; }
				break;
			case BF_P_FLOAT_EQ:
				if (v->d != p->d) { return false; }
				break;
			case BF_P_FLOAT_GT:
				if (!(v->d > p->d)) { return false; }
				break;
			case BF_P_BOOL_EQ:
				if (v->b != (uint8_t)p->b) { return false; }
				break;
			case BF_P_STR_EQ:
				if (v->n != p->n || memcmp(v->s, p->s, p->n) != 0) { return false; }
				break;
			default:
				if (full && !bf_match_m2b(p, v)) {
					return false;
				}
				break;
		}
	}
	return true;
}

/*
 * One aggregate pair over one non-NULL value (SPEC 5.8): checked int64 sums, IEEE float sums in row order,
 * strict comparisons so the first of equal values is kept. false with err set on an int64 overflow.
 */
static inline bool bf_agg_add(bf_run *r, const bf_agg *a, bool is_int, bf_agg_state *st, const bf_val *v)
{
	switch (a->op) {
		case BF_AGG_COUNT:
			break;
		case BF_AGG_SUM:
		case BF_AGG_AVG:
			if (is_int) {
				if (BF_UNLIKELY(__builtin_add_overflow(st->v.i, v->i, &st->v.i))) {
					bf_fail_row(r, BF_E_SUM_INT_OVERFLOW, (int32_t)a->col, 0, 0);
					return false;
				}
			} else {
				st->v.d += v->d;
			}
			break;
		case BF_AGG_MIN:
			if (is_int ? (st->n == 0 || v->i < st->v.i) : (st->n == 0 || v->d < st->v.d)) {
				if (is_int) {
					st->v.i = v->i;
				} else {
					st->v.d = v->d;
				}
			}
			break;
		case BF_AGG_MAX:
			if (is_int ? (st->n == 0 || v->i > st->v.i) : (st->n == 0 || v->d > st->v.d)) {
				if (is_int) {
					st->v.i = v->i;
				} else {
					st->v.d = v->d;
				}
			}
			break;
	}
	st->n++;
	return true;
}

/* The pairs of a matched row in list order, into states (one per pair): the first overflow is reported. */
static inline bool bf_aggs_update(bf_run *r, bf_agg_state *states)
{
	for (uint32_t k = 0; k < r->naggs; k++) {
		const bf_val *v = &r->vals[r->agg_slot[k]];

		if (!v->is_null && BF_UNLIKELY(!bf_agg_add(r, &r->aggs[k], r->agg_int[k], &states[k], v))) {
			return false;
		}
	}
	return true;
}

/* aggregate(): run-level states; groupAgg(): the row's group, its count, then its states. */
static bool bf_agg_row(bf_run *r)
{
	uint32_t gid;

	if (r->ngkeys == 0) {
		return bf_aggs_update(r, r->agg);
	}
	if (bf_tuple_of(r, &gid) != 0) {
		return false;
	}
	r->tuples.count[gid]++;
	return r->naggs == 0 || bf_aggs_update(r, r->tuples.state + (size_t)gid * r->naggs);
}

/* Non-finite float64 sums after the scan (sum and avg pairs), pairs in list order. */
static bool bf_aggs_finite(bf_run *r, const bf_agg_state *states)
{
	for (uint32_t k = 0; k < r->naggs; k++) {
		if (bf_agg_float_sum(&r->aggs[k], r->agg_int[k]) && !isfinite(states[k].v.d)) {
			bf_fail_now(r, BF_E_SUM_NOT_FINITE);
			r->err.column = (int32_t)r->aggs[k].col;
			return false;
		}
	}
	return true;
}

/* Counts the matched row into its key's group and adds its sum value; overflow is per group, at this row. */
static inline __attribute__((always_inline)) bool bf_group_update(bf_run *r)
{
	bf_groups *g = &r->groups;
	uint32_t gid;

	if (BF_UNLIKELY(bf_group_of(r, &r->vals[r->group_slot], &gid) != 0)) {
		return false;
	}
	g->count[gid]++;
	if (r->sum_slot >= 0 && !r->vals[r->sum_slot].is_null) {
		if (g->sum_int) {
			if (BF_UNLIKELY(__builtin_add_overflow(g->isum[gid], r->vals[r->sum_slot].i, &g->isum[gid]))) {
				bf_fail_row(r, BF_E_SUM_INT_OVERFLOW, r->sum_col, 0, 0);
				return false;
			}
		} else {
			g->fsum[gid] += r->vals[r->sum_slot].d;
		}
	}
	return true;
}

/*
 * SPEC 6.3: a matched row of a single-key group-by is queued with its key hashed and its slot prefetched, and the
 * queued rows are probed and counted in row order when BF_GB_MAX of them wait, or before anything else can end the
 * run. Groups are therefore inserted in the order of the rows, maxGroups and the sum overflow are raised at the same
 * row, and a framing or decode error of a later row is reported only if no queued row fails first: the later error is
 * kept aside while the queue is flushed. A string key too long for the entry is flushed behind the queue and handled at
 * once. Nothing is allocated: the queue lives in the run.
 */
static bool bf_group_flush(bf_run *r)
{
	bf_groups *g = &r->groups;
	const uint32_t n = r->gb_n;
	const uint64_t scanned = r->scanned, line = r->row_line, offset = r->row_offset;

	r->gb_n = 0;
	for (uint32_t k = 0; k < n; k++) {
		bf_gentry *e = &r->gb[k];
		uint32_t gid;

		r->scanned = e->row;
		r->row_line = e->line;
		r->row_offset = e->offset;
		if (e->key.is_null) {
			if (BF_UNLIKELY(bf_group_of(r, &e->key, &gid) != 0)) {
				return false;
			}
		} else if (BF_UNLIKELY(bf_group_probe(r, &e->key, e->h, &gid) != 0)) {
			return false;
		}
		g->count[gid]++;
		if (!e->sum_null) {
			if (g->sum_int) {
				if (BF_UNLIKELY(__builtin_add_overflow(g->isum[gid], e->isum, &g->isum[gid]))) {
					bf_fail_row(r, BF_E_SUM_INT_OVERFLOW, r->sum_col, 0, 0);
					return false;
				}
			} else {
				g->fsum[gid] += e->fsum;
			}
		}
	}
	/* The run goes on at the row it had framed. */
	r->scanned = scanned;
	r->row_line = line;
	r->row_offset = offset;
	return true;
}

/* The run stops at a framing or decode error: a queued row that fails first is the error reported. */
static bool bf_group_fail(bf_run *r)
{
	if (r->gb_n > 0) {
		const bf_error later = r->err;

		if (!bf_group_flush(r)) {
			return false;
		}
		r->err = later;
	}
	return false;
}

static inline __attribute__((always_inline)) bool bf_group_queue(bf_run *r)
{
	const bf_val *key = &r->vals[r->group_slot];
	bf_gentry *e;

	/* A table that fits the cache needs no prefetch: the row is counted at once, as before the queue existed. The table only
	   grows, so once rows are queued every later row is. */
	if (r->groups.mask + 1 < r->gb_min_slots && r->gb_n == 0) {
		return bf_group_update(r);
	}
	if (BF_UNLIKELY(!r->groups.int_keys && !key->is_null && key->n > BF_GB_KEY)) {
		return (r->gb_n == 0 || bf_group_flush(r)) && bf_group_update(r);
	}
	e = &r->gb[r->gb_n];
	e->key = *key;
	if (!key->is_null) {
		e->h = bf_group_hash(r, key);
		__builtin_prefetch(&r->groups.slots[(size_t)e->h & r->groups.mask]);
		if (!r->groups.int_keys && key->n > 0) {
			memcpy(e->s, key->s, key->n);
			e->key.s = e->s;
		}
	}
	if (r->sum_slot >= 0 && !r->vals[r->sum_slot].is_null) {
		e->sum_null = false;
		e->isum = r->vals[r->sum_slot].i;
		e->fsum = r->vals[r->sum_slot].d;
	} else {
		e->sum_null = true;
	}
	e->row = r->scanned;
	e->line = r->row_line;
	e->offset = r->row_offset;
	if (++r->gb_n == r->gb_batch) {
		return bf_group_flush(r);
	}
	return true;
}

/*
 * SPEC 6.2: the row loop, instantiated from this one body per framing function (comma or any delimiter) and
 * per plan class. full false is a basic plan: basic predicate kinds, then count, sum, groupCount or groupSum.
 */
static inline __attribute__((always_inline)) bool bf_scan_rows(bf_run *r, int (*const next_row)(bf_run *),
	const bool full)
{
	const bool sum_int = r->sum_col >= 0 && r->cols[r->sum_col].type == BF_INT64;
	int rc;

	while ((rc = next_row(r)) > 0) {
		if (BF_UNLIKELY(!bf_decode_row(r))) {
			return bf_group_fail(r);
		}
		if (!bf_match(r->preds, r->npreds, r->vals, full)) {
			continue;
		}
		r->matched++;
		if (full && r->agg_mode) {
			if (!bf_agg_row(r)) {
				return false;
			}
			continue;
		}
		if (r->group_slot >= 0) {
			if (BF_UNLIKELY(!bf_group_queue(r))) {
				return false;
			}
			continue;
		}
		if (r->sum_slot >= 0 && !r->vals[r->sum_slot].is_null) {
			if (sum_int) {
				if (BF_UNLIKELY(__builtin_add_overflow(r->isum, r->vals[r->sum_slot].i, &r->isum))) {
					bf_fail_row(r, BF_E_SUM_INT_OVERFLOW, r->sum_col, 0, 0);
					return false;
				}
			} else {
				r->fsum += r->vals[r->sum_slot].d;
			}
		}
	}
	if (rc < 0) {
		return bf_group_fail(r);
	}
	return r->gb_n == 0 || bf_group_flush(r);
}

static __attribute__((noinline)) bool bf_scan_comma_m2(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row_comma, false);
}

static __attribute__((noinline)) bool bf_scan_comma_full(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row_comma, true);
}

static __attribute__((noinline)) bool bf_scan_any_m2(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row, false);
}

static __attribute__((noinline)) bool bf_scan_any_full(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row, true);
}

static __attribute__((noinline)) bool bf_scan_comma_m2_tail(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row_comma_tail, false);
}

static __attribute__((noinline)) bool bf_scan_comma_full_tail(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row_comma_tail, true);
}

static __attribute__((noinline)) bool bf_scan_any_m2_tail(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row_tail, false);
}

static __attribute__((noinline)) bool bf_scan_any_full_tail(bf_run *r)
{
	return bf_scan_rows(r, bf_csv_next_row_tail, true);
}

/* A basic plan: no aggregate() or groupAgg(), and every predicate of a basic kind. */
static bool bf_plan_m2(const bf_run *r)
{
	if (r->agg_mode) {
		return false;
	}
	for (uint32_t i = 0; i < r->npreds; i++) {
		switch (r->preds[i].kind) {
			case BF_P_IS_NULL:
			case BF_P_INT_EQ:
			case BF_P_INT_GT:
			case BF_P_FLOAT_EQ:
			case BF_P_FLOAT_GT:
			case BF_P_BOOL_EQ:
			case BF_P_STR_EQ:
				break;
			default:
				return false;
		}
	}
	return true;
}

bool bf_run_scan(bf_run *r)
{
	const bool sum_int = r->sum_col >= 0 && r->cols[r->sum_col].type == BF_INT64;
	bool basic, scanned;

	if (bf_csv_open(r) != 0 || bf_prepare(r) != 0) {
		return false;
	}
	/* The instantiation is chosen once per run. */
	basic = bf_plan_m2(r);
	if (r->tail_from != SIZE_MAX) {
		if (r->delim == ',') {
			scanned = basic ? bf_scan_comma_m2_tail(r) : bf_scan_comma_full_tail(r);
		} else {
			scanned = basic ? bf_scan_any_m2_tail(r) : bf_scan_any_full_tail(r);
		}
	} else if (r->delim == ',') {
		scanned = basic ? bf_scan_comma_m2(r) : bf_scan_comma_full(r);
	} else {
		scanned = basic ? bf_scan_any_m2(r) : bf_scan_any_full(r);
	}
	if (!scanned) {
		return false;
	}
	r->where = BF_WHERE_NONE;
	if (r->agg_mode) {
		/* groupAgg: groups in result order, and within a group pairs in list order. */
		if (r->ngkeys == 0) {
			return bf_aggs_finite(r, r->agg);
		}
		for (uint32_t gid = 0; r->naggs > 0 && gid < r->tuples.n; gid++) {
			if (!bf_aggs_finite(r, r->tuples.state + (size_t)gid * r->naggs)) {
				return false;
			}
		}
		return true;
	}
	if (r->group_slot >= 0) {
		/* Non-finite group sums: one pass over the groups in result order. */
		for (uint32_t gid = 0; r->sum_col >= 0 && !sum_int && gid < r->groups.n; gid++) {
			if (!isfinite(r->groups.fsum[gid])) {
				bf_fail_now(r, BF_E_SUM_NOT_FINITE);
				r->err.column = r->sum_col;
				return false;
			}
		}
		return true;
	}
	if (r->sum_col >= 0 && !sum_int && !isfinite(r->fsum)) {
		bf_fail_now(r, BF_E_SUM_NOT_FINITE);
		r->err.column = r->sum_col;
		return false;
	}
	return true;
}

/*
 * SPEC 6.1, 6.2: the rows loop of toArray and a cursor, a plan class of its own instantiated per framing function, so
 * the scan loops above keep their code. Each matched row past the maxRows check goes to emit_row, which builds the PHP
 * row and may pause the run after it; the framing state stays in r, so the next call continues at the next row.
 * 1 paused, 0 end of input, -1 failed.
 */
static inline __attribute__((always_inline)) int bf_emit_rows(bf_run *r, int (*const next_row)(bf_run *))
{
	int rc;

	while ((rc = next_row(r)) > 0) {
		if (BF_UNLIKELY(!bf_decode_row(r))) {
			return -1;
		}
		if (!bf_match(r->preds, r->npreds, r->vals, true)) {
			continue;
		}
		/* SPEC 5.10: the matching row that would make maxRows + 1 rows. */
		if (BF_UNLIKELY(r->matched == r->max_rows)) {
			return bf_fail_row(r, BF_E_MAX_ROWS, -1, r->max_rows, 0);
		}
		r->matched++;
		switch (r->host->emit_row(r->host->ud, r->vals, r->nrefs)) {
			case BF_EMIT_CONTINUE:
				break;
			case BF_EMIT_PAUSE:
				return 1;
			case BF_EMIT_NO_MEMORY:
				return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
		}
	}
	return rc;
}

static __attribute__((noinline)) int bf_rows_comma(bf_run *r)
{
	return bf_emit_rows(r, bf_csv_next_row_comma);
}

static __attribute__((noinline)) int bf_rows_any(bf_run *r)
{
	return bf_emit_rows(r, bf_csv_next_row);
}

static __attribute__((noinline)) int bf_rows_comma_tail(bf_run *r)
{
	return bf_emit_rows(r, bf_csv_next_row_comma_tail);
}

static __attribute__((noinline)) int bf_rows_any_tail(bf_run *r)
{
	return bf_emit_rows(r, bf_csv_next_row_tail);
}

int bf_run_rows(bf_run *r)
{
	if (!r->rows_started) {
		r->rows_started = true;
		if (bf_csv_open(r) != 0 || bf_prepare(r) != 0) {
			return -1;
		}
	}
	if (r->tail_from != SIZE_MAX) {
		return r->delim == ',' ? bf_rows_comma_tail(r) : bf_rows_any_tail(r);
	}
	return r->delim == ',' ? bf_rows_comma(r) : bf_rows_any(r);
}

void bf_run_release(bf_run *r)
{
	void *const parts[] = { r->buf, r->masks, r->col_file, r->fields, r->refs, r->vals };

	bf_groups_release(r);
	bf_tuples_release(r);
	for (size_t i = 0; i < sizeof(parts) / sizeof(parts[0]); i++) {
		if (parts[i] != NULL) {
			r->host->free(r->host->ud, parts[i]);
		}
	}
}

/* ---- Table runs (SPEC 6.6): new loops over the column store; the CSV loops above are unchanged ---- */

#define BF_TABLE_TICK (UINT64_C(1) << 20)  /* interrupted() every 1,048,576 rows (SPEC 6.4) */

static int bf_id_cmp(const void *a, const void *b)
{
	const uint32_t x = *(const uint32_t *)a, y = *(const uint32_t *)b;

	return (x > y) - (x < y);
}

/* The id of a non-NULL literal in a dictionary column, UINT32_MAX when the value is absent. */
static uint32_t bf_dict_id(const bf_run *r, uint32_t col, const bf_val *v)
{
	const int64_t gid = bf_groups_find(&r->table->tcols[col].values, r->table->hash_key, v);

	return gid < 0 ? UINT32_MAX : (uint32_t)gid + 1;
}

/*
 * Moves every whereEq, whereNe and whereIn on a dictionary column out of the value predicates into id predicates,
 * resolved here, at the start of the run. Table values were validated at load, so no predicate raises and the AND of
 * the same predicates in another order selects the same rows.
 */
static int bf_table_resolve(bf_run *r)
{
	uint32_t kept = 0;

	for (uint32_t i = 0; i < r->npreds; i++) {
		const bf_pred *p = &r->preds[i];
		const bf_tcol *c = &r->table->tcols[p->col];
		bf_tpred *tp;

		if (!c->dict || !(p->kind == BF_P_INT_EQ || p->kind == BF_P_INT_NE || p->kind == BF_P_INT_IN
				|| p->kind == BF_P_STR_EQ || p->kind == BF_P_STR_NE || p->kind == BF_P_STR_IN)) {
			r->preds[kept++] = *p;
			continue;
		}
		if (r->tpreds == NULL && bf_run_alloc(r, (void **)&r->tpreds, BF_MAX_PREDS * sizeof(bf_tpred)) != 0) {
			return -1;
		}
		tp = &r->tpreds[r->ntpreds++];
		memset(tp, 0, sizeof(*tp));
		tp->col = p->col;
		if (p->kind == BF_P_INT_IN || p->kind == BF_P_STR_IN) {
			tp->kind = BF_T_IN;
			tp->null_match = p->b;
			if (p->n > 0 && bf_run_alloc(r, (void **)&tp->ids, p->n * sizeof(uint32_t)) != 0) {
				return -1;
			}
			for (size_t k = 0; k < p->n; k++) {
				bf_val v = { 0 };
				uint32_t id;

				if (p->kind == BF_P_INT_IN) {
					v.i = ((const int64_t *)p->set)[k];
				} else {
					const uint64_t *off = p->set;

					v.s = (const char *)(off + p->n + 1) + off[k];
					v.n = (size_t)(off[k + 1] - off[k]);
				}
				if ((id = bf_dict_id(r, p->col, &v)) != UINT32_MAX) {
					tp->ids[tp->n++] = id;
				}
			}
			if (tp->n > 1) {
				qsort(tp->ids, tp->n, sizeof(uint32_t), bf_id_cmp);
			}
		} else {
			bf_val v = { .i = p->i, .s = p->s, .n = p->n };

			tp->kind = p->kind == BF_P_INT_EQ || p->kind == BF_P_STR_EQ ? BF_T_EQ : BF_T_NE;
			tp->id = bf_dict_id(r, p->col, &v);
		}
	}
	r->npreds = kept;
	return 0;
}

static inline bool bf_tmatch(const bf_run *r, uint64_t i)
{
	for (uint32_t k = 0; k < r->ntpreds; k++) {
		const bf_tpred *tp = &r->tpreds[k];
		const uint32_t id = r->table->tcols[tp->col].ids[i];

		switch (tp->kind) {
			case BF_T_EQ:
				if (id != tp->id) { return false; }
				break;
			case BF_T_NE:
				if (id == 0 || id == tp->id) { return false; }
				break;
			default:
				/* A list of only null holds no id, and no ids array. */
				if (id == 0 ? !tp->null_match : tp->n == 0 || bsearch(&id, tp->ids, tp->n, sizeof(uint32_t), bf_id_cmp) == NULL) {
					return false;
				}
				break;
		}
	}
	return true;
}

/* Row i of the table into the referenced slots. A string of length 0 never points into an arena, which may be NULL. */
static inline __attribute__((always_inline)) void bf_trow(bf_run *r, uint64_t i)
{
	const bf_table *t = r->table;

	for (uint32_t slot = 0; slot < r->nrefs; slot++) {
		const bf_tcol *c = &t->tcols[r->refs[slot].column];
		bf_val *v = &r->vals[slot];

		v->is_null = !c->valid[i];
		v->id = c->dict ? c->ids[i] : 0;
		switch (c->type) {
			case BF_INT64:
				v->i = c->i[i];
				break;
			case BF_FLOAT64:
				v->d = c->d[i];
				break;
			case BF_BOOL:
				v->b = c->b[i];
				break;
			case BF_STRING:
				if (c->dict) {
					const uint32_t id = c->ids[i];

					v->n = id == 0 ? 0 : c->values.len[id - 1];
					v->s = v->n == 0 ? "" : c->values.arena + c->values.off[id - 1];
				} else {
					v->n = c->len[i];
					v->s = v->n == 0 ? "" : t->arena + c->off[i];
				}
				break;
		}
	}
}

/* groupCount and groupSum on a dictionary key: arrays indexed by id, sized by the whole dictionary (entries + 1)
   and checked against maxGroupBytes before the scan, then each against memory as it is allocated, with no row
   prefix; the run's release frees those already allocated. */
static int bf_dgroup_prepare(bf_run *r)
{
	const uint64_t n = (uint64_t)r->table->tcols[r->group_col].values.n + 1;
	const bool has_sum = r->sum_col >= 0, sum_int = has_sum && r->cols[r->sum_col].type == BF_INT64;
	const uint64_t bytes = n * (8 + (has_sum ? 8 : 0) + 4);
	const bf_host *h = r->host;

	r->dgroup = true;
	if (bytes > r->max_group_bytes) {
		return bf_fail_now(r, BF_E_GROUP_BYTES);
	}
	if (bytes > SIZE_MAX || !h->mem_fits(h->ud, (size_t)n * sizeof(uint64_t))) {
		return bf_fail_now(r, BF_E_MEMORY);
	}
	r->dcount = h->alloc(h->ud, (size_t)n * sizeof(uint64_t));
	memset(r->dcount, 0, (size_t)n * sizeof(uint64_t));
	if (has_sum && !h->mem_fits(h->ud, (size_t)n * 8)) {
		return bf_fail_now(r, BF_E_MEMORY);
	}
	if (sum_int) {
		r->disum = h->alloc(h->ud, (size_t)n * sizeof(int64_t));
		memset(r->disum, 0, (size_t)n * sizeof(int64_t));
	} else if (has_sum) {
		r->dfsum = h->alloc(h->ud, (size_t)n * sizeof(double));
		for (uint64_t k = 0; k < n; k++) {
			r->dfsum[k] = 0.0;
		}
	}
	if (!h->mem_fits(h->ud, (size_t)n * sizeof(uint32_t))) {
		return bf_fail_now(r, BF_E_MEMORY);
	}
	r->dorder = h->alloc(h->ud, (size_t)n * sizeof(uint32_t));
	return 0;
}

static inline bool bf_dgroup_update(bf_run *r, uint64_t i)
{
	const uint32_t id = r->table->tcols[r->group_col].ids[i];

	if (r->dcount[id] == 0) {
		/* SPEC 5.9: the first distinct key that would make maxGroups + 1 groups, among groups actually produced. */
		if (r->ndorder >= r->max_groups) {
			bf_fail_row(r, BF_E_MAX_GROUPS, -1, r->max_groups, 0);
			return false;
		}
		r->dorder[r->ndorder++] = id;
	}
	r->dcount[id]++;
	if (r->sum_slot >= 0 && !r->vals[r->sum_slot].is_null) {
		if (r->disum != NULL) {
			if (BF_UNLIKELY(__builtin_add_overflow(r->disum[id], r->vals[r->sum_slot].i, &r->disum[id]))) {
				bf_fail_row(r, BF_E_SUM_INT_OVERFLOW, r->sum_col, 0, 0);
				return false;
			}
		} else {
			r->dfsum[id] += r->vals[r->sum_slot].d;
		}
	}
	return true;
}

/* bf_agg_row of a table run: a dictionary key enters the groupAgg tuple as its id (SPEC 6.6). */
static bool bf_agg_row_table(bf_run *r)
{
	uint32_t gid;

	if (r->ngkeys == 0) {
		return bf_aggs_update(r, r->agg);
	}
	if (bf_tuple_of_table(r, &gid) != 0) {
		return false;
	}
	r->tuples.count[gid]++;
	return r->naggs == 0 || bf_aggs_update(r, r->tuples.state + (size_t)gid * r->naggs);
}

static int bf_table_begin(bf_run *r)
{
	if (bf_run_alloc(r, (void **)&r->col_file, r->ncols * sizeof(int32_t)) != 0) {
		return -1;
	}
	for (uint32_t c = 0; c < r->ncols; c++) {
		r->col_file[c] = (int32_t)c;
	}
	if (bf_prepare(r) != 0 || bf_table_resolve(r) != 0) {
		return -1;
	}
	if (r->group_col >= 0 && r->table->tcols[r->group_col].dict && bf_dgroup_prepare(r) != 0) {
		return -1;
	}
	r->where = BF_WHERE_ROW;
	return 0;
}

/* The engine's timeout stops the run with no row prefix (SPEC 6.4). */
static bool bf_table_timed_out(bf_run *r)
{
	if (r->host->interrupted(r->host->ud)) {
		r->where = BF_WHERE_NONE;
		bf_fail_now(r, BF_E_TIMED_OUT);
		return true;
	}
	return false;
}

/* Every 1,048,576 rows the timeout is checked, and once more when the scan reaches the last row. */
static inline bool bf_table_tick(bf_run *r, uint64_t i)
{
	if (BF_UNLIKELY((i & (BF_TABLE_TICK - 1)) == 0) && i != 0 && bf_table_timed_out(r)) {
		return false;
	}
	r->scanned = i + 1;
	return true;
}

bool bf_run_table_scan(bf_run *r)
{
	const uint64_t rows = r->table->rows;
	const bool sum_int = r->sum_col >= 0 && r->cols[r->sum_col].type == BF_INT64;

	if (bf_table_begin(r) != 0) {
		return false;
	}
	for (uint64_t i = 0; i < rows; i++) {
		if (!bf_table_tick(r, i)) {
			return false;
		}
		if (!bf_tmatch(r, i)) {
			continue;
		}
		bf_trow(r, i);
		if (!bf_match(r->preds, r->npreds, r->vals, true)) {
			continue;
		}
		r->matched++;
		if (r->agg_mode) {
			if (!bf_agg_row_table(r)) {
				return false;
			}
		} else if (r->dgroup) {
			if (!bf_dgroup_update(r, i)) {
				return false;
			}
		} else if (r->group_slot >= 0) {
			if (!bf_group_update(r)) {
				return false;
			}
		} else if (r->sum_slot >= 0 && !r->vals[r->sum_slot].is_null) {
			if (sum_int) {
				if (BF_UNLIKELY(__builtin_add_overflow(r->isum, r->vals[r->sum_slot].i, &r->isum))) {
					bf_fail_row(r, BF_E_SUM_INT_OVERFLOW, r->sum_col, 0, 0);
					return false;
				}
			} else {
				r->fsum += r->vals[r->sum_slot].d;
			}
		}
	}
	r->scanned = rows;
	if (bf_table_timed_out(r)) {
		return false;
	}
	r->where = BF_WHERE_NONE;
	/* The checks after the scan, as bf_run_scan makes them. */
	if (r->agg_mode) {
		if (r->ngkeys == 0) {
			return bf_aggs_finite(r, r->agg);
		}
		for (uint32_t gid = 0; r->naggs > 0 && gid < r->tuples.n; gid++) {
			if (!bf_aggs_finite(r, r->tuples.state + (size_t)gid * r->naggs)) {
				return false;
			}
		}
		return true;
	}
	if (r->dgroup || r->group_slot >= 0) {
		const uint32_t n = r->dgroup ? r->ndorder : r->groups.n;

		for (uint32_t k = 0; r->sum_col >= 0 && !sum_int && k < n; k++) {
			if (!isfinite(r->dgroup ? r->dfsum[r->dorder[k]] : r->groups.fsum[k])) {
				bf_fail_now(r, BF_E_SUM_NOT_FINITE);
				r->err.column = r->sum_col;
				return false;
			}
		}
		return true;
	}
	if (r->sum_col >= 0 && !sum_int && !isfinite(r->fsum)) {
		bf_fail_now(r, BF_E_SUM_NOT_FINITE);
		r->err.column = r->sum_col;
		return false;
	}
	return true;
}

int bf_run_table_rows(bf_run *r)
{
	const uint64_t rows = r->table->rows;

	if (!r->rows_started) {
		r->rows_started = true;
		if (bf_table_begin(r) != 0) {
			return -1;
		}
	}
	r->where = BF_WHERE_ROW;
	while (r->trow < rows) {
		const uint64_t i = r->trow++;

		if (!bf_table_tick(r, i)) {
			return -1;
		}
		if (!bf_tmatch(r, i)) {
			continue;
		}
		bf_trow(r, i);
		if (!bf_match(r->preds, r->npreds, r->vals, true)) {
			continue;
		}
		if (BF_UNLIKELY(r->matched == r->max_rows)) {
			return bf_fail_row(r, BF_E_MAX_ROWS, -1, r->max_rows, 0);
		}
		r->matched++;
		switch (r->host->emit_row(r->host->ud, r->vals, r->nrefs)) {
			case BF_EMIT_CONTINUE:
				break;
			case BF_EMIT_PAUSE:
				return 1;
			case BF_EMIT_NO_MEMORY:
				return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
		}
	}
	if (bf_table_timed_out(r)) {
		return -1;
	}
	r->where = BF_WHERE_NONE;
	return 0;
}

void bf_run_table_release(bf_run *r)
{
	void *const parts[] = { r->dcount, r->disum, r->dfsum, r->dorder };

	for (uint32_t k = 0; r->tpreds != NULL && k < r->ntpreds; k++) {
		if (r->tpreds[k].ids != NULL) {
			r->host->free(r->host->ud, r->tpreds[k].ids);
		}
	}
	if (r->tpreds != NULL) {
		r->host->free(r->host->ud, r->tpreds);
	}
	for (size_t k = 0; k < sizeof(parts) / sizeof(parts[0]); k++) {
		if (parts[k] != NULL) {
			r->host->free(r->host->ud, parts[k]);
		}
	}
	bf_run_release(r);
}
