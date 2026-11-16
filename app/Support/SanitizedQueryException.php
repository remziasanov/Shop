<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use ReflectionProperty;
use RuntimeException;

/**
 * A QueryException without personal data (NF-15, D-206 hardening S3-8).
 *
 * `QueryException::getMessage()` contains the bound values (an order insert that fails would log the name, phone and
 * e-mail) and the driver text may echo a value too (MySQL «Duplicate entry '…' for key»). This exception keeps the
 * SQLSTATE, the driver code, the SQL with `?` placeholders and, when it can be extracted safely, the name of the
 * violated key or column. The file, line and trace of the original are kept for debugging; the original itself is
 * NOT chained (its message would be printed by the log formatter as «Next …»).
 */
final class SanitizedQueryException extends RuntimeException
{
    private function __construct(
        string $message,
        int $code,
        private readonly string $sqlState,
        private readonly string $sql,
    ) {
        parent::__construct($message, $code);
    }

    public static function from(QueryException $e): self
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $sqlState = (string) ($info[0] ?? $e->getCode());
        $driverCode = (int) ($info[1] ?? 0);
        $constraint = self::constraint((string) ($info[2] ?? $e->getPrevious()?->getMessage() ?? ''));

        $message = 'SQLSTATE['.$sqlState.']: database error'
            .($driverCode !== 0 ? ' (driver code '.$driverCode.')' : '')
            .($constraint !== null ? ' ['.$constraint.']' : '')
            .' (SQL: '.$e->getSql().')';

        $sanitized = new self($message, $driverCode, $sqlState, $e->getSql());

        // Keep the place of the failure; copy the trace without chaining the original.
        foreach (['file' => $e->getFile(), 'line' => $e->getLine()] as $property => $value) {
            (new ReflectionProperty(RuntimeException::class, $property))->setValue($sanitized, $value);
        }

        (new ReflectionProperty(\Exception::class, 'trace'))->setValue($sanitized, $e->getTrace());

        return $sanitized;
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Name of the violated key/column from the driver text; never a value. Null when it cannot be told safely.
     */
    private static function constraint(string $driverMessage): ?string
    {
        // MySQL: Duplicate entry '...' for key 'table.index' (the value part is dropped, only the key name is read).
        if (preg_match("/\\bfor key '([A-Za-z0-9_.\\-]+)'\\s*$/", $driverMessage, $m) === 1) {
            return 'key '.$m[1];
        }

        // SQLite: UNIQUE constraint failed: table.column[, table.column]
        if (preg_match('/^(?:.*?:\\s)?(UNIQUE|NOT NULL|CHECK|FOREIGN KEY) constraint failed:\\s*([A-Za-z0-9_.,\\s]+)$/', $driverMessage, $m) === 1) {
            return $m[1].' '.trim($m[2]);
        }

        return null;
    }
}
