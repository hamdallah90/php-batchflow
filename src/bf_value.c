#include <math.h>
#include <string.h>

#include "bf_core.h"

/* Removes the second quote of every doubled pair, in place in the read buffer. */
size_t bf_unquote_inplace(char *p, size_t len)
{
	char *d = p;

	for (size_t i = 0; i < len; i++) {
		*d++ = p[i];
		if (p[i] == '"') {
			i++;
		}
	}
	return (size_t)(d - p);
}

/* Canonical decimal: -?(0|[1-9][0-9]*), no "-0", no leading zeros, no plus. */
int bf_parse_int64(const char *s, size_t n, int64_t *out)
{
	size_t i = 0, ndigits;
	bool neg = false;
	uint64_t v = 0;

	if (s[0] == '-') {
		neg = true;
		i = 1;
		if (n == 1) {
			return BF_KIND_MALFORMED + 1;
		}
	}
	if (s[i] == '0') {
		if (neg || n - i != 1) {
			return BF_KIND_MALFORMED + 1;
		}
		*out = 0;
		return 0;
	}
	ndigits = n - i;
	for (; i < n; i++) {
		unsigned d = (unsigned char)s[i] - (unsigned)'0';

		if (d > 9) {
			return BF_KIND_MALFORMED + 1;
		}
		if (ndigits <= 19) {
			v = v * 10 + d;
		}
	}
	if (ndigits > 19) {
		return BF_KIND_OUT_OF_RANGE + 1;
	}
	if (neg) {
		if (v > UINT64_C(9223372036854775808)) {
			return BF_KIND_OUT_OF_RANGE + 1;
		}
		*out = (int64_t)(~v + 1);
	} else {
		if (v > (uint64_t)INT64_MAX) {
			return BF_KIND_OUT_OF_RANGE + 1;
		}
		*out = (int64_t)v;
	}
	return 0;
}

static const double bf_pow10[23] = {
	1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
	1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22
};

/*
 * zend_strtod replaces an exponent magnitude above 19999 with 19999. The result is 0 or infinity either
 * way until the digits offset that exponent: the shortest field where the clamp changes the result is
 * 19,676 digits plus "e-20000", which should be 0 and becomes 1e-323.
 */
#define BF_STRTOD_MAX_EXP     19999
#define BF_STRTOD_CLAMP_BYTES 19683

/*
 * Grammar: [+-]?(d+(.d*)?|.d+)([eE][+-]?d+)?, the whole field. Correctly rounded either way:
 * Clinger's exact fast path, otherwise the host's strtod. A non-finite result is out of range.
 */
int bf_parse_float64(const bf_host *host, const char *s, size_t n, double *out)
{
	size_t i = 0;
	bool neg = false, any = false;
	uint64_t mant = 0;
	int digits = 0, frac = 0, exp = 0;

	if (s[0] == '-') {
		neg = true;
		i = 1;
	} else if (s[0] == '+') {
		i = 1;
	}
	for (; i < n && (unsigned)((unsigned char)s[i] - '0') <= 9; i++) {
		unsigned c = (unsigned char)s[i] - (unsigned)'0';

		any = true;
		if (digits < 19) {
			mant = mant * 10 + c;
			if (mant != 0) {
				digits++;
			}
		}
	}
	if (i < n && s[i] == '.') {
		i++;
		for (; i < n && (unsigned)((unsigned char)s[i] - '0') <= 9; i++) {
			unsigned c = (unsigned char)s[i] - (unsigned)'0';

			any = true;
			if (digits < 19) {
				mant = mant * 10 + c;
				if (mant != 0) {
					digits++;
				}
				frac++;
			}
		}
	}
	if (!any) {
		return BF_KIND_MALFORMED + 1;
	}
	if (i < n && (s[i] == 'e' || s[i] == 'E')) {
		bool eneg = false, edigit = false;

		i++;
		if (i < n && (s[i] == '-' || s[i] == '+')) {
			eneg = s[i] == '-';
			i++;
		}
		for (; i < n && (unsigned)((unsigned char)s[i] - '0') <= 9; i++) {
			edigit = true;
			if (exp < 100000) {
				exp = exp * 10 + ((unsigned char)s[i] - '0');
			}
		}
		if (!edigit) {
			return BF_KIND_MALFORMED + 1;
		}
		if (eneg) {
			exp = -exp;
		}
	}
	if (i != n) {
		return BF_KIND_MALFORMED + 1;
	}

	{
		int e10 = exp - frac;
		double d;

		if (n >= BF_STRTOD_CLAMP_BYTES && (exp > BF_STRTOD_MAX_EXP || exp < -BF_STRTOD_MAX_EXP)) {
			return BF_KIND_OUT_OF_RANGE + 1;
		}
		/* Digits past 19 are dropped from mant, which then already exceeds 2^53 and takes strtod. */
		if (mant <= (UINT64_C(1) << 53) && e10 >= -22 && e10 <= 22) {
			d = (double)mant;
			d = e10 < 0 ? d / bf_pow10[-e10] : d * bf_pow10[e10];
			*out = neg ? -d : d;
			return 0;
		}
		if (!host->strtod(host->ud, s, n, &d)) {
			return BF_KIND_MALFORMED + 1;
		}
		if (!isfinite(d)) {
			return BF_KIND_OUT_OF_RANGE + 1;
		}
		*out = d;
		return 0;
	}
}

/* Rejects overlong forms, surrogates, and anything above U+10FFFF. */
bool bf_utf8_valid(const char *str, size_t n)
{
	const unsigned char *s = (const unsigned char *)str;
	size_t i = 0;

	while (i < n) {
		uint64_t w;
		unsigned char c;

		while (i + 8 <= n) {
			memcpy(&w, s + i, 8);
			if (w & UINT64_C(0x8080808080808080)) {
				break;
			}
			i += 8;
		}
		if (i >= n) {
			break;
		}
		c = s[i];
		if (c < 0x80) {
			i++;
		} else if (c < 0xC2) {
			return false;
		} else if (c < 0xE0) {
			if (i + 1 >= n || (s[i + 1] & 0xC0) != 0x80) {
				return false;
			}
			i += 2;
		} else if (c < 0xF0) {
			if (i + 2 >= n || (s[i + 1] & 0xC0) != 0x80 || (s[i + 2] & 0xC0) != 0x80) {
				return false;
			}
			if ((c == 0xE0 && s[i + 1] < 0xA0) || (c == 0xED && s[i + 1] > 0x9F)) {
				return false;
			}
			i += 3;
		} else if (c < 0xF5) {
			if (i + 3 >= n || (s[i + 1] & 0xC0) != 0x80 || (s[i + 2] & 0xC0) != 0x80 || (s[i + 3] & 0xC0) != 0x80) {
				return false;
			}
			if ((c == 0xF0 && s[i + 1] < 0x90) || (c == 0xF4 && s[i + 1] > 0x8F)) {
				return false;
			}
			i += 4;
		} else {
			return false;
		}
	}
	return true;
}
