# Nora Framework — Release Notes (Phase 3 Draft)

**Status:** Draft (conditional RC candidate)  
**Date:** 2026-11-21  
**Scope:** Phase 3 hardening, developer experience, and release-preparation updates on top of Phase 2.

## Highlights

- **Session security:** Password reset flows now revoke existing authenticated sessions for session-versioned accounts by bumping account session version and validating it in `SessionAuthenticator`.
- **ORM safety:** `Model` now supports mass-assignment controls (`$fillable`, `$guarded`, `forceFill()`, `isFillable()`/`isGuarded()`) while preserving permissive default behavior for models that do not configure guards.
- **CLI discoverability:** Added `php nora list` as an alias for help output.
- **Release engineering:** Added Phase 3 audit/test/readiness artifacts, RC checklist, migration guidance, and changelog updates.

## Security notes

- Phase 2 protections (upload source validation hardening, session idle/absolute timeout support, symlink fail-closed behavior) remain in place.
- Session revocation is implemented for the password-reset token path with session versioning. Applications with custom account/session integrations should adopt `SessionVersionedAccountInterface` semantics where appropriate.
- Mass assignment protection is configurable per model; models without `fillable`/`guarded` remain permissive for backward compatibility. Applications must explicitly protect sensitive attributes.
- Residual risks remain (filesystem TOCTOU/race windows in hostile shared environments, reactive defense-in-depth dependencies, and environment-specific deployment concerns). This draft does **not** claim a security certification.

## Compatibility and verification

- Verified locally on Windows with PHP 8.2.34 / PHPUnit 11.5.57.
- Baseline full suite (this pass): 229 tests, 1248 assertions, 3 skipped.
- Targeted post-change tests passed: model/account lifecycle/security/console (19 + 8 tests).
- Linux, full CI matrix for the final commit, browser/Tailwind pipeline, and broader deployment verification: **not fully verified in this local pass**.

## Upgrade notes

- Account lifecycle storage/schema includes `session_version` for new installs. Existing `nora_accounts` deployments require a schema upgrade (see `docs/releases/MIGRATION_GUIDE.md`).
- Review models that accept untrusted input and define `$fillable` or `$guarded` for sensitive attributes.

## Known limitations

- Stable public Composer installation/publication is maintainer-controlled and not verified as complete in this pass.
- Some release gates remain pending (final full regression/CI evidence, platform matrix confirmation). See `docs/releases/RC_CHECKLIST.md` and `docs/releases/PHASE_3_READINESS_REPORT.md`.