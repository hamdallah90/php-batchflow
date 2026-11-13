--TEST--
batchflow: fclose() from a callback is refused with a warning and no crash, get_resources() fclose, held-stream refusal, nested run on another stream
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64'];
$csv = "a\n1\n2\n3\n4\n5\n";
$warnings = [];
set_error_handler(function ($no, $msg) use (&$warnings) {
    $warnings[] = $msg;
    return true;
});

echo "-- fclose() of the running caller stream from inside a callback\n";
$h = null;
$h = bf_wrap_stream($csv, 4, ['on_read' => function ($w) use (&$h) {
    if ($w->reads === 2) {
        fclose($h);
    }
}]);
$r = bf_try(fn() => Csv::scanStream($h, $S)->count());
echo "result: $r\n";
echo 'warnings: ', count($warnings), ', all from fclose(): ', $warnings && count(array_filter($warnings, fn($m) => str_starts_with($m, 'fclose(): '))) === count($warnings) ? 'yes' : 'NO ' . json_encode($warnings), "\n";
echo 'stream still open: ', is_resource($h) ? 'yes' : 'NO', "\n";
fclose($h);

echo "-- fclose() of the path-opened stream, found through get_resources()\n";
$warnings = [];
$closeAll = function ($w) {
    if ($w->reads === 2) {
        foreach (get_resources('stream') as $res) {
            if (@stream_get_meta_data($res)['wrapper_type'] === 'user-space') {
                fclose($res);
            }
        }
    }
};
$r = bf_try(fn() => Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]], 'max' => 4, 'on_read' => $closeAll]), $S)->count());
echo "result: $r\n";
echo 'warnings: ', count($warnings) >= 1 ? 'at least one' : 'NONE', ', all from fclose(): ', count(array_filter($warnings, fn($m) => str_starts_with($m, 'fclose(): '))) === count($warnings) ? 'yes' : 'NO', "\n";
echo 'the wrapper was closed exactly once, by the run: ', BfWrap::$closes === 1 ? 'yes' : 'NO (' . BfWrap::$closes . ')', "\n";

echo "-- a second run on a stream that a run already holds is refused\n";
$warnings = [];
$inner = 'none';
$h2 = null;
$h2 = bf_wrap_stream($csv, 4, ['on_read' => function ($w) use (&$h2, &$inner, $S) {
    if ($w->reads === 2 && $inner === 'none') {
        $inner = bf_try(fn() => Csv::scanStream($h2, $S)->count());
    }
}]);
echo 'outer run: ', bf_try(fn() => Csv::scanStream($h2, $S)->count()), "\n";
echo 'inner run: ', $inner, "\n";
rewind($h2);
echo 'a new run once the first has finished: ', bf_try(fn() => Csv::scanStream($h2, $S)->count()), "\n";

echo "-- a nested run on another stream\n";
$inner = 'none';
$h3 = bf_wrap_stream($csv, 4, ['on_read' => function ($w) use (&$inner, $S) {
    if ($w->reads === 2) {
        $other = bf_mem("a\n10\n20\n");
        $inner = bf_try(fn() => Csv::scanStream($other, $S)->sum('a'));
    }
}]);
echo 'outer run: ', bf_try(fn() => Csv::scanStream($h3, $S)->sum('a')), "\n";
echo 'inner run: ', $inner, "\n";

echo "-- a nested run on another path source\n";
$inner = 'none';
$file = bf_file("a\n100\n200\n");
$path = bf_wrap_path(['segments' => [[$csv, 1]], 'max' => 4, 'on_read' => function ($w) use (&$inner, $S, $file) {
    if ($w->reads === 2) {
        $inner = bf_try(fn() => Csv::scan($file, $S)->sum('a'));
    }
}]);
$r = bf_try(fn() => Csv::scan($path, $S)->sum('a'));
echo "outer run: $r\ninner run: $inner\n";

echo "-- a directory handle is refused\n";
$d = opendir(sys_get_temp_dir());
echo bf_try(fn() => Csv::scanStream($d, $S)->count()), "\n";
closedir($d);

echo "-- a run started from the wrapper's own callback while PHP is reading that stream\n";
// Stream rules: PHP 8.5 sets the in-use flag around userspace reads and refuses; 8.3 and 8.4 allow it
// (the result is then unspecified, so only 8.5 is asserted).
$res = 'none';
$self = null;
$self = bf_wrap_stream($csv, 4, ['on_read' => function ($w) use (&$self, &$res, $S) {
    if ($res === 'none') {
        $res = bf_try(fn() => Csv::scanStream($self, $S)->count());
    }
}]);
fread($self, 1);
$want = 'StreamException: a stream is already in use by another BatchFlow run or cannot be closed from PHP';
echo PHP_VERSION_ID < 80500 || $res === $want ? "ok\n" : "WRONG: $res\n";
restore_error_handler();
?>
--EXPECT--
-- fclose() of the running caller stream from inside a callback
result: int(5)
warnings: 1, all from fclose(): yes
stream still open: yes
-- fclose() of the path-opened stream, found through get_resources()
result: int(5)
warnings: at least one, all from fclose(): yes
the wrapper was closed exactly once, by the run: yes
-- a second run on a stream that a run already holds is refused
outer run: int(5)
inner run: StreamException: a stream is already in use by another BatchFlow run or cannot be closed from PHP
a new run once the first has finished: CsvException: missing header
-- a nested run on another stream
outer run: int(15)
inner run: int(30)
-- a nested run on another path source
outer run: int(15)
inner run: int(300)
-- a directory handle is refused
StreamException: a stream is already in use by another BatchFlow run or cannot be closed from PHP
-- a run started from the wrapper's own callback while PHP is reading that stream
ok
