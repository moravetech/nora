# Phase 3 Audit — Production Readiness, DX, Security & Release Candidate

**Date:** 2026-11-21  
**Scope:** Existing Nora repository at `C:\Users\PRODESK\Desktop\Nora` (Windows). Inspection, targeted implementation, targeted validation, and documentation updates performed. Phase 2 results preserved as handoff baseline.

## 1. Discovery summary

### Repository and runtime
- Git: `moravetech/nora`, branch/main history present; working tree contains extensive pre-existing modified and untracked files (preserved).
- PHP 8.2.34, Composer 2.10.3, PHPUnit 11.5.57 available locally.
- Composer package: `morave/nora`, `php: ^8.2`, `ext-filter/json/pdo/session` required, optional `ext-pdo_mysql` suggested.
- PHPUnit suites: Unit (`tests/Unit` + `tests/ConsoleTest.php`), Integration (`tests/Integration`, excluding `NoraWebsiteErrorPageTest.php`), Security (`tests/Security`).
- CI (`.github/workflows/ci.yml`): package-consumer, PHP 8.2/8.3 MySQL-backed test job, reactive browser/Tailwind/npm audit job.

### Phase 2 handoff (preserved)
- P2-01 upload source validation hardening: preserved in `src/Http/UploadedFile.php` / request parsing behavior and existing tests.
- P2-02 session idle/absolute timeout support: preserved in `src/Security/SessionManager.php`.
- Symlink fail-closed protections and baseline regressions: preserved (baseline full suite previously/locally green except skips per recorded results).
- Existing audit docs preserved: `docs/audit/SECURITY_AUDIT_PHASE_2.md`, `SECURITY_TEST_MATRIX.md`, `SECURITY_HARDENING.md`, `SECURITY_FINDINGS.md`, `RELEASE_READINESS.md`.

### Baseline evidence (this pass)
- Full PHPUnit: **229 tests, 1248 assertions, 3 skipped**, exit code 1 with PHPUnit message `OK, but some tests were skipped!` (Windows run; expected reactive warning/error logs observed on stderr during integration tests).
- `composer validate --strict`: PASS.
- CLI help: PASS.

## 2. Prioritized readiness matrix

| ID | Severity | Domain | Description | Risk | Evidence | Required action | Status | Tests |
|---|---|---|---|---|---|---|---|---|
| P3-SEC-01 | High | Authentication / Session (P2-03) | Password reset consumed token and updated hash but did not invalidate existing authenticated sessions. | Compromised/stale sessions could remain valid after credential reset. | `PdoAccountLifecycleStore::resetPasswordWithToken` updated password only; `SessionAuthenticator` stored only `auth.user_id`. | Implement session-version-based revocation, bump version on reset, validate on authenticated access; add migration/schema and regression tests; document upgrade impact. | **Mitigated (implemented)** | `AccountLifecycleTest::testPasswordResetRevokesExistingAuthenticatedSessions`, updated reset assertions; targeted Security/AccountLifecycle tests pass. |
| P3-SEC-02 | High | ORM / Mass assignment (P2-04) | `Model::fill()` assigned all provided attributes with no fillable/guarded controls. | Untrusted input could modify protected attributes (role/permissions/status/ownership) in applications that mass-fill models. | `src/Database/Model.php` `fill()` loop; existing model tests permissive by default. | Add `fillable`/`guarded`, `forceFill`, `isFillable/isGuarded` with backward-compatible default behavior; add positive/negative regressions. | **Mitigated (implemented)** | `ModelTest::testMassAssignmentRespectsFillableAttributes`, `testMassAssignmentRespectsGuardedAttributes`, `testForceFillBypassesMassAssignmentProtection`; targeted model/security tests pass. |
| P3-DX-01 | Medium | CLI / DX | `php nora list` was not recognized even though help text is the primary discovery surface. | Reduced discoverability for users expecting Laravel-style command listing. | `ConsoleApplication` handled `--help/-h/help` only. | Add `list` as help alias. | **Fixed** | `tests/ConsoleTest.php` (8 tests, 180 assertions) pass; manual `php bin/nora list` shows help. |
| P3-SEC-03 | Medium | Session revocation scope | Revocation is implemented for password reset token flow and session-versioned accounts; broader credential-change/suspension APIs are not fully surfaced as dedicated framework operations. | Applications may need explicit hooks for suspension/compromise flows beyond reset. | `AccountLifecycleService`/store expose reset/verify/create; no dedicated suspend/revoke API. | Document current guarantees/limitations; extend API in future if needed. | **Open (documented)** | N/A |
| P3-SEC-04 | Low | Filesystem TOCTOU/races (P2-05) | Residual check-then-operate windows can remain in portable PHP filesystem flows. | Conditional risk in hostile shared storage environments. | Phase 2 findings; existing fail-closed symlink/validation protections remain. | Preserve protections, avoid unsafe refactors, document residual risk; OS/deployment hardening required. | **Open (residual)** | Existing upload/rate-limit/storage tests retained. |
| P3-SEC-05 | Informational | Reactive CSRF/origin (P2-06) | Same-origin enforcement rejects explicit cross-site `Sec-Fetch-Site` and validates `Origin` when present; missing `Origin` relies on session-bound state, revision checks, and CSRF token validation. | Defense-in-depth depends on correct CSRF/session integration and deployment headers. | `src/Reactive/ReactiveEngine.php` `assertSameOrigin()` / CSRF checks; Phase 2 notes. | Keep layered protections, document behavior, avoid weakening checks. | **Open (by design/defense-in-depth)** | Existing reactive/CSRF regressions in baseline suite. |
| P3-REL-01 | High | Release evidence / verification | Full post-change suite rerun, Linux verification, and multi-PHP local matrix not completed in this pass. | Cannot claim full RC readiness without complete evidence. | Test results recorded in `PHASE_3_TEST_RESULTS.md`. | Run full suite and CI-equivalent checks on final commit; verify Linux/PHP matrix in CI or controlled environment. | **Open** | Baseline full suite recorded; targeted post-change tests passed. |
| P3-REL-02 | Medium | Package publication | Stable Packagist/release publication and clean public install path not verified. | External users cannot rely on stable Composer install yet. | README/docs state dev-main/VCS path; no verified stable tag/publication in this pass. | Maintainer-controlled release/tag/publication process. | **Blocked by external decision/infrastructure** | Composer validation passes. |
| P3-DOC-01 | Medium | Documentation completeness | Documentation set is broad but must remain aligned with actual APIs and clearly state limitations (installation channels, DB/MySQL local verification, auth lifecycle boundaries, session revocation upgrade notes). | Misleading docs reduce trust and adoption. | Existing docs under `docs/`; README and release readiness docs. | Update/add Phase 3 release docs, migration guidance, and status notes; avoid documenting non-existent APIs. | **Partially addressed** | Docs reviewed/updated where necessary in this pass. |
| P3-DX-02 | Low | Cache CLI | No application cache subsystem found; `cache:clear` not implemented to avoid fake behavior. | Minor DX gap versus Laravel-like expectations. | Source search found cache headers only in reactive responses. | Document absence or implement only if real cache layer exists. | **Open (deferred, by design)** | N/A |

## 3. Critical and High issues disposition

- **Resolved/mitigated:** P3-SEC-01 (session revocation on password reset via session versioning), P3-SEC-02 (ORM mass-assignment controls with BC defaults), and preservation of Phase 2 fixes.
- **Remaining High/Release-blocking for "READY FOR RC":** P3-REL-01 (incomplete final verification evidence in this environment), plus external publication/CI confirmation typical of a maintained release.

## 4. Platform and compatibility notes

- **Windows:** Inspected and tested locally (PHP 8.2.34). Baseline and targeted tests executed.
- **Linux:** **NOT VERIFIED** in this environment. CI workflow includes Ubuntu jobs but was not run for this exact local uncommitted state.
- **PHP versions:** `composer.json` declares `^8.2`; local run used 8.2.34. CI matrix includes 8.2 and 8.3.
- **Databases:** Dedicated MySQL integration tests exist and require configured loopback `nora_test`; SQLite used in many unit tests. Local dedicated MySQL execution not separately claimed beyond existing environment configuration.

## 5. Decision input

Nora has meaningful security and maintainability improvements in this pass, but full release-candidate gates (complete post-change regression evidence, Linux/CI confirmation for the final tree, and maintainer release actions) are not fully satisfied here. See `docs/releases/PHASE_3_READINESS_REPORT.md` for the final verdict.