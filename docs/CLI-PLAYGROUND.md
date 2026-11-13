# Interactive Chapa API v2 test playground

This repository includes a **test-mode-only PHP CLI** for developers to run safe Chapa API checks using their own test secret and test public keys. No Laravel project, database, or Composer dependencies are required when running the CLI in a cloned repository. The SDK API client runs under PHP 8.2+.

## Get started

```bash
git clone https://github.com/nateyk/chapa-laravel.git
cd chapa-laravel
php bin/chapa doctor     # Offline requirements check, no Chapa requests
php bin/chapa test       # Hidden prompts; enter TEST secret and optionally TEST public key
```

When Composer installs this package as a dependency, the same CLI is available from `vendor/bin/chapa` (provided the project has allowed Composer's bin symlink or proxy creation).

In Chapa Dashboard, select **Test Mode**, then **Settings → API Keys**. Test keys begin with `CHAPA_TEST_` in the current [Chapa v2 key documentation](https://docs.chapa.global/docs/v2/integrations/test-live-mode). The two input prompts are hidden; the public key is optional. The CLI rejects `CHAPA_LIVE_` keys even if they are misconfigured under the `CHAPA_TEST_SECRET_KEY` variable.

**Never enter test or live API keys in command-line arguments**, terminal chat, bug reports, or code committed to Git. The CLI does not save credentials, use dotenv automatically, or print raw Chapa responses. On terminals that cannot disable input echo, it fails closed and asks you to use environment variables.

## Interactive menu

| Choice | Action | Request type |
| --- | --- | --- |
| 1 | Check private key + payment methods + ETB balance; check public key if given | GET only |
| 2 | List enabled payment methods | GET |
| 3 | Read ETB balances | GET |
| 4 | Verify one payment's status by reference | GET |
| 5 | List 5 recent payments | GET |
| 6 | List 5 recent payouts | GET |
| 7 | List 5 recent refunds | GET |
| 8 | List payout banks for Ethiopia | GET |
| 9 | Create a **test** hosted checkout | POST, explicit confirmation required |
| 10 | Check public-key HTML service | GET |
| 0 | Exit | No request |

The CLI intentionally excludes payout creation, direct wallet charges, refunds and destructive operations. Those can affect real recipients or state and should be tested through controlled application workflows, not a general-purpose menu.

### Test hosted checkout

Choice **9** asks for an **integer-cent ETB amount**, then requires typing exactly `CREATE TEST CHECKOUT` before making any POST. For example, `100` means **ETB 1.00**. The CLI generates a unique merchant reference (at most 20 characters) and operation idempotency key, and prints both before sending the request. Keep the same idempotency key if you retry that *same* operation manually; do not generate a new key for an uncertain outcome.

If the API confirms a checkout, the CLI shows an HTTPS checkout URL. Opening that URL and completing a simulated payment are manual steps. **Checkout initialization does not prove a payment succeeded**. Use choice 4 or `--verify-payment=REFERENCE` afterward. Always validate the amount, currency, merchant reference, and fulfillment state in your application before completing an order.

## Headless / CI key checks

Supply keys with your secret manager or secure interactive shell prompt; do not paste literal keys in commands or scripts:

```bash
# Bash on a private development machine
read -r -s -p 'Chapa TEST secret: ' CHAPA_TEST_SECRET_KEY; echo
export CHAPA_TEST_SECRET_KEY
read -r -s -p 'Chapa TEST public (optional): ' CHAPA_TEST_PUBLIC_KEY; echo
export CHAPA_TEST_PUBLIC_KEY

php bin/chapa test --check
php bin/chapa test --verify-payment=YOUR_TEST_REFERENCE

unset CHAPA_TEST_SECRET_KEY CHAPA_TEST_PUBLIC_KEY
```

For CI, use the CI provider's encrypted secret configuration. Do **not** put real keys into public GitHub Actions workflows or `.env.example`. `--check` sends only GET requests to `https://api.chapa.global`. It validates private-key access via payment methods and balances, and the public key via HTML checkout health. If the public key is not set, that check is skipped, not marked successful.

`--verify-payment=REFERENCE` returns a transaction status; `PENDING` is not a completed payment. The command prints status and integer-cent amount/currency, **not customer details**. Command arguments accept only payment references, never keys.

## Offline tests (no Chapa keys required)

```bash
php tests/smoke.php
php tests/cli-smoke.php
composer install
composer test
```

Unit tests mock the transport and never contact Chapa. The CLI smoke test validates live-key rejection, absence of unsafe POST requests, secure output, key selection, simulated hosted checkout confirmation and idempotency. The PHP matrix runs on GitHub Actions.

## What the CLI cannot prove

- A valid key format does not guarantee API authorization; `--check` contacts Chapa and may return 401/403.
- A successful hosted checkout initialization does not mean payment settlement.
- The CLI does not run or simulate webhook deliveries. Use dedicated Laravel integration and replay tests for that.
- API sandbox checks are not a substitute for production readiness, fraud controls, settlement reconciliation, and merchant account approval.

## Troubleshooting

| Message | Meaning / action |
| --- | --- |
| Test-mode key setup failed | Key missing or not `CHAPA_TEST_`, or hidden terminal input unavailable. Use a test key, not live key. |
| HTTP 401 | Invalid or unauthorized key. Check the key copied from your Chapa Test dashboard. |
| HTTP 403 / `INVALID_VALUE` | API key environment does not match the Chapa business mode. |
| No interactive TTY | Run from a real terminal, or provide key environment variables and use `--check`. |
| PHP OpenSSL / HTTPS failed | Enable OpenSSL and `allow_url_fopen`; never disable TLS verification. |
| Test checkout network error | Outcome might be unknown; do not create a new idempotency key for a retry of that operation. |

Keep test credentials local and rotate them immediately if inadvertently exposed.