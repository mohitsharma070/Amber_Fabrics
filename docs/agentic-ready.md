# Agentic readiness

## Objective

Make the repository understandable and safely verifiable by coding agents through additive documentation, machine-readable endpoint contracts, and behavior-preserving tests. No business logic is changed by these artifacts.

## Readiness assets

| Area | Evidence |
|---|---|
| Human onboarding | `README.md` |
| Repository-wide agent rules | `AGENTS.md` |
| Claude workflow notes | `CLAUDE.md` |
| Architecture and trust boundaries | `docs/repo-architecture.md` |
| HTTP endpoint contract | `openapi.yaml` |
| Executable validation | `composer test`, `tests/agentic_readiness_contract_test.php` |
| Dependency/runtime declaration | `composer.json`, `composer.lock` |
| Secret and artifact exclusions | `.gitignore`, `.htaccess`, `router.php` |
| Schema evolution | `database/migrate.php`, `database/migrations/`, `schema_migrations` |

## Compliance baseline

The task referenced this external file:

```text
C:\Users\abc\Downloads\qa\sfa-recording-service\gitlab-scapper\docs\compliance-checks-criteria.md
```

It is not present on this machine, and an exact public copy could not be located. The repository history contains an earlier Agentic Ready baseline created under the same limitation. This update uses that baseline plus the explicit criteria in the task: onboarding docs, agent instructions, architecture, tests, broad OpenAPI coverage, validation evidence, minimal changes, and no business-logic changes.

Share the source criteria file to perform an authoritative line-by-line reassessment.

## Safe agent workflow

1. Read `AGENTS.md` and architecture documentation.
2. Inspect Git state and the exact endpoint/service/test chain.
3. State assumptions and keep scope minimal.
4. Preserve security and data-integrity guards.
5. Update docs/spec/tests when contracts change.
6. Run focused validation and the complete test command.
7. Review the final diff and request approval before committing or pushing.

## Current limitations

- Tests are predominantly static/contract checks rather than isolated unit or database integration tests.
- OpenAPI represents browser forms, redirects, JSON handlers, and webhooks, but internal admin screens and third-party provider APIs may still be summarized rather than exhaustively modeled.
- No CI workflow currently enforces the documented commands on every pull request.
- Full payment, courier, email, browser, and production deployment validation requires external environments and credentials.
- The referenced external compliance rubric remains unavailable.

## Maintenance rule

When routes, auth requirements, request fields, response envelopes, migrations, or test commands change, update the corresponding readiness assets in the same change.
