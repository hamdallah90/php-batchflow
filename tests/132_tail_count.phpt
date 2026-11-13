--TEST--
batchflow: past the last referenced column the delimiters of a row are counted by popcount; results and error text equal the reference and the scalar path for quotes, CR, LF, short and long rows at every block offset
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$modes = bf_simd_available();
echo 'modes under test include scalar: ', in_array('scalar', $modes, true) ? 'yes' : 'NO', "\n";

$ncols = 40;
$header = implode(',', array_map(fn ($i) => "c$i", range(0, $ncols - 1))) . "\n";
// Only c0 and c2 are referenced: columns 3 to 39 are the tail, 37 fields, above the popcount threshold.
$schema = ['c0' => 'int64', 'c2' => 'string'];
$opts = ['allowExtraColumns' => true];

function wide_row(int $i, array $tail): string
{
    $f = [$i, 'k' . ($i % 3), 'c2v'];
    return implode(',', array_merge($f, $tail));
}

function tails(string $kind, int $pad, int $ncols): array
{
    $t = [];
    for ($k = 3; $k < $ncols; $k++) {
        $t[] = $k === 20 ? str_repeat('p', $pad) : 'v' . $k;
    }
    switch ($kind) {
        case 'plain':
            break;
        case 'quoted delimiter':
            $t[10] = '"a,' . str_repeat('b', $pad) . ',c"';
            break;
        case 'quoted newline':
            $t[10] = "\"a\n" . str_repeat('b', $pad) . "\nc\"";
            break;
        case 'doubled quote':
            $t[10] = '"a""' . str_repeat('b', $pad) . '"';
            break;
        case 'bare quote':
            $t[30] = 'a"' . str_repeat('b', $pad);
            break;
        case 'text after quote':
            $t[30] = '"a"' . str_repeat('b', $pad + 1);
            break;
        case 'bare CR':
            $t[25] = 'a' . "\r" . str_repeat('b', $pad);
            break;
        case 'too few':
            array_splice($t, 15 + ($pad % 20), 1);
            break;
        case 'too many':
            $t[] = 'x' . str_repeat('y', $pad);
            break;
        case 'quoted last':
            $t[count($t) - 1] = '"q' . str_repeat('z', $pad) . '"';
            break;
        case 'quoted last with newline':
            $t[count($t) - 1] = "\"q\n" . str_repeat('z', $pad) . '"';
            break;
        case 'quoted last then text':
            $t[count($t) - 1] = '"q"' . str_repeat('z', $pad % 4 + 1);
            break;
        case 'empty last':
            $t[count($t) - 1] = '';
            break;
    }
    return $t;
}

$kinds = ['plain', 'quoted delimiter', 'quoted newline', 'doubled quote', 'bare quote', 'text after quote', 'bare CR', 'too few', 'too many', 'quoted last', 'quoted last with newline', 'quoted last then text', 'empty last'];
$queries = [['count', [], null], ['sum', [], 'c0'], ['summary', [['eq', 'c2', 'c2v']], 'c0']];
$total = 0;
$bad = [];
foreach ($kinds as $kind) {
    for ($pad = 0; $pad <= 70; $pad++) {
        foreach (["\n", "\r\n"] as $eol) {
            // a few good rows first, so the bad one sits at different offsets in the buffer; then the row under test, then a good one
            $csv = $header;
            for ($i = 0; $i < 2 + $pad % 3; $i++) {
                $csv .= wide_row($i, tails('plain', $i * 7 % 40, $ncols)) . $eol;
            }
            $csv .= wide_row(9, tails($kind, $pad, $ncols)) . $eol . wide_row(10, tails('plain', 3, $ncols)) . $eol;
            foreach ($queries as [$term, $preds, $col]) {
                $want = bf_ref($csv, $schema, $opts, $preds, $term, $col);
                foreach ($modes as $m) {
                    $total++;
                    if (bf_exec($csv, $schema, $opts + ['simd' => $m], $preds, $term, $col) !== $want) {
                        $bad[] = "$kind pad=$pad eol=" . json_encode($eol) . " $term $m";
                    }
                }
            }
        }
    }
}
echo 'checks: ', $total > 0 ? 'ran' : 'NONE', "\n";
echo 'disagreements: ', count($bad), "\n";
foreach (array_slice($bad, 0, 10) as $b) {
    echo "  $b\n";
}

// A file whose rows end exactly at a block boundary and one that ends in the middle of a tail.
$csv = $header . wide_row(1, tails('plain', 0, $ncols));
for ($cut = 0; $cut <= 70; $cut++) {
    $c = substr($csv, 0, strlen($csv) - $cut);
    $want = bf_ref($c, $schema, $opts);
    foreach ($modes as $m) {
        if (bf_exec($c, $schema, $opts + ['simd' => $m]) !== $want) {
            $bad[] = "cut $cut $m";
        }
    }
}
echo 'cut files disagreements: ', count($bad), "\n";
?>
--EXPECT--
modes under test include scalar: yes
checks: ran
disagreements: 0
cut files disagreements: 0
