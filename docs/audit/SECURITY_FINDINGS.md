# Security findings

**Assessment date:** 2026-10-29  
**Assessment type:** implementation self-review and verification record; not
an independent security audit.

This report records scoped findings with direct code/test evidence. It does
not claim comprehensive coverage of every request path, deployment, browser,
or application configuration. No destructive tests or production services
were used.

## Findings

| ID | Severity | Status | Affected area | Evidence | Remediation / retest |
|---|---|---|---|---|---|
| SEC-01 | Medium | Fixed | Rate-limit middleware | Previously, an exhausted bucket could reach the downstream handler before the 429 response. `src/Security/RateLimitMiddleware.php` now returns 429 before calling `$next`; `tests/Unit/RateLimitTest.php` asserts the handler ran only once across two requests. | Fixed in the current worktree. `composer check` passes; the focused regression asserts the 429, headers, and downstream-call count. |
| SEC-02 | Low (operational hardening) | Open / environment-specific | Request resource limits | `Request::fromGlobals()` bounds a request body to 1 MiB by default (`src/Http/Request.php:13`); the reactive endpoint has a stricter 16 KiB check (`src/Reactive/ReactiveEngine.php:31`, `:185`). The general parser bound is finite, but its limit may still be high for a deployment with many concurrent requests. | Configure web-server/reverse-proxy body and concurrency limits appropriate to the application. This is a deployment recommendation, not a demonstrated bypass of Nora's request-size bound. No load test was performed. |
| SEC-03 | Low (configuration risk) | Open / deployment-specific | Error responses | `src/Core/ExceptionHandler.php:58` returns exception class/message details when debug mode is enabled. Production-safe behavior requires debug disabled; configuration is application/deployment controlled. | Keep `APP_DEBUG=false` in production and verify effective environment configuration during deployment. New scaffold defaults to `debug=false` in `config/app.php`; no production deployment was tested. |

No comprehensive severity assessment was performed for unaudited components;
the absence of another listed item must not be interpreted as a clean audit.

## Explicitly unverified

- Independent security review and retest.
- Live Linux/Apache/Nginx deployment, reverse-proxy limits, HTTPS, and
  filesystem ACLs.
- Full authentication/account lifecycle remains incomplete: registration,
  email verification, password reset, and an optional MySQL store now exist,
  but HTTP/mail integration, MFA, account deletion, session revocation after
  reset, and live MySQL verification are outstanding.
- Real local MySQL integration; dedicated tests are skipped without the
  explicitly configured loopback test database.
- Multi-browser security/accessibility behavior and automated accessibility
  scanning.
- Hosted CI for this uncommitted worktree.

Dependency scans passed locally (`composer audit`; `npm audit`), but these
scans cover known package advisories, not application security.
