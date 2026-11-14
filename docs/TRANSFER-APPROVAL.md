# Transfer Approval URL — Laravel merchant admin workflow (opt-in)

## What this feature does

The Chapa merchant dashboard (Account Settings → Transfer Approval) exposes a **Transfer Approval URL** and an **Approval Secret**, in addition to email OTP. Chapa's older [Transfers documentation](https://developer.chapa.co/transfer/transfers) describes a server approval callback:

- Chapa issues an HTTPS **POST** with header **`Chapa-Signature`** and transfer fields `reference`, `amount`, `bank`, `account_name`, `account_number`.
- Your approval endpoint should respond **HTTP 200** to authorize transfer processing or **HTTP 400** to reject it.
- **200 means approved to continue, not that funds were delivered.** Always verify actual payout settlement separately.

**Important version distinction:** This callback mechanism is documented in Chapa's **legacy v1 Transfers section**. The [v2 Create Payout](https://docs.chapa.global/docs/v2/payouts/payout) and [v2 Verify Payout](https://docs.chapa.global/docs/v2/payouts/verify-payout) docs do not explicitly confirm that v2 payouts call the same Transfer Approval URL. Ask Chapa to confirm v2 support before enabling it for live payouts.

**Important signature interoperability gate:** The legacy docs say HMAC-SHA256 with an `approval_secret`, but do not explicitly define the HMAC *message/canonicalization*. The SDK uses **HMAC-SHA256 over the exact raw request body** and requires a 64-character hex signature. This is secure and fail-closed, **but is not yet verified against a genuine Chapa approval callback**. If Chapa uses a different signing formula, calls will return 400 until an authenticated, documented compatibility adaptation is implemented. Never disable signature verification, accept a secret-only token, or approve from unsigned fields. For a confirmed different algorithm, the consuming Laravel application can bind an audited `Nateyk\Chapa\Transfers\ApprovalSignatureVerifier` implementation; it must cryptographically authenticate the complete payload, not just compare a reusable token.

## Admin-before-Chapa process

```text
User requests withdrawal inside YOUR application
    │
    ▼
TransferApprovalManager::request()  [encrypted PII, unique merchant ref]
    │
    ▼
Dashboard: authorized merchant admin reviews request and destination
    ├─ Reject ───────► manager::reject()  [never sends money]
    └─ Approve ──────► manager::approve()
                           │
                           ▼
                    manager::dispatchApproved() [one idempotent API POST]
                           │
                           ▼
                    Chapa POST /v2/payouts (if compatibility verified)
                           │
                           ▼
                    Chapa calls configured Approval URL
                           │
                    verify HMAC + previously approved record + exact destination
                           ├─ HTTP 400: reject mismatch
                           └─ HTTP 200: authorize to continue
                           │
                           ▼
                    Chapa status PENDING, then verify/webhooks for final state
```

Chapa expects **an immediate HTTP decision**. You cannot hold this callback open for a human to click Approve later. The human/admin decision MUST already exist in your application before dispatch. This avoids holding network requests open and avoids accidentally approving a fraudulent transfer.

## 1. Installation

```bash
php artisan vendor:publish --tag=chapa-config
php artisan vendor:publish --tag=chapa-migrations
php artisan migrate
```

The new `chapa_transfer_approvals` table is created only when you publish and run migrations. No endpoint or payout is enabled automatically.

In your **server-side `.env` only**, configure:

```dotenv
CHAPA_TRANSFER_APPROVAL_ENABLED=false
CHAPA_TRANSFER_APPROVAL_SECRET=replace_with_random_separate_32_plus_character_secret
CHAPA_TRANSFER_APPROVAL_MODE=test
CHAPA_TRANSFER_APPROVAL_INTEGRATION=default
```

Generate a dedicated strong random approval secret and put the exact same secret in the Chapa dashboard's **Approval Secret** field. Do not reuse `CHAPA_SECRET_KEY` (API secret), `CHAPA_WEBHOOK_SECRET` (webhook signing key), or Laravel `APP_KEY`. Do not commit secrets. Enable only in test after confirming your signature scheme and v2 compatibility with Chapa. Set `CHAPA_TRANSFER_APPROVAL_ENABLED=true` to allow the opt-in feature.

The server approval callback and v2 payout webhook are **different endpoints** with **different signatures**:

| Purpose | Approval URL | v2 payout webhook |
| --- | --- | --- |
| Example path | `/chapa/transfer-approval` | `/chapa/webhook` |
| Header | `Chapa-Signature` | `x-chapa-signature` |
| Secret | `CHAPA_TRANSFER_APPROVAL_SECRET` | `CHAPA_WEBHOOK_SECRET` |
| Payload | Legacy transfer fields, major-unit decimal amount string | v2 event fields, integer cents as string |
| Response | 200 authorizes, 400 rejects | 200 acknowledges durable receipt |
| Business result | **Not settlement** | Verify final payout state |

## 2. Register only the callback route, no automatic public routes

In `routes/web.php` (or another explicit Laravel route file):

```php
use Illuminate\Support\Facades\Route;
use Nateyk\Chapa\Laravel\Http\Controllers\ChapaTransferApprovalController;

Route::post('/chapa/transfer-approval', ChapaTransferApprovalController::class);
```

In Laravel 11/12/13 `bootstrap/app.php`, exempt only the callback path from CSRF (the endpoint uses its own signature):

```php
$middleware->validateCsrfTokens(except: ['chapa/transfer-approval']);
```

Use HTTPS and a stable public URL. Never place the callback behind session login. Register this URL in your Chapa dashboard **only once the test callback signature has been validated**. In the dashboard select URL verification as the intended approval method; do not rely on conflicting URL/OTP checkboxes without confirming the dashboard's precedence rules.

The controller returns blank **200/400**, does not reflect request fields, and never creates a payout. It accepts only approved, already-dispatched (or in-flight) reference + **exact** amount + currency + bank slug + account name + account number. Leading zeros in account numbers are preserved. Callback `amount="2000.00"` is interpreted as ETB 2000.00 (200000 cents), **not** 2000 cents. If currency is absent, the SDK only accepts previously approved ETB payouts; other currencies require an explicit matching currency.

## 3. Add your merchant dashboard flow

The package supplies **business logic services**, not a public administrative interface or user roles. Your project must use its own policies/Gates and own admin screens. See [illustrative Laravel admin routes](../examples/laravel-transfer-admin.php) and [sample Blade review screen](../examples/laravel-admin-chapa-transfer-requests.blade.php). Never expose `approve`, `reject`, or `dispatchApproved` through unauthenticated routes, and do not let users approve their own withdrawals. The `reviewQueue()` method returns only masked destination numbers and must likewise be called exclusively within a merchant-admin Gate.

```php
use Nateyk\Chapa\Laravel\Transfers\TransferApprovalManager;

// Authenticated user: submit a request. Server must decide authorized amount/account.
$id = app(TransferApprovalManager::class)->request([
    'amount' => 10000, // ETB 100.00 in cents
    'currency' => 'ETB',
    'merchant_reference' => 'PAYOUT123', // unique, <=20 letters/digits/_/-
    'account' => [
        'account_number' => '251911000000',
        'account_name' => 'Abebe Kebede',
        'bank_slug' => 'telebirr',
    ],
    'reason' => 'Merchant withdrawal',
], requester: 'user:22');

// Authorized merchant admin action (behind Laravel Gate):
$manager = app(TransferApprovalManager::class);
$approved = $manager->approve('PAYOUT123', 'admin:7');
// Admin can instead reject('PAYOUT123', 'admin:7') before dispatch.

// Only after approval (preferably a queued, authorized job):
$created = $manager->dispatchApproved('PAYOUT123');
// created->status is normally PENDING. Store created->chapaReference.
// Do NOT treat creation as successful settlement.
```

The manager's protected states are `requested → approved → sending → submitted`. Rejections are allowed only before dispatch. A failed/uncertain network request leaves the record in `unknown`, not silently re-POSTed. The idempotency key was generated **before** dispatch, persisted and reused. If the network result is uncertain, verify the merchant reference with Chapa and investigate before any manual retry; **never create a different idempotency key for the same attempt**.

Approval records are isolated by `integration`, `mode`, and `merchant_reference`, with a database uniqueness constraint and encrypted transfer details. The SDK stores the approver/requester IDs as audit labels. The host app must protect admin routes, enforce tenant boundaries, avoid excessive privileges, and implement account owner verification, business limits and compliance checks.

## 4. Track the final result

Use existing SDK methods:

```php
$verification = $chapa->payouts()->verifyTyped('PAYOUT123');
if ($verification->matchesPayout(10000, 'ETB', 'PAYOUT123')) {
    // Idempotent, atomic account/balance settlement in your merchant application.
} elseif ($verification->payoutRequiresAction()) {
    // Notify an admin that Chapa requires more authorization or OTP.
}
```

Also process authenticated `payout.success`, `payout.failed`, `payout.reversed`, `payout.auth_needed`, and `payout.otp_needed` webhooks from the **separate** v2 webhook endpoint. Server approval does not replace payout verification or webhook handling.

## 5. Test plan before production

1. Run `php tests/approval-smoke.php` and the full PHP/Laravel CI matrix with synthetic data.
2. Confirm Chapa invokes the Approval URL for **v2** payout creation in your business test mode.
3. Capture only an **authorized redacted diagnostic** of the genuine callback and verify the exact `Chapa-Signature` signing formula and decimal amount units; never publish secrets, account numbers, raw payloads or signatures.
4. Test valid approved requests return 200; unapproved, rejected, mismatched, unsigned, altered, expired preapproval and duplicate-dispatch requests return 400 or cannot initiate.
5. Confirm Chapa's response to both 200 and 400 and verify payout status afterward.
6. Confirm idempotency, account/amount correctness, negative balances, double-submit, webhook replay, reversals and admin audit logs.
7. Implement rate limiting, HTTPS trusted proxy configuration, key rotation, alerts and retention in the host app.

This SDK component is **pre-release and opt-in**. Until Chapa's v2 compatibility and actual signature calculation are verified, do not enable the callback on live transfers.
