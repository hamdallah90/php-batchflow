--TEST--
batchflow: at a 2M memory_limit the 288 KiB initial buffers are refused while less than twice that is free, before any allocation
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// The expected output depends on the Zend allocator's accounting of chunks and pages; run-tests --asan replaces it
// with the tracked allocator, which enforces memory_limit but counts differently.
if (getenv('USE_ZEND_ALLOC') === '0') die('skip needs the Zend allocator');
?>
--INI--
memory_limit=2M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$q = Csv::scan(bf_file("a\n1\n"), ['a' => 'int64']);

// real + a 2 MiB chunk exceeds the limit, so a request above half the free space is refused
// (SPEC 6.4). The initial buffers ask for 262,144 + 32,768 bytes; leave 500,000 free: enough for them
// to fit, not enough for the rule.
$free = memory_get_usage(true) - memory_get_usage();
$fill = str_repeat('x', max(0, $free - 500000));
echo 'free memory: ', memory_get_usage(true) - memory_get_usage() < 589824 ? 'below twice the request' : 'too much', "\n";
echo bf_try(fn() => $q->count()), "\n";

$fill = null;
echo 'after freeing the filler: ', bf_try(fn() => $q->count()), "\n";
?>
--EXPECT--
free memory: below twice the request
LimitException: memory_limit would be exceeded
after freeing the filler: int(1)
