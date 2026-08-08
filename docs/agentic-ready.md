# Agentic Ready Status

## Goal

Make the repository easier and safer for code agents to analyze, modify, and validate with minimal risk and no business logic changes.

## What Was Added

- `AGENTS.md` for repository-wide agent operating guidance.
- `CLAUDE.md` for assistant workflow and risk-control notes.
- `docs/repo-architecture.md` for stack and endpoint topology.
- `openapi.yaml` with broad endpoint coverage and auth/status/schema baselines.
- `tests/endpoint_contract_test.php` for behavior-preserving endpoint guard checks.
- README updates linking these assets and run commands.

## Baseline Compliance Mapping

The requested external compliance criteria file was not accessible at:

- `C:\Users\abc\Downloads\qa\sfa-recording-service\gitlab-scapper\docs\compliance-checks-criteria.md`

Status for this run:

- A best-effort compliance mapping was performed based on the explicit repo-readiness goals in the task.
- Replace/confirm checklist items once the external criteria file is shared in this environment.

## Checkout Safety Contract

- Guest COD checkout remains supported.
- Guest Razorpay submissions must redirect to customer login before account creation, order persistence, or inventory reservation.
- Razorpay verification retains payment signature checks; both browser callbacks retain CSRF and customer-ownership checks.

## Courier Integration Contract

- Bigship Direct authentication is performed only on the server through `POST /api/outbound/login` using `BIGSHIP_USERNAME`, `BIGSHIP_PASSWORD`, and `BIGSHIP_ACCESS_KEY`.
- Courier API calls use a cached, unexpired `Authorization: Bearer` token. Browser code receives neither credentials nor bearer tokens.
- Domestic checkout quotes use Bigship's configured warehouse pincode and parcel dimensions, then persist the selected courier partner and its `totalCharge` in the existing quote flow.
- Courier shipment creation follows Bigship's create-order, courier-cost, then place-order lifecycle and stores the resulting IDs, AWB, selected courier/rate, and raw lifecycle responses in existing shipment metadata.
- Cron-based tracking sync uses the stored `CustomGlobalOrderId`; Bigship webhooks are rejected until signature verification details are available.
- Bigship secrets are supported only through server environment variables or external `secure-config.php`; tracked configuration examples use empty credential placeholders.

## Validation Performed

- Endpoint contract test execution via PHP CLI.
- OpenAPI syntax validation via Swagger CLI.
- Syntax lint on changed PHP test file.

## Remaining Optional Hardening (No logic changes)

- Add CI job to run endpoint contract test and OpenAPI validation on pull requests.
- Expand OpenAPI coverage for additional admin/customer pages where request contracts are stable.
