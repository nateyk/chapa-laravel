<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Cli\DiagnosticReport;
use Nateyk\Chapa\Cli\TerminalInterface;
use Nateyk\Chapa\Cli\OfflineScenarios;
use Nateyk\Chapa\Cli\ReadOnlyDiagnostics;
use Nateyk\Chapa\Cli\TestConsole;
use Nateyk\Chapa\Http\RawResponse;
use PHPUnit\Framework\TestCase;

final class OfflineScenariosTest extends TestCase
{
    public function testEveryOfflineScenarioAndJsonReport(): void
    {
        $runner = new OfflineScenarios();
        foreach (['direct', 'inline', 'states', 'webhooks', 'all'] as $name) {
            $report = $runner->run($name);
            self::assertTrue($report->successful(), $name);
            $document = json_decode($report->toJson(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('offline', $document['environment']);
            self::assertSame('none', $document['network']);
            self::assertSame(0, $document['summary']['fail']);
            self::assertGreaterThan(0, $document['summary']['pass']);
            self::assertStringNotContainsString('CHAPA_TEST_SYNTHETIC_PRIVATE', $report->toJson());
            self::assertStringNotContainsString('251900000000', $report->toJson());
        }
    }

    public function testUnknownScenarioFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OfflineScenarios())->run('create-real-payout');
    }

    public function testReadOnlyDiagnosticsNeverEmitRawResponsesOrCredentials(): void
    {
        $mock = new MockTransport();
        $mock->responses[] = new RawResponse(200, '{"status":"success","data":{"email":"secret-person@example.com"}}');
        $mock->responses[] = new RawResponse(401, '{"status":"error","message":"secret-token","error":{"code":"UNAUTHORIZED"}}');
        $client = new ChapaClient('CHAPA_TEST_private', 'CHAPA_TEST_public', transport: $mock);
        $report = (new ReadOnlyDiagnostics())->run($client, false);
        self::assertFalse($report->successful());
        self::assertCount(2, $mock->requests);
        self::assertSame(['GET', 'GET'], array_column($mock->requests, 'method'));
        self::assertStringNotContainsString('secret-token', $report->toJson());
        self::assertStringNotContainsString('secret-person@example.com', $report->toJson());
        self::assertStringNotContainsString('CHAPA_TEST_private', $report->toJson());
        self::assertStringContainsString('HTTP 401', $report->toJson());
    }

    public function testCliInteractiveOfflineOptionsDoNotContactChapa(): void
    {
        $terminal = new ScenarioFakeTerminal(true, ['11', '12', '13', '14', '15', '0']);
        $externalTransport = new MockTransport();
        $console = new TestConsole(
            $terminal,
            static fn (string $secret, ?string $public): ChapaClient => new ChapaClient($secret, $public, transport: $externalTransport),
            static fn (string $key): string|false => match ($key) {
                'CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_fake',
                'CHAPA_TEST_PUBLIC_KEY' => 'CHAPA_TEST_fake_public',
                default => false,
            },
        );
        self::assertSame(0, $console->run([]));
        self::assertCount(0, $externalTransport->requests);
        self::assertStringContainsString('OFFLINE ONLY', $terminal->output());
        self::assertStringContainsString('Summary:', $terminal->output());
    }

    public function testCliHeadlessDiagnosticsCanEmitJsonOnly(): void
    {
        $terminal = new ScenarioFakeTerminal(false);
        $mock = new MockTransport();
        $console = new TestConsole(
            $terminal,
            static fn (string $secret, ?string $public): ChapaClient => new ChapaClient($secret, $public, transport: $mock),
            static fn (string $key): string|false => $key === 'CHAPA_TEST_SECRET_KEY' ? 'CHAPA_TEST_fake' : false,
        );
        self::assertSame(0, $console->run(['--diagnostics=json']));
        $data = json_decode($terminal->output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('chapa-test-api', $data['environment']);
        self::assertSame('read-only-test-api', $data['network']);
        self::assertSame(2, $data['summary']['pass']);
        self::assertSame(1, $data['summary']['skip']);
    }

    public function testDiagnosticReportCannotIncludeSensitiveFreeformText(): void
    {
        $report = new DiagnosticReport('offline', 'test');
        $this->expectException(\InvalidArgumentException::class);
        $report->add('email', true, 'email=user@example.com secret=abc');
    }
}


final class ScenarioFakeTerminal implements TerminalInterface
{
    /** @var list<string> */
    private array $messages = [];
    /** @param list<string> $responses */
    public function __construct(private readonly bool $tty, private array $responses = []) {}
    public function interactive(): bool { return $this->tty; }
    public function read(string $prompt): string { return array_shift($this->responses) ?? '0'; }
    public function secret(string $prompt): string { throw new \RuntimeException('No secret needed for this test.'); }
    public function line(string $message = ''): void { $this->messages[] = $message; }
    public function output(): string { return implode("\n", $this->messages); }
}
