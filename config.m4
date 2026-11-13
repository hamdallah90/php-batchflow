PHP_ARG_ENABLE([batchflow],
  [whether to enable BatchFlow support],
  [AS_HELP_STRING([--enable-batchflow], [Enable BatchFlow support])],
  [no])

if test "$PHP_BATCHFLOW" != "no"; then
  dnl Code alignment for the row loops (SPEC 6.2), each flag only if the compiler accepts it without a warning.
  BATCHFLOW_CFLAGS="-fvisibility=hidden"
  AX_CHECK_COMPILE_FLAG([-falign-functions=64], [BATCHFLOW_CFLAGS="$BATCHFLOW_CFLAGS -falign-functions=64"],, [-Werror])
  AX_CHECK_COMPILE_FLAG([-falign-loops=32], [BATCHFLOW_CFLAGS="$BATCHFLOW_CFLAGS -falign-loops=32"],, [-Werror])
  PHP_NEW_EXTENSION([batchflow], [src/bf_masks.c src/bf_value.c src/bf_csv.c src/bf_exec.c src/bf_hash.c src/bf_groups.c src/bf_table.c src/php_batchflow.c src/php_errors.c src/php_query.c src/php_table.c src/php_run.c src/php_cursor.c], [$ext_shared],, [$BATCHFLOW_CFLAGS])
  PHP_ADD_INCLUDE([$ext_srcdir/stubs])
  PHP_ADD_INCLUDE([$ext_srcdir/src])
  PHP_ADD_BUILD_DIR([$ext_builddir/src])
fi
