# API v2 method map

Every method returns `Nateyk\Chapa\Http\ApiResponse`. Use `->json()` for the full parsed envelope, `->data()` for the `data` field, and `->operationSucceeded()` **only** for HTTP/API request success (not money movement). Network failures throw `TransportException`; HTTP/API errors throw `ApiException`, with `statusCode` and nullable `errorCode`.

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
- Payouts can use `account` or `account_reference`; batch payouts take `items` and optional top-level `currency`.
- Refunds omit `amount` for full refunds and specify an integer-cent amount for partial refunds.
- Subaccount operations are not idempotency-cached; use your own deduplication and state locking.

Source of truth: <https://docs.chapa.global/docs/v2>. V1 `transaction/initialize` and `transaction/verify` endpoints are **not** used by this package.
