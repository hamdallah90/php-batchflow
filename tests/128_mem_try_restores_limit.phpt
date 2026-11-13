--TEST--
batchflow: the memory preflight's try of a large block leaves memory_limit and the hard limit in force, whether the try fits or not
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0') die('skip needs the Zend allocator: its chunks and page runs');
?>
--CONFLICTS--
all
--INI--
memory_limit=256M
--FILE--
<?php
// A large block the rule refuses for want of room for a chunk is tried under a heap limit one chunk higher, then the
// limit is restored; zend_set_memory_limit() recomputes the hard limit (PHP 8.3.36 and later) from it. Each child runs
// one try, then allocates past a limit:
//   soft: a 1.9 MB string, a run of 464 pages no chunk has free, needs a chunk the limit has no room for. It must end
//         in "Allowed memory size of L bytes", L the limit the child set. With the limit left raised it succeeds.
//   hard: a string of 4 L, past twice the limit (the hard limit at the default memory_hard_limit_percent). It must end
//         in the same fatal as a control child that prepares the same heap and limit and makes no run. The PHP builds
//         tested report the soft-limit fatal there, with and without a run.
// Cases:
//   fits:    memory_limit at the one mapped chunk, a 3-row file: the 288 KiB reader buffers fit in its free pages.
//   refused: a heap whose free pages lie in runs of 3, no room for a chunk: the key arena growth does not fit.
$p = tempnam(sys_get_temp_dir(), 'bf');
$f = fopen($p, 'w');
fwrite($f, "k,g,v\n");
for ($i = 0; $i < 100000; $i++) {
    fwrite($f, 'key' . $i . ',' . ($i * 3) . ",$i\n");
}
fclose($f);
$small = $p . '.small';
file_put_contents($small, "k,g,v\na,1,1\nb,2,2\na,3,3\n");
$c = $p . '.php';
file_put_contents($c, '<?php
[$_, $p, $case, $check, $run] = $argv;
$s = ["k" => "string", "g" => "int64", "v" => "int64"];
$a = [];
if ($case === "refused") {
    for ($i = 0; $i < 2048; $i++) {
        $a[] = str_repeat(chr(65 + $i % 26), 3500);
    }
    for ($i = 0; $i < 2048; $i++) {
        if ($i % 4 !== 3) {
            unset($a[$i]);
        }
    }
    gc_collect_cycles();
    $lim = (string)memory_get_usage(true);
    $L = (int)$lim;
} else {
    $lim = "2M";
    $L = 2097152;
}
ini_set("memory_limit", $lim);
try {
    $n = $run ? count(BatchFlow\Csv::scan($p, $s)->groupCount("k")) : 0;
    echo "groups $n\n";
} catch (BatchFlow\LimitException $e) {
    echo "limit\n";
}
echo "L $L ini ", ini_get("memory_limit") === $lim ? "same" : "changed", "\n";
$b = str_repeat("y", $check === "soft" ? 1900000 : 4 * $L);
echo "allocated ", strlen($b), "\n";
');
$php = getenv('TEST_PHP_EXECUTABLE') . ' ' . getenv('TEST_PHP_EXTRA_ARGS');
$fatal = fn ($t) => preg_match('/(Allowed memory size|Hard memory limit) of \d+ bytes exhausted[^\n]*/', $t, $h) ? $h[0] : 'none';
foreach (['fits' => $small, 'refused' => $p] as $case => $file) {
    $r = [];
    foreach (['soft', 'hard'] as $check) {
        $o = [];
        foreach ([1, 0] as $run) {
            $out = [];
            exec($php . ' ' . escapeshellarg($c) . ' ' . escapeshellarg($file) . " $case $check $run 2>&1", $out);
            $o[$run] = implode("\n", $out);
        }
        if (!preg_match('/^L (\d+) ini (\w+)$/m', $o[1], $m)) {
            echo "$case $check: no report: $o[1]\n";
            continue;
        }
        if ($check === 'soft') {
            $got = str_contains($fatal($o[1]), "Allowed memory size of $m[1] bytes") ? 'kept' : $fatal($o[1]);
        } else {
            $got = $fatal($o[1]) !== 'none' && $fatal($o[1]) === $fatal($o[0]) ? 'kept' : $fatal($o[1]) . ' vs ' . $fatal($o[0]);
        }
        $r[] = "$check $got, ini $m[2], allocated: " . (str_contains($o[1], 'allocated ') ? 'yes' : 'no');
        $first = strtok($o[1], "\n");
    }
    echo "$case: $first; ", implode('; ', $r), "\n";
}
unlink($c);
unlink($small);
unlink($p);
?>
--EXPECT--
fits: groups 2; soft kept, ini same, allocated: no; hard kept, ini same, allocated: no
refused: limit; soft kept, ini same, allocated: no; hard kept, ini same, allocated: no
