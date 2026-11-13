--TEST--
batchflow: a run suspended inside a wrapper callback resumes correctly; concurrent runs on different streams in different fibers
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64'];

echo "-- suspend inside stream_read and resume\n";
$csv = "a\n" . implode("\n", range(1, 20)) . "\n";
$log = [];
$f = bf_wrap_stream($csv, 8, ['on_read' => function ($w) use (&$log) {
    if ($w->reads % 3 === 0) {
        $log[] = 'suspend@' . $w->reads;
        Fiber::suspend($w->reads);
    }
}]);
$fiber = new Fiber(fn() => Csv::scanStream($f, ['a' => 'int64'])->sum('a'));
$v = $fiber->start();
$suspends = 0;
while (!$fiber->isTerminated()) {
    $suspends++;
    $v = $fiber->resume();
}
echo 'suspended at least twice: ', $suspends >= 2 ? 'yes' : 'NO', "\n";
echo 'result: ', bf_fmt($fiber->getReturn()), "\n";

echo "-- two fibers, two streams, interleaved\n";
$mk = function (string $name, string $csv) use ($S) {
    $f = bf_wrap_stream($csv, 5, ['on_read' => function ($w) { Fiber::suspend(); }]);
    return new Fiber(fn() => $name . '=' . bf_try(fn() => Csv::scanStream($f, $S)->sum('a')));
};
$fa = $mk('A', "a\n1\n2\n3\n4\n5\n6\n");
$fb = $mk('B', "a\n100\n200\n300\n400\n");
$fa->start();
$fb->start();
$turns = 0;
while (!$fa->isTerminated() || !$fb->isTerminated()) {
    foreach ([$fa, $fb] as $fi) {
        if (!$fi->isTerminated()) {
            $fi->resume();
        }
    }
    $turns++;
}
echo $fa->getReturn(), ' ', $fb->getReturn(), "\n";
echo 'interleaved over several turns: ', $turns > 2 ? 'yes' : 'NO', "\n";

echo "-- an error in one fiber does not disturb the other\n";
$fa = $mk('A', "a\n1\nx\n");
$fb = $mk('B', "a\n7\n8\n");
$fa->start();
$fb->start();
while (!$fa->isTerminated() || !$fb->isTerminated()) {
    foreach ([$fa, $fb] as $fi) {
        if (!$fi->isTerminated()) {
            $fi->resume();
        }
    }
}
echo $fa->getReturn(), "\n", $fb->getReturn(), "\n";

echo "-- path sources in fibers\n";
$mkp = function (string $name, string $csv) use ($S) {
    return new Fiber(function () use ($name, $csv, $S) {
        $q = Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]], 'max' => 6, 'on_read' => function ($w) { Fiber::suspend(); }]), $S);
        return $name . '=' . bf_try(fn() => $q->count());
    });
};
$fa = $mkp('P', "a\n1\n2\n3\n");
$fa->start();
while (!$fa->isTerminated()) {
    $fa->resume();
}
echo $fa->getReturn(), "\n";
?>
--EXPECT--
-- suspend inside stream_read and resume
suspended at least twice: yes
result: int(210)
-- two fibers, two streams, interleaved
A=int(21) B=int(1000)
interleaved over several turns: yes
-- an error in one fiber does not disturb the other
A=CsvException: row 2 (line 3, offset 4): column "a": expected int64, got malformed value
B=int(15)
-- path sources in fibers
P=int(3)
