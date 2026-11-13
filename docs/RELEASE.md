# Publishing and release checklist

This is a **community-maintained** Chapa API v2 package and is not affiliated with Chapa. The repository is public; **Packagist is not yet configured**, and no stable version/tag has been issued. Do not publish an unverified stable version.

## Before the first version

1. Confirm endpoints and request/response schemas against current [Chapa v2 documentation](https://docs.chapa.global/docs/v2) and the actual **test-mode** merchant account, without real-money operations.
2. Validate the package: `composer validate --strict`, `composer install`, `php tests/smoke.php`, `vendor/bin/phpunit --testdox`, and `composer audit`.
3. Verify CI on supported PHP 8.2/8.3/8.4, including the Laravel integration suite with SQLite.
4. Exercise server-side verification and webhook signature handling, duplicate webhook delivery, events arriving out of order, retries, partial refunds and the expiry of inbox leases.
5. Verify supported Laravel versions with a minimal consuming application. Check `vendor:publish` for the config and migration, `artisan migrate`, and schedule the inbox worker.
6. Review security, exception messages, logging, operations keys, migration rollback and customer data retention; document release-breaking changes in `CHANGELOG.md`.
7. Publish a **pre-release** tag first (`v0.1.0`) after these checks; use SemVer for subsequent versions. Prefer a signed annotated tag and GitHub Release notes.

## Packagist registration (maintainer action)

1. Sign into [packagist.org](https://packagist.org/) with the maintainer account that can administer `nateyk/chapa-laravel`.
2. Submit the public GitHub source URL `https://github.com/nateyk/chapa-laravel` as a new package after the release review.
3. Enable the GitHub-to-Packagist webhook/integration in Packagist's setup instructions so tags and releases update automatically.
4. Check the package page for correct owner, license, description and version before announcing `composer require nateyk/chapa-laravel`.
5. Do not publish private API keys, webhooks, signatures, customer information or any production `.env`/`composer.lock` files.

## API stability

The original SDK array-payload methods and raw `ApiResponse` return types remain available. The new DTOs and `verifyTyped()` methods are additive. The Laravel database inbox, endpoint and scheduled processor are strictly **opt-in**, and no app's database is migrated automatically.

Money amounts must be integer cents, payout/refund/payment creation requires a persisted operation-specific idempotency key, and every order must be fulfilled only from confirmed server-side verification with its own unique constraint.
