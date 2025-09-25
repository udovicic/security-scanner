<?php

namespace SecurityScanner\Core;

class ValidationException extends \Exception
{
    protected array $errors;

    public function __construct(string $message, array $errors = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?string
    {
        $firstField = array_key_first($this->errors);
        return $firstField ? $this->errors[$firstField][0] ?? null : null;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getErrorsForField(string $field): array
    {
        return $this->errors[$field] ?? [];
    }
}