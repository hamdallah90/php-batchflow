--TEST--
batchflow: maxGroupBytes bounds native group state by capacity (slots, group arrays, key arena), also under memory_limit=-1
--EXTENSIONS--
batchflow
--INI--
memory_limit=-1
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

const LIM = 1048576; // the smallest allowed maxGroupBytes

/** "Class: message" with the row context replaced by its row number, and that number. */
function split(string $r): array
{
    if (preg_match('/^(\w+): row (\d+) \(line (\d+), offset (\d+)\): (.*)$/s', $r, $m)) {
        return [$m[1], (int)$m[2], (int)$m[3], (int)$m[4], $m[5]];
    }
    return [$r, 0, 0, 0, ''];
}

function ints(int $n): string
{
    $s = "k\n";
    for ($i = 1; $i <= $n; $i++) {
        $s .= "$i\n";
    }
    return $s;
}

$I = ['k' => 'int64'];
echo "-- the default maxGroupBytes is far from reached by a few thousand groups\n";
echo bf_try(fn() => count(bf_q(ints(5000), $I)->groupCount('k'))), "\n";

echo "-- the smallest allowed limit still holds 1,000 groups (state is well under 1 MiB)\n";
echo bf_try(fn() => count(bf_q(ints(1000), $I, ['maxGroupBytes' => LIM])->groupCount('k'))), "\n";

echo "-- 100,000 distinct int keys need more than 1 MiB of slots alone: LimitException at a row between\n";
[$cls, $row, $line, $off, $why] = split(bf_try(fn() => bf_q(ints(100000), $I, ['maxGroupBytes' => LIM])->groupCount('k')));
echo "$cls | $why\n";
echo 'row after 1000 and before 100000: ', ($row > 1000 && $row < 100000) ? 'yes' : "NO ($row)", "\n";
echo 'line = row + 1 and offset matches the file: ', ($line === $row + 1 && $off === strlen(ints($row - 1))) ? 'yes' : "NO ($line, $off)", "\n";
$failAt1 = $row;

// SPEC 6.4 capacities, int keys: slots 8 bytes each, from 1024 doubling at load 3/4; groupCount arrays
// 24 bytes per group (hash, key, count), from 256 doubling. At 24,576 groups: 32,768 slots + 32,768 entries
// = 262,144 + 786,432 = exactly 1,048,576, which is allowed; group 24,577 needs 65,536 slots.
echo 'fails at exactly row 24577: ', $row === 24577 ? 'yes' : "NO ($row)", "\n";
echo "-- the state may equal the limit: 24,576 groups fit exactly\n";
echo bf_try(fn() => count(bf_q(ints(24576), $I, ['maxGroupBytes' => LIM])->groupCount('k'))), "\n";
echo "-- the NULL group is not in the slots, so it does not count toward their load: NULL first, then 24,576 keys fit\n";
// The 24,577th key (row 24,578) is the one that needs 65,536 slots; counting the NULL group would move it one row up.
$s = "k,v\n,1\n";
for ($i = 1; $i <= 24577; $i++) {
    $s .= "$i,1\n";
}
$IV = ['k' => 'int64', 'v' => 'int64'];
echo bf_try(fn() => count(bf_q(substr($s, 0, strrpos($s, "24577,")), $IV, ['maxGroupBytes' => LIM])->groupCount('k'))), "\n";
echo split(bf_try(fn() => bf_q($s, $IV, ['maxGroupBytes' => LIM])->groupCount('k')))[1], "\n";
unset($s);
echo "-- group arrays clamp to maxGroups: 20,000 groupSum groups (32 bytes each) fit only with the clamp\n";
// 20,000 groups: 32,768 slots (262,144) + arrays clamped to 20,000 entries (640,000) = 902,144 <= 1 MiB.
// Unclamped, the arrays would be 32,768 entries (1,048,576) and fail at group 16,385 (16,385 > 16,384 entries).
$s = "k,v\n";
for ($i = 1; $i <= 20000; $i++) {
    $s .= "$i,1\n";
}
echo bf_try(fn() => count(bf_q($s, $IV, ['maxGroupBytes' => LIM])->groupSum('k', 'v', 20000))), "\n";
echo split(bf_try(fn() => bf_q($s, $IV, ['maxGroupBytes' => LIM])->groupSum('k', 'v')))[1], "\n";
unset($s);

echo "-- the same input under a larger maxGroupBytes succeeds, so the limit is what stopped it\n";
echo bf_try(fn() => count(bf_q(ints(100000), $I, ['maxGroupBytes' => 67108864])->groupCount('k'))), "\n";
echo "-- the failing row grows with the limit\n";
[, $row2] = split(bf_try(fn() => bf_q(ints(100000), $I, ['maxGroupBytes' => 4 * LIM])->groupCount('k')));
echo 'row at 4 MiB is after row at 1 MiB: ', ($row2 === 0 || $row2 > $failAt1) ? 'yes' : "NO ($row2 vs $failAt1)", "\n";
echo "-- groupSum is bounded the same way\n";
$s = "k,v\n";
for ($i = 1; $i <= 100000; $i++) {
    $s .= "$i,1\n";
}
[$cls, $row, , , $why] = split(bf_try(fn() => bf_q($s, ['k' => 'int64', 'v' => 'int64'], ['maxGroupBytes' => LIM])->groupSum('k', 'v')));
echo "$cls | $why | row > 1000: ", $row > 1000 ? 'yes' : 'NO', "\n";
unset($s);

echo "-- a string key arena counts: one 500,000-byte key fits, two do not (arena would be 1,048,576 bytes)\n";
$K1 = str_repeat('a', 500000);
$K2 = str_repeat('b', 500000);
echo bf_try(fn() => count(bf_q("k\n$K1\n", ['k' => 'string'], ['maxGroupBytes' => LIM])->groupCount('k'))), "\n";
echo bf_try(fn() => count(bf_q("k\n$K1\n$K1\n", ['k' => 'string'], ['maxGroupBytes' => LIM])->groupCount('k'))), "\n";
echo bf_try(fn() => bf_q("k\n$K1\n$K2\n", ['k' => 'string'], ['maxGroupBytes' => LIM])->groupCount('k')), "\n";
echo bf_try(fn() => count(bf_q("k\n$K1\n$K2\n", ['k' => 'string'], ['maxGroupBytes' => 4 * LIM])->groupCount('k'))), "\n";

echo "-- keys at maxFieldBytes: a 1,048,576-byte key cannot fit a 1 MiB group state, fits a larger one\n";
$BIG = str_repeat('x', 1048576);
echo bf_try(fn() => bf_q("k\n$BIG\n", ['k' => 'string'], ['maxGroupBytes' => LIM])->groupCount('k')), "\n";
$r = bf_q("k\n$BIG\n$BIG\n", ['k' => 'string'], ['maxGroupBytes' => 16 * LIM])->groupCount('k');
echo count($r), ' group, count ', $r[0][1], ', key length ', strlen($r[0][0]), ', key intact: ', $r[0][0] === $BIG ? 'yes' : 'NO', "\n";
echo "-- a key of exactly 1,048,576 bytes takes an arena of exactly that size: 8,192 slot bytes + 256 * 28 array bytes\n";
echo "   + 1,048,576 = 1,063,936 bytes fits a limit of 1,063,936 and not one byte less\n";
echo bf_try(fn() => count(bf_q("k\n$BIG\n", ['k' => 'string'], ['maxGroupBytes' => 1063936])->groupCount('k'))), "\n";
echo bf_try(fn() => bf_q("k\n$BIG\n", ['k' => 'string'], ['maxGroupBytes' => 1063935])->groupCount('k')), "\n";
echo "-- one byte over maxFieldBytes is the field limit, not the group limit\n";
echo bf_try(fn() => bf_q("k\n" . $BIG . "y\n", ['k' => 'string'], ['maxGroupBytes' => 16 * LIM])->groupCount('k')), "\n";
unset($K1, $K2, $BIG);

echo "-- the bound holds with memory_limit=-1 and after a refusal the process is usable\n";
echo 'memory_limit: ', ini_get('memory_limit'), "\n";
echo bf_try(fn() => count(bf_q(ints(10), $I, ['maxGroupBytes' => LIM])->groupCount('k'))), "\n";
echo "-- the bound is per run: a second run after a refusal starts clean\n";
$q = bf_q(ints(100000), $I, ['maxGroupBytes' => LIM]);
echo split(bf_try(fn() => $q->groupCount('k')))[4], ' / ', split(bf_try(fn() => $q->groupCount('k')))[4], "\n";
?>
--EXPECT--
-- the default maxGroupBytes is far from reached by a few thousand groups
int(5000)
-- the smallest allowed limit still holds 1,000 groups (state is well under 1 MiB)
int(1000)
-- 100,000 distinct int keys need more than 1 MiB of slots alone: LimitException at a row between
LimitException | group state exceeds maxGroupBytes
row after 1000 and before 100000: yes
line = row + 1 and offset matches the file: yes
fails at exactly row 24577: yes
-- the state may equal the limit: 24,576 groups fit exactly
int(24576)
-- the NULL group is not in the slots, so it does not count toward their load: NULL first, then 24,576 keys fit
int(24577)
24578
-- group arrays clamp to maxGroups: 20,000 groupSum groups (32 bytes each) fit only with the clamp
int(20000)
16385
-- the same input under a larger maxGroupBytes succeeds, so the limit is what stopped it
int(100000)
-- the failing row grows with the limit
row at 4 MiB is after row at 1 MiB: yes
-- groupSum is bounded the same way
LimitException | group state exceeds maxGroupBytes | row > 1000: yes
-- a string key arena counts: one 500,000-byte key fits, two do not (arena would be 1,048,576 bytes)
int(1)
int(1)
LimitException: row 2 (line 3, offset 500003): group state exceeds maxGroupBytes
int(2)
-- keys at maxFieldBytes: a 1,048,576-byte key cannot fit a 1 MiB group state, fits a larger one
LimitException: row 1 (line 2, offset 2): group state exceeds maxGroupBytes
1 group, count 2, key length 1048576, key intact: yes
-- a key of exactly 1,048,576 bytes takes an arena of exactly that size: 8,192 slot bytes + 256 * 28 array bytes
   + 1,048,576 = 1,063,936 bytes fits a limit of 1,063,936 and not one byte less
int(1)
LimitException: row 1 (line 2, offset 2): group state exceeds maxGroupBytes
-- one byte over maxFieldBytes is the field limit, not the group limit
LimitException: row 1 (line 2, offset 2): column "k": field exceeds maxFieldBytes
-- the bound holds with memory_limit=-1 and after a refusal the process is usable
memory_limit: -1
int(10)
-- the bound is per run: a second run after a refusal starts clean
group state exceeds maxGroupBytes / group state exceeds maxGroupBytes
