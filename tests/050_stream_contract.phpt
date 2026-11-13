--TEST--
batchflow: path and stream sources - laziness, reopen per terminal, caller stream left open and unchanged, buffered data consumed, rewind
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\{Csv, StreamException};

$S = ['a' => 'int64', 'b' => 'string'];
$csv = "a,b\n1,x\n2,y\n3,z\n";

echo "-- path source: no I/O at scan(), reopened by every terminal\n";
$p = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $p;
unlink($p);
$q = Csv::scan($p, $S);
echo 'scan() of a file that does not exist yet: ', get_class($q), "\n";
echo 'terminal on the missing file: ', bf_try(fn() => $q->count()), "\n";
file_put_contents($p, $csv);
echo 'after the file appears: ', bf_try(fn() => $q->count()), "\n";
file_put_contents($p, "a,b\n1,x\n");
echo 'after the file changes (no cache): ', bf_try(fn() => $q->count()), "\n";
file_put_contents($p, "a,b\n1,x\n2,y\n3,z\n4,w\n5,v\n");
echo 'sum on the new content: ', bf_try(fn() => $q->sum('a')), "\n";
unlink($p);
echo 'after the file is deleted: ', bf_try(fn() => $q->count()), "\n";
$w = $q->whereEq('b', 'x');
echo 'where* leaves the receiver unchanged: ', get_class($w), ' ', $w !== $q ? 'distinct' : 'same', "\n";
file_put_contents($p, $csv);
echo 'receiver count: ', bf_try(fn() => $q->count()), ', derived count: ', bf_try(fn() => $w->count()), "\n";

echo "-- stream source: reads from the current position to EOF on each terminal\n";
$f = bf_mem($csv);
$q = Csv::scanStream($f, $S);
echo 'first terminal: ', bf_try(fn() => $q->count()), "\n";
echo 'second terminal on the consumed stream: ', bf_try(fn() => $q->count()), "\n";
echo 'and a third: ', bf_try(fn() => $q->sum('a')), "\n";
rewind($f);
echo 'after rewind(): ', bf_try(fn() => $q->count()), "\n";
rewind($f);
echo 'after rewind(), sum: ', bf_try(fn() => $q->sum('a')), "\n";
echo 'caller stream is still open: ', is_resource($f) ? 'yes' : 'NO', "\n";

echo "-- caller stream left open and unchanged\n";
$tmp = bf_file($csv);
$f = fopen($tmp, 'rb');
$before = stream_get_meta_data($f);
unset($before['eof'], $before['unread_bytes']);
$r = bf_try(fn() => Csv::scanStream($f, $S)->count());
$after = stream_get_meta_data($f);
unset($after['eof'], $after['unread_bytes']);
echo "result $r; meta data unchanged: ", $before === $after ? 'yes' : 'NO', '; open: ', is_resource($f) ? 'yes' : 'NO', "\n";
fclose($f);
echo "-- data the caller has already buffered is still consumed\n";
$f = fopen($tmp, 'rb');
echo 'caller reads the first line: ', json_encode(fgets($f)), "\n";   // pulls the whole file into PHP's stream buffer
echo 'the run starts at the caller position, "1,x" is the header: ', bf_try(fn() => Csv::scanStream($f, ['1' => 'int64', 'x' => 'string'])->count()), "\n";
fclose($f);
$f = fopen($tmp, 'rb');
fgets($f);
fgets($f);
echo 'caller consumed two lines, "2,y" is the header: ', bf_try(fn() => Csv::scanStream($f, ['2' => 'int64', 'y' => 'string'])->count()), "\n";
fclose($f);
$f = fopen($tmp, 'rb');
echo 'caller reads 4 bytes: ', json_encode(fread($f, 4)), "\n";
echo 'the run starts after them: ', bf_try(fn() => Csv::scanStream($f, ['1' => 'int64', 'x' => 'string'])->count()), "\n";
fclose($f);

echo "-- a stream closed before the run starts\n";
$f = bf_mem($csv);
$q = Csv::scanStream($f, $S);
fclose($f);
echo bf_try(fn() => $q->count()), "\n";
echo bf_try(fn() => $q->sum('a')), "\n";

echo "-- the Query keeps the stream alive\n";
function mk(string $csv): BatchFlow\Query
{
    return Csv::scanStream(bf_mem($csv), ['a' => 'int64', 'b' => 'string']);
}
$q = mk($csv);
echo 'stream out of scope: ', bf_try(fn() => $q->count()), "\n";
$q2 = mk($csv)->whereEq('b', 'y');
echo 'derived query: ', bf_try(fn() => $q2->count()), "\n";

echo "-- the run leaves no 'in use' flag behind\n";
$f = bf_mem("a,b\n1,\"x\n");
$q = Csv::scanStream($f, $S);
echo bf_try(fn() => $q->count()), "\n";
rewind($f);
echo bf_try(fn() => $q->count()), "\n";
$f = bf_mem($csv);
Csv::scanStream($f, $S)->count();
rewind($f);
echo 'a new Query on the same stream: ', bf_try(fn() => Csv::scanStream($f, $S)->count()), "\n";
echo 'fclose() is allowed afterwards: ';
set_error_handler(function ($n, $m) { echo "WARNING: $m\n"; return true; });
fclose($f);
restore_error_handler();
echo "done\n";
?>
--EXPECT--
-- path source: no I/O at scan(), reopened by every terminal
scan() of a file that does not exist yet: BatchFlow\Query
terminal on the missing file: StreamException: cannot open input
after the file appears: int(3)
after the file changes (no cache): int(1)
sum on the new content: int(15)
after the file is deleted: StreamException: cannot open input
where* leaves the receiver unchanged: BatchFlow\Query distinct
receiver count: int(3), derived count: int(1)
-- stream source: reads from the current position to EOF on each terminal
first terminal: int(3)
second terminal on the consumed stream: CsvException: missing header
and a third: CsvException: missing header
after rewind(): int(3)
after rewind(), sum: int(6)
caller stream is still open: yes
-- caller stream left open and unchanged
result int(3); meta data unchanged: yes; open: yes
-- data the caller has already buffered is still consumed
caller reads the first line: "a,b\n"
the run starts at the caller position, "1,x" is the header: int(2)
caller consumed two lines, "2,y" is the header: int(1)
caller reads 4 bytes: "a,b\n"
the run starts after them: int(2)
-- a stream closed before the run starts
StreamException: a stream was closed before the run started
StreamException: a stream was closed before the run started
-- the Query keeps the stream alive
stream out of scope: int(3)
derived query: int(1)
-- the run leaves no 'in use' flag behind
CsvException: row 1 (line 2, offset 4): unterminated quoted field
CsvException: row 1 (line 2, offset 4): unterminated quoted field
a new Query on the same stream: int(3)
fclose() is allowed afterwards: done
