#include <string.h>

#include "bf_core.h"

#if defined(__x86_64__)
# include <immintrin.h>
#elif defined(__aarch64__)
# include <arm_neon.h>
#endif

#define BF_BLOCK ((size_t)64)

/* One static table per allowed delimiter (SPEC 6.2): the delimiter byte, LF, CR and the quote. */
#define BF_SPECIAL(d) { [(unsigned char)(d)] = 1, ['\n'] = 1, ['\r'] = 1, ['"'] = 1 }
static const unsigned char bf_special_comma[256] = BF_SPECIAL(',');
static const unsigned char bf_special_semicolon[256] = BF_SPECIAL(';');
static const unsigned char bf_special_tab[256] = BF_SPECIAL('\t');
static const unsigned char bf_special_pipe[256] = BF_SPECIAL('|');

void bf_masks_scalar(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out)
{
	const unsigned char *p = (const unsigned char *)buf;
	/* The plan admits only these four bytes; any other value cannot reach here. */
	const unsigned char *special = delim == ';' ? bf_special_semicolon
		: delim == '\t' ? bf_special_tab : delim == '|' ? bf_special_pipe : bf_special_comma;

	for (size_t i = 0; i < nblocks; i++, p += BF_BLOCK) {
		uint64_t m = 0;

		for (unsigned j = 0; j < BF_BLOCK; j++) {
			m |= (uint64_t)special[p[j]] << j;
		}
		out[i] = m;
	}
}

#if defined(__x86_64__)
__attribute__((target("avx2")))
void bf_masks_avx2(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out)
{
	const __m256i dl = _mm256_set1_epi8((char)delim), lf = _mm256_set1_epi8('\n');
	const __m256i cr = _mm256_set1_epi8('\r'), quote = _mm256_set1_epi8('"');

	for (size_t i = 0; i < nblocks; i++) {
		const char *p = buf + i * BF_BLOCK;
		__m256i a = _mm256_loadu_si256((const __m256i *)p);
		__m256i b = _mm256_loadu_si256((const __m256i *)(p + 32));
		__m256i ma = _mm256_or_si256(_mm256_or_si256(_mm256_cmpeq_epi8(a, dl), _mm256_cmpeq_epi8(a, lf)),
			_mm256_or_si256(_mm256_cmpeq_epi8(a, cr), _mm256_cmpeq_epi8(a, quote)));
		__m256i mb = _mm256_or_si256(_mm256_or_si256(_mm256_cmpeq_epi8(b, dl), _mm256_cmpeq_epi8(b, lf)),
			_mm256_or_si256(_mm256_cmpeq_epi8(b, cr), _mm256_cmpeq_epi8(b, quote)));

		out[i] = (uint64_t)(uint32_t)_mm256_movemask_epi8(ma) | ((uint64_t)(uint32_t)_mm256_movemask_epi8(mb) << 32);
	}
}
const char *bf_tail_avx2(const char *buf, const uint64_t *masks, size_t nmasks, size_t *blkp, uint64_t *curp, size_t *nd);
__attribute__((target("avx2,popcnt")))
const char *bf_tail_avx2(const char *buf, const uint64_t *masks, size_t nmasks, size_t *blkp, uint64_t *curp, size_t *nd)
{
	const __m256i lf = _mm256_set1_epi8('\n'), cr = _mm256_set1_epi8('\r'), quote = _mm256_set1_epi8('"');
	size_t blk = *blkp, d = 0;
	uint64_t cur = *curp;

	for (;;) {
		const char *p = buf + blk * BF_BLOCK;
		__m256i a = _mm256_loadu_si256((const __m256i *)p);
		__m256i b = _mm256_loadu_si256((const __m256i *)(p + 32));
		__m256i ma = _mm256_or_si256(_mm256_or_si256(_mm256_cmpeq_epi8(a, lf), _mm256_cmpeq_epi8(a, cr)), _mm256_cmpeq_epi8(a, quote));
		__m256i mb = _mm256_or_si256(_mm256_or_si256(_mm256_cmpeq_epi8(b, lf), _mm256_cmpeq_epi8(b, cr)), _mm256_cmpeq_epi8(b, quote));
		uint64_t x = ((uint64_t)(uint32_t)_mm256_movemask_epi8(ma) | ((uint64_t)(uint32_t)_mm256_movemask_epi8(mb) << 32)) & cur;

		if (x != 0) {
			uint64_t low = x & (~x + 1);

			*nd = d + (size_t)_mm_popcnt_u64(cur & (low - 1));
			*curp = cur & ~((low << 1) - 1);
			*blkp = blk;
			return p + __builtin_ctzll(x);
		}
		d += (size_t)_mm_popcnt_u64(cur);
		if (++blk >= nmasks) {
			return NULL;
		}
		cur = masks[blk];
	}
}
#endif

#if defined(__aarch64__)
void bf_masks_neon(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out)
{
	const uint8x16_t dl = vdupq_n_u8(delim), lf = vdupq_n_u8('\n'), cr = vdupq_n_u8('\r'), quote = vdupq_n_u8('"');
	static const uint8_t bit[16] = { 1, 2, 4, 8, 16, 32, 64, 128, 1, 2, 4, 8, 16, 32, 64, 128 };
	const uint8x16_t bits = vld1q_u8(bit);

	for (size_t i = 0; i < nblocks; i++) {
		const uint8_t *p = (const uint8_t *)buf + i * BF_BLOCK;
		uint8x16_t sums[4];

		for (unsigned k = 0; k < 4; k++) {
			uint8x16_t v = vld1q_u8(p + k * 16);
			uint8x16_t m = vorrq_u8(vorrq_u8(vceqq_u8(v, dl), vceqq_u8(v, lf)), vorrq_u8(vceqq_u8(v, cr), vceqq_u8(v, quote)));

			sums[k] = vandq_u8(m, bits);
		}
		/* Bytes 0..15 of the block reduce to bits 0..15 of the mask, and so on. */
		uint8x16_t half = vpaddq_u8(vpaddq_u8(sums[0], sums[1]), vpaddq_u8(sums[2], sums[3]));

		out[i] = vgetq_lane_u64(vreinterpretq_u64_u8(vpaddq_u8(half, half)), 0);
	}
}
const char *bf_tail_neon(const char *buf, const uint64_t *masks, size_t nmasks, size_t *blkp, uint64_t *curp, size_t *nd);
const char *bf_tail_neon(const char *buf, const uint64_t *masks, size_t nmasks, size_t *blkp, uint64_t *curp, size_t *nd)
{
	const uint8x16_t lf = vdupq_n_u8('\n'), cr = vdupq_n_u8('\r'), quote = vdupq_n_u8('"');
	static const uint8_t bit[16] = { 1, 2, 4, 8, 16, 32, 64, 128, 1, 2, 4, 8, 16, 32, 64, 128 };
	const uint8x16_t bits = vld1q_u8(bit);
	size_t blk = *blkp, d = 0;
	uint64_t cur = *curp;

	for (;;) {
		const uint8_t *p = (const uint8_t *)buf + blk * BF_BLOCK;
		uint8x16_t sums[4];
		uint64_t x;

		for (unsigned k = 0; k < 4; k++) {
			uint8x16_t v = vld1q_u8(p + k * 16);

			sums[k] = vandq_u8(vorrq_u8(vorrq_u8(vceqq_u8(v, lf), vceqq_u8(v, cr)), vceqq_u8(v, quote)), bits);
		}
		{
			uint8x16_t half = vpaddq_u8(vpaddq_u8(sums[0], sums[1]), vpaddq_u8(sums[2], sums[3]));

			x = vgetq_lane_u64(vreinterpretq_u64_u8(vpaddq_u8(half, half)), 0) & cur;
		}
		if (x != 0) {
			uint64_t low = x & (~x + 1);

			*nd = d + (size_t)__builtin_popcountll(cur & (low - 1));
			*curp = cur & ~((low << 1) - 1);
			*blkp = blk;
			return (const char *)p + __builtin_ctzll(x);
		}
		d += (size_t)__builtin_popcountll(cur);
		if (++blk >= nmasks) {
			return NULL;
		}
		cur = masks[blk];
	}
}
#endif

bf_masks_fn bf_masks_auto = bf_masks_scalar;
const char *bf_masks_auto_name = "scalar";

const char *const bf_masks_compiled[] = {
	"scalar",
#if defined(__x86_64__)
	"avx2",
#elif defined(__aarch64__)
	"neon",
#endif
	NULL
};

#if defined(__x86_64__)
static bool bf_cpu_avx2(void)
{
	__builtin_cpu_init();
	return __builtin_cpu_supports("avx2") != 0;
}
#endif

void bf_masks_init(void)
{
#if defined(__x86_64__)
	if (bf_cpu_avx2()) {
		bf_masks_auto = bf_masks_avx2;
		bf_masks_auto_name = "avx2";
	}
#elif defined(__aarch64__)
	bf_masks_auto = bf_masks_neon;
	bf_masks_auto_name = "neon";
#endif
}

bf_masks_fn bf_masks_find(const char *name)
{
	if (strcmp(name, "scalar") == 0) {
		return bf_masks_scalar;
	}
#if defined(__x86_64__)
	if (strcmp(name, "avx2") == 0 && bf_cpu_avx2()) {
		return bf_masks_avx2;
	}
#endif
#if defined(__aarch64__)
	if (strcmp(name, "neon") == 0) {
		return bf_masks_neon;
	}
#endif
	return NULL;
}

bf_tail_fn bf_tail_for(bf_masks_fn masks)
{
#if defined(__x86_64__)
	if (masks == bf_masks_avx2) {
		return bf_tail_avx2;
	}
#endif
#if defined(__aarch64__)
	if (masks == bf_masks_neon) {
		return bf_tail_neon;
	}
#endif
	(void)masks;
	return NULL;
}

#ifdef BF_FUZZ_HASH_SHIM
size_t bf_fuzz_tail_min = BF_TAIL_MIN;
#endif
