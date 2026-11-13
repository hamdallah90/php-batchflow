#include <string.h>

#include "bf_core.h"

/* SPEC 6.6: capacity bytes per row of one column, its validity byte included. */
static uint64_t bf_tcol_row_bytes(const bf_tcol *c)
{
	if (c->dict) {
		return 1 + 4 + (c->type == BF_INT64 ? 8 : 0);
	}
	switch (c->type) {
		case BF_INT64:
		case BF_FLOAT64:
			return 1 + 8;
		case BF_BOOL:
			return 1 + 1;
		default:
			return 1 + 8 + 4;
	}
}

int bf_table_init(bf_table *t, bf_run *r, const bool *dict)
{
	bool any_dict = false;

	if (bf_run_alloc(r, (void **)&t->tcols, (size_t)t->ncols * sizeof(bf_tcol)) != 0) {
		return -1;
	}
	memset(t->tcols, 0, (size_t)t->ncols * sizeof(bf_tcol));
	for (uint32_t c = 0; c < t->ncols; c++) {
		bf_tcol *tc = &t->tcols[c];

		tc->type = t->cols[c].type;
		tc->dict = dict[c];
		t->row_bytes += bf_tcol_row_bytes(tc);
		any_dict |= dict[c];
	}
	if (!any_dict) {
		return 0;
	}
	/* Dictionary values go through the single-key group table of SPEC 6.3, with the table's key and limits. */
	if (bf_run_alloc(r, (void **)&t->dict_run, sizeof(bf_run)) != 0) {
		return -1;
	}
	memset(t->dict_run, 0, sizeof(bf_run));
	t->dict_run->cols = t->cols;
	t->dict_run->ncols = t->ncols;
	t->dict_run->host = &t->host;
	t->dict_run->sum_col = -1;
	t->dict_run->max_groups = t->max_dict;
	memcpy(t->dict_run->hash_key, t->hash_key, sizeof(t->hash_key));
	for (uint32_t c = 0; c < t->ncols; c++) {
		if (dict[c]) {
			t->dict_run->group_col = (int32_t)c;
			bf_groups_prepare(t->dict_run);
			t->tcols[c].values = t->dict_run->groups;
		}
	}
	memset(&t->dict_run->groups, 0, sizeof(bf_groups));
	return 0;
}

/* Reallocates *p to n bytes after the memory preflight of those n bytes; false, with *p untouched, on refusal. */
static bool bf_table_realloc(const bf_host *h, void **p, size_t n)
{
	if (!h->mem_fits(h->ud, n)) {
		return false;
	}
	*p = h->realloc(h->ud, *p, n);
	return true;
}

/* All row arrays grow together after the maxTableBytes check on the total; each array is preflighted as it is
   reallocated (SPEC 6.4): ZendMM may place each in a chunk of its own, so one sum can price too little. */
static int bf_table_grow_rows(bf_table *t, bf_run *r)
{
	const bf_host *h = &t->host;
	uint64_t ncap = t->cap == 0 ? BF_TABLE_ROWS0 : t->cap * 2, add, total, need;

	if (ncap > t->max_rows) {
		ncap = t->max_rows;
	}
	if (ncap <= t->cap) {
		/* Not reached: the rows loop refuses row maxInputRows + 1 before it is appended. */
		return bf_fail_row(r, BF_E_INPUT_ROWS, -1, 0, 0);
	}
	if (__builtin_mul_overflow(ncap - t->cap, t->row_bytes, &add) || __builtin_add_overflow(t->bytes, add, &total)
		|| total > t->max_bytes) {
		return bf_fail_row(r, BF_E_TABLE_BYTES, -1, 0, 0);
	}
	if (__builtin_mul_overflow(ncap, t->row_bytes, &need) || need > SIZE_MAX) {
		return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
	}
	for (uint32_t c = 0; c < t->ncols; c++) {
		bf_tcol *tc = &t->tcols[c];
		const size_t n = (size_t)ncap;

		bool ok = true;

		/* A refusal leaves the arrays grown so far larger than cap, which stays: the load then fails as a whole. */
		if (!bf_table_realloc(h, (void **)&tc->valid, n)
			|| (tc->dict && !bf_table_realloc(h, (void **)&tc->ids, n * sizeof(uint32_t)))) {
			return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
		}
		if (tc->type == BF_INT64) {
			ok = bf_table_realloc(h, (void **)&tc->i, n * sizeof(int64_t));
		} else if (tc->type == BF_FLOAT64) {
			ok = bf_table_realloc(h, (void **)&tc->d, n * sizeof(double));
		} else if (tc->type == BF_BOOL) {
			ok = bf_table_realloc(h, (void **)&tc->b, n);
		} else if (!tc->dict) {
			ok = bf_table_realloc(h, (void **)&tc->off, n * sizeof(uint64_t))
				&& bf_table_realloc(h, (void **)&tc->len, n * sizeof(uint32_t));
		}
		if (!ok) {
			return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
		}
	}
	t->cap = ncap;
	t->bytes = total;
	return 0;
}

/* The string arena starts at 65,536 bytes and doubles until need fits; a growth is never shrunk to fit. */
static int bf_table_grow_arena(bf_table *t, bf_run *r, size_t need)
{
	const bf_host *h = &t->host;
	size_t ncap = t->arena_cap == 0 ? BF_TABLE_ARENA0 : t->arena_cap;
	uint64_t total;

	while (ncap < need) {
		if (ncap > SIZE_MAX / 2) {
			return bf_fail_row(r, BF_E_TABLE_BYTES, -1, 0, 0);
		}
		ncap *= 2;
	}
	if (__builtin_add_overflow(t->bytes - t->arena_cap, (uint64_t)ncap, &total) || total > t->max_bytes) {
		return bf_fail_row(r, BF_E_TABLE_BYTES, -1, 0, 0);
	}
	if (!h->mem_fits(h->ud, ncap)) {
		return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
	}
	t->arena = h->realloc(h->ud, t->arena, ncap);
	t->arena_cap = ncap;
	t->bytes = total;
	return 0;
}

/*
 * A non-NULL dictionary value's id. The group table checks its own capacity against what maxTableBytes leaves to it,
 * so the table total stays within the limit; its refusals become the table's reasons with this row's context.
 */
static int bf_table_dict_id(bf_table *t, bf_run *r, uint32_t c, const bf_val *v, uint32_t *id)
{
	bf_run *d = t->dict_run;
	bf_tcol *tc = &t->tcols[c];
	const uint64_t before = bf_groups_bytes(&tc->values);
	uint32_t gid;
	int rc;

	d->host = &t->host;     /* the table struct moves after the load, into its object */
	/* The arrays move to d for the call, so a fatal inside it leaves no stale copy for bf_table_free to free. */
	d->groups = tc->values;
	memset(&tc->values, 0, sizeof(bf_groups));
	d->max_group_bytes = t->max_bytes - (t->bytes - before);
	rc = bf_group_of(d, v, &gid);
	tc->values = d->groups;
	memset(&d->groups, 0, sizeof(bf_groups));
	t->bytes = t->bytes - before + bf_groups_bytes(&tc->values);
	if (rc != 0) {
		switch (d->err.reason) {
			case BF_E_MAX_GROUPS:
				return bf_fail_row(r, BF_E_DICT_SIZE, (int32_t)c, 0, 0);
			case BF_E_GROUP_BYTES:
				return bf_fail_row(r, BF_E_TABLE_BYTES, -1, 0, 0);
			default:
				return bf_fail_row(r, BF_E_MEMORY, -1, 0, 0);
		}
	}
	*id = gid + 1;
	return 0;
}

int bf_table_append(bf_table *t, bf_run *r, const bf_val *vals)
{
	const uint64_t row = t->rows;

	if (row == t->cap && bf_table_grow_rows(t, r) != 0) {
		return -1;
	}
	for (uint32_t c = 0; c < t->ncols; c++) {
		bf_tcol *tc = &t->tcols[c];
		const bf_val *v = &vals[c];
		const bool null = v->is_null;

		tc->valid[row] = !null;
		if (tc->dict) {
			uint32_t id = 0;

			if (!null && bf_table_dict_id(t, r, c, v, &id) != 0) {
				return -1;
			}
			tc->ids[row] = id;
		}
		switch (tc->type) {
			case BF_INT64:
				tc->i[row] = null ? 0 : v->i;
				break;
			case BF_FLOAT64:
				tc->d[row] = null ? 0.0 : v->d;
				break;
			case BF_BOOL:
				tc->b[row] = null ? 0 : v->b;
				break;
			case BF_STRING:
				if (tc->dict) {
					break;
				}
				if (null) {
					tc->off[row] = 0;
					tc->len[row] = 0;
					break;
				}
				if (v->n > t->arena_cap - t->arena_len) {
					size_t need;

					if (__builtin_add_overflow(t->arena_len, v->n, &need)) {
						return bf_fail_row(r, BF_E_TABLE_BYTES, -1, 0, 0);
					}
					if (bf_table_grow_arena(t, r, need) != 0) {
						return -1;
					}
				}
				tc->off[row] = t->arena_len;
				tc->len[row] = (uint32_t)v->n;
				if (v->n > 0) {
					memcpy(t->arena + t->arena_len, v->s, v->n);
					t->arena_len += v->n;
				}
				break;
		}
	}
	t->rows = row + 1;
	return 0;
}

void bf_table_free(bf_table *t)
{
	const bf_host *h = &t->host;

	/* Non-empty only after a fatal inside bf_table_dict_id: the arrays of the column then being added to. */
	if (t->dict_run != NULL) {
		t->dict_run->host = h;
		bf_groups_release(t->dict_run);
	}
	for (uint32_t c = 0; t->tcols != NULL && c < t->ncols; c++) {
		bf_tcol *tc = &t->tcols[c];
		void *const parts[] = { tc->valid, tc->i, tc->d, tc->b, tc->off, tc->len, tc->ids };

		for (size_t k = 0; k < sizeof(parts) / sizeof(parts[0]); k++) {
			if (parts[k] != NULL) {
				h->free(h->ud, parts[k]);
			}
		}
		if (tc->dict && t->dict_run != NULL) {
			t->dict_run->host = h;
			t->dict_run->groups = tc->values;
			bf_groups_release(t->dict_run);
		}
	}
	if (t->tcols != NULL) {
		h->free(h->ud, t->tcols);
	}
	if (t->dict_run != NULL) {
		h->free(h->ud, t->dict_run);
	}
	if (t->arena != NULL) {
		h->free(h->ud, t->arena);
	}
	t->tcols = NULL;
	t->dict_run = NULL;
	t->arena = NULL;
}
