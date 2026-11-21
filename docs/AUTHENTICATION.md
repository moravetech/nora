# Authentication

Nora provides opt-in login and account-lifecycle services, not an automatic
authentication application. Applications still own controllers, routes,
authorization rules, configuration, and delivery of email. No routes are
registered implicitly.

## Password login

`Nora\Security\PasswordLoginService` accepts an
`AccountProviderInterface`, `AuthenticatorInterface`, `PasswordHasher`, and
`RateLimiter`. Login checks account enablement, requires email verification
when the account implements `EmailVerifiableAccountInterface`, performs a
dummy password verification for unknown accounts, and rehashes passwords when
the configured PHP default changes. Rejected credentials use a generic result.
Provide a rate-limit key derived from trusted request data and authorize every
protected action on the server.

`PasswordHasher` uses PHP's password APIs. Do not log submitted passwords,
password hashes, session identifiers, or authentication tokens.

## Registration, verification, and password reset

`AccountLifecycleService` implements rate-limited registration, verification
email requests, email verification, password-reset requests, and password
reset. It depends on:

- `AccountLifecycleStoreInterface`, which extends the existing
  `AccountProviderInterface`.
- `AccountLifecycleNotifierInterface`, implemented by the application using
  its chosen mail provider.
- `PasswordHasher` and a configured `RateLimiter`.

The service normalizes email addresses for uniqueness, requires passwords of
12–72 bytes, returns a generic accepted result for duplicate registrations
and unknown reset/verification requests, and never stores raw tokens. It
generates 256-bit URL-safe tokens and persists only SHA-256 token hashes.
Verification tokens expire after 24 hours by default; reset tokens expire
after one hour. Reissuing a token invalidates its previous token for the same
account and purpose. Token consumption, email verification, and password
replacement are atomic in the PDO store; tokens cannot be replayed.

The notifier receives the recipient and raw token so the application can
construct an HTTPS link using a configured, trusted application origin. Do
not construct links from the request `Host` header, log token values, or
include third-party assets on token-bearing pages. Render a confirmation page
and consume tokens through a CSRF-protected POST rather than consuming them
on GET; email security scanners may follow links automatically. Return the
same public response for known and unknown reset/verification emails.

Example construction:

```php
$connection = PdoConnection::connect($dsn, $username, $password);
$accounts = new PdoAccountLifecycleStore($connection);
$lifecycle = new AccountLifecycleService(
    $accounts,
    $applicationMailer, // Implements AccountLifecycleNotifierInterface.
    new PasswordHasher(),
    $authenticationRateLimiter,
);
```

Applications using their own storage implement
`AccountLifecycleStoreInterface` and `AccountLifecycleNotifierInterface`.
There is no mandatory database or email-provider dependency.

## Optional MySQL/MariaDB storage

`PdoAccountLifecycleStore` is an optional MySQL/MariaDB adapter. It requires
`pdo_mysql` and the schema returned by
`Nora\Security\AccountLifecycleMigration`. Invoke that migration from the
application's explicitly configured migration process after reviewing its
DDL. It creates `nora_accounts` and `nora_account_tokens`; `down()` drops
both tables and therefore must only be used by an approved rollback or a
disposable test database.

The adapter uses prepared statements and transactional `SELECT ... FOR
UPDATE` token consumption. The PDO integration test refuses to run unless
`NORA_TEST_DSN` names the loopback `nora_test` database and
`NORA_TEST_DB_NAME=nora_test` is set. Do not point it at a shared or production
database. Configure `NORA_REQUIRE_TEST_DB=1` in CI to fail instead of silently
skipping if the dedicated test database is missing.

## Sessions and CSRF

`SessionManager` configures strict session mode, cookie-only sessions,
HttpOnly and SameSite=Lax cookies, and a configurable Secure flag. Set Secure
for HTTPS deployments. `SessionAuthenticator` regenerates the session ID and
invalidates CSRF state on login/logout. Configure `SessionMiddleware` and
`CsrfMiddleware` on browser routes that mutate state. Reactive endpoints have
their own session, CSRF, revision, and action authorization checks; this does
not protect unrelated routes.

## Remaining authentication work

- No built-in HTTP controllers, route registration, mail transport, or
  password-reset/verification page templates.
- No TOTP or other multi-factor authentication, recovery codes, remember-me
  flow, or account-deletion workflow.
- Password reset does not revoke sessions in application-defined session
  stores; applications should revoke active sessions or require reauthentication.
- The MySQL adapter has an opt-in integration test, but this environment has
  no reachable MySQL server; live database verification remains outstanding.
- No independent security review or production deployment audit has been
  performed.
