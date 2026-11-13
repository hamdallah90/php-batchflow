/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: c67d8968358fba312db5f0d1897e1cfad2bd07f4 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_BatchFlow_Csv___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Csv_scan, 0, 2, BatchFlow\\Query, 0)
	ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, schema, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Csv_scanStream, 0, 2, BatchFlow\\Query, 0)
	ZEND_ARG_INFO(0, stream)
	ZEND_ARG_TYPE_INFO(0, schema, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

#define arginfo_class_BatchFlow_Table___construct arginfo_class_BatchFlow_Csv___construct

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Table_fromCsv, 0, 2, BatchFlow\\Table, 0)
	ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, schema, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Table_fromStream, 0, 2, BatchFlow\\Table, 0)
	ZEND_ARG_INFO(0, stream)
	ZEND_ARG_TYPE_INFO(0, schema, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Table_rowCount, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Table_columnNames, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_BatchFlow_Table_schema arginfo_class_BatchFlow_Table_columnNames

#define arginfo_class_BatchFlow_Table_memoryBytes arginfo_class_BatchFlow_Table_rowCount

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Table_query, 0, 0, BatchFlow\\Query, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Table_whereEq, 0, 2, BatchFlow\\Query, 0)
	ZEND_ARG_TYPE_INFO(0, column, IS_STRING, 0)
	ZEND_ARG_TYPE_MASK(0, value, MAY_BE_LONG|MAY_BE_DOUBLE|MAY_BE_BOOL|MAY_BE_STRING|MAY_BE_NULL, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Table_whereGt, 0, 2, BatchFlow\\Query, 0)
	ZEND_ARG_TYPE_INFO(0, column, IS_STRING, 0)
	ZEND_ARG_TYPE_MASK(0, value, MAY_BE_LONG|MAY_BE_DOUBLE, NULL)
ZEND_END_ARG_INFO()

#define arginfo_class_BatchFlow_Table_whereNe arginfo_class_BatchFlow_Table_whereEq

#define arginfo_class_BatchFlow_Table_whereLt arginfo_class_BatchFlow_Table_whereGt

#define arginfo_class_BatchFlow_Table_whereGte arginfo_class_BatchFlow_Table_whereGt

#define arginfo_class_BatchFlow_Table_whereLte arginfo_class_BatchFlow_Table_whereGt

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Table_whereIn, 0, 2, BatchFlow\\Query, 0)
	ZEND_ARG_TYPE_INFO(0, column, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, values, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_BatchFlow_Query___construct arginfo_class_BatchFlow_Csv___construct

#define arginfo_class_BatchFlow_Query_whereEq arginfo_class_BatchFlow_Table_whereEq

#define arginfo_class_BatchFlow_Query_whereGt arginfo_class_BatchFlow_Table_whereGt

#define arginfo_class_BatchFlow_Query_whereNe arginfo_class_BatchFlow_Table_whereEq

#define arginfo_class_BatchFlow_Query_whereLt arginfo_class_BatchFlow_Table_whereGt

#define arginfo_class_BatchFlow_Query_whereGte arginfo_class_BatchFlow_Table_whereGt

#define arginfo_class_BatchFlow_Query_whereLte arginfo_class_BatchFlow_Table_whereGt

#define arginfo_class_BatchFlow_Query_whereIn arginfo_class_BatchFlow_Table_whereIn

#define arginfo_class_BatchFlow_Query_count arginfo_class_BatchFlow_Table_rowCount

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_class_BatchFlow_Query_sum, 0, 1, MAY_BE_LONG|MAY_BE_DOUBLE)
	ZEND_ARG_TYPE_INFO(0, column, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_class_BatchFlow_Query_min, 0, 1, MAY_BE_LONG|MAY_BE_DOUBLE|MAY_BE_NULL)
	ZEND_ARG_TYPE_INFO(0, column, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_BatchFlow_Query_max arginfo_class_BatchFlow_Query_min

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Query_avg, 0, 1, IS_DOUBLE, 1)
	ZEND_ARG_TYPE_INFO(0, column, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Query_aggregate, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, aggregates, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Query_summary, 0, 0, BatchFlow\\Summary, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, sumColumn, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Query_groupCount, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxGroups, IS_LONG, 0, "1000000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Query_groupSum, 0, 2, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, sum, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxGroups, IS_LONG, 0, "1000000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Query_groupAgg, 0, 2, IS_ARRAY, 0)
	ZEND_ARG_TYPE_MASK(0, keys, MAY_BE_STRING|MAY_BE_ARRAY, NULL)
	ZEND_ARG_TYPE_INFO(0, aggregates, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxGroups, IS_LONG, 0, "1000000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Query_toArray, 0, 0, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxRows, IS_LONG, 0, "10000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_BatchFlow_Query_cursor, 0, 0, BatchFlow\\Cursor, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_BatchFlow_Cursor___construct arginfo_class_BatchFlow_Csv___construct

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Cursor_fetch, 0, 0, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxRows, IS_LONG, 0, "1000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_BatchFlow_Cursor_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_BatchFlow_Summary___construct arginfo_class_BatchFlow_Csv___construct

#define arginfo_class_BatchFlow_Runtime___construct arginfo_class_BatchFlow_Csv___construct

#define arginfo_class_BatchFlow_Runtime_info arginfo_class_BatchFlow_Table_columnNames


ZEND_METHOD(BatchFlow_Csv, __construct);
ZEND_METHOD(BatchFlow_Csv, scan);
ZEND_METHOD(BatchFlow_Csv, scanStream);
ZEND_METHOD(BatchFlow_Table, __construct);
ZEND_METHOD(BatchFlow_Table, fromCsv);
ZEND_METHOD(BatchFlow_Table, fromStream);
ZEND_METHOD(BatchFlow_Table, rowCount);
ZEND_METHOD(BatchFlow_Table, columnNames);
ZEND_METHOD(BatchFlow_Table, schema);
ZEND_METHOD(BatchFlow_Table, memoryBytes);
ZEND_METHOD(BatchFlow_Table, query);
ZEND_METHOD(BatchFlow_Table, whereEq);
ZEND_METHOD(BatchFlow_Table, whereGt);
ZEND_METHOD(BatchFlow_Table, whereNe);
ZEND_METHOD(BatchFlow_Table, whereLt);
ZEND_METHOD(BatchFlow_Table, whereGte);
ZEND_METHOD(BatchFlow_Table, whereLte);
ZEND_METHOD(BatchFlow_Table, whereIn);
ZEND_METHOD(BatchFlow_Query, __construct);
ZEND_METHOD(BatchFlow_Query, whereEq);
ZEND_METHOD(BatchFlow_Query, whereGt);
ZEND_METHOD(BatchFlow_Query, whereNe);
ZEND_METHOD(BatchFlow_Query, whereLt);
ZEND_METHOD(BatchFlow_Query, whereGte);
ZEND_METHOD(BatchFlow_Query, whereLte);
ZEND_METHOD(BatchFlow_Query, whereIn);
ZEND_METHOD(BatchFlow_Query, count);
ZEND_METHOD(BatchFlow_Query, sum);
ZEND_METHOD(BatchFlow_Query, min);
ZEND_METHOD(BatchFlow_Query, max);
ZEND_METHOD(BatchFlow_Query, avg);
ZEND_METHOD(BatchFlow_Query, aggregate);
ZEND_METHOD(BatchFlow_Query, summary);
ZEND_METHOD(BatchFlow_Query, groupCount);
ZEND_METHOD(BatchFlow_Query, groupSum);
ZEND_METHOD(BatchFlow_Query, groupAgg);
ZEND_METHOD(BatchFlow_Query, toArray);
ZEND_METHOD(BatchFlow_Query, cursor);
ZEND_METHOD(BatchFlow_Cursor, __construct);
ZEND_METHOD(BatchFlow_Cursor, fetch);
ZEND_METHOD(BatchFlow_Cursor, close);
ZEND_METHOD(BatchFlow_Summary, __construct);
ZEND_METHOD(BatchFlow_Runtime, __construct);
ZEND_METHOD(BatchFlow_Runtime, info);


static const zend_function_entry class_BatchFlow_Csv_methods[] = {
	ZEND_ME(BatchFlow_Csv, __construct, arginfo_class_BatchFlow_Csv___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(BatchFlow_Csv, scan, arginfo_class_BatchFlow_Csv_scan, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(BatchFlow_Csv, scanStream, arginfo_class_BatchFlow_Csv_scanStream, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_Table_methods[] = {
	ZEND_ME(BatchFlow_Table, __construct, arginfo_class_BatchFlow_Table___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(BatchFlow_Table, fromCsv, arginfo_class_BatchFlow_Table_fromCsv, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(BatchFlow_Table, fromStream, arginfo_class_BatchFlow_Table_fromStream, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(BatchFlow_Table, rowCount, arginfo_class_BatchFlow_Table_rowCount, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, columnNames, arginfo_class_BatchFlow_Table_columnNames, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, schema, arginfo_class_BatchFlow_Table_schema, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, memoryBytes, arginfo_class_BatchFlow_Table_memoryBytes, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, query, arginfo_class_BatchFlow_Table_query, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, whereEq, arginfo_class_BatchFlow_Table_whereEq, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, whereGt, arginfo_class_BatchFlow_Table_whereGt, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, whereNe, arginfo_class_BatchFlow_Table_whereNe, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, whereLt, arginfo_class_BatchFlow_Table_whereLt, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, whereGte, arginfo_class_BatchFlow_Table_whereGte, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, whereLte, arginfo_class_BatchFlow_Table_whereLte, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Table, whereIn, arginfo_class_BatchFlow_Table_whereIn, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_Query_methods[] = {
	ZEND_ME(BatchFlow_Query, __construct, arginfo_class_BatchFlow_Query___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(BatchFlow_Query, whereEq, arginfo_class_BatchFlow_Query_whereEq, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, whereGt, arginfo_class_BatchFlow_Query_whereGt, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, whereNe, arginfo_class_BatchFlow_Query_whereNe, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, whereLt, arginfo_class_BatchFlow_Query_whereLt, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, whereGte, arginfo_class_BatchFlow_Query_whereGte, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, whereLte, arginfo_class_BatchFlow_Query_whereLte, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, whereIn, arginfo_class_BatchFlow_Query_whereIn, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, count, arginfo_class_BatchFlow_Query_count, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, sum, arginfo_class_BatchFlow_Query_sum, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, min, arginfo_class_BatchFlow_Query_min, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, max, arginfo_class_BatchFlow_Query_max, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, avg, arginfo_class_BatchFlow_Query_avg, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, aggregate, arginfo_class_BatchFlow_Query_aggregate, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, summary, arginfo_class_BatchFlow_Query_summary, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, groupCount, arginfo_class_BatchFlow_Query_groupCount, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, groupSum, arginfo_class_BatchFlow_Query_groupSum, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, groupAgg, arginfo_class_BatchFlow_Query_groupAgg, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, toArray, arginfo_class_BatchFlow_Query_toArray, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Query, cursor, arginfo_class_BatchFlow_Query_cursor, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_Cursor_methods[] = {
	ZEND_ME(BatchFlow_Cursor, __construct, arginfo_class_BatchFlow_Cursor___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(BatchFlow_Cursor, fetch, arginfo_class_BatchFlow_Cursor_fetch, ZEND_ACC_PUBLIC)
	ZEND_ME(BatchFlow_Cursor, close, arginfo_class_BatchFlow_Cursor_close, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_Summary_methods[] = {
	ZEND_ME(BatchFlow_Summary, __construct, arginfo_class_BatchFlow_Summary___construct, ZEND_ACC_PRIVATE)
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_Runtime_methods[] = {
	ZEND_ME(BatchFlow_Runtime, __construct, arginfo_class_BatchFlow_Runtime___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(BatchFlow_Runtime, info, arginfo_class_BatchFlow_Runtime_info, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_BatchFlowException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_SchemaException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_CsvException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_LimitException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_ResultTooLargeException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_SumOverflowException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_BatchFlow_StreamException_methods[] = {
	ZEND_FE_END
};

static zend_class_entry *register_class_BatchFlow_Csv(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "Csv", class_BatchFlow_Csv_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE;

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_Table(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "Table", class_BatchFlow_Table_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE;

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_Query(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "Query", class_BatchFlow_Query_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE;

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_Cursor(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "Cursor", class_BatchFlow_Cursor_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE;

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_Summary(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "Summary", class_BatchFlow_Summary_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES;

	zval property_rowsScanned_default_value;
	ZVAL_UNDEF(&property_rowsScanned_default_value);
	zend_string *property_rowsScanned_name = zend_string_init("rowsScanned", sizeof("rowsScanned") - 1, 1);
	zend_declare_typed_property(class_entry, property_rowsScanned_name, &property_rowsScanned_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_rowsScanned_name);

	zval property_rowsMatched_default_value;
	ZVAL_UNDEF(&property_rowsMatched_default_value);
	zend_string *property_rowsMatched_name = zend_string_init("rowsMatched", sizeof("rowsMatched") - 1, 1);
	zend_declare_typed_property(class_entry, property_rowsMatched_name, &property_rowsMatched_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_rowsMatched_name);

	zval property_sum_default_value;
	ZVAL_UNDEF(&property_sum_default_value);
	zend_string *property_sum_name = zend_string_init("sum", sizeof("sum") - 1, 1);
	zend_declare_typed_property(class_entry, property_sum_name, &property_sum_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG|MAY_BE_DOUBLE|MAY_BE_NULL));
	zend_string_release(property_sum_name);

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_Runtime(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "Runtime", class_BatchFlow_Runtime_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE;

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_BatchFlowException(zend_class_entry *class_entry_RuntimeException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "BatchFlowException", class_BatchFlow_BatchFlowException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_RuntimeException);

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_SchemaException(zend_class_entry *class_entry_BatchFlow_BatchFlowException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "SchemaException", class_BatchFlow_SchemaException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_BatchFlow_BatchFlowException);

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_CsvException(zend_class_entry *class_entry_BatchFlow_BatchFlowException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "CsvException", class_BatchFlow_CsvException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_BatchFlow_BatchFlowException);

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_LimitException(zend_class_entry *class_entry_BatchFlow_BatchFlowException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "LimitException", class_BatchFlow_LimitException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_BatchFlow_BatchFlowException);

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_ResultTooLargeException(zend_class_entry *class_entry_BatchFlow_LimitException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "ResultTooLargeException", class_BatchFlow_ResultTooLargeException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_BatchFlow_LimitException);

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_SumOverflowException(zend_class_entry *class_entry_BatchFlow_BatchFlowException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "SumOverflowException", class_BatchFlow_SumOverflowException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_BatchFlow_BatchFlowException);

	return class_entry;
}

static zend_class_entry *register_class_BatchFlow_StreamException(zend_class_entry *class_entry_BatchFlow_BatchFlowException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "BatchFlow", "StreamException", class_BatchFlow_StreamException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_BatchFlow_BatchFlowException);

	return class_entry;
}
