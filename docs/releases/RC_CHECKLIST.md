# Nora Release Candidate Checklist (Phase 3)

Use this checklist with evidence. Do not check items without recorded verification for the release commit.

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
- [ ] **READY FOR RC** (all mandatory gates satisfied with evidence)
- [x] **CONDITIONALLY READY** (critical mitigations in place; remaining verification/release gates pending)
- [ ] **NOT READY** (critical unresolved blockers)