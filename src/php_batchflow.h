#ifndef PHP_BATCHFLOW_H
#define PHP_BATCHFLOW_H

#include <stddef.h>

#include "php.h"

#include "bf_core.h"

#define PHP_BATCHFLOW_VERSION "1.0.0"

extern zend_module_entry batchflow_module_entry;
#define phpext_batchflow_ptr &batchflow_module_entry

extern zend_class_entry *bf_ce_BatchFlowException;
extern zend_class_entry *bf_ce_SchemaException;
extern zend_class_entry *bf_ce_CsvException;
extern zend_class_entry *bf_ce_LimitException;
extern zend_class_entry *bf_ce_ResultTooLargeException;
extern zend_class_entry *bf_ce_SumOverflowException;
extern zend_class_entry *bf_ce_StreamException;

extern zend_class_entry *bf_ce_Csv;
extern zend_class_entry *bf_ce_Table;
extern zend_class_entry *bf_ce_Query;
extern zend_class_entry *bf_ce_Summary;
extern zend_class_entry *bf_ce_Runtime;
extern zend_class_entry *bf_ce_Cursor;

#define BF_NAME_ESCAPED_MAX (64 * 4 + 3 + 1)

/* Writes the SPEC 5.11 escaped form of name, NUL-terminated, into out. */
void bf_escape_name(char out[BF_NAME_ESCAPED_MAX], const char *name, size_t len);
/* Throws ce with a vsprintf-style message. Never takes record bytes. */
void bf_throw(zend_class_entry *ce, const char *fmt, ...) ZEND_ATTRIBUTE_FORMAT(printf, 2, 3);

/* A compiled schema and options; immutable, shared by every Query derived from one call. */
typedef struct {
	uint32_t refcount;
	uint32_t ncols;
	bf_col *cols;
	bool allow_extra;
	size_t max_field_bytes;
	size_t max_row_bytes;
	uint64_t max_input_rows;
	uint64_t max_group_bytes;
	uint64_t max_dictionary_size;
	uint64_t max_table_bytes;
	bool *dict;             /* Table: dict[c] for each dictionary column, NULL when there is none */
	bf_masks_fn masks;
	unsigned char delim;
} bf_plan;

bf_plan *bf_plan_compile(HashTable *schema, HashTable *options, bool is_table);
void bf_plan_addref(bf_plan *plan);
void bf_plan_release(bf_plan *plan);

/* -1 when the schema has no such column. */
int bf_plan_find(const bf_plan *plan, const char *name, size_t len);

/* A Query's source and predicates. Immutable once built. */
typedef struct {
	bf_plan *plan;
	zend_string *path;      /* path source */
	zend_resource *stream;  /* stream source, strong reference */
	bf_pred *preds;         /* owned; string bytes live in each pred's owner */
	uint32_t npreds;
	zend_object *table;     /* Table source, strong reference (SPEC 5.12) */
	const bf_table *store;  /* that table's column store */
	zend_object std;
} bf_query;

static inline bf_query *bf_query_from_obj(zend_object *obj)
{
	return (bf_query *)((char *)obj - offsetof(bf_query, std));
}

/* Scan results, or false with an exception set. sum_col is a schema index or -1. */
typedef struct {
	uint64_t scanned, matched;
	int64_t isum;
	double fsum;
} bf_scan_result;

typedef struct {
	int32_t sum_col;     /* schema index, or -1 */
	int32_t group_col;   /* schema index, or -1 for a scan terminal */
	uint32_t max_groups;
	const bf_agg *aggs;  /* aggregate() and groupAgg() pairs */
	uint32_t naggs;
	const uint32_t *gkeys; /* groupAgg key columns */
	uint32_t ngkeys;
} bf_terminal;

bool bf_execute_scan(const bf_query *q, int32_t sum_col, bf_scan_result *out);
/* groupCount (sum_col -1) and groupSum: out receives the packed group list. */
bool bf_execute_group(const bf_query *q, int32_t key_col, int32_t sum_col, uint32_t max_groups, zval *out);
/* aggregate(): out receives one result per pair, in order. */
bool bf_execute_aggregate(const bf_query *q, const bf_agg *aggs, uint32_t naggs, zval *out);
/* groupAgg(): out receives [key1, ..., keyN, count, agg1, ..., aggM] per group, in first-seen order. */
bool bf_execute_group_agg(const bf_query *q, const uint32_t *keys, uint32_t nkeys, const bf_agg *aggs, uint32_t naggs,
	uint32_t max_groups, zval *out);

/* toArray(): out receives the matching rows in input order, at most max_rows of them (SPEC 5.10). */
bool bf_execute_rows(const bf_query *q, uint64_t max_rows, zval *out);

/* Table::query() and the Table where* shortcuts (SPEC 5.12): a Query over the table, holding a strong reference. */
void bf_query_from_table(zval *return_value, zend_object *table, bf_plan *plan, const bf_table *store);
/* Query::where* and whereIn on this_obj, for the Table shortcuts. */
typedef enum { BF_W_EQ, BF_W_NE, BF_W_GT, BF_W_GTE, BF_W_LT, BF_W_LTE } bf_where_op;
void bf_query_where(zend_object *this_obj, zend_execute_data *execute_data, zval *return_value, bf_where_op op);
void bf_query_where_in(zend_object *this_obj, zend_execute_data *execute_data, zval *return_value);

/* Table::from*: loads every row of q's source into t (SPEC 5.1). false with an exception set and t freed. */
bool bf_execute_load(const bf_query *q, bf_table *t);

/* A Cursor (SPEC 5.13, 6.8): its Query, its state, and the run, NULL unless the cursor is open. */
typedef enum { BF_CURSOR_NEW, BF_CURSOR_OPEN, BF_CURSOR_EXHAUSTED, BF_CURSOR_CLOSED } bf_cursor_state;
typedef struct bf_php_run bf_php_run;

typedef struct {
	zend_object *query;     /* strong reference */
	bf_php_run *run;
	bf_cursor_state state;
	bool fetching;
	zend_object std;
} bf_cursor;

static inline bf_cursor *bf_cursor_from_obj(zend_object *obj)
{
	return (bf_cursor *)((char *)obj - offsetof(bf_cursor, std));
}

/*
 * The next batch of a new or open cursor, at most max_rows rows, into out. The fetch that reaches end of input releases
 * the run and leaves the cursor exhausted; a failed fetch releases it, closes the cursor and returns false with an
 * exception set.
 */
bool bf_cursor_fetch(bf_cursor *c, uint64_t max_rows, zval *out);
/* Marks the cursor closed and releases an open run (SPEC 5.13); a path stream's stream_close may throw. */
void bf_cursor_release(bf_cursor *c);

/* The memory_limit preflight of SPEC 6.4; ud is unused. */
bool bf_host_mem_fits(void *ud, size_t request);

/* Maps a core error to its exception, prefix included. */
void bf_raise_core_error(const bf_error *e, const bf_col *cols);

void bf_query_minit(void);
void bf_table_minit(void);
void bf_cursor_minit(void);

#endif
