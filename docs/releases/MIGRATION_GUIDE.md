# Migration Guide — Phase 3 (Security & Release Readiness)

This guide covers notable changes introduced during Phase 3 hardening. Review before upgrading applications that use Nora account lifecycle/session authentication or ORM mass assignment.

## 1. Session revocation and account session versioning (P2-03 mitigation)

### What changed
- `Nora\Security\SessionAuthenticator` now supports session invalidation for accounts implementing `Nora\Security\SessionVersionedAccountInterface`.
- On login, the authenticator stores the account's session version when available and rejects stale sessions when the stored version no longer matches.
- The built-in PDO account lifecycle implementation (`PdoAccountLifecycleStore`, `PdoLifecycleAccount`, and `AccountLifecycleMigration`) now includes `session_version`.
- `resetPasswordWithToken()` increments `session_version` when a password reset is successfully applied.

### Impact
- **New installations:** use the updated `AccountLifecycleMigration` (creates `nora_accounts.session_version`).
- **Existing databases:** require a schema upgrade before using the updated PDO account lifecycle store implementation.

### Recommended database upgrade (MySQL/MariaDB)
Run in a controlled maintenance window after backup:

```sql
ALTER TABLE nora_accounts
  ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1
  AFTER enabled;
```

Adjust column position as desired; only the column name/type/default are required by the current implementation. After upgrade, existing sessions remain valid until their stored version mismatches (new logins will persist the current version).

### Application integration notes
- Custom account stores/objects used with session authentication should implement `SessionVersionedAccountInterface` and persist/increment version on credential/security-sensitive changes to benefit from revocation.
- Password rehash-on-login must **not** increment session version.
- Logout and idle/absolute session timeout behavior remain governed by existing session/auth components.

## 2. ORM mass-assignment protections (P2-04 mitigation)

### What changed
- `Nora\Database\Model` now supports:
  - `protected static array $fillable = [];`
  - `protected static array $guarded = [];`
  - `public function forceFill(array $attributes): self`
  - `public function isFillable(string $key): bool`
  - `public function isGuarded(string $key): bool`
- `fill()` (and therefore mass-assignment paths such as constructor/create input handling) now respects fillable/guarded rules.

### Backward compatibility
- If a model defines neither `fillable` nor `guarded` entries that restrict assignment, default behavior remains permissive for compatibility.
- Direct attribute assignment (`$model->attribute = ...`, `setAttribute()`) is unchanged and remains an explicit trusted-code path.

### Recommended action
For models that accept request/user-controlled arrays, explicitly define `$fillable` (preferred allowlist) or `$guarded` to protect sensitive attributes such as `role`, `permissions`, `account_status`, `enabled`, ownership IDs, and other privileged fields. Use `forceFill()` only for trusted internal/system-controlled data.

## 3. CLI

- `php nora list` is now supported as an alias for help output.
- Existing commands and option conventions remain unchanged.

## 4. Verification after upgrade

- Run application tests and framework test suites relevant to your integration.
- Validate authentication flows: login, logout, password reset, and session invalidation behavior.
- Validate ORM create/update flows for models with newly added `fillable`/`guarded` definitions.
- Review `docs/releases/RC_CHECKLIST.md` and `docs/audit/PHASE_3_TEST_RESULTS.md` for release evidence expectations.