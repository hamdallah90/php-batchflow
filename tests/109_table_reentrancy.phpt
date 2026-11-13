--TEST--
batchflow: a table is immutable and holds no per-run state: queries on it from inside a wrapper callback of another load and scan, and from interleaved fibers, are correct
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\{Csv, Table};

[$csv, $S] = tb_fixture(800, 109);
$o = ['dictionary' => ['k', 'g']];
$t = tb_load($csv, $S, $o);
$m0 = $t->memoryBytes();
$expect = [
    'count' => tb_term($t->whereEq('k', 'red'), 'count'),
    'groupSum' => tb_term($t->query(), 'groupSum', ['key' => 'k', 'sum' => 'v']),
    'toArray' => tb_term($t->whereGt('id', 790), 'toArray'),
];
$run = fn() => [
    'count' => tb_term($t->whereEq('k', 'red'), 'count'),
    'groupSum' => tb_term($t->query(), 'groupSum', ['key' => 'k', 'sum' => 'v']),
    'toArray' => tb_term($t->whereGt('id', 790), 'toArray'),
];

// SPEC 5.12: nested queries on one table are safe. Here the nesting is real: the callbacks run inside another run's reads.
echo "-- queries on the table from a wrapper's read callback, during a streaming scan\n";
$inner = [];
$path = bf_wrap_path(['segments' => [["a,b\n", 1], ["1,x\n", 3000]], 'max' => 512, 'on_read' => function () use (&$inner, $run) {
    $inner[] = $run();
}]);
echo 'outer count: ', Csv::scan($path, ['a' => 'int64', 'b' => 'string'])->count(), "\n";
echo 'callbacks ran: ', count($inner) > 5 ? 'yes' : 'NO', ', every nested result correct: ', array_unique(array_map(fn($r) => $r === $expect, $inner)) === [true] ? 'yes' : 'NO', "\n";

echo "-- queries on the table, and loads of other tables, from a wrapper's read callback during a table load\n";
$inner = [];
$extra = tb_file("n\n5\n6\n7\n");
$path = bf_wrap_path(['segments' => [["a,b\n", 1], ["1,x\n", 3000]], 'max' => 512, 'on_read' => function () use (&$inner, $run, $extra) {
    $n = Table::fromCsv($extra, ['n' => 'int64']);
    $inner[] = [$run(), $n->query()->sum('n')];
}]);
$loaded = Table::fromCsv($path, ['a' => 'int64', 'b' => 'string']);
echo 'outer table rows: ', $loaded->rowCount(), ', sum a: ', $loaded->query()->sum('a'), "\n";
echo 'callbacks ran: ', count($inner) > 5 ? 'yes' : 'NO', ', nested results correct: ', array_unique(array_map(fn($r) => $r[0] === $expect && $r[1] === 18, $inner)) === [true] ? 'yes' : 'NO', "\n";

echo "-- a query from stream_close of a table load\n";
$seen = null;
$path = bf_wrap_path(['segments' => [["a\n1\n2\n", 1]], 'on_close' => function () use (&$seen, $run) {
    $seen = $run();
}]);
$lt = Table::fromCsv($path, ['a' => 'int64']);
echo 'rows: ', $lt->rowCount(), ', close ran: ', BfWrap::$closes, ', nested results correct: ', $seen === $expect ? 'yes' : 'NO', "\n";

// SPEC 5.12 "fiber-interleaved queries on one table are safe"
echo "-- fibers\n";
$fibers = [];
$log = [];
foreach ([1, 2, 3] as $id) {
    $fibers[$id] = new Fiber(function () use ($t, $id, &$log, $expect, $run) {
        $c = $t->whereEq('k', 'red')->cursor();
        $rows = [];
        while (($b = $c->fetch(10)) !== []) {
            array_push($rows, ...$b);
            Fiber::suspend();
            $r = $run();
            $log[] = $r === $expect ? "f$id ok" : "f$id WRONG";
        }
        $c->close();
        return count($rows);
    });
}
foreach ($fibers as $f) {
    $f->start();
}
$guard = 0;
while (array_filter($fibers, fn($f) => !$f->isTerminated()) && $guard++ < 1000) {
    foreach ($fibers as $f) {
        if ($f->isSuspended()) {
            $f->resume();
        }
    }
}
$want = count($t->whereEq('k', 'red')->toArray(10000));
echo 'fiber results: ', json_encode(array_map(fn($f) => $f->getReturn() === $want, $fibers)), ', checks ok: ', count(array_unique($log)) <= 3 && !preg_grep('/WRONG/', $log) ? 'yes' : 'NO', ', checks run: ', count($log) > 10 ? 'yes' : 'NO', "\n";

echo "-- a table load suspended in a fiber while the table is queried and another is loaded\n";
$f = new Fiber(function () use ($S) {
    $path = bf_wrap_path(['segments' => [["a\n", 1], ["1\n", 2000]], 'max' => 256, 'on_read' => function () {
        Fiber::suspend();
    }]);
    return Table::fromCsv($path, ['a' => 'int64']);
});
$f->start();
$steps = 0;
while (!$f->isTerminated()) {
    $steps++;
    $other = Table::fromCsv($extra, ['n' => 'int64']);
    $ok = $run() === $expect && $other->query()->sum('n') === 18;
    if (!$ok) {
        echo "WRONG at step $steps\n";
    }
    $f->resume();
}
echo 'suspended ', $steps > 5 ? 'many times' : 'too few times', ', table loaded: ', $f->getReturn()->rowCount(), ', sum ', $f->getReturn()->query()->sum('a'), "\n";

echo "-- the table is unchanged by all of it\n";
echo 'memoryBytes unchanged: ', $t->memoryBytes() === $m0 ? 'yes' : 'NO', ', results unchanged: ', $run() === $expect ? 'yes' : 'NO', "\n";
?>
--EXPECT--
-- queries on the table from a wrapper's read callback, during a streaming scan
outer count: 3000
callbacks ran: yes, every nested result correct: yes
-- queries on the table, and loads of other tables, from a wrapper's read callback during a table load
outer table rows: 3000, sum a: 3000
callbacks ran: yes, nested results correct: yes
-- a query from stream_close of a table load
rows: 2, close ran: 1, nested results correct: yes
-- fibers
fiber results: {"1":true,"2":true,"3":true}, checks ok: yes, checks run: yes
-- a table load suspended in a fiber while the table is queried and another is loaded
suspended many times, table loaded: 2000, sum 2000
-- the table is unchanged by all of it
memoryBytes unchanged: yes, results unchanged: yes
