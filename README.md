# Chapa Laravel / PHP SDK (API v2)

[![PHP SDK](https://github.com/nateyk/chapa-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/nateyk/chapa-laravel/actions/workflows/tests.yml)

A **community-maintained**, framework-independent PHP SDK for [Chapa API v2](https://docs.chapa.global/docs/v2), with optional Laravel auto-discovery, facade, configuration, and webhook middleware.

> **Status: pre-release / integration pending.** This is not an official Chapa SDK. The implementation uses the documented API v2 contract and local tests; live Chapa test-mode integration has not been verified. Do not use for real-money production traffic until you test all relevant flows and reconciliation in your Chapa account.

## Features

- Hosted checkout, including a validated typed checkout URL/reference and cryptographically secure 20-character merchant-reference generator; direct/inline wallet charges, payment verification, lists and methods
- Single, bulk, and merchant-to-merchant payouts; payout verification, bank list and payout list; typed payout lifecycle and destination validation
- Full/partial refunds, refund verification and refund list
- Split payment recipient (subaccount) create/list/update
- Multi-currency balances
- **Explicit idempotency keys** for creating payments, payouts and refunds; no hidden retries on money movement
- HTTP API errors with typed status/code, configurable timeouts, TLS verification and no redirects
- Chapa v2 webhook HMAC-SHA256 verification over the **raw body**
- Safe payment fulfillment helper that matches `SUCCESS`, amount, currency and merchant reference
- Laravel config publishing, service container binding, facade and opt-in webhook middleware
- Immutable typed DTOs for hosted/direct/inline payment requests, payouts, and refunds, plus typed payment/payout/refund verification views (raw arrays still work)
- Optional encrypted Laravel webhook inbox, replay protection, verified processing, retry/backoff and manual recovery commands
- Opt-in merchant-admin transfer approval ledger, encrypted destination details, signed approval URL callback and one-time payout dispatch (v1 callback protocol; v2 compatibility still unverified)
- Validated v2 payout webhook fields: OTP channels/attempts, payout type, fee, processor reference and auth reason; masked destination details and verified-only payout settlement helpers
- Offline release preflight CLI, runtime Composer security audit and package archive CI
- PHP 8.2+, no mandatory runtime dependencies beyond `ext-json`

## Test-mode CLI playground

You can test the SDK **directly from this repository** using your own Chapa Test secret and optional Test public key. Keys are securely prompted, not persisted or passed as CLI arguments. Live-mode keys are refused.

```bash
php bin/chapa doctor      # Offline PHP environment checks
php bin/chapa test        # Secure hidden key prompts; interactive test menu
php bin/chapa test --help # Available commands
```

The menu supports API key checks, methods, balances, verification, recent payments/payouts/refunds, banks and public-key checkout health. Creating a **simulated** hosted checkout requires an explicit `CREATE TEST CHECKOUT` confirmation.

For non-interactive API checks use `CHAPA_TEST_SECRET_KEY` and optional `CHAPA_TEST_PUBLIC_KEY` from your secret manager and run `php bin/chapa test --check`. For fully offline tests run `php tests/cli-smoke.php`.

Read the [CLI playground guide](docs/CLI-PLAYGROUND.md) for key handling, menu choices, safety and troubleshooting.

## Offline scenario testing and webhook review

The CLI also runs **credential-free, zero-network** simulations. These exercise SDK direct/inline wallet request construction, payment states, signed webhook replay, duplicate deliveries, partial refunds, payout reversals and tampering checks. No real wallet prompts or money movement occur.

```bash
php bin/chapa simulate all
php bin/chapa simulate direct
php bin/chapa simulate inline
php bin/chapa simulate states
php bin/chapa simulate webhooks
php bin/chapa simulate all --json
```

For actual **read-only Chapa Test API** checks, securely provide the test keys as described in the CLI guide, then run `php bin/chapa test --diagnostics=json`. The report is redacted and never includes keys, raw responses or customer information. Interactive test-menu options 11–15 also launch the offline scenarios.

Read [CLI playground](docs/CLI-PLAYGROUND.md), [Payout v2 authorization and lifecycle](docs/PAYOUT-V2.md), and the [deep v2 webhook review](docs/WEBHOOK-V2-AUDIT.md) for supported events, strict event/status validation, idempotency, signature rules and reconciliation responsibilities.

## Transfer Approval URL and merchant-admin review (opt-in)

The package now offers an **optional Laravel transfer approval workflow** for custom merchant applications: users request a payout, authenticated admins approve or reject it, and only approved requests can be submitted to Chapa. The approval callback accepts signed transfer details **only** when they exactly match an already admin-approved, in-flight/submitted payout. It returns 200 to authorize or 400 to reject; **200 is never proof of settlement**.

**Compatibility warning:** Chapa documents Transfer Approval URLs under its older **v1 Transfers** API. Its v2 payout documentation does not confirm that this mechanism is invoked for v2 payouts. The legacy docs also do not unambiguously define the HMAC message. The included default verifier uses HMAC-SHA256 over the exact raw body and fails closed; validate against a genuine Chapa test callback before enabling this feature in production. By default it is **disabled**, and the SDK registers no public callback or admin routes.

```bash
php artisan vendor:publish --tag=chapa-config
php artisan vendor:publish --tag=chapa-migrations
php artisan migrate
php tests/approval-smoke.php           # offline; synthetic signatures
php bin/chapa simulate approvals       # offline; no secret key, no network
```

The new **test-only signature probe** always rejects callbacks, logs only fixed status enums, and is disabled by default. The admin manager also provides `verifySubmitted($merchantReference)` for **read-only** reconciliation of submitted or uncertain payouts without re-POSTing. See the guide for how to register the separate probe route with Chapa Test Mode and remove it after testing.

See [Transfer Approval URL and admin workflow](docs/TRANSFER-APPROVAL.md), [illustrative protected admin routes](examples/laravel-transfer-admin.php), and [sample admin dashboard Blade view](examples/laravel-admin-chapa-transfer-requests.blade.php).

### Laravel compatibility security note

The CI matrix now explicitly tests Laravel 11, 12 and 13. Composer currently blocks **all published Laravel 11 versions** due to security advisories; its isolated compatibility job uses a temporary audit override with a visible warning, but **normal SDK and production installs do not bypass security policy**. Laravel 11 API compatibility is not a production-safety certification. See [release gates](docs/RELEASE.md).

### Offline release preflight

Check SDK metadata and see explicit blockers **without any Chapa credentials or HTTP requests**:

```bash
php bin/chapa release:check
php bin/chapa release:check --json
```

The CI package gate confirms zero third-party runtime dependencies, audits development dependencies, and validates an archive. Neither operation creates a tag, registers Packagist or establishes real-money readiness. Read [Packagist preparation](docs/PACKAGIST-RELEASE.md), [Laravel 11 advisory review](docs/LARAVEL-11-SECURITY.md) and [Chapa Approval URL compatibility](docs/CHAPA-CALLBACK-COMPATIBILITY.md).

## Installation

When published to Packagist, install using:

```bash
composer require nateyk/chapa-laravel
```

**Until Packagist publication**, register a VCS repository in your application's `composer.json`, then require its development branch:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/nateyk/chapa-laravel" }
  ]
}
```

```bash
composer require nateyk/chapa-laravel:dev-main
```

For production, pin a reviewed/tagged release instead of `dev-main`.

## Laravel setup

Laravel automatically discovers the package's service provider. Optional config publishing:

```bash
php artisan vendor:publish --tag=chapa-config
```

Set keys in your Laravel `.env` (never commit real keys):

```dotenv
CHAPA_SECRET_KEY=CHAPA_TEST_your_private_key
CHAPA_PUBLIC_KEY=CHAPA_TEST_your_public_key
CHAPA_WEBHOOK_SECRET=your_chapa_webhook_signature_secret
```

Keys for test and live environments must not be mixed. Both use the `https://api.chapa.global` origin.

### Hosted checkout

New in the v2 SDK: use `MerchantReference::generate('ord')` to create a cryptographically random 20-character merchant reference and `payments()->hostedTyped($payload, $storedIdempotencyKey)` to parse a validated checkout URL, Chapa reference and expiration timestamp:

```php
use Nateyk\Chapa\Support\MerchantReference;

$merchantReference = MerchantReference::generate('ord'); // Persist once per attempt.
$session = $chapa->payments()->hostedTyped([
    'amount' => 10000, 'currency' => 'ETB',
    'merchant_reference' => $merchantReference,
], $storedIdempotencyKey);

$checkoutUrl = $session->checkoutUrl;
$chapaReference = $session->chapaReference;
```

The typed helper validates the returned HTTPS checkout host (default: Chapa's documented `checkout.chapa.global`), without constructing a new URL or assuming a payment succeeded. If Chapa changes the host, explicitly review and supply a trusted-host list. **Persist your attempt before calling the API.**

Migrating an older application? Read the [official legacy package → v2 migration guide](docs/MIGRATE-FROM-V1.md).


```php
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Support\PaymentVerifier;

// In a Laravel controller; obtain the client from dependency injection.
public function checkout(ChapaClient $chapa)
{
    $order = /* Load and authorize your pending order */;
    // Persist the idempotency key with this order attempt, reuse on retries.
    $operationKey = 'order-'.$order->id.'-attempt-'.$order->payment_attempt;

    $response = $chapa->payments()->hosted([
        'amount' => $order->amount_cents, // 10000 = ETB 100.00
        'currency' => 'ETB',
        'merchant_reference' => $order->chapa_reference, // <= 20 characters
        'customer' => ['email' => $order->customer_email],
        'return_url' => route('checkout.return'),
    ], $operationKey);

    $checkoutUrl = $response->data()['checkout_url'];
    // Store checkout URL + merchant reference on the order/payment attempt.
    return redirect()->away($checkoutUrl);
}

// After redirect or webhook, verify with Chapa before fulfillment.
$verified = $chapa->payments()->verify($order->chapa_reference);
if (PaymentVerifier::matches($verified, $order->amount_cents, 'ETB', $order->chapa_reference)) {
    // Fulfill atomically and idempotently only once (e.g., DB transaction + unique constraint).
}
```

A successful HTTP response or a returned `checkout_url` **does not** mean a successful payment. Fulfill only after server-side verification.

### Plain PHP

```php
use Nateyk\Chapa\ChapaClient;

require 'vendor/autoload.php';

$chapa = new ChapaClient(privateKey: getenv('CHAPA_SECRET_KEY'));
$payment = $chapa->payments()->verify('CH0Vn2VASWCi');
var_dump($payment->data());
```

## Other operations

```php
use Nateyk\Chapa\Support\IdempotencyKey;

// Generate ONCE per operation, store durably, reuse for safe network retries.
$key = IdempotencyKey::generate();

$chapa->payments()->direct([
    'payment_method' => 'telebirr',
    'amount' => 10000,
    'currency' => 'ETB',
    'customer' => ['phone_number' => '251911000000'],
], $key);

$chapa->payments()->validateDirect(['reference' => 'CH0Vn2VASWCi', 'payment_method' => 'telebirr']);
$chapa->payments()->methods();
$chapa->payouts()->banks('ET');
$chapa->payouts()->create([
    'amount' => 10000,
    'currency' => 'ETB',
    'account' => ['account_number' => '251911000000', 'account_name' => 'Abebe Kebede', 'bank_slug' => 'telebirr'],
], IdempotencyKey::generate()); // Persist this *separate* payout key before sending.

$chapa->refunds()->create(['payment_reference' => 'CH0Vn2VASWCi'], IdempotencyKey::generate());
$chapa->balances()->get('ETB');
```

See [all SDK methods](docs/API.md), [webhook security](docs/SECURITY.md), [durable Laravel webhook inbox](docs/WEBHOOK-INBOX.md) and [Laravel checkout example](examples/laravel-checkout.php).

## Verify Chapa v2 webhooks

The SDK provides signature verification, **not an automatically enabled public endpoint**:

```php
use Illuminate\Support\Facades\Route;
use Nateyk\Chapa\Laravel\Http\Middleware\VerifyChapaWebhook;

Route::post('/chapa/webhook', [YourChapaWebhookController::class, 'handle'])
    ->middleware(VerifyChapaWebhook::class);
```

In Laravel's `bootstrap/app.php`, exempt **only this webhook path** from CSRF, using the framework's `validateCsrfTokens(except: ['chapa/webhook'])` configuration. Do not exempt arbitrary routes. Use the event's exact raw bytes and check `mode`, payment reference, amount and currency before acting. **Re-verify the payment/payout/refund server-side** and deduplicate deliveries transactionally.

## Typed requests (optional)

```php
use Nateyk\Chapa\Dto\HostedCheckoutRequest;
use Nateyk\Chapa\Dto\RefundRequest;

$checkout = $chapa->payments()->hosted(new HostedCheckoutRequest(
    amount: 10000,
    currency: 'ETB',
    merchantReference: 'ORDER123',
    customer: ['email' => 'buyer@example.com'],
    returnUrl: 'https://your-store.example/checkout/return',
), $storedIdempotencyKey);

$verified = $chapa->payments()->verifyTyped('ORDER123');
if ($verified->matchesPayment(10000, 'ETB', 'ORDER123')) {
    // Fulfill once, inside your application's business transaction.
}
```

The original array-based methods remain supported. Typed verification snapshots reject undocumented response shapes instead of silently assuming a successful payment.

## Durable Laravel webhook inbox (opt-in)

Publish the migration with `php artisan vendor:publish --tag=chapa-migrations`, run `php artisan migrate`, and register the authenticated inbox route explicitly. A scheduled Artisan command reconciles and dispatches stored deliveries; your app remains responsible for idempotent order-level fulfillment. Follow [the complete inbox guide](docs/WEBHOOK-INBOX.md). **The package does not automatically register a public route.**

## Test

```bash
php tests/smoke.php            # No composer required; local mocked HTTP tests
composer install
vendor/bin/phpunit --testdox # PHPUnit suite
```

CI tests PHP 8.2, 8.3 and 8.4. No real payments are sent by tests.

## Important API v2 distinctions

| Concern | Chapa v2 requirement |
| --- | --- |
| Amount | Integer **cents**; 10000 means ETB 100.00 |
| Base URL | `https://api.chapa.global` |
| Payment success | `data.status === 'SUCCESS'` **after verification** |
| Webhook event status | Lowercase top-level `status`, unlike verification |
| Idempotency | Required for payment, payout and refund creation |
| Direct metadata | `metadata` (not `meta`) |
| Hosted/inline metadata | `meta` |
| Inline auth | Public key, not private key |
| Payout batch currency | Top-level `currency` (not `default_currency`) |
| Lists | Cursor pagination; cursor *request* parameter not specified in published v2 docs |

Docs: [Chapa API v2](https://docs.chapa.global/docs/v2), [webhooks](https://docs.chapa.global/docs/v2/integrations/webhooks), [migration](https://docs.chapa.global/docs/v2/migration).

## Release status and contribution

See [release and Packagist checklist](docs/RELEASE.md), [contribution guide](CONTRIBUTING.md) and [security policy](SECURITY.md). Packagist publishing and real Chapa test-mode integration remain outstanding; do not deploy this pre-release SDK for live funds.

## License

MIT. Community project; not affiliated with or endorsed by Chapa.
