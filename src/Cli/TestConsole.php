<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Exceptions\ApiException;
use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Support\IdempotencyKey;

/** Developer-only test-mode CLI; never accepts live keys or stores credentials. */
final class TestConsole
{
    /**
     * @param (\Closure(string, ?string): ChapaClient)|null $clientFactory Test seam; otherwise builds a real client.
     * @param (\Closure(string): (string|false))|null $environment Test seam; otherwise reads process environment.
     */
    public function __construct(
        private readonly TerminalInterface $terminal = new Terminal(),
        private readonly ?\Closure $clientFactory = null,
        private readonly ?\Closure $environment = null,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        if ($args === ['--help'] || $args === ['-h'] || $args === ['help']) {
            $this->help();
            return 0;
        }
        if ($args === ['doctor']) {
            return $this->doctor();
        }
        if ($args !== [] && $args !== ['--check'] && !(count($args) === 1 && str_starts_with($args[0], '--verify-payment='))) {
            $this->terminal->line('Unknown option. Run: php bin/chapa test --help');
            return 2;
        }
        if ($args === [] && !$this->terminal->interactive()) {
            $this->terminal->line('An interactive TTY is required. Use php bin/chapa test --check with environment keys.');
            return 2;
        }

        try {
            [$private, $public] = $this->credentials();
            $client = $this->clientFactory !== null
                ? ($this->clientFactory)($private, $public)
                : new ChapaClient(privateKey: $private, publicKey: $public);
        } catch (\Throwable $exception) {
            // Deliberately do not print exception values; a custom dependency may contain a key.
            $this->terminal->line('Test-mode key setup failed. Use valid CHAPA_TEST_ keys; no keys were saved or sent.');
            return 2;
        }

        $this->terminal->line('Chapa API v2 TEST MODE — no live credentials allowed.');
        if ($args === ['--check']) {
            return $this->check($client, $public !== null) ? 0 : 1;
        }
        if ($args !== []) {
            $reference = substr($args[0], strlen('--verify-payment='));
            return $this->verifyPayment($client, $reference) ? 0 : 1;
        }
        return $this->menu($client, $public !== null);
    }

    private function help(): void
    {
        $this->terminal->line('Chapa API v2 — safe test-mode playground');
        $this->terminal->line('  php bin/chapa doctor                   # offline PHP requirements');
        $this->terminal->line('  php bin/chapa test                     # interactive secure key prompts and menu');
        $this->terminal->line('  php bin/chapa test --check             # read-only test API checks');
        $this->terminal->line('  php bin/chapa test --verify-payment=REF # read-only payment status');
        $this->terminal->line('Credentials: CHAPA_TEST_SECRET_KEY; optional CHAPA_TEST_PUBLIC_KEY.');
        $this->terminal->line('Never enter keys as command-line arguments or commit them into Git.');
        $this->terminal->line('Only the interactive hosted checkout command can create a test operation.');
    }

    private function doctor(): int
    {
        $checks = [
            'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
            'JSON extension' => extension_loaded('json'),
            'OpenSSL extension' => extension_loaded('openssl'),
            'HTTPS streams enabled' => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) === true,
        ];
        foreach ($checks as $label => $passed) {
            $this->terminal->line(($passed ? 'OK   ' : 'FAIL ').$label);
        }
        $this->terminal->line('No API calls made; no credentials requested.');
        return in_array(false, $checks, true) ? 1 : 0;
    }

    /** @return array{string, ?string} */
    private function credentials(): array
    {
        $private = $this->environment('CHAPA_TEST_SECRET_KEY');
        if ($private === null && $this->terminal->interactive()) {
            $private = $this->terminal->secret('Test SECRET key (hidden): ');
        }
        self::assertTestKey($private);

        $public = $this->environment('CHAPA_TEST_PUBLIC_KEY');
        if ($public === null && $this->terminal->interactive()) {
            $public = $this->terminal->secret('Test PUBLIC key (optional, hidden; Enter to skip): ');
            if ($public === '') {
                $public = null;
            }
        }
        if ($public !== null) {
            self::assertTestKey($public);
        }
        return [$private, $public];
    }

    private function environment(string $key): ?string
    {
        $value = $this->environment !== null ? ($this->environment)($key) : getenv($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function assertTestKey(?string $key): void
    {
        if ($key === null || !str_starts_with($key, 'CHAPA_TEST_') || strlen($key) <= strlen('CHAPA_TEST_')
            || preg_match('/[\s\x00-\x1f\x7f]/', $key) === 1) {
            throw new \InvalidArgumentException('Only valid test-mode keys are allowed.');
        }
    }

    private function check(ChapaClient $client, bool $hasPublic): bool
    {
        $ok = $this->attempt('Private key / payment methods', static fn (): ApiResponse => $client->payments()->methods());
        $balanceOk = $this->attempt('Private key / ETB balance', static fn (): ApiResponse => $client->balances()->get('ETB'));
        $ok = $balanceOk && $ok;
        if ($hasPublic) {
            $publicOk = $this->attempt('Public key / HTML checkout service', static fn (): ApiResponse => $client->payments()->htmlStatus());
            $ok = $publicOk && $ok;
        } else {
            $this->terminal->line('SKIP Public-key service check (no test public key supplied).');
        }
        $this->terminal->line($ok ? 'PASS All requested read-only API checks passed.' : 'FAIL One or more checks failed.');
        return $ok;
    }

    private function menu(ChapaClient $client, bool $hasPublic): int
    {
        $failed = false;
        while (true) {
            $this->terminal->line('');
            $this->terminal->line('1 Check API keys and connectivity  2 Payment methods  3 ETB balance');
            $this->terminal->line('4 Verify payment               5 Recent payments  6 Payouts');
            $this->terminal->line('7 Refunds                      8 Banks            9 Test hosted checkout');
            $this->terminal->line('10 Public-key HTML service      0 Exit');
            try {
                $choice = $this->terminal->read('Select: ');
                if ($choice === '0') {
                    return $failed ? 1 : 0;
                }
                switch ($choice) {
                    case '1': $success = $this->check($client, $hasPublic); break;
                    case '2': $success = $this->attempt('Payment methods', static fn (): ApiResponse => $client->payments()->methods()); break;
                    case '3': $success = $this->attempt('ETB balance', static fn (): ApiResponse => $client->balances()->get('ETB')); break;
                    case '4': $success = $this->verifyPayment($client, $this->terminal->read('Chapa or merchant reference: ')); break;
                    case '5': $success = $this->attempt('Recent payments', static fn (): ApiResponse => $client->payments()->list(['limit' => 5])); break;
                    case '6': $success = $this->attempt('Recent payouts', static fn (): ApiResponse => $client->payouts()->list(['limit' => 5])); break;
                    case '7': $success = $this->attempt('Recent refunds', static fn (): ApiResponse => $client->refunds()->list(['limit' => 5])); break;
                    case '8': $success = $this->attempt('Payout banks', static fn (): ApiResponse => $client->payouts()->banks('ET')); break;
                    case '9': $success = $this->hostedCheckout($client); break;
                    case '10':
                        $success = $hasPublic
                            ? $this->attempt('Public-key HTML service', static fn (): ApiResponse => $client->payments()->htmlStatus())
                            : $this->skipPublic();
                        break;
                    default: $this->terminal->line('Unknown option. Choose 0-10.'); continue 2;
                }
                $failed = !$success || $failed;
            } catch (\Throwable) {
                $this->terminal->line('Input failed or session ended. No credentials or raw responses were displayed.');
                return 2;
            }
        }
    }

    private function skipPublic(): bool
    {
        $this->terminal->line('SKIP Public-key service check; restart with a TEST public key.');
        return true;
    }

    private function attempt(string $label, callable $operation): bool
    {
        try {
            $response = $operation();
            if (!$response->operationSucceeded()) {
                $this->terminal->line('FAIL '.$label.' (API did not confirm success).');
                return false;
            }
            $this->terminal->line('PASS '.$label.' (HTTP '.$response->statusCode.').');
            return true;
        } catch (ApiException $exception) {
            $code = substr(preg_replace('/[^A-Za-z0-9_]/', '', $exception->errorCode ?? '') ?? '', 0, 48);
            $this->terminal->line('FAIL '.$label.' (HTTP '.$exception->statusCode.', API code '.($code === '' ? 'unknown' : $code).').');
            return false;
        } catch (\Throwable) {
            $this->terminal->line('FAIL '.$label.' (connection, validation, or response error; raw details hidden).');
            return false;
        }
    }

    private function verifyPayment(ChapaClient $client, string $reference): bool
    {
        if ($reference === '' || preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $reference) !== 1) {
            $this->terminal->line('Invalid reference: use only letters, digits, underscore, or hyphen.');
            return false;
        }
        try {
            $payment = $client->payments()->verifyTyped($reference);
            // Never print the customer object or raw API response (may contain PII).
            $this->terminal->line('Verified payment status: '.$payment->status);
            $this->terminal->line('Amount in cents: '.$payment->amount.' '.$payment->currency);
            $this->terminal->line($payment->succeeded()
                ? 'Payment SUCCESS. Applications must still match their own amount, currency and merchant reference before fulfillment.'
                : 'Payment is not confirmed SUCCESS; do not fulfill.');
            return true;
        } catch (ApiException $exception) {
            $this->terminal->line('Verification failed (HTTP '.$exception->statusCode.').');
        } catch (\Throwable) {
            $this->terminal->line('Verification failed (invalid reference, connection, or response).');
        }
        return false;
    }

    private function hostedCheckout(ChapaClient $client): bool
    {
        $amountText = $this->terminal->read('Amount in ETB cents (100 = 1.00 ETB, minimum 100): ');
        if (preg_match('/^[1-9][0-9]{0,7}$/D', $amountText) !== 1 || (int) $amountText < 100) {
            $this->terminal->line('Invalid amount. Enter integer cents from 100 to 99999999.');
            return false;
        }
        $amount = (int) $amountText;
        $confirm = $this->terminal->read('Type CREATE TEST CHECKOUT to send a simulated payment request: ');
        if ($confirm !== 'CREATE TEST CHECKOUT') {
            $this->terminal->line('Cancelled. No payment creation request sent.');
            return true;
        }
        $reference = 'SDK'.strtoupper(bin2hex(random_bytes(7)));
        $operationKey = IdempotencyKey::generate();
        $this->terminal->line('Merchant reference: '.$reference);
        $this->terminal->line('Idempotency key (reuse for this same operation only): '.$operationKey);
        try {
            $response = $client->payments()->hosted([
                'amount' => $amount, 'currency' => 'ETB', 'merchant_reference' => $reference,
            ], $operationKey);
            $data = $response->data();
            $checkoutUrl = is_array($data) ? ($data['checkout_url'] ?? null) : null;
            if (!$response->operationSucceeded() || !is_string($checkoutUrl)) {
                $this->terminal->line('Checkout was not confirmed by the API; do not retry with a new idempotency key.');
                return false;
            }
            $parsed = parse_url($checkoutUrl);
            if ($parsed === false || ($parsed['scheme'] ?? null) !== 'https'
                || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])) {
                $this->terminal->line('Unsafe or missing HTTPS checkout URL returned; not displaying it.');
                return false;
            }
            $this->terminal->line('Test checkout URL: '.$checkoutUrl);
            $this->terminal->line('Checkout initialization is NOT proof of payment. Verify before fulfilling anything.');
            return true;
        } catch (\Throwable) {
            $this->terminal->line('Checkout creation failed or outcome unknown. Use the same idempotency key for any manual retry.');
            return false;
        }
    }
}