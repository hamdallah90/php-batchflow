--TEST--
batchflow: 100,000 build-and-free cycles, including failures, return memory to baseline
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator counts memory_get_usage();
// only the untracked system allocator leaves it at 0.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_get_usage() needs the Zend or the tracked allocator');
?>
--FILE--
<?php
use BatchFlow\{Csv, Table, BatchFlowException, SchemaException};

$schema = ['id' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$tally = [];

function cycle(int $i, array $schema, array &$tally): void
{
    try {
        switch ($i % 8) {
            case 0:
                $q = Csv::scan('/nonexistent/batchflow/none.csv', $schema, ['maxFieldBytes' => 100, 'simd' => 'scalar']);
                $q = $q->whereEq('id', $i)->whereGt('f', 1.5)->whereEq('s', 'x' . $i)->whereEq('b', null);
                $k = 'built';
                break;
            case 1:
                Csv::scan('/nonexistent/batchflow/none.csv', []);
                break;
            case 2:
                Csv::scan('/nonexistent/batchflow/none.csv', ['a' => 'nope']);
                break;
            case 3:
                Csv::scan('/nonexistent/batchflow/none.csv', $schema, ['maxFieldBytes' => 0]);
                break;
            case 4:
                Csv::scan('/nonexistent/batchflow/none.csv', $schema)->whereEq('zz', 1);
                break;
            case 5:
                Csv::scan('/nonexistent/batchflow/none.csv', $schema)->whereEq('s', "\xff");
                break;
            case 6:
                Csv::scan('/nonexistent/batchflow/none.csv', $schema)->whereEq('id', [1]);
                break;
            case 7:
                Table::fromCsv('/nonexistent/batchflow/none.csv', $schema, ['dictionary' => ['f']]);
                break;
        }
        $k = $k ?? 'no exception';
    } catch (Throwable $e) {
        $k = get_class($e);
    }
    $tally[$k] = ($tally[$k] ?? 0) + 1;
}

for ($i = 0; $i < 2000; $i++) {   // warm up caches and the exception machinery
    cycle($i, $schema, $tally);
}
$tally = [];
gc_collect_cycles();
$before = memory_get_usage();
for ($i = 0; $i < 100000; $i++) {
    cycle($i, $schema, $tally);
}
gc_collect_cycles();
$growth = memory_get_usage() - $before;
ksort($tally);
print_r($tally);
echo $growth < 65536 ? "memory returned to baseline\n" : "LEAK: grew by $growth bytes\n";
?>
--EXPECT--
Array
(
    [BatchFlow\SchemaException] => 75000
    [TypeError] => 12500
    [built] => 12500
)
memory returned to baseline
