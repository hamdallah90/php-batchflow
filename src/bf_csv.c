#include <string.h>

#include "bf_core.h"

#define BF_LIKELY(x)   __builtin_expect(!!(x), 1)
#define BF_UNLIKELY(x) __builtin_expect(!!(x), 0)
#define BF_HEADER_FIELDS 64

static inline size_t bf_min(size_t a, size_t b) { return a < b ? a : b; }
static inline size_t bf_max(size_t a, size_t b) { return a > b ? a : b; }

/* ---- failures ---- */

/* The record being framed: it starts at r->pos, unconsumed. */
int bf_fail_now(bf_run *r, bf_reason reason)
{
	bf_error *e = &r->err;

	e->reason = reason;
	e->where = r->where;
	e->column = -1;
	e->n1 = e->n2 = 0;
	e->row = r->where == BF_WHERE_ROW ? r->scanned + 1 : 0;
	e->line = r->line;
	e->offset = r->base + r->pos;
	return -1;
}

/* The record just framed and consumed. */
int bf_fail_row(bf_run *r, bf_reason reason, int32_t column, uint64_t n1, uint64_t n2)
{
	bf_error *e = &r->err;

	e->reason = reason;
	e->where = r->where;
	e->column = column;
	e->n1 = n1;
	e->n2 = n2;
	e->row = r->where == BF_WHERE_ROW ? r->scanned : 0;
	e->line = r->row_line;
	e->offset = r->row_offset;
	return -1;
}

/* A framing error at x decides only if no byte before it was already past the row limit. */
static int bf_fail_at(bf_run *r, const char *x, size_t limit_off, bf_reason reason)
{
	return bf_fail_now(r, (size_t)(x - r->buf) > limit_off ? BF_E_ROW_TOO_LONG : reason);
}

/* ---- memory ---- */

int bf_run_alloc(bf_run *r, void **out, size_t size)
{
	if (!r->host->mem_fits(r->host->ud, size)) {
		return bf_fail_now(r, BF_E_MEMORY);
	}
	*out = r->host->alloc(r->host->ud, size);
	return 0;
}

/* Buffer and masks grow together, so the sum is preflighted first: erealloc can hold old and new at once. */
static int bf_reader_grow(bf_run *r, size_t need)
{
	size_t ncap = bf_min(bf_max(r->cap * 2, need), r->bound);
	size_t nmask = (ncap + BF_BLOCK - 1) / BF_BLOCK * sizeof(uint64_t);

	if (!r->host->mem_fits(r->host->ud, ncap + nmask)) {
		return bf_fail_now(r, BF_E_MEMORY);
	}
	r->buf = r->host->realloc(r->host->ud, r->buf, ncap);
	r->masks = r->host->realloc(r->host->ud, r->masks, nmask);
	r->cap = ncap;
	r->mask_cap = nmask / sizeof(uint64_t);
	return 0;
}

/* ---- reader ---- */

/*
 * Compacts, reads, and rescans. Masks are recomputed only once the buffered bytes have at least
 * doubled, or EOF arrived, or the buffer reached its bound, so a long row in short reads stays
 * linear. goal 0 means the doubling rule; the first fill passes 3 for the BOM test.
 */
static int bf_refill(bf_run *r, size_t goal)
{
	size_t prev;

	if (r->pos > 0) {
		size_t avail = r->fill - r->pos;

		if (avail > 0) {
			memmove(r->buf, r->buf + r->pos, avail);
		}
		r->fill = avail;
		r->base += r->pos;
		r->pos = 0;
	}
	prev = r->fill;
	if (goal == 0) {
		goal = prev * 2;
	}
	do {
		size_t got = 0;
		size_t want = bf_min(bf_max(r->fill, BF_CHUNK), r->bound - BF_BLOCK - r->fill);

		if (r->fill + want + BF_BLOCK > r->cap && bf_reader_grow(r, r->fill + want + BF_BLOCK) != 0) {
			return -1;
		}
		switch (r->host->read(r->host->ud, r->buf + r->fill, want, &got)) {
			case BF_READ_DATA:
				r->fill += got;
				break;
			case BF_READ_EOF:
				r->eof = true;
				break;
			case BF_READ_FAILED:
				return bf_fail_now(r, BF_E_READ_FAILED);
			case BF_READ_NO_DATA:
				return bf_fail_now(r, BF_E_NO_DATA);
			case BF_READ_RAISED:
				return -1;
		}
		if (r->host->interrupted(r->host->ud)) {
			return bf_fail_now(r, BF_E_TIMED_OUT);
		}
	} while (!r->eof && r->fill < goal && r->fill < r->bound - BF_BLOCK);

	memset(r->buf + r->fill, 0, BF_BLOCK);
	r->nmasks = (r->fill + BF_BLOCK - 1) / BF_BLOCK;
	if (r->nmasks > 0) {
		r->masks_fn(r->buf, r->nmasks, r->delim, r->masks);
	}
	r->blk = 0;
	r->cur = r->nmasks > 0 ? r->masks[0] : 0;
	return 0;
}

/* Next structural byte (delimiter, LF, CR, "), or NULL when the buffered data has none left. */
static inline char *bf_next(uint64_t *cur, size_t *blk, const uint64_t *masks, size_t nmasks, char *buf)
{
	while (*cur == 0) {
		if (++*blk >= nmasks) {
			return NULL;
		}
		*cur = masks[*blk];
	}
	{
		unsigned tz = (unsigned)__builtin_ctzll(*cur);

		*cur &= *cur - 1;
		return buf + *blk * BF_BLOCK + tz;
	}
}

/*
 * RFC 4180 over the structural positions of one contiguous buffer. A row running off the buffer is
 * compacted, refilled and rescanned from its start, so fields never point at moved memory. The
 * leftmost failing byte decides: any error at x > row start + maxRowBytes is reported as the row
 * limit, and a tie at exactly that byte reports the framing error.
 *
 * SPEC 6.2: this one body is instantiated with delim a constant for the comma and with the plan's byte
 * for any delimiter, so the comma loop compares against an immediate.
 */
static inline __attribute__((always_inline)) int bf_read_row(bf_run *r, const bool header, const char delim, const bool tail)
{
restart:;
	char *const buf = r->buf;
	char *const end = buf + r->fill;
	char *p = buf + r->pos;
	char *const row_start = p;
	const size_t limit_off = r->pos + r->max_row_bytes;
	const uint64_t *const masks = r->masks;
	const size_t nmasks = r->nmasks;
	const bool eof = r->eof;
	const size_t nf_max = header ? r->fields_cap : r->nfile;
	const bf_tail_fn tailfn = tail ? r->tail_fn : NULL;
	const size_t tail_from = tail && !header ? r->tail_from : SIZE_MAX;
	bf_field *const f = r->fields;
	uint64_t cur = r->cur;
	size_t blk = r->blk, n = 0;
	uint64_t quoted_lf = 0;

	if (BF_UNLIKELY(p == end)) {
		if (eof) {
			return 0;
		}
		goto need_more;
	}
	for (;;) {
		char *s = bf_next(&cur, &blk, masks, nmasks, buf);
		char *fs;
		size_t flen;
		bool quoted = false, doubled = false;

		if (BF_LIKELY(s != NULL) && BF_LIKELY(*s != '"')) {
			fs = p;
			flen = (size_t)(s - p);
		} else if (s == NULL) {
			/* No structural left: everything up to the end of input is this last field. */
			if (!eof) {
				goto need_more;
			}
			s = end;
			fs = p;
			flen = (size_t)(end - p);
		} else {
			char *q;

			if (s != p) {
				return bf_fail_at(r, s, limit_off, BF_E_BARE_QUOTE);
			}
			quoted = true;
			fs = s + 1;
			for (;;) {
				q = bf_next(&cur, &blk, masks, nmasks, buf);
				if (q == NULL) {
					if (!eof) {
						goto need_more;
					}
					return bf_fail_at(r, end, limit_off, BF_E_UNTERMINATED_QUOTE);
				}
				if (*q != '"') {
					quoted_lf += *q == '\n';
					continue;
				}
				if (q + 1 == end) {
					if (!eof) {
						goto need_more;
					}
					break;
				}
				if (q[1] != '"') {
					break;
				}
				doubled = true;
				(void)bf_next(&cur, &blk, masks, nmasks, buf);
			}
			flen = (size_t)(q - fs);
			if (q + 1 == end) {
				s = end;
			} else {
				s = q + 1;
				if (*s != delim && *s != '\n' && *s != '\r') {
					return bf_fail_at(r, s, limit_off, BF_E_TEXT_AFTER_QUOTE);
				}
				(void)bf_next(&cur, &blk, masks, nmasks, buf);
			}
		}

		f[n].p = fs;
		f[n].len = flen;
		f[n].quoted = quoted;
		f[n].doubled = doubled;
		n++;

		if (BF_LIKELY(s != end) && BF_LIKELY(*s == delim)) {
			if (BF_UNLIKELY(n == nf_max)) {
				/* The delimiter opens one field more than the table or row holds. */
				if (header && nf_max < BF_MAX_FILE_COLS) {
					size_t ncap = bf_min(nf_max * 2, BF_MAX_FILE_COLS);

					if (!r->host->mem_fits(r->host->ud, ncap * sizeof(bf_field))) {
						return bf_fail_now(r, BF_E_MEMORY);
					}
					r->fields = r->host->realloc(r->host->ud, r->fields, ncap * sizeof(bf_field));
					r->fields_cap = ncap;
					goto restart;
				}
				if (header) {
					return bf_fail_at(r, s, limit_off, BF_E_TOO_MANY_COLUMNS);
				}
				if ((size_t)(s - buf) > limit_off) {
					return bf_fail_now(r, BF_E_ROW_TOO_LONG);
				}
				bf_fail_now(r, BF_E_FIELD_COUNT_HIGH);
				r->err.n1 = r->nfile;
				return -1;
			}
			if (tail && BF_UNLIKELY(n == tail_from)) {
				/* Past the last referenced column: the delimiters up to the row's LF are counted by popcount. Anything else
				   (a quote, a CR, a field count other than the file's, the end of the data) takes the per-bit walk below, which
				   reports each error where it always has. */
				size_t b2 = blk, nd = 0;
				uint64_t c2 = cur;
				const char *x = tailfn(buf, masks, nmasks, &b2, &c2, &nd);

				if (x != NULL && *x == '\n' && n + nd + 1 == r->nfile) {
					blk = b2;
					cur = c2;
					s = (char *)x;
					n = r->nfile;
					goto end_of_record;
				}
			}
			p = s + 1;
			continue;
		}

end_of_record:
		/* End of record at a terminator or end of input. */
		{
			char *next;
			bool terminated = true;

			if (s == end) {
				next = end;
				terminated = false;
			} else if (*s == '\n') {
				next = s + 1;
			} else {
				if (s + 1 == end) {
					if (!eof) {
						goto need_more;
					}
					return bf_fail_at(r, s, limit_off, BF_E_BARE_CR);
				}
				if (s[1] != '\n') {
					return bf_fail_at(r, s, limit_off, BF_E_BARE_CR);
				}
				(void)bf_next(&cur, &blk, masks, nmasks, buf);
				next = s + 2;
			}
			if (BF_UNLIKELY((size_t)(s - row_start) > r->max_row_bytes)) {
				return bf_fail_now(r, BF_E_ROW_TOO_LONG);
			}
			if (!header) {
				if (BF_UNLIKELY(n != r->nfile)) {
					bf_fail_now(r, BF_E_FIELD_COUNT_LOW);
					r->err.n1 = r->nfile;
					r->err.n2 = n;
					return -1;
				}
				if (BF_UNLIKELY(r->scanned >= r->max_input_rows)) {
					return bf_fail_now(r, BF_E_INPUT_ROWS);
				}
				r->scanned++;
			} else {
				r->nfile = (uint32_t)n;
			}
			r->row_offset = r->base + r->pos;
			r->row_line = r->line;
			r->pos = (size_t)(next - buf);
			r->cur = cur;
			r->blk = blk;
			r->line += quoted_lf + terminated;
			return 1;
		}
	}

need_more:
	/* Past maxRowBytes plus CRLF with no terminator found: the row cannot fit. */
	if (BF_UNLIKELY((size_t)(end - row_start) > r->max_row_bytes + 2)) {
		return bf_fail_now(r, BF_E_ROW_TOO_LONG);
	}
	if (bf_refill(r, 0) != 0) {
		return -1;
	}
	goto restart;
}

int bf_csv_next_row(bf_run *r)
{
	return bf_read_row(r, false, (char)r->delim, false);
}

int bf_csv_next_row_comma(bf_run *r)
{
	return bf_read_row(r, false, ',', false);
}

/* The same framing with the popcount tail (SPEC 6.2), instantiated apart so that a plan with a short tail runs the code
   without it. */
int bf_csv_next_row_tail(bf_run *r)
{
	return bf_read_row(r, false, (char)r->delim, true);
}

int bf_csv_next_row_comma_tail(bf_run *r)
{
	return bf_read_row(r, false, ',', true);
}

/* ---- header ---- */

/*
 * Ceiling: names are matched by a linear scan per header field, at worst 4096 fields times 1024
 * schema names, once per run. Hashing them would buy nothing on real schemas of a few columns.
 */
static int bf_find_col(const bf_run *r, const char *name, size_t len)
{
	for (uint32_t c = 0; c < r->ncols; c++) {
		if (r->cols[c].name_len == len && memcmp(r->cols[c].name, name, len) == 0) {
			return (int)c;
		}
	}
	return -1;
}

static int bf_header_apply(bf_run *r)
{
	for (uint32_t i = 0; i < r->nfile; i++) {
		bf_field *f = &r->fields[i];
		size_t len = f->doubled ? bf_unquote_inplace(f->p, f->len) : f->len;
		int col = bf_find_col(r, f->p, len);

		if (col >= 0) {
			if (r->col_file[col] >= 0) {
				return bf_fail_row(r, BF_E_DUPLICATE_COLUMN, col, 0, 0);
			}
			r->col_file[col] = (int32_t)i;
		} else if (!r->allow_extra) {
			return bf_fail_row(r, BF_E_UNEXPECTED_COLUMN, -1, (uint64_t)i + 1, 0);
		}
	}
	for (uint32_t c = 0; c < r->ncols; c++) {
		if (r->col_file[c] < 0) {
			return bf_fail_row(r, BF_E_MISSING_COLUMN, (int32_t)c, 0, 0);
		}
	}
	return 0;
}

int bf_csv_open(bf_run *r)
{
	int rc;

	r->bound = r->max_row_bytes + 2 + BF_CHUNK + BF_BLOCK;
	r->cap = bf_min(BF_INITIAL_BUFFER, r->bound);
	r->mask_cap = (r->cap + BF_BLOCK - 1) / BF_BLOCK;
	r->line = 1;
	if (!r->host->mem_fits(r->host->ud, r->cap + r->mask_cap * sizeof(uint64_t))) {
		return bf_fail_now(r, BF_E_MEMORY);
	}
	r->buf = r->host->alloc(r->host->ud, r->cap);
	r->masks = r->host->alloc(r->host->ud, r->mask_cap * sizeof(uint64_t));

	if (bf_run_alloc(r, (void **)&r->col_file, r->ncols * sizeof(int32_t)) != 0
		|| bf_run_alloc(r, (void **)&r->fields, BF_HEADER_FIELDS * sizeof(bf_field)) != 0) {
		return -1;
	}
	memset(r->col_file, 0xFF, r->ncols * sizeof(int32_t));
	r->fields_cap = BF_HEADER_FIELDS;

	if (bf_refill(r, 3) != 0) {
		return -1;
	}
	if (r->fill >= 3 && memcmp(r->buf, "\xEF\xBB\xBF", 3) == 0) {
		r->pos = 3;
	}

	r->where = BF_WHERE_HEADER;
	rc = bf_read_row(r, true, (char)r->delim, false);
	if (rc <= 0) {
		if (rc == 0) {
			r->where = BF_WHERE_NONE;
			bf_fail_now(r, BF_E_MISSING_HEADER);
		}
		return -1;
	}
	if (bf_header_apply(r) != 0) {
		return -1;
	}
	r->where = BF_WHERE_ROW;

	/* The field table now holds exactly the file's columns. */
	r->fields = r->host->realloc(r->host->ud, r->fields, r->nfile * sizeof(bf_field));
	r->fields_cap = r->nfile;
	return 0;
}
