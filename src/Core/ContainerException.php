<?php

namespace SecurityScanner\Core;

class ContainerException extends \Exception
{
    protected string $service;
    protected array $context;

    public function __construct(
        string $message,
        string $service = '',
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->service = $service;
        $this->context = $context;
    }

    public function getService(): string
    {
        return $this->service;
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