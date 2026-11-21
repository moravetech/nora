# Security

Nora is early-stage software and is not security-certified or independently
audited. Passing automated tests and dependency scans are not a production
security assessment. Do not use the reactive UI's experimental APIs as a
security boundary.

## Available controls

- Request parsing has a bounded body size and explicit validation.
- `SafeHtml`, `UI`, and `TemplateRenderer` escape text/attributes and apply
  their documented URL, attribute, template-path, and include-cycle rules.
- PDO APIs support bound parameter values; dynamic identifiers are validated
  by `QueryBuilder`.
- Sessions can use strict mode, cookie-only IDs, HttpOnly, SameSite=Lax, and
  Secure cookies.
- CSRF middleware checks non-safe requests when configured.
- Rate limiting is opt-in; its file-backed store fails closed on storage
  errors and is not a distributed limiter.
- Upload helpers enforce configured upload policy and private local storage,
  but deployment-specific ACLs and live multipart/server behavior require
  verification.
- Production exception handling is intended to avoid stack traces in
  responses; configure debug behavior deliberately.

These controls are not automatically enabled for every application route.
Applications must configure middleware, authorization, HTTPS, environment
secrets, upload policy, and web-server document roots appropriately.

## Release status and remaining review

The rate-limit middleware regression where an exhausted request reached its
downstream handler has a local regression test and is fixed. No independent
security review has been performed. Registration, reset/verification flows,
MFA, and account persistence are not implemented. Linux/Apache/Nginx
deployment, multiple browser engines, and security headers have not been
independently verified.

See the [security findings record](audit/SECURITY_FINDINGS.md) for the
scoped evidence, severity, remediation, and retest status. It is not an
independent audit.

Report suspected vulnerabilities using the repository's existing security
reporting guidance. Do not include live credentials, session tokens, or
production user data in reports or test fixtures.
