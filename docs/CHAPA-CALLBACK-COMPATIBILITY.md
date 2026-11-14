# Chapa v2 Transfer Approval URL: interoperability evidence required

**Status: NOT CONFIRMED for v2.** [Chapa's legacy transfers guide](https://developer.chapa.co/transfer/transfers) describes server approval for v1 transfers. The published [v2 payout creation](https://docs.chapa.global/docs/v2/payouts/payout) and [verification](https://docs.chapa.global/docs/v2/payouts/verify-payout) documentation does not explicitly confirm that the dashboard Approval URL is used for v2 payouts.

The legacy guide states that `Chapa-Signature` is HMAC-SHA256 involving `approval_secret`, but does **not clearly specify the HMAC message bytes or encoding**. Its sample signature is not a test vector with a known fake secret and exact payload. The SDK's default raw-body HMAC is an unverified interpretation.

## Questions for Chapa support

1. Does the dashboard Approval URL run for **POST /v2/payouts** in test and live mode?
2. What exact message bytes are signed, using what HMAC algorithm and output encoding? Can Chapa supply a *synthetic* known-secret, exact-payload, expected-digest vector?
3. Is approval-callback `amount` a major-unit decimal string (legacy) or v2 integer cents? Is `currency` present?
4. What are the timeout, retry and response-code rules for HTTP 200 and 400?
5. Does URL approval replace or coexist with `AUTH_NEEDED`, `OTP_NEEDED` and email OTP approval? What is the precedence?
6. Can this be exercised entirely with test keys and no real funds?

**Never share real API keys, secret, signature, recipient bank account, raw callback payload or live transaction references with support or in public issues.**

## Safe test-only validation

1. Keep `CHAPA_TRANSFER_APPROVAL_ENABLED=false` and `CHAPA_TRANSFER_APPROVAL_LIVE_ENABLED=false`.
2. Use a test-only private key beginning `CHAPA_TEST_` and separate approval secret, set `CHAPA_TRANSFER_APPROVAL_PROBE_ENABLED=true` and register the opt-in HTTPS probe route. See [Transfer Approval guide](TRANSFER-APPROVAL.md).
3. With Chapa's confirmation, trigger a **test-mode** callback. The probe **always returns HTTP 400**; it never approves a payout.
4. Inspect only the fixed redacted signature/payload diagnostic categories; never record secrets or account data.
5. If the signature mismatches, get a confirmed signing algorithm. Never disable verification or accept a constant-secret token as a substitute.
6. Remove the probe route, reset its flag and restore the dashboard setting after the experiment.
7. Before any live deployment, separately complete authenticated positive/negative sandbox cases and server-side payout verification.

No genuine Chapa callback has yet been received by this project. V2 compatibility remains a release blocker.
