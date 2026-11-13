--TEST--
batchflow: integer-like column names use PHP symtable keys, in schema order, with correct next-free-key behaviour and independent rows
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

function dump(string $label, array $rows): void
{
    echo "-- $label\n";
    foreach ($rows as $row) {
        $p = [];
        foreach ($row as $k => $v) {
            $p[] = (is_int($k) ? 'int' : 'str') . '(' . $k . ')=' . bf_val($v);
        }
        echo '{', implode(', ', $p), "}\n";
    }
}

dump('names 0,1,2 in order', bf_q("0,1,2\n10,x,true\n20,y,false\n", [0 => 'int64', 1 => 'string', 2 => 'bool'])->toArray());
dump('names 2,0,1: schema order, not numeric order', bf_q("0,1,2\n10,x,true\n", [2 => 'bool', 0 => 'int64', 1 => 'string'])->toArray());
dump('names 1,0 reversed, file order differs', bf_q("0,1\na,b\n", ['1' => 'string', '0' => 'string'])->toArray());
dump('a single column named 0', bf_q("0\n5\n6\n", [0 => 'int64'])->toArray());
dump('2024 next to a word', bf_q("2024,name\n1.5,x\n", ['2024' => 'float64', 'name' => 'string'])->toArray());
dump('2024 as the second key, file order reversed', bf_q("name,2024\nx,1.5\n", ['name' => 'string', '2024' => 'float64'])->toArray());
dump('negative, big and non-canonical names', bf_q("-5,007,9223372036854775807,9223372036854775808,1.5, 1,+1\n1,2,3,4,5,6,7\n", [
    '-5' => 'int64', '007' => 'int64', '9223372036854775807' => 'int64', '9223372036854775808' => 'int64', '1.5' => 'int64', ' 1' => 'int64', '+1' => 'int64',
])->toArray());

echo "-- lookups work with both int and string forms\n";
$r = bf_q("0,1,2\n10,x,true\n", [0 => 'int64', 1 => 'string', 2 => 'bool'])->toArray();
var_dump($r[0][0] === 10, $r[0]['0'] === 10, $r[0][1] === 'x', $r[0]['1'] === 'x', $r[0][2] === true, $r[0]['2'] === true);
$r = bf_q("2024\n1.5\n", ['2024' => 'float64'])->toArray();
var_dump($r[0][2024] === 1.5, $r[0]['2024'] === 1.5, isset($r[0]['2024']), array_key_exists(2024, $r[0]));
echo "-- the row is a normal hash: append uses the next free integer key\n";
$r = bf_q("0,1,2\n10,x,true\n", [0 => 'int64', 1 => 'string', 2 => 'bool'])->toArray();
$r[0][] = 'tail';
echo implode(',', array_keys($r[0])), "\n";
$r = bf_q("2024\n1.5\n", ['2024' => 'float64'])->toArray();
$r[0][] = 'tail';
echo implode(',', array_keys($r[0])), "\n";
echo "-- rows are independent: changing one leaves the next untouched\n";
$r = bf_q("0,1,2\n10,x,true\n20,y,false\n30,z,true\n", [0 => 'int64', 1 => 'string', 2 => 'bool'])->toArray();
$r[0][0] = 99;
$r[0][1] .= '!';
unset($r[0][2]);
$r[0][] = 'tail';
echo json_encode($r), "\n";
echo "-- the same with the same string value repeated in every row\n";
$r = bf_q("0,1\nsame,same\nsame,same\n", [0 => 'string', 1 => 'string'])->toArray();
$r[1][0] = 'changed';
echo json_encode($r), "\n";
echo "-- the comparison with PHP's own symtable\n";
$want = [];
foreach (['5' => 1, 'a' => 2, '-3' => 3, '007' => 4] as $k => $v) {
    $want[$k] = $v;
}
$r = bf_q("5,a,-3,007\n1,2,3,4\n", ['5' => 'int64', 'a' => 'int64', '-3' => 'int64', '007' => 'int64'])->toArray();
var_dump($r[0] === $want);
var_dump(array_keys($r[0]) === array_keys($want));
?>
--EXPECT--
-- names 0,1,2 in order
{int(0)=int(10), int(1)=s"x", int(2)=bool(true)}
{int(0)=int(20), int(1)=s"y", int(2)=bool(false)}
-- names 2,0,1: schema order, not numeric order
{int(2)=bool(true), int(0)=int(10), int(1)=s"x"}
-- names 1,0 reversed, file order differs
{int(1)=s"b", int(0)=s"a"}
-- a single column named 0
{int(0)=int(5)}
{int(0)=int(6)}
-- 2024 next to a word
{int(2024)=float(1.5), str(name)=s"x"}
-- 2024 as the second key, file order reversed
{str(name)=s"x", int(2024)=float(1.5)}
-- negative, big and non-canonical names
{int(-5)=int(1), str(007)=int(2), int(9223372036854775807)=int(3), str(9223372036854775808)=int(4), str(1.5)=int(5), str( 1)=int(6), str(+1)=int(7)}
-- lookups work with both int and string forms
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
-- the row is a normal hash: append uses the next free integer key
0,1,2,3
2024,2025
-- rows are independent: changing one leaves the next untouched
[{"0":99,"1":"x!","3":"tail"},[20,"y",false],[30,"z",true]]
-- the same with the same string value repeated in every row
[["same","same"],["changed","same"]]
-- the comparison with PHP's own symtable
bool(true)
bool(true)
