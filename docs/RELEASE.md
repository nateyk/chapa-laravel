# Publishing and release checklist

This is a **community-maintained** Chapa API v2 package and is not affiliated with Chapa. The repository is public; **Packagist is not yet configured**, and no stable version/tag has been issued. Do not publish an unverified stable version.

## Before the first version

1. Confirm endpoints and request/response schemas against current [Chapa v2 documentation](https://docs.chapa.global/docs/v2) and the actual **test-mode** merchant account, without real-money operations.
2. Validate the package: `composer validate --strict`, `composer install`, `php tests/smoke.php`, `vendor/bin/phpunit --testdox`, and `composer audit`.
3. Verify CI on supported PHP 8.2/8.3/8.4 **and explicit Laravel 11/12/13 matrix**, including the Laravel integration suite with SQLite.
4. Exercise server-side verification and webhook signature handling, duplicate webhook delivery, events arriving out of order, retries, partial refunds and the expiry of inbox leases.
5. Verify supported Laravel versions with a minimal consuming application. Check `vendor:publish` for the config and migration, `artisan migrate`, and schedule the inbox worker.
6. Review security, exception messages, logging, operations keys, migration rollback and customer data retention; document release-breaking changes in `CHANGELOG.md`.
7. Publish a **pre-release** tag first (`v0.1.0`) after these checks; use SemVer for subsequent versions. Prefer a signed annotated tag and GitHub Release notes.

## Laravel 11 compatibility versus security

The CI matrix includes Laravel 11, 12 and 13. **As of 14 November 2026**, Composer's security-policy resolver blocks all currently available Laravel 11 releases due to published security advisories. The Laravel 11 matrix job temporarily bypasses dependency blocking **inside that isolated CI container only** to test API compatibility, and reports advisories as a release warning. This is not a safe-production certification. The package itself does **not** disable Composer's policy, and normal installations retain secure defaults.

Treat Laravel 11 as a **separate security release blocker** until upstream packages and advisory exposure have been reviewed. Laravel 12 and 13 CI jobs resolve dependencies without this override. Never copy the Laravel 11 compatibility-only workaround into your deployment pipeline or use its green test result as proof that the dependency set is safe.

## Outstanding transfer approval interoperability gates

- [ ] Chapa confirms whether **API v2** payouts trigger the dashboard Transfer Approval URL.
- [ ] A genuine **Chapa Test Mode** callback arrives at the **always-rejecting probe**, and the official `Chapa-Signature` message/canonicalization is confirmed without leaking recipient data.
- [ ] The merchant's app uses protected, tenant-scoped admin Gates to approve payouts, enforces payout limits, and has a reconciliation ledger for `SUCCESS`, `REVERSED` and uncertain outcomes.
- [ ] End-to-end test-mode duplicate/invalid/cancelled/approved callback cases are exercised with a public HTTPS endpoint; API creation 200 is never treated as successful settlement.
- [ ] The probe route is removed and `CHAPA_TRANSFER_APPROVAL_PROBE_ENABLED` is reset to false before any live rollout.

These depend on a private merchant account and/or Chapa support. Offline tests **cannot** satisfy them. Do not activate real-money transfer approval before they are confirmed.

## Automated pre-release gates

Run `php bin/chapa release:check --json` to check local package identity and list blockers. The output always marks external release readiness as false. CI audits **runtime** Composer dependencies with `composer audit --no-dev` and builds a distributable archive using `composer archive`; neither step publishes the package.

See [Packagist playbook](PACKAGIST-RELEASE.md), [Laravel 11 security report](LARAVEL-11-SECURITY.md) and [Chapa callback compatibility](CHAPA-CALLBACK-COMPATIBILITY.md). Real test-mode Chapa integration remains mandatory before the pre-release tag.

## Packagist registration (maintainer action)

1. Sign into [packagist.org](https://packagist.org/) with the maintainer account that can administer `nateyk/chapa-laravel`.
2. Submit the public GitHub source URL `https://github.com/nateyk/chapa-laravel` as a new package after the release review.
3. Enable the GitHub-to-Packagist webhook/integration in Packagist's setup instructions so tags and releases update automatically.
4. Check the package page for correct owner, license, description and version before announcing `composer require nateyk/chapa-laravel`.
5. Do not publish private API keys, webhooks, signatures, customer information or any production `.env`/`composer.lock` files.

## API stability

The original SDK array-payload methods and raw `ApiResponse` return types remain available. The new DTOs and `verifyTyped()` methods are additive. The Laravel database inbox, endpoint and scheduled processor are strictly **opt-in**, and no app's database is migrated automatically.

Money amounts must be integer cents, payout/refund/payment creation requires a persisted operation-specific idempotency key, and every order must be fulfilled only from confirmed server-side verification with its own unique constraint.
