--TEST--
batchflow: the whereIn literal preflight (SPEC 5.7 step 5): the distinct string bytes are checked with mem_fits before copying; refused, LimitException "memory_limit would be exceeded" with no prefix, after every element check
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=128M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

$q = Csv::scan(bf_file("s\na\n"), ['s' => 'string']);
$distinct = [];
for ($k = 0; $k < 16; $k++) {
    $distinct[] = str_repeat(chr(97 + $k), 1048576);
}
$same = array_fill(0, 16, $distinct[0]);

/** Leave about 4 MiB above what the process holds now. */
function tight(): void
{
    gc_mem_caches();
    ini_set('memory_limit', (string)(memory_get_usage(true) + 4 * 1048576));
}

tight();
echo "-- 16 distinct 1 MiB strings need 16 MiB of copies: refused before copying, no prefix\n";
echo bf_try(fn() => $q->whereIn('s', $distinct)), "\n";
echo "-- 16 copies of one 1 MiB string are 1 MiB distinct: accepted\n";
echo bf_try(fn() => $q->whereIn('s', $same)->count()), "\n";
echo "-- nulls take no bytes\n";
echo bf_try(fn() => $q->whereIn('s', array_merge([null], $same, [null]))->count()), "\n";
echo "-- an element error is reported before the memory check\n";
echo bf_try(fn() => $q->whereIn('s', array_merge($distinct, [5]))), "\n";
echo bf_try(fn() => $q->whereIn('s', array_merge($distinct, ["\xFF"]))), "\n";
echo "-- the refusal is repeatable and the process stays usable\n";
echo bf_try(fn() => $q->whereIn('s', $distinct)), "\n";
echo bf_try(fn() => $q->whereIn('s', ['a', 'b'])->count()), "\n";
echo "-- with headroom the same list is accepted, so memory alone refused it\n";
ini_set('memory_limit', '256M');
echo bf_try(fn() => $q->whereIn('s', $distinct)->count()), "\n";
?>
--EXPECT--
-- 16 distinct 1 MiB strings need 16 MiB of copies: refused before copying, no prefix
LimitException: memory_limit would be exceeded
-- 16 copies of one 1 MiB string are 1 MiB distinct: accepted
int(0)
-- nulls take no bytes
int(0)
-- an element error is reported before the memory check
SchemaException: column "s": whereIn element 16: value of type int cannot be compared with string column
SchemaException: column "s": whereIn element 16: string literal is not valid UTF-8
-- the refusal is repeatable and the process stays usable
LimitException: memory_limit would be exceeded
int(1)
-- with headroom the same list is accepted, so memory alone refused it
int(0)
