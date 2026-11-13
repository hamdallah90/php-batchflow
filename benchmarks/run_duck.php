<?php
// run_duck.php <impl> <workload> <fixture> <threads>  -- the DuckDB CLI on one workload, one JSON line.
// impl: duckdb, duckdb_1t, duckdb_forced, duckdb_forced_1t (forced: the idiomatic query plus a COUNT of every
// unreferenced column). Wall is the CLI process alone; CPU and peak RSS are the child's (getrusage RUSAGE_CHILDREN).
declare(strict_types=1);
require __DIR__ . '/lib.php';

[, $impl, $workload, $fixture, $threads] = $argv;
$spec = bf2_spec($workload);
$sql = bf2_duckdb_sql($spec, $fixture, str_starts_with($impl, 'duckdb_forced'));
$out = tempnam(sys_get_temp_dir(), 'duck');
$ru0 = getrusage(1);
$t0 = hrtime(true);
$p = proc_open(['duckdb', '-noheader', '-csv', '-c', "SET threads=$threads; $sql"], [1 => ['file', $out, 'w'], 2 => ['pipe', 'w']], $pipes);
$err = stream_get_contents($pipes[2]);
$rc = proc_close($p);
$t1 = hrtime(true);
$ru1 = getrusage(1);
if ($rc !== 0) {
    fwrite(STDERR, "duckdb failed: $err\n");
    exit(5);
}
$cpu = (($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) + ($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec'])) * 1000000000
    + (($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec'])) * 1000;
$lines = file($out, FILE_IGNORE_NEW_LINES);
unlink($out);
if ($spec['kind'] === 'scan') {
    [$sum, $count] = str_getcsv(end($lines), ',', '"', '');
    $count = (int)$count;
    $total = (float)$sum;
    $ngroups = -1;
} else {
    $total = 0.0;
    $count = 0;
    $ngroups = 0;
    foreach ($lines as $line) {
        if ($line === '') { continue; }
        $f = str_getcsv($line, ',', '"', '');
        $count += (int)$f[1];
        $total += (float)$f[2];
        $ngroups++;
    }
}
echo json_encode([
    'impl' => $impl, 'mode' => 'na', 'workload' => $workload, 'php' => PHP_VERSION, 'zts' => (bool)PHP_ZTS, 'arch' => php_uname('m'),
    'count' => $count, 'digest' => null, 'sum_total' => $total, 'ngroups' => $ngroups, 'outrows' => -1, 'threads' => (int)$threads,
    'fresh' => ['wall_ns' => $t1 - $t0, 'cpu_ns' => $cpu, 'proc_wall_ns' => $t1 - $t0, 'proc_cpu_ns' => $cpu,
        'maxrss_kb' => (int)$ru1['ru_maxrss'], 'peak_php' => 0, 'load_ns' => 0, 'query_ns' => 0],
    'warm' => [],
], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
