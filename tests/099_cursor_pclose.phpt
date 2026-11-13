--TEST--
batchflow: pclose() closes a caller's stream between fetches although fclose() is refused; the next fetch raises StreamException before emitting the rows still buffered, and the cursor is closed
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// Four rows that the first fetch's read buffers in full, so only the closed-stream check stops the second fetch.
$p = bf_file("a\n1\n2\n3\n4\n");
$fp = fopen($p, 'rb');
$c = Csv::scanStream($fp, ['a' => 'int64'])->cursor();
echo 'first: ', json_encode($c->fetch(1)), "\n";
echo 'fclose() refused while the cursor holds the stream: ', @fclose($fp) === false ? 'yes' : 'NO', "\n";
// pclose() does not check PHP_STREAM_FLAG_NO_FCLOSE (php-src ext/standard/file.c), so it closes the resource.
@pclose($fp);
echo 'closed: ', is_resource($fp) ? 'NO' : 'yes', "\n";
echo 'second: ', bf_try(fn() => $c->fetch(1)), "\n";
echo 'third: ', bf_try(fn() => $c->fetch(1)), "\n";
?>
--EXPECT--
first: [{"a":1}]
fclose() refused while the cursor holds the stream: yes
closed: yes
second: StreamException: a stream was closed during processing
third: BatchFlowException: cursor is closed
