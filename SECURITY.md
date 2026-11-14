# Vulnerability disclosure

Please do not open public issues containing API keys, customer data, or exploitable credentials. Report suspected vulnerabilities privately using GitHub's **Report a vulnerability** feature (Security Advisories) if it is enabled for this repository; otherwise contact the repository owner through GitHub before sharing sensitive technical details.

Supported versions: this project is currently pre-release and has no supported production versions.

Never process a webhook without verifying the exact raw-body signature. For the optional inbox, protect APP_KEY and restrict access to encrypted receipt records. Operators must configure retention and test-mode/live-mode separation. Payment fulfillment is not automatic and requires server-side verification plus domain-specific idempotency.

## Transfer Approval URL

The optional transfer approval callback is **disabled by default**. Use a dedicated, strong `CHAPA_TRANSFER_APPROVAL_SECRET`, distinct from API keys and v2 webhook secrets. Only a previously authenticated merchant-admin approval of an immutable payout request can authorize a signed callback; the callback never creates a payout. Keep admin routes behind merchant-scoped authorization and CSRF protection; only the explicitly signed Chapa callback route may be exempt from CSRF. The SDK encrypts stored destination details with Laravel APP_KEY and uses a database-unique merchant reference and persisted idempotency key.

**Compatibility caveat:** Chapa's published v1 Transfer Approval docs do not unambiguously specify the signed message; default raw-body HMAC-SHA256 must be verified with a genuine test callback. v2 payout use of that callback remains unconfirmed. Do not enable for real payouts before both checks.
