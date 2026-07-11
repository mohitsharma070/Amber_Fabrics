# CLAUDE

## Repository Working Notes

This file provides AI-assistant guidance for safe maintenance tasks.

## Priorities

- Make smallest-change updates.
- Preserve current business behavior.
- Improve repository observability for automated agents.

## What to Update First

1. docs/repo-architecture.md for structural understanding.
2. openapi.yaml for endpoint contract coverage.
3. tests/endpoint_contract_test.php for behavior guardrails.
4. README.md and docs/agentic-ready.md for operational status.

## Risk Controls

- Do not modify payment, order, inventory, or auth decision logic unless requested.
- Prefer additive tests/docs/spec work.
- Validate syntax and run available tests after changes.

## Verification Commands

- php tests/endpoint_contract_test.php
- php -l tests/endpoint_contract_test.php
- php -l docs/repo-architecture.md (skip, not PHP)
- npx @apidevtools/swagger-cli validate openapi.yaml

## Notes

- This codebase is file-based endpoint routing (`*.php`) instead of framework route definitions.
- Authentication is primarily session-cookie based, with CSRF checks in POST flows.
- External webhooks (Razorpay, COD Guard, shipping courier) use signature verification.
