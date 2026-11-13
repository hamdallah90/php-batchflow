#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include <inttypes.h>

#include "php.h"
#include "zend_exceptions.h"

#include "php_batchflow.h"

static zend_object_handlers bf_query_handlers;

/* ---- plan compilation ---- */

void bf_plan_addref(bf_plan *plan)
{
	plan->refcount++;
}

void bf_plan_release(bf_plan *plan)
{
	if (--plan->refcount == 0) {
		if (plan->dict != NULL) {
			efree(plan->dict);
		}
		efree(plan);
	}
}

int bf_plan_find(const bf_plan *plan, const char *name, size_t len)
{
	for (uint32_t i = 0; i < plan->ncols; i++) {
		if (plan->cols[i].name_len == len && memcmp(plan->cols[i].name, name, len) == 0) {
			return (int)i;
		}
	}
	return -1;
}

static bool bf_parse_type(zval *v, bf_type *out)
{
	static const struct { const char *name; size_t len; bf_type type; } types[] = {
		{"int64", 5, BF_INT64}, {"float64", 7, BF_FLOAT64}, {"bool", 4, BF_BOOL}, {"string", 6, BF_STRING},
	};

	if (Z_TYPE_P(v) != IS_STRING) {
		return false;
	}
	for (size_t i = 0; i < sizeof(types) / sizeof(types[0]); i++) {
		if (Z_STRLEN_P(v) == types[i].len && memcmp(Z_STRVAL_P(v), types[i].name, types[i].len) == 0) {
			*out = types[i].type;
			return true;
		}
	}
	return false;
}

static void bf_schema_throw(const char *name, size_t len, const char *what)
{
	char escaped[BF_NAME_ESCAPED_MAX];

	bf_escape_name(escaped, name, len);
	bf_throw(bf_ce_SchemaException, "column \"%s\": %s", escaped, what);
}

static void bf_option_throw(const char *name, size_t len, const char *what)
{
	char escaped[BF_NAME_ESCAPED_MAX];

	bf_escape_name(escaped, name, len);
	bf_throw(bf_ce_SchemaException, "option \"%s\": %s", escaped, what);
}

/* A plain int within the option's range, no coercion. */
static bool bf_option_int(zval *v, zend_string *key, zend_long min, zend_long max, zend_long *out)
{
	if (Z_TYPE_P(v) != IS_LONG) {
		bf_option_throw(ZSTR_VAL(key), ZSTR_LEN(key), "wrong type");
		return false;
	}
	if (Z_LVAL_P(v) < min || Z_LVAL_P(v) > max) {
		bf_option_throw(ZSTR_VAL(key), ZSTR_LEN(key), "out of range");
		return false;
	}
	*out = Z_LVAL_P(v);
	return true;
}

static bool bf_option_dictionary(bf_plan *plan, zval *v)
{
	zval *entry;
	char escaped[BF_NAME_ESCAPED_MAX];
	bool seen[BF_MAX_SCHEMA_COLS] = { false };

	if (Z_TYPE_P(v) != IS_ARRAY || !zend_array_is_list(Z_ARRVAL_P(v))) {
		bf_throw(bf_ce_SchemaException, "option \"dictionary\": wrong type");
		return false;
	}
	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(v), entry) {
		int col;

		ZVAL_DEREF(entry);
		if (Z_TYPE_P(entry) != IS_STRING) {
			bf_throw(bf_ce_SchemaException, "option \"dictionary\": wrong type");
			return false;
		}
		bf_escape_name(escaped, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
		col = bf_plan_find(plan, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
		if (col < 0) {
			bf_throw(bf_ce_SchemaException, "unknown column \"%s\"", escaped);
			return false;
		}
		if (plan->cols[col].type != BF_STRING && plan->cols[col].type != BF_INT64) {
			bf_throw(bf_ce_SchemaException, "option \"dictionary\": column \"%s\" must be string or int64", escaped);
			return false;
		}
		if (seen[col]) {
			bf_throw(bf_ce_SchemaException, "option \"dictionary\": out of range");
			return false;
		}
		seen[col] = true;
	} ZEND_HASH_FOREACH_END();
	/* A later "dictionary" key in the same options array replaces an earlier one. */
	if (plan->dict == NULL) {
		plan->dict = ecalloc(plan->ncols, sizeof(bool));
	}
	memcpy(plan->dict, seen, plan->ncols * sizeof(bool));
	return true;
}

static bool bf_option_simd(bf_plan *plan, zval *v)
{
	static const char *const known[] = {"auto", "scalar", "avx2", "neon"};

	if (Z_TYPE_P(v) != IS_STRING) {
		bf_throw(bf_ce_SchemaException, "option \"simd\": wrong type");
		return false;
	}
	for (size_t i = 0; i < sizeof(known) / sizeof(known[0]); i++) {
		if (zend_string_equals_cstr(Z_STR_P(v), known[i], strlen(known[i]))) {
			if (i == 0) {
				plan->masks = bf_masks_auto;
				return true;
			}
			plan->masks = bf_masks_find(known[i]);
			if (plan->masks == NULL) {
				bf_throw(bf_ce_SchemaException, "option \"simd\": %s is not available on this CPU", known[i]);
				return false;
			}
			return true;
		}
	}
	bf_throw(bf_ce_SchemaException, "option \"simd\": out of range");
	return false;
}

/* Exactly one of the bytes , ; tab | (SPEC 5.3), compared as bytes. */
static bool bf_option_delimiter(bf_plan *plan, zval *v)
{
	if (Z_TYPE_P(v) != IS_STRING) {
		bf_throw(bf_ce_SchemaException, "option \"delimiter\": wrong type");
		return false;
	}
	if (Z_STRLEN_P(v) != 1 || memchr(",;\t|", Z_STRVAL_P(v)[0], 4) == NULL) {
		bf_throw(bf_ce_SchemaException, "option \"delimiter\": out of range");
		return false;
	}
	plan->delim = (unsigned char)Z_STRVAL_P(v)[0];
	return true;
}

/* Options in array order, values checked with no coercion. */
static bool bf_plan_options(bf_plan *plan, HashTable *options, bool is_table)
{
	zend_string *key;
	zend_ulong idx;
	zval *v;

	if (options == NULL) {
		return true;
	}
	ZEND_HASH_FOREACH_KEY_VAL(options, idx, key, v) {
		zend_long n = 0;

		ZVAL_DEREF(v);
		if (key == NULL) {
			char buf[MAX_LENGTH_OF_LONG];
			char *s = zend_print_long_to_buf(buf + sizeof(buf) - 1, (zend_long)idx);

			bf_option_throw(s, (size_t)(buf + sizeof(buf) - 1 - s), "unknown option");
			return false;
		}
		if (zend_string_equals_literal(key, "allowExtraColumns")) {
			if (Z_TYPE_P(v) != IS_TRUE && Z_TYPE_P(v) != IS_FALSE) {
				bf_option_throw(ZSTR_VAL(key), ZSTR_LEN(key), "wrong type");
				return false;
			}
			plan->allow_extra = Z_TYPE_P(v) == IS_TRUE;
		} else if (zend_string_equals_literal(key, "maxFieldBytes")) {
			if (!bf_option_int(v, key, 1, 67108864, &n)) {
				return false;
			}
			plan->max_field_bytes = (size_t)n;
		} else if (zend_string_equals_literal(key, "maxRowBytes")) {
			if (!bf_option_int(v, key, 1, 268435456, &n)) {
				return false;
			}
			plan->max_row_bytes = (size_t)n;
		} else if (zend_string_equals_literal(key, "maxInputRows")) {
			if (!bf_option_int(v, key, 1, ZEND_LONG_MAX, &n)) {
				return false;
			}
			plan->max_input_rows = (uint64_t)n;
		} else if (zend_string_equals_literal(key, "maxGroupBytes")) {
			if (!bf_option_int(v, key, 1048576, 1099511627776, &n)) {
				return false;
			}
			plan->max_group_bytes = (uint64_t)n;
		} else if (zend_string_equals_literal(key, "simd")) {
			if (!bf_option_simd(plan, v)) {
				return false;
			}
		} else if (zend_string_equals_literal(key, "delimiter")) {
			if (!bf_option_delimiter(plan, v)) {
				return false;
			}
		} else if (is_table && zend_string_equals_literal(key, "dictionary")) {
			if (!bf_option_dictionary(plan, v)) {
				return false;
			}
		} else if (is_table && zend_string_equals_literal(key, "maxDictionarySize")) {
			if (!bf_option_int(v, key, 1, 4294967294, &n)) {
				return false;
			}
			plan->max_dictionary_size = (uint64_t)n;
		} else if (is_table && zend_string_equals_literal(key, "maxTableBytes")) {
			if (!bf_option_int(v, key, 1048576, 1099511627776, &n)) {
				return false;
			}
			plan->max_table_bytes = (uint64_t)n;
		} else {
			bf_option_throw(ZSTR_VAL(key), ZSTR_LEN(key), "unknown option");
			return false;
		}
	} ZEND_HASH_FOREACH_END();
	return true;
}

bf_plan *bf_plan_compile(HashTable *schema, HashTable *options, bool is_table)
{
	uint32_t count = zend_hash_num_elements(schema), i = 0;
	size_t total_names = 0;
	zend_string *key;
	zend_ulong idx;
	zval *v;
	bf_plan *plan;
	char *names;
	char buf[MAX_LENGTH_OF_LONG];

	if (count == 0) {
		bf_throw(bf_ce_SchemaException, "schema must not be empty");
		return NULL;
	}
	if (count > BF_MAX_SCHEMA_COLS) {
		bf_throw(bf_ce_SchemaException, "too many columns (limit 1024)");
		return NULL;
	}

	/* Entries in array order, name before type; every name fits one allocation afterwards. */
	ZEND_HASH_FOREACH_KEY_VAL(schema, idx, key, v) {
		const char *name;
		size_t len;
		bf_type type;

		ZVAL_DEREF(v);
		if (key != NULL) {
			name = ZSTR_VAL(key);
			len = ZSTR_LEN(key);
		} else {
			name = zend_print_long_to_buf(buf + sizeof(buf) - 1, (zend_long)idx);
			len = (size_t)(buf + sizeof(buf) - 1 - name);
		}
		if (len == 0 || len > BF_MAX_NAME_BYTES || !bf_utf8_valid(name, len)) {
			bf_schema_throw(name, len, "invalid name");
			return NULL;
		}
		if (!bf_parse_type(v, &type)) {
			bf_schema_throw(name, len, "unknown type");
			return NULL;
		}
		total_names += len;
	} ZEND_HASH_FOREACH_END();

	plan = emalloc(sizeof(bf_plan) + (size_t)count * sizeof(bf_col) + total_names);
	plan->refcount = 1;
	plan->ncols = count;
	plan->cols = (bf_col *)(plan + 1);
	plan->allow_extra = false;
	plan->max_field_bytes = 1048576;
	plan->max_row_bytes = 16777216;
	plan->max_input_rows = 100000000;
	plan->max_group_bytes = 268435456;
	plan->max_dictionary_size = 16777216;
	plan->max_table_bytes = UINT64_C(4294967296);
	plan->dict = NULL;
	plan->masks = bf_masks_auto;
	plan->delim = ',';
	names = (char *)(plan->cols + count);

	ZEND_HASH_FOREACH_KEY_VAL(schema, idx, key, v) {
		const char *name;
		size_t len;

		ZVAL_DEREF(v);
		if (key != NULL) {
			name = ZSTR_VAL(key);
			len = ZSTR_LEN(key);
		} else {
			name = zend_print_long_to_buf(buf + sizeof(buf) - 1, (zend_long)idx);
			len = (size_t)(buf + sizeof(buf) - 1 - name);
		}
		memcpy(names, name, len);
		plan->cols[i].name = names;
		plan->cols[i].name_len = len;
		bf_parse_type(v, &plan->cols[i].type);
		names += len;
		i++;
	} ZEND_HASH_FOREACH_END();

	if (!bf_plan_options(plan, options, is_table)) {
		bf_plan_release(plan);
		return NULL;
	}
	return plan;
}

/* ---- Query objects ---- */

static zend_object *bf_query_create(zend_class_entry *ce)
{
	bf_query *q = zend_object_alloc(sizeof(bf_query), ce);

	zend_object_std_init(&q->std, ce);
	object_properties_init(&q->std, ce);
	q->std.handlers = &bf_query_handlers;
	return &q->std;
}

static void bf_query_free(zend_object *obj)
{
	bf_query *q = bf_query_from_obj(obj);

	for (uint32_t i = 0; i < q->npreds; i++) {
		if (q->preds[i].owner != NULL) {
			zend_string_release_ex(q->preds[i].owner, 0);
		}
	}
	if (q->preds != NULL) {
		efree(q->preds);
	}
	if (q->plan != NULL) {
		bf_plan_release(q->plan);
	}
	if (q->path != NULL) {
		zend_string_release(q->path);
	}
	if (q->stream != NULL) {
		zend_list_delete(q->stream);
	}
	if (q->table != NULL) {
		OBJ_RELEASE(q->table);
	}
	zend_object_std_dtor(obj);
}

void bf_query_minit(void)
{
	memcpy(&bf_query_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	bf_query_handlers.offset = offsetof(bf_query, std);
	bf_query_handlers.free_obj = bf_query_free;
	bf_query_handlers.clone_obj = NULL;
	bf_ce_Query->create_object = bf_query_create;
	bf_ce_Query->default_object_handlers = &bf_query_handlers;
}

static bf_query *bf_query_new(zval *return_value)
{
	object_init_ex(return_value, bf_ce_Query);
	return bf_query_from_obj(Z_OBJ_P(return_value));
}

void bf_query_from_table(zval *return_value, zend_object *table, bf_plan *plan, const bf_table *store)
{
	bf_query *q = bf_query_new(return_value);

	q->plan = plan;
	bf_plan_addref(plan);
	q->table = table;
	GC_ADDREF(table);
	q->store = store;
}

ZEND_METHOD(BatchFlow_Csv, scan)
{
	zend_string *path;
	HashTable *schema, *options = NULL;
	bf_plan *plan;
	bf_query *q;

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_PATH_STR(path)
		Z_PARAM_ARRAY_HT(schema)
		Z_PARAM_OPTIONAL
		Z_PARAM_ARRAY_HT(options)
	ZEND_PARSE_PARAMETERS_END();

	if (ZSTR_LEN(path) == 0) {
		zend_argument_value_error(1, "must not be empty");
		RETURN_THROWS();
	}
	plan = bf_plan_compile(schema, options, false);
	if (plan == NULL) {
		RETURN_THROWS();
	}
	q = bf_query_new(return_value);
	q->plan = plan;
	q->path = zend_string_copy(path);
}

ZEND_METHOD(BatchFlow_Csv, scanStream)
{
	zval *zstream;
	php_stream *stream;
	HashTable *schema, *options = NULL;
	bf_plan *plan;
	bf_query *q;

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_ZVAL(zstream)
		Z_PARAM_ARRAY_HT(schema)
		Z_PARAM_OPTIONAL
		Z_PARAM_ARRAY_HT(options)
	ZEND_PARSE_PARAMETERS_END();

	php_stream_from_zval(stream, zstream);
	(void)stream;
	plan = bf_plan_compile(schema, options, false);
	if (plan == NULL) {
		RETURN_THROWS();
	}
	q = bf_query_new(return_value);
	q->plan = plan;
	q->stream = Z_RES_P(zstream);
	GC_ADDREF(q->stream);
}

/* ---- predicates ---- */


static const char *const bf_where_names[] = {"whereEq", "whereNe", "whereGt", "whereGte", "whereLt", "whereLte"};

#define BF_REASON_MAX 96

/*
 * The whereEq literal rules of SPEC 5.7 for a non-null literal: NULL when value can be compared with col
 * (for a float64 column *d receives it as a double), else the reason without its column prefix.
 */
static const char *bf_literal_check(const bf_col *col, zval *value, double *d, char reason[BF_REASON_MAX])
{
	static const char *const type_names[] = {"int64", "float64", "bool", "string"};

	switch (col->type) {
		case BF_INT64:
			if (Z_TYPE_P(value) == IS_LONG) {
				return NULL;
			}
			break;
		case BF_FLOAT64:
			if (Z_TYPE_P(value) == IS_DOUBLE) {
				if (!zend_finite(Z_DVAL_P(value))) {
					return "float literal must be finite";
				}
				*d = Z_DVAL_P(value);
				return NULL;
			}
			if (Z_TYPE_P(value) == IS_LONG) {
				double x = (double)Z_LVAL_P(value);

				if (x >= 9223372036854775808.0 || (zend_long)x != Z_LVAL_P(value)) {
					return "integer literal is not exactly representable as float64";
				}
				*d = x;
				return NULL;
			}
			break;
		case BF_BOOL:
			if (Z_TYPE_P(value) == IS_TRUE || Z_TYPE_P(value) == IS_FALSE) {
				return NULL;
			}
			break;
		case BF_STRING:
			if (Z_TYPE_P(value) == IS_STRING) {
				if (!bf_utf8_valid(Z_STRVAL_P(value), Z_STRLEN_P(value))) {
					return "string literal is not valid UTF-8";
				}
				if (Z_STRLEN_P(value) > BF_MAX_LITERAL) {
					return "string literal exceeds 1048576 bytes";
				}
				return NULL;
			}
			break;
	}
	snprintf(reason, BF_REASON_MAX, "value of type %s cannot be compared with %s column", zend_zval_type_name(value), type_names[col->type]);
	return reason;
}

/* Predicate count, then unknown column (SPEC 5.11): the column's schema index, or -1 with an exception set. */
static int bf_where_column(const bf_query *q, zend_string *column)
{
	int col;

	if (q->npreds >= BF_MAX_PREDS) {
		bf_throw(bf_ce_SchemaException, "too many predicates (limit 64)");
		return -1;
	}
	col = bf_plan_find(q->plan, ZSTR_VAL(column), ZSTR_LEN(column));
	if (col < 0) {
		char escaped[BF_NAME_ESCAPED_MAX];

		bf_escape_name(escaped, ZSTR_VAL(column), ZSTR_LEN(column));
		bf_throw(bf_ce_SchemaException, "unknown column \"%s\"", escaped);
	}
	return col;
}

/* A new Query holding the receiver's predicates plus pred, whose owner reference it takes; the receiver is unchanged. */
static void bf_query_derive(const bf_query *q, const bf_pred *pred, zval *return_value)
{
	bf_query *next = bf_query_new(return_value);

	next->plan = q->plan;
	bf_plan_addref(next->plan);
	if (q->path != NULL) {
		next->path = zend_string_copy(q->path);
	}
	if (q->stream != NULL) {
		next->stream = q->stream;
		GC_ADDREF(next->stream);
	}
	if (q->table != NULL) {
		next->table = q->table;
		GC_ADDREF(next->table);
		next->store = q->store;
	}
	next->preds = safe_emalloc((size_t)q->npreds + 1, sizeof(bf_pred), 0);
	for (uint32_t i = 0; i < q->npreds; i++) {
		next->preds[i] = q->preds[i];
		if (next->preds[i].owner != NULL) {
			zend_string_addref((zend_string *)next->preds[i].owner);
		}
	}
	next->preds[q->npreds] = *pred;
	next->npreds = q->npreds + 1;
}

/* whereEq, whereNe and the four range predicates (SPEC 5.7). */
void bf_query_where(zend_object *this_obj, zend_execute_data *execute_data, zval *return_value, bf_where_op op)
{
	static const bf_pred_kind int_kinds[] = {BF_P_INT_EQ, BF_P_INT_NE, BF_P_INT_GT, BF_P_INT_GTE, BF_P_INT_LT, BF_P_INT_LTE};
	static const bf_pred_kind float_kinds[] = {BF_P_FLOAT_EQ, BF_P_FLOAT_NE, BF_P_FLOAT_GT, BF_P_FLOAT_GTE, BF_P_FLOAT_LT, BF_P_FLOAT_LTE};
	zend_string *column;
	zval *value;
	const bf_query *q = bf_query_from_obj(this_obj);
	const bool range = op >= BF_W_GT;
	const bf_col *c;
	bf_pred pred;
	char escaped[BF_NAME_ESCAPED_MAX], buf[BF_REASON_MAX];
	const char *reason;
	double d = 0.0;
	int col;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STR(column)
		Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();

	if (range ? (Z_TYPE_P(value) != IS_LONG && Z_TYPE_P(value) != IS_DOUBLE)
		: (Z_TYPE_P(value) == IS_ARRAY || Z_TYPE_P(value) == IS_OBJECT || Z_TYPE_P(value) == IS_RESOURCE)) {
		zend_argument_type_error(2, "must be of type %s, %s given", range ? "int|float" : "string|int|float|bool|null", zend_zval_value_name(value));
		RETURN_THROWS();
	}
	if ((col = bf_where_column(q, column)) < 0) {
		RETURN_THROWS();
	}
	c = &q->plan->cols[col];
	bf_escape_name(escaped, c->name, c->name_len);
	if (range && (c->type == BF_BOOL || c->type == BF_STRING)) {
		bf_throw(bf_ce_SchemaException, "column \"%s\": %s requires a numeric column", escaped, bf_where_names[op]);
		RETURN_THROWS();
	}
	memset(&pred, 0, sizeof(pred));
	pred.col = (uint32_t)col;
	if (Z_TYPE_P(value) == IS_NULL) {
		/* Only equality takes null: IS NULL for whereEq, IS NOT NULL for whereNe. */
		pred.kind = op == BF_W_NE ? BF_P_NOT_NULL : BF_P_IS_NULL;
		bf_query_derive(q, &pred, return_value);
		return;
	}
	if ((reason = bf_literal_check(c, value, &d, buf)) != NULL) {
		bf_throw(bf_ce_SchemaException, "column \"%s\": %s", escaped, reason);
		RETURN_THROWS();
	}
	switch (c->type) {
		case BF_INT64:
			pred.kind = int_kinds[op];
			pred.i = Z_LVAL_P(value);
			break;
		case BF_FLOAT64:
			pred.kind = float_kinds[op];
			pred.d = d;
			break;
		case BF_BOOL:
			pred.kind = op == BF_W_NE ? BF_P_BOOL_NE : BF_P_BOOL_EQ;
			pred.b = Z_TYPE_P(value) == IS_TRUE;
			break;
		case BF_STRING: {
			zend_string *copy = zend_string_init(Z_STRVAL_P(value), Z_STRLEN_P(value), 0);

			pred.kind = op == BF_W_NE ? BF_P_STR_NE : BF_P_STR_EQ;
			pred.owner = copy;
			pred.s = ZSTR_VAL(copy);
			pred.n = ZSTR_LEN(copy);
			break;
		}
	}
	bf_query_derive(q, &pred, return_value);
}

ZEND_METHOD(BatchFlow_Query, whereEq)
{
	bf_query_where(Z_OBJ_P(ZEND_THIS), execute_data, return_value, BF_W_EQ);
}

ZEND_METHOD(BatchFlow_Query, whereNe)
{
	bf_query_where(Z_OBJ_P(ZEND_THIS), execute_data, return_value, BF_W_NE);
}

ZEND_METHOD(BatchFlow_Query, whereGt)
{
	bf_query_where(Z_OBJ_P(ZEND_THIS), execute_data, return_value, BF_W_GT);
}

ZEND_METHOD(BatchFlow_Query, whereGte)
{
	bf_query_where(Z_OBJ_P(ZEND_THIS), execute_data, return_value, BF_W_GTE);
}

ZEND_METHOD(BatchFlow_Query, whereLt)
{
	bf_query_where(Z_OBJ_P(ZEND_THIS), execute_data, return_value, BF_W_LT);
}

ZEND_METHOD(BatchFlow_Query, whereLte)
{
	bf_query_where(Z_OBJ_P(ZEND_THIS), execute_data, return_value, BF_W_LTE);
}

/* ---- whereIn (SPEC 5.7) ---- */

#define BF_MAX_IN 1024

static int bf_cmp_int64(const void *a, const void *b)
{
	const int64_t x = *(const int64_t *)a, y = *(const int64_t *)b;

	return (x > y) - (x < y);
}

/* IEEE order over finite doubles, -0.0 already stored as 0.0. */
static int bf_cmp_double(const void *a, const void *b)
{
	const double x = *(const double *)a, y = *(const double *)b;

	return (x > y) - (x < y);
}

/* The order bf_in_str searches in: the shorter string first, then bytes. */
static int bf_cmp_zstr(const void *a, const void *b)
{
	const zend_string *x = *(zend_string *const *)a, *y = *(zend_string *const *)b;

	if (ZSTR_LEN(x) != ZSTR_LEN(y)) {
		return ZSTR_LEN(x) < ZSTR_LEN(y) ? -1 : 1;
	}
	return memcmp(ZSTR_VAL(x), ZSTR_VAL(y), ZSTR_LEN(x));
}

/* Sorts n fixed-width elements and drops repeats; returns the distinct count. */
static size_t bf_sort_unique(void *base, size_t n, size_t width, int (*cmp)(const void *, const void *))
{
	char *p = base;
	size_t k = 0;

	if (n == 0) {
		return 0;
	}
	qsort(base, n, width, cmp);
	for (size_t i = 1; i < n; i++) {
		if (cmp(p + k * width, p + i * width) != 0) {
			k++;
			if (k != i) {
				memcpy(p + k * width, p + i * width, width);
			}
		}
	}
	return k + 1;
}

/*
 * The sorted distinct set of a validated list, as a refcounted blob owned by the predicate. String elements
 * are copied after the mem_fits preflight of their distinct byte total. false with an exception set.
 */
static bool bf_in_build(const bf_col *c, HashTable *values, bf_pred *pred)
{
	const uint32_t count = zend_hash_num_elements(values);
	zval *v;
	size_t n = 0;

	if (c->type == BF_BOOL) {
		ZEND_HASH_FOREACH_VAL(values, v) {
			ZVAL_DEREF(v);
			if (Z_TYPE_P(v) == IS_NULL) {
				pred->b = true;
			} else {
				pred->i |= Z_TYPE_P(v) == IS_TRUE ? 2 : 1;
			}
		} ZEND_HASH_FOREACH_END();
		pred->kind = BF_P_BOOL_IN;
		return true;
	}
	if (c->type == BF_STRING) {
		zend_string **elems = safe_emalloc(count, sizeof(zend_string *), 0);
		size_t total = 0;
		zend_string *blob;
		uint64_t *off;
		char *bytes;

		ZEND_HASH_FOREACH_VAL(values, v) {
			ZVAL_DEREF(v);
			if (Z_TYPE_P(v) == IS_NULL) {
				pred->b = true;
			} else {
				elems[n++] = Z_STR_P(v);
			}
		} ZEND_HASH_FOREACH_END();
		n = bf_sort_unique(elems, n, sizeof(zend_string *), bf_cmp_zstr);
		for (size_t k = 0; k < n; k++) {
			total += ZSTR_LEN(elems[k]);
		}
		/* At most 1024 elements of at most 1 MiB each: the total cannot overflow. No prefix (SPEC 5.11). */
		if (!bf_host_mem_fits(NULL, total)) {
			efree(elems);
			zend_throw_exception(bf_ce_LimitException, "memory_limit would be exceeded", 0);
			return false;
		}
		blob = zend_string_alloc((n + 1) * sizeof(uint64_t) + total, 0);
		off = (uint64_t *)ZSTR_VAL(blob);
		bytes = (char *)(off + n + 1);
		off[0] = 0;
		for (size_t k = 0; k < n; k++) {
			memcpy(bytes + off[k], ZSTR_VAL(elems[k]), ZSTR_LEN(elems[k]));
			off[k + 1] = off[k] + ZSTR_LEN(elems[k]);
		}
		efree(elems);
		pred->kind = BF_P_STR_IN;
		pred->owner = blob;
		pred->set = off;
		pred->n = n;
		return true;
	}
	{
		/* int64 and float64 elements are 8 bytes each; the list is at most 1024 long. */
		zend_string *blob = zend_string_alloc((size_t)count * 8, 0);

		ZEND_HASH_FOREACH_VAL(values, v) {
			ZVAL_DEREF(v);
			if (Z_TYPE_P(v) == IS_NULL) {
				pred->b = true;
			} else if (c->type == BF_INT64) {
				((int64_t *)ZSTR_VAL(blob))[n++] = (int64_t)Z_LVAL_P(v);
			} else {
				double d = Z_TYPE_P(v) == IS_DOUBLE ? Z_DVAL_P(v) : (double)Z_LVAL_P(v);

				/* -0.0 and 0.0 are one value. */
				((double *)ZSTR_VAL(blob))[n++] = d == 0.0 ? 0.0 : d;
			}
		} ZEND_HASH_FOREACH_END();
		n = bf_sort_unique(ZSTR_VAL(blob), n, 8, c->type == BF_INT64 ? bf_cmp_int64 : bf_cmp_double);
		pred->kind = c->type == BF_INT64 ? BF_P_INT_IN : BF_P_FLOAT_IN;
		pred->owner = blob;
		pred->set = ZSTR_VAL(blob);
		pred->n = n;
		return true;
	}
}

void bf_query_where_in(zend_object *this_obj, zend_execute_data *execute_data, zval *return_value)
{
	zend_string *column;
	HashTable *values;
	const bf_query *q = bf_query_from_obj(this_obj);
	const bf_col *c;
	bf_pred pred;
	char escaped[BF_NAME_ESCAPED_MAX], buf[BF_REASON_MAX];
	uint32_t idx = 0;
	zval *v;
	int col;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STR(column)
		Z_PARAM_ARRAY_HT(values)
	ZEND_PARSE_PARAMETERS_END();

	/* (1) shape, (2) element types: both before any schema check. */
	if (zend_hash_num_elements(values) == 0) {
		zend_argument_value_error(2, "must not be empty");
		RETURN_THROWS();
	}
	if (!zend_array_is_list(values)) {
		zend_argument_value_error(2, "must be a list");
		RETURN_THROWS();
	}
	if (zend_hash_num_elements(values) > BF_MAX_IN) {
		zend_argument_value_error(2, "must contain at most 1024 values");
		RETURN_THROWS();
	}
	ZEND_HASH_FOREACH_VAL(values, v) {
		ZVAL_DEREF(v);
		if (Z_TYPE_P(v) == IS_ARRAY || Z_TYPE_P(v) == IS_OBJECT || Z_TYPE_P(v) == IS_RESOURCE) {
			zend_argument_type_error(2, "element %" PRIu32 " must be of type string|int|float|bool|null, %s given", idx, zend_zval_value_name(v));
			RETURN_THROWS();
		}
		idx++;
	} ZEND_HASH_FOREACH_END();
	/* (3) predicate count and column, (4) each element against the column. */
	if ((col = bf_where_column(q, column)) < 0) {
		RETURN_THROWS();
	}
	c = &q->plan->cols[col];
	bf_escape_name(escaped, c->name, c->name_len);
	idx = 0;
	ZEND_HASH_FOREACH_VAL(values, v) {
		const char *reason;
		double d;

		ZVAL_DEREF(v);
		if (Z_TYPE_P(v) != IS_NULL && (reason = bf_literal_check(c, v, &d, buf)) != NULL) {
			bf_throw(bf_ce_SchemaException, "column \"%s\": whereIn element %" PRIu32 ": %s", escaped, idx, reason);
			RETURN_THROWS();
		}
		idx++;
	} ZEND_HASH_FOREACH_END();
	/* (5) the set, with its memory preflight. */
	memset(&pred, 0, sizeof(pred));
	pred.col = (uint32_t)col;
	if (!bf_in_build(c, values, &pred)) {
		RETURN_THROWS();
	}
	bf_query_derive(q, &pred, return_value);
}

ZEND_METHOD(BatchFlow_Query, whereIn)
{
	bf_query_where_in(Z_OBJ_P(ZEND_THIS), execute_data, return_value);
}

/* ---- terminals ---- */

/* The numeric column named by sum() or summary(), or -1 with an exception set. */
static int bf_resolve_sum_column(const bf_plan *plan, zend_string *column)
{
	char escaped[BF_NAME_ESCAPED_MAX];
	int col = bf_plan_find(plan, ZSTR_VAL(column), ZSTR_LEN(column));

	if (col < 0) {
		bf_escape_name(escaped, ZSTR_VAL(column), ZSTR_LEN(column));
		bf_throw(bf_ce_SchemaException, "unknown column \"%s\"", escaped);
		return -1;
	}
	if (plan->cols[col].type != BF_INT64 && plan->cols[col].type != BF_FLOAT64) {
		bf_escape_name(escaped, plan->cols[col].name, plan->cols[col].name_len);
		bf_throw(bf_ce_SchemaException, "column \"%s\": sum requires a numeric column", escaped);
		return -1;
	}
	return col;
}

ZEND_METHOD(BatchFlow_Query, count)
{
	bf_scan_result result;

	ZEND_PARSE_PARAMETERS_NONE();

	if (!bf_execute_scan(bf_query_from_obj(Z_OBJ_P(ZEND_THIS)), -1, &result)) {
		RETURN_THROWS();
	}
	RETURN_LONG((zend_long)result.matched);
}

ZEND_METHOD(BatchFlow_Query, sum)
{
	zend_string *column;
	const bf_query *q = bf_query_from_obj(Z_OBJ_P(ZEND_THIS));
	bf_scan_result result;
	int col;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(column)
	ZEND_PARSE_PARAMETERS_END();

	col = bf_resolve_sum_column(q->plan, column);
	if (col < 0 || !bf_execute_scan(q, col, &result)) {
		RETURN_THROWS();
	}
	if (q->plan->cols[col].type == BF_INT64) {
		RETURN_LONG((zend_long)result.isum);
	}
	RETURN_DOUBLE(result.fsum);
}

ZEND_METHOD(BatchFlow_Query, summary)
{
	zend_string *column = NULL;
	const bf_query *q = bf_query_from_obj(Z_OBJ_P(ZEND_THIS));
	bf_scan_result result;
	int col = -1;
	zend_object *summary;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_STR_OR_NULL(column)
	ZEND_PARSE_PARAMETERS_END();

	if (column != NULL) {
		col = bf_resolve_sum_column(q->plan, column);
		if (col < 0) {
			RETURN_THROWS();
		}
	}
	if (!bf_execute_scan(q, col, &result)) {
		RETURN_THROWS();
	}
	object_init_ex(return_value, bf_ce_Summary);
	summary = Z_OBJ_P(return_value);
	zend_update_property_long(bf_ce_Summary, summary, ZEND_STRL("rowsScanned"), (zend_long)result.scanned);
	zend_update_property_long(bf_ce_Summary, summary, ZEND_STRL("rowsMatched"), (zend_long)result.matched);
	if (col < 0) {
		zend_update_property_null(bf_ce_Summary, summary, ZEND_STRL("sum"));
	} else if (q->plan->cols[col].type == BF_INT64) {
		zend_update_property_long(bf_ce_Summary, summary, ZEND_STRL("sum"), (zend_long)result.isum);
	} else {
		zend_update_property_double(bf_ce_Summary, summary, ZEND_STRL("sum"), result.fsum);
	}
}

/* The group key column, string or int64 (SPEC 5.9), or -1 with an exception set. */
static int bf_resolve_group_column(const bf_plan *plan, zend_string *column)
{
	char escaped[BF_NAME_ESCAPED_MAX];
	int col = bf_plan_find(plan, ZSTR_VAL(column), ZSTR_LEN(column));

	if (col < 0) {
		bf_escape_name(escaped, ZSTR_VAL(column), ZSTR_LEN(column));
		bf_throw(bf_ce_SchemaException, "unknown column \"%s\"", escaped);
		return -1;
	}
	if (plan->cols[col].type != BF_STRING && plan->cols[col].type != BF_INT64) {
		bf_escape_name(escaped, plan->cols[col].name, plan->cols[col].name_len);
		bf_throw(bf_ce_SchemaException, "column \"%s\": group key must be string or int64", escaped);
		return -1;
	}
	return col;
}

/* maxGroups range (SPEC 5.9), as argument arg. */
static bool bf_check_max_groups(zend_long max_groups, uint32_t arg)
{
	if (max_groups < 1 || max_groups > 100000000) {
		zend_argument_value_error(arg, "must be between 1 and 100000000");
		return false;
	}
	return true;
}

ZEND_METHOD(BatchFlow_Query, groupCount)
{
	zend_string *key;
	zend_long max_groups = 1000000;
	const bf_query *q = bf_query_from_obj(Z_OBJ_P(ZEND_THIS));
	int col;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_STR(key)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(max_groups)
	ZEND_PARSE_PARAMETERS_END();

	if (!bf_check_max_groups(max_groups, 2) || (col = bf_resolve_group_column(q->plan, key)) < 0
		|| !bf_execute_group(q, col, -1, (uint32_t)max_groups, return_value)) {
		RETURN_THROWS();
	}
}

ZEND_METHOD(BatchFlow_Query, groupSum)
{
	zend_string *key, *sum;
	zend_long max_groups = 1000000;
	const bf_query *q = bf_query_from_obj(Z_OBJ_P(ZEND_THIS));
	int col, sum_col;

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_STR(key)
		Z_PARAM_STR(sum)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(max_groups)
	ZEND_PARSE_PARAMETERS_END();

	if (!bf_check_max_groups(max_groups, 3) || (col = bf_resolve_group_column(q->plan, key)) < 0
		|| (sum_col = bf_resolve_sum_column(q->plan, sum)) < 0
		|| !bf_execute_group(q, col, sum_col, (uint32_t)max_groups, return_value)) {
		RETURN_THROWS();
	}
}

/* ---- aggregates (SPEC 5.8) and groupAgg (SPEC 5.9) ---- */

static const char *const bf_agg_names[] = {"count", "sum", "min", "max", "avg"};

/*
 * Step 1 of the aggregate checks, as argument arg: list shape, then each pair in list order, its shape before
 * its op. An empty list is accepted only when allow_empty (groupAgg). false with a ValueError set.
 */
static bool bf_aggs_shape(HashTable *list, uint32_t arg, bool allow_empty, bf_agg *aggs, zend_string **cols, uint32_t *n)
{
	const uint32_t count = zend_hash_num_elements(list);
	uint32_t idx = 0;
	zval *pair;

	*n = 0;
	if (count == 0) {
		if (!allow_empty) {
			zend_argument_value_error(arg, "must not be empty");
			return false;
		}
		return true;
	}
	if (!zend_array_is_list(list)) {
		zend_argument_value_error(arg, "must be a list");
		return false;
	}
	if (count > BF_MAX_AGGS) {
		zend_argument_value_error(arg, "must contain at most 16 aggregates");
		return false;
	}
	ZEND_HASH_FOREACH_VAL(list, pair) {
		zval *op = NULL, *col = NULL;
		uint32_t k;

		ZVAL_DEREF(pair);
		if (Z_TYPE_P(pair) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(pair)) == 2 && zend_array_is_list(Z_ARRVAL_P(pair))) {
			op = zend_hash_index_find_deref(Z_ARRVAL_P(pair), 0);
			col = zend_hash_index_find_deref(Z_ARRVAL_P(pair), 1);
		}
		if (op == NULL || col == NULL || Z_TYPE_P(op) != IS_STRING || Z_TYPE_P(col) != IS_STRING) {
			zend_argument_value_error(arg, "element %" PRIu32 " must be a list of two strings", idx);
			return false;
		}
		for (k = 0; k < sizeof(bf_agg_names) / sizeof(bf_agg_names[0]); k++) {
			if (zend_string_equals_cstr(Z_STR_P(op), bf_agg_names[k], strlen(bf_agg_names[k]))) {
				break;
			}
		}
		if (k == sizeof(bf_agg_names) / sizeof(bf_agg_names[0])) {
			zend_argument_value_error(arg, "element %" PRIu32 ": aggregate must be one of count, sum, min, max, avg", idx);
			return false;
		}
		aggs[idx].op = (bf_agg_op)k;
		cols[idx] = Z_STR_P(col);
		idx++;
	} ZEND_HASH_FOREACH_END();
	*n = count;
	return true;
}

/* The column of one pair: unknown column, then a numeric column for every op but count. -1 with an exception set. */
static int bf_agg_column(const bf_plan *plan, bf_agg_op op, zend_string *column)
{
	char escaped[BF_NAME_ESCAPED_MAX];
	int col = bf_plan_find(plan, ZSTR_VAL(column), ZSTR_LEN(column));

	if (col < 0) {
		bf_escape_name(escaped, ZSTR_VAL(column), ZSTR_LEN(column));
		bf_throw(bf_ce_SchemaException, "unknown column \"%s\"", escaped);
		return -1;
	}
	if (op != BF_AGG_COUNT && plan->cols[col].type != BF_INT64 && plan->cols[col].type != BF_FLOAT64) {
		bf_escape_name(escaped, plan->cols[col].name, plan->cols[col].name_len);
		bf_throw(bf_ce_SchemaException, "column \"%s\": %s requires a numeric column", escaped, bf_agg_names[op]);
		return -1;
	}
	return col;
}

/* Step 2: each pair's column in list order. */
static bool bf_aggs_columns(const bf_plan *plan, bf_agg *aggs, zend_string *const *cols, uint32_t n)
{
	for (uint32_t k = 0; k < n; k++) {
		int col = bf_agg_column(plan, aggs[k].op, cols[k]);

		if (col < 0) {
			return false;
		}
		aggs[k].col = (uint32_t)col;
	}
	return true;
}

/* min, max and avg: one pair through the aggregate run, its single result returned. */
static void bf_query_single_agg(zend_execute_data *execute_data, zval *return_value, bf_agg_op op)
{
	zend_string *column;
	const bf_query *q = bf_query_from_obj(Z_OBJ_P(ZEND_THIS));
	bf_agg agg = { .op = op };
	zval list, *first;
	int col;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(column)
	ZEND_PARSE_PARAMETERS_END();

	if ((col = bf_agg_column(q->plan, op, column)) < 0) {
		RETURN_THROWS();
	}
	agg.col = (uint32_t)col;
	if (!bf_execute_aggregate(q, &agg, 1, &list)) {
		RETURN_THROWS();
	}
	first = zend_hash_index_find(Z_ARRVAL(list), 0);
	ZVAL_COPY(return_value, first);
	zval_ptr_dtor(&list);
}

ZEND_METHOD(BatchFlow_Query, min)
{
	bf_query_single_agg(execute_data, return_value, BF_AGG_MIN);
}

ZEND_METHOD(BatchFlow_Query, max)
{
	bf_query_single_agg(execute_data, return_value, BF_AGG_MAX);
}

ZEND_METHOD(BatchFlow_Query, avg)
{
	bf_query_single_agg(execute_data, return_value, BF_AGG_AVG);
}

ZEND_METHOD(BatchFlow_Query, aggregate)
{
	HashTable *list;
	const bf_query *q = bf_query_from_obj(Z_OBJ_P(ZEND_THIS));
	bf_agg aggs[BF_MAX_AGGS];
	zend_string *cols[BF_MAX_AGGS];
	uint32_t n;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY_HT(list)
	ZEND_PARSE_PARAMETERS_END();

	if (!bf_aggs_shape(list, 1, false, aggs, cols, &n) || !bf_aggs_columns(q->plan, aggs, cols, n)
		|| !bf_execute_aggregate(q, aggs, n, return_value)) {
		RETURN_THROWS();
	}
}

/* Step 1 of the groupAgg checks: $keys as an array, each element in list order, its type before repeats. */
static bool bf_keys_shape(HashTable *keys, zend_string **names, uint32_t *n)
{
	const uint32_t count = zend_hash_num_elements(keys);
	uint32_t idx = 0;
	zval *v;

	if (count == 0) {
		zend_argument_value_error(1, "must not be empty");
		return false;
	}
	if (!zend_array_is_list(keys)) {
		zend_argument_value_error(1, "must be a list");
		return false;
	}
	if (count > BF_MAX_GROUP_KEYS) {
		zend_argument_value_error(1, "must contain at most 4 key columns");
		return false;
	}
	ZEND_HASH_FOREACH_VAL(keys, v) {
		ZVAL_DEREF(v);
		if (Z_TYPE_P(v) != IS_STRING) {
			zend_argument_value_error(1, "element %" PRIu32 " must be a string", idx);
			return false;
		}
		for (uint32_t k = 0; k < idx; k++) {
			if (zend_string_equals(names[k], Z_STR_P(v))) {
				zend_argument_value_error(1, "must not contain a column twice");
				return false;
			}
		}
		names[idx++] = Z_STR_P(v);
	} ZEND_HASH_FOREACH_END();
	*n = count;
	return true;
}

ZEND_METHOD(BatchFlow_Query, groupAgg)
{
	HashTable *keys_ht = NULL, *list;
	zend_string *keys_str = NULL;
	zend_long max_groups = 1000000;
	const bf_query *q = bf_query_from_obj(Z_OBJ_P(ZEND_THIS));
	zend_string *names[BF_MAX_GROUP_KEYS], *cols[BF_MAX_AGGS];
	uint32_t key_cols[BF_MAX_GROUP_KEYS], nkeys = 1, naggs;
	bf_agg aggs[BF_MAX_AGGS];

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_ARRAY_HT_OR_STR(keys_ht, keys_str)
		Z_PARAM_ARRAY_HT(list)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(max_groups)
	ZEND_PARSE_PARAMETERS_END();

	if (keys_ht == NULL) {
		names[0] = keys_str;
	} else if (!bf_keys_shape(keys_ht, names, &nkeys)) {
		RETURN_THROWS();
	}
	if (!bf_aggs_shape(list, 2, true, aggs, cols, &naggs) || !bf_check_max_groups(max_groups, 3)) {
		RETURN_THROWS();
	}
	for (uint32_t k = 0; k < nkeys; k++) {
		int col = bf_resolve_group_column(q->plan, names[k]);

		if (col < 0) {
			RETURN_THROWS();
		}
		key_cols[k] = (uint32_t)col;
	}
	if (!bf_aggs_columns(q->plan, aggs, cols, naggs)
		|| !bf_execute_group_agg(q, key_cols, nkeys, aggs, naggs, (uint32_t)max_groups, return_value)) {
		RETURN_THROWS();
	}
}

/* ---- rows (SPEC 5.10) ---- */

ZEND_METHOD(BatchFlow_Query, toArray)
{
	zend_long max_rows = 10000;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(max_rows)
	ZEND_PARSE_PARAMETERS_END();

	if (max_rows < 1 || max_rows > 100000000) {
		zend_argument_value_error(1, "must be between 1 and 100000000");
		RETURN_THROWS();
	}
	if (!bf_execute_rows(bf_query_from_obj(Z_OBJ_P(ZEND_THIS)), (uint64_t)max_rows, return_value)) {
		RETURN_THROWS();
	}
}
