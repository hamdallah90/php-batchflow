--TEST--
batchflow: with zend.enable_gc=0, a cursor kept alive by a cycle until the engine's final object sweep, below its Query's handle, after the caller's stream was closed at shutdown: the run holds its own reference on the stream resource (SPEC 6.8; the use-after-free shows under ASan)
--EXTENSIONS--
batchflow
--INI--
zend.enable_gc=0
--ENV--
ASAN_OPTIONS=detect_leaks=0:halt_on_error=1:abort_on_error=0:detect_stack_use_after_return=1:print_summary=1
ZEND_ALLOC_PRINT_LEAKS=0
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// With zend.enable_gc=1 (even after gc_disable()) the cursor is freed before its Query. With 0, at shutdown PHP closes
// every resource, then frees the objects still alive from the highest handle down, whatever references remain, and
// leaves the cycle's objects to its leak report (engine behaviour, hence the leak options above). The cycle keeps the
// cursor out of the symbol table's destruction; the freed $gap handle puts the cursor below its Query, so the Query,
// the only other holder of the stream resource, is freed first.
$p = bf_file("a\n1\n2\n3\n");
$holder = new stdClass();
$holder->self = $holder;
$gap = new stdClass();
$q = Csv::scanStream(fopen($p, 'rb'), ['a' => 'int64']);
unset($gap);
$c = $q->cursor();
echo 'cursor handle below the query handle: ', spl_object_id($c) < spl_object_id($q) ? 'yes' : 'NO', "\n";
echo 'first: ', json_encode($c->fetch(1)), "\n";
$holder->c = $c;
unset($c, $q);
echo "end\n";
?>
--EXPECT--
cursor handle below the query handle: yes
first: [{"a":1}]
end
