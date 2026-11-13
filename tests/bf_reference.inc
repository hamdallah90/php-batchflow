<?php
/*
 * Independent pure-PHP reference for the BatchFlow terminals: count, sum, summary, groupCount,
 * groupSum (SPEC 5.9) and toArray (SPEC 5.10). The extended parts (the delimiter is here; the predicates
 * ne, lt, gte, lte and in, min, max, avg, aggregate and groupAgg) live in bf_reference_m2b.inc, loaded only by
 * the extended tests so that the other memory_limit tests keep the heap layout they were sized for.
 * Written from the CSV and value rules only; byte-at-a-time, no shared code with the extension.
 * Returns results in the same text form as bf_try() in bf_helper.inc; groups and rows are rendered
 * by bf_fmt_groups() and bf_fmt_rows() at the end of this file. The reference models framing, values,
 * predicates, first-seen group order, maxGroups and maxRows. It does not model maxGroupBytes or
 * memory_limit: those tests carry their own literal expectations.
 */

final class BfRefErr extends Exception
{
    public function __construct(public string $cls, string $m)
    {
        parent::__construct($m);
    }
}

final class BfRef
{
    private const NS = 'BatchFlow\\';

    public static function esc(string $s): string
    {
        $o = '';
        $m = min(strlen($s), 64);
        for ($i = 0; $i < $m; $i++) {
            $c = ord($s[$i]);
            $o .= ($c < 0x20 || $c > 0x7e || $c === 0x22 || $c === 0x5c) ? sprintf('\x%02X', $c) : $s[$i];
        }
        return $o . (strlen($s) > 64 ? '...' : '');
    }

    private static function err(string $cls, string $m): BfRefErr
    {
        return new BfRefErr(self::NS . $cls, $m);
    }

    /** Frame one record at $p. Returns [fields, next]; each field is [value, quoted]. */
    private static function frame(string $d, int $p, int $maxRow, ?int $n, string $dl = ','): array
    {
        $len = strlen($d);
        $fields = [];
        $cur = '';
        $quoted = false;
        $inQ = false;
        $start = true;
        $after = false;
        $skip = false;
        $count = 1;
        $i = $p;
        while (true) {
            $eof = $i >= $len;
            $term = $eof || (!$inQ && ($d[$i] === "\n" || ($d[$i] === "\r" && $i + 1 < $len && $d[$i + 1] === "\n")));
            $tie = ($i - $p === $maxRow && !$term);
            if ($i - $p > $maxRow && !$term) {
                throw self::err('LimitException', 'row exceeds maxRowBytes');
            }
            if ($eof) {
                if ($inQ) {
                    throw self::err('CsvException', 'unterminated quoted field');
                }
                $fields[] = [$cur, $quoted];
                if ($n !== null && $count !== $n) {
                    throw self::err('CsvException', "expected $n fields, got $count");
                }
                return [$fields, $len];
            }
            $c = $d[$i];
            if ($skip) {
                if ($tie) throw self::err('LimitException', 'row exceeds maxRowBytes');
                $skip = false;
                $i++;
                continue;
            }
            if ($inQ) {
                if ($tie) throw self::err('LimitException', 'row exceeds maxRowBytes');
                if ($c === '"') {
                    if ($i + 1 < $len && $d[$i + 1] === '"') {
                        $cur .= '"';
                        $skip = true;
                    } else {
                        $inQ = false;
                        $after = true;
                    }
                } else {
                    $cur .= $c;
                }
                $i++;
                continue;
            }
            if ($term) {
                $fields[] = [$cur, $quoted];
                if ($n !== null && $count !== $n) {
                    throw self::err('CsvException', "expected $n fields, got $count");
                }
                return [$fields, $i + ($c === "\n" ? 1 : 2)];
            }
            if ($c === "\r") {
                throw self::err('CsvException', 'bare CR');
            }
            if ($after && $c !== $dl) {
                throw self::err('CsvException', 'text after closing quote');
            }
            if ($c === $dl) {
                $count++;
                if ($n !== null && $count > $n) {
                    throw self::err('CsvException', "expected $n fields, got more");
                }
                if ($n === null && $count > 4096) {
                    throw self::err('CsvException', 'too many columns (limit 4096)');
                }
                if ($tie) throw self::err('LimitException', 'row exceeds maxRowBytes');
                $fields[] = [$cur, $quoted];
                $cur = '';
                $quoted = false;
                $start = true;
                $after = false;
            } elseif ($c === '"') {
                if (!$start) {
                    throw self::err('CsvException', 'bare quote in unquoted field');
                }
                if ($tie) throw self::err('LimitException', 'row exceeds maxRowBytes');
                $inQ = true;
                $quoted = true;
                $start = false;
            } else {
                if ($tie) throw self::err('LimitException', 'row exceeds maxRowBytes');
                $cur .= $c;
                $start = false;
            }
            $i++;
        }
    }

    /** Decode one field of $type. Returns the PHP value; throws a CsvException on invalid. */
    private static function value(string $name, string $type, string $v, bool $quoted): mixed
    {
        $fail = fn(string $kind) => self::err('CsvException', 'column "' . self::esc($name) . "\": expected $type, got $kind");
        if ($v === '' && !$quoted) {
            return null;
        }
        if ($type === 'string') {
            if (!preg_match('//u', $v)) {
                throw self::err('CsvException', 'column "' . self::esc($name) . '": invalid UTF-8');
            }
            return $v;
        }
        if ($v === '' && $quoted) {
            throw $fail('quoted empty field');
        }
        switch ($type) {
            case 'int64':
                if (!preg_match('/\A(0|-?[1-9][0-9]*)\z/D', $v)) {
                    throw $fail('malformed value');
                }
                if ((string)(int)$v !== $v) {
                    throw $fail('out-of-range number');
                }
                return (int)$v;
            case 'float64':
                if (!preg_match('/\A[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?\z/D', $v)) {
                    throw $fail('malformed value');
                }
                $f = (float)$v;
                if (is_infinite($f)) {
                    throw $fail('out-of-range number');
                }
                return $f;
            case 'bool':
                if ($v === 'true') {
                    return true;
                }
                if ($v === 'false') {
                    return false;
                }
                throw $fail('malformed value');
        }
        throw new LogicException('type');
    }

    private static function test(array $p, mixed $v): bool
    {
        [$kind, , $lit] = $p;
        if ($kind !== 'eq' && $kind !== 'gt') {
            return BfRefM2b::test($p, $v);
        }
        if ($kind === 'eq') {
            if ($lit === null || $v === null) {
                return $lit === $v;
            }
            return is_float($v) ? $v == (float)$lit : $v === $lit;
        }
        return $v !== null && (is_float($v) ? $v > (float)$lit : $v > $lit);
    }

    private static function exec(string $d, array $schema, array $o, array $preds, string $term, ?string $sumCol, array $x = []): mixed
    {
        $o += ['allowExtraColumns' => false, 'maxFieldBytes' => 1048576, 'maxRowBytes' => 16777216,
               'maxInputRows' => 100000000];
        $names = array_map('strval', array_keys($schema));
        $types = array_values($schema);
        $byName = array_flip($names);
        $isGroup = $term === 'groupCount' || $term === 'groupSum';
        $keyCol = $x['key'] ?? null;
        $maxGroups = $x['maxGroups'] ?? 1000000;
        $maxRows = $x['maxRows'] ?? 10000;
        $dl = $o['delimiter'] ?? ',';
        [$aggs, $keys] = in_array($term, ['min', 'max', 'avg', 'aggregate', 'groupAgg'], true)
            ? BfRefM2b::checks($term, $x, $byName, $types, $maxGroups) : [[], []];
        // SPEC 5.11: ValueError first, then column checks in argument order (groupSum: key before sum).
        if ($isGroup && ($maxGroups < 1 || $maxGroups > 100000000)) {
            $n = $term === 'groupCount' ? 2 : 3;
            throw new BfRefErr('ValueError', "BatchFlow\\Query::$term(): Argument #$n (\$maxGroups) must be between 1 and 100000000");
        }
        if ($term === 'toArray' && ($maxRows < 1 || $maxRows > 100000000)) {
            throw new BfRefErr('ValueError', 'BatchFlow\\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000');
        }
        if ($isGroup) {
            if (!isset($byName[$keyCol])) {
                throw self::err('SchemaException', 'unknown column "' . self::esc($keyCol) . '"');
            }
            if (!in_array($types[$byName[$keyCol]], ['int64', 'string'], true)) {
                throw self::err('SchemaException', 'column "' . self::esc($keyCol) . '": group key must be string or int64');
            }
        }
        if ($sumCol !== null) {
            if (!isset($byName[$sumCol])) {
                throw self::err('SchemaException', 'unknown column "' . self::esc($sumCol) . '"');
            }
            if (!in_array($types[$byName[$sumCol]], ['int64', 'float64'], true)) {
                throw self::err('SchemaException', 'column "' . self::esc($sumCol) . '": sum requires a numeric column');
            }
        }
        $len = strlen($d);
        $p = (substr($d, 0, 3) === "\xEF\xBB\xBF") ? 3 : 0;
        if ($p >= $len) {
            throw self::err('CsvException', 'missing header');
        }
        // header
        $hp = "header (line 1, offset $p): ";
        try {
            [$hf, $next] = self::frame($d, $p, $o['maxRowBytes'], null, $dl);
            $col = array_fill(0, count($names), null);
            foreach ($hf as $i => [$h]) {
                if (isset($byName[$h])) {
                    if ($col[$byName[$h]] !== null) {
                        throw self::err('CsvException', 'duplicate column "' . self::esc($h) . '"');
                    }
                    $col[$byName[$h]] = $i;
                } elseif (!$o['allowExtraColumns']) {
                    throw self::err('CsvException', 'unexpected column at position ' . ($i + 1));
                }
            }
            foreach ($names as $k => $nm) {
                if ($col[$k] === null) {
                    throw self::err('CsvException', 'missing column "' . self::esc($nm) . '"');
                }
            }
        } catch (BfRefErr $e) {
            throw new BfRefErr($e->cls, $hp . $e->getMessage());
        }
        $nf = count($hf);
        $ref = [];
        foreach ($preds as $pr) {
            $ref[$pr[1]] = true;
        }
        if ($sumCol !== null) {
            $ref[$sumCol] = true;
        }
        if ($isGroup) {
            $ref[$keyCol] = true;
        }
        foreach (array_merge(array_column($aggs, 1), $keys) as $nm) {
            $ref[$nm] = true;
        }
        if ($term === 'toArray') {
            foreach ($names as $nm) {
                $ref[$nm] = true;
            }
        }
        $groups = [];
        $rows = [];
        $st = $aggs ? BfRefM2b::aggInit($aggs, $byName, $types) : [];
        $tuples = [];
        $line = 1 + substr_count($d, "\n", $p, $next - $p);
        $p = $next;
        $R = 0;
        $scanned = 0;
        $matched = 0;
        $sum = null;
        $sumType = $sumCol !== null ? $types[$byName[$sumCol]] : null;
        if ($sumType === 'int64') {
            $sum = 0;
        } elseif ($sumType === 'float64') {
            $sum = 0.0;
        }
        while ($p < $len) {
            $R++;
            $pre = "row $R (line $line, offset $p): ";
            try {
                [$fields, $next] = self::frame($d, $p, $o['maxRowBytes'], $nf, $dl);
                if ($R > $o['maxInputRows']) {
                    throw self::err('LimitException', 'input exceeds maxInputRows');
                }
                $vals = [];
                foreach ($names as $k => $nm) {
                    if (!isset($ref[$nm])) {
                        continue;
                    }
                    [$v, $q] = $fields[$col[$k]];
                    if (strlen($v) > $o['maxFieldBytes']) {
                        throw self::err('LimitException', 'column "' . self::esc($nm) . '": field exceeds maxFieldBytes');
                    }
                    $vals[$nm] = self::value($nm, $types[$k], $v, $q);
                }
                $scanned++;
                $hit = true;
                foreach ($preds as $pr) {
                    if (!self::test($pr, $vals[$pr[1]])) {
                        $hit = false;
                        break;
                    }
                }
                if ($hit) {
                    $matched++;
                    if ($term === 'groupAgg' || $aggs !== []) {
                        BfRefM2b::row($term, $keys, $aggs, $vals, $byName, $types, $maxGroups, $tuples, $st);
                    }
                    if ($term === 'toArray') {
                        if (count($rows) >= $maxRows) {
                            throw self::err('ResultTooLargeException', "result exceeds maxRows ($maxRows)");
                        }
                        $row = [];
                        foreach ($names as $nm) {
                            $row[$nm] = $vals[$nm];
                        }
                        $rows[] = $row;
                    }
                    if ($isGroup) {
                        $kv = $vals[$keyCol];
                        $h = $kv === null ? 'n' : (is_int($kv) ? 'i' . $kv : 's' . $kv);
                        if (!isset($groups[$h])) {
                            if (count($groups) >= $maxGroups) {
                                throw self::err('ResultTooLargeException', "result exceeds maxGroups ($maxGroups)");
                            }
                            $groups[$h] = [$kv, 0, $sumType === 'int64' ? 0 : 0.0];
                        }
                        $groups[$h][1]++;
                        if ($term === 'groupSum' && $vals[$sumCol] !== null) {
                            $r = $groups[$h][2] + $vals[$sumCol];
                            if ($sumType === 'int64' && is_float($r)) {
                                throw self::err('SumOverflowException', 'int64 sum overflow in column "' . self::esc($sumCol) . '"');
                            }
                            $groups[$h][2] = $r;
                        }
                    }
                    if ($term !== 'groupSum' && $sumCol !== null && $vals[$sumCol] !== null) {
                        $r = $sum + $vals[$sumCol];
                        if ($sumType === 'int64' && is_float($r)) {
                            throw self::err('SumOverflowException', 'int64 sum overflow in column "' . self::esc($sumCol) . '"');
                        }
                        $sum = $r;
                    }
                }
            } catch (BfRefErr $e) {
                throw new BfRefErr($e->cls, $pre . $e->getMessage());
            }
            $line += substr_count($d, "\n", $p, $next - $p);
            $p = $next;
        }
        if ($term === 'groupAgg' || $aggs !== []) {
            return BfRefM2b::finish($term, $aggs, $st, $tuples);
        }
        if ($term === 'groupSum') {
            foreach ($groups as $g) {
                if (is_float($g[2]) && !is_finite($g[2])) {
                    throw self::err('SumOverflowException', 'float64 sum of column "' . self::esc($sumCol) . '" is not finite');
                }
            }
        } elseif ($sumType === 'float64' && !is_finite($sum)) {
            throw self::err('SumOverflowException', 'float64 sum of column "' . self::esc($sumCol) . '" is not finite');
        }
        return match ($term) {
            'groupCount' => array_map(fn($g) => [$g[0], $g[1]], array_values($groups)),
            'groupSum' => array_values($groups),
            'toArray' => $rows,
            'count' => $matched,
            'sum' => $sum,
            'summary' => ['scanned' => $scanned, 'matched' => $matched, 'sum' => $sum],
        };
    }

    /** Raw result or a BfRefErr (used by the userland stand-in that checks test structure). */
    public static function raw(string $d, array $schema, array $o, array $preds, string $term, ?string $sumCol, array $x = []): mixed
    {
        return self::exec($d, $schema, $o, $preds, $term, $sumCol, $x);
    }

    public static function runStr(string $d, array $schema, array $o, array $preds, string $term, ?string $sumCol, array $x = []): string
    {
        try {
            $r = self::exec($d, $schema, $o, $preds, $term, $sumCol, $x);
            if ($term === 'groupCount' || $term === 'groupSum') {
                return bf_fmt_groups($r);
            }
            if ($term === 'aggregate' || $term === 'groupAgg') {
                return BfRefM2b::fmt($term, $r);
            }
            return $term === 'toArray' ? bf_fmt_rows($r) : bf_fmt($r);
        } catch (BfRefErr $e) {
            return str_replace(self::NS, '', $e->cls) . ': ' . $e->getMessage();
        }
    }
}

/** Typed one-line rendering of a scalar result value. Strings are JSON-quoted so NUL, CR, LF and quotes show. */
function bf_val(mixed $v): string
{
    return match (true) {
        $v === null => 'NULL',
        is_bool($v) => 'bool(' . ($v ? 'true' : 'false') . ')',
        is_int($v) => "int($v)",
        is_float($v) => 'float(' . var_export($v, true) . ')',
        is_string($v) => 's' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        default => 'UNEXPECTED(' . get_debug_type($v) . ')',
    };
}

/** "[key,count]" or "[key,count,sum]" per group, joined by "; ". Also checks the packed-list shape. */
function bf_fmt_groups(mixed $r): string
{
    if (!is_array($r) || !array_is_list($r)) {
        return 'NOT A LIST';
    }
    $o = [];
    foreach ($r as $g) {
        if (!is_array($g) || !array_is_list($g) || count($g) < 2 || count($g) > 3) {
            return 'BAD ELEMENT SHAPE';
        }
        $o[] = '[' . implode(',', array_map('bf_val', $g)) . ']';
    }
    return $o ? implode('; ', $o) : '[]';
}

/** One line per row, "{name=value, ...}" in the row's own key order; keys shown as int or string. */
function bf_fmt_rows(mixed $r): string
{
    if (!is_array($r) || !array_is_list($r)) {
        return 'NOT A LIST';
    }
    $o = [];
    foreach ($r as $row) {
        if (!is_array($row)) {
            return 'BAD ROW';
        }
        $p = [];
        foreach ($row as $k => $v) {
            $p[] = (is_int($k) ? "#$k" : $k) . '=' . bf_val($v);
        }
        $o[] = '{' . implode(', ', $p) . '}';
    }
    return $o ? implode("\n", $o) : '[]';
}

/** Run a group or rows terminal ('groupCount' | 'groupSum' | 'toArray') through the extension, rendered. */
function bf_gexec(string $data, array $schema, array $opts, array $preds, string $term, array $x = []): string
{
    if (!in_array($term, ['groupCount', 'groupSum', 'toArray'], true)) {
        return bf_gexec_m2b($data, $schema, $opts, $preds, $term, $x);
    }
    try {
        $q = bf_apply(bf_q($data, $schema, $opts), $preds);
        $r = match ($term) {
            'groupCount' => $q->groupCount($x['key'], $x['maxGroups'] ?? 1000000),
            'groupSum' => $q->groupSum($x['key'], $x['sum'], $x['maxGroups'] ?? 1000000),
            'toArray' => $q->toArray($x['maxRows'] ?? 10000),
        };
        return match ($term) {
            'toArray' => bf_fmt_rows($r),
            default => bf_fmt_groups($r),
        };
    } catch (Throwable $e) {
        return bf_cls($e) . ': ' . $e->getMessage();
    }
}

/** Same through the reference. $x: key, sum, maxGroups, maxRows. */
function bf_gref(string $data, array $schema, array $opts, array $preds, string $term, array $x = []): string
{
    return BfRef::runStr($data, $schema, $opts, $preds, $term, $x['sum'] ?? null, $x);
}

/** "same" when extension and reference agree, else a diagnosis. */
function bf_gcmp(string $data, array $schema, array $opts, array $preds, string $term, array $x = []): string
{
    $a = bf_gexec($data, $schema, $opts, $preds, $term, $x);
    $b = bf_gref($data, $schema, $opts, $preds, $term, $x);
    return $a === $b ? 'same' : "DIFF ext=[$a] ref=[$b]";
}
