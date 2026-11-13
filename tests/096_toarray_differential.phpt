--TEST--
batchflow: toArray agrees with the independent byte-at-a-time reference (not fgetcsv) on 150 seeded random inputs, valid and corrupted, with BOM, CRLF, quoted newlines and extra columns
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

mt_srand(4711);

function pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

function quote(string $s, bool $force): string
{
    return ($force || strpbrk($s, ",\"\r\n") !== false) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

/** One random scenario: [csv, schema, preds, opts, maxRows]. */
function scenario(int $n): array
{
    $S = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string', 't' => 'string'];
    $names = array_keys($S);
    $extra = mt_rand(0, 3) === 0;
    $header = $names;
    if ($extra) {
        array_splice($header, mt_rand(0, 5), 0, [pick(['junk', '', 'i2'])]);
        $header[] = 'tail';
    }
    shuffle_det($header);
    $eol = pick(["\n", "\r\n"]);
    $csv = (mt_rand(0, 9) === 0 ? "\xEF\xBB\xBF" : '') . implode(',', array_map(fn($h) => quote($h, mt_rand(0, 5) === 0), $header)) . $eol;
    $rows = mt_rand(0, 40);
    $corrupt = $n % 4 === 0 && $rows > 0 ? mt_rand(0, $rows - 1) : -1;
    for ($r = 0; $r < $rows; $r++) {
        $cell = [
            'i' => mt_rand(0, 8) === 0 ? '' : pick(['0', '-1', '42', '9223372036854775807', '-9223372036854775808', (string)mt_rand(-999, 999)]),
            'f' => mt_rand(0, 8) === 0 ? '' : pick(['0', '-0.0', '1.5', '1e3', '.25', '5.', '1e-400', '123456789.123456789', sprintf('%.3f', mt_rand(-99999, 99999) / 1000)]),
            'b' => mt_rand(0, 8) === 0 ? '' : pick(['true', 'false']),
            's' => mt_rand(0, 6) === 0 ? '' : quote(pick(['a', 'é', '日本', 'x,y', "l\nf", "c\r\nl", 'q"t', ' sp ', '007', "n\0n"]), mt_rand(0, 4) === 0),
            't' => mt_rand(0, 6) === 0 ? '' : quote(pick(['', 'z', 'zz zz', '"']), mt_rand(0, 3) === 0),
        ];
        if ($r === $corrupt) {
            $cell[pick($names)] = pick(['zz"', '1.0', '+1', 'TRUE', "\xFF", '"x"y']);
        }
        $line = [];
        foreach ($header as $h) {
            $line[] = $cell[$h] ?? pick(['', 'e', "\xFF\xFF", '"q"']);
        }
        if ($n % 13 === 0 && $r === 1) {
            array_pop($line);
        }
        $csv .= implode(',', $line) . $eol;
    }
    if (mt_rand(0, 3) === 0) {
        $csv = rtrim($csv, "\r\n");
    }
    $preds = [];
    for ($p = mt_rand(0, 2); $p > 0; $p--) {
        $preds[] = pick([['eq', 'b', true], ['eq', 'b', false], ['gt', 'i', 0], ['gt', 'f', 1.0], ['eq', 's', 'a'], ['eq', 't', null], ['eq', 'i', 42]]);
    }
    return [$csv, $S, $preds, $extra ? ['allowExtraColumns' => true] : [], pick([10000, 10000, 10000, 1, 2, 5, 20])];
}

/** Deterministic Fisher-Yates on mt_rand (shuffle() is seeded the same way, but this does not rely on it). */
function shuffle_det(array &$a): void
{
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
    }
}

$diffs = [];
$ok = $err = $empty = $limit = $rowsTotal = 0;
for ($n = 1; $n <= 150; $n++) {
    [$csv, $S, $preds, $opts, $max] = scenario($n);
    $x = ['maxRows' => $max];
    $a = bf_gexec($csv, $S, $opts, $preds, 'toArray', $x);
    $b = bf_gref($csv, $S, $opts, $preds, 'toArray', $x);
    if ($a !== $b) {
        $diffs[] = "#$n ext=[" . str_replace("\n", ' | ', $a) . '] ref=[' . str_replace("\n", ' | ', $b) . ']';
    } elseif (str_contains($a, 'ResultTooLargeException')) {
        $limit++;
    } elseif (preg_match('/^\w+Exception: /', $a)) {
        $err++;
    } elseif ($a === '[]') {
        $empty++;
    } else {
        $ok++;
        $rowsTotal += substr_count($a, "\n") + 1;
    }
}
echo 'differences: ', count($diffs), "\n";
foreach (array_slice($diffs, 0, 3) as $d) {
    echo $d, "\n";
}
echo 'coverage: successes>=40: ', $ok >= 40 ? 'yes' : "NO ($ok)", ', errors>=15: ', $err >= 15 ? 'yes' : "NO ($err)", ', maxRows refusals>=5: ', $limit >= 5 ? 'yes' : "NO ($limit)", ', empty>=3: ', $empty >= 3 ? 'yes' : "NO ($empty)", ', rows compared>=300: ', $rowsTotal >= 300 ? 'yes' : "NO ($rowsTotal)", "\n";
?>
--EXPECT--
differences: 0
coverage: successes>=40: yes, errors>=15: yes, maxRows refusals>=5: yes, empty>=3: yes, rows compared>=300: yes
