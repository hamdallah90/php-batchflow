<?php
// gen.php <numeric|mixed|mixnq|wide> <count> <seed> <outpath>  -- deterministic CSV fixture.
// One file per shape+size. Selectivity and group-key cardinality come from predicate constants and
// from which key column a query names, not from different data.
//   numeric  int and float columns only, no quotes anywhere, so fgets+explode is legal
//   mixed    strings with RFC-4180 quoting, doubled quotes and embedded newlines in `note`
//   mixnq    same columns as mixed, but `note` never needs quoting, so fgets+explode is legal
//   wide     30 columns, no quotes anywhere; queries reference only a handful
// Key columns k10/k1k/k100k have uniform random draws from pools of 10/1000/100000 values; 1% are NULL.
declare(strict_types=1);

[, $shape, $count, $seed, $path] = $argv;
$count = (int)$count;
mt_srand((int)$seed);

function amount(): string
{
    if (mt_rand(0, 99) === 0) { return ''; }                          // 1% NULL
    $cents = mt_rand(0, 99999);
    if (mt_rand(0, 19) === 0) { return (string)intdiv($cents, 100); } // 5% integer-looking
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

function status_roll(): int
{
    $r = mt_rand(0, 99);
    return $r < 90 ? 1 : ($r < 98 ? 2 : 3); // paid 90%, pending 8%, refunded 2%
}

/** @return array{0: string[], 1: string[], 2: string[]} */
function string_key_pools(): array
{
    $k10 = ['eu-west-1', 'us-east-1', 'us-west-2', 'ap-south-1', 'ap-northeast-2', 'sa-east-1', 'me-central-1', 'ca-central-1', 'af-south-1', 'eu-north-1'];
    $k1k = [];
    for ($i = 0; $i < 1000; $i++) { $k1k[] = sprintf('sku-%04d-%s', $i, substr(md5('sku' . $i), 0, 8)); }
    $k100k = [];
    for ($i = 0; $i < 100000; $i++) { $k100k[] = 'user' . $i . '.' . substr(md5('u' . $i), 0, 10) . '@example.com'; }
    return [$k10, $k1k, $k100k];
}

function key_cell(array $pool, int $card): string
{
    if (mt_rand(0, 99) === 0) { return ''; } // 1% NULL
    return $pool[mt_rand(0, $card - 1)];
}

function int_key_cell(int $base, int $card): string
{
    if (mt_rand(0, 99) === 0) { return ''; } // 1% NULL
    return (string)($base + mt_rand(0, $card - 1));
}

/** @return string[] */
function notes(bool $withQuotes): array
{
    $words = ['order', 'customer', 'latency', 'checkout', 'mobile', 'café', '東京', 'naïve', 'gift', 'renewal', 'promo', 'bundle'];
    $notes = [];
    for ($i = 0; $i < 3000; $i++) {
        $n = mt_rand(1, 8);
        $parts = [];
        for ($k = 0; $k < $n; $k++) { $parts[] = $words[mt_rand(0, count($words) - 1)]; }
        $s = implode(' ', $parts);
        if ($withQuotes) {
            // Roughly 1 in 8 notes needs RFC-4180 quoting, including doubled quotes and embedded newlines.
            switch (mt_rand(0, 23)) {
                case 0: $s = '"' . $s . ', with comma"'; break;
                case 1: $s = '"' . $s . ' said ""hello"""'; break;
                case 2: $s = '"' . $s . "\nsecond line\""; break;
            }
        }
        $notes[] = $s;
    }
    return $notes;
}

$h = fopen($path, 'wb');
$buf = '';
$names = [1 => 'paid', 2 => 'pending', 3 => 'refunded'];
$regions = ['eu-west-1', 'us-east-1', 'ap-south-1', 'sa-east-1', 'me-central-1'];

if ($shape === 'numeric') {
    $buf = "id,ts,status_code,qty,k10,k1k,k100k,amount\n";
    for ($i = 1; $i <= $count; $i++) {
        $buf .= $i . ',' . (1600000000 + mt_rand(0, 100000000)) . ',' . status_roll() . ',' . mt_rand(1, 1000)
            . ',' . int_key_cell(1, 10) . ',' . int_key_cell(1001, 1000) . ',' . int_key_cell(100001, 100000)
            . ',' . amount() . "\n";
        if (strlen($buf) > 1048576) { fwrite($h, $buf); $buf = ''; }
    }
} elseif ($shape === 'mixed' || $shape === 'mixnq') {
    [$k10, $k1k, $k100k] = string_key_pools();
    $notes = notes($shape === 'mixed');
    $maxNote = count($notes) - 1;
    $buf = "id,status,amount,region,active,k10,k1k,k100k,note\n";
    for ($i = 1; $i <= $count; $i++) {
        $region = mt_rand(0, 49) === 0 ? '' : $regions[mt_rand(0, 4)];
        $note = mt_rand(0, 19) === 0 ? '' : $notes[mt_rand(0, $maxNote)];
        $buf .= $i . ',' . $names[status_roll()] . ',' . amount() . ',' . $region . ',' . (mt_rand(0, 1) ? 'true' : 'false')
            . ',' . key_cell($k10, 10) . ',' . key_cell($k1k, 1000) . ',' . key_cell($k100k, 100000) . ',' . $note . "\n";
        if (strlen($buf) > 1048576) { fwrite($h, $buf); $buf = ''; }
    }
} elseif ($shape === 'wide') {
    [$k10, $k1k, $k100k] = string_key_pools();
    $words = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet'];
    $head = ['id', 'ts', 'status', 'amount', 'region', 'active', 'k10', 'k1k', 'k100k'];
    for ($c = 0; $c < 21; $c++) { $head[] = sprintf('c%02d', $c); }
    $buf = implode(',', $head) . "\n";
    for ($i = 1; $i <= $count; $i++) {
        $region = mt_rand(0, 49) === 0 ? '' : $regions[mt_rand(0, 4)];
        $row = $i . ',' . (1600000000 + mt_rand(0, 100000000)) . ',' . $names[status_roll()] . ',' . amount() . ',' . $region
            . ',' . (mt_rand(0, 1) ? 'true' : 'false') . ',' . key_cell($k10, 10) . ',' . key_cell($k1k, 1000) . ',' . key_cell($k100k, 100000);
        for ($c = 0; $c < 21; $c++) {
            switch ($c % 4) {
                case 0: $row .= ',' . mt_rand(1, 2000000000); break;
                case 1: $row .= ',' . intdiv(mt_rand(0, 999999), 100) . '.' . sprintf('%02d', mt_rand(0, 99)); break;
                case 2: $row .= ',' . $words[mt_rand(0, 9)]; break;
                case 3: $row .= ',' . (mt_rand(0, 1) ? 'true' : 'false'); break;
            }
        }
        $buf .= $row . "\n";
        if (strlen($buf) > 1048576) { fwrite($h, $buf); $buf = ''; }
    }
} else {
    fwrite(STDERR, "unknown shape $shape\n");
    exit(2);
}
fwrite($h, $buf);
fclose($h);
