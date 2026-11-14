# Laravel 11 compatibility and security review

Reviewed 14 November 2026. This is a summary of the **isolated CI dependency audit**, not a production exploit assessment.

Under [Laravel's official support policy](https://laravel.com/docs/11.x/releases), Laravel 11 security fixes ended **12 March 2026**. It is an API compatibility target but not recommended for a new real-money payment deployment.

Composer reported **five advisory records affecting laravel/framework** in the compatibility CI job:

| Severity | Advisory | Area |
| --- | --- | --- |
| Low | PKSA-yxbx-3c9s-cjt9 | Concurrent task output injection |
| Low | PKSA-d5tc-s1qs-h781 | Debug-page XSS |
| Medium | PKSA-m5cs-t1y6-qpcs | Temporary signed URL path confusion |
| High | PKSA-3r5d-mb8f-1qw9 | CRLF injection in default email validation |
| Not specified | PKSA-mdq4-51ck-6kdq | Additional record for the same email-validation GHSA |

The two email-validation records refer to the same GHSA: five advisory *entries* do not necessarily mean five different vulnerabilities. This review will become stale as upstream advisories change.

### Security disposition

- **Laravel 11:** CI uses Composer's `--no-security-blocking` only in an isolated compatibility job, then runs `composer audit` and emits a warning. **Not production-certified.** Normal package installation does not disable Composer's security policy.
- **Laravel 12 and 13:** CI resolves dependencies with normal blocking; consuming applications still need their own audit.
- **SDK runtime dependencies:** Separately audited via `composer audit --no-dev` in package-preflight CI. This does not audit a consuming application's dependencies.
- For real-money integrations, upgrade to an actively supported patched Laravel release.

References: [Laravel release policy](https://laravel.com/docs/11.x/releases), [GitHub Advisories](https://github.com/laravel/framework/security/advisories), [Packagist advisories](https://packagist.org/security-advisories/).
