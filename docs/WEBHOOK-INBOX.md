# Durable Laravel webhook inbox (opt-in)

Chapa API v2 webhooks use `x-chapa-signature` = `HMAC-SHA256(secret, exact raw request body)`. Webhook event `status` is lowercase. **A successful webhook is not payment verification.** The SDK only acknowledges storage; no order is marked paid automatically.

## 1. Install and configure

```bash
php artisan vendor:publish --tag=chapa-config
php artisan vendor:publish --tag=chapa-migrations
php artisan migrate
```

Set `CHAPA_SECRET_KEY`, `CHAPA_WEBHOOK_SECRET`, `CHAPA_MODE=test` (or `live` for live keys and webhook configuration), and `CHAPA_WEBHOOK_INTEGRATION=default`. Set a distinct integration for separate Chapa configurations. Ensure Laravel `APP_KEY` is stable and backed up; encrypted webhook receipts become unreadable if it changes without re-encryption.

Chapa must send **Signature** webhooks to a TLS endpoint. Laravel apps must use correct trusted proxy and HTTPS configuration. Do not log raw request bodies, customer details, banking details, API tokens or signatures.

## 2. Register exactly one opt-in endpoint

```php
use Illuminate\Support\Facades\Route;
use Nateyk\Chapa\Laravel\Http\Controllers\ChapaWebhookController;

Route::post('/chapa/webhook', ChapaWebhookController::class);
```

For Laravel 11+, exempt `chapa/webhook` only from CSRF via `bootstrap/app.php`:

```php
$middleware->validateCsrfTokens(except: ['chapa/webhook']);
```

Do not place the route behind an end-user authentication middleware. The controller verifies the raw-body signature itself, validates environment, restricts the payload size and stores it in an encrypted database column. It responds with 200 only after a durable insert or identification of an already stored delivery. Signature-invalid requests receive 401, invalid event shapes 422, oversized payloads 413, and storage outages fail closed.

The database enforces a unique `(integration, mode, fingerprint)` key. Fingerprints are scoped to the configured integration and environment and hash exact request bytes. Different partial refunds with different payloads are not collapsed. The SDK **does not** dedupe merely on event name and payment reference.

## 3. Process receipts after they are safely stored

```bash
php artisan chapa:webhooks:process --limit=50
```

Schedule this command with Laravel Scheduler, for example in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
Schedule::command('chapa:webhooks:process --limit=100')->everyMinute()->withoutOverlapping();
```

The processor claims receipts atomically, checks the claim token before dispatch, and automatically retries failures with exponential backoff up to `CHAPA_WEBHOOK_MAX_ATTEMPTS` (default 8). Run `php artisan chapa:webhooks:retry {receiptId}` to retry a permanently failed receipt after investigating. Keep database backups; set a data retention policy appropriate for your jurisdiction and customer privacy obligations.

Payment and payout webhook receipts are reconciled via the Chapa `verify` API **before** dispatch. Refund webhooks refer to the original payment, not necessarily the refund reference: the SDK intentionally supplies `verification=null` for refund events. Retrieve refund references from your own refund creation records and verify those separately. Do not create a new refund in response to a refund notification.

## 4. Listen for an already-reconciled event

```php
use Illuminate\Support\Facades\Event;
use Nateyk\Chapa\Laravel\Events\ChapaWebhookProcessed;

Event::listen(ChapaWebhookProcessed::class, function (ChapaWebhookProcessed $notification): void {
    // Fetch & authorize the order by merchant reference using your own tenant scope.
    $order = /* your app's database lookup */;
    if ($order && $notification->paymentMatches(
        $order->amount_cents,
        $order->currency,
        $order->chapa_reference,
    )) {
        // Database unique constraint + transaction: fulfill at most once.
        // Check payment attempt identity and don't overwrite a newer status.
    }
});
```

Listener logic remains your responsibility. The inbox guarantees **at-least-once** processing, not exactly-once external effects. A worker can crash after a listener's external side effect but before receipt state is committed. Use payment/order-level unique constraints, transaction boundaries, monotonic state transitions, and idempotent external calls. `event_updated_at` is retained so listeners can reject out-of-order notifications. If listeners are queued, configure after-commit delivery and independent business idempotency.

**Security:** Do not expose a public API route that can manually call `WebhookProcessor` or arbitrarily fetch encrypted receipts. Protect operational commands and admin tools. Keep `APP_KEY`, Chapa API keys and signature secret on the server. Keep webhook payloads only as long as required, because they may contain PII.
