<?php

/** @generate-class-entries */

namespace BatchFlow;

/**
 * @strict-properties
 * @not-serializable
 */
final class Csv
{
    private function __construct() {}

    public static function scan(string $path, array $schema, array $options = []): Query {}

    /** @param resource $stream */
    public static function scanStream($stream, array $schema, array $options = []): Query {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Table
{
    private function __construct() {}

    public static function fromCsv(string $path, array $schema, array $options = []): Table {}

    /** @param resource $stream */
    public static function fromStream($stream, array $schema, array $options = []): Table {}

    public function rowCount(): int {}

    public function columnNames(): array {}

    public function schema(): array {}

    public function memoryBytes(): int {}

    public function query(): Query {}

    public function whereEq(string $column, int|float|bool|string|null $value): Query {}

    public function whereGt(string $column, int|float $value): Query {}

    public function whereNe(string $column, int|float|bool|string|null $value): Query {}

    public function whereLt(string $column, int|float $value): Query {}

    public function whereGte(string $column, int|float $value): Query {}

    public function whereLte(string $column, int|float $value): Query {}

    /** $values: a list of 1 to 1024 int|float|bool|string|null elements */
    public function whereIn(string $column, array $values): Query {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Query
{
    private function __construct() {}

    public function whereEq(string $column, int|float|bool|string|null $value): Query {}

    public function whereGt(string $column, int|float $value): Query {}

    public function whereNe(string $column, int|float|bool|string|null $value): Query {}

    public function whereLt(string $column, int|float $value): Query {}

    public function whereGte(string $column, int|float $value): Query {}

    public function whereLte(string $column, int|float $value): Query {}

    /** $values: a list of 1 to 1024 int|float|bool|string|null elements */
    public function whereIn(string $column, array $values): Query {}

    public function count(): int {}

    public function sum(string $column): int|float {}

    public function min(string $column): int|float|null {}

    public function max(string $column): int|float|null {}

    public function avg(string $column): ?float {}

    /** $aggregates: a list of 1 to 16 [op, column] pairs; returns one result per pair, in order */
    public function aggregate(array $aggregates): array {}

    public function summary(?string $sumColumn = null): Summary {}

    public function groupCount(string $key, int $maxGroups = 1000000): array {}

    public function groupSum(string $key, string $sum, int $maxGroups = 1000000): array {}

    /** $keys: 1 to 4 column names; $aggregates: 0 to 16 [op, column] pairs; rows [key1, ..., keyN, count, agg1, ..., aggM] */
    public function groupAgg(string|array $keys, array $aggregates, int $maxGroups = 1000000): array {}

    public function toArray(int $maxRows = 10000): array {}

    public function cursor(): Cursor {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Cursor
{
    private function __construct() {}

    public function fetch(int $maxRows = 1000): array {}

    public function close(): void {}
}

/**
 * @strict-properties
 */
final class Summary
{
    public readonly int $rowsScanned;
    public readonly int $rowsMatched;
    public readonly int|float|null $sum;

    private function __construct() {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Runtime
{
    private function __construct() {}

    /** @return array{version:string,simd:array{compiled:list<string>,auto:string}} */
    public static function info(): array {}
}

class BatchFlowException extends \RuntimeException {}

class SchemaException extends BatchFlowException {}

class CsvException extends BatchFlowException {}

class LimitException extends BatchFlowException {}

class ResultTooLargeException extends LimitException {}

class SumOverflowException extends BatchFlowException {}

class StreamException extends BatchFlowException {}
