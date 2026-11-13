<?php
declare(strict_types=1);

const BF2_MAX_ROWS = 100000000;
const BF2_MAX_FIELD = 1048576;
const BF2_NULL_STR_KEY = "\xFF\xFFNULL"; // String group keys are validated UTF-8, and this is not, so it cannot collide.

// ---- workloads ---------------------------------------------------------------------------------

function bf2_schema(string $shape): array
{
    switch ($shape) {
        case 'numeric':
            return ['id' => 'int64', 'ts' => 'int64', 'status_code' => 'int64', 'qty' => 'int64', 'k10' => 'int64', 'k1k' => 'int64', 'k100k' => 'int64', 'amount' => 'float64'];
        case 'mixed':
        case 'mixnq':
            return ['id' => 'int64', 'status' => 'string', 'amount' => 'float64', 'region' => 'string', 'active' => 'bool', 'k10' => 'string', 'k1k' => 'string', 'k100k' => 'string', 'note' => 'string'];
        case 'wide':
            $s = ['id' => 'int64', 'ts' => 'int64', 'status' => 'string', 'amount' => 'float64', 'region' => 'string', 'active' => 'bool', 'k10' => 'string', 'k1k' => 'string', 'k100k' => 'string'];
            $types = ['int64', 'float64', 'string', 'bool'];
            for ($c = 0; $c < 21; $c++) { $s[sprintf('c%02d', $c)] = $types[$c % 4]; }
            return $s;
    }
    throw new RuntimeException("unknown shape $shape");
}

/** 'hi' keeps about 1% of rows, 'lo' keeps about 87%. Same data file for both. */
function bf2_preds(string $shape, string $sel): array
{
    if ($shape === 'numeric') {
        return $sel === 'hi' ? [['eq', 'status_code', 3], ['gt', 'amount', 500.0]] : [['eq', 'status_code', 1], ['gt', 'amount', 10.0]];
    }
    return $sel === 'hi' ? [['eq', 'status', 'refunded'], ['gt', 'amount', 500.0]] : [['eq', 'status', 'paid'], ['gt', 'amount', 10.0]];
}

const BF2_SIZES = ['100k' => 100000, '1m' => 1000000, '10m' => 10000000];
const BF2_CARDS = ['c10' => 'k10', 'c1k' => 'k1k', 'c100k' => 'k100k'];

/**
 * scan-<shape>-<sel>-<size>          streaming sum and count
 * gs-<shape>-<sel>-<card>-<size>     streaming group-by on a string key
 * gi-numeric-<sel>-<card>-<size>     streaming group-by on an integer key
 * gt-<shape>-<sel>-<card>-<size>     group-by on a string key from a preloaded table
 * gti-numeric-<sel>-<card>-<size>    group-by on an integer key from a preloaded table
 * rows-<shape>-<sel>-<size>          CSV to real PHP rows
 */
function bf2_spec(string $workload): array
{
    $p = explode('-', $workload);
    $op = $p[0];
    $shape = $p[1] ?? '';
    $sel = $p[2] ?? '';
    $grouped = in_array($op, ['gs', 'gi', 'gt', 'gti'], true);
    $card = $grouped ? ($p[3] ?? '') : null;
    $size = $p[$grouped ? 4 : 3] ?? '';
    if (!isset(BF2_SIZES[$size]) || !in_array($sel, ['hi', 'lo'], true) || count($p) !== ($grouped ? 5 : 4)) {
        throw new RuntimeException("bad workload $workload");
    }
    if ($grouped && !isset(BF2_CARDS[$card])) { throw new RuntimeException("bad cardinality in $workload"); }
    $kind = match ($op) { 'scan' => 'scan', 'gs', 'gi' => 'group', 'gt', 'gti' => 'table', 'rows' => 'rows', default => throw new RuntimeException("bad op $op") };
    if (($op === 'gi' || $op === 'gti') && $shape !== 'numeric') { throw new RuntimeException('integer key workloads use the numeric shape'); }
    if (($op === 'gs' || $op === 'gt') && $shape === 'numeric') { throw new RuntimeException('string key workloads need string columns'); }
    return [
        'workload' => $workload, 'op' => $op, 'shape' => $shape, 'sel' => $sel, 'size' => $size, 'count' => BF2_SIZES[$size],
        'kind' => $kind, 'schema' => bf2_schema($shape), 'preds' => bf2_preds($shape, $sel),
        'sum' => $kind === 'rows' ? null : 'amount',
        'key' => $grouped ? BF2_CARDS[$card] : null,
        'max_rows' => BF2_MAX_ROWS,
        // The fastest CSV reader that is legal for this fixture. Materializing and SQLite baselines use it,
        // so their load phase is not handicapped by fgetcsv on data that does not need it.
        'reader' => $shape === 'mixed' ? 'hybrid' : 'fast',
    ];
}

// ---- generated PHP baselines -------------------------------------------------------------------

function bf2_err(int $row, string $what): RuntimeException
{
    return new RuntimeException("baseline: row $row: $what");
}

/** Emits the loop a competent developer would hand-write for this schema, in an include file so opcache and the JIT see it. */
function bf2_baseline(array $spec, string $variant): array
{
    $id = substr(sha1(serialize([$spec['kind'], $spec['schema'], $spec['preds'], $spec['sum'], $spec['key'], $spec['max_rows'], $spec['reader'] ?? 'fgetcsv', $variant])), 0, 16);
    $fn = "bf2_{$variant}_$id";
    $file = sys_get_temp_dir() . "/$fn.php";
    if (!is_file($file)) {
        file_put_contents($file, bf2_source($fn, $spec, $variant), LOCK_EX);
    }
    require_once $file;
    return [$fn, $file];
}

function bf2_validate(int $i, string $type, string $name): string
{
    $v = '$v' . $i;
    $bad = "throw bf2_err(\$n, " . var_export($name, true) . ");";
    $max = BF2_MAX_FIELD;
    $body = match ($type) {
        'int64' => "{ \$t = (int)$v; if ((string)\$t !== $v) { $bad } $v = \$t; }",
        'float64' => "{ if (isset({$v}[$max]) || !is_numeric($v) || $v !== trim($v, \" \\t\\n\\r\\v\\f\")) { $bad } $v = (float)$v; if (!is_finite($v)) { $bad } }",
        'bool' => "{ if ($v === 'true') { $v = true; } elseif ($v === 'false') { $v = false; } else { $bad } }",
        'string' => "{ if (isset({$v}[$max]) || !mb_check_encoding($v, 'UTF-8')) { $bad } }",
        default => throw new RuntimeException("bad type $type"),
    };
    return "            $v = \$r[\$p$i];\n            if ($v === '') { $v = null; } else $body\n";
}

function bf2_cond(array $p, string $e, string $type): string
{
    [$op, , $val] = $p;
    if ($op === 'eq') {
        if ($val === null) { return "$e === null"; }
        if ($type === 'float64' && is_int($val)) { $val = (float)$val; }
        return "$e === " . var_export($val, true);
    }
    if ($type === 'float64') { $val = (float)$val; }
    return "$e > " . var_export($val, true); // NULL never compares greater, so no separate null test
}

function bf2_sum_code(string $e, string $type, bool $nonNull): string
{
    $add = $type === 'int64'
        ? "\$sum += $e; if (!is_int(\$sum)) { throw new RuntimeException('baseline: int64 sum overflow'); }"
        : "\$sum += $e;";
    return $nonNull ? $add : "if ($e !== null) { $add }";
}

/** Per-group counters in two flat arrays: $cnt[$gk] and $sum[$gk]. The fastest competent $groups[$key] form. */
function bf2_group_code(string $k, bool $keyInt, string $s, string $sumType, bool $nonNull): string
{
    $sent = $keyInt ? "'null'" : '"\xFF\xFFNULL"';
    $init = $sumType === 'int64' ? '0' : '0.0';
    $add = $sumType === 'int64'
        ? "\$sum[\$gk] += $s; if (!is_int(\$sum[\$gk])) { throw new RuntimeException('baseline: int64 sum overflow'); }"
        : "\$sum[\$gk] += $s;";
    if ($nonNull) {
        return "\$gk = $k ?? $sent; if (isset(\$cnt[\$gk])) { ++\$cnt[\$gk]; $add } else { \$cnt[\$gk] = 1; \$sum[\$gk] = $init + $s; }";
    }
    return "\$gk = $k ?? $sent; if (isset(\$cnt[\$gk])) { ++\$cnt[\$gk]; if ($s !== null) { $add } } else { \$cnt[\$gk] = 1; \$sum[\$gk] = $s === null ? $init : $init + $s; }";
}

function bf2_group_finish(bool $keyInt): string
{
    $sent = $keyInt ? "'null'" : '"\xFF\xFFNULL"';
    $out = $keyInt ? '$gk' : '(string)$gk';
    return "\$groups = [];\n        foreach (\$cnt as \$gk => \$c) { \$groups[] = [\$gk === $sent ? null : $out, \$c, \$sum[\$gk]]; }\n";
}

function bf2_source(string $fn, array $spec, string $variant): string
{
    ['kind' => $kind, 'schema' => $schema, 'preds' => $preds, 'sum' => $sumName, 'key' => $keyName] = $spec;
    $names = array_keys($schema);
    $types = array_values($schema);
    $idx = array_flip($names);
    $n = count($names);
    $sumType = $sumName !== null ? $schema[$sumName] : null;
    $init = $sumType === 'int64' ? '0' : '0.0';
    $keyInt = $keyName !== null && $schema[$keyName] === 'int64';
    $maxRows = BF2_MAX_ROWS;

    $materialized = in_array($variant, ['rows', 'rows_foreach', 'cols', 'cols_dict'], true);
    $sqlite = in_array($variant, ['sqlite', 'sqlite_idx'], true);
    $streaming = in_array($variant, ['stream', 'stream_fast', 'stream_hybrid'], true);
    if (!$materialized && !$sqlite && !$streaming) { throw new RuntimeException("unknown variant $variant"); }
    if ($materialized && $kind === 'rows') { throw new RuntimeException("$variant does not apply to rows workloads"); }
    if ($streaming && $kind === 'table') { throw new RuntimeException("$variant does not apply to preloaded table workloads"); }
    if ($variant === 'cols_dict' && $kind !== 'table' && $kind !== 'group') { throw new RuntimeException('cols_dict needs a group key'); }

    // Referenced columns only: type, UTF-8 and length checks apply to these. See the README rule.
    $refs = [];
    foreach ($preds as $p) { $refs[$p[1]] = true; }
    if ($sumName !== null) { $refs[$sumName] = true; }
    if ($keyName !== null) { $refs[$keyName] = true; }
    $validateAll = $materialized || $kind === 'rows' || $kind === 'table';
    $validated = $validateAll ? $names : array_values(array_filter($names, fn($c) => isset($refs[$c])));

    $posLines = '';
    $validate = '';
    foreach ($names as $i => $c) {
        $q = var_export($c, true);
        $posLines .= "    \$p$i = \$pos[$q] ?? throw bf2_err(0, 'header does not match schema');\n";
    }
    foreach ($validated as $c) { $validate .= bf2_validate($idx[$c], $schema[$c], $c); }

    $vCond = [];
    $xCond = [];
    $cCond = [];
    foreach ($preds as $p) {
        $i = $idx[$p[1]] ?? throw new RuntimeException('unknown predicate column');
        $vCond[] = bf2_cond($p, '$v' . $i, $types[$i]);
        $xCond[] = bf2_cond($p, '$x[' . var_export($p[1], true) . ']', $types[$i]);
        $cCond[] = bf2_cond($p, '$c' . $i . '[$i]', $types[$i]);
    }
    $sumI = $sumName !== null ? $idx[$sumName] : null;
    $keyI = $keyName !== null ? $idx[$keyName] : null;
    $nonNull = false; // A predicate on the sum column that NULL cannot pass makes the separate null test redundant.
    foreach ($preds as $p) {
        if ($p[1] === $sumName && ($p[0] === 'gt' || $p[2] !== null)) { $nonNull = true; }
    }
    $sumV = $sumI !== null ? '$v' . $sumI : '';
    $keyV = $keyI !== null ? '$v' . $keyI : '';

    $vTest = $vCond ? implode(' && ', $vCond) : 'true';
    $rowItems = [];
    foreach ($names as $i => $c) { $rowItems[] = var_export($c, true) . ' => $v' . $i; }
    $rowLit = '[' . implode(', ', $rowItems) . ']';

    // Loop head. fgetcsv reads anything; explode is legal only with no quotes. `stream` is always fgetcsv by
    // definition; stream_fast and stream_hybrid are explicit; the rest use the fastest reader legal for the data.
    $reader = match ($variant) {
        'stream' => 'fgetcsv',
        'stream_fast' => 'fast',
        'stream_hybrid' => 'hybrid',
        default => $spec['reader'] ?? 'fgetcsv',
    };
    $loopHead = "while ((\$r = fgetcsv(\$h, 0, ',', '\"', '')) !== false) {";
    if ($reader === 'fast') {
        $loopHead = "while ((\$line = fgets(\$h)) !== false) {\n"
            . "        if (strpos(\$line, '\"') !== false) { throw bf2_err(\$n + 1, 'stream_fast: quote in input'); }\n"
            . "        \$r = explode(',', rtrim(\$line, \"\\r\\n\"));";
    } elseif ($reader === 'hybrid') {
        $loopHead = "while ((\$line = fgets(\$h)) !== false) {\n"
            . "        if (strpos(\$line, '\"') === false) {\n"
            . "            \$r = explode(',', rtrim(\$line, \"\\r\\n\"));\n"
            . "        } else {\n"
            . "            while (substr_count(\$line, '\"') % 2 === 1) {\n"
            . "                \$more = fgets(\$h);\n"
            . "                if (\$more === false) { break; }\n"
            . "                \$line .= \$more;\n"
            . "            }\n"
            . "            \$r = str_getcsv(rtrim(\$line, \"\\r\\n\"), ',', '\"', '');\n"
            . "        }";
    }

    $init1 = '';
    $body = '';
    $query = "    \$load = 0;\n    \$qns = hrtime(true) - \$t0;\n";
    $groupsExpr = 'null';
    $dataExpr = 'null';

    if ($streaming) {
        if ($kind === 'scan') {
            $init1 = "    \$count = 0;\n    \$sum = $init;\n";
            $body = "            if ($vTest) { ++\$count; " . bf2_sum_code($sumV, $sumType, $nonNull) . " }\n";
        } elseif ($kind === 'group') {
            $init1 = "    \$count = 0;\n    \$cnt = [];\n    \$sum = [];\n";
            $body = "            if ($vTest) { ++\$count; " . bf2_group_code($keyV, $keyInt, $sumV, $sumType, $nonNull) . " }\n";
            $query = "    " . bf2_group_finish($keyInt) . "    \$load = 0;\n    \$qns = hrtime(true) - \$t0;\n";
            $groupsExpr = '$groups';
        } else { // rows
            $init1 = "    \$count = 0;\n    \$data = [];\n";
            $body = "            if ($vTest) { if (++\$count > {$spec['max_rows']}) { throw new RuntimeException('baseline: result exceeds maxRows'); } \$data[] = $rowLit; }\n";
            $dataExpr = '$data';
        }
    } elseif ($materialized) {
        if ($variant === 'rows' || $variant === 'rows_foreach') {
            $init1 = "    \$rows = [];\n";
            $body = "            \$rows[] = $rowLit;\n";
        } elseif ($variant === 'cols') {
            foreach ($names as $i => $c) { $init1 .= "    \$c$i = [];\n"; $body .= "            \$c{$i}[] = \$v$i;\n"; }
        } else { // cols_dict: PHP-side dictionary encoding of the key, the strongest PHP form for repeated group-bys
            $predOnKey = false;
            foreach ($preds as $p) { if ($p[1] === $keyName) { $predOnKey = true; } }
            foreach ($names as $i => $c) {
                if ($i === $keyI && !$predOnKey) { continue; } // the key column itself is only needed if a predicate reads it
                $init1 .= "    \$c$i = [];\n";
                $body .= "            \$c{$i}[] = \$v$i;\n";
            }
            $init1 .= "    \$cd = [];\n    \$dict = [];\n    \$dv = [null];\n";
            $body .= "            if ($keyV === null) { \$cd[] = 0; } else { \$id = \$dict[$keyV] ?? null; if (\$id === null) { \$id = count(\$dv); \$dict[$keyV] = \$id; \$dv[] = $keyV; } \$cd[] = \$id; }\n";
        }
        // $once is the query run $repeats times, written per variant and per kind.
        if ($variant === 'rows') {
            $filters = '';
            foreach ($xCond as $c) { $filters .= "        \$s = array_filter(\$s, static fn(array \$x): bool => $c);\n"; }
            $pre = "        \$s = \$rows;\n$filters        \$count = count(\$s);\n";
            $loopVar = '$s';
        } elseif ($variant === 'rows_foreach') {
            $pre = "        \$count = 0;\n";
            $loopVar = '$rows';
        }
        $tests = '';
        if ($variant === 'rows_foreach') { foreach ($xCond as $c) { $tests .= "            if (!($c)) { continue; }\n"; } }
        if ($variant === 'cols' || $variant === 'cols_dict') { foreach ($cCond as $c) { $tests .= "            if (!($c)) { continue; }\n"; } }
        $sumE = $variant === 'cols' || $variant === 'cols_dict' ? "\$c{$sumI}[\$i]" : '$x[' . var_export($sumName, true) . ']';
        $keyE = $variant === 'cols' ? "\$c{$keyI}[\$i]" : '$x[' . var_export($keyName ?? '', true) . ']';

        if ($variant === 'cols_dict') {
            $sumStmt = $sumType === 'int64'
                ? "\$sum[\$id] += $sumE; if (!is_int(\$sum[\$id])) { throw new RuntimeException('baseline: int64 sum overflow'); }"
                : "\$sum[\$id] += $sumE;";
            $sumLine = $nonNull ? $sumStmt : "if ($sumE !== null) { $sumStmt }";
            $once = "        \$count = 0;\n        \$cnt = array_fill(0, count(\$dv), 0);\n        \$sum = array_fill(0, count(\$dv), $init);\n        \$order = [];\n"
                . "        for (\$i = 0; \$i < \$n; ++\$i) {\n$tests            \$id = \$cd[\$i];\n            if (\$cnt[\$id] === 0) { \$order[] = \$id; }\n            ++\$cnt[\$id];\n            ++\$count;\n            $sumLine\n        }\n"
                . "        \$groups = [];\n        foreach (\$order as \$id) { \$groups[] = [\$dv[\$id], \$cnt[\$id], \$sum[\$id]]; }\n";
        } elseif ($kind === 'group' || $kind === 'table') {
            $grp = bf2_group_code($keyE, $keyInt, $sumE, $sumType, $nonNull);
            if ($variant === 'rows') {
                $once = $pre . "        \$cnt = [];\n        \$sum = [];\n        foreach ($loopVar as \$x) { $grp }\n        " . bf2_group_finish($keyInt);
            } elseif ($variant === 'rows_foreach') {
                $once = $pre . "        \$cnt = [];\n        \$sum = [];\n        foreach ($loopVar as \$x) {\n$tests            ++\$count;\n            $grp\n        }\n        " . bf2_group_finish($keyInt);
            } else { // cols
                $once = "        \$count = 0;\n        \$cnt = [];\n        \$sum = [];\n        for (\$i = 0; \$i < \$n; ++\$i) {\n$tests            ++\$count;\n            $grp\n        }\n        " . bf2_group_finish($keyInt);
            }
        } else { // scan
            $addSum = bf2_sum_code($sumE, $sumType, $nonNull);
            if ($variant === 'rows') {
                $once = $pre . "        \$sum = $init;\n        foreach ($loopVar as \$x) { $addSum }\n";
            } elseif ($variant === 'rows_foreach') {
                $once = $pre . "        \$sum = $init;\n        foreach ($loopVar as \$x) {\n$tests            ++\$count;\n            $addSum\n        }\n";
            } else { // cols
                $once = "        \$count = 0;\n        \$sum = $init;\n        for (\$i = 0; \$i < \$n; ++\$i) {\n$tests            ++\$count;\n            $addSum\n        }\n";
            }
        }
        $query = bf2_query_loop($once);
        if ($kind === 'group' || $kind === 'table') { $groupsExpr = '$groups'; }
    } else { // sqlite, sqlite_idx
        // Streaming kinds store only the columns the query references, the strongest form that keeps the
        // same validation rule. Table and rows kinds store every column.
        $stored = ($kind === 'scan' || $kind === 'group') ? array_values(array_filter($names, fn($c) => isset($refs[$c]))) : $names;
        $sqlType = ['int64' => 'INTEGER', 'float64' => 'REAL', 'bool' => 'INTEGER', 'string' => 'TEXT'];
        $colsSql = [];
        $params = [];
        foreach ($stored as $c) {
            $i = $idx[$c];
            $colsSql[] = '"' . $c . '" ' . $sqlType[$types[$i]];
            $v = '$v' . $i;
            // PDO binds as text. Pass the validated source token for floats: (string)$float goes through
            // `precision` (14 digits) and would silently lose bits.
            $params[] = match ($types[$i]) {
                'float64' => "$v === null ? null : \$r[\$p$i]",
                'bool' => "$v === null ? null : ($v ? 1 : 0)",
                default => $v,
            };
        }
        $marks = implode(',', array_fill(0, count($stored), '?'));
        $create = var_export('CREATE TABLE t (' . implode(', ', $colsSql) . ')', true);
        $init1 = "    \$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);\n"
            . "    \$db->exec($create);\n    \$db->beginTransaction();\n"
            . "    \$ins = \$db->prepare('INSERT INTO t VALUES ($marks)');\n";
        $body = '            $ins->execute([' . implode(', ', $params) . "]);\n";

        $where = [];
        $bind = [];
        $predCols = [];
        foreach ($preds as [$op, $col, $val]) {
            $predCols[$col] = true;
            if ($op === 'eq' && $val === null) { $where[] = "\"$col\" IS NULL"; continue; }
            $where[] = "\"$col\" " . ($op === 'eq' ? '=' : '>') . ' ?';
            $bind[] = is_bool($val) ? ($val ? '1' : '0') : var_export($val, true);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $post = "    \$db->commit();\n";
        if ($variant === 'sqlite_idx') {
            if ($predCols) {
                $ix = implode(', ', array_map(fn($c) => "\"$c\"", array_keys($predCols)));
                $post .= '    $db->exec(' . var_export("CREATE INDEX ix_p ON t ($ix)", true) . ");\n";
            }
            if ($keyName !== null && !isset($predCols[$keyName])) {
                $post .= '    $db->exec(' . var_export("CREATE INDEX ix_k ON t (\"$keyName\")", true) . ");\n";
            }
        }
        if ($kind === 'scan') {
            $sql = "SELECT COALESCE(SUM(\"$sumName\"), $init), COUNT(*) FROM t$whereSql";
            $once = '        $q->execute([' . implode(', ', $bind) . "]);\n        \$row = \$q->fetch(PDO::FETCH_NUM);\n        \$q->closeCursor();\n"
                . "        \$sum = \$row[0];\n        \$count = (int)\$row[1];\n";
        } elseif ($kind === 'rows') {
            $sel = implode(', ', array_map(fn($c) => "\"$c\"", $names));
            $sql = "SELECT $sel FROM t$whereSql ORDER BY rowid";
            $boolCols = array_keys(array_filter($schema, fn($t) => $t === 'bool'));
            $conv = '';
            if ($boolCols) {
                $assign = '';
                foreach ($boolCols as $b) { $bq = var_export($b, true); $assign .= " \$row[$bq] = \$row[$bq] === null ? null : (bool)\$row[$bq];"; }
                $conv = "        foreach (\$data as &\$row) {{$assign} }\n        unset(\$row);\n";
            }
            $once = '        $q->execute([' . implode(', ', $bind) . "]);\n        \$data = \$q->fetchAll(PDO::FETCH_ASSOC);\n        \$q->closeCursor();\n$conv"
                . "        \$count = count(\$data);\n        if (\$count > {$spec['max_rows']}) { throw new RuntimeException('baseline: result exceeds maxRows'); }\n";
        } else { // group or table
            $k = "\"$keyName\"";
            $sql = "SELECT $k, COUNT(*), COALESCE(SUM(\"$sumName\"), $init) FROM t$whereSql GROUP BY $k ORDER BY MIN(rowid)";
            $keyCast = $keyInt ? '$row[0] === null ? null : (int)$row[0]' : '$row[0]';
            $once = '        $q->execute([' . implode(', ', $bind) . "]);\n        \$groups = [];\n        \$count = 0;\n"
                . "        while (\$row = \$q->fetch(PDO::FETCH_NUM)) { \$c = (int)\$row[1]; \$count += \$c; \$groups[] = [$keyCast, \$c, \$row[2]]; }\n"
                . "        \$q->closeCursor();\n";
            $groupsExpr = '$groups';
        }
        $query = $post . '    $q = $db->prepare(' . var_export($sql, true) . ");\n" . bf2_query_loop($once);
        if ($kind === 'rows') { $dataExpr = '$data'; }
    }

    $sumExpr = ($kind === 'scan') ? '$sum' : '0';

    return <<<PHP
<?php
declare(strict_types=1);

function $fn(string \$path, int \$repeats): array
{
    \$t0 = hrtime(true);
    \$h = fopen(\$path, 'rb');
    if (\$h === false) { throw new RuntimeException('baseline: cannot open input'); }
    stream_set_chunk_size(\$h, 65536);
    \$hdr = fgetcsv(\$h, 0, ',', '"', '');
    if (!is_array(\$hdr) || count(\$hdr) !== $n) { throw bf2_err(0, 'header does not match schema'); }
    \$pos = array_flip(\$hdr);
    if (count(\$pos) !== $n) { throw bf2_err(0, 'header does not match schema'); }
$posLines    \$n = 0;
$init1
    $loopHead
        if (count(\$r) !== $n) { throw bf2_err(\$n + 1, 'wrong field count'); }
        if (++\$n > $maxRows) { throw bf2_err(\$n, 'row limit exceeded'); }
$validate$body    }
    if (!feof(\$h)) { throw new RuntimeException('baseline: read failed'); }
    fclose(\$h);
$query    return ['sum' => $sumExpr, 'count' => \$count ?? 0, 'rows' => \$n, 'groups' => $groupsExpr, 'data' => $dataExpr, 'load_ns' => \$load, 'query_ns' => \$qns];
}

PHP;
}

function bf2_query_loop(string $once): string
{
    return "    \$load = hrtime(true) - \$t0;\n    \$q0 = hrtime(true);\n    for (\$rep = 0; \$rep < \$repeats; ++\$rep) {\n$once    }\n    \$qns = hrtime(true) - \$q0;\n";
}

// ---- DuckDB (CLI and PDO) ----------------------------------------------------------------------

/**
 * SQL for DuckDB. Explicit columns, no sniffing, errors are fatal, quoted empty stays a string.
 * $forceAll adds COUNT(col) for every unreferenced column. Without it (the idiomatic form) projection
 * pushdown never casts unreferenced columns, which matches the referenced-columns-only validation rule
 * of the streaming native scan.
 */
function bf2_duckdb_sql(array $spec, string $path, bool $forceAll): string
{
    ['kind' => $kind, 'schema' => $schema, 'preds' => $preds, 'sum' => $sum, 'key' => $key] = $spec;
    $map = ['int64' => 'BIGINT', 'float64' => 'DOUBLE', 'bool' => 'BOOLEAN', 'string' => 'VARCHAR'];
    $cols = [];
    foreach ($schema as $name => $type) { $cols[] = "'$name':'{$map[$type]}'"; }
    $where = [];
    foreach ($preds as [$op, $col, $val]) {
        if ($op === 'eq' && $val === null) { $where[] = "\"$col\" IS NULL"; continue; }
        $lit = is_string($val) ? "'" . str_replace("'", "''", $val) . "'" : (is_bool($val) ? ($val ? 'true' : 'false') : var_export($val, true));
        $where[] = "\"$col\" " . ($op === 'eq' ? '=' : '>') . " $lit";
    }
    $pathq = str_replace("'", "''", $path);
    $from = "read_csv('$pathq', header=true, auto_detect=false, delim=',', quote='\"', escape='\"', strict_mode=true, ignore_errors=false, null_padding=false, nullstr='', allow_quoted_nulls=false, columns={" . implode(',', $cols) . "})";
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    if ($kind === 'rows') {
        return 'SELECT ' . implode(', ', array_map(fn($c) => "\"$c\"", array_keys($schema))) . " FROM $from$whereSql";
    }
    $sumType = $schema[$sum];
    $sumSql = $sumType === 'int64' ? "COALESCE(SUM(\"$sum\"), 0)::BIGINT" : "COALESCE(SUM(\"$sum\"), 0.0)";
    $forced = '';
    if ($forceAll) {
        $refs = [];
        foreach ($preds as $p) { $refs[$p[1]] = true; }
        $refs[$sum] = true;
        if ($key !== null) { $refs[$key] = true; }
        foreach (array_keys($schema) as $c) { if (!isset($refs[$c])) { $forced .= ", COUNT(\"$c\")"; } }
    }
    if ($kind === 'scan') {
        return "SELECT $sumSql, COUNT(*)$forced FROM $from$whereSql";
    }
    return "SELECT \"$key\", COUNT(*), $sumSql$forced FROM $from$whereSql GROUP BY \"$key\"";
}

/** PDO DuckDB baseline. Uses the idiomatic query form; for rows workloads every column is projected anyway. */
function bf2_pdo_duckdb(array $spec, string $path, int $threads): array
{
    $t0 = hrtime(true);
    $db = new PDO('duckdb::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($threads > 0) { $db->exec("SET threads=$threads"); }
    $stmt = $db->query(bf2_duckdb_sql($spec, $path, false));
    $groups = null;
    $data = null;
    $sum = 0;
    $count = 0;
    $n = -1; // DuckDB does not report rows scanned
    switch ($spec['kind']) {
        case 'scan':
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $sum = $row[0];
            $count = (int)$row[1];
            break;
        case 'group':
        case 'table':
            $groups = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $c = (int)$row[1];
                $count += $c;
                $groups[] = [$row[0], $c, $row[2]];
            }
            break;
        case 'rows':
            $names = array_keys($spec['schema']);
            $boolIdx = array_keys(array_values($spec['schema']), 'bool', true); // the driver returns BOOLEAN as 0/1
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                foreach ($boolIdx as $b) { if ($row[$b] !== null) { $row[$b] = (bool)$row[$b]; } }
                $data[] = array_combine($names, $row);
            }
            $count = count($data);
            if ($count > $spec['max_rows']) { throw new RuntimeException('baseline: result exceeds maxRows'); }
            break;
    }
    return ['sum' => $sum, 'count' => $count, 'rows' => $n, 'groups' => $groups, 'data' => $data, 'load_ns' => 0, 'query_ns' => hrtime(true) - $t0];
}

// ---- dispatch ----------------------------------------------------------------------------------

/** Returns [callable(path, repeats), generated source file or null]. */
function bf2_make_call(array $spec, string $impl): array
{
    if ($impl === 'native' || $impl === 'native_scalar') {
        $opts = $impl === 'native_scalar' ? ['simd' => 'scalar'] : [];
        ['kind' => $kind, 'schema' => $schema, 'preds' => $preds, 'sum' => $sum, 'key' => $key, 'max_rows' => $max] = $spec;
        $apply = static function (BatchFlow\Query $q) use ($preds): BatchFlow\Query {
            foreach ($preds as [$op, $col, $v]) {
                $q = $op === 'eq' ? $q->whereEq($col, $v) : $q->whereGt($col, $v);
            }
            return $q;
        };
        $call = match ($kind) {
            'scan' => static function (string $f, int $r) use ($schema, $opts, $apply, $sum): array {
                $t0 = hrtime(true);
                $s = $apply(BatchFlow\Csv::scan($f, $schema, $opts))->summary($sum);
                return ['sum' => $s->sum, 'count' => $s->rowsMatched, 'rows' => $s->rowsScanned, 'groups' => null, 'load_ns' => 0, 'query_ns' => hrtime(true) - $t0];
            },
            'group' => static function (string $f, int $r) use ($schema, $opts, $apply, $sum, $key): array {
                $t0 = hrtime(true);
                $g = $apply(BatchFlow\Csv::scan($f, $schema, $opts))->groupSum($key, $sum);
                $count = 0;
                foreach ($g as $row) { $count += $row[1]; }
                return ['sum' => 0, 'count' => $count, 'rows' => -1, 'groups' => $g, 'load_ns' => 0, 'query_ns' => hrtime(true) - $t0];
            },
            'table' => static function (string $f, int $r) use ($schema, $opts, $apply, $sum, $key): array {
                $t0 = hrtime(true);
                $t = BatchFlow\Table::fromCsv($f, $schema, $opts + ['dictionary' => [$key]]);
                $t1 = hrtime(true);
                for ($i = 0; $i < $r; $i++) {
                    $g = $apply($t->query())->groupSum($key, $sum);
                }
                $count = 0;
                foreach ($g as $row) { $count += $row[1]; }
                return ['sum' => 0, 'count' => $count, 'rows' => $t->rowCount(), 'groups' => $g, 'load_ns' => $t1 - $t0, 'query_ns' => hrtime(true) - $t1];
            },
            'rows' => static function (string $f, int $r) use ($schema, $opts, $apply, $max): array {
                $t0 = hrtime(true);
                $data = $apply(BatchFlow\Csv::scan($f, $schema, $opts))->toArray($max);
                return ['sum' => 0, 'count' => count($data), 'rows' => -1, 'groups' => null, 'data' => $data, 'load_ns' => 0, 'query_ns' => hrtime(true) - $t0];
            },
        };
        return [$call, null];
    }
    if ($impl === 'pdo_duckdb') {
        return [static fn(string $f, int $r) => bf2_pdo_duckdb($spec, $f, 0), null];
    }
    if ($impl === 'pdo_duckdb_1t') {
        return [static fn(string $f, int $r) => bf2_pdo_duckdb($spec, $f, 1), null];
    }
    [$fn, $file] = bf2_baseline($spec, $impl);
    return [$fn, $file];
}

/** Hashes the observable result exactly, floats as raw bits. Computed outside the timed region. */
function bf2_digest(array $r): array
{
    $h = hash_init('xxh128');
    $sumTotal = 0.0;
    $ngroups = -1;
    $outrows = -1;
    if (isset($r['groups']) && is_array($r['groups'])) {
        $ngroups = count($r['groups']);
        foreach ($r['groups'] as [$k, $c, $s]) {
            hash_update($h, ($k === null ? "\x00" : (is_int($k) ? "\x01$k" : "\x02" . strlen($k) . ':' . $k)) . "|$c|" . (is_float($s) ? 'f' . pack('E', $s) : "i$s"));
            $sumTotal += $s;
        }
    } elseif (isset($r['data']) && is_array($r['data'])) {
        $outrows = count($r['data']);
        foreach ($r['data'] as $row) {
            $line = '';
            foreach ($row as $v) {
                $line .= match (true) {
                    $v === null => "\x00",
                    is_int($v) => "\x01$v",
                    is_float($v) => "\x03" . pack('E', $v),
                    is_bool($v) => $v ? "\x04" : "\x05",
                    default => "\x02" . strlen($v) . ':' . $v,
                };
            }
            hash_update($h, $line . "\n");
        }
    } else {
        $sumTotal = (float)$r['sum'];
        hash_update($h, ($r['count'] ?? 0) . '|' . (is_float($r['sum']) ? 'f' . pack('E', $r['sum']) : 'i' . $r['sum']));
    }
    return ['digest' => hash_final($h), 'sum_total' => $sumTotal, 'ngroups' => $ngroups, 'outrows' => $outrows];
}

// ---- engine state ------------------------------------------------------------------------------

function bf2_engine_state(): array
{
    $st = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    $jit = is_array($st) ? ($st['jit'] ?? []) : [];
    return [
        'opcache' => is_array($st) && !empty($st['opcache_enabled']),
        'jit_enabled' => !empty($jit['enabled']),
        'jit_on' => !empty($jit['on']),
        'jit_ini' => (string)ini_get('opcache.jit'),
        'jit_buffer' => (int)($jit['buffer_size'] ?? 0),
        'jit_free' => (int)($jit['buffer_free'] ?? 0),
    ];
}

/** Dies with exit 3 unless the actual engine state is the state this run claims to measure. */
function bf2_assert_engine(string $mode): array
{
    $s = bf2_engine_state();
    $ok = match ($mode) {
        'off' => !$s['opcache'] && !$s['jit_on'],
        'nojit' => $s['opcache'] && !$s['jit_on'],
        'jit' => $s['opcache'] && $s['jit_enabled'] && $s['jit_on'] && $s['jit_ini'] === 'tracing' && $s['jit_buffer'] > 0,
        default => false,
    };
    if (!$ok) {
        fwrite(STDERR, "ENGINE ASSERT FAILED: claimed mode=$mode, actual=" . json_encode($s) . "\n");
        exit(3);
    }
    return $s;
}
