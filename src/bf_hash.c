#include <string.h>

#include "bf_core.h"

/* SipHash-1-3 (SPEC 6.3): one compression round per 8-byte word, three finalization rounds. */

#define BF_ROTL(x, b) (uint64_t)(((x) << (b)) | ((x) >> (64 - (b))))

#define BF_SIPROUND \
	do { \
		v0 += v1; v1 = BF_ROTL(v1, 13); v1 ^= v0; v0 = BF_ROTL(v0, 32); \
		v2 += v3; v3 = BF_ROTL(v3, 16); v3 ^= v2; \
		v0 += v3; v3 = BF_ROTL(v3, 21); v3 ^= v0; \
		v2 += v1; v1 = BF_ROTL(v1, 17); v1 ^= v2; v2 = BF_ROTL(v2, 32); \
	} while (0)

static inline uint64_t bf_le64(const unsigned char *p)
{
	uint64_t v;

	memcpy(&v, p, 8);
#if defined(__BYTE_ORDER__) && __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
	v = __builtin_bswap64(v);
#endif
	return v;
}

uint64_t bf_siphash13(const uint64_t key[2], const void *data, size_t n)
{
	const unsigned char *p = data;
	uint64_t v0 = UINT64_C(0x736f6d6570736575) ^ key[0];
	uint64_t v1 = UINT64_C(0x646f72616e646f6d) ^ key[1];
	uint64_t v2 = UINT64_C(0x6c7967656e657261) ^ key[0];
	uint64_t v3 = UINT64_C(0x7465646279746573) ^ key[1];
	uint64_t b = (uint64_t)n << 56;
	const size_t whole = n & ~(size_t)7;

	for (size_t i = 0; i < whole; i += 8) {
		uint64_t m = bf_le64(p + i);

		v3 ^= m;
		BF_SIPROUND;
		v0 ^= m;
	}
	for (size_t i = n & 7; i > 0; i--) {
		b |= (uint64_t)p[whole + i - 1] << (8 * (i - 1));
	}
	v3 ^= b;
	BF_SIPROUND;
	v0 ^= b;
	v2 ^= 0xff;
	BF_SIPROUND;
	BF_SIPROUND;
	BF_SIPROUND;
	return v0 ^ v1 ^ v2 ^ v3;
}

/* The same function over the 8-byte little-endian two's complement form of v. */
uint64_t bf_siphash13_u64(const uint64_t key[2], uint64_t v)
{
	uint64_t v0 = UINT64_C(0x736f6d6570736575) ^ key[0];
	uint64_t v1 = UINT64_C(0x646f72616e646f6d) ^ key[1];
	uint64_t v2 = UINT64_C(0x6c7967656e657261) ^ key[0];
	uint64_t v3 = UINT64_C(0x7465646279746573) ^ key[1];
	const uint64_t b = (uint64_t)8 << 56;

	v3 ^= v;
	BF_SIPROUND;
	v0 ^= v;
	v3 ^= b;
	BF_SIPROUND;
	v0 ^= b;
	v2 ^= 0xff;
	BF_SIPROUND;
	BF_SIPROUND;
	BF_SIPROUND;
	return v0 ^ v1 ^ v2 ^ v3;
}

void bf_sip_init(bf_sip *s, const uint64_t key[2])
{
	s->v0 = UINT64_C(0x736f6d6570736575) ^ key[0];
	s->v1 = UINT64_C(0x646f72616e646f6d) ^ key[1];
	s->v2 = UINT64_C(0x6c7967656e657261) ^ key[0];
	s->v3 = UINT64_C(0x7465646279746573) ^ key[1];
	s->tail = 0;
	s->total = 0;
}

static inline void bf_sip_word(bf_sip *s, uint64_t m)
{
	uint64_t v0 = s->v0, v1 = s->v1, v2 = s->v2, v3 = s->v3;

	v3 ^= m;
	BF_SIPROUND;
	v0 ^= m;
	s->v0 = v0;
	s->v1 = v1;
	s->v2 = v2;
	s->v3 = v3;
}

/* Bytes go into the pending word in input order; each full word is compressed as bf_siphash13 would. */
void bf_sip_update(bf_sip *s, const void *data, size_t n)
{
	const unsigned char *p = data;
	size_t fill = s->total & 7;

	s->total += n;
	while (n > 0 && fill != 0) {
		s->tail |= (uint64_t)*p++ << (8 * fill);
		n--;
		if (++fill == 8) {
			bf_sip_word(s, s->tail);
			s->tail = 0;
			fill = 0;
		}
	}
	for (; n >= 8; n -= 8, p += 8) {
		bf_sip_word(s, bf_le64(p));
	}
	for (size_t i = 0; i < n; i++) {
		s->tail |= (uint64_t)p[i] << (8 * i);
	}
}

uint64_t bf_sip_final(const bf_sip *s)
{
	uint64_t v0 = s->v0, v1 = s->v1, v2 = s->v2, v3 = s->v3;
	const uint64_t b = ((uint64_t)s->total << 56) | s->tail;

	v3 ^= b;
	BF_SIPROUND;
	v0 ^= b;
	v2 ^= 0xff;
	BF_SIPROUND;
	BF_SIPROUND;
	BF_SIPROUND;
	return v0 ^ v1 ^ v2 ^ v3;
}
