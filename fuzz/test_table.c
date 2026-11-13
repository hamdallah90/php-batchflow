/*
 * C unit test for the Table column store (SPEC 6.4, 6.6); no PHP needed.
 * T1: capacities: rows from 4096 doubling, clamped to maxInputRows; memoryBytes is the sum of all capacities;
 *     maxTableBytes refuses the growth whose total would exceed it, at the row that needs it.
 * T2: stored values: every type, NULL slots zero, plain strings in the arena, arena doubling from 65,536.
 * T3: dictionaries: first-seen ids, NULL id 0 and not counted, "" distinct from NULL, maxDictionarySize exact and +1,
 *     the group table counted in the total and refused by maxTableBytes.
 * T4: a refused memory preflight on each growth, and on each row array of the rows growth.
 * Every table is freed; ASan and LeakSanitizer check the release paths. Build and run: fuzz/build.sh does both.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "bf_core.h"

static void *h_alloc(void *ud, size_t n) { (void)ud; return malloc(n); }
static void *h_realloc(void *ud, void *p, size_t n) { (void)ud; return realloc(p, n); }
static void h_free(void *ud, void *p) { (void)ud; free(p); }
static size_t fits_left;    /* mem_fits grants this many more requests; SIZE_MAX grants all */
static bool h_fits(void *ud, size_t n)
{
	(void)ud;
	(void)n;
	if (fits_left == SIZE_MAX) {
		return true;
	}
	return fits_left-- > 0;
}

static bf_col cols[5] = { { "i", 1, BF_INT64 }, { "f", 1, BF_FLOAT64 }, { "b", 1, BF_BOOL }, { "s", 1, BF_STRING },
	{ "k", 1, BF_STRING } };
static int failed;

static void check(bool ok, const char *what)
{
	printf("%s: %s\n", ok ? "ok" : "FAIL", what);
	failed |= !ok;
}

static bf_run run;

static void setup(bf_table *t, uint32_t ncols, const bool *dict, uint64_t max_bytes, uint64_t max_rows, uint32_t max_dict)
{
	static const bf_host host = { .alloc = h_alloc, .realloc = h_realloc, .free = h_free, .mem_fits = h_fits };

	memset(&run, 0, sizeof(run));
	run.host = &host;
	run.where = BF_WHERE_ROW;
	memset(t, 0, sizeof(*t));
	t->host = host;
	t->cols = cols;
	t->ncols = ncols;
	t->max_bytes = max_bytes;
	t->max_rows = max_rows;
	t->max_dict = max_dict;
	t->hash_key[0] = 1;
	t->hash_key[1] = 2;
	fits_left = SIZE_MAX;
	if (bf_table_init(t, &run, dict) != 0) {
		printf("FAIL: bf_table_init\n");
		exit(1);
	}
}

/* Appends row number row (1-based, as the rows loop counts) with v; returns the append's result. */
static int put(bf_table *t, uint64_t row, const bf_val *v)
{
	run.scanned = row;
	run.err.reason = BF_E_NONE;
	return bf_table_append(t, &run, v);
}

static bf_val ival(int64_t i) { return (bf_val){ .i = i }; }
static bf_val sval(const char *s, size_t n) { return (bf_val){ .s = s, .n = n }; }
static const bf_val vnull = { .is_null = true };

static void t1(void)
{
	static const bool nodict[5];
	bf_table t;
	bf_val v = ival(7);
	uint64_t row = 0;
	int rc = 0;

	setup(&t, 1, nodict, UINT64_C(1) << 40, 100000000, 16);
	check(t.bytes == 0 && t.cap == 0, "T1 nothing allocated before the first row");
	put(&t, ++row, &v);
	check(t.cap == 4096 && t.bytes == 4096 * 9, "T1 the first row allocates 4096 rows of 9 bytes");
	while (row < 4097) {
		put(&t, ++row, &v);
	}
	check(t.cap == 8192 && t.bytes == 8192 * 9 && t.rows == 4097, "T1 row 4097 doubles to 8192");
	bf_table_free(&t);

	setup(&t, 1, nodict, UINT64_C(1) << 40, 3, 16);
	for (row = 0; row < 3;) {
		put(&t, ++row, &v);
	}
	check(t.cap == 3 && t.bytes == 27, "T1 capacity clamped to maxInputRows");
	bf_table_free(&t);

	/* Five columns of 9 + 9 + 2 + 13 + 13 = 46 bytes per row: 8192 rows hold 376,832, 16,384 rows 753,664. */
	static const bf_val five[5] = { { .i = 1 }, { .d = 1.0 }, { .b = 1 }, { .s = "z", .n = 1 }, { .s = "z", .n = 1 } };
	setup(&t, 5, nodict, 376832 + 65536, 100000000, 16);
	for (row = 0; rc == 0 && row < 20000;) {
		rc = put(&t, ++row, five);
	}
	check(rc == -1 && row == 8193 && run.err.reason == BF_E_TABLE_BYTES && run.err.row == 8193 && t.rows == 8192,
		"T1 maxTableBytes refuses at row 8193, the first growth over it");
	check(t.bytes == 376832 + 65536, "T1 the refused growth left the capacity unchanged");
	bf_table_free(&t);
	setup(&t, 5, nodict, 376832 + 65535, 100000000, 16);
	for (rc = 0, row = 0; rc == 0 && row < 20000;) {
		rc = put(&t, ++row, five);
	}
	check(rc == -1 && row == 4097 && run.err.reason == BF_E_TABLE_BYTES && t.bytes == 188416 + 65536,
		"T1 one byte less refuses the growth at row 4097");
	bf_table_free(&t);
}

static void t2(void)
{
	static const bool nodict[5];
	bf_table t;
	char big[40000];
	bf_val row1[5] = { { .i = -5 }, { .d = -0.0 }, { .b = 1 }, { .s = "abc", .n = 3 }, { .s = "", .n = 0 } };
	bf_val row2[5] = { vnull, vnull, vnull, vnull, vnull };
	bf_val row3[5] = { { .i = INT64_MAX }, { .d = 2.5 }, { .b = 0 }, { .s = big, .n = sizeof(big) },
		{ .s = big, .n = sizeof(big) } };
	bool ok;

	memset(big, 'q', sizeof(big));
	setup(&t, 5, nodict, UINT64_C(1) << 40, 100000000, 16);
	ok = put(&t, 1, row1) == 0 && put(&t, 2, row2) == 0 && put(&t, 3, row3) == 0;
	check(ok && t.rows == 3, "T2 three rows appended");
	const bf_tcol *c = t.tcols;
	check(c[0].valid[0] == 1 && c[0].i[0] == -5 && c[0].valid[1] == 0 && c[0].i[1] == 0 && c[0].i[2] == INT64_MAX,
		"T2 int64 values, NULL slot zero");
	check(c[1].d[0] == 0.0 && __builtin_signbit(c[1].d[0]) && c[1].valid[1] == 0 && c[1].d[1] == 0.0
		&& !__builtin_signbit(c[1].d[1]) && c[1].d[2] == 2.5, "T2 float64 values keep -0.0, NULL slot +0.0");
	check(c[2].b[0] == 1 && c[2].b[1] == 0 && c[2].valid[1] == 0 && c[2].b[2] == 0 && c[2].valid[2] == 1,
		"T2 bool values");
	check(c[3].len[0] == 3 && memcmp(t.arena + c[3].off[0], "abc", 3) == 0 && c[3].valid[1] == 0 && c[3].off[1] == 0
		&& c[3].len[1] == 0 && c[4].valid[0] == 1 && c[4].len[0] == 0, "T2 strings in the arena, \"\" present, NULL zero");
	check(c[3].len[2] == sizeof(big) && c[4].len[2] == sizeof(big)
		&& memcmp(t.arena + c[4].off[2], big, sizeof(big)) == 0 && t.arena_len == 3 + 2 * sizeof(big),
		"T2 two 40,000-byte strings");
	check(t.arena_cap == 131072 && t.bytes == 4096 * 46 + 131072, "T2 the arena doubled once, total by capacity");
	bf_table_free(&t);
}

static void t3(void)
{
	static const bool dict[5] = { true, false, false, false, true };
	bf_table t;
	bf_val r[5] = { ival(10), { .d = 0 }, { .b = 0 }, sval("", 0), sval("a", 1) };
	bool ok = true;
	int rc;

	setup(&t, 5, dict, UINT64_C(1) << 40, 100000000, 3);
	ok &= put(&t, 1, r) == 0;
	r[0] = vnull;
	r[4] = vnull;
	ok &= put(&t, 2, r) == 0;
	r[0] = ival(20);
	r[4] = sval("", 0);
	ok &= put(&t, 3, r) == 0;
	r[0] = ival(10);
	r[4] = sval("a", 1);
	ok &= put(&t, 4, r) == 0;
	r[0] = ival(30);
	r[4] = sval("b", 1);
	ok &= put(&t, 5, r) == 0;
	check(ok && t.rows == 5, "T3 five rows, three distinct per column, limit 3");
	const bf_tcol *ci = &t.tcols[0], *ck = &t.tcols[4];
	check(ci->ids[0] == 1 && ci->ids[1] == 0 && ci->ids[2] == 2 && ci->ids[3] == 1 && ci->ids[4] == 3
		&& ci->i[2] == 20 && ci->i[1] == 0 && ci->valid[1] == 0, "T3 int64 ids first-seen, NULL 0, values kept");
	check(ck->ids[0] == 1 && ck->ids[1] == 0 && ck->ids[2] == 2 && ck->ids[3] == 1 && ck->ids[4] == 3
		&& ck->values.len[1] == 0 && ck->values.n == 3, "T3 string ids, \"\" is a value distinct from NULL");
	check(t.bytes == 4096 * (13 + 9 + 2 + 13 + 5) + bf_groups_bytes(&ci->values) + bf_groups_bytes(&ck->values)
		&& bf_groups_bytes(&ck->values) == 1024 * 8 + 3 * 28 + 65536 && bf_groups_bytes(&ci->values) == 1024 * 8 + 3 * 24,
		"T3 the dictionaries count in the total, arrays clamped to maxDictionarySize");
	r[0] = ival(10);
	r[4] = sval("c", 1);
	rc = put(&t, 6, r);
	check(rc == -1 && run.err.reason == BF_E_DICT_SIZE && run.err.column == 4 && run.err.row == 6,
		"T3 a fourth distinct value refused at its row, with its column");
	bf_table_free(&t);

	/* Row 1 needs the rows, then each dictionary's slots and arrays, then the string dictionary's arena. */
	setup(&t, 5, dict, 4096 * 42 + (1024 * 8 + 3 * 24) + (1024 * 8 + 3 * 28 + 65536) - 1, 100000000, 3);
	r[0] = ival(1);
	r[4] = sval("a", 1);
	rc = put(&t, 1, r);
	check(rc == -1 && run.err.reason == BF_E_TABLE_BYTES && run.err.row == 1, "T3 maxTableBytes refuses a dictionary growth");
	bf_table_free(&t);
}

static void t4(void)
{
	static const bool dict[5] = { false, false, false, false, true };
	bf_table t;
	bf_val r[5] = { ival(1), { .d = 0 }, { .b = 0 }, sval("x", 1), sval("y", 1) };

	/* Row 1: the eleven row arrays one by one (valid and the value array per column; off and len for the plain
	   string, ids for the dictionary column), then the arena, the dictionary's slots, its four arrays one by one
	   (hash, off, len, count) and its arena. */
	for (size_t grant = 0; grant < 20; grant++) {
		int rc;

		setup(&t, 5, dict, UINT64_C(1) << 40, 100000000, 16);
		fits_left = grant;
		rc = put(&t, 1, r);
		printf("  grant %zu: rc %d reason %d\n", grant, rc, (int)run.err.reason);
		check(grant < 18 ? rc == -1 && run.err.reason == BF_E_MEMORY && run.err.row == 1 : rc == 0,
			"T4 eleven row arrays, arena, slots, four arrays, dictionary arena: each refusal is memory_limit at the row");
		bf_table_free(&t);
	}
}

int main(void)
{
	t1();
	t2();
	t3();
	t4();
	return failed;
}
