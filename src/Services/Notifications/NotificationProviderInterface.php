<?php

namespace SecurityScanner\Services\Notifications;

interface NotificationProviderInterface
{
    public function send(string $recipient, array $template, array $context): bool;

    public function test(): bool;

    public function getStatus(): array;
}