--TEST--
batchflow: Runtime::info() shape, compiled SIMD list contains scalar, auto is one of the compiled
--EXTENSIONS--
batchflow
--FILE--
<?php
use BatchFlow\Runtime;

$i = Runtime::info();
$k = array_keys($i);
sort($k);
echo implode(',', $k), "\n";
var_dump(is_string($i['version']) && $i['version'] !== '');
$s = $i['simd'];
$k = array_keys($s);
sort($k);
echo implode(',', $k), "\n";
var_dump(array_is_list($s['compiled']));
var_dump(count($s['compiled']) === count(array_unique($s['compiled'])));
var_dump(array_diff($s['compiled'], ['scalar', 'avx2', 'neon']) === []);
var_dump(in_array('scalar', $s['compiled'], true));
var_dump(is_string($s['auto']));
var_dump(in_array($s['auto'], $s['compiled'], true));
var_dump(Runtime::info() === $i);
echo "-- architecture\n";
$m = php_uname('m');
$c = $s['compiled'];
if ($m === 'x86_64') {
    echo in_array('avx2', $c, true) && !in_array('neon', $c, true) ? "ok\n" : "WRONG\n";
} elseif ($m === 'aarch64' || $m === 'arm64') {
    echo in_array('neon', $c, true) && !in_array('avx2', $c, true) ? "ok\n" : "WRONG\n";
} else {
    echo "ok\n";
}
?>
--EXPECT--
simd,version
bool(true)
auto,compiled
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
-- architecture
ok
