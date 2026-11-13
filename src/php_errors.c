#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"

#include "php_batchflow.h"

#include <inttypes.h>

zend_class_entry *bf_ce_BatchFlowException;
zend_class_entry *bf_ce_SchemaException;
zend_class_entry *bf_ce_CsvException;
zend_class_entry *bf_ce_LimitException;
zend_class_entry *bf_ce_ResultTooLargeException;
zend_class_entry *bf_ce_SumOverflowException;
zend_class_entry *bf_ce_StreamException;

/* Non-printable bytes, quotes and backslashes become \xHH; output stops after 64 source bytes. */
void bf_escape_name(char out[BF_NAME_ESCAPED_MAX], const char *name, size_t len)
{
	static const char hex[] = "0123456789ABCDEF";
	size_t i, n = 0;

	for (i = 0; i < len && i < 64; i++) {
		unsigned char c = (unsigned char)name[i];

		if (c >= 0x20 && c <= 0x7E && c != '"' && c != '\\') {
			out[n++] = (char)c;
		} else {
			out[n++] = '\\';
			out[n++] = 'x';
			out[n++] = hex[c >> 4];
			out[n++] = hex[c & 15];
		}
	}
	if (len > 64) {
		memcpy(out + n, "...", 3);
		n += 3;
	}
	out[n] = '\0';
}

void bf_throw(zend_class_entry *ce, const char *fmt, ...)
{
	va_list args;
	char *message;

	va_start(args, fmt);
	zend_vspprintf(&message, 0, fmt, args);
	va_end(args);
	zend_throw_exception(ce, message, 0);
	efree(message);
}

void bf_raise_core_error(const bf_error *e, const bf_col *cols)
{
	static const char *const type_names[] = {"int64", "float64", "bool", "string"};
	static const char *const kinds[] = {"malformed value", "quoted empty field", "out-of-range number"};
	char name[BF_NAME_ESCAPED_MAX] = "";
	zend_class_entry *ce = bf_ce_CsvException;
	char *reason = NULL;
	bool prefixed = true;

	if (e->column >= 0) {
		bf_escape_name(name, cols[e->column].name, cols[e->column].name_len);
	}
	switch (e->reason) {
		case BF_E_MISSING_HEADER:
			zend_spprintf(&reason, 0, "missing header");
			break;
		case BF_E_TOO_MANY_COLUMNS:
			zend_spprintf(&reason, 0, "too many columns (limit 4096)");
			break;
		case BF_E_DUPLICATE_COLUMN:
			zend_spprintf(&reason, 0, "duplicate column \"%s\"", name);
			break;
		case BF_E_UNEXPECTED_COLUMN:
			zend_spprintf(&reason, 0, "unexpected column at position %" PRIu64, e->n1);
			break;
		case BF_E_MISSING_COLUMN:
			zend_spprintf(&reason, 0, "missing column \"%s\"", name);
			break;
		case BF_E_BARE_QUOTE:
			zend_spprintf(&reason, 0, "bare quote in unquoted field");
			break;
		case BF_E_UNTERMINATED_QUOTE:
			zend_spprintf(&reason, 0, "unterminated quoted field");
			break;
		case BF_E_TEXT_AFTER_QUOTE:
			zend_spprintf(&reason, 0, "text after closing quote");
			break;
		case BF_E_BARE_CR:
			zend_spprintf(&reason, 0, "bare CR");
			break;
		case BF_E_FIELD_COUNT_LOW:
			zend_spprintf(&reason, 0, "expected %" PRIu64 " fields, got %" PRIu64, e->n1, e->n2);
			break;
		case BF_E_FIELD_COUNT_HIGH:
			zend_spprintf(&reason, 0, "expected %" PRIu64 " fields, got more", e->n1);
			break;
		case BF_E_BAD_VALUE:
			zend_spprintf(&reason, 0, "column \"%s\": expected %s, got %s", name, type_names[cols[e->column].type], kinds[e->n1]);
			break;
		case BF_E_BAD_UTF8:
			zend_spprintf(&reason, 0, "column \"%s\": invalid UTF-8", name);
			break;
		case BF_E_ROW_TOO_LONG:
			ce = bf_ce_LimitException;
			zend_spprintf(&reason, 0, "row exceeds maxRowBytes");
			break;
		case BF_E_FIELD_TOO_LONG:
			ce = bf_ce_LimitException;
			zend_spprintf(&reason, 0, "column \"%s\": field exceeds maxFieldBytes", name);
			break;
		case BF_E_INPUT_ROWS:
			ce = bf_ce_LimitException;
			zend_spprintf(&reason, 0, "input exceeds maxInputRows");
			break;
		case BF_E_MEMORY:
			ce = bf_ce_LimitException;
			zend_spprintf(&reason, 0, "memory_limit would be exceeded");
			break;
		case BF_E_SUM_INT_OVERFLOW:
			ce = bf_ce_SumOverflowException;
			zend_spprintf(&reason, 0, "int64 sum overflow in column \"%s\"", name);
			break;
		case BF_E_SUM_NOT_FINITE:
			ce = bf_ce_SumOverflowException;
			zend_spprintf(&reason, 0, "float64 sum of column \"%s\" is not finite", name);
			prefixed = false;
			break;
		case BF_E_MAX_GROUPS:
			ce = bf_ce_ResultTooLargeException;
			zend_spprintf(&reason, 0, "result exceeds maxGroups (%" PRIu64 ")", e->n1);
			break;
		case BF_E_MAX_ROWS:
			ce = bf_ce_ResultTooLargeException;
			zend_spprintf(&reason, 0, "result exceeds maxRows (%" PRIu64 ")", e->n1);
			break;
		case BF_E_GROUP_BYTES:
			ce = bf_ce_LimitException;
			zend_spprintf(&reason, 0, "group state exceeds maxGroupBytes");
			break;
		case BF_E_TABLE_BYTES:
			ce = bf_ce_LimitException;
			zend_spprintf(&reason, 0, "table exceeds maxTableBytes");
			break;
		case BF_E_DICT_SIZE:
			ce = bf_ce_LimitException;
			zend_spprintf(&reason, 0, "dictionary for column \"%s\" exceeds maxDictionarySize", name);
			break;
		case BF_E_READ_FAILED:
			ce = bf_ce_StreamException;
			zend_spprintf(&reason, 0, "reading the input stream failed");
			prefixed = false;
			break;
		case BF_E_NO_DATA:
			ce = bf_ce_StreamException;
			zend_spprintf(&reason, 0, "input stream returned no data before EOF");
			prefixed = false;
			break;
		case BF_E_TIMED_OUT:
			ce = bf_ce_BatchFlowException;
			zend_spprintf(&reason, 0, "stopped by execution timeout");
			prefixed = false;
			break;
		case BF_E_NONE:
			return;
	}
	if (prefixed && e->where == BF_WHERE_HEADER) {
		bf_throw(ce, "header (line 1, offset %" PRIu64 "): %s", e->offset, reason);
	} else if (prefixed && e->where == BF_WHERE_TABLE_ROW) {
		bf_throw(ce, "row %" PRIu64 ": %s", e->row, reason);
	} else if (prefixed && e->where == BF_WHERE_ROW) {
		bf_throw(ce, "row %" PRIu64 " (line %" PRIu64 ", offset %" PRIu64 "): %s", e->row, e->line, e->offset, reason);
	} else {
		zend_throw_exception(ce, reason, 0);
	}
	efree(reason);
}
