#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "ext/spl/spl_exceptions.h"
#include "ext/standard/info.h"

#include "php_batchflow.h"
#include "batchflow_arginfo.h"

zend_class_entry *bf_ce_Csv;
zend_class_entry *bf_ce_Table;
zend_class_entry *bf_ce_Query;
zend_class_entry *bf_ce_Summary;
zend_class_entry *bf_ce_Runtime;

static zend_object_handlers bf_no_clone_handlers;

ZEND_METHOD(BatchFlow_Csv, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(BatchFlow_Table, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(BatchFlow_Query, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(BatchFlow_Summary, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(BatchFlow_Runtime, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(BatchFlow_Runtime, info)
{
	zval simd, compiled;

	ZEND_PARSE_PARAMETERS_NONE();

	array_init(&compiled);
	for (const char *const *name = bf_masks_compiled; *name != NULL; name++) {
		add_next_index_string(&compiled, *name);
	}
	array_init(&simd);
	add_assoc_zval(&simd, "compiled", &compiled);
	add_assoc_string(&simd, "auto", bf_masks_auto_name);
	array_init(return_value);
	add_assoc_string(return_value, "version", PHP_BATCHFLOW_VERSION);
	add_assoc_zval(return_value, "simd", &simd);
}

static PHP_MINIT_FUNCTION(batchflow)
{
	(void)type;
	(void)module_number;

	bf_masks_init();

	bf_ce_BatchFlowException = register_class_BatchFlow_BatchFlowException(spl_ce_RuntimeException);
	bf_ce_SchemaException = register_class_BatchFlow_SchemaException(bf_ce_BatchFlowException);
	bf_ce_CsvException = register_class_BatchFlow_CsvException(bf_ce_BatchFlowException);
	bf_ce_LimitException = register_class_BatchFlow_LimitException(bf_ce_BatchFlowException);
	bf_ce_ResultTooLargeException = register_class_BatchFlow_ResultTooLargeException(bf_ce_LimitException);
	bf_ce_SumOverflowException = register_class_BatchFlow_SumOverflowException(bf_ce_BatchFlowException);
	bf_ce_StreamException = register_class_BatchFlow_StreamException(bf_ce_BatchFlowException);

	bf_ce_Csv = register_class_BatchFlow_Csv();
	bf_ce_Table = register_class_BatchFlow_Table();
	bf_ce_Query = register_class_BatchFlow_Query();
	bf_ce_Summary = register_class_BatchFlow_Summary();
	bf_ce_Runtime = register_class_BatchFlow_Runtime();
	bf_ce_Cursor = register_class_BatchFlow_Cursor();

	/* Csv and Runtime have no native state, only private constructors and clone refused. */
	memcpy(&bf_no_clone_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	bf_no_clone_handlers.clone_obj = NULL;
	bf_ce_Csv->default_object_handlers = &bf_no_clone_handlers;
	bf_ce_Runtime->default_object_handlers = &bf_no_clone_handlers;

	bf_query_minit();
	bf_table_minit();
	bf_cursor_minit();

	return SUCCESS;
}

static PHP_MINFO_FUNCTION(batchflow)
{
	char compiled[32] = "";

	(void)zend_module;

	for (const char *const *name = bf_masks_compiled; *name != NULL; name++) {
		strlcat(compiled, *name, sizeof(compiled));
		if (name[1] != NULL) {
			strlcat(compiled, ", ", sizeof(compiled));
		}
	}

	php_info_print_table_start();
	php_info_print_table_row(2, "batchflow support", "enabled");
	php_info_print_table_row(2, "version", PHP_BATCHFLOW_VERSION);
	php_info_print_table_row(2, "simd compiled", compiled);
	php_info_print_table_row(2, "simd auto", bf_masks_auto_name);
	php_info_print_table_end();
}

zend_module_entry batchflow_module_entry = {
	STANDARD_MODULE_HEADER,
	"batchflow",
	NULL,
	PHP_MINIT(batchflow),
	NULL,
	NULL,
	NULL,
	PHP_MINFO(batchflow),
	PHP_BATCHFLOW_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_BATCHFLOW
ZEND_GET_MODULE(batchflow)
#endif
