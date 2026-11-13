#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "ext/standard/file.h"

#include "php_batchflow.h"

/* The column store behind Table. */
/* A Table (SPEC 5.1, 5.12, 6.6): its plan and column store, immutable once loaded. */
typedef struct {
	bf_plan *plan;          /* NULL until loaded */
	bf_table store;
	zend_object std;
} bf_table_obj;

static zend_object_handlers bf_table_handlers;

static inline bf_table_obj *bf_table_from_obj(zend_object *obj)
{
	return (bf_table_obj *)((char *)obj - offsetof(bf_table_obj, std));
}

static zend_object *bf_table_create(zend_class_entry *ce)
{
	bf_table_obj *t = zend_object_alloc(sizeof(bf_table_obj), ce);

	memset(&t->store, 0, sizeof(t->store));
	t->plan = NULL;
	zend_object_std_init(&t->std, ce);
	object_properties_init(&t->std, ce);
	t->std.handlers = &bf_table_handlers;
	return &t->std;
}

static void bf_table_free_obj(zend_object *obj)
{
	bf_table_obj *t = bf_table_from_obj(obj);

	if (t->plan != NULL) {
		bf_table_free(&t->store);
		bf_plan_release(t->plan);
	}
	zend_object_std_dtor(&t->std);
}

void bf_table_minit(void)
{
	memcpy(&bf_table_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	bf_table_handlers.offset = offsetof(bf_table_obj, std);
	bf_table_handlers.free_obj = bf_table_free_obj;
	bf_table_handlers.clone_obj = NULL;
	bf_ce_Table->create_object = bf_table_create;
	bf_ce_Table->default_object_handlers = &bf_table_handlers;
}

/* Compiles the plan and loads the whole source eagerly; no Table exists unless the load succeeded (SPEC 5.1). */
static void bf_table_load(zval *return_value, HashTable *schema, HashTable *options, zend_string *path,
	zend_resource *stream)
{
	bf_plan *plan = bf_plan_compile(schema, options, true);
	bf_query src;
	bf_table store;

	if (plan == NULL) {
		return;
	}
	memset(&src, 0, sizeof(src));
	memset(&store, 0, sizeof(store));
	src.plan = plan;
	src.path = path;
	src.stream = stream;
	if (!bf_execute_load(&src, &store)) {
		bf_plan_release(plan);
		return;
	}
	object_init_ex(return_value, bf_ce_Table);
	bf_table_from_obj(Z_OBJ_P(return_value))->store = store;
	bf_table_from_obj(Z_OBJ_P(return_value))->plan = plan;
}

ZEND_METHOD(BatchFlow_Table, fromCsv)
{
	zend_string *path;
	HashTable *schema, *options = NULL;

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
	bf_table_load(return_value, schema, options, path, NULL);
}

ZEND_METHOD(BatchFlow_Table, fromStream)
{
	zval *zstream;
	php_stream *stream;
	HashTable *schema, *options = NULL;

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_ZVAL(zstream)
		Z_PARAM_ARRAY_HT(schema)
		Z_PARAM_OPTIONAL
		Z_PARAM_ARRAY_HT(options)
	ZEND_PARSE_PARAMETERS_END();

	php_stream_from_zval(stream, zstream);
	bf_table_load(return_value, schema, options, NULL, stream->res);
}

ZEND_METHOD(BatchFlow_Table, rowCount)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG((zend_long)bf_table_from_obj(Z_OBJ_P(ZEND_THIS))->store.rows);
}

ZEND_METHOD(BatchFlow_Table, memoryBytes)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG((zend_long)bf_table_from_obj(Z_OBJ_P(ZEND_THIS))->store.bytes);
}

static const char *const bf_type_names[] = { "int64", "float64", "bool", "string" };

ZEND_METHOD(BatchFlow_Table, columnNames)
{
	const bf_plan *plan = bf_table_from_obj(Z_OBJ_P(ZEND_THIS))->plan;

	ZEND_PARSE_PARAMETERS_NONE();
	array_init_size(return_value, plan->ncols);
	for (uint32_t c = 0; c < plan->ncols; c++) {
		add_next_index_stringl(return_value, plan->cols[c].name, plan->cols[c].name_len);
	}
}

ZEND_METHOD(BatchFlow_Table, schema)
{
	const bf_plan *plan = bf_table_from_obj(Z_OBJ_P(ZEND_THIS))->plan;

	ZEND_PARSE_PARAMETERS_NONE();
	array_init_size(return_value, plan->ncols);
	for (uint32_t c = 0; c < plan->ncols; c++) {
		zval type;

		ZVAL_STRING(&type, bf_type_names[plan->cols[c].type]);
		/* Integer-like names become int keys, as in the schema array the caller passed (SPEC 5.2). */
		zend_symtable_str_update(Z_ARRVAL_P(return_value), plan->cols[c].name, plan->cols[c].name_len, &type);
	}
}

ZEND_METHOD(BatchFlow_Table, query)
{
	bf_table_obj *t = bf_table_from_obj(Z_OBJ_P(ZEND_THIS));

	ZEND_PARSE_PARAMETERS_NONE();
	bf_query_from_table(return_value, &t->std, t->plan, &t->store);
}

/* The shortcuts are query()->where*(...): argument errors name Table's own method, the frame being checked. */
static void bf_table_where(zend_execute_data *execute_data, zval *return_value, int op)
{
	bf_table_obj *t = bf_table_from_obj(Z_OBJ_P(ZEND_THIS));
	zval base;

	bf_query_from_table(&base, &t->std, t->plan, &t->store);
	if (op < 0) {
		bf_query_where_in(Z_OBJ(base), execute_data, return_value);
	} else {
		bf_query_where(Z_OBJ(base), execute_data, return_value, (bf_where_op)op);
	}
	zval_ptr_dtor(&base);
}

ZEND_METHOD(BatchFlow_Table, whereEq) { bf_table_where(execute_data, return_value, BF_W_EQ); }
ZEND_METHOD(BatchFlow_Table, whereNe) { bf_table_where(execute_data, return_value, BF_W_NE); }
ZEND_METHOD(BatchFlow_Table, whereGt) { bf_table_where(execute_data, return_value, BF_W_GT); }
ZEND_METHOD(BatchFlow_Table, whereGte) { bf_table_where(execute_data, return_value, BF_W_GTE); }
ZEND_METHOD(BatchFlow_Table, whereLt) { bf_table_where(execute_data, return_value, BF_W_LT); }
ZEND_METHOD(BatchFlow_Table, whereLte) { bf_table_where(execute_data, return_value, BF_W_LTE); }
ZEND_METHOD(BatchFlow_Table, whereIn) { bf_table_where(execute_data, return_value, -1); }
