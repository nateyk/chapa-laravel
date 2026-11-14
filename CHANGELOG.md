# Changelog

## Unreleased

- Added validated payout webhook metadata including fee and OTP details, masked destination fields, and safeguards against stale authorization/OTP events overwriting payout success/reversal.
- Added separate transfer approval live-mode opt-in and fail-closed test/live key matching.
- Added offline `release:check` CLI, standalone smoke assertions, runtime Composer security audit and distribution-archive build.
- Added detailed Chapa callback interoperability, Laravel 11 advisories and Packagist pre-release preparation documents.

- Added test-only Transfer Approval URL interoperability probe: always HTTP 400, redacted diagnostics only, gated by test-mode keys and explicit configuration; no auto routes.
- Added read-only payout reconciliation after submission/unknown network outcomes; validates server-side amount, currency and merchant/Chapa references without submitting or updating settlement.
- Added explicit Laravel 11/12/13 compatibility CI jobs alongside PHP 8.2/8.3/8.4 tests; includes regression coverage for probe safety and reconciliation.

- Added opt-in merchant-admin transfer approval flow with an encrypted approval ledger, requester/approver separation, protected idempotent dispatch and no automatic public route.
- Added fail-closed Transfer Approval URL signature verification, exact amount/destination checks and synchronous 200/400 response.
- Added test-only approval signature smoke checks, offline CLI simulation, Laravel Testbench request/decision/dispatch coverage and integration docs.
- Approval URL uses Chapa's legacy v1 callback contract; v2 support and signature canonicalization require Chapa test-mode validation.

- Added credential-free direct/inline payment simulations, payment-state matrix, and signed webhook replay CLI.
- Added sanitized machine-readable read-only Chapa Test API diagnostics.
- Hardened documented webhook event/status validation and fail-closed server verification consistency.
- Added guidance for stale delivery ordering, duplicate refunds, security and integration release gates.

- Added typed request DTOs for hosted checkout, direct/inline charges, payouts and refunds, plus strict typed verification snapshots.
- Added opt-in Laravel encrypted webhook inbox, deduplication, lease-based processors, reconciliation event, retry command, migration and documentation.
- Added DTO, webhook event and Laravel integration test cases (verification pending).


- Initial API v2 PHP client with hosted/direct/inline payment flows, payouts, refunds, subaccounts, balances, webhook verification, and Laravel integration.
- Local HTTP mock tests and PHP CI matrix.

No stable release has been published.
