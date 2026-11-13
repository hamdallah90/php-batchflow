--TEST--
batchflow: every available simd value gives identical results over fixtures that put each structural byte at every offset of a 64-byte block
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$modes = array_merge(['auto'], bf_simd_available());
echo 'modes under test include scalar: ', in_array('scalar', $modes, true) ? 'yes' : 'NO', "\n";

$S = ['a' => 'string', 'b' => 'int64'];
$fixtures = [];
// A structural byte (delimiter, LF, CR LF, quote pair, bare CR, bare quote) at every offset 0..130 of the
// first data row, which starts at byte 4 ("a,b\n"); the padding is the first field.
for ($pos = 0; $pos <= 130; $pos++) {
    $pad = str_repeat('x', $pos);
    $fixtures["delimiter at $pos"] = "a,b\n{$pad},1\n{$pad}y,2\n";
    $fixtures["quoted delimiter at $pos"] = "a,b\n\"{$pad},z\",1\nq,2\n";
    $fixtures["quoted LF at $pos"] = "a,b\n\"{$pad}\nz\",1\nq,2\n";
    $fixtures["quoted CRLF at $pos"] = "a,b\n\"{$pad}\r\nz\",1\nq,2\n";
    $fixtures["doubled quote at $pos"] = "a,b\n\"{$pad}\"\"z\",1\nq,2\n";
    $fixtures["CRLF terminator at $pos"] = "a,b\r\n{$pad},1\r\n{$pad},2\r\n";
    $fixtures["bare CR at $pos"] = "a,b\n{$pad}\rz,1\n";
    $fixtures["bare quote at $pos"] = "a,b\n{$pad}\"z,1\n";
    $fixtures["closing quote then text at $pos"] = "a,b\n\"{$pad}\"z,1\n";
    $fixtures["unterminated quote at $pos"] = "a,b\n\"{$pad}";
    $fixtures["short row at $pos"] = "a,b\n{$pad}\n";
    $fixtures["long row at $pos"] = "a,b\n{$pad},1,2\n";
    $fixtures["lone CR at end, offset $pos"] = "a,b\n{$pad},1\r";
}
// the same structure, many rows, header padded so rows straddle blocks differently
for ($h = 0; $h < 64; $h++) {
    $name = 'h' . str_repeat('n', $h);
    $rows = '';
    for ($r = 0; $r < 12; $r++) {
        $rows .= str_repeat('v', $r * 5) . ",$r\n\"q,$r\",$r\n";
    }
    $fixtures["many rows, header pad $h"] = "$name,b\n$rows";
}

$queries = [
    ['count', [], null], ['sum', [], 'b'], ['summary', [['eq', 'a', 'y']], 'b'],
];
$total = 0;
$bad = [];
foreach ($fixtures as $label => $csv) {
    $schema = str_starts_with($label, 'many rows') ? [explode(',', $csv, 2)[0] => 'string', 'b' => 'int64'] : $S;
    foreach ($queries as [$term, $preds, $col]) {
        $preds = str_starts_with($label, 'many rows') ? [] : $preds;
        $want = bf_ref($csv, $schema, [], $preds, $term, $col);
        foreach ($modes as $m) {
            $total++;
            if (bf_exec($csv, $schema, ['simd' => $m], $preds, $term, $col) !== $want) {
                $bad[] = "$label / $term / $m";
            }
        }
    }
}
echo 'checks: ', $total > 0 ? 'ran' : 'NONE', "\n";
echo 'disagreements with the reference: ', count($bad), "\n";
foreach (array_slice($bad, 0, 10) as $b) {
    echo "  $b\n";
}

echo "-- byte-identical error messages across modes for one fixed bad input per class\n";
$errs = [
    "a,b\n" . str_repeat('x', 61) . "\"z,1\n",
    "a,b\n\"" . str_repeat('x', 62) . "\"z,1\n",
    "a,b\n" . str_repeat('x', 62) . "\rz,1\n",
    "a,b\n1,2,3\n",
];
foreach ($errs as $i => $csv) {
    $r = [];
    foreach ($modes as $m) {
        $r[bf_exec($csv, $S, ['simd' => $m])] = 1;
    }
    echo "error fixture $i: ", count($r) === 1 ? 'identical in all modes: ' . array_key_first($r) : 'DIFFERENT', "\n";
}
?>
--EXPECT--
modes under test include scalar: yes
checks: ran
disagreements with the reference: 0
-- byte-identical error messages across modes for one fixed bad input per class
error fixture 0: identical in all modes: CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
error fixture 1: identical in all modes: CsvException: row 1 (line 2, offset 4): text after closing quote
error fixture 2: identical in all modes: CsvException: row 1 (line 2, offset 4): bare CR
error fixture 3: identical in all modes: CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
