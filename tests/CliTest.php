<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Cli\TerminalInterface;
use Nateyk\Chapa\Cli\TestConsole;
use Nateyk\Chapa\Http\RawResponse;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private function console(CliFakeTerminal $terminal, MockTransport $transport, array $env): TestConsole
    {
        return new TestConsole(
            $terminal,
            static fn (string $private, ?string $public): ChapaClient => new ChapaClient($private, $public, transport: $transport),
            static fn (string $name): string|false => $env[$name] ?? false,
        );
    }

    public function testDoctorIsOfflineAndNeedsNoKeys(): void
    {
        $terminal = new CliFakeTerminal(false);
        $transport = new MockTransport();
        self::assertSame(0, $this->console($terminal, $transport, [])->run(['doctor']));
        self::assertCount(0, $transport->requests);
        self::assertStringContainsString('No API calls made', $terminal->output());
    }

    public function testNonInteractiveModeRequiresKeys(): void
    {
        $terminal = new CliFakeTerminal(false);
        $transport = new MockTransport();
        self::assertSame(2, $this->console($terminal, $transport, [])->run(['--check']));
        self::assertCount(0, $transport->requests);
    }

    public function testLivePrivateOrPublicKeyIsRejectedBeforeNetwork(): void
    {
        foreach ([
            ['CHAPA_TEST_SECRET_KEY' => 'CHAPA_LIVE_secret'],
            ['CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret', 'CHAPA_TEST_PUBLIC_KEY' => 'CHAPA_LIVE_public'],
            ['CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_bad key'],
        ] as $env) {
            $terminal = new CliFakeTerminal(false);
            $transport = new MockTransport();
            self::assertSame(2, $this->console($terminal, $transport, $env)->run(['--check']));
            self::assertCount(0, $transport->requests);
            self::assertStringNotContainsString('CHAPA_LIVE', $terminal->output());
            self::assertStringNotContainsString('CHAPA_TEST_secret', $terminal->output());
        }
    }

    public function testReadonlyChecksUseCorrectKeysAndNoSecretsInOutput(): void
    {
        $terminal = new CliFakeTerminal(false);
        $transport = new MockTransport();
        self::assertSame(0, $this->console($terminal, $transport, [
            'CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret',
            'CHAPA_TEST_PUBLIC_KEY' => 'CHAPA_TEST_public',
        ])->run(['--check']));

        self::assertCount(3, $transport->requests);
        self::assertSame(['GET', 'GET', 'GET'], array_column($transport->requests, 'method'));
        self::assertStringEndsWith('/v2/payments/methods', $transport->requests[0]['url']);
        self::assertStringContainsString('/v2/balances?currency=ETB', $transport->requests[1]['url']);
        self::assertStringEndsWith('/v2/payments/html', $transport->requests[2]['url']);
        self::assertSame('Bearer CHAPA_TEST_secret', $transport->requests[0]['headers']['Authorization']);
        self::assertSame('Bearer CHAPA_TEST_public', $transport->requests[2]['headers']['Authorization']);
        self::assertStringNotContainsString('CHAPA_TEST_', $terminal->output());
        self::assertStringContainsString('PASS All requested', $terminal->output());
    }

    public function testPublicKeyIsOptional(): void
    {
        $terminal = new CliFakeTerminal(false);
        $transport = new MockTransport();
        self::assertSame(0, $this->console($terminal, $transport, [
            'CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret',
        ])->run(['--check']));
        self::assertCount(2, $transport->requests);
        self::assertStringContainsString('SKIP Public-key', $terminal->output());
    }

    public function testInteractiveCancelledCheckoutNeverSendsRequest(): void
    {
        $terminal = new CliFakeTerminal(true, ['9', '100', 'no', '0'], ['CHAPA_TEST_secret', '']);
        $transport = new MockTransport();
        self::assertSame(0, $this->console($terminal, $transport, [])->run([]));
        self::assertCount(0, $transport->requests);
        self::assertSame(2, $terminal->secretCalls);
        self::assertStringContainsString('Cancelled.', $terminal->output());
        self::assertStringNotContainsString('CHAPA_TEST_secret', $terminal->output());
    }

    public function testInteractiveConfirmedCheckoutUsesIdempotencyAndIntegerCents(): void
    {
        $terminal = new CliFakeTerminal(true, ['9', '150', 'CREATE TEST CHECKOUT', '0']);
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"checkout_url":"https://checkout.chapa.co/test-123"}}');
        self::assertSame(0, $this->console($terminal, $transport, [
            'CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret',
        ])->run([]));
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertStringEndsWith('/v2/payments/hosted', $transport->requests[0]['url']);
        self::assertNotEmpty($transport->requests[0]['headers']['Idempotency-Key']);
        $body = json_decode($transport->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(150, $body['amount']);
        self::assertSame('ETB', $body['currency']);
        self::assertLessThanOrEqual(20, strlen($body['merchant_reference']));
        self::assertStringContainsString('https://checkout.chapa.co/test-123', $terminal->output());
    }

    public function testInvalidCheckoutAmountNeverSendsRequest(): void
    {
        $terminal = new CliFakeTerminal(true, ['9', '99', '0']);
        $transport = new MockTransport();
        self::assertSame(1, $this->console($terminal, $transport, [
            'CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret',
        ])->run([]));
        self::assertCount(0, $transport->requests);
    }

    public function testPaymentVerificationIsReadOnlyAndRedactsCustomer(): void
    {
        $terminal = new CliFakeTerminal(false);
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CH123","merchant_reference":"ORDER1","amount":10000,"currency":"ETB","status":"PENDING","customer":{"email":"private@example.com"}}}');
        self::assertSame(0, $this->console($terminal, $transport, [
            'CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret',
        ])->run(['--verify-payment=CH123']));
        self::assertCount(1, $transport->requests);
        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertStringContainsString('PENDING', $terminal->output());
        self::assertStringNotContainsString('private@example.com', $terminal->output());
        self::assertStringContainsString('do not fulfill', $terminal->output());
    }

    public function testCommandLineRejectsUnknownOptionsBeforeNetwork(): void
    {
        $terminal = new CliFakeTerminal(false);
        $transport = new MockTransport();
        self::assertSame(2, $this->console($terminal, $transport, [])->run(['--private-key=CHAPA_TEST_private']));
        self::assertCount(0, $transport->requests);
    }
}

final class CliFakeTerminal implements TerminalInterface
{
    /** @var list<string> */
    private array $messages = [];
    public int $secretCalls = 0;

    /** @param list<string> $inputs @param list<string> $secrets */
    public function __construct(
        private readonly bool $tty,
        private array $inputs = [],
        private array $secrets = [],
    ) {
    }

    public function interactive(): bool { return $this->tty; }
    public function read(string $prompt): string { return array_shift($this->inputs) ?? '0'; }
    public function secret(string $prompt): string { ++$this->secretCalls; return array_shift($this->secrets) ?? ''; }
    public function line(string $message = ''): void { $this->messages[] = $message; }
    public function output(): string { return implode("\n", $this->messages); }
}