#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "php_globals.h"
#include "zend_exceptions.h"
#include "zend_strtod.h"
#include "ext/random/php_random.h"

#include "php_batchflow.h"

struct bf_php_run {
	bf_run core;
	bf_host host;
	zend_resource *res;
	bool path_opened;
	bool flag_set;
	bool begun;          /* a cursor run: the stream was opened or taken at its first fetch */
	bool res_held;       /* a cursor run holds its own reference on the caller's stream resource */
	/* toArray and Cursor::fetch (SPEC 6.4, 6.7) */
	HashTable *tpl;      /* the template row: every schema column, NULL values */
	HashTable *rows;     /* the rows built so far, owned until returned */
	size_t row_base;     /* allocation sizes of a row's zend_array and hash data */
	size_t pending;      /* estimated bytes since the last budget check */
	uint64_t batch_left; /* rows until a cursor fetch pauses; 0 never pauses */
	bf_table *table;     /* a Table load: the table each row is appended to */
};

/* ---- host ---- */

static void *bf_host_alloc(void *ud, size_t n)
{
	(void)ud;
	return emalloc(n);
}

static void *bf_host_realloc(void *ud, void *p, size_t n)
{
	(void)ud;
	return erealloc(p, n);
}

static void bf_host_free(void *ud, void *p)
{
	(void)ud;
	efree(p);
}

_Static_assert(BF_MM_CHUNK == ZEND_MM_CHUNK_SIZE && BF_MM_PAGE == ZEND_MM_PAGE_SIZE
	&& BF_MM_MAX_SMALL == ZEND_MM_MAX_SMALL_SIZE, "ZendMM chunk, page and small sizes");

/* Refusal path only: release ZendMM's cached free pages and chunks, as zend_mm_alloc_pages does before it reports
 * the limit, and apply the rule once more. A large block (a page run) the rule still refuses, for want of room for
 * another chunk, is then tried for real, since ZendMM's free-page map is private: allocated and freed at once under a
 * heap limit one chunk higher, it fits if the real bytes stayed within memory_limit (ZendMM placed it in a free run,
 * or a cached chunk). Restoring the limit releases a chunk the try left cached. */
static __attribute__((noinline, cold)) bool bf_host_mem_retry(size_t request)
{
	const size_t limit = (size_t)PG(memory_limit);
	size_t real;
	bool fits = false;

	zend_mm_gc(zend_mm_get_heap());
	real = zend_memory_usage(1);
	if (bf_mem_rule(real, zend_memory_usage(0), limit, request)) {
		return true;
	}
	/* Only a large block is tried: it needs at most one fresh chunk, so the raised limit is never reached. */
	if (request <= BF_MM_MAX_SMALL || request > BF_MM_CHUNK - BF_MM_PAGE || real > limit) {
		return false;
	}
	zend_set_memory_limit(limit + BF_MM_CHUNK);
	zend_try {
		void *p = emalloc(request);

		fits = zend_memory_usage(1) <= limit;
		efree(p);
	} zend_catch {
		zend_set_memory_limit(limit);
		zend_bailout();
	} zend_end_try();
	/* A chunk the try left in ZendMM's cache is unmapped first, so real memory is back to what it was. */
	if (zend_memory_usage(1) > real) {
		zend_set_memory_limit(real);
	}
	zend_set_memory_limit(limit);
	return fits;
}

/* SPEC 6.4: bf_mem_rule (bf_core.h, unit-tested in fuzz/test_mem_rule.c) on ZendMM's counters. */
bool bf_host_mem_fits(void *ud, size_t request)
{
	(void)ud;
	return PG(memory_limit) == -1
		|| bf_mem_rule(zend_memory_usage(1), zend_memory_usage(0), (size_t)PG(memory_limit), request)
		|| bf_host_mem_retry(request);
}

static bool bf_host_strtod(void *ud, const char *s, size_t n, double *d)
{
	const char *end = NULL;

	(void)ud;
	/* Stops at the byte after the field: a delimiter, closing quote, or zeroed padding. */
	*d = zend_strtod(s, &end);
	return end == s + n;
}

static bool bf_host_interrupted(void *ud)
{
	(void)ud;
	return zend_atomic_bool_load_ex(&EG(timed_out));
}

static php_stream *bf_stream_fetch(zend_resource *res)
{
	return (php_stream *)zend_fetch_resource2(res, NULL, php_file_le_stream(), php_file_le_pstream());
}

/* After any PHP stream call, in this order: the exception wins, then a closed stream raises. */
static php_stream *bf_after_stream_call(bf_php_run *run)
{
	php_stream *s;

	if (EG(exception)) {
		return NULL;
	}
	s = bf_stream_fetch(run->res);
	if (s == NULL) {
		zend_throw_exception(bf_ce_StreamException, "a stream was closed during processing", 0);
	}
	return s;
}

static bf_read_status bf_host_read(void *ud, char *buf, size_t want, size_t *got)
{
	bf_php_run *run = ud;
	php_stream *s = bf_after_stream_call(run);
	ssize_t n;

	if (s == NULL) {
		return BF_READ_RAISED;
	}
	n = php_stream_read(s, buf, want);
	s = bf_after_stream_call(run);
	if (s == NULL) {
		return BF_READ_RAISED;
	}
	if (n < 0) {
		return BF_READ_FAILED;
	}
	if (n == 0) {
		bool eof = php_stream_eof(s);

		if (bf_after_stream_call(run) == NULL) {
			return BF_READ_RAISED;
		}
		return eof ? BF_READ_EOF : BF_READ_NO_DATA;
	}
	*got = (size_t)n;
	return BF_READ_DATA;
}

/* ---- stream lifecycle ---- */

/* Opens or re-fetches the source and takes the in-use flag. false with an exception set. */
static bool bf_stream_begin(bf_php_run *run, const bf_query *q)
{
	php_stream *s;

	/* A table query reads no stream (SPEC 6.6, 6.8): the run binds the table, set by bf_run_new. */
	if (q->store != NULL) {
		return true;
	}

	if (q->path != NULL) {
		/* Options 0, not REPORT_ERRORS: the failure is a clean StreamException with no warning. */
		s = php_stream_open_wrapper(ZSTR_VAL(q->path), "rb", 0, NULL);
		if (s == NULL) {
			if (!EG(exception)) {
				zend_throw_exception(bf_ce_StreamException, "cannot open input", 0);
			}
			return false;
		}
		/* Never php_stream_set_option: that would run a user wrapper's stream_set_option. */
		s->flags |= PHP_STREAM_FLAG_NO_BUFFER;
		run->path_opened = true;
		run->res = s->res;
		/* Keeps the resource struct alive for the re-fetch if PHP code closes the stream. */
		GC_ADDREF(run->res);
	} else {
		s = bf_stream_fetch(q->stream);
		if (s == NULL) {
			zend_throw_exception(bf_ce_StreamException, "a stream was closed before the run started", 0);
			return false;
		}
		run->res = q->stream;
	}
	if (s->flags & PHP_STREAM_FLAG_NO_FCLOSE) {
		zend_throw_exception(bf_ce_StreamException, "a stream is already in use by another BatchFlow run or cannot be closed from PHP", 0);
		return false;
	}
	s->flags |= PHP_STREAM_FLAG_NO_FCLOSE;
	run->flag_set = true;
	return true;
}

static void bf_stream_clear_flag(bf_php_run *run)
{
	php_stream *s;

	if (run->res != NULL && run->flag_set) {
		s = bf_stream_fetch(run->res);
		if (s != NULL) {
			s->flags &= ~PHP_STREAM_FLAG_NO_FCLOSE;
		}
		run->flag_set = false;
	}
}

/* Whether target is in the previous chain of ex, ex itself excluded. */
static bool bf_previous_chain_has(zend_object *ex, const zend_object *target)
{
	zval rv, *prev;

	for (;;) {
		prev = zend_read_property_ex(zend_get_exception_base(ex), ex, ZSTR_KNOWN(ZEND_STR_PREVIOUS), 1, &rv);
		ZVAL_DEREF(prev);
		if (Z_TYPE_P(prev) != IS_OBJECT) {
			return false;
		}
		if (Z_OBJ_P(prev) == target) {
			return true;
		}
		ex = Z_OBJ_P(prev);
	}
}

/* Closes a path-opened stream; close can run a wrapper's stream_close, so callers recheck EG(exception). */
static void bf_stream_end(bf_php_run *run)
{
	bf_stream_clear_flag(run);
	if (run->path_opened && run->res != NULL) {
		php_stream *s = bf_stream_fetch(run->res);

		if (s != NULL) {
			/*
			 * A pending exception would skip a wrapper's stream_close, so it is held in a local, as PHP does
			 * around a destructor (not zend_exception_save: a catch inside stream_close would consume it).
			 * If close throws, its exception gets the held one as previous. An exit stays pending: user code
			 * must not run.
			 */
			zend_object *held = EG(exception);

			if (held != NULL && (zend_is_unwind_exit(held) || zend_is_graceful_exit(held))) {
				held = NULL;
			}
			if (held != NULL) {
				EG(exception) = NULL;
			}
			php_stream_close(s);
			if (held != NULL) {
				if (EG(exception) != NULL && (zend_is_unwind_exit(EG(exception)) || zend_is_graceful_exit(EG(exception)))) {
					/* Close exited: the exit wins. PHP before 8.3.36 would chain held onto the exit object. */
					OBJ_RELEASE(held);
				} else if (EG(exception) != NULL && bf_previous_chain_has(EG(exception), held)) {
					/* Already in the close exception's chain: set_previous returns without releasing it (8.3.36, 8.5.12). */
					OBJ_RELEASE(held);
				} else if (EG(exception) != NULL) {
					zend_exception_set_previous(EG(exception), held);
				} else {
					EG(exception) = held;
				}
			}
		}
		zend_list_delete(run->res);
		run->res = NULL;
	}
}

/* ---- terminal ---- */

/* Packed [key, count] or [key, count, sum] (SPEC 5.9). */
static zend_array *bf_group_row(const bf_groups *g, uint32_t gid)
{
	zend_array *row = zend_new_array(g->has_sum ? 3 : 2);

	zend_hash_real_init_packed(row);
	ZEND_HASH_FILL_PACKED(row) {
		if ((int64_t)gid == g->null_gid) {
			ZEND_HASH_FILL_SET_NULL();
		} else if (g->int_keys) {
			ZEND_HASH_FILL_SET_LONG((zend_long)g->ikey[gid]);
		} else if (g->len[gid] == 0) {
			ZEND_HASH_FILL_SET_INTERNED_STR(ZSTR_EMPTY_ALLOC());
		} else {
			ZEND_HASH_FILL_SET_STR(zend_string_init_fast(g->arena + g->off[gid], g->len[gid]));
		}
		ZEND_HASH_FILL_NEXT();
		ZEND_HASH_FILL_SET_LONG((zend_long)g->count[gid]);
		ZEND_HASH_FILL_NEXT();
		if (g->has_sum && g->sum_int) {
			ZEND_HASH_FILL_SET_LONG((zend_long)g->isum[gid]);
			ZEND_HASH_FILL_NEXT();
		} else if (g->has_sum) {
			ZEND_HASH_FILL_SET_DOUBLE(g->fsum[gid]);
			ZEND_HASH_FILL_NEXT();
		}
	} ZEND_HASH_FILL_END();
	return row;
}

/*
 * What ZendMM takes for an emalloc of n bytes (SPEC 6.4): the smallest bin of the engine's own ZEND_MM_BINS_INFO that
 * holds the block, whole pages up to ZEND_MM_MAX_LARGE_SIZE, and whole chunks above (huge blocks are rounded to the OS
 * page size, which is not known here). ZEND_MM_OVERHEAD is the debug build's block header, 0 on release builds.
 */
static size_t bf_zmm_size(size_t n)
{
	n = ZEND_MM_ALIGNED_SIZE(n) + ZEND_MM_OVERHEAD;
#define BF_BIN(num, size, elements, pages, x, y) if (n <= (size)) { return (size); }
	ZEND_MM_BINS_INFO(BF_BIN, x, y)
#undef BF_BIN
	return ZEND_MM_ALIGNED_SIZE_EX(n, n <= ZEND_MM_MAX_LARGE_SIZE ? ZEND_MM_PAGE_SIZE : ZEND_MM_CHUNK_SIZE);
}

/* A key string from zend_string_init_fast: nothing for 0 or 1 byte, which come interned. */
static size_t bf_zstr_alloc_size(size_t len)
{
	return len <= 1 ? 0 : bf_zmm_size(_ZSTR_STRUCT_SIZE(len));
}

/* One result row: its zend_array and its packed data for cap elements. 216 on release builds for cap 8. */
static size_t bf_row_alloc_size(uint32_t cap)
{
	return bf_zmm_size(sizeof(zend_array)) + bf_zmm_size(HT_PACKED_SIZE_EX(cap, HT_MIN_MASK));
}

#if ZEND_DEBUG
/*
 * Debug builds check each result preflight after the build (SPEC 6.4): the bytes the build allocated stay within the
 * estimate plus the outer list's own zend_array, its 8-element packed minimum and its rounding to a page.
 */
static void bf_check_estimate(size_t used0, size_t estimate)
{
	ZEND_ASSERT(zend_memory_usage(0) - used0 <= estimate + bf_row_alloc_size(HT_MIN_SIZE) + ZEND_MM_PAGE_SIZE);
}
#endif

/*
 * The group list in first-seen order, after the result preflight of SPEC 6.4: groups * (row + 56) + key allocation
 * sizes, row the allocation sizes of a row's zend_array and packed data (272 per group on release builds).
 */
static bool bf_group_result(const bf_groups *g, zval *out)
{
	size_t estimate;
	bool over = __builtin_mul_overflow((size_t)g->n, bf_row_alloc_size(HT_MIN_SIZE) + 56, &estimate);

	for (uint32_t gid = 0; !g->int_keys && !over && gid < g->n; gid++) {
		over = __builtin_add_overflow(estimate, bf_zstr_alloc_size(g->len[gid]), &estimate);
	}
	if (over || !bf_host_mem_fits(NULL, estimate)) {
		zend_throw_exception(bf_ce_LimitException, "memory_limit would be exceeded", 0);
		return false;
	}
#if ZEND_DEBUG
	const size_t used0 = zend_memory_usage(0);
#endif
	array_init_size(out, g->n);
	if (g->n == 0) {
		return true;
	}
	zend_hash_real_init_packed(Z_ARRVAL_P(out));
	ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(out)) {
		for (uint32_t gid = 0; gid < g->n; gid++) {
			zval row;

			ZVAL_ARR(&row, bf_group_row(g, gid));
			ZEND_HASH_FILL_ADD(&row);
		}
	} ZEND_HASH_FILL_END();
#if ZEND_DEBUG
	bf_check_estimate(used0, estimate);
#endif
	return true;
}

/*
 * A dictionary group-by's result (SPEC 6.6): the produced groups in first-seen order, as a group table view over the
 * dictionary's keys, so bf_group_result builds the rows and makes the same preflight. The view's arrays are checked
 * with mem_fits first.
 */
static bool bf_dgroup_result(const bf_run *r, zval *out)
{
	const bf_groups *d = &r->table->tcols[r->group_col].values;
	const uint32_t n = r->ndorder;
	bf_groups v;
	bool ok;

	memset(&v, 0, sizeof(v));
	v.n = n;
	v.null_gid = -1;
	v.int_keys = d->int_keys;
	v.has_sum = r->sum_col >= 0;
	v.sum_int = r->disum != NULL;
	v.arena = d->arena;
	/* Each array is checked as it is allocated, with its own size: ZendMM places every block on its own. */
	{
		void **arr[] = { (void **)&v.count, (void **)&v.ikey, (void **)&v.off, (void **)&v.len, (void **)&v.isum, (void **)&v.fsum };
		const size_t size[] = { sizeof(uint64_t), sizeof(int64_t), sizeof(uint64_t), sizeof(uint32_t), sizeof(int64_t), sizeof(double) };

		for (size_t a = 0; a < sizeof(arr) / sizeof(arr[0]); a++) {
			if (!bf_host_mem_fits(NULL, (size_t)(n ? n : 1) * size[a])) {
				for (size_t b = 0; b < a; b++) {
					efree(*arr[b]);
				}
				zend_throw_exception(bf_ce_LimitException, "memory_limit would be exceeded", 0);
				return false;
			}
			*arr[a] = safe_emalloc(n ? n : 1, size[a], 0);
		}
	}
	for (uint32_t k = 0; k < n; k++) {
		const uint32_t id = r->dorder[k];

		v.count[k] = r->dcount[id];
		v.isum[k] = r->disum != NULL ? r->disum[id] : 0;
		v.fsum[k] = r->dfsum != NULL ? r->dfsum[id] : 0.0;
		if (id == 0) {
			v.null_gid = k;
			v.ikey[k] = 0;
			v.off[k] = 0;
			v.len[k] = 0;
		} else {
			v.ikey[k] = d->int_keys ? d->ikey[id - 1] : 0;
			v.off[k] = d->int_keys ? 0 : d->off[id - 1];
			v.len[k] = d->int_keys ? 0 : d->len[id - 1];
		}
	}
	ok = bf_group_result(&v, out);
	efree(v.count);
	efree(v.ikey);
	efree(v.off);
	efree(v.len);
	efree(v.isum);
	efree(v.fsum);
	return ok;
}

/* One aggregate pair's result (SPEC 5.8): count an int; sum 0 or 0.0 when empty; min, max and avg null when empty. */
static void bf_agg_zval(const bf_agg *a, bool is_int, const bf_agg_state *st, zval *out)
{
	switch (a->op) {
		case BF_AGG_COUNT:
			ZVAL_LONG(out, (zend_long)st->n);
			return;
		case BF_AGG_SUM:
			if (is_int) {
				ZVAL_LONG(out, (zend_long)st->v.i);
			} else {
				ZVAL_DOUBLE(out, st->v.d);
			}
			return;
		case BF_AGG_MIN:
		case BF_AGG_MAX:
			if (st->n == 0) {
				ZVAL_NULL(out);
			} else if (is_int) {
				ZVAL_LONG(out, (zend_long)st->v.i);
			} else {
				ZVAL_DOUBLE(out, st->v.d);
			}
			return;
		case BF_AGG_AVG:
			if (st->n == 0) {
				ZVAL_NULL(out);
			} else {
				/* An int64 sum above 2^53 is rounded once to binary64 before the division. */
				ZVAL_DOUBLE(out, (is_int ? (double)st->v.i : st->v.d) / (double)st->n);
			}
			return;
	}
}

/* The packed list of aggregate() results, one per pair in order. */
static void bf_aggregate_result(const bf_run *r, zval *out)
{
	array_init_size(out, r->naggs);
	zend_hash_real_init_packed(Z_ARRVAL_P(out));
	ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(out)) {
		for (uint32_t k = 0; k < r->naggs; k++) {
			zval v;

			bf_agg_zval(&r->aggs[k], r->agg_int[k], &r->agg[k], &v);
			ZEND_HASH_FILL_ADD(&v);
		}
	} ZEND_HASH_FILL_END();
}

/* Packed [key1, ..., keyN, count, agg1, ..., aggM], the keys decoded from the group's tuple encoding. */
static zend_array *bf_tuple_row(const bf_run *r, uint32_t gid)
{
	const bf_tuples *t = &r->tuples;
	const unsigned char *e = (const unsigned char *)t->arena + t->off[gid];
	const bf_agg_state *states = r->naggs > 0 ? t->state + (size_t)gid * r->naggs : NULL;
	zend_array *row = zend_new_array(r->ngkeys + 1 + r->naggs);

	zend_hash_real_init_packed(row);
	ZEND_HASH_FILL_PACKED(row) {
		for (uint32_t k = 0; k < r->ngkeys; k++) {
			const uint8_t tag = *e++;

			if (tag == BF_TUPLE_INT) {
				ZEND_HASH_FILL_SET_LONG((zend_long)(int64_t)bf_get_le(e, 8));
				e += 8;
			} else if (tag == BF_TUPLE_STR) {
				const size_t len = (size_t)bf_get_le(e, 4);

				if (len == 0) {
					ZEND_HASH_FILL_SET_INTERNED_STR(ZSTR_EMPTY_ALLOC());
				} else {
					ZEND_HASH_FILL_SET_STR(zend_string_init_fast((const char *)e + 4, len));
				}
				e += 4 + len;
			} else if (tag == BF_TUPLE_DICT) {
				/* A dictionary key of a table run: its id, converted back to the value (SPEC 6.6). */
				const bf_groups *d = &r->table->tcols[r->gkeys[k]].values;
				const uint32_t g = (uint32_t)bf_get_le(e, 4) - 1;

				if (d->int_keys) {
					ZEND_HASH_FILL_SET_LONG((zend_long)d->ikey[g]);
				} else if (d->len[g] == 0) {
					ZEND_HASH_FILL_SET_INTERNED_STR(ZSTR_EMPTY_ALLOC());
				} else {
					ZEND_HASH_FILL_SET_STR(zend_string_init_fast(d->arena + d->off[g], d->len[g]));
				}
				e += 4;
			} else {
				ZEND_HASH_FILL_SET_NULL();
			}
			ZEND_HASH_FILL_NEXT();
		}
		ZEND_HASH_FILL_SET_LONG((zend_long)t->count[gid]);
		ZEND_HASH_FILL_NEXT();
		for (uint32_t k = 0; k < r->naggs; k++) {
			zval v;

			bf_agg_zval(&r->aggs[k], r->agg_int[k], &states[k], &v);
			ZEND_HASH_FILL_ADD(&v);
		}
	} ZEND_HASH_FILL_END();
	return row;
}

/*
 * The groupAgg list in first-seen order, after the result preflight of SPEC 6.3:
 * groups * (row + 88 + 32 * N) + string key allocation sizes, row the allocation sizes of a row's zend_array and
 * packed data for P, the packed capacity of an N + 1 + M list.
 */
static bool bf_tuple_result(const bf_run *r, zval *out)
{
	const bf_tuples *t = &r->tuples;
	const uint32_t elems = r->ngkeys + 1 + r->naggs;
	const uint32_t packed = elems <= 8 ? 8 : (elems <= 16 ? 16 : 32);
	size_t estimate;
	bool over = __builtin_mul_overflow((size_t)t->n, bf_row_alloc_size(packed) + 88 + 32 * (size_t)r->ngkeys, &estimate);

	for (uint32_t gid = 0; !over && gid < t->n; gid++) {
		const unsigned char *e = (const unsigned char *)t->arena + t->off[gid];

		for (uint32_t k = 0; k < r->ngkeys && !over; k++) {
			const uint8_t tag = *e++;

			if (tag == BF_TUPLE_INT) {
				e += 8;
			} else if (tag == BF_TUPLE_STR) {
				const size_t len = (size_t)bf_get_le(e, 4);

				over = __builtin_add_overflow(estimate, bf_zstr_alloc_size(len), &estimate);
				e += 4 + len;
			} else if (tag == BF_TUPLE_DICT) {
				const bf_groups *d = &r->table->tcols[r->gkeys[k]].values;

				if (!d->int_keys) {
					over = __builtin_add_overflow(estimate, bf_zstr_alloc_size(d->len[bf_get_le(e, 4) - 1]), &estimate);
				}
				e += 4;
			}
		}
	}
	if (over || !bf_host_mem_fits(NULL, estimate)) {
		zend_throw_exception(bf_ce_LimitException, "memory_limit would be exceeded", 0);
		return false;
	}
#if ZEND_DEBUG
	const size_t used0 = zend_memory_usage(0);
#endif
	array_init_size(out, t->n);
	if (t->n == 0) {
		return true;
	}
	zend_hash_real_init_packed(Z_ARRVAL_P(out));
	ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(out)) {
		for (uint32_t gid = 0; gid < t->n; gid++) {
			zval row;

			ZVAL_ARR(&row, bf_tuple_row(r, gid));
			ZEND_HASH_FILL_ADD(&row);
		}
	} ZEND_HASH_FILL_END();
#if ZEND_DEBUG
	bf_check_estimate(used0, estimate);
#endif
	return true;
}

/* A run over q's source and plan for terminal t, not started. */
static bf_php_run *bf_run_new(const bf_query *q, const bf_terminal *t)
{
	bf_php_run *run = ecalloc(1, sizeof(bf_php_run));

	run->host = (bf_host){
		.alloc = bf_host_alloc,
		.realloc = bf_host_realloc,
		.free = bf_host_free,
		.mem_fits = bf_host_mem_fits,
		.read = bf_host_read,
		.strtod = bf_host_strtod,
		.interrupted = bf_host_interrupted,
		.ud = run,
	};
	run->core.host = &run->host;
	run->core.cols = q->plan->cols;
	run->core.ncols = q->plan->ncols;
	run->core.allow_extra = q->plan->allow_extra;
	run->core.max_field_bytes = q->plan->max_field_bytes;
	run->core.max_row_bytes = q->plan->max_row_bytes;
	run->core.max_input_rows = q->plan->max_input_rows;
	run->core.masks_fn = q->plan->masks;
	run->core.delim = q->plan->delim;
	run->core.pred_src = q->preds;
	run->core.npreds = q->npreds;
	run->core.sum_col = t->sum_col;
	run->core.group_col = t->group_col;
	run->core.aggs = t->aggs;
	run->core.naggs = t->naggs;
	run->core.gkeys = t->gkeys;
	run->core.ngkeys = t->ngkeys;
	run->core.max_groups = t->max_groups;
	run->core.max_group_bytes = q->plan->max_group_bytes;
	run->core.table = q->store;
	return run;
}

/* A run's error; a row error of a table run carries the table prefix "row R: " (SPEC 5.11). */
static void bf_raise_run_error(const bf_error *e, const bf_query *q)
{
	bf_error x = *e;

	if (q->store != NULL && x.where == BF_WHERE_ROW) {
		x.where = BF_WHERE_TABLE_ROW;
	}
	bf_raise_core_error(&x, q->plan->cols);
}

/* One terminal run: scan results into scan_out, or the group list into group_out. false with an exception set. */
static bool bf_execute(const bf_query *q, const bf_terminal *t, bf_scan_result *scan_out, zval *group_out)
{
	bf_php_run *run = bf_run_new(q, t);
	bool ok = false;

	/* One key per group run, drawn before any I/O; a CSPRNG failure propagates its RandomException. */
	if ((t->group_col >= 0 || t->ngkeys > 0) && php_random_bytes_throw(run->core.hash_key, sizeof(run->core.hash_key)) == FAILURE) {
		efree(run);
		return false;
	}

	zend_try {
		if (bf_stream_begin(run, q)) {
			bool scanned = run->core.table != NULL ? bf_run_table_scan(&run->core) : bf_run_scan(&run->core);

			/* Close first: a pending exception would stop a wrapper's stream_close from running. */
			bf_stream_end(run);
			if (!EG(exception)) {
				if (!scanned) {
					bf_raise_run_error(&run->core.err, q);
				} else if (t->ngkeys > 0) {
					ok = bf_tuple_result(&run->core, group_out);
				} else if (t->naggs > 0) {
					bf_aggregate_result(&run->core, group_out);
					ok = true;
				} else if (t->group_col >= 0) {
					ok = run->core.dgroup ? bf_dgroup_result(&run->core, group_out)
						: bf_group_result(&run->core.groups, group_out);
				} else {
					scan_out->scanned = run->core.scanned;
					scan_out->matched = run->core.matched;
					scan_out->isum = run->core.isum;
					scan_out->fsum = run->core.fsum;
					ok = true;
				}
			}
		} else {
			bf_stream_end(run);
		}
	} zend_catch {
		/* Never php_stream_close here: that can run a wrapper callback during a fatal. */
		bf_stream_clear_flag(run);
		bf_run_table_release(&run->core);
		efree(run);
		zend_bailout();
	} zend_end_try();

	bf_run_table_release(&run->core);
	efree(run);
	return ok;
}

/* ---- rows (SPEC 5.10, 6.4, 6.7) ---- */

#define BF_ROW_BUDGET ((size_t)1048576)
#define BF_ROW_HEADROOM ((size_t)2 * 1048576)

/* The template row and an empty list. Keys go in through symtable semantics, so a column named 2024 is the int key 2024. */
static void bf_rows_begin(bf_php_run *run, const bf_plan *plan)
{
	zval null;

	ZVAL_NULL(&null);
	run->tpl = zend_new_array(plan->ncols);
	zend_hash_real_init_mixed(run->tpl);
	for (uint32_t k = 0; k < plan->ncols; k++) {
		const bf_col *c = &plan->cols[k];

		/*
		 * Names from one PHP array give distinct keys. Should two still meet (an int key 5 and an unnormalized "5"), the
		 * second is a plain string key, so bucket k stays column k; such a schema fails the header with missing column.
		 */
		if (zend_symtable_str_find(run->tpl, c->name, c->name_len) == NULL) {
			zend_symtable_str_update(run->tpl, c->name, c->name_len, &null);
		} else {
			zend_hash_str_add_new(run->tpl, c->name, c->name_len, &null);
		}
	}
	run->row_base = bf_zmm_size(sizeof(zend_array)) + bf_zmm_size(HT_SIZE(run->tpl));
	run->rows = zend_new_array(0);
	run->pending = 0;
}

static void bf_rows_free(bf_php_run *run)
{
	if (run->rows != NULL) {
		zend_array_destroy(run->rows);
		run->rows = NULL;
	}
	if (run->tpl != NULL) {
		zend_array_destroy(run->tpl);
		run->tpl = NULL;
	}
}

/*
 * The host's emit_row: the row budget of SPEC 6.4, then one row, a zend_array_dup of the template with the values
 * written by position into its buckets (SPEC 6.7). vals are in schema order, n columns.
 */
static bf_emit_status bf_host_emit_row(void *ud, const bf_val *vals, size_t n)
{
	bf_php_run *run = ud;
	const bf_col *cols = run->core.cols;
	size_t estimate = run->row_base;
	HashTable *row;
	Bucket *b;
	zval zrow;

	for (size_t k = 0; k < n; k++) {
		if (cols[k].type == BF_STRING && !vals[k].is_null) {
			estimate += bf_zstr_alloc_size(vals[k].n);
		}
	}
	/* The row whose insert makes the list grow also charges the list's new packed data, twice its capacity. */
	if (run->rows->nNumUsed == run->rows->nTableSize) {
		estimate += bf_zmm_size(HT_PACKED_SIZE_EX(run->rows->nTableSize * 2, HT_MIN_MASK));
	}
	run->pending += estimate;
	if (run->pending >= BF_ROW_BUDGET) {
		if (!bf_host_mem_fits(NULL, run->pending + BF_ROW_HEADROOM)) {
			return BF_EMIT_NO_MEMORY;
		}
		run->pending = 0;
	}
	row = zend_array_dup(run->tpl);
	b = row->arData;
	for (size_t k = 0; k < n; k++) {
		const bf_val *v = &vals[k];

		if (v->is_null) {
			continue;
		}
		switch (cols[k].type) {
			case BF_INT64:
				ZVAL_LONG(&b[k].val, (zend_long)v->i);
				break;
			case BF_FLOAT64:
				ZVAL_DOUBLE(&b[k].val, v->d);
				break;
			case BF_BOOL:
				ZVAL_BOOL(&b[k].val, v->b);
				break;
			case BF_STRING:
				ZVAL_STR(&b[k].val, zend_string_init_fast(v->s, v->n));
				break;
		}
	}
	ZVAL_ARR(&zrow, row);
	zend_hash_next_index_insert_new(run->rows, &zrow);
	if (run->batch_left != 0 && --run->batch_left == 0) {
		return BF_EMIT_PAUSE;
	}
	return BF_EMIT_CONTINUE;
}

bool bf_execute_rows(const bf_query *q, uint64_t max_rows, zval *out)
{
	const bf_terminal t = { .sum_col = -1, .group_col = -1 };
	bf_php_run *run = bf_run_new(q, &t);
	bf_error err;
	int rc = -1;
	bool ok;

	run->core.rows = true;
	run->core.max_rows = max_rows;
	run->host.emit_row = bf_host_emit_row;
	bf_rows_begin(run, q->plan);

	zend_try {
		if (bf_stream_begin(run, q)) {
			rc = run->core.table != NULL ? bf_run_table_rows(&run->core) : bf_run_rows(&run->core);
		}
		/* Close first: a pending exception would stop a wrapper's stream_close from running. */
		bf_stream_end(run);
	} zend_catch {
		/* Never php_stream_close here: that can run a wrapper callback during a fatal. */
		bf_stream_clear_flag(run);
		bf_rows_free(run);
		bf_run_table_release(&run->core);
		efree(run);
		zend_bailout();
	} zend_end_try();

	/* toArray never pauses. A failed run frees its rows before the exception is built: memory may be short. */
	ok = rc == 0 && !EG(exception);
	if (ok) {
		ZVAL_ARR(out, run->rows);
		run->rows = NULL;
	}
	err = run->core.err;
	bf_rows_free(run);
	bf_run_table_release(&run->core);
	efree(run);
	if (!ok && !EG(exception)) {
		bf_raise_run_error(&err, q);
	}
	return ok;
}

/* ---- Table load (SPEC 5.1, 6.4, 6.6) ---- */

/* A refused append pauses the rows loop with err set; bf_execute_load reads it as a failure. */
static bf_emit_status bf_host_load_row(void *ud, const bf_val *vals, size_t n)
{
	bf_php_run *run = ud;

	(void)n;
	return bf_table_append(run->table, &run->core, vals) == 0 ? BF_EMIT_CONTINUE : BF_EMIT_PAUSE;
}

bool bf_execute_load(const bf_query *q, bf_table *t)
{
	static const bool no_dict[BF_MAX_SCHEMA_COLS];
	const bf_terminal term = { .sum_col = -1, .group_col = -1 };
	bf_php_run *run;
	bf_error err;
	volatile int rc = -1;  /* set inside zend_try, read after it */
	bool ok;

	/* SPEC 6.3: the table's own key, drawn before any I/O; a CSPRNG failure propagates unchanged. */
	if (php_random_bytes_throw(t->hash_key, sizeof(t->hash_key)) == FAILURE) {
		return false;
	}
	run = bf_run_new(q, &term);
	run->core.rows = true;
	run->core.max_rows = UINT64_MAX;
	run->host.emit_row = bf_host_load_row;
	run->table = t;
	/* The table outlives the run: its host allocates and preflights only, none of which reads ud. */
	t->host = (bf_host){ .alloc = bf_host_alloc, .realloc = bf_host_realloc, .free = bf_host_free,
		.mem_fits = bf_host_mem_fits };
	t->cols = q->plan->cols;
	t->ncols = q->plan->ncols;
	t->max_bytes = q->plan->max_table_bytes;
	t->max_rows = q->plan->max_input_rows;
	t->max_dict = (uint32_t)q->plan->max_dictionary_size;

	zend_try {
		if (bf_table_init(t, &run->core, q->plan->dict != NULL ? q->plan->dict : no_dict) == 0
			&& bf_stream_begin(run, q)) {
			rc = run->core.table != NULL ? bf_run_table_rows(&run->core) : bf_run_rows(&run->core);
		}
		bf_stream_end(run);
	} zend_catch {
		/* Never php_stream_close here: that can run a wrapper callback during a fatal. */
		bf_stream_clear_flag(run);
		bf_table_free(t);
		bf_run_table_release(&run->core);
		efree(run);
		zend_bailout();
	} zend_end_try();

	/* rc 1 is an append refused with err set. A failed load frees the table before the exception is built. */
	ok = rc == 0 && !EG(exception);
	err = run->core.err;
	bf_run_table_release(&run->core);
	efree(run);
	if (!ok) {
		bf_table_free(t);
		if (!EG(exception)) {
			bf_raise_core_error(&err, q->plan->cols);
		}
	}
	return ok;
}

/* ---- cursor runs (SPEC 5.13, 6.8) ---- */

static void bf_cursor_free_run(bf_php_run *run)
{
	bf_rows_free(run);
	bf_run_table_release(&run->core);
	if (run->res_held && run->res != NULL) {
		zend_list_delete(run->res);
	}
	efree(run);
}

/* A new cursor's run: no I/O yet, the template row and an empty list ready. */
static bf_php_run *bf_cursor_run_new(bf_cursor *c, const bf_query *q)
{
	const bf_terminal t = { .sum_col = -1, .group_col = -1 };
	bf_php_run *run = bf_run_new(q, &t);

	run->core.rows = true;
	run->core.max_rows = UINT64_MAX;
	run->host.emit_row = bf_host_emit_row;
	bf_rows_begin(run, q->plan);
	c->run = run;
	c->state = BF_CURSOR_OPEN;
	return run;
}

bool bf_cursor_fetch(bf_cursor *c, uint64_t max_rows, zval *out)
{
	const bf_query *q = bf_query_from_obj(c->query);
	bf_php_run *const volatile run = c->run != NULL ? c->run : bf_cursor_run_new(c, q);
	bf_error err;
	/* Set inside the zend_try and read only on its normal exit. */
	volatile int rc = -1;
	volatile bool ends = true;
	bool ok;

	if (run->rows == NULL) {
		/* Each fetch builds its own list, and its row budget starts at 0 (SPEC 6.4). */
		run->rows = zend_new_array(0);
		run->pending = 0;
	}
	run->batch_left = max_rows;
	c->fetching = true;

	zend_try {
		if (!run->begun) {
			bool begun = bf_stream_begin(run, q);

			run->begun = true;
			/* The cursor may outlive its Query at request shutdown, so the run keeps the caller's stream itself. */
			if (!run->path_opened && run->res != NULL) {
				GC_ADDREF(run->res);
				run->res_held = true;
			}
			if (begun) {
				rc = run->core.table != NULL ? bf_run_table_rows(&run->core) : bf_run_rows(&run->core);
			}
		} else if (run->res != NULL && bf_stream_fetch(run->res) == NULL) {
			/* fclose() is refused while the flag is set, but pclose() closes any stream between fetches (SPEC 6.8). */
			zend_throw_exception(bf_ce_StreamException, "a stream was closed during processing", 0);
		} else {
			rc = run->core.table != NULL ? bf_run_table_rows(&run->core) : bf_run_rows(&run->core);
		}
		ends = rc != 1 || EG(exception);
		/* A fetch releases with fetching still set, so a stream_close callback cannot re-enter the release. */
		if (ends) {
			bf_stream_end(run);
		}
	} zend_catch {
		/* Never php_stream_close here: that can run a wrapper callback during a fatal. */
		bf_stream_clear_flag(run);
		c->run = NULL;
		c->state = BF_CURSOR_CLOSED;
		c->fetching = false;
		bf_cursor_free_run(run);
		zend_bailout();
	} zend_end_try();

	ok = rc >= 0 && !EG(exception);
	if (ok) {
		ZVAL_ARR(out, run->rows);
		run->rows = NULL;
	}
	if (!ends) {
		c->fetching = false;
		return true;
	}
	err = run->core.err;
	c->run = NULL;
	c->state = ok ? BF_CURSOR_EXHAUSTED : BF_CURSOR_CLOSED;
	bf_cursor_free_run(run);
	c->fetching = false;
	if (!ok && !EG(exception)) {
		bf_raise_run_error(&err, q);
	}
	return ok;
}

void bf_cursor_release(bf_cursor *c)
{
	bf_php_run *run = c->run;

	/* Closed before releasing: a stream_close callback finds a closed cursor and cannot re-enter. */
	c->state = BF_CURSOR_CLOSED;
	c->run = NULL;
	if (run == NULL) {
		return;
	}
	zend_try {
		bf_stream_end(run);
	} zend_catch {
		bf_stream_clear_flag(run);
		bf_cursor_free_run(run);
		zend_bailout();
	} zend_end_try();
	bf_cursor_free_run(run);
}

bool bf_execute_scan(const bf_query *q, int32_t sum_col, bf_scan_result *out)
{
	const bf_terminal t = { .sum_col = sum_col, .group_col = -1 };

	return bf_execute(q, &t, out, NULL);
}

bool bf_execute_group(const bf_query *q, int32_t key_col, int32_t sum_col, uint32_t max_groups, zval *out)
{
	const bf_terminal t = { .sum_col = sum_col, .group_col = key_col, .max_groups = max_groups };

	return bf_execute(q, &t, NULL, out);
}

bool bf_execute_aggregate(const bf_query *q, const bf_agg *aggs, uint32_t naggs, zval *out)
{
	const bf_terminal t = { .sum_col = -1, .group_col = -1, .aggs = aggs, .naggs = naggs };

	return bf_execute(q, &t, NULL, out);
}

bool bf_execute_group_agg(const bf_query *q, const uint32_t *keys, uint32_t nkeys, const bf_agg *aggs, uint32_t naggs,
	uint32_t max_groups, zval *out)
{
	const bf_terminal t = { .sum_col = -1, .group_col = -1, .aggs = aggs, .naggs = naggs, .gkeys = keys, .ngkeys = nkeys,
		.max_groups = max_groups };

	return bf_execute(q, &t, NULL, out);
}
