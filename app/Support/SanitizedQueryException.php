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
 * SQLSTATE, the driver code, the SQL with `?` placeholders and the safe part of the driver text: names of
 * tables, columns, keys and foreign keys, and connection-level errors (D-206). The file, line and trace of the original are kept for debugging; the original itself is
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
        $constraint ??= self::constraint((string) $e->getPrevious()?->getMessage());

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
     * Safe diagnostics from the driver text: names of tables, columns, keys and foreign keys, and connection-level
     * errors. Anything that can be a bound value (the quoted part of «Duplicate entry '…'», «Incorrect integer value:
     * '…'») is never copied: every pattern captures identifiers only. Null when nothing can be told safely.
     */
    private static function constraint(string $driverMessage): ?string
    {
        // PDO prefix of a full message: «SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry …».
        $driverMessage = preg_replace('/^SQLSTATE\\[[^\\]]*\\]:\\s*(?:[A-Za-z ]+:\\s*)?(?:\\d+\\s+)?/', '', $driverMessage) ?? $driverMessage;

        // Identifier as the drivers print it: `a`.`b`, 'a.b', a.b.
        $id = "[`']?([A-Za-z0-9_\\-]+(?:[`']?\\.[`']?[A-Za-z0-9_\\-]+)*)[`']?";

        $patterns = [
            // MySQL: Duplicate entry '<value>' for key 'table.index': only the key name.
            ['/\\bfor key '.$id.'\\s*$/', 'key %s'],
            ['/^Column '.$id.' cannot be null/', 'column %s cannot be null'],
            ['/^Field '.$id." doesn't have a default value/", 'field %s has no default'],
            ['/^Data too long for column '.$id.' at row/', 'data too long for column %s'],
            ['/^Out of range value for column '.$id.' at row/', 'out of range value for column %s'],
            ["/^Incorrect (\\w+) value: '.*' for column ".$id.' at row \\d+$/s', 'incorrect %s value for column %s'],
            ['/^Unknown column '.$id.' in /', 'unknown column %s'],
            ['/^Table '.$id." doesn't exist/", 'table %s does not exist'],
            ['/^Unknown database '.$id.'/', 'unknown database %s'],
            // SQLite.
            ['/^no such (table|column): '.$id.'$/', 'no such %s %s'],
            ['/^table '.$id.' has no column named '.$id.'$/', 'table %s has no column %s'],
        ];

        foreach ($patterns as [$pattern, $format]) {
            if (preg_match($pattern, $driverMessage, $m) === 1) {
                return sprintf($format, ...array_slice($m, 1));
            }
        }

        // MySQL foreign key: "... a foreign key constraint fails (`db`.`t`, CONSTRAINT `fk` FOREIGN KEY (`c`) REFERENCES `p` (`id`) ...)":
        // names only; the pattern refuses anything with a quote that is not a backtick (a value).
        if (preg_match('/foreign key constraint fails \\(([`A-Za-z0-9_.,\\s()\\-]+)\\)$/', $driverMessage, $m) === 1) {
            return 'foreign key ('.trim($m[1]).')';
        }

        // SQLite: UNIQUE constraint failed: table.column[, table.column] (also with the PDO prefix).
        if (preg_match('/^(?:.*?:\\s)?(UNIQUE|NOT NULL|CHECK|FOREIGN KEY) constraint failed:?\\s*([A-Za-z0-9_.,\\s]*)$/', $driverMessage, $m) === 1) {
            return trim($m[1].' '.trim($m[2]));
        }

        // Connection-level errors (no values in them): «[2002] Connection refused», «[1045] Access denied for user …».
        if (preg_match('/\\[(1044|1045|1049|2002|2003|2006|2013|2054)\\]\\s*(.*)$/s', $driverMessage, $m) === 1) {
            $text = trim($m[2]);

            if (preg_match("/^(Connection refused|Connection timed out|No such file or directory|MySQL server has gone away|Lost connection[A-Za-z0-9 ]*|php_network_getaddresses: getaddrinfo[A-Za-z0-9_ :.\\-]*|Access denied for user '[^'\\s]*'@'[^'\\s]*'(?: \\(using password: (?:YES|NO)\\))?|Unknown database '[A-Za-z0-9_\\-]+')/", $text, $c) === 1) {
                return '['.$m[1].'] '.$c[1];
            }

            return '['.$m[1].']';
        }

        return null;
    }
}
