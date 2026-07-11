# AGENTS

## Purpose

Operational guidance for coding agents working in this repository.

## Scope

- Keep changes minimal and low risk.
- Do not change business logic unless explicitly requested.
- Prefer documentation, tests, and contract/spec updates over runtime behavior changes.

## Stack Summary

- Runtime: PHP 8.2+
- Web app shape: multi-page PHP endpoints (no centralized framework router)
- Data: MySQL via mysqli
- Package managers: Composer (PHP), npm (tooling)

## Safe Command Policy

Safe commands allowed without extra confirmation:

- composer install
- php tests/endpoint_contract_test.php
- php -l <file>
- npx @apidevtools/swagger-cli validate openapi.yaml

Ask before commands that are destructive or modify history/state significantly.

## Endpoint Conventions

- Mutation endpoints typically require POST.
- CSRF checks are expected on state-changing browser endpoints.
- Webhooks validate signatures before processing payloads.
- Redirect-driven endpoints often return 302 for browser flows.

## Test Strategy

- Use behavior-preserving tests only.
- Favor contract/smoke checks around existing endpoints and guards.
- Do not rewrite application logic to satisfy tests.

## Documentation Contract

When adding/changing endpoints or contracts, keep these in sync:

- docs/repo-architecture.md
- docs/agentic-ready.md
- openapi.yaml
- tests/endpoint_contract_test.php
