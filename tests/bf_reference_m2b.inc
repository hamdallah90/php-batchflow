<?php
/*
 * The extended part of the independent pure-PHP reference (tests/bf_reference.inc): the argument checks of min,
 * max, avg, aggregate and groupAgg (SPEC 5.8, 5.9), per-pair aggregate states, and key tuples. Written from
 * the behaviour rules only; no shared code with the extension. BfRef::exec calls it for the extended terms; only the extended tests
 * load it, so the other tests keep the memory layout their memory_limit cases were sized for.
 */

final class BfRefM2b
{
    private static function err(string $cls, string $m): BfRefErr
    {
        return new BfRefErr('BatchFlow\\' . $cls, $m);
    }

    /** The whereEq rule for one literal: null is IS NULL, a float column compares as floats (-0.0 == 0.0). */
    private static function eq(mixed $lit, mixed $v): bool
    {
        if ($lit === null || $v === null) {
            return $lit === $v;
        }
        return is_float($v) ? $v == (float)$lit : $v === $lit;
    }

    /** The extended predicates: ne, in, and the ranges gte, lt, lte (SPEC 5.7). */
    public static function test(array $p, mixed $v): bool
    {
        [$kind, , $lit] = $p;
        if ($kind === 'ne') {
            return $lit === null ? $v !== null : ($v !== null && !self::eq($lit, $v));
        }
        if ($kind === 'in') {
            foreach ($lit as $e) {
                if (self::eq($e, $v)) {
                    return true;
                }
            }
            return false;
        }
        if ($v === null) {
            return false;
        }
        $l = is_float($v) ? (float)$lit : $lit;
        return match ($kind) {
            'gte' => $v >= $l,
            'lt' => $v < $l,
            'lte' => $v <= $l,
        };
    }

    public static function fmt(string $term, mixed $r): string
    {
        return $term === 'aggregate' ? bf_fmt_list($r) : bf_fmt_lists($r);
    }

    /** ValueError text of BatchFlow\Query::$m() for argument $n named $name. */
    public static function verr(string $m, int $n, string $name, string $msg): BfRefErr
    {
        return new BfRefErr('ValueError', "BatchFlow\\Query::$m(): Argument #$n (\$$name) $msg");
    }

    /** SPEC 5.8 step 1 for an aggregate list as argument $n of $m. */
    public static function aggShape(mixed $aggs, string $m, int $n, bool $allowEmpty): void
    {
        if ($aggs === [] && !$allowEmpty) {
            throw self::verr($m, $n, 'aggregates', 'must not be empty');
        }
        if (!array_is_list($aggs)) {
            throw self::verr($m, $n, 'aggregates', 'must be a list');
        }
        if (count($aggs) > 16) {
            throw self::verr($m, $n, 'aggregates', 'must contain at most 16 aggregates');
        }
        foreach ($aggs as $i => $pair) {
            if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1])) {
                throw self::verr($m, $n, 'aggregates', "element $i must be a list of two strings");
            }
            if (!in_array($pair[0], ['count', 'sum', 'min', 'max', 'avg'], true)) {
                throw self::verr($m, $n, 'aggregates', "element $i: aggregate must be one of count, sum, min, max, avg");
            }
        }
    }

    /** SPEC 5.8 step 2: each pair's column in list order. */
    public static function aggColumns(array $aggs, array $byName, array $types): void
    {
        foreach ($aggs as [$op, $c]) {
            if (!isset($byName[$c])) {
                throw self::err('SchemaException', 'unknown column "' . BfRef::esc($c) . '"');
            }
            if ($op !== 'count' && !in_array($types[$byName[$c]], ['int64', 'float64'], true)) {
                throw self::err('SchemaException', 'column "' . BfRef::esc($c) . "\": $op requires a numeric column");
            }
        }
    }

    /** A fresh state per pair: [value, non-null count]. */
    public static function aggInit(array $aggs, array $byName, array $types): array
    {
        $st = [];
        foreach ($aggs as [$op, $c]) {
            $st[] = [in_array($op, ['sum', 'avg'], true) ? ($types[$byName[$c]] === 'int64' ? 0 : 0.0) : null, 0];
        }
        return $st;
    }

    /** One matched row into the per-pair states, pairs in list order; int64 overflow raises at this row. */
    public static function aggAdd(array $aggs, array &$st, array $vals, array $byName, array $types): void
    {
        foreach ($aggs as $k => [$op, $c]) {
            $v = $vals[$c];
            if ($v === null) {
                continue;
            }
            switch ($op) {
                case 'sum':
                case 'avg':
                    $r = $st[$k][0] + $v;
                    if ($types[$byName[$c]] === 'int64' && is_float($r)) {
                        throw self::err('SumOverflowException', 'int64 sum overflow in column "' . BfRef::esc($c) . '"');
                    }
                    $st[$k][0] = $r;
                    break;
                case 'min':
                    if ($st[$k][1] === 0 || $v < $st[$k][0]) {
                        $st[$k][0] = $v;
                    }
                    break;
                case 'max':
                    if ($st[$k][1] === 0 || $v > $st[$k][0]) {
                        $st[$k][0] = $v;
                    }
                    break;
            }
            $st[$k][1]++;
        }
    }

    /** After the scan: non-finite float64 sums of sum and avg pairs, in list order. */
    public static function aggFinite(array $aggs, array $st): void
    {
        foreach ($aggs as $k => [$op, $c]) {
            if (($op === 'sum' || $op === 'avg') && is_float($st[$k][0]) && !is_finite($st[$k][0])) {
                throw self::err('SumOverflowException', 'float64 sum of column "' . BfRef::esc($c) . '" is not finite');
            }
        }
    }

    public static function aggResult(array $aggs, array $st): array
    {
        $out = [];
        foreach ($aggs as $k => [$op]) {
            [$v, $n] = $st[$k];
            $out[] = match ($op) {
                'count' => $n,
                'sum' => $v,
                'min', 'max' => $n === 0 ? null : $v,
                'avg' => $n === 0 ? null : (float)$v / $n,
            };
        }
        return $out;
    }

    /** Argument checks before any I/O; returns [aggregate pairs, key columns]. */
    public static function checks(string $term, array $x, array $byName, array $types, int $maxGroups): array
    {
        // min, max and avg are one-pair aggregates with their own column reasons.
        $aggs = match ($term) {
            'min', 'max', 'avg' => [[$term, $x['col']]],
            'aggregate', 'groupAgg' => $x['aggs'],
            default => [],
        };
        $keys = [];
        if ($term === 'groupAgg') {
            // SPEC 5.9: keys shape, aggregates shape (empty allowed), maxGroups, key columns, aggregate columns.
            $keys = $x['keys'];
            if (is_array($keys)) {
                if ($keys === []) {
                    throw self::verr('groupAgg', 1, 'keys', 'must not be empty');
                }
                if (!array_is_list($keys)) {
                    throw self::verr('groupAgg', 1, 'keys', 'must be a list');
                }
                if (count($keys) > 4) {
                    throw self::verr('groupAgg', 1, 'keys', 'must contain at most 4 key columns');
                }
                foreach ($keys as $i => $kn) {
                    if (!is_string($kn)) {
                        throw self::verr('groupAgg', 1, 'keys', "element $i must be a string");
                    }
                    if (in_array($kn, array_slice($keys, 0, $i), true)) {
                        throw self::verr('groupAgg', 1, 'keys', 'must not contain a column twice');
                    }
                }
            } else {
                $keys = [$keys];
            }
            self::aggShape($aggs, 'groupAgg', 2, true);
            if ($maxGroups < 1 || $maxGroups > 100000000) {
                throw self::verr('groupAgg', 3, 'maxGroups', 'must be between 1 and 100000000');
            }
            foreach ($keys as $kn) {
                if (!isset($byName[$kn])) {
                    throw self::err('SchemaException', 'unknown column "' . BfRef::esc($kn) . '"');
                }
                if (!in_array($types[$byName[$kn]], ['int64', 'string'], true)) {
                    throw self::err('SchemaException', 'column "' . BfRef::esc($kn) . '": group key must be string or int64');
                }
            }
            self::aggColumns($aggs, $byName, $types);
        } elseif ($term === 'aggregate') {
            self::aggShape($aggs, 'aggregate', 1, false);
            self::aggColumns($aggs, $byName, $types);
        } elseif ($aggs !== []) {
            self::aggColumns($aggs, $byName, $types);
        }
        return [$aggs, $keys];
    }

    /** One matched row: its tuple's group and states for groupAgg, the run states for aggregate. */
    public static function row(string $term, array $keys, array $aggs, array $vals, array $byName, array $types, int $maxGroups, array &$tuples, array &$st): void
    {
        if ($term === 'groupAgg') {
            // Own tuple key: position-tagged, so NULL, '' and 0 stay apart in every position.
            $tk = '';
            foreach ($keys as $kn) {
                $kv = $vals[$kn];
                $tk .= $kv === null ? 'N|' : (is_int($kv) ? 'I' . $kv . '|' : 'S' . strlen($kv) . ':' . $kv . '|');
            }
            if (!isset($tuples[$tk])) {
                if (count($tuples) >= $maxGroups) {
                    throw self::err('ResultTooLargeException', "result exceeds maxGroups ($maxGroups)");
                }
                $tuples[$tk] = [array_map(fn($kn) => $vals[$kn], $keys), 0, self::aggInit($aggs, $byName, $types)];
            }
            $tuples[$tk][1]++;
            self::aggAdd($aggs, $tuples[$tk][2], $vals, $byName, $types);
        } elseif ($aggs !== []) {
            self::aggAdd($aggs, $st, $vals, $byName, $types);
        }
    }

    /** After the scan: the non-finite checks, then the result. */
    public static function finish(string $term, array $aggs, array $st, array $tuples): mixed
    {
    if ($term === 'groupAgg') {
        foreach ($tuples as $t) {
            self::aggFinite($aggs, $t[2]);
        }
        $out = [];
        foreach ($tuples as [$kv, $cnt, $tst]) {
            $out[] = array_merge($kv, [$cnt], self::aggResult($aggs, $tst));
        }
        return $out;
    }
    if ($aggs !== []) {
        self::aggFinite($aggs, $st);
        $res = self::aggResult($aggs, $st);
        return $term === 'aggregate' ? $res : $res[0];
    }
    }
}

/** "[v1,v2,...]" for an aggregate() result; checks the packed-list shape. */
function bf_fmt_list(mixed $r): string
{
    if (!is_array($r) || !array_is_list($r)) {
        return 'NOT A LIST';
    }
    return '[' . implode(',', array_map('bf_val', $r)) . ']';
}

/** "[k1,...,count,agg1,...]" per groupAgg row, joined by "; "; checks every row is a packed list. */
function bf_fmt_lists(mixed $r): string
{
    if (!is_array($r) || !array_is_list($r)) {
        return 'NOT A LIST';
    }
    $o = [];
    foreach ($r as $g) {
        if (!is_array($g) || !array_is_list($g)) {
            return 'BAD ELEMENT SHAPE';
        }
        $o[] = bf_fmt_list($g);
    }
    return $o ? implode('; ', $o) : '[]';
}


/** The extended where* calls of bf_apply(). */
function bf_where_m2b(BatchFlow\Query $q, string $k, string $c, mixed $v): BatchFlow\Query
{
    return match ($k) {
        'ne' => $q->whereNe($c, $v),
        'lt' => $q->whereLt($c, $v),
        'gte' => $q->whereGte($c, $v),
        'lte' => $q->whereLte($c, $v),
        'in' => $q->whereIn($c, $v),
    };
}

/** bf_gexec() for the extended terms 'min' | 'max' | 'avg' | 'aggregate' | 'groupAgg'. $x: col, aggs, keys, maxGroups. */
function bf_gexec_m2b(string $data, array $schema, array $opts, array $preds, string $term, array $x): string
{
    try {
        $q = bf_apply(bf_q($data, $schema, $opts), $preds);
        return match ($term) {
            'min' => bf_fmt($q->min($x['col'])),
            'max' => bf_fmt($q->max($x['col'])),
            'avg' => bf_fmt($q->avg($x['col'])),
            'aggregate' => bf_fmt_list($q->aggregate($x['aggs'])),
            'groupAgg' => bf_fmt_lists($q->groupAgg($x['keys'], $x['aggs'], $x['maxGroups'] ?? 1000000)),
        };
    } catch (Throwable $e) {
        return bf_cls($e) . ': ' . $e->getMessage();
    }
}
