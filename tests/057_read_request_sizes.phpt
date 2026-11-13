--TEST--
batchflow: a path stream is read unbuffered with the request sizes of SPEC 6.2: max(fill, 65536), clamped by the buffer bound
--EXTENSIONS--
batchflow
--FILE--
<?php
use BatchFlow\Csv;

final class Rec
{
    public $context;
    public static string $data = '';
    public static int $max = PHP_INT_MAX;
    public static array $counts = [];
    private int $pos = 0;

    public function stream_open($p, $m, $o, &$op) { self::$counts = []; return true; }
    public function stream_read($count)
    {
        self::$counts[] = $count;
        $s = substr(self::$data, $this->pos, min($count, self::$max));
        $this->pos += strlen($s);
        return $s;
    }
    public function stream_eof() { return $this->pos >= strlen(self::$data); }
    public function stream_stat() { return []; }
    public function stream_close() {}
}
stream_wrapper_register('rec', Rec::class);

$S = ['a' => 'int64', 's' => 'string'];

echo "-- one row of 200,003 bytes, default limits\n";
Rec::$data = "a,s\n1," . str_repeat('x', 200000) . "\n2,y\n";
echo Csv::scan('rec://x', $S, ['maxFieldBytes' => 1048576])->whereEq('s', 'y')->count(), "\n";
echo implode(' ', Rec::$counts), "\n";

echo "-- short reads of 20,000 bytes and maxRowBytes 100000: requests are clamped to B - 64 - fill\n";
Rec::$max = 20000;
Rec::$data = "a,s\n1," . str_repeat('x', 180000) . "\n2,y\n";
try {
    Csv::scan('rec://x', $S, ['maxRowBytes' => 100000])->whereEq('s', 'y')->count();
    echo "no exception\n";
} catch (BatchFlow\LimitException $e) {
    echo $e->getMessage(), "\n";
}
echo implode(' ', Rec::$counts), "\n";
?>
--EXPECT--
-- one row of 200,003 bytes, default limits
1
65536 65536 131068 200007
-- short reads of 20,000 bytes and maxRowBytes 100000: requests are clamped to B - 64 - fill
row 1 (line 2, offset 4): row exceeds maxRowBytes
65536 65536 65536 65536 79996 65542 45542 25542
