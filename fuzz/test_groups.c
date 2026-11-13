/*
 * C unit test for the single-key group table (SPEC 6.3) with injected hash keys; no PHP needed.
 * T1: the slot layout depends on the run's key, for int and string keys.
 * T2: 1,000 strings whose hashes under the all-zero key share their low 11 bits fill one probe run under that key
 *     and spread out under another: the hash-flooding defence holds only with the run's key in use.
 * T3: with every hash forced to 0 (BF_FUZZ_HASH_SHIM), a NULL group seen first and kept out of the slots through
 *     rehashes, so int key 0 and the empty string still get their own groups.
 * T4: mem_fits refusing its k-th request, for each k, while 300 string-keyed groups with a sum are inserted (the
 *     arrays grow at 256, one realloc per array): the refusal is memory_limit, every group so far keeps its key in
 *     every array, and ASan and LeakSanitizer check the arrays already grown are freed once.
 * Build and run: fuzz/build.sh does both.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "bf_core.h"

static void *h_alloc(void *ud, size_t n) { (void)ud; return malloc(n); }
static void *h_realloc(void *ud, void *p, size_t n) { (void)ud; return realloc(p, n); }
static void h_free(void *ud, void *p) { (void)ud; free(p); }
static size_t fits_left = SIZE_MAX; /* mem_fits grants this many more requests; SIZE_MAX grants all */
static bool h_fits(void *ud, size_t n) { (void)ud; (void)n; return fits_left == SIZE_MAX || fits_left-- > 0; }
static bf_host host = { .alloc = h_alloc, .realloc = h_realloc, .free = h_free, .mem_fits = h_fits };
static bf_col cols[2] = { { "k", 1, BF_INT64 }, { "s", 1, BF_STRING } };
static int failed;

static void check(bool ok, const char *what)
{
	printf("%s: %s\n", ok ? "ok" : "FAIL", what);
	failed |= !ok;
}

static void setup(bf_run *r, int col, uint64_t k0, uint64_t k1)
{
	memset(r, 0, sizeof(*r));
	r->host = &host;
	r->cols = cols;
	r->ncols = 2;
	r->group_col = col;
	r->sum_col = -1;
	r->max_groups = 100000000;
	r->max_group_bytes = UINT64_C(1) << 40;
	r->hash_key[0] = k0;
	r->hash_key[1] = k1;
	bf_groups_prepare(r);
}

static uint32_t put(bf_run *r, bool null, int64_t i, const char *s, size_t n)
{
	bf_val v = { .i = i, .s = s, .n = n, .is_null = null };
	uint32_t gid = 0;

	if (bf_group_of(r, &v, &gid) != 0) {
		printf("FAIL: bf_group_of refused\n");
		exit(1);
	}
	return gid;
}

/* Sum over occupied slots of the distance from the home slot: the probes all lookups of the keys take, minus one each. */
static uint64_t displacement(const bf_groups *g)
{
	uint64_t d = 0;

	for (size_t i = 0; i <= g->mask; i++) {
		if (g->slots[i] != 0) {
			d += (i - (size_t)(g->hash[(uint32_t)g->slots[i] - 1] & g->mask)) & g->mask;
		}
	}
	return d;
}

int main(void)
{
	static char coll[1000][16];
	static const uint64_t zero[2] = { 0, 0 };
	bf_run a, b;
	char buf[32];

	for (int col = 0; col < 2; col++) {
		setup(&a, col, 1, 2);
		setup(&b, col, 3, 4);
		for (int i = 0; i < 2000; i++) {
			int n = snprintf(buf, sizeof buf, "key%d", i);

			put(&a, false, i, buf, (size_t)n);
			put(&b, false, i, buf, (size_t)n);
		}
		check(a.groups.mask != b.groups.mask || memcmp(a.groups.slots, b.groups.slots, (a.groups.mask + 1) * 8) != 0,
			col ? "T1 string keys: the slot layout differs between two hash keys" : "T1 int keys: the slot layout differs between two hash keys");
		bf_groups_release(&a);
		bf_groups_release(&b);
	}

	/* 1,000 groups end in 2,048 slots, so a shared low 11 bits means one home slot. */
	for (uint32_t c = 0, found = 0; found < 1000; c++) {
		int n = snprintf(coll[found], sizeof coll[found], "c%u", c);

		if ((bf_siphash13(zero, coll[found], (size_t)n) & 0x7FF) == 0) {
			found++;
		}
	}
	setup(&a, 1, 0, 0);
	setup(&b, 1, 0x0123456789ABCDEF, 0xFEDCBA9876543210);
	for (int i = 0; i < 1000; i++) {
		put(&a, false, 0, coll[i], strlen(coll[i]));
		put(&b, false, 0, coll[i], strlen(coll[i]));
	}
	printf("T2 displacement: %llu under the all-zero key, %llu under another\n",
		(unsigned long long)displacement(&a.groups), (unsigned long long)displacement(&b.groups));
	check(a.groups.mask == 2047 && displacement(&a.groups) == 499500, "T2 the colliding set fills one probe run under its key");
	check(b.groups.mask == 2047 && displacement(&b.groups) < 5000, "T2 the colliding set spreads out under another key");
	bf_groups_release(&a);
	bf_groups_release(&b);

	bf_fuzz_identical_hash = true;
	for (int col = 0; col < 2; col++) {
		setup(&a, col, 1, 2);
		uint32_t null_gid = put(&a, true, 0, NULL, 0);

		for (int i = 1; i <= 900; i++) {
			int n = snprintf(buf, sizeof buf, "k%d", i);

			put(&a, false, i, buf, (size_t)n);
		}
		uint32_t z = put(&a, false, 0, "", 0);

		check(z != null_gid && a.groups.n == 902,
			col ? "T3 string keys: \"\" is not the NULL group after rehashes" : "T3 int keys: 0 is not the NULL group after rehashes");
		bf_groups_release(&a);
	}
	bf_fuzz_identical_hash = false;

	for (size_t grant = 0;; grant++) {
		bf_val v = { 0 };
		uint32_t gid = 0;
		int i, rc = 0;
		bool keys_ok = true;

		setup(&a, 1, 1, 2);
		a.sum_col = 0;
		bf_groups_prepare(&a);
		fits_left = grant;
		for (i = 0; i < 300 && rc == 0; i++) {
			v.n = (size_t)snprintf(buf, sizeof buf, "key%d", i);
			v.s = buf;
			rc = bf_group_of(&a, &v, &gid);
		}
		fits_left = SIZE_MAX;
		keys_ok = a.groups.n == (uint32_t)(rc == 0 ? 300 : i - 1) && a.groups.n <= a.groups.cap;
		for (uint32_t j = 0; j < a.groups.n; j++) {
			int n = snprintf(buf, sizeof buf, "key%u", j);

			/* hash, count and isum are read too, so ASan sees every array hold the group */
			keys_ok &= a.groups.len[j] == (uint32_t)n && memcmp(a.groups.arena + a.groups.off[j], buf, (size_t)n) == 0
				&& (a.groups.hash[j] | a.groups.count[j] | (uint64_t)a.groups.isum[j]) != UINT64_MAX - 1;
		}
		bf_groups_release(&a);
		if (rc == 0) {
			printf("T4 %zu refusals, then all 300 groups fit\n", grant);
			check(grant == 12, "T4 twelve preflights, each refused once: slots, arena and the five arrays, each time they grow");
			break;
		}
		check(a.err.reason == BF_E_MEMORY && keys_ok,
			"T4 a refusal is memory_limit, and every group so far keeps its key in every array");
	}
	return failed;
}
