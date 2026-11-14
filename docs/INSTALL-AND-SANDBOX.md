# Laravel setup and Chapa test-mode acceptance

This guide covers actual application installation and an operator-run **authorized Chapa Test Mode** acceptance session. The SDK's mocked automated tests cannot confirm real API behavior or the legacy Transfer Approval URL contract for v2 payouts.

## 1. Install in a supported Laravel app

Preferred new applications: Laravel **12 or 13** with current security patches. Laravel 11 is API-compatibility tested but outside its security maintenance window; see [security review](LARAVEL-11-SECURITY.md).

Until the maintainer registers the community package on Packagist, edit the consuming application's `composer.json` to add the VCS repository:

```json
{
  "repositories": [{"type": "vcs", "url": "https://github.com/nateyk/chapa-laravel"}]
}
```

Then run:

```bash
composer require nateyk/chapa-laravel:dev-main
php artisan vendor:publish --tag=chapa-config
php artisan vendor:publish --tag=chapa-migrations
php artisan migrate
php artisan chapa:doctor --json
```

Pin a reviewed commit or release when your application is deployed; `dev-main` is not a stable-production install. The doctor command is **local only**, makes no network request, hides keys and account information, and intentionally reports `live_money_certified: false` even when local checks pass.

## 2. Configure your private merchant test environment

Configure **privately** through your application environment/secret manager:

```dotenv
CHAPA_SECRET_KEY=CHAPA_TEST_your_test_secret_key
CHAPA_PUBLIC_KEY=CHAPA_TEST_your_test_public_key
CHAPA_WEBHOOK_SECRET=your_separate_webhook_signing_secret
CHAPA_MODE=test
CHAPA_WEBHOOK_INTEGRATION=default
CHAPA_TRANSFER_APPROVAL_ENABLED=false
CHAPA_TRANSFER_APPROVAL_LIVE_ENABLED=false
CHAPA_TRANSFER_APPROVAL_PROBE_ENABLED=false
```

Keep `APP_KEY` backed up: the SDK encrypts raw webhook receipts and transfer destination details with Laravel's encryption service. Do not commit `.env`, expose keys in frontend code, or share keys in GitHub issues/chat. The test and live API modes use the same Chapa origin; keep test/live credentials separated.

**Never set `CHAPA_TRANSFER_APPROVAL_ENABLED=true` for production merely because the callback passes synthetic offline tests.** Chapa's published approval callback is documented for the older v1 transfer API; v2 payout interoperability and signature canonicalization remain unverified.

## 3. Register a signed webhook receiver explicitly

In `routes/web.php`:

```php
use Illuminate\Support\Facades\Route;
use Nateyk\Chapa\Laravel\Http\Controllers\ChapaWebhookController;

Route::post('/chapa/webhook', ChapaWebhookController::class);
```

Exempt **only** the webhook path from CSRF in Laravel 11/12/13 `bootstrap/app.php`:

```php
->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['chapa/webhook']);
})
```

Configure a genuine public **HTTPS** URL and webhook **Signature** authentication in your Chapa test dashboard. The SDK verifies the exact raw-body `x-chapa-signature` HMAC before parsing or storing the payload; it persists authenticated deliveries with replay protection and returns HTTP 200 for durable receipt, including duplicates. See [Webhook Inbox](WEBHOOK-INBOX.md).

## 4. Configure processing separately from HTTP acknowledgement

Test the worker manually:

```bash
php artisan chapa:webhooks:process --limit=50
```

Or schedule in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
Schedule::command('chapa:webhooks:process --limit=50')->everyMinute()->withoutOverlapping();
```

Configure your application's listener for `Nateyk\Chapa\Laravel\Events\ChapaWebhookProcessed` and enforce unique order/payment fulfillment in your own database transaction. `paymentMatches()` / `payoutMatches()` check the independently verified API response. **A verified webhook receipt never directly executes a payout, refund or order fulfillment in the SDK.**

Do not treat successful HTTP acknowledgement, redirect, checkout initialization or payout creation as settlement. Chapa's verification API must agree with your stored reference, amount and currency. Duplicate or out-of-order events must not overwrite a newer settled state.

## 5. Exercise the real Chapa Test API without moving live funds

The repository CLI can run locally with hidden credentials or environment-provided **test** keys. The safe default is read-only:

```bash
php bin/chapa test --diagnostics=json  # Chapa Test API, GET only
php bin/chapa test --verify-payment=YOUR_TEST_REFERENCE
php bin/chapa test --verify-payout=YOUR_TEST_PAYOUT_REFERENCE
php bin/chapa simulate all --json   # Offline; no keys and no HTTP
```

Running `php bin/chapa test` opens an interactive menu. The hosted checkout action requires entering an amount in integer cents and an exact `CREATE TEST CHECKOUT` confirmation. It validates the returned HTTPS checkout host before displaying a URL and must still be verified for successful payment. Do not use live keys, real bank or wallet destinations, or real-money transfer requests during acceptance.

### Genuine Chapa test-mode acceptance matrix

| Test | Required expected result |
| --- | --- |
| Test key read-only checks | Methods and balance GET work with **test** secret; no private keys logged |
| Hosted checkout + verify | Initialization returns an approved HTTPS checkout host; payment verification matches merchant reference, amount, currency and SUCCESS only after payment completes |
| Payment webhook | Genuine signed test delivery receives 200 and is stored; invalid signature gets 401; duplicate delivery gets 200 and does not fulfill twice |
| Payment timing | Webhook received before verification is final remains retryable, never fulfills early |
| Payout creation | Only authorized **test-mode** operation; creation is PENDING, never marked delivered until verify SUCCESS |
| Payout auth/OTP | AUTH_NEEDED, OTP_NEEDED, OTP_FAILED do not initiate another payout or imply an OTP submission URL |
| Payout reversal | REVERSED must reconcile even after SUCCESS; do not allow a late OTP event to overwrite settlement |
| Refund reconciliation | Track each refund by its own reference; authenticate refund webhook and verify stored refund reference, especially partial refunds |
| Transfer Approval URL | **Blocked** until Chapa confirms v2 compatibility and exact signature test vector |

**No live transaction or genuine Chapa callback has been executed by the repository's CI.** The above matrix must be recorded by an authorized merchant using its own test account.

## 6. Validate the separate Transfer Approval URL safely

The actual Chapa dashboard Transfer Approval URL and the standard v2 webhook URL are **different integrations with different secrets**. Follow [Chapa callback compatibility](CHAPA-CALLBACK-COMPATIBILITY.md). When Chapa confirms that v2 invokes the dashboard approval callback, you may temporarily register the SDK's always-rejecting `ChapaTransferApprovalProbeController` on a public HTTPS **test-only** route with its separate secret. It returns HTTP 400 unconditionally and logs only redacted signature/payload outcomes. **It must never be used to approve a real transfer.** Remove the probe route after the experiment.

Do not use the Chapa dashboard's URL verification instead of your own authenticated admin approval workflow. The admin must approve a locked, immutable payout request **before** the application dispatches it, and the signed Chapa callback must independently match those approved details.

## 7. Laravel acceptance and operational checklist

- [ ] Unique merchant references and persisted per-attempt idempotency keys in your application's tables.
- [ ] Public webhook endpoint uses HTTPS, exact CSRF exemption, correct test secret and no request-body logging.
- [ ] Worker/scheduler active; receipt retention, encrypted backups and retry monitoring configured.
- [ ] Admin routes protected by login, merchant-specific Gate/policy, CSRF and independent admin/requester roles.
- [ ] A tenant cannot view, approve or dispatch another merchant's payout reference. Configure integration scoping appropriately; the SDK does not own tenant identities.
- [ ] Transfer-reversal, unknown network outcome and refund-reference reconciliation update your real financial ledger atomically.
- [ ] Chapa callback signature and v2 applicability verified from genuine test evidence; until then both approval flags remain false.
- [ ] Clean Laravel 12/13 consumer CI, PHP/Composer CI and package preflight checks green.
- [ ] Maintainer separately authorizes a Packagist prerelease once all external release gates pass.

Track unresolved items in [release issue #1](https://github.com/nateyk/chapa-laravel/issues/1).
