<?php

namespace SecurityScanner\Core;

class ServiceException extends \Exception
{
    protected array $context;

    public function __construct(
        string $message,
        int $code = 0,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function addContext(string $key, $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }
}