/*
 * C unit test for the memory_limit rule of SPEC 6.4 (bf_mem_rule in bf_core.h); no PHP needed. The rule reads only
 * ZendMM's counters, so each case sets real, used and the limit directly: states a PHPT cannot reach on purpose
 * (real bytes above the limit after a crossing, a huge block making real bytes a non-multiple of the chunk size).
 * Build and run: fuzz/build.sh does both.
 */
#include <stdint.h>
#include <stdio.h>

#include "bf_core.h"

#define M BF_MM_CHUNK
#define P BF_MM_PAGE

static int failed;

static void check(bool got, bool want, const char *what)
{
	printf("%s: %s\n", got == want ? "ok" : "FAIL", what);
	failed |= got != want;
}

int main(void)
{
	/* After a crossing (real above the limit) nothing fits, even with used bytes far below it. */
	check(bf_mem_rule(3 * M, M, 3 * M - 1, 64), false, "real above limit refuses a small request");
	check(bf_mem_rule(3 * M, M, 4 * M, 64), true, "same state with headroom allows it");

	/* With headroom for another chunk, a small request is compared unrounded with used bytes. */
	check(bf_mem_rule(3 * M, 3 * M - 12000, 4 * M, 3000), true, "headroom: contiguous rule off");
	check(bf_mem_rule(3 * M, 3 * M - 12000, 4 * M - 1, 3000), false, "no headroom: above half the free space per chunk");

	/* No headroom: a small request up to half the average free space per chunk, (real - used) / 2 / chunks. */
	check(bf_mem_rule(3 * M, 3 * M - 17994, 4 * M - 1, 2999), true, "per chunk: at the bound");
	check(bf_mem_rule(3 * M, 3 * M - 17994, 4 * M - 1, 3000), false, "per chunk: one above the bound");

	/* A huge block of M + P beside two chunks: the chunk count rounds up (4), so the bound is 2,500, not 3,333. */
	check(bf_mem_rule(3 * M + P, 3 * M + P - 20000, 4 * M + P - 1, 2500), true, "chunks rounded up: at the bound");
	check(bf_mem_rule(3 * M + P, 3 * M + P - 20000, 4 * M + P - 1, 2600), false, "chunks rounded up: above the bound");

	/* Above the largest small size (3,072), a request is charged in whole chunks against real bytes: a large block
	   (a page run) as one fresh chunk, whatever the free pages, a huge block in its own chunks. */
	check(bf_mem_rule(M, 0, 2 * M - 1, 3072), true, "one chunk, no headroom: 3,072 is small, half the free space");
	check(bf_mem_rule(M, 0, 2 * M - 1, 3073), false, "one chunk, no headroom: 3,073 is large, needs a fresh chunk");
	check(bf_mem_rule(M, 0, 2 * M, 3073), true, "3,073 charged one chunk: fits");
	check(bf_mem_rule(3 * M, M, 4 * M - 1, 128 * 1024), false, "2 MiB free over three chunks: 128 KiB still needs a chunk");
	check(bf_mem_rule(M, 0, 2 * M - 1, M / 2), false, "one chunk, no headroom: half its free space is large");
	check(bf_mem_rule(3 * M, M, 4 * M, M - P), true, "M - P charged one chunk: fits");
	check(bf_mem_rule(3 * M, M, 4 * M - 1, M - P), false, "M - P charged one chunk: one byte short");
	check(bf_mem_rule(3 * M, M, 5 * M, M + 1), true, "M + 1 charged two chunks: fits");
	check(bf_mem_rule(3 * M, M, 5 * M - 1, M + 1), false, "M + 1 charged two chunks: one byte short");
	check(bf_mem_rule(3 * M, M, 4 * M, M - P - 1), true, "M - P - 1 charged one chunk: fits");
	check(bf_mem_rule(M, 0, 2 * M - 1, M - P), false, "one chunk, no headroom: M - P needs a new chunk");

	/* Used bytes bound a small request even if the counters disagree (used above real never happens in ZendMM). */
	check(bf_mem_rule(0, 3 * M, 3 * M, 1000), false, "used above real: used still bounds the request");

	/* A request above the limit never fits; the chunk rounding must not wrap. */
	check(bf_mem_rule(M, 0, 8 * M, SIZE_MAX), false, "SIZE_MAX");
	check(bf_mem_rule(M, 0, 8 * M, SIZE_MAX - M + 2), false, "rounding would wrap to 0");

	/* Counters at 0 (USE_ZEND_ALLOC=0 keeps them there). */
	check(bf_mem_rule(0, 0, 128 * M, 1000), true, "zero counters, headroom");

	return failed;
}
