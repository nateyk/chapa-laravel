# Migrating from the official legacy Chapa Laravel package to API v2

This guide compares the public [Chapa-Et/chapa-laravel](https://github.com/Chapa-Et/chapa-laravel) repository (its `src/Chapa.php`, provider, facade, config and README) with our community [Chapa API v2 SDK](https://github.com/nateyk/chapa-laravel). Refer to Chapa's [official v1-to-v2 migration guide](https://docs.chapa.global/docs/v2/migration) for the endpoint and payload contract.

**No backward-compatible v1 API adapter is provided.** The old SDK and this package have different package names, namespaces, endpoints, money units and transaction semantics. Avoid installing both global `Chapa` facade aliases simultaneously.

## Old method → v2 method

| Legacy `chapa/chapa-laravel` | New `nateyk/chapa-laravel` | Important difference |
| --- | --- | --- |
| `Chapa::initializePayment($data)` | `$chapa->payments()->hosted($data, $key)` or `hostedTyped()` | `/v2/payments/hosted`, integer cents, merchant reference and persisted idempotency key |
| `Chapa::verifyTransaction($ref)` | `$chapa->payments()->verifyTyped($ref)` | Verify SUCCESS plus exact amount/currency/reference before fulfillment |
| `Chapa::createTransfer($data)` | `$chapa->payouts()->createTyped($data, $key)` | Payouts are asynchronous; creation PENDING is not settlement |
| `Chapa::verifyTransfer($ref)` | `$chapa->payouts()->verifyTyped($ref)` | Verify documented payout states and support reversals |
| `Chapa::bulkTransfer($data)` | `$chapa->payouts()->bulk($data, $key)` | v2 batch uses `items`, not `bulk_data`; idempotency required |
| `Chapa::getBanks()` | `$chapa->payouts()->banks('ET')` | Use destination bank slugs instead of legacy bank codes |
| `Chapa::createSubaccount($data)` | `$chapa->subaccounts()->create($data)` | Migrate the split-recipient payload separately |
| `Chapa::validateWebhook($request)` | `$chapa->webhooks()->verify($request->getContent(), $request->header('x-chapa-signature'))` | Raw-body HMAC and constant-time verification; do not equate signed delivery to settlement |
| `Chapa::generateReference($prefix)` | `MerchantReference::generate('ord')` | Cryptographically unpredictable, maximum **20 ASCII characters** |
| `Chapa::getTransactionIDFromCallback()` | **No direct replacement** | Do not trust a redirect query string or embedded `resp` to authorize fulfillment |

## Laravel setup changes

The official legacy SDK uses `Chapa\Chapa\Facades\Chapa`, `chapa.secretKey` and `chapa.webhookSecret`. The new SDK uses `Nateyk\Chapa\Laravel\Facades\Chapa` or dependency injection with `Nateyk\Chapa\ChapaClient`, plus `chapa.secret_key` and `chapa.webhook_secret`. Environment variables `CHAPA_SECRET_KEY` and `CHAPA_WEBHOOK_SECRET` retain their usual names, but API keys and endpoint configuration must be tested for **v2**.

Install the new SDK using the [README's VCS instructions](../README.md#installation) while Packagist publication is pending, then optionally run:

```bash
php artisan vendor:publish --tag=chapa-config
```

## Safer hosted checkout flow

```php
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Support\IdempotencyKey;
use Nateyk\Chapa\Support\MerchantReference;

// Under your application's transaction, create a unique payment attempt and
// persist its merchant reference and idempotency key BEFORE issuing an API call.
$merchantReference = MerchantReference::generate('ord');
$idempotencyKey = IdempotencyKey::generate();
$attempt = /* persist attempt, amount, currency, reference, idempotency key */ null;
if ($attempt === null) {
    throw new LogicException('Persist the payment attempt before initializing checkout.');
}

/** @var ChapaClient $chapa */
$session = $chapa->payments()->hostedTyped([
    'amount' => $attempt->amount_cents,
    'currency' => $attempt->currency,
    'merchant_reference' => $merchantReference,
    'return_url' => route('checkout.return'),
], $idempotencyKey);

// The returned URL is validated as HTTPS on Chapa's documented checkout host.
// Persist the returned Chapa reference and URL, then redirect to the exact URL.
$attempt->storeCheckout($session->checkoutUrl, $session->chapaReference, $session->expiresAt);
return redirect()->away($session->checkoutUrl);
```

The official v2 documentation identifies the **final path segment** of the returned `data.checkout_url` as the Chapa reference. `HostedCheckoutSession` validates the returned HTTPS URL, expected host, reference and optional timestamps. It does **not** construct a checkout URL. If Chapa officially moves hosts, pass a reviewed allowlist as the third argument to `hostedTyped()`.

When the browser returns, **load the persisted payment attempt from your own authorized order/session**, not an untrusted `trx_ref` or `resp` parameter. Verify the payment with `$chapa->payments()->verifyTyped($attempt->merchant_reference)` and fulfill only when `matchesPayment($attempt->amount_cents, $attempt->currency, $attempt->merchant_reference)` is true, under application-level locking and a unique fulfillment ledger.

## Key v2 differences and test gates

- Amounts are integer **cents** (10000 = ETB 100.00), not the decimal amount strings used in many v1 integrations.
- `merchant_reference` is at most 20 characters. Use the secure generator **and** a database unique constraint; this is not an idempotency key.
- Payment, payout, and refund creation use a **persisted** `Idempotency-Key`. Never generate a fresh key for an automatic retry of the same monetary action.
- `checkout_url`, a customer redirect, or a successful API envelope is **not** payment success. Only server-verified `data.status === SUCCESS` and matching order fields may authorize fulfillment.
- V2 webhook payloads and signing rules require independent verification. During transition, route v1 and v2 callbacks separately rather than parsing them as interchangeable messages.
- Chapa's old Transfer Approval URL is documented for v1; its v2 payout interoperability and exact signature input remain **unconfirmed**.
- Run test-mode payment, payout, webhook and refund scenarios before removing the old integration. Ensure legacy and new records cannot pay or disburse twice.

No live Chapa payment, callback or transfer was executed in creating this migration guide.
