#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"

#include "php_batchflow.h"

/* Cursor (SPEC 5.13, 6.8): the object and its state checks; the run itself lives in php_run.c. */

zend_class_entry *bf_ce_Cursor;

static zend_object_handlers bf_cursor_handlers;

static zend_object *bf_cursor_create(zend_class_entry *ce)
{
	bf_cursor *c = zend_object_alloc(sizeof(bf_cursor), ce);

	zend_object_std_init(&c->std, ce);
	object_properties_init(&c->std, ce);
	c->std.handlers = &bf_cursor_handlers;
	return &c->std;
}

/* Dropping the last reference to an open cursor releases its run as close() would. */
static void bf_cursor_free(zend_object *obj)
{
	bf_cursor *c = bf_cursor_from_obj(obj);

	/* Only a fiber suspended inside a fetch keeps fetching set here, at request shutdown: its run is left to ZendMM. */
	if (!c->fetching) {
		bf_cursor_release(c);
	}
	if (c->query != NULL) {
		OBJ_RELEASE(c->query);
	}
	zend_object_std_dtor(obj);
}

void bf_cursor_minit(void)
{
	memcpy(&bf_cursor_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	bf_cursor_handlers.offset = offsetof(bf_cursor, std);
	bf_cursor_handlers.free_obj = bf_cursor_free;
	bf_cursor_handlers.clone_obj = NULL;
	bf_ce_Cursor->create_object = bf_cursor_create;
	bf_ce_Cursor->default_object_handlers = &bf_cursor_handlers;
}

ZEND_METHOD(BatchFlow_Query, cursor)
{
	bf_cursor *c;

	ZEND_PARSE_PARAMETERS_NONE();

	/* No I/O and no checks: like toArray, a cursor references every schema column. */
	object_init_ex(return_value, bf_ce_Cursor);
	c = bf_cursor_from_obj(Z_OBJ_P(return_value));
	c->query = Z_OBJ_P(ZEND_THIS);
	GC_ADDREF(c->query);
}

ZEND_METHOD(BatchFlow_Cursor, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

static bool bf_cursor_not_running(const bf_cursor *c)
{
	if (c->fetching) {
		zend_throw_exception(bf_ce_BatchFlowException, "a fetch on this cursor is already running", 0);
		return false;
	}
	return true;
}

/* Order: $maxRows range, then the running check, then the state. */
ZEND_METHOD(BatchFlow_Cursor, fetch)
{
	zend_long max_rows = 1000;
	bf_cursor *c = bf_cursor_from_obj(Z_OBJ_P(ZEND_THIS));

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(max_rows)
	ZEND_PARSE_PARAMETERS_END();

	if (max_rows < 1 || max_rows > 1000000) {
		zend_argument_value_error(1, "must be between 1 and 1000000");
		RETURN_THROWS();
	}
	if (!bf_cursor_not_running(c)) {
		RETURN_THROWS();
	}
	switch (c->state) {
		case BF_CURSOR_CLOSED:
			zend_throw_exception(bf_ce_BatchFlowException, "cursor is closed", 0);
			RETURN_THROWS();
		case BF_CURSOR_EXHAUSTED:
			RETURN_EMPTY_ARRAY();
		case BF_CURSOR_NEW:
		case BF_CURSOR_OPEN:
			if (!bf_cursor_fetch(c, (uint64_t)max_rows, return_value)) {
				RETURN_THROWS();
			}
			return;
	}
}

ZEND_METHOD(BatchFlow_Cursor, close)
{
	bf_cursor *c = bf_cursor_from_obj(Z_OBJ_P(ZEND_THIS));

	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();

	if (bf_cursor_not_running(c)) {
		bf_cursor_release(c);
	}
}
