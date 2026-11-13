<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/MockTransport.php';

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Cli\TerminalInterface;
use Nateyk\Chapa\Cli\TestConsole;
use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Tests\MockTransport;

final class CliSmokeTerminal implements TerminalInterface
{
    public array $messages = [];
    public function __construct(public bool $tty = false, public array $answers = [], public array $secrets = []) {}
    public function interactive(): bool { return $this->tty; }
    public function read(string $prompt): string { return array_shift($this->answers) ?? '0'; }
    public function secret(string $prompt): string { return array_shift($this->secrets) ?? ''; }
    public function line(string $message = ''): void { $this->messages[] = $message; }
    public function output(): string { return implode("\n", $this->messages); }
}

$assertions = 0;
$check = static function (bool $pass, string $label) use (&$assertions): void {
    ++$assertions;
    if (!$pass) { throw new RuntimeException('CLI smoke FAIL: '.$label); }
};
$make = static function (CliSmokeTerminal $io, MockTransport $transport, array $keys): TestConsole {
    return new TestConsole(
        $io,
        static fn (string $private, ?string $public): ChapaClient => new ChapaClient($private, $public, transport: $transport),
        static fn (string $name): string|false => $keys[$name] ?? false,
    );
};

// Live credentials are never accepted, even if someone sets a misleading variable name.
$io = new CliSmokeTerminal();
$transport = new MockTransport();
$check($make($io, $transport, ['CHAPA_TEST_SECRET_KEY' => 'CHAPA_LIVE_real'])->run(['--check']) === 2, 'Reject live keys');
$check(count($transport->requests) === 0, 'No calls with live keys');
$check(!str_contains($io->output(), 'CHAPA_LIVE_real'), 'No key disclosure');

// Test keypair exercises private and public endpoints without moving money.
$io = new CliSmokeTerminal();
$transport = new MockTransport();
$check($make($io, $transport, [
    'CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret',
    'CHAPA_TEST_PUBLIC_KEY' => 'CHAPA_TEST_public',
])->run(['--check']) === 0, 'Read-only checks succeed');
$check(count($transport->requests) === 3, 'Three read-only requests');
$check(array_column($transport->requests, 'method') === ['GET', 'GET', 'GET'], 'Read only');
$check(!str_contains($io->output(), 'CHAPA_TEST_secret'), 'No private key in output');
$check($transport->requests[2]['headers']['Authorization'] === 'Bearer CHAPA_TEST_public', 'Public authorization');

// Creation requires explicit confirmation. Amount always integer cents.
$io = new CliSmokeTerminal(true, ['9', '100', 'no', '0']);
$transport = new MockTransport();
$check($make($io, $transport, ['CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret'])->run([]) === 0, 'Cancelled checkout cleanly');
$check(count($transport->requests) === 0, 'No POST without confirmation');

$io = new CliSmokeTerminal(true, ['9', '150', 'CREATE TEST CHECKOUT', '0']);
$transport = new MockTransport();
$transport->responses[] = new RawResponse(200, '{"status":"success","data":{"checkout_url":"https://checkout.chapa.co/test-123"}}');
$check($make($io, $transport, ['CHAPA_TEST_SECRET_KEY' => 'CHAPA_TEST_secret'])->run([]) === 0, 'Confirmed simulated checkout');
$check(count($transport->requests) === 1, 'Only one POST');
$check($transport->requests[0]['method'] === 'POST', 'Hosted checkout request');
$check(!empty($transport->requests[0]['headers']['Idempotency-Key']), 'Idempotency key added');
$check(json_decode($transport->requests[0]['body'], true)['amount'] === 150, 'Amount is integer cents');

printf("OK: %d CLI smoke assertions passed\n", $assertions);