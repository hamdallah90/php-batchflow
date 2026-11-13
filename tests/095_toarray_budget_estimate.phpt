--TEST--
batchflow: the row budget's first check falls on the row where the estimate of SPEC 6.4 first reaches 1 MiB: each string at its allocation size (header, terminator, ZendMM bin or whole pages), 0- and 1-byte strings at nothing, and on each row whose insert grows the result list the list's new packed data at twice its capacity
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

use BatchFlow\Csv;

// bf_zmm_size (src/php_run.c): ZendMM's small bins, whole pages up to a chunk less a page, whole chunks above; debug
// builds add ZEND_MM_OVERHEAD, the 32-byte zend_mm_debug_info.
function zmm(int $n): int
{
    static $bins = [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384, 448, 512, 640, 768,
        896, 1024, 1280, 1536, 1792, 2048, 2560, 3072];
    $n = (($n + 7) & ~7) + (PHP_DEBUG ? 32 : 0);
    foreach ($bins as $b) {
        if ($n <= $b) {
            return $b;
        }
    }
    $unit = $n <= 2097152 - 4096 ? 4096 : 2097152;
    return intdiv($n + $unit - 1, $unit) * $unit;
}

// The 1-based row on which the summed estimate first reaches 1 MiB. A row is its zend_array (56 bytes) and the
// template's hash data (8 buckets of 32 bytes and 16 hash slots of 4), each string of 2 or more bytes its zend_string
// (24-byte header, the bytes, a terminator), and, when the list holds as many rows as its capacity, the packed data of
// twice that capacity (16 bytes a slot and 8 bytes of hash).
function first_check(array $rows): int
{
    $base = zmm(56) + zmm(8 * 32 + 16 * 4);
    $cap = 8;
    $sum = 0;
    foreach ($rows as $i => $lens) {
        $e = $base;
        foreach ($lens as $len) {
            $e += $len <= 1 ? 0 : zmm(24 + $len + 1);
        }
        if ($i === $cap) {
            $e += zmm(2 * $cap * 16 + 8);
            $cap *= 2;
        }
        $sum += $e;
        if ($sum >= 1048576) {
            return $i + 1;
        }
    }
    return -1;
}

/*
 * memory_limit is set 3.5 MiB above the real usage before the call. The rows before the first check need at most one
 * new chunk, and the check asks for 1 MiB and more pending plus 2 MiB, rounded up to 4 MiB, more than is left: every
 * first check is refused, so the refused row is the row of the first check.
 */
function refused_row(string $name, string $csv, array $schema, array $rows): void
{
    $p = bf_file($csv);
    gc_mem_caches();
    ini_set('memory_limit', (string)(memory_get_usage(true) + 3670016));
    try {
        $r = Csv::scan($p, $schema)->toArray();
        $got = 'no refusal, ' . count($r) . ' rows';
        unset($r);
    } catch (BatchFlow\LimitException $e) {
        $got = preg_match('/^row (\d+) \(line \d+, offset \d+\): memory_limit would be exceeded$/', $e->getMessage(), $m)
            ? (int)$m[1] : $e->getMessage();
    }
    ini_set('memory_limit', '128M');
    $want = first_check($rows);
    echo "$name: refused on the row where the estimate reaches 1 MiB: ", $got === $want ? 'yes' : "no (got $got, expected $want)", "\n";
}

// One string column, lengths 1 to 600 bytes: bins from 32 to 640 bytes, the 1-byte strings at nothing.
$rows = [];
$csv = "s\n";
for ($i = 0; $i < 4000; $i++) {
    $len = 1 + ($i * 7919) % 600;
    $rows[] = [$len];
    $csv .= str_repeat('a', $len) . "\n";
}
refused_row('one string column', $csv, ['s' => 'string'], $rows);

// An int and two strings; every fifth row's second string is 3,100 bytes, one whole page, against 3,125 raw bytes.
$rows = [];
$csv = "n,s,t\n";
for ($i = 0; $i < 4000; $i++) {
    $ls = 1 + ($i * 31) % 90;
    $lt = $i % 5 === 0 ? 3100 : 2 + $i % 3;
    $rows[] = [$ls, $lt];
    $csv .= $i . ',' . str_repeat('b', $ls) . ',' . str_repeat('c', $lt) . "\n";
}
refused_row('int and two strings', $csv, ['n' => 'int64', 's' => 'string', 't' => 'string'], $rows);

// One int column: no string is charged; the list grows nine times before the first check, the last time to 4,096 slots.
$rows = array_fill(0, 4000, []);
$csv = "n\n";
for ($i = 0; $i < 4000; $i++) {
    $csv .= "$i\n";
}
refused_row('one int column', $csv, ['n' => 'int64'], $rows);
?>
--EXPECT--
one string column: refused on the row where the estimate reaches 1 MiB: yes
int and two strings: refused on the row where the estimate reaches 1 MiB: yes
one int column: refused on the row where the estimate reaches 1 MiB: yes
