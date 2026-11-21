# Phase 3 Readiness Report

**Project:** Nora PHP Framework (`morave/nora`)  
**Date:** 2026-11-21  
**Prepared in:** local Windows workspace with pre-existing uncommitted changes preserved

## Executive summary

Phase 3 work advanced Nora toward a more secure and maintainable release candidate by implementing concrete mitigations for two previously open security concerns (session revocation after password reset and ORM mass-assignment controls), improving CLI discoverability (`nora list`), and producing Phase 3 audit/release documentation based on inspected code and executed checks.

The framework is **not** declared production-ready. Remaining gaps include full post-change regression/CI evidence for the final state, Linux/runtime verification, and maintainer-controlled release/publication steps. Existing Phase 2 protections were preserved.

## Inspected

- Repository structure, git status, recent history, and modified/untracked working tree.
- `composer.json`, `phpunit.xml`, CI workflow, README, changelog, release/readiness and Phase 2 security docs.
- Core/runtime: bootstrap, application, config, container/providers/events, routing/HTTP/middleware, exception handling.
- Security: sessions, CSRF, auth/login, account lifecycle, rate limiting, uploads/storage.
- Database/ORM: PDO connection, query builder, schema/migrations, model/query/relations, database manager/configuration.
- Reactive engine and UI/runtime integration points; Tailwind/asset workflow configuration.
- CLI implementation and generated-project scaffolding behavior (source inspection).
- Existing tests and Phase 2 audit/test matrix.

## Implemented changes (this pass)

### Security
- **P2-03 mitigation:** Added session-version support for account session revocation.
  - New `Nora\Security\SessionVersionedAccountInterface`.
  - `SessionAuthenticator` stores/verifies session version for versioned accounts and clears stale authenticated sessions.
  - PDO account lifecycle schema/store/account model updated to persist and increment `session_version` on password reset.
  - Account lifecycle migration updated for new installations.
  - Regression coverage added/updated in account lifecycle and security-related unit tests.
- **P2-04 mitigation:** Added ORM mass-assignment controls in `Nora\Database\Model`.
  - `fillable`/`guarded` class properties, `fill()` enforcement, `forceFill()`, `isFillable()`/`isGuarded()`.
  - Default behavior remains permissive when neither fillable nor guarded is configured (backward compatibility).
  - Regression tests added for fillable, guarded, protected sensitive attributes, and explicit `forceFill`.

### Developer experience / CLI
- Added `nora list` command alias to display CLI help for discoverability.

### Documentation and release artifacts
- Added Phase 3 audit and test-results documents.
- Added release readiness report, RC checklist, release notes draft, and migration guide under `docs/releases/`.
- Updated `CHANGELOG.md` (Unreleased) with factual Phase 3 entries.

## Important files modified/added in this pass

- `src/Security/SessionAuthenticator.php`
- `src/Security/SessionVersionedAccountInterface.php` (new)
- `src/Security/PdoAccountLifecycleStore.php`
- `src/Security/AccountLifecycleMigration.php`
- `src/Database/Model.php`
- `src/Console/ConsoleApplication.php`
- `tests/Unit/AccountLifecycleTest.php`
- `tests/Unit/ModelTest.php`
- `docs/audit/PHASE_3_AUDIT.md` (new)
- `docs/audit/PHASE_3_TEST_RESULTS.md` (new)
- `docs/releases/PHASE_3_READINESS_REPORT.md` (new)
- `docs/releases/RC_CHECKLIST.md` (new)
- `docs/releases/RELEASE_NOTES.md` (new)
- `docs/releases/MIGRATION_GUIDE.md` (new)
- `CHANGELOG.md`

(Pre-existing modified/untracked files in the repository were preserved.)

## Tests executed and actual results

See `docs/audit/PHASE_3_TEST_RESULTS.md` for full detail.

| Command | Result |
|---|---|
| `vendor\bin\phpunit --display-warnings` (baseline, full) | 229 tests, 1248 assertions, 3 skipped, exit code 1 (`OK, but some tests were skipped!`) |
| `composer validate --strict` | PASS |
| `vendor\bin\phpunit tests\Unit\ModelTest.php tests\Unit\AccountLifecycleTest.php tests\Unit\SecurityTest.php --display-warnings` | PASS (19 tests, 109 assertions) |
| `vendor\bin\phpunit tests\ConsoleTest.php --display-warnings` | PASS (8 tests, 180 assertions) |
| `php bin\nora list` | PASS (help displayed) |

## Outstanding risks and limitations

- Full PHPUnit suite not rerun after every Phase 3 change set in this pass; CI-equivalent validation for the final commit not executed locally.
- Session revocation coverage is strongest for password-reset token flow and session-versioned accounts; dedicated APIs for suspension/credential compromise revocation are limited.
- ORM mass assignment defaults remain permissive for models that do not define `fillable`/`guarded` (BC); applications must configure protections for sensitive models.
- Residual filesystem TOCTOU/race risks and reactive defense-in-depth considerations remain as documented in Phase 2/Phase 3 audits.
- Linux, multi-PHP runtime matrix, browser/Tailwind pipeline, and deployment-specific behavior not fully verified in this environment.
- Stable public package publication/installation remains a maintainer/external process concern.

## Release decision

**CONDITIONALLY READY**

**Why:** Implemented and tested mitigations address key Phase 3 security blockers (P2-03 reset revocation path, P2-04 mass-assignment controls) and preserve Phase 2 protections, with targeted regressions passing. However, RC declaration requires complete final verification evidence (full suite and CI on the final tree, Linux/PHP matrix confirmation, and maintainer release actions) that was not fully completed in this local pass. Unresolved/documented limitations must be carried into RC notes and checklist sign-off.