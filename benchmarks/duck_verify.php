<?php
// duck_verify.php <workload> <output.csv>  -- reduces DuckDB CLI output to the fields run.sh compares.
declare(strict_types=1);
require __DIR__ . '/lib.php';

[, $workload, $file] = $argv;
$spec = bf2_spec($workload);
$lines = file($file, FILE_IGNORE_NEW_LINES);
if ($lines === false) { fwrite(STDERR, "cannot read $file\n"); exit(4); }

if ($spec['kind'] === 'scan') {
    [$sum, $count] = str_getcsv(end($lines), ',', '"', '');
    printf("count=%d sum_total=%.17g ngroups=-1\n", (int)$count, (float)$sum);
    exit(0);
}
$total = 0.0;
$count = 0;
$groups = 0;
foreach ($lines as $line) {
    if ($line === '') { continue; }
    $f = str_getcsv($line, ',', '"', '');
    $count += (int)$f[1];
    $total += (float)$f[2];
    $groups++;
}
printf("count=%d sum_total=%.17g ngroups=%d\n", $count, $total, $groups);
