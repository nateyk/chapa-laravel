# Packagist pre-release preparation

Package identity: `nateyk/chapa-laravel`. Community-maintained Chapa API v2 SDK, PHP `^8.2`, MIT. This is a **different package** from `chapa/chapa-laravel` and is not an official Chapa SDK.

## Automated release preflight (no publication)

The CI package-preflight job runs `composer validate --strict`, Composer install, `composer audit --no-dev`, `php tests/release-smoke.php`, `php bin/chapa release:check --json`, and `composer archive --format=zip --dir=/tmp`. Archive creation only validates distributability; it does not publish any package.

The CLI preflight always reports `release_ready: false`, because offline checks cannot prove real Chapa API behavior, a genuine signed callback, a sandbox transfer or maintainer approval.

## Maintainer tasks after sandbox signoff

1. Confirm the PHP and Laravel matrices, runtime audit and archive preflight all pass.
2. Confirm v2 payout/webhook behavior using the merchant's **test account**, including approval callback signing and state reconciliation.
3. Verify clean consuming Laravel 12 and 13 applications. Laravel 11 is compatibility-only because security maintenance ended.
4. Sign in to [Packagist](https://packagist.org/) with a maintainer account and submit the **public** source https://github.com/nateyk/chapa-laravel. Enable the documented GitHub update hook.
5. Only after required checks, create a pre-release tag such as `v0.1.0-beta.1` and GitHub release notes. Do not tag a production-stable 1.0 release prematurely.
6. Verify Packagist resolves the exact repository, MIT license and pre-release tag; try a pinned pre-release Composer install.
7. Never publish `.env`, live keys, webhook payloads or customer data. Run deployment-specific audits before real-money use.

The repository remains public, but no Packagist registration, tag or production approval is claimed by this document.
