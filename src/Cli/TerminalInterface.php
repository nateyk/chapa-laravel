<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

interface TerminalInterface
{
    public function interactive(): bool;

    public function read(string $prompt): string;

    /** The typed value MUST NOT be echoed to the terminal. */
    public function secret(string $prompt): string;

    public function line(string $message = ''): void;
}