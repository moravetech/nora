# Nora Release Candidate Checklist

Use this checklist with evidence. Do not check items without recorded verification for the release/RC commit.

## Phase 4 (RC1 Track) evidence update — 2026-11-21 (closure run)
**Status:** **RC1 NOT READY** (P0 evidence incomplete). Project remains **Pre-stable — Maturing**.  
**Evidence:** `docs/audit/PHASE_4_AUDIT.md`, `docs/audit/PHASE_4_TEST_RESULTS.md`, `docs/releases/PHASE_4_READINESS_REPORT.md`

- [x] Repeatable PostgreSQL integration test added for supported DB/migration runner behaviors (`tests/Integration/DatabaseServerPostgreSqlTest.php`, env-gated, loopback/`nora_test` safeguards).
- [ ] **P0:** Real PostgreSQL integration suite executed against a configured dedicated loopback `nora_test` database with recorded results. (Not verified: 127.0.0.1:5432 reachable but no configured/accessible test credentials; `psql` unavailable; Docker daemon unavailable.)
- [x] Existing MySQL integration test/CI contracts preserved (no weakening of `DatabaseServerTest` / `AccountLifecycleMySqlTest`).
- [ ] **P0:** Real MySQL integration suite executed against a configured dedicated loopback `nora_test` database with recorded results (CI-equivalent or reproducible local/container evidence). (Not verified: 127.0.0.1:3306 reachable but no configured/accessible test credentials; root probe denied; Docker daemon unavailable.)
- [x] Unified runtime lifecycle E2E strengthened and verified locally (bootstrap/providers/config/routes/middleware/request/events/termination/DB cleanup) via `tests/Integration/UnifiedRuntimeIntegrationTest::testEndToEndLifecycleBootstrapsProvidersMiddlewareRoutesEventsAndCleanup`.
- [x] "New app from scratch" scaffold generation and local/runtime checks verified: `php bin/nora new SmokeApp` in isolated temp dir, generated PHP lint clean, HTTP runtime smoke passed on PHP built-in server (`GET /welcome` 200 with `Welcome to Nora`; `GET /` 200 with `data-nora-page`, `Counter`, `/assets/app.css`).
- [x] **P0:** Generated app verified end-to-end through supported documented install/build/test flow with default remote dependency manifest and recorded results (isolated temp dir `C:\Users\PRODESK\AppData\Local\Temp\nora-phase4-smoke-399367b7cad043d09c89fa9ca40aa658\SmokeApp`; scaffold VCS repo `https://github.com/Yasser-devb/nora.git`, `morave/nora:dev-main`; `composer install --no-interaction --prefer-dist` PASS, resolved `morave/nora dev-main a6db327`; `.env` copied; `npm install --no-audit --no-fund` PASS; `npm run nora:assets:build` PASS, Tailwind v4.3.5 output `public/assets/app.css`; `vendor\bin\phpunit --do-not-cache-result` PASS, 1 test/4 assertions in installed app context).
- [x] Local quality gates (this environment): `composer validate --strict` PASS; `composer audit` no advisories; PHPUnit Unit/Security/Integration/Full suites PASS with expected skips/logs (full: 239 tests, 1296 assertions, 5 skipped).
- [ ] CI/release-commit gates green with evidence (package-consumer, PHP matrix, DB-backed jobs, reactive-browser/Tailwind/Playwright as applicable). Not executed locally for a candidate commit.

## Phase 3 baseline checklist (preserved)

## Security and correctness
- [x] Phase 2 protections preserved (P2-01 upload source validation, P2-02 session timeout controls, symlink fail-closed behavior).
- [x] P2-03 password-reset session revocation path mitigated with session versioning and regression coverage (targeted tests passed).
- [x] P2-04 ORM mass-assignment controls implemented with backward-compatible defaults and regression coverage (targeted tests passed).
- [ ] P2-03 extended flows (suspension, explicit credential-compromise revocation API/hooks) fully designed and validated as needed.
- [ ] Residual filesystem race/TOCTOU risks reviewed for target deployment and documented.
- [ ] Reactive origin/CSRF/session protections reviewed against deployment headers and client behavior.
- [x] No secrets committed or exposed in docs/logs.

## Testing and quality gates
- [x] Baseline full PHPUnit suite recorded (229 tests, 1248 assertions, 3 skipped on Windows/PHP 8.2.34 in this pass).
- [x] Targeted regressions for Phase 3 changes passed (model/account lifecycle/security/console).
- [ ] Full PHPUnit suite passes on the final release commit.
- [ ] Security suite and integration suite pass on the final release commit.
- [ ] `composer validate --strict` passes on the final release commit.
- [ ] `composer audit` reviewed/passes as appropriate for release policy.
- [ ] Browser/Tailwind/npm checks pass when included in release scope (CI or local evidence).
- [ ] No tests weakened, skipped, or removed to obtain green results.

## Compatibility and packaging
- [x] PHP requirement reviewed (`^8.2` in composer metadata).
- [ ] PHP 8.2 and 8.3 CI matrix green for release commit.
- [x] Windows local behavior exercised.
- [ ] Linux behavior verified (CI and/or runtime environment).
- [ ] Database integrations verified as supported (including dedicated MySQL when available).
- [x] Composer package metadata/autoload/bin reviewed.
- [ ] Clean consumer/install verification completed for intended distribution path.
- [ ] Archive contents/exclusions reviewed.

## Documentation and developer experience
- [x] Phase 3 audit, test results, readiness report, release notes draft, and migration guide prepared.
- [x] CHANGELOG updated with factual Unreleased/Phase 3 entries.
- [ ] README and docs verified against actual APIs/commands for the release commit.
- [ ] CLI help/examples verified (`list`, existing commands) for release commit.
- [ ] Reactive and Tailwind integration docs/examples verified against implementation.
- [ ] Upgrade/session-revocation schema notes communicated to users of account lifecycle tables.

## Release engineering
- [ ] Version/tag/changelog finalized by authorized maintainer.
- [ ] CI green on official repository for release commit.
- [ ] Release notes finalized and evidence-linked.
- [ ] No false claims of unverified platforms, databases, or security certification.
- [ ] Publication/push/tag performed only with explicit authorization.

## Sign-off
- [ ] **READY FOR RC1** (all mandatory P0/P1 RC gates satisfied with evidence on the RC commit)
- [ ] **CONDITIONALLY READY** (critical mitigations in place; remaining verification/release gates pending)
- [x] **NOT READY FOR RC1** (P0 evidence incomplete: live MySQL/PostgreSQL integration runs and CI/release-commit evidence pending; external from-scratch install/build/test flow verified in isolated temp environment on 2026-11-21)