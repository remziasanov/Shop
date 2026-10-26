<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * An error or a warning of one row.
 */
final readonly class RowIssue
{
    /**
     * @param  string  $field  field key ("price", "attr:Цвет") or '' for a row-level issue
     * @param  string  $column  Russian field label; the analyzer replaces it with the real file header
     * @param  string|null  $value  offending raw cell text (truncated by the consumer when stored)
     */
    public function __construct(
        public string $field,
        public string $column,
        public string $message,
        public ?string $value = null,
    ) {}

    /**
     * @return array{field: string, column: string, message: string, value: string|null}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'column' => $this->column,
            'message' => $this->message,
            'value' => $this->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['field'] ?? ''),
            (string) ($data['column'] ?? ''),
            (string) ($data['message'] ?? ''),
            isset($data['value']) ? (string) $data['value'] : null,
        );
    }

    public function withColumn(string $column): self
    {
        return new self($this->field, $column, $this->message, $this->value);
    }
}
