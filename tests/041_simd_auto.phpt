--TEST--
batchflow: simd => auto picks the best available ISA, scalar always works, an unavailable ISA raises SchemaException
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\{Csv, Runtime};

$S = ['a' => 'int64'];
$info = Runtime::info()['simd'];
$m = php_uname('m');
// Oracle independent of the extension: /proc/cpuinfo and the machine name.
$best = bf_cpu_has_avx2() ? 'avx2' : (($m === 'aarch64' || $m === 'arm64') ? 'neon' : 'scalar');
echo 'auto is the best available ISA: ', $info['auto'] === $best ? 'yes' : "NO ({$info['auto']}, expected $best)", "\n";
echo 'auto is in the compiled list: ', in_array($info['auto'], $info['compiled'], true) ? 'yes' : 'NO', "\n";

echo "-- explicit values\n";
$csv = "a\n1\n2\n3\n";
$ref = bf_ref($csv, $S, [], [], 'sum', 'a');
foreach (['auto', 'scalar', 'avx2', 'neon'] as $v) {
    $available = $v === 'auto' || $v === 'scalar' || in_array($v, bf_simd_available(), true);
    $r = bf_try(fn() => Csv::scan(bf_file($csv), $S, ['simd' => $v])->sum('a'));
    if ($available) {
        echo str_pad($v, 8), $r === $ref ? "ok" : "WRONG: $r (expected $ref)", "\n";
    } else {
        echo str_pad($v, 8), $r === 'SchemaException: option "simd": ' . $v . ' is not available on this CPU' ? 'ok' : "WRONG: $r", "\n";
    }
}
echo "-- the failure happens at scan(), before any I/O\n";
$late = 0;
foreach (['avx2', 'neon'] as $v) {
    if (!in_array($v, bf_simd_available(), true)) {
        $late += str_starts_with(bf_try(fn() => Csv::scan('/nonexistent/batchflow/none.csv', $S, ['simd' => $v])), 'SchemaException: option "simd": ') ? 0 : 1;
    }
}
echo "unavailable ISAs raised late: $late\n";
echo "-- auto never fails and equals the best explicit value\n";
$e = Csv::scan(bf_file($csv), $S, ['simd' => $best])->sum('a');
$a = Csv::scan(bf_file($csv), $S, ['simd' => 'auto'])->sum('a');
var_dump($a === $e, $a);
echo "-- unrecognized strings are out of range, not unavailable\n";
foreach (['sse4', 'AUTO', 'Neon'] as $v) {
    echo str_pad($v, 8), bf_try(fn() => Csv::scan('/x', $S, ['simd' => $v])), "\n";
}
?>
--EXPECT--
auto is the best available ISA: yes
auto is in the compiled list: yes
-- explicit values
auto    ok
scalar  ok
avx2    ok
neon    ok
-- the failure happens at scan(), before any I/O
unavailable ISAs raised late: 0
-- auto never fails and equals the best explicit value
bool(true)
int(6)
-- unrecognized strings are out of range, not unavailable
sse4    SchemaException: option "simd": out of range
AUTO    SchemaException: option "simd": out of range
Neon    SchemaException: option "simd": out of range
