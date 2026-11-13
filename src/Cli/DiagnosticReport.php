<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

/** Redacted, stable CLI diagnostics. Never store headers, body, credentials or customer data. */
final class DiagnosticReport
{
    /** @var list<array{id:string,result:string,detail:string}> */
    private array $checks = [];

    public function __construct(public readonly string $mode, public readonly string $scenario)
    {
        if (!in_array($mode, ['offline', 'chapa-test-api'], true)) {
            throw new \InvalidArgumentException('Unsupported diagnostic mode.');
        }
    }

    public function add(string $id, bool $pass, string $detail = ''): void
    {
        if (preg_match('/^[a-z0-9._-]{1,80}$/D', $id) !== 1
            || preg_match('/^[a-zA-Z0-9 .,()_:\/-]{0,140}$/D', $detail) !== 1) {
            throw new \InvalidArgumentException('Diagnostic labels must be static and non-sensitive.');
        }
        $this->checks[] = ['id' => $id, 'result' => $pass ? 'pass' : 'fail', 'detail' => $detail];
    }

    public function skip(string $id, string $detail = ''): void
    {
        if (preg_match('/^[a-z0-9._-]{1,80}$/D', $id) !== 1
            || preg_match('/^[a-zA-Z0-9 .,()_:\/-]{0,140}$/D', $detail) !== 1) {
            throw new \InvalidArgumentException('Diagnostic labels must be static and non-sensitive.');
        }
        $this->checks[] = ['id' => $id, 'result' => 'skip', 'detail' => $detail];
    }

    public function merge(self $other): void
    {
        if ($other->mode !== $this->mode) {
            throw new \InvalidArgumentException('Cannot merge different diagnostic modes.');
        }
        array_push($this->checks, ...$other->checks);
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $counts = ['pass' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($this->checks as $check) {
            ++$counts[$check['result']];
        }
        return [
            'schema' => 'chapa-sdk-diagnostics/v1',
            'environment' => $this->mode,
            'scenario' => $this->scenario,
            'network' => $this->mode === 'offline' ? 'none' : 'read-only-test-api',
            'summary' => $counts,
            'checks' => $this->checks,
        ];
    }

    public function successful(): bool
    {
        return $this->json()['summary']['fail'] === 0;
    }

    public function toJson(): string
    {
        return json_encode($this->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function toText(): string
    {
        $result = $this->json();
        $lines = ['Chapa SDK diagnostics: '.$this->scenario.' ('.$this->mode.')'];
        foreach ($result['checks'] as $check) {
            $lines[] = strtoupper($check['result']).' '.$check['id'].($check['detail'] === '' ? '' : ' - '.$check['detail']);
        }
        $lines[] = sprintf('Summary: %d passed, %d failed, %d skipped.', $result['summary']['pass'], $result['summary']['fail'], $result['summary']['skip']);
        return implode("\n", $lines);
    }
}
