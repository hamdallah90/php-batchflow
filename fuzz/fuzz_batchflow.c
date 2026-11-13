/*
 * libFuzzer harness over the BatchFlow core, linked without PHP.
 *  1. All compiled mask functions agree over arbitrary bytes.
 *  2. An independent byte-at-a-time CSV parser and value decoder agrees with the core: results and
 *     the exact error (reason, row, line, offset, column, numbers).
 *  3. Every read-chunking pattern and every compiled mask function produce the same outcome.
 *  4. An injected read failure, a refused mem_fits, and a timeout flag end the run cleanly.
 *  5. Group-by (SPEC 5.9, 6.3, 6.4): the core's table under two fixed hash keys, and under a shim that gives
 *     every key one hash, equals an independent reference map, group order, maxGroups and the
 *     maxGroupBytes capacity rule included.
 *  6. The delimiter byte (every mask function under all four delimiters, and the reference parser), the
 *     predicates ne, lt, gte, lte and in (the reference tests set membership linearly), the aggregate list of
 *     SPEC 5.8, and the groupAgg tuple table of 6.3 against a reference map of key tuples, the identical-hash
 *     shim and the 6.4 capacity rule over the tuple arrays included.
 *  7. The rows loop of toArray (mode 3): every column decoded, the emitted row stream and maxRows against the
 *     reference, and a refused row budget (emit_row answering no memory) ending the run cleanly.
 *  8. The same rows run paused after every k emitted rows (k from 1 to 8, the cursor's fetch) and resumed
 *     until it ends: the row stream and the first error, position included, equal the unpaused run.
 *
 * Input layout: 48 config bytes, then the CSV body; config byte 0 bit 6 prepends a valid header.
 */
#define _GNU_SOURCE
#include <inttypes.h>
#include <locale.h>
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "bf_core.h"

#define CFG_BYTES 48
#define MAX_COLS  6

static locale_t c_locale;

/* ---- scripted host ---- */

typedef struct {
	uint8_t *buf;
	size_t len, cap;
} bytes;

typedef struct {
	const uint8_t *data;
	size_t len, pos;
	size_t chunk;
	size_t granted;      /* bytes the last mem_fits allowed and no allocation has used yet */
	long fail_read_at, refuse_mem_at, interrupt_at;
	long reads, mems, interrupts;
	/* emit_row: the row stream is recorded; the row budget can refuse at one row */
	const bf_col *cols;
	bytes *rows;
	long refuse_emit_at, emits;
	long pause_every;    /* emit_row pauses after every k-th row; 0 never */
} fuzz_ud;

static void bytes_put(bytes *b, const void *p, size_t n)
{
	if (b->len + n > b->cap) {
		b->cap = (b->len + n) * 2 + 64;
		b->buf = realloc(b->buf, b->cap);
		if (b->buf == NULL) {
			abort();
		}
	}
	if (n > 0) {
		memcpy(b->buf + b->len, p, n);
	}
	b->len += n;
}

/* One emitted row, serialized: per column a tag (0 NULL, 1 int64, 2 float64 bits, 3 bool, 4 string) and its value. */
static void row_put(bytes *b, const bf_col *cols, uint32_t ncols, const bool *is_null, const int64_t *iv, const double *dv,
	const int *bv, const uint8_t *const *sv, const size_t *nv)
{
	for (uint32_t k = 0; k < ncols; k++) {
		const uint8_t tag = is_null[k] ? 0 : (uint8_t)(cols[k].type == BF_STRING ? 4 : (uint8_t)cols[k].type + 1);

		bytes_put(b, &tag, 1);
		if (tag == 1) {
			bytes_put(b, &iv[k], 8);
		} else if (tag == 2) {
			bytes_put(b, &dv[k], 8);
		} else if (tag == 3) {
			const uint8_t x = (uint8_t)bv[k];

			bytes_put(b, &x, 1);
		} else if (tag == 4) {
			const uint64_t len = nv[k];

			bytes_put(b, &len, 8);
			bytes_put(b, sv[k], nv[k]);
		}
	}
	bytes_put(b, "\n", 1);
}

static bf_emit_status h_emit_row(void *ud, const bf_val *vals, size_t n)
{
	fuzz_ud *u = ud;
	bool is_null[MAX_COLS];
	int64_t iv[MAX_COLS];
	double dv[MAX_COLS];
	int bv[MAX_COLS];
	const uint8_t *sv[MAX_COLS];
	size_t nv[MAX_COLS];

	if (u->emits++ == u->refuse_emit_at) {
		return BF_EMIT_NO_MEMORY;
	}
	if (n > MAX_COLS) {
		abort();
	}
	for (size_t k = 0; k < n; k++) {
		is_null[k] = vals[k].is_null;
		iv[k] = vals[k].i;
		dv[k] = vals[k].d;
		bv[k] = vals[k].b;
		sv[k] = (const uint8_t *)vals[k].s;
		nv[k] = vals[k].n;
	}
	row_put(u->rows, u->cols, (uint32_t)n, is_null, iv, dv, bv, sv, nv);
	return u->pause_every > 0 && u->emits % u->pause_every == 0 ? BF_EMIT_PAUSE : BF_EMIT_CONTINUE;
}

/* SPEC 6.4: every growth is covered by the mem_fits call before it. Blocks carry their size so a shrink is free. */
static void *h_block(fuzz_ud *u, void *q, size_t n)
{
	size_t *b = q != NULL ? (size_t *)q - 2 : NULL;

	if (q == NULL || n > b[0]) {
		if (n > u->granted) {
			abort();
		}
		u->granted -= n;
	}
	b = realloc(b, n + 2 * sizeof(size_t));
	if (b == NULL) {
		abort();
	}
	b[0] = n;
	return b + 2;
}

static void *h_alloc(void *ud, size_t n)
{
	return h_block(ud, NULL, n);
}

static void *h_realloc(void *ud, void *q, size_t n)
{
	return h_block(ud, q, n);
}

static void h_free(void *ud, void *p)
{
	(void)ud;
	free((size_t *)p - 2);
}

static bool h_mem_fits(void *ud, size_t n)
{
	fuzz_ud *u = ud;
	bool ok = u->mems++ != u->refuse_mem_at;

	u->granted = ok ? n : 0;
	return ok;
}

static bf_read_status h_read(void *ud, char *buf, size_t want, size_t *got)
{
	fuzz_ud *u = ud;
	size_t n;

	if (u->reads++ == u->fail_read_at) {
		return BF_READ_FAILED;
	}
	if (u->pos == u->len) {
		return BF_READ_EOF;
	}
	n = u->len - u->pos;
	if (n > want) {
		n = want;
	}
	if (n > u->chunk) {
		n = u->chunk;
	}
	memcpy(buf, u->data + u->pos, n);
	u->pos += n;
	*got = n;
	return BF_READ_DATA;
}

static bool h_strtod(void *ud, const char *s, size_t n, double *d)
{
	char *copy = malloc(n + 1), *end;

	(void)ud;
	if (copy == NULL) {
		abort();
	}
	memcpy(copy, s, n);
	copy[n] = '\0';
	*d = strtod_l(copy, &end, c_locale);
	if (end != copy + n) {
		abort();
	}
	free(copy);
	return true;
}

static bool h_interrupted(void *ud)
{
	fuzz_ud *u = ud;

	return u->interrupts++ == u->interrupt_at;
}

/* ---- config ---- */

typedef struct {
	bf_col cols[MAX_COLS];
	char names[MAX_COLS][4];
	uint32_t ncols;
	bool allow_extra, auto_header;
	size_t max_row, max_field;
	uint64_t max_input;
	uint32_t npreds;
	bf_pred preds[4];
	char lit[4][8];
	int32_t sum_col;
	int32_t group_col;
	uint32_t max_groups;
	uint64_t max_group_bytes;
	uint8_t chunk_sel;
	unsigned char delim;
	int mode;                    /* 0 count, sum and group-by as before; 1 aggregate(); 2 groupAgg(); 3 toArray() */
	uint64_t max_rows;
	bf_agg aggs[BF_MAX_AGGS];
	uint32_t naggs;
	uint32_t gkeys[BF_MAX_GROUP_KEYS];
	uint32_t ngkeys;
	/* whereIn: the raw list for the reference, the sorted distinct set the glue would build for the core */
	uint32_t nraw[4];
	int64_t raw_i[4][3];
	double raw_d[4][3];
	char raw_s[4][3][2];
	size_t raw_n[4][3];
	int64_t set_i[4][3];
	double set_d[4][3];
	uint64_t set_s[4][4 + 2];    /* 4 offsets, then at most 3 bytes of string data */
} config;

static const unsigned char DELIMS[4] = { ',', ';', '\t', '|' };

/* Small maxGroupBytes values put each first allocation of 6.4 on a boundary: 12000 holds 1024 slots and 128
   int-key entries but not 256; 70000 holds slots, arrays and a 32 KiB arena but not the 64 KiB one. */
static const uint64_t GROUP_BYTES[8] = { 8192, 12000, 16384, 20000, 40000, 70000, 140000, 1 << 20 };

static const size_t LIMITS[16] = {
	1, 2, 3, 4, 5, 7, 8, 16, 63, 64, 65, 128, 1024, 65536, 1 << 20, 1 << 24
};

static void config_parse(const uint8_t *b, config *c)
{
	memset(c, 0, sizeof(*c));
	c->ncols = (b[0] & 7) % MAX_COLS + 1;
	c->allow_extra = (b[0] & 0x80) != 0;
	c->auto_header = (b[0] & 0x40) != 0;
	for (uint32_t i = 0; i < c->ncols; i++) {
		snprintf(c->names[i], sizeof(c->names[i]), "c%u", i);
		c->cols[i].name = c->names[i];
		c->cols[i].name_len = strlen(c->names[i]);
		c->cols[i].type = (bf_type)((b[1 + i / 4] >> ((i % 4) * 2)) & 3);
	}
	c->max_row = (b[3] & 0x10) ? (size_t)1 << 24 : LIMITS[b[3] & 15];
	c->max_field = (b[4] & 0x10) ? (size_t)1 << 20 : LIMITS[b[4] & 15];
	c->max_input = (b[5] & 0x80) ? UINT64_C(100000000) : (uint64_t)(b[5] & 0x3F) + 1;
	c->sum_col = (b[6] & 8) ? (int32_t)(b[6] & 7) % (int32_t)c->ncols : -1;
	if (c->sum_col >= 0 && c->cols[c->sum_col].type != BF_INT64 && c->cols[c->sum_col].type != BF_FLOAT64) {
		c->sum_col = -1;
	}
	c->npreds = (b[7] >> 5) % 4;
	for (uint32_t i = 0; i < c->npreds; i++) {
		bf_pred *p = &c->preds[i];
		const uint8_t v = b[8 + i * 2];
		uint32_t col = (b[9 + i * 2] & 7) % c->ncols;
		const bool want_null = (b[9 + i * 2] & 0x80) != 0;

		const int op = (b[9 + i * 2] >> 4) & 7; /* 1 ne, 2 lt, 3 gte, 4 lte, 5 and 6 in; else bit 3 picks gt or eq */
		const bool gt = (b[9 + i * 2] & 8) != 0;

		p->col = col;
		if (op == 5 || op == 6) {
			/* whereIn: up to three elements and null; the core gets the sorted distinct set. */
			const uint8_t e[3] = { v, b[38 + i * 3], b[39 + i * 3] };
			const uint32_t ne = 1 + (uint32_t)(b[40 + i * 3] % 3);
			uint32_t k = 0;

			c->nraw[i] = ne;
			p->b = want_null;
			for (uint32_t j = 0; j < ne; j++) {
				c->raw_i[i][j] = (int64_t)(int8_t)e[j];
				c->raw_d[i][j] = e[j] == 0xFF ? -0.0 : (double)(int8_t)e[j] / 4.0;
				c->raw_s[i][j][0] = (char)('a' + e[j] % 4);
				c->raw_n[i][j] = e[j] % 5 == 4 ? 0 : 1;
			}
			switch (c->cols[col].type) {
				case BF_INT64:
					for (uint32_t j = 0; j < ne; j++) {
						uint32_t at = 0;

						while (at < k && c->set_i[i][at] < c->raw_i[i][j]) { at++; }
						if (at < k && c->set_i[i][at] == c->raw_i[i][j]) { continue; }
						memmove(&c->set_i[i][at + 1], &c->set_i[i][at], (k - at) * sizeof(int64_t));
						c->set_i[i][at] = c->raw_i[i][j];
						k++;
					}
					p->kind = BF_P_INT_IN;
					p->set = c->set_i[i];
					break;
				case BF_FLOAT64:
					for (uint32_t j = 0; j < ne; j++) {
						const double d = c->raw_d[i][j] == 0.0 ? 0.0 : c->raw_d[i][j];
						uint32_t at = 0;

						while (at < k && c->set_d[i][at] < d) { at++; }
						if (at < k && c->set_d[i][at] == d) { continue; }
						memmove(&c->set_d[i][at + 1], &c->set_d[i][at], (k - at) * sizeof(double));
						c->set_d[i][at] = d;
						k++;
					}
					p->kind = BF_P_FLOAT_IN;
					p->set = c->set_d[i];
					break;
				case BF_BOOL:
					for (uint32_t j = 0; j < ne; j++) {
						p->i |= (int64_t)1 << (e[j] & 1);
					}
					p->kind = BF_P_BOOL_IN;
					break;
				case BF_STRING: {
					/* Elements are "" or one of a b c d: sorted by length, then bytes, the empty string first; the bytes follow
					   the k + 1 offsets, as SPEC 5.7's set layout in bf_core.h says. */
					bool have[5] = { false };
					char chars[4];
					uint32_t nchars = 0;
					char *bytes;

					for (uint32_t j = 0; j < ne; j++) {
						have[c->raw_n[i][j] == 0 ? 0 : 1 + (e[j] % 4)] = true;
					}
					for (uint32_t h = 0; h < 5; h++) {
						k += have[h];
					}
					c->set_s[i][0] = 0;
					for (uint32_t h = 0, at = 0; h < 5; h++) {
						if (have[h]) {
							if (h > 0) {
								chars[nchars++] = (char)('a' + h - 1);
							}
							c->set_s[i][at + 1] = c->set_s[i][at] + (h > 0);
							at++;
						}
					}
					bytes = (char *)(c->set_s[i] + k + 1);
					memcpy(bytes, chars, nchars);
					p->kind = BF_P_STR_IN;
					p->set = c->set_s[i];
					break;
				}
			}
			p->n = k;
			continue;
		}
		switch (c->cols[col].type) {
			case BF_INT64: {
				static const bf_pred_kind k[] = { BF_P_INT_EQ, BF_P_INT_NE, BF_P_INT_LT, BF_P_INT_GTE, BF_P_INT_LTE };

				p->kind = want_null ? (op == 1 ? BF_P_NOT_NULL : BF_P_IS_NULL) : (op >= 1 && op <= 4 ? k[op] : (gt ? BF_P_INT_GT : BF_P_INT_EQ));
				p->i = (int64_t)(int8_t)v;
				break;
			}
			case BF_FLOAT64: {
				static const bf_pred_kind k[] = { BF_P_FLOAT_EQ, BF_P_FLOAT_NE, BF_P_FLOAT_LT, BF_P_FLOAT_GTE, BF_P_FLOAT_LTE };

				p->kind = want_null ? (op == 1 ? BF_P_NOT_NULL : BF_P_IS_NULL) : (op >= 1 && op <= 4 ? k[op] : (gt ? BF_P_FLOAT_GT : BF_P_FLOAT_EQ));
				p->d = v == 0xFF ? -0.0 : (double)(int8_t)v / 4.0;
				break;
			}
			case BF_BOOL:
				p->kind = want_null ? (op == 1 ? BF_P_NOT_NULL : BF_P_IS_NULL) : (op == 1 ? BF_P_BOOL_NE : BF_P_BOOL_EQ);
				p->b = (v & 1) != 0;
				break;
			case BF_STRING:
				if (want_null) {
					p->kind = op == 1 ? BF_P_NOT_NULL : BF_P_IS_NULL;
				} else {
					p->kind = op == 1 ? BF_P_STR_NE : BF_P_STR_EQ;
					memcpy(c->lit[i], b + 8 + i * 2, 2);
					c->lit[i][2] = '\0';
					p->s = c->lit[i];
					p->n = v & 1 ? 1 : 2;
				}
				break;
		}
	}
	c->group_col = -1;
	if (b[7] & 0x10) {
		int32_t k = (int32_t)(b[7] & 7) % (int32_t)c->ncols;

		if (c->cols[k].type == BF_STRING || c->cols[k].type == BF_INT64) {
			c->group_col = k;
		}
	}
	c->max_groups = (b[14] & 0x80) ? UINT32_C(100000000) : (uint32_t)(b[14] & 0x7F) + 1;
	c->max_group_bytes = (b[5] & 0x40) ? GROUP_BYTES[(b[3] >> 5) & 7] : (UINT64_C(1) << 40);
	c->chunk_sel = b[15];
	c->delim = DELIMS[b[16] & 3];
	c->mode = (b[16] >> 2) & 3;
	if (c->mode == 1 || c->mode == 2) {
		/* aggregate() has 1 to 16 pairs, groupAgg() 0 to 15; sum, min, max and avg need a numeric column. */
		c->naggs = (uint32_t)(b[16] >> 4) + (c->mode == 1 ? 1 : 0);
		for (uint32_t k = 0; k < c->naggs; k++) {
			const uint8_t x = b[22 + k];
			const uint32_t col = (uint32_t)((x >> 3) & 7) % c->ncols;

			c->aggs[k].op = (bf_agg_op)((x & 7) % 5);
			c->aggs[k].col = col;
			if (c->aggs[k].op != BF_AGG_COUNT && c->cols[col].type != BF_INT64 && c->cols[col].type != BF_FLOAT64) {
				c->aggs[k].op = BF_AGG_COUNT;
			}
		}
		c->sum_col = -1;
		c->group_col = -1;
	}
	if (c->mode == 2) {
		/* Distinct string or int64 key columns, in byte order; none left means a plain aggregate run. */
		for (uint32_t k = 0; k < 1 + (uint32_t)(b[17] & 3); k++) {
			const uint32_t col = b[18 + k] % c->ncols;
			bool seen = false;

			for (uint32_t j = 0; j < c->ngkeys; j++) {
				seen |= c->gkeys[j] == col;
			}
			if (!seen && (c->cols[col].type == BF_STRING || c->cols[col].type == BF_INT64)) {
				c->gkeys[c->ngkeys++] = col;
			}
		}
		if (c->ngkeys == 0) {
			c->mode = c->naggs > 0 ? 1 : 0;
		}
	} else if (c->mode == 3) {
		/* toArray(): every column referenced, maxRows 1 to 128 or unbounded; no sum, group or aggregate. */
		c->max_rows = (b[14] & 0x80) ? UINT64_MAX : (uint64_t)(b[14] & 0x7F) + 1;
		c->sum_col = -1;
		c->group_col = -1;
	}
}

/* ---- outcome ---- */

typedef struct {
	bool is_null;
	int64_t i;
	uint8_t *s;
	size_t n;
} rkey;

typedef struct {
	bool is_null;
	int64_t i;
	uint8_t *s;
	size_t n;
	uint64_t count;
	int64_t isum;
	double fsum;
	rkey keys[BF_MAX_GROUP_KEYS];  /* groupAgg */
	bf_agg_state st[BF_MAX_AGGS];
} rgroup;

typedef struct {
	bool ok;
	bf_error err;
	uint64_t scanned, matched;
	int64_t isum;
	double fsum;
	rgroup *groups;
	size_t ngroups, gcap;
	bf_agg_state agg[BF_MAX_AGGS];  /* aggregate() */
	bytes rows;                     /* toArray(): the rows emitted, serialized by row_put, error or not */
} outcome;

static rgroup *outcome_add_group(outcome *o)
{
	if (o->ngroups == o->gcap) {
		o->gcap = o->gcap ? o->gcap * 2 : 64;
		o->groups = realloc(o->groups, o->gcap * sizeof(rgroup));
		if (o->groups == NULL) {
			abort();
		}
	}
	memset(&o->groups[o->ngroups], 0, sizeof(rgroup));
	return &o->groups[o->ngroups++];
}

static void outcome_free(outcome *o)
{
	for (size_t i = 0; i < o->ngroups; i++) {
		free(o->groups[i].s);
		for (uint32_t k = 0; k < BF_MAX_GROUP_KEYS; k++) {
			free(o->groups[i].keys[k].s);
		}
	}
	free(o->groups);
	o->groups = NULL;
	o->ngroups = o->gcap = 0;
	free(o->rows.buf);
	memset(&o->rows, 0, sizeof(o->rows));
}

static void outcome_check_equal(const outcome *a, const outcome *b, const char *ctx)
{
	/* The rows emitted before an error too: nothing after the failing row, nothing missing before it. */
	if (a->rows.len != b->rows.len || (a->rows.len > 0 && memcmp(a->rows.buf, b->rows.buf, a->rows.len) != 0)) {
		fprintf(stderr, "%s: emitted rows differ (%zu vs %zu bytes)\n", ctx, a->rows.len, b->rows.len);
		abort();
	}
	if (a->ok != b->ok) {
		fprintf(stderr, "%s: ok %d vs %d (reason %d vs %d)\n", ctx, a->ok, b->ok, a->err.reason, b->err.reason);
		abort();
	}
	if (a->ok) {
		if (a->scanned != b->scanned || a->matched != b->matched || a->isum != b->isum
			|| memcmp(&a->fsum, &b->fsum, sizeof(double)) != 0) {
			fprintf(stderr, "%s: results differ %" PRIu64 "/%" PRIu64 " %" PRIu64 "/%" PRIu64 " %" PRId64 "/%" PRId64 " %a/%a\n",
				ctx, a->scanned, b->scanned, a->matched, b->matched, a->isum, b->isum, a->fsum, b->fsum);
			abort();
		}
		if (a->ngroups != b->ngroups) {
			fprintf(stderr, "%s: %zu groups vs %zu\n", ctx, a->ngroups, b->ngroups);
			abort();
		}
		for (size_t i = 0; i < a->ngroups; i++) {
			const rgroup *x = &a->groups[i], *y = &b->groups[i];

			if (x->is_null != y->is_null || (!x->is_null && (x->i != y->i || x->n != y->n || (x->n > 0 && memcmp(x->s, y->s, x->n) != 0)))
				|| x->count != y->count || x->isum != y->isum || memcmp(&x->fsum, &y->fsum, sizeof(double)) != 0) {
				fprintf(stderr, "%s: group %zu differs (null %d/%d, count %" PRIu64 "/%" PRIu64 ")\n", ctx, i, x->is_null, y->is_null, x->count, y->count);
				abort();
			}
			for (uint32_t k = 0; k < BF_MAX_GROUP_KEYS; k++) {
				const rkey *p = &x->keys[k], *q = &y->keys[k];

				if (p->is_null != q->is_null || p->i != q->i || p->n != q->n || (p->n > 0 && memcmp(p->s, q->s, p->n) != 0)) {
					fprintf(stderr, "%s: group %zu key %u differs\n", ctx, i, k);
					abort();
				}
			}
			if (memcmp(x->st, y->st, sizeof(x->st)) != 0) {
				fprintf(stderr, "%s: group %zu aggregate states differ\n", ctx, i);
				abort();
			}
		}
		if (memcmp(a->agg, b->agg, sizeof(a->agg)) != 0) {
			fprintf(stderr, "%s: aggregate states differ\n", ctx);
			abort();
		}
		return;
	}
	if (a->err.reason != b->err.reason || a->err.where != b->err.where || a->err.column != b->err.column
		|| a->err.n1 != b->err.n1 || a->err.n2 != b->err.n2) {
		fprintf(stderr, "%s: error %d/%d where %d/%d col %d/%d n %" PRIu64 "/%" PRIu64 " %" PRIu64 "/%" PRIu64 "\n",
			ctx, a->err.reason, b->err.reason, a->err.where, b->err.where, a->err.column, b->err.column,
			a->err.n1, b->err.n1, a->err.n2, b->err.n2);
		abort();
	}
	if (a->err.where != BF_WHERE_NONE
		&& (a->err.row != b->err.row || a->err.line != b->err.line || a->err.offset != b->err.offset)) {
		fprintf(stderr, "%s: position r%" PRIu64 "/%" PRIu64 " l%" PRIu64 "/%" PRIu64 " o%" PRIu64 "/%" PRIu64 " reason %d\n",
			ctx, a->err.row, b->err.row, a->err.line, b->err.line, a->err.offset, b->err.offset, a->err.reason);
		abort();
	}
}

/* ---- reference: byte-at-a-time framing and decoding, independent of the core ---- */

typedef struct {
	size_t start, raw_len, len;
	bool quoted, doubled;
} rf_field;

typedef enum { RF_OK, RF_EOF, RF_FAIL } rf_status;

typedef struct {
	const uint8_t *in;
	size_t len, pos;
	size_t max_row;
	size_t nfile;       /* required field count; 0 while reading the header */
	rf_field *fields;
	size_t cap, n;
	uint64_t line;
	uint64_t row;       /* data rows framed */
	uint64_t max_input;
	unsigned char delim;
	outcome *o;
} rf_state;

static void rf_fail(rf_state *s, bf_reason reason, bf_where where, uint64_t line, uint64_t offset, int32_t col, uint64_t n1, uint64_t n2)
{
	s->o->ok = false;
	s->o->err.reason = reason;
	s->o->err.where = where;
	s->o->err.row = where == BF_WHERE_ROW ? s->row : 0;
	s->o->err.line = line;
	s->o->err.offset = offset;
	s->o->err.column = col;
	s->o->err.n1 = n1;
	s->o->err.n2 = n2;
}

static void rf_push_field(rf_state *s, size_t start, size_t raw_len, bool quoted, bool doubled)
{
	if (s->n == s->cap) {
		s->cap = s->cap ? s->cap * 2 : 64;
		s->fields = realloc(s->fields, s->cap * sizeof(rf_field));
		if (s->fields == NULL) {
			abort();
		}
	}
	s->fields[s->n].start = start;
	s->fields[s->n].raw_len = raw_len;
	s->fields[s->n].len = raw_len;
	s->fields[s->n].quoted = quoted;
	s->fields[s->n].doubled = doubled;
	s->n++;
}

/* Frames one record starting at s->pos. A byte at index max_row that is not the terminator fails. */
static rf_status rf_read_record(rf_state *s, bool header, bf_where where)
{
	enum { START, UNQUOTED, QUOTED, AFTER } st = START;
	const size_t r0 = s->pos;
	const size_t fmax = header ? 4096 : s->nfile;
	size_t j = r0, fstart = j, raw_end = j;
	bool doubled = false;
	uint64_t quoted_lf = 0;

	s->n = 0;
	if (j == s->len) {
		return RF_EOF;
	}
	for (;;) {
		bool at_limit = j - r0 == s->max_row;
		bf_reason framing = BF_E_NONE;

		if (j == s->len) {
			if (st == QUOTED) {
				rf_fail(s, BF_E_UNTERMINATED_QUOTE, where, s->line, r0, -1, 0, 0);
				return RF_FAIL;
			}
			rf_push_field(s, fstart, (st == AFTER ? raw_end : j) - fstart, st == AFTER, doubled);
			if (!header && s->n != s->nfile) {
				rf_fail(s, BF_E_FIELD_COUNT_LOW, where, s->line, r0, -1, s->nfile, s->n);
				return RF_FAIL;
			}
			s->pos = j;
			return RF_OK;
		}

		uint8_t c = s->in[j];

		switch (st) {
			case START:
				if (c == '"') {
					if (at_limit) {
						goto too_long;
					}
					st = QUOTED;
					fstart = j + 1;
					doubled = false;
					j++;
					continue;
				}
				st = UNQUOTED;
				/* fallthrough */
			case UNQUOTED:
				if (c == '"') {
					framing = BF_E_BARE_QUOTE;
					goto fail_here;
				}
				break;
			case QUOTED:
				if (at_limit) {
					goto too_long;
				}
				if (c == '"') {
					if (j + 1 < s->len && s->in[j + 1] == '"') {
						if (j + 1 - r0 == s->max_row) {
							goto too_long;
						}
						doubled = true;
						j += 2;
						continue;
					}
					raw_end = j;
					st = AFTER;
					j++;
					continue;
				}
				quoted_lf += c == '\n';
				j++;
				continue;
			case AFTER:
				if (c != s->delim && c != '\n' && c != '\r') {
					framing = BF_E_TEXT_AFTER_QUOTE;
					goto fail_here;
				}
				break;
		}

		/* Field terminators: the delimiter, LF, CR. */
		if (c == s->delim || c == '\n' || c == '\r') {
			size_t end = st == AFTER ? raw_end : j;
			bool terminator = c == '\n' || (c == '\r' && j + 1 < s->len && s->in[j + 1] == '\n');

			if (c == '\r' && !terminator) {
				framing = BF_E_BARE_CR;
				goto fail_here;
			}
			if (c == s->delim) {
				rf_push_field(s, fstart, end - fstart, st == AFTER, doubled);
				if (s->n == fmax) {
					framing = header ? BF_E_TOO_MANY_COLUMNS : BF_E_FIELD_COUNT_HIGH;
					s->n--;
					goto fail_here;
				}
				if (at_limit) {
					goto too_long;
				}
				st = START;
				fstart = j + 1;
				doubled = false;
				j++;
				continue;
			}
			rf_push_field(s, fstart, end - fstart, st == AFTER, doubled);
			if (!header && s->n != s->nfile) {
				/* Reported at the terminator, which sits at or before the limit here. */
				if (s->n > s->nfile) {
					abort();
				}
				if (j - r0 > s->max_row) {
					goto too_long;
				}
				rf_fail(s, BF_E_FIELD_COUNT_LOW, where, s->line, r0, -1, s->nfile, s->n);
				return RF_FAIL;
			}
			if (j - r0 > s->max_row) {
				goto too_long;
			}
			s->pos = j + (c == '\r' ? 2 : 1);
			s->line += quoted_lf + 1;
			return RF_OK;
		}

		if (at_limit) {
			goto too_long;
		}
		j++;
		continue;

fail_here:
		rf_fail(s, framing, where, s->line, r0, -1, framing == BF_E_FIELD_COUNT_HIGH ? s->nfile : 0, 0);
		return RF_FAIL;
too_long:
		rf_fail(s, BF_E_ROW_TOO_LONG, where, s->line, r0, -1, 0, 0);
		return RF_FAIL;
	}
}

/* ---- reference value decode ---- */

static bool rf_utf8(const uint8_t *s, size_t n)
{
	size_t i = 0;

	while (i < n) {
		uint8_t c = s[i];
		size_t need;
		uint8_t lo = 0x80, hi = 0xBF;

		if (c <= 0x7F) { i++; continue; }
		if (c >= 0xC2 && c <= 0xDF) { need = 1; }
		else if (c == 0xE0) { need = 2; lo = 0xA0; }
		else if (c >= 0xE1 && c <= 0xEC) { need = 2; }
		else if (c == 0xED) { need = 2; hi = 0x9F; }
		else if (c == 0xEE || c == 0xEF) { need = 2; }
		else if (c == 0xF0) { need = 3; lo = 0x90; }
		else if (c >= 0xF1 && c <= 0xF3) { need = 3; }
		else if (c == 0xF4) { need = 3; hi = 0x8F; }
		else { return false; }
		if (i + need >= n + (need == 0)) {
			if (i + need >= n) {
				return false;
			}
		}
		for (size_t k = 1; k <= need; k++) {
			uint8_t b = s[i + k];
			uint8_t lo2 = k == 1 ? lo : 0x80, hi2 = k == 1 ? hi : 0xBF;

			if (b < lo2 || b > hi2) {
				return false;
			}
		}
		i += need + 1;
	}
	return true;
}

static int rf_int(const uint8_t *s, size_t n, int64_t *out)
{
	size_t i = 0;
	bool neg = false;
	/* Magnitude in 64 unsigned bits with checked steps: no 128-bit multiply, so no compiler-rt helper to link. */
	uint64_t mag = 0;

	if (s[0] == '-') {
		neg = true;
		i = 1;
	}
	if (i == n) {
		return BF_KIND_MALFORMED + 1;
	}
	if (s[i] == '0' && (n - i > 1 || neg)) {
		return BF_KIND_MALFORMED + 1;
	}
	for (size_t k = i; k < n; k++) {
		if (s[k] < '0' || s[k] > '9') {
			return BF_KIND_MALFORMED + 1;
		}
	}
	for (size_t k = i; k < n; k++) {
		if (__builtin_mul_overflow(mag, (uint64_t)10, &mag) || __builtin_add_overflow(mag, (uint64_t)(s[k] - '0'), &mag)) {
			return BF_KIND_OUT_OF_RANGE + 1;
		}
	}
	if (mag > (uint64_t)INT64_MAX + (neg ? 1 : 0)) {
		return BF_KIND_OUT_OF_RANGE + 1;
	}
	/* -(mag - 1) - 1 reaches INT64_MIN without overflowing. */
	*out = neg ? (mag == 0 ? 0 : -(int64_t)(mag - 1) - 1) : (int64_t)mag;
	return 0;
}

/* [+-]?(d+(.d*)?|.d+)([eE][+-]?d+)? */
static bool rf_float_grammar(const uint8_t *s, size_t n)
{
	size_t i = 0;
	size_t digits = 0;

	if (i < n && (s[i] == '+' || s[i] == '-')) {
		i++;
	}
	while (i < n && s[i] >= '0' && s[i] <= '9') { i++; digits++; }
	if (i < n && s[i] == '.') {
		i++;
		while (i < n && s[i] >= '0' && s[i] <= '9') { i++; digits++; }
	}
	if (digits == 0) {
		return false;
	}
	if (i < n && (s[i] == 'e' || s[i] == 'E')) {
		size_t expd = 0;

		i++;
		if (i < n && (s[i] == '+' || s[i] == '-')) {
			i++;
		}
		while (i < n && s[i] >= '0' && s[i] <= '9') { i++; expd++; }
		if (expd == 0) {
			return false;
		}
	}
	return i == n;
}

/* SPEC 5.6: a field of 19,683 bytes or more with an exponent magnitude above 19999. */
static bool rf_float_exp_too_large(const uint8_t *s, size_t n)
{
	size_t i = 0;
	unsigned long e = 0;

	if (n < 19683) {
		return false;
	}
	while (i < n && s[i] != 'e' && s[i] != 'E') {
		i++;
	}
	if (i == n) {
		return false;
	}
	i++;
	if (i < n && (s[i] == '+' || s[i] == '-')) {
		i++;
	}
	for (; i < n; i++) {
		if (e <= 19999) {
			e = e * 10 + (unsigned long)(s[i] - '0');
		}
	}
	return e > 19999;
}

typedef struct {
	bool is_null;
	int64_t i;
	double d;
	int b;
	const uint8_t *s;
	size_t n;
} rf_value;

/* Quote removal into a scratch copy so the input stays intact. */
static const uint8_t *rf_field_bytes(const uint8_t *in, rf_field *f, uint8_t **scratch, size_t *scap)
{
	const uint8_t *raw = in + f->start;

	if (!f->doubled) {
		f->len = f->raw_len;
		return raw;
	}
	if (*scap < f->raw_len + 1) {
		*scap = f->raw_len + 1;
		*scratch = realloc(*scratch, *scap);
		if (*scratch == NULL) {
			abort();
		}
	}
	{
		size_t d = 0;

		for (size_t k = 0; k < f->raw_len; k++) {
			(*scratch)[d++] = raw[k];
			if (raw[k] == '"') {
				k++;
			}
		}
		f->len = d;
	}
	return *scratch;
}

/* ---- reference group map: FNV-1a chains, independent of the core's SipHash table ---- */

#define RMAP_BUCKETS 4096

typedef struct {
	int32_t heads[RMAP_BUCKETS];
	int32_t *next;
	size_t ncap;
	/* the SPEC 6.4 capacities: slots from 1024 doubling at load 3/4, arrays from 256 doubling clamped to
	   maxGroups, the key arena from 65,536 doubling until the key fits */
	uint64_t nslots, cap, arena_cap, arena_len, keyed;
	long null_gi;
} rmap;

static uint32_t rmap_hash(const rf_value *v, bool int_keys)
{
	uint64_t h = UINT64_C(14695981039346656037);
	const uint8_t *p = int_keys ? (const uint8_t *)&v->i : v->s;
	size_t n = int_keys ? sizeof(v->i) : v->n;

	for (size_t i = 0; i < n; i++) {
		h = (h ^ p[i]) * UINT64_C(1099511628211);
	}
	return (uint32_t)(h % RMAP_BUCKETS);
}

/* The group of v, or -1 when absent. */
static long rmap_find(const rmap *m, const outcome *o, const rf_value *v, bool int_keys)
{
	(void)o;
	if (v->is_null) {
		return m->null_gi;
	}
	for (int32_t i = m->heads[rmap_hash(v, int_keys)]; i >= 0; i = m->next[i]) {
		const rgroup *g = &o->groups[i];

		if (!g->is_null && (int_keys ? g->i == v->i : (g->n == v->n && (v->n == 0 || memcmp(g->s, v->s, v->n) == 0)))) {
			return i;
		}
	}
	return -1;
}

static bool rmap_fits(const config *c, const rmap *m, uint64_t nslots, uint64_t cap, uint64_t arena_cap)
{
	const bool int_keys = c->cols[c->group_col].type == BF_INT64;
	const uint64_t per = 8 + (int_keys ? 8 : 12) + 8 + (c->sum_col >= 0 ? 8 : 0);

	(void)m;
	return nslots * 8 + cap * per + arena_cap <= c->max_group_bytes;
}

/* Adds v as a new group: BF_E_NONE, or the reason the spec gives for refusing it. */
static bf_reason rmap_add(const config *c, rmap *m, outcome *o, const rf_value *v)
{
	const bool int_keys = c->cols[c->group_col].type == BF_INT64;
	rgroup *g;

	if (o->ngroups >= c->max_groups) {
		return BF_E_MAX_GROUPS;
	}
	if (!v->is_null && (m->nslots == 0 || (m->keyed + 1) * 4 > m->nslots * 3)) {
		m->nslots = m->nslots ? m->nslots * 2 : 1024;
		if (!rmap_fits(c, m, m->nslots, m->cap, m->arena_cap)) {
			return BF_E_GROUP_BYTES;
		}
	}
	if (o->ngroups == m->cap) {
		m->cap = m->cap ? m->cap * 2 : 256;
		if (m->cap > c->max_groups) {
			m->cap = c->max_groups;
		}
		if (!rmap_fits(c, m, m->nslots, m->cap, m->arena_cap)) {
			return BF_E_GROUP_BYTES;
		}
	}
	if (!int_keys && !v->is_null && m->arena_len + v->n > m->arena_cap) {
		uint64_t ncap = m->arena_cap ? m->arena_cap : 65536;

		while (ncap < m->arena_len + v->n) {
			ncap *= 2;
		}
		m->arena_cap = ncap;
		if (!rmap_fits(c, m, m->nslots, m->cap, m->arena_cap)) {
			return BF_E_GROUP_BYTES;
		}
	}
	if (o->ngroups == m->ncap) {
		m->ncap = m->ncap ? m->ncap * 2 : 64;
		m->next = realloc(m->next, m->ncap * sizeof(int32_t));
		if (m->next == NULL) {
			abort();
		}
	}
	g = outcome_add_group(o);
	g->is_null = v->is_null;
	if (v->is_null) {
		m->null_gi = (long)o->ngroups - 1;
	} else {
		uint32_t b = rmap_hash(v, int_keys);

		m->next[o->ngroups - 1] = m->heads[b];
		m->heads[b] = (int32_t)(o->ngroups - 1);
		m->keyed++;
		if (int_keys) {
			g->i = v->i;
		} else {
			m->arena_len += v->n;
			g->n = v->n;
			g->s = malloc(v->n + 1);
			if (g->s == NULL) {
				abort();
			}
			memcpy(g->s, v->s, v->n);
		}
	}
	return BF_E_NONE;
}

/* ---- reference aggregates (SPEC 5.8) and key tuples (5.9), written apart from the core ---- */

static bool rf_is_in(bf_pred_kind k)
{
	return k == BF_P_INT_IN || k == BF_P_FLOAT_IN || k == BF_P_BOOL_IN || k == BF_P_STR_IN;
}

/* Membership by a linear scan of the raw list: equal to some element by the whereEq rule. */
static bool rf_in(const config *c, uint32_t pi, const rf_value *v)
{
	const bf_pred *p = &c->preds[pi];

	for (uint32_t j = 0; j < c->nraw[pi]; j++) {
		switch (p->kind) {
			case BF_P_INT_IN: if (v->i == c->raw_i[pi][j]) { return true; } break;
			case BF_P_FLOAT_IN: if (v->d == c->raw_d[pi][j]) { return true; } break;
			case BF_P_BOOL_IN: if (v->b == (int)((uint8_t)c->raw_i[pi][j] & 1)) { return true; } break;
			case BF_P_STR_IN: if (v->n == c->raw_n[pi][j] && (v->n == 0 || memcmp(v->s, c->raw_s[pi][j], v->n) == 0)) { return true; } break;
			default: abort();
		}
	}
	return false;
}

/* One non-NULL value into one pair; false on an int64 overflow. */
static bool rf_agg_add(bf_agg_op op, bool is_int, bf_agg_state *st, const rf_value *v)
{
	if (op == BF_AGG_SUM || op == BF_AGG_AVG) {
		if (is_int) {
			if (__builtin_add_overflow(st->v.i, v->i, &st->v.i)) {
				return false;
			}
		} else {
			st->v.d = st->v.d + v->d;
		}
	} else if (op == BF_AGG_MIN && (st->n == 0 || (is_int ? v->i < st->v.i : v->d < st->v.d))) {
		if (is_int) { st->v.i = v->i; } else { st->v.d = v->d; }
	} else if (op == BF_AGG_MAX && (st->n == 0 || (is_int ? v->i > st->v.i : v->d > st->v.d))) {
		if (is_int) { st->v.i = v->i; } else { st->v.d = v->d; }
	}
	st->n++;
	return true;
}

/* The pairs of one matched row in list order: the column of the first overflow, or -1. */
static int32_t rf_aggs_row(const config *c, bf_agg_state *st, const rf_value *vals)
{
	for (uint32_t k = 0; k < c->naggs; k++) {
		const uint32_t col = c->aggs[k].col;

		if (!vals[col].is_null && !rf_agg_add(c->aggs[k].op, c->cols[col].type == BF_INT64, &st[k], &vals[col])) {
			return (int32_t)col;
		}
	}
	return -1;
}

/* After the scan: the column of the first non-finite float64 sum or avg pair in list order, or -1. */
static int32_t rf_aggs_finite(const config *c, const bf_agg_state *st)
{
	for (uint32_t k = 0; k < c->naggs; k++) {
		const bf_agg *a = &c->aggs[k];

		if ((a->op == BF_AGG_SUM || a->op == BF_AGG_AVG) && c->cols[a->col].type == BF_FLOAT64 && !isfinite(st[k].v.d)) {
			return (int32_t)a->col;
		}
	}
	return -1;
}

typedef struct {
	int32_t heads[RMAP_BUCKETS];
	int32_t *next;
	size_t ncap;
	uint64_t nslots, cap, arena_cap, arena_len;
} tmap;

static uint32_t tmap_hash(const config *c, const rf_value *vals)
{
	uint64_t h = UINT64_C(14695981039346656037);

	for (uint32_t k = 0; k < c->ngkeys; k++) {
		const rf_value *v = &vals[c->gkeys[k]];
		const bool is_int = c->cols[c->gkeys[k]].type == BF_INT64;
		const uint8_t *p = is_int ? (const uint8_t *)&v->i : v->s;
		const size_t n = v->is_null ? 0 : (is_int ? sizeof(v->i) : v->n);

		h = (h ^ (v->is_null ? 0x4E : 0x56)) * UINT64_C(1099511628211);
		for (size_t i = 0; i < n; i++) {
			h = (h ^ p[i]) * UINT64_C(1099511628211);
		}
		h = (h ^ 0x7C) * UINT64_C(1099511628211);
	}
	return (uint32_t)(h % RMAP_BUCKETS);
}

static bool tmap_same(const config *c, const rgroup *g, const rf_value *vals)
{
	for (uint32_t k = 0; k < c->ngkeys; k++) {
		const rf_value *v = &vals[c->gkeys[k]];
		const rkey *x = &g->keys[k];

		if (x->is_null != v->is_null) {
			return false;
		}
		if (!v->is_null && (c->cols[c->gkeys[k]].type == BF_INT64 ? x->i != v->i : (x->n != v->n || (v->n > 0 && memcmp(x->s, v->s, v->n) != 0)))) {
			return false;
		}
	}
	return true;
}

/* The group of the row's tuple, added at first sight: its index, or -1 with *why set (SPEC 5.9, 6.3, 6.4). */
static long tmap_group(const config *c, tmap *m, outcome *o, const rf_value *vals, bf_reason *why)
{
	const uint32_t b = tmap_hash(c, vals);
	const uint64_t per = 28 + 16 * (uint64_t)c->naggs;
	uint64_t enc = 0;
	rgroup *g;

	for (int32_t i = m->heads[b]; i >= 0; i = m->next[i]) {
		if (tmap_same(c, &o->groups[i], vals)) {
			return i;
		}
	}
	for (uint32_t k = 0; k < c->ngkeys; k++) {
		const rf_value *v = &vals[c->gkeys[k]];

		enc += 1 + (v->is_null ? 0 : (c->cols[c->gkeys[k]].type == BF_INT64 ? 8 : 4 + v->n));
	}
	*why = BF_E_MAX_GROUPS;
	if (o->ngroups >= c->max_groups) {
		return -1;
	}
	*why = BF_E_GROUP_BYTES;
	if (m->nslots == 0 || (o->ngroups + 1) * 4 > m->nslots * 3) {
		m->nslots = m->nslots ? m->nslots * 2 : 1024;
		if (m->nslots * 8 + m->cap * per + m->arena_cap > c->max_group_bytes) {
			return -1;
		}
	}
	if (o->ngroups == m->cap) {
		m->cap = m->cap ? m->cap * 2 : 256;
		m->cap = m->cap > c->max_groups ? c->max_groups : m->cap;
		if (m->nslots * 8 + m->cap * per + m->arena_cap > c->max_group_bytes) {
			return -1;
		}
	}
	if (m->arena_len + enc > m->arena_cap) {
		uint64_t ncap = m->arena_cap ? m->arena_cap : 65536;

		while (ncap < m->arena_len + enc) {
			ncap *= 2;
		}
		m->arena_cap = ncap;
		if (m->nslots * 8 + m->cap * per + m->arena_cap > c->max_group_bytes) {
			return -1;
		}
	}
	m->arena_len += enc;
	if (o->ngroups == m->ncap) {
		m->ncap = m->ncap ? m->ncap * 2 : 64;
		m->next = realloc(m->next, m->ncap * sizeof(int32_t));
		if (m->next == NULL) {
			abort();
		}
	}
	g = outcome_add_group(o);
	for (uint32_t k = 0; k < c->ngkeys; k++) {
		const rf_value *v = &vals[c->gkeys[k]];

		g->keys[k].is_null = v->is_null;
		if (!v->is_null && c->cols[c->gkeys[k]].type == BF_INT64) {
			g->keys[k].i = v->i;
		} else if (!v->is_null) {
			g->keys[k].n = v->n;
			g->keys[k].s = malloc(v->n + 1);
			if (g->keys[k].s == NULL) {
				abort();
			}
			memcpy(g->keys[k].s, v->s, v->n);
		}
	}
	m->next[o->ngroups - 1] = m->heads[b];
	m->heads[b] = (int32_t)(o->ngroups - 1);
	return (long)o->ngroups - 1;
}

static void ref_scan(const config *c, const uint8_t *in, size_t len, outcome *o)
{
	rmap *gm = calloc(1, sizeof(rmap));
	tmap *tm = calloc(1, sizeof(tmap));
	rf_state s;
	uint8_t *copies[MAX_COLS];
	size_t ncopies = 0;
	int32_t file_of[MAX_COLS];
	bool referenced[MAX_COLS] = { false };
	uint8_t *scratch = NULL;
	size_t scap = 0;
	bool sum_int;
	int64_t isum = 0;
	double fsum = 0.0;
	uint64_t matched = 0;

	if (gm == NULL || tm == NULL) {
		abort();
	}
	memset(gm->heads, 0xFF, sizeof(gm->heads));
	memset(tm->heads, 0xFF, sizeof(tm->heads));
	gm->null_gi = -1;
	memset(o, 0, sizeof(*o));
	memset(&s, 0, sizeof(s));
	s.in = in;
	s.len = len;
	s.max_row = c->max_row;
	s.max_input = c->max_input;
	s.delim = c->delim;
	s.line = 1;
	s.o = o;
	if (len >= 3 && in[0] == 0xEF && in[1] == 0xBB && in[2] == 0xBF) {
		s.pos = 3;
	}
	for (uint32_t i = 0; i < c->ncols; i++) {
		file_of[i] = -1;
	}

	/* Header. */
	{
		uint64_t hline = 1;
		size_t hoff = s.pos;
		rf_status st = rf_read_record(&s, true, BF_WHERE_HEADER);

		if (st == RF_EOF) {
			rf_fail(&s, BF_E_MISSING_HEADER, BF_WHERE_NONE, 1, hoff, -1, 0, 0);
			goto out;
		}
		if (st == RF_FAIL) {
			goto out;
		}
		s.nfile = s.n;
		for (size_t f = 0; f < s.n; f++) {
			const uint8_t *name = rf_field_bytes(in, &s.fields[f], &scratch, &scap);
			size_t namelen = s.fields[f].len;
			int found = -1;

			for (uint32_t k = 0; k < c->ncols; k++) {
				if (c->cols[k].name_len == namelen && memcmp(c->cols[k].name, name, namelen) == 0) {
					found = (int)k;
					break;
				}
			}
			if (found >= 0) {
				if (file_of[found] >= 0) {
					rf_fail(&s, BF_E_DUPLICATE_COLUMN, BF_WHERE_HEADER, hline, hoff, found, 0, 0);
					goto out;
				}
				file_of[found] = (int32_t)f;
			} else if (!c->allow_extra) {
				rf_fail(&s, BF_E_UNEXPECTED_COLUMN, BF_WHERE_HEADER, hline, hoff, -1, (uint64_t)f + 1, 0);
				goto out;
			}
		}
		for (uint32_t k = 0; k < c->ncols; k++) {
			if (file_of[k] < 0) {
				rf_fail(&s, BF_E_MISSING_COLUMN, BF_WHERE_HEADER, hline, hoff, (int32_t)k, 0, 0);
				goto out;
			}
		}
	}

	for (uint32_t i = 0; i < c->npreds; i++) {
		referenced[c->preds[i].col] = true;
	}
	if (c->sum_col >= 0) {
		referenced[c->sum_col] = true;
	}
	if (c->group_col >= 0) {
		referenced[c->group_col] = true;
	}
	for (uint32_t k = 0; k < c->naggs; k++) {
		referenced[c->aggs[k].col] = true;
	}
	for (uint32_t k = 0; k < c->ngkeys; k++) {
		referenced[c->gkeys[k]] = true;
	}
	for (uint32_t k = 0; c->mode == 3 && k < c->ncols; k++) {
		referenced[k] = true;
	}
	sum_int = c->sum_col >= 0 && c->cols[c->sum_col].type == BF_INT64;

	for (;;) {
		uint64_t line = s.line;
		size_t offset = s.pos;
		rf_value vals[MAX_COLS];
		rf_status st;

		s.row++;
		st = rf_read_record(&s, false, BF_WHERE_ROW);
		if (st == RF_EOF) {
			s.row--;
			break;
		}
		if (st == RF_FAIL) {
			goto out;
		}
		if (s.row > c->max_input) {
			rf_fail(&s, BF_E_INPUT_ROWS, BF_WHERE_ROW, line, offset, -1, 0, 0);
			goto out;
		}

		for (uint32_t k = 0; k < c->ncols; k++) {
			if (!referenced[k]) {
				continue;
			}
			{
				rf_field *f = &s.fields[file_of[k]];
				rf_value *v = &vals[k];
				const uint8_t *data;

				memset(v, 0, sizeof(*v));
				if (!f->quoted && f->raw_len == 0) {
					v->is_null = true;
					continue;
				}
				data = rf_field_bytes(in, f, &scratch, &scap);
				if (f->len > c->max_field) {
					rf_fail(&s, BF_E_FIELD_TOO_LONG, BF_WHERE_ROW, line, offset, (int32_t)k, 0, 0);
					goto out;
				}
				if (c->cols[k].type == BF_STRING) {
					if (!rf_utf8(data, f->len)) {
						rf_fail(&s, BF_E_BAD_UTF8, BF_WHERE_ROW, line, offset, (int32_t)k, 0, 0);
						goto out;
					}
					v->s = data;
					v->n = f->len;
					/* Doubled strings live in scratch only until the next field: copy. */
					if (f->doubled) {
						uint8_t *cp = malloc(f->len + 1);

						if (cp == NULL) { abort(); }
						memcpy(cp, data, f->len);
						v->s = cp;
						copies[ncopies++] = cp;
					}
					continue;
				}
				if (f->len == 0) {
					rf_fail(&s, BF_E_BAD_VALUE, BF_WHERE_ROW, line, offset, (int32_t)k, BF_KIND_QUOTED_EMPTY, 0);
					goto out;
				}
				if (f->doubled) {
					rf_fail(&s, BF_E_BAD_VALUE, BF_WHERE_ROW, line, offset, (int32_t)k, BF_KIND_MALFORMED, 0);
					goto out;
				}
				if (c->cols[k].type == BF_INT64) {
					int rc = rf_int(data, f->len, &v->i);

					if (rc != 0) {
						rf_fail(&s, BF_E_BAD_VALUE, BF_WHERE_ROW, line, offset, (int32_t)k, (uint64_t)(rc - 1), 0);
						goto out;
					}
				} else if (c->cols[k].type == BF_FLOAT64) {
					if (!rf_float_grammar(data, f->len)) {
						rf_fail(&s, BF_E_BAD_VALUE, BF_WHERE_ROW, line, offset, (int32_t)k, BF_KIND_MALFORMED, 0);
						goto out;
					}
					h_strtod(NULL, (const char *)data, f->len, &v->d);
					if (rf_float_exp_too_large(data, f->len) || !isfinite(v->d)) {
						rf_fail(&s, BF_E_BAD_VALUE, BF_WHERE_ROW, line, offset, (int32_t)k, BF_KIND_OUT_OF_RANGE, 0);
						goto out;
					}
				} else {
					if (f->len == 4 && memcmp(data, "true", 4) == 0) {
						v->b = 1;
					} else if (f->len == 5 && memcmp(data, "false", 5) == 0) {
						v->b = 0;
					} else {
						rf_fail(&s, BF_E_BAD_VALUE, BF_WHERE_ROW, line, offset, (int32_t)k, BF_KIND_MALFORMED, 0);
						goto out;
					}
				}
			}
		}

		/* Predicates. */
		{
			bool match = true;

			for (uint32_t i = 0; i < c->npreds && match; i++) {
				const bf_pred *p = &c->preds[i];
				const rf_value *v = &vals[p->col];

				if (p->kind == BF_P_IS_NULL) {
					match = v->is_null;
				} else if (v->is_null) {
					match = rf_is_in(p->kind) && p->b;
				} else if (rf_is_in(p->kind)) {
					match = rf_in(c, i, v);
				} else {
					switch (p->kind) {
						case BF_P_NOT_NULL: match = true; break;
						case BF_P_INT_NE: match = v->i != p->i; break;
						case BF_P_INT_GTE: match = v->i >= p->i; break;
						case BF_P_INT_LT: match = v->i < p->i; break;
						case BF_P_INT_LTE: match = v->i <= p->i; break;
						case BF_P_FLOAT_NE: match = v->d != p->d; break;
						case BF_P_FLOAT_GTE: match = v->d >= p->d; break;
						case BF_P_FLOAT_LT: match = v->d < p->d; break;
						case BF_P_FLOAT_LTE: match = v->d <= p->d; break;
						case BF_P_BOOL_NE: match = v->b != (p->b ? 1 : 0); break;
						case BF_P_STR_NE: match = !(v->n == p->n && memcmp(v->s, p->s, p->n) == 0); break;
						case BF_P_INT_EQ: match = v->i == p->i; break;
						case BF_P_INT_GT: match = v->i > p->i; break;
						case BF_P_FLOAT_EQ: match = v->d == p->d; break;
						case BF_P_FLOAT_GT: match = v->d > p->d; break;
						case BF_P_BOOL_EQ: match = v->b == (p->b ? 1 : 0); break;
						case BF_P_STR_EQ: match = v->n == p->n && memcmp(v->s, p->s, p->n) == 0; break;
						default: abort();
					}
				}
			}
			if (match && c->mode == 3) {
				bool is_null[MAX_COLS];
				int64_t iv[MAX_COLS];
				double dv[MAX_COLS];
				int bv[MAX_COLS];
				const uint8_t *sv[MAX_COLS];
				size_t nv[MAX_COLS];

				if (matched == c->max_rows) {
					rf_fail(&s, BF_E_MAX_ROWS, BF_WHERE_ROW, line, offset, -1, c->max_rows, 0);
					goto out;
				}
				matched++;
				for (uint32_t k = 0; k < c->ncols; k++) {
					is_null[k] = vals[k].is_null;
					iv[k] = vals[k].i;
					dv[k] = vals[k].d;
					bv[k] = vals[k].b;
					sv[k] = vals[k].s;
					nv[k] = vals[k].n;
				}
				row_put(&o->rows, c->cols, c->ncols, is_null, iv, dv, bv, sv, nv);
			} else if (match && c->mode == 2) {
				bf_reason why = BF_E_NONE;
				long gi = tmap_group(c, tm, o, vals, &why);
				int32_t ov;

				matched++;
				if (gi < 0) {
					rf_fail(&s, why, BF_WHERE_ROW, line, offset, -1, why == BF_E_MAX_GROUPS ? c->max_groups : 0, 0);
					goto out;
				}
				o->groups[gi].count++;
				if ((ov = rf_aggs_row(c, o->groups[gi].st, vals)) >= 0) {
					rf_fail(&s, BF_E_SUM_INT_OVERFLOW, BF_WHERE_ROW, line, offset, ov, 0, 0);
					goto out;
				}
			} else if (match && c->mode == 1) {
				int32_t ov;

				matched++;
				if ((ov = rf_aggs_row(c, o->agg, vals)) >= 0) {
					rf_fail(&s, BF_E_SUM_INT_OVERFLOW, BF_WHERE_ROW, line, offset, ov, 0, 0);
					goto out;
				}
			} else if (match && c->group_col >= 0) {
				long gi = rmap_find(gm, o, &vals[c->group_col], c->cols[c->group_col].type == BF_INT64);

				matched++;
				if (gi < 0) {
					bf_reason why = rmap_add(c, gm, o, &vals[c->group_col]);

					if (why != BF_E_NONE) {
						rf_fail(&s, why, BF_WHERE_ROW, line, offset, -1, why == BF_E_MAX_GROUPS ? c->max_groups : 0, 0);
						goto out;
					}
					gi = (long)o->ngroups - 1;
				}
				o->groups[gi].count++;
				if (c->sum_col >= 0 && !vals[c->sum_col].is_null) {
					if (sum_int) {
						if (__builtin_add_overflow(o->groups[gi].isum, vals[c->sum_col].i, &o->groups[gi].isum)) {
							rf_fail(&s, BF_E_SUM_INT_OVERFLOW, BF_WHERE_ROW, line, offset, c->sum_col, 0, 0);
							goto out;
						}
					} else {
						o->groups[gi].fsum += vals[c->sum_col].d;
					}
				}
			} else if (match) {
				matched++;
				if (c->sum_col >= 0 && !vals[c->sum_col].is_null) {
					if (sum_int) {
						if (__builtin_add_overflow(isum, vals[c->sum_col].i, &isum)) {
							rf_fail(&s, BF_E_SUM_INT_OVERFLOW, BF_WHERE_ROW, line, offset, c->sum_col, 0, 0);
							goto out;
						}
					} else {
						fsum += vals[c->sum_col].d;
					}
				}
			}
		}

		while (ncopies > 0) {
			free(copies[--ncopies]);
		}
	}

	if (c->mode != 0) {
		int32_t bad = -1;

		if (c->mode == 1) {
			bad = rf_aggs_finite(c, o->agg);
		}
		for (size_t i = 0; c->mode == 2 && bad < 0 && i < o->ngroups; i++) {
			bad = rf_aggs_finite(c, o->groups[i].st);
		}
		if (bad >= 0) {
			rf_fail(&s, BF_E_SUM_NOT_FINITE, BF_WHERE_NONE, 0, 0, bad, 0, 0);
			goto out;
		}
	} else if (c->group_col >= 0 && c->sum_col >= 0 && !sum_int) {
		for (size_t i = 0; i < o->ngroups; i++) {
			if (!isfinite(o->groups[i].fsum)) {
				rf_fail(&s, BF_E_SUM_NOT_FINITE, BF_WHERE_NONE, 0, 0, c->sum_col, 0, 0);
				goto out;
			}
		}
	} else if (c->sum_col >= 0 && !sum_int && !isfinite(fsum)) {
		rf_fail(&s, BF_E_SUM_NOT_FINITE, BF_WHERE_NONE, 0, 0, c->sum_col, 0, 0);
		goto out;
	}
	o->ok = true;
	o->scanned = s.row;
	o->matched = matched;
	o->isum = isum;
	o->fsum = fsum;
out:
	while (ncopies > 0) {
		free(copies[--ncopies]);
	}
	free(s.fields);
	free(scratch);
	free(gm->next);
	free(gm);
	free(tm->next);
	free(tm);
	if (!o->ok) {
		bytes rows = o->rows;

		memset(&o->rows, 0, sizeof(o->rows));
		outcome_free(o);
		o->rows = rows;
	}
}

/* ---- running the core ---- */

static const uint64_t KEY_A[2] = { UINT64_C(0x0123456789ABCDEF), UINT64_C(0xFEDCBA9876543210) };
static const uint64_t KEY_B[2] = { UINT64_C(0), UINT64_C(0) };

/* Set while core_run runs a query over a loaded table instead of the CSV input (SPEC 6.6). */
static const bf_table *g_table;

static void core_run(const config *c, const uint8_t *in, size_t len, size_t chunk, bf_masks_fn masks,
	long fail_read, long refuse_mem, long interrupt, const uint64_t key[2], bool shim, outcome *o, long pause_every,
	long refuse_emit)
{
	fuzz_ud ud = { .data = in, .len = len, .chunk = chunk, .fail_read_at = fail_read, .refuse_mem_at = refuse_mem, .interrupt_at = interrupt,
		.cols = c->cols, .rows = &o->rows, .refuse_emit_at = refuse_emit, .pause_every = pause_every };
	bf_host host = { .alloc = h_alloc, .realloc = h_realloc, .free = h_free, .mem_fits = h_mem_fits, .read = h_read,
		.strtod = h_strtod, .interrupted = h_interrupted, .emit_row = h_emit_row, .ud = &ud };
	bf_run *r = calloc(1, sizeof(bf_run));

	if (r == NULL) {
		abort();
	}
	r->host = &host;
	r->cols = c->cols;
	r->ncols = c->ncols;
	r->allow_extra = c->allow_extra;
	r->max_field_bytes = c->max_field;
	r->max_row_bytes = c->max_row;
	r->max_input_rows = c->max_input;
	r->masks_fn = masks;
	r->delim = c->delim;
	r->pred_src = c->preds;
	r->npreds = c->npreds;
	r->sum_col = c->sum_col;
	r->group_col = c->group_col;
	r->aggs = c->aggs;
	r->naggs = c->naggs;
	r->gkeys = c->gkeys;
	r->ngkeys = c->mode == 2 ? c->ngkeys : 0;
	r->max_groups = c->max_groups;
	r->max_group_bytes = c->max_group_bytes;
	r->hash_key[0] = key[0];
	r->hash_key[1] = key[1];
	r->rows = c->mode == 3;
	r->max_rows = c->max_rows;
	r->table = g_table;

	memset(o, 0, sizeof(*o));
	bf_fuzz_identical_hash = shim;
	if (c->mode == 3) {
		int rc;
		long pauses = 0;

		/* Each pause returns to the caller with the whole position kept, as between two cursor fetches. */
		while ((rc = g_table != NULL ? bf_run_table_rows(r) : bf_run_rows(r)) == 1) {
			pauses++;
		}
		if (pause_every > 0 && pauses != ud.emits / pause_every && !(rc < 0 && pauses == (ud.emits - 1) / pause_every)) {
			abort();
		}
		if (pause_every == 0 && pauses != 0) {
			abort();
		}
		o->ok = rc == 0;
	} else {
		o->ok = g_table != NULL ? bf_run_table_scan(r) : bf_run_scan(r);
	}
	bf_fuzz_identical_hash = false;
	o->err = r->err;
	o->scanned = r->scanned;
	o->matched = r->matched;
	o->isum = r->isum;
	o->fsum = r->fsum;
	if (o->ok && c->mode == 1) {
		memcpy(o->agg, r->agg, sizeof(o->agg));
	}
	if (o->ok && c->mode == 2) {
		/* Keys decoded from the core's tuple encoding; the reference kept its own values. */
		const bf_tuples *t = &r->tuples;

		for (uint32_t gid = 0; gid < t->n; gid++) {
			rgroup *x = outcome_add_group(o);
			const uint8_t *e = (const uint8_t *)t->arena + t->off[gid];

			x->count = t->count[gid];
			for (uint32_t k = 0; k < c->ngkeys; k++) {
				const uint8_t tag = *e++;

				x->keys[k].is_null = tag == BF_TUPLE_NULL;
				if (tag == BF_TUPLE_INT) {
					x->keys[k].i = (int64_t)bf_get_le(e, 8);
					e += 8;
				} else if (tag == BF_TUPLE_STR) {
					x->keys[k].n = (size_t)bf_get_le(e, 4);
					x->keys[k].s = malloc(x->keys[k].n + 1);
					if (x->keys[k].s == NULL) {
						abort();
					}
					memcpy(x->keys[k].s, e + 4, x->keys[k].n);
					e += 4 + x->keys[k].n;
				} else if (tag == BF_TUPLE_DICT && g_table != NULL) {
					/* A dictionary key of a table run: its id, back to the dictionary's value. */
					const bf_groups *d = &g_table->tcols[c->gkeys[k]].values;
					const uint32_t g = (uint32_t)bf_get_le(e, 4) - 1;

					if (d->int_keys) {
						x->keys[k].i = d->ikey[g];
					} else {
						x->keys[k].n = d->len[g];
						x->keys[k].s = malloc(x->keys[k].n + 1);
						if (x->keys[k].s == NULL) {
							abort();
						}
						if (x->keys[k].n > 0) {
							memcpy(x->keys[k].s, d->arena + d->off[g], x->keys[k].n);
						}
					}
					e += 4;
				} else if (tag != BF_TUPLE_NULL) {
					abort();
				}
			}
			if (e != (const uint8_t *)t->arena + t->off[gid] + t->len[gid]) {
				abort();
			}
			if (c->naggs > 0) {
				memcpy(x->st, t->state + (size_t)gid * c->naggs, c->naggs * sizeof(bf_agg_state));
			}
		}
	}
	if (o->ok && c->group_col >= 0 && r->dgroup) {
		/* A dictionary group-by of a table run: produced groups in first-seen order, keys from the dictionary. */
		const bf_groups *d = &g_table->tcols[c->group_col].values;

		for (uint32_t k = 0; k < r->ndorder; k++) {
			const uint32_t id = r->dorder[k];
			rgroup *x = outcome_add_group(o);

			x->is_null = id == 0;
			x->count = r->dcount[id];
			x->isum = r->disum != NULL ? r->disum[id] : 0;
			x->fsum = r->dfsum != NULL ? r->dfsum[id] : 0.0;
			if (!x->is_null && d->int_keys) {
				x->i = d->ikey[id - 1];
			} else if (!x->is_null) {
				x->n = d->len[id - 1];
				x->s = malloc(x->n + 1);
				if (x->s == NULL) {
					abort();
				}
				if (x->n > 0) {
					memcpy(x->s, d->arena + d->off[id - 1], x->n);
				}
			}
		}
	} else if (o->ok && c->group_col >= 0) {
		const bf_groups *g = &r->groups;

		for (uint32_t gid = 0; gid < g->n; gid++) {
			rgroup *x = outcome_add_group(o);

			x->is_null = (int64_t)gid == g->null_gid;
			x->count = g->count[gid];
			x->isum = g->has_sum && g->sum_int ? g->isum[gid] : 0;
			x->fsum = g->has_sum && !g->sum_int ? g->fsum[gid] : 0.0;
			if (!x->is_null && g->int_keys) {
				x->i = g->ikey[gid];
			} else if (!x->is_null) {
				x->n = g->len[gid];
				x->s = malloc(x->n + 1);
				if (x->s == NULL) {
					abort();
				}
				if (x->n > 0) {
					memcpy(x->s, g->arena + g->off[gid], x->n);
				}
			}
		}
	}
	bf_run_table_release(r);
	free(r);
}

typedef struct {
	fuzz_ud u;           /* first, so the h_* host functions read it */
	bf_table *t;
	bf_run *r;
} load_ud;

static bf_emit_status h_load_row(void *ud, const bf_val *vals, size_t n)
{
	load_ud *l = ud;

	(void)n;
	return bf_table_append(l->t, l->r, vals) == 0 ? BF_EMIT_CONTINUE : BF_EMIT_PAUSE;
}

/*
 * The input loaded as a table (dict[c] per column) and the query run over it must equal the streaming
 * result whenever the load succeeds. Errors compare by reason, row and column: a table row error has no line or offset.
 * With dictionaries, maxGroupBytes is counted over other arrays (ids, the whole dictionary), so a GROUP_BYTES outcome on
 * either side is not compared.
 */
static void table_check(const config *c, const uint8_t *in, size_t len, const bool *dict, bool shim, const outcome *expected,
	const char *ctx)
{
	load_ud l = { .u = { .data = in, .len = len, .chunk = SIZE_MAX, .fail_read_at = -1, .refuse_mem_at = -1,
		.interrupt_at = -1, .cols = c->cols, .refuse_emit_at = -1 } };
	bf_host host = { .alloc = h_alloc, .realloc = h_realloc, .free = h_free, .mem_fits = h_mem_fits, .read = h_read,
		.strtod = h_strtod, .interrupted = h_interrupted, .emit_row = h_load_row, .ud = &l };
	bf_run *r = calloc(1, sizeof(bf_run));
	bf_table *t = calloc(1, sizeof(bf_table));
	bytes ignored = { 0 };
	bool any_dict = false;
	outcome got, want;
	int rc = -1;

	if (r == NULL || t == NULL) {
		abort();
	}
	l.u.rows = &ignored;
	l.t = t;
	l.r = r;
	r->host = &host;
	r->cols = c->cols;
	r->ncols = c->ncols;
	r->allow_extra = c->allow_extra;
	r->max_field_bytes = c->max_field;
	r->max_row_bytes = c->max_row;
	r->max_input_rows = c->max_input;
	r->masks_fn = bf_masks_scalar;
	r->delim = c->delim;
	r->sum_col = -1;
	r->group_col = -1;
	r->rows = true;
	r->max_rows = UINT64_MAX;
	t->host = host;
	t->cols = c->cols;
	t->ncols = c->ncols;
	t->max_bytes = UINT64_MAX;
	t->max_rows = c->max_input;
	t->max_dict = UINT32_MAX - 1;
	t->hash_key[0] = KEY_A[1];
	t->hash_key[1] = KEY_A[0];
	for (uint32_t k = 0; k < c->ncols; k++) {
		any_dict |= dict[k];
	}
	bf_fuzz_identical_hash = shim;
	if (bf_table_init(t, r, dict) == 0) {
		rc = bf_run_rows(r);
	}
	bf_fuzz_identical_hash = false;
	bf_run_release(r);
	free(r);
	free(ignored.buf);
	if (rc == 0) {
		bf_fuzz_identical_hash = shim;
		g_table = t;
		core_run(c, in, len, SIZE_MAX, bf_masks_scalar, -1, -1, -1, KEY_B, shim, &got, 0, -1);
		g_table = NULL;
		bf_fuzz_identical_hash = false;
		if (!(any_dict && (got.err.reason == BF_E_GROUP_BYTES || expected->err.reason == BF_E_GROUP_BYTES))) {
			want = *expected;
			if (!want.ok && !got.ok && want.err.where == BF_WHERE_ROW && got.err.where == BF_WHERE_ROW) {
				want.err.line = got.err.line;
				want.err.offset = got.err.offset;
			}
			outcome_check_equal(&got, &want, ctx);
		}
		outcome_free(&got);
	}
	bf_table_free(t);
	free(t);
}

static void check_masks(const uint8_t *in, size_t len)
{
	size_t nblocks = (len + 63) / 64;
	char *buf = malloc(nblocks * 64 + 64);
	uint64_t *a = malloc(nblocks * 8 + 8), *b = malloc(nblocks * 8 + 8);

	if (buf == NULL || a == NULL || b == NULL) {
		abort();
	}
	memset(buf, 0, nblocks * 64 + 64);
	memcpy(buf, in, len);
	for (unsigned di = 0; di < 4; di++) {
		const unsigned char d = DELIMS[di];

		bf_masks_scalar(buf, nblocks, d, a);
		/* The scalar masks themselves: bit i is set exactly for the delimiter, LF, CR and quote bytes. */
		for (size_t i = 0; i < nblocks * 64; i++) {
			const unsigned char ch = (unsigned char)buf[i];

			if ((((a[i / 64] >> (i % 64)) & 1) != 0) != (ch == d || ch == '\n' || ch == '\r' || ch == '"')) {
				abort();
			}
		}
#if defined(__x86_64__)
		__builtin_cpu_init();
		if (__builtin_cpu_supports("avx2")) {
			bf_masks_avx2(buf, nblocks, d, b);
			if (memcmp(a, b, nblocks * 8) != 0) {
				abort();
			}
		}
#endif
#if defined(__aarch64__)
		bf_masks_neon(buf, nblocks, d, b);
		if (memcmp(a, b, nblocks * 8) != 0) {
			abort();
		}
#endif
	}
	free(buf);
	free(a);
	free(b);
}

int LLVMFuzzerInitialize(int *argc, char ***argv)
{
	(void)argc;
	(void)argv;
	c_locale = newlocale(LC_ALL_MASK, "C", (locale_t)0);
	bf_masks_init();
	return 0;
}

int LLVMFuzzerTestOneInput(const uint8_t *data, size_t size)
{
	static const size_t chunks[] = {1, 2, 3, 7, 13, 64, 65, 4096, 65536, SIZE_MAX};
	config c;
	outcome expected, got;
	uint8_t *input = NULL;
	const uint8_t *body;
	size_t body_len;

	if (size < CFG_BYTES) {
		return 0;
	}
	config_parse(data, &c);
	body = data + CFG_BYTES;
	body_len = size - CFG_BYTES;

	if (body_len > 0) {
		check_masks(body, body_len);
	}
	if (c.auto_header) {
		char header[64];
		int hn = 0;

		for (uint32_t i = 0; i < c.ncols; i++) {
			hn += snprintf(header + hn, sizeof(header) - (size_t)hn, "%s%s", i ? (const char[]){ (char)c.delim, 0 } : "", c.names[i]);
		}
		header[hn++] = '\n';
		input = malloc(body_len + (size_t)hn);
		if (input == NULL) {
			abort();
		}
		memcpy(input, header, (size_t)hn);
		if (body_len > 0) {
			memcpy(input + hn, body, body_len);
		}
		body = input;
		body_len += (size_t)hn;
	}

	ref_scan(&c, body, body_len, &expected);

	/* SPEC 6.2: how many fields must be left after the last referenced column for the popcount tail; 0 uses it at once. */
	{
		static const size_t tail_min[4] = { 0, 1, 2, 8 };

		bf_fuzz_tail_min = tail_min[c.chunk_sel & 3];
	}
	/* SPEC 6.3: how many matched rows of a group-by wait for their probes; 1 probes each at once. */
	{
		static const uint32_t batch[4] = { 16, 1, 3, 7 };

		bf_fuzz_group_batch = batch[(c.chunk_sel >> 2) & 3];
		/* Mostly 0, so that every row of a small table goes through the queue; sometimes the real threshold. */
		bf_fuzz_group_min_slots = (c.chunk_sel >> 4) & 7 ? 0 : BF_GB_SLOTS_MIN;
	}
	core_run(&c, body, body_len, SIZE_MAX, bf_masks_scalar, -1, -1, -1, KEY_A, false, &got, 0, -1);
	outcome_check_equal(&got, &expected, "reference");
	outcome_free(&got);

	/* toArray paused after every k-th row and resumed, as a cursor's fetches: the same rows, the same first error. */
	if (c.mode == 3) {
		core_run(&c, body, body_len, chunks[(c.chunk_sel >> 4) % 10], bf_masks_scalar, -1, -1, -1, KEY_A, false, &got,
			1 + (long)(c.chunk_sel & 7), -1);
		outcome_check_equal(&got, &expected, "paused");
		outcome_free(&got);
	}

	/* A second hash key: same groups, same order. */
	core_run(&c, body, body_len, chunks[c.chunk_sel % 10], bf_masks_scalar, -1, -1, -1, KEY_B, false, &got, 0, -1);
	outcome_check_equal(&got, &expected, "chunked");
	outcome_free(&got);

	/* Every key hashes alike: correct by full key comparison alone. Quadratic, so bounded. */
	if ((c.group_col >= 0 || c.mode == 2) && expected.ngroups <= 2048) {
		core_run(&c, body, body_len, SIZE_MAX, bf_masks_scalar, -1, -1, -1, KEY_A, true, &got, 0, -1);
		outcome_check_equal(&got, &expected, "identical hash");
		outcome_free(&got);
	}
#if defined(__x86_64__)
	if (__builtin_cpu_supports("avx2")) {
		core_run(&c, body, body_len, chunks[(c.chunk_sel >> 4) % 10], bf_masks_avx2, -1, -1, -1, KEY_A, false, &got, 0, -1);
		outcome_check_equal(&got, &expected, "avx2");
		outcome_free(&got);
	}
#endif
#if defined(__aarch64__)
	core_run(&c, body, body_len, chunks[(c.chunk_sel >> 4) % 10], bf_masks_neon, -1, -1, -1, KEY_A, false, &got, 0, -1);
	outcome_check_equal(&got, &expected, "neon");
	outcome_free(&got);
#endif

	/* Injected failures: each must end the run cleanly with the injected reason, if it fires. */
	{
		long at = (long)(c.chunk_sel & 7);

		core_run(&c, body, body_len, 64, bf_masks_scalar, at, -1, -1, KEY_A, false, &got, 0, -1);
		if (!got.ok && got.err.reason != BF_E_READ_FAILED && expected.ok) {
			abort();
		}
		outcome_free(&got);
		core_run(&c, body, body_len, 64, bf_masks_scalar, -1, at, -1, KEY_A, false, &got, 0, -1);
		if (!got.ok && expected.ok && got.err.reason != BF_E_MEMORY) {
			abort();
		}
		outcome_free(&got);
		/* The row budget refusing the at-th emitted row: the rows before it are the reference's first rows, and the
		   error names the refused row itself (with no predicate, matching row at + 1 is data row at + 1). */
		if (c.mode == 3 && expected.ok && expected.matched > (uint64_t)at) {
			core_run(&c, body, body_len, 64, bf_masks_scalar, -1, -1, -1, KEY_A, false, &got, 0, at);
			if (got.ok || got.err.reason != BF_E_MEMORY || got.err.where != BF_WHERE_ROW || got.rows.len > expected.rows.len
				|| (got.rows.len > 0 && memcmp(got.rows.buf, expected.rows.buf, got.rows.len) != 0)
				|| (c.npreds == 0 && got.err.row != (uint64_t)at + 1)) {
				fprintf(stderr, "refused emit %ld: ok %d reason %d row %" PRIu64 "\n", at, got.ok, got.err.reason, got.err.row);
				abort();
			}
			outcome_free(&got);
		}
		core_run(&c, body, body_len, 64, bf_masks_scalar, -1, -1, at, KEY_A, false, &got, 0, -1);
		if (!got.ok && expected.ok && got.err.reason != BF_E_TIMED_OUT) {
			abort();
		}
		outcome_free(&got);
	}

	/* The same query over the input loaded as a table, plain, all dictionary, mixed, and under the shim. */
	{
		bool dict[BF_MAX_SCHEMA_COLS] = { false }, mixed[BF_MAX_SCHEMA_COLS] = { false }, none[BF_MAX_SCHEMA_COLS] = { false };

		for (uint32_t k = 0, e = 0; k < c.ncols; k++) {
			if (c.cols[k].type == BF_STRING || c.cols[k].type == BF_INT64) {
				dict[k] = true;
				mixed[k] = (e++ + c.chunk_sel) % 2 == 0;
			}
		}
		table_check(&c, body, body_len, none, false, &expected, "table plain");
		table_check(&c, body, body_len, dict, false, &expected, "table dictionary");
		table_check(&c, body, body_len, mixed, false, &expected, "table mixed");
		table_check(&c, body, body_len, dict, true, &expected, "table dictionary, identical hash");
	}

	outcome_free(&expected);
	free(input);
	return 0;
}
