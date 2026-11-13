# Contributing

Thanks for helping make Chapa API v2 integration safe for PHP and Laravel developers.

- Open an issue describing the Chapa v2 documentation section and the proposed backwards-compatible behavior before large changes.
- Never submit test/live API keys, personal information, card details or actual webhook payloads. Use synthetic examples and mocked transports.
- Keep the framework-independent client free of mandatory Laravel dependencies. Laravel-only features belong under `src/Laravel`.
- Represent amounts in integer cents, require idempotency for money-moving creation requests, and never infer payment success from initiation or a webhook alone.
- Add unit or Laravel integration tests for new paths; update README, `docs/API.md` and CHANGELOG when behavior changes.
- Use `composer validate --strict`, PHP lint, `php tests/smoke.php`, and `vendor/bin/phpunit --testdox` before opening a release PR.
- Security reports should follow [SECURITY.md](SECURITY.md), not public bug reports with sensitive information.

There is no published stable release yet. Contributions should target the default branch using PRs and review.
