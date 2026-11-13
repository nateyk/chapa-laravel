<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

/** Console IO designed not to echo API keys or accept them as CLI arguments. */
final class Terminal implements TerminalInterface
{
    public function interactive(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    public function read(string $prompt): string
    {
        if (!$this->interactive()) {
            throw new \RuntimeException('Interactive terminal required. Use CHAPA_TEST_SECRET_KEY in the environment for --check.');
        }

        fwrite(STDOUT, $prompt);
        $line = fgets(STDIN);
        if ($line === false) {
            throw new \RuntimeException('Terminal input ended unexpectedly.');
        }

        return rtrim($line, "\r\n");
    }

    public function secret(string $prompt): string
    {
        if (!$this->interactive() || DIRECTORY_SEPARATOR === '\\' || !function_exists('shell_exec')) {
            throw new \RuntimeException('Secure hidden input unavailable. Set CHAPA_TEST_SECRET_KEY / CHAPA_TEST_PUBLIC_KEY via your environment instead.');
        }

        // Fail closed if terminal echo cannot be disabled. No plaintext fallback.
        $saved = shell_exec('stty -g </dev/tty 2>/dev/null');
        if (!is_string($saved) || ($saved = trim($saved)) === '') {
            throw new \RuntimeException('Could not read terminal echo state. Use environment variables instead.');
        }
        $disabled = shell_exec('stty -echo </dev/tty 2>/dev/null && printf disabled');
        if ($disabled !== 'disabled') {
            throw new \RuntimeException('Could not disable terminal echo. Use environment variables instead.');
        }

        try {
            fwrite(STDOUT, $prompt);
            $value = fgets(STDIN);
        } finally {
            // The mode is returned by stty itself, never user input.
            shell_exec('stty '.escapeshellarg($saved).' </dev/tty 2>/dev/null');
            fwrite(STDOUT, "\n");
        }
        if ($value === false) {
            throw new \RuntimeException('Terminal input ended unexpectedly.');
        }

        return rtrim($value, "\r\n");
    }

    public function line(string $message = ''): void
    {
        fwrite(STDOUT, $message."\n");
    }
}