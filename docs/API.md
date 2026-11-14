# API v2 method map

The existing API methods return `Nateyk\Chapa\Http\ApiResponse` (new `verifyTyped()` methods return typed `VerifiedTransaction` objects). Use `->json()` for the full parsed envelope, `->data()` for the `data` field, and `->operationSucceeded()` **only** for HTTP/API request success (not money movement). Network failures throw `TransportException`; HTTP/API errors throw `ApiException`, with `statusCode` and nullable `errorCode`.

| PHP method | API endpoint | Authentication | Idempotency |
| --- | --- | --- | --- |
| `payments()->hosted($body, $key)` | `POST /v2/payments/hosted` | Private | Required |
| `payments()->direct($body, $key)` | `POST /v2/payments/direct` | Private | Required |
| `payments()->validateDirect($body)` | `POST /v2/payments/direct/validate` | Private | Not cached |
| `payments()->inline($body, $key)` | `POST /v2/payments/inline` | Public | Required |
| `payments()->validateInline($body)` | `POST /v2/payments/inline/validate` | Public | Not cached |
| `payments()->verify($reference)` | `GET /v2/payments/{reference}/verify` | Private | — |
| `payments()->methods()` | `GET /v2/payments/methods` | Private | — |
| `payments()->list(['limit' => 10])` | `GET /v2/payments` | Private | — |
| `payments()->htmlStatus()` | `GET /v2/payments/html` | Public | — |
| `payouts()->create($body, $key)` | `POST /v2/payouts` | Private | Required |
| `payouts()->bulk($body, $key)` | `POST /v2/payouts/bulk` | Private | Required |
| `payouts()->b2b($body, $key)` | `POST /v2/payouts/b2b` | Private | Required |
| `payouts()->verify($reference)` | `GET /v2/payouts/{reference}/verify` | Private | — |
| `payouts()->banks('ET')` | `GET /v2/payouts/banks` | Private | — |
| `payouts()->list(['limit' => 10])` | `GET /v2/payouts` | Private | — |
| `refunds()->create($body, $key)` | `POST /v2/refunds` | Private | Required |
| `refunds()->verify($reference)` | `GET /v2/refunds/{reference}/verify` | Private | — |
| `refunds()->list(['limit' => 10])` | `GET /v2/refunds` | Private | — |
| `subaccounts()->create($body)` | `POST /v2/subaccounts` | Private | Not cached |
| `subaccounts()->update($reference, $body)` | `PUT /v2/subaccounts/{reference}` | Private | Not cached |
| `subaccounts()->list(['limit' => 10])` | `GET /v2/subaccounts` | Private | — |
| `balances()->get('ETB')` | `GET /v2/balances?currency=ETB` | Private | — |

The SDK deliberately **does not guess** Chapa's undocumented cursor query parameter. `list()` forwards caller-provided query filters without claiming their names are supported. Validate fields against Chapa before enabling custom pagination/filter parameters in production.

## Payload notes

- Hosted uses `meta`, nested `customer`, and optional `subaccounts` for revenue splitting.
- Direct uses `metadata`, nested `customer` with `phone_number`, and `payment_method`.
- Inline uses `meta`, `mobile` plus top-level customer fields, and a **public** key.
- Payouts can use `account` (account_number, account_name, bank_slug) or `account_reference`; when both exist, inline account fields take precedence. Batch payouts take `items` and optional top-level `currency`.
- Refunds omit `amount` for full refunds and specify an integer-cent amount for partial refunds.
- Subaccount operations are not idempotency-cached; use your own deduplication and state locking.

Source of truth: <https://docs.chapa.global/docs/v2>. V1 `transaction/initialize` and `transaction/verify` endpoints are **not** used by this package.

## Typed request and response APIs

For hosted checkout, use `payments()->hostedTyped($payload, $idempotencyKey)` to return `HostedCheckoutSession` with a validated `checkoutUrl`, final-segment `chapaReference`, optional `createdAt` and `expiresAt`. The third argument is an explicitly reviewed list of trusted HTTPS checkout hosts (default: `['checkout.chapa.global']`). A valid hosted session is **not** verified payment completion. Use `MerchantReference::generate('ord')` for a cryptographically unpredictable reference of at most 20 bytes (also enforce database uniqueness).


The following methods accept DTOs as an alternative to arrays; all require the same explicit idempotency key as before:

- `payments()->hosted(new HostedCheckoutRequest(...), $key)`
- `payments()->direct(new DirectChargeRequest(...), $key)`
- `payments()->inline(new InlineChargeRequest(...), $key)`
- `payouts()->create(new PayoutRequest(...), $key)`
- `refunds()->create(new RefundRequest(...), $key)`

`payments()->verifyTyped($reference)`, `payouts()->verifyTyped($reference)`, and `refunds()->verifyTyped($refundReference)` return `Nateyk\Chapa\Dto\VerifiedTransaction`. Payment verification supports `matchesPayment($expectedAmountCents, $currency, $merchantReference)`. Refunds deliberately have no inferred `succeeded()` status until a documented final state is confirmed.

Typed payout creation is available through `payouts()->createTyped($payload, $idempotencyKey)`, returning `PayoutInitiation`. Verification through `verifyTyped()` exposes `payoutStatus()`, `payoutRequiresAction()`, and `matchesPayout($amountCents, $currency, $merchantReference)`.

Chapa v2 does not document a payout `authorization_url` or OTP submission endpoint. See [PAYOUT-V2.md](PAYOUT-V2.md).

For event inbox operation, see [WEBHOOK-INBOX.md](WEBHOOK-INBOX.md).
