# Error Handling & Logging Unification Plan

## Summary

Unify backend + frontend error handling for translation workflows so editors always see clear, actionable, localized messages while logs retain full technical diagnostics. Introduce a domain-level exception model, stable error codes, normalized API error envelopes, and a frontend message mapper.

## Why This Is Needed

Current behavior is inconsistent:

- Different endpoints return different error shapes (`error` string vs `success/error` vs implicit failures).
- Provider/internal messages leak to users (e.g. Anthropic `x-api-key header is required`).
- Frontend often collapses to `error_unexpected` or generic HTTP status text.
- Backend logs are useful but not structured around stable error codes/correlation.

This creates poor UX, brittle frontend handling, and difficult support triage.

## Goals

1. **User-safe messages**: no raw provider internals for non-technical users.
2. **Stable contract**: consistent API error shape across endpoints.
3. **Actionable semantics**: machine-readable `error.code` + `retryable`.
4. **Strong observability**: structured backend logs with context.
5. **Backward compatibility**: transitional support for existing frontend code while migrating.

## Non-Goals

- Reworking translation architecture (extract/translate/reassemble).
- Changing provider selection/config model.
- Adding full Sentry/Honeycomb integration in this pass.

---

## Target Design

## 1) Domain Exception Model (Backend)

Create explicit domain exceptions under `src/Exceptions/`.

### Base class

`MagicTranslatorException extends RuntimeException`

Required metadata:

- `errorCode(): string` (stable machine code, e.g. `provider_auth_failed`)
- `messageKey(): string` (translation key for frontend/admin surfaces)
- `retryable(): bool`
- `httpStatus(): int` (for non-job HTTP paths)
- `context(): array` (safe structured context for logs)

### Concrete exceptions

- `ProviderNotConfiguredException`
- `ProviderAuthException`
- `ProviderRateLimitedException`
- `ProviderUnavailableException`
- `ProviderResponseInvalidException`
- `TranslationConfigException`
- `TranslationDispatchException`
- `ResourceNotFoundException` (optional if we want typed not-found errors)
- `AuthorizationException` adapter exception (optional)

## 2) Provider Exception Mapping

Map external/provider exceptions to domain exceptions at service boundary:

- `PrismTranslationService`
  - catch Prism/provider request exceptions
  - map status classes:
    - 401/403 → `ProviderAuthException`
    - 429 → `ProviderRateLimitedException`
    - 5xx/network/timeout → `ProviderUnavailableException`
    - malformed structured payload → `ProviderResponseInvalidException`
- `DeepLTranslationService`
  - map SDK exceptions:
    - `AuthorizationException` → auth
    - `TooManyRequestsException`/`QuotaExceededException` → rate limit / quota code
    - `ConnectionException`/5xx-like errors → unavailable
    - parse/reassembly mismatches → response invalid

Never leak raw upstream messages as user-facing output; keep raw details in logs via `previous` + `context`.

## 3) Unified Error Envelope

Normalize controller JSON errors:

```json
{
  "success": false,
  "error": {
    "code": "provider_auth_failed",
    "message": "Translation service authentication failed.",
    "message_key": "magic-translator::messages.error_provider_auth_failed",
    "retryable": false
  }
}
```

For job status failures:

```json
{
  "id": "...",
  "target_site": "de",
  "status": "failed",
  "error": {
    "code": "provider_auth_failed",
    "message": "Translation service authentication failed.",
    "retryable": false
  }
}
```

### Compatibility strategy

For one transition cycle, include legacy string fallback when helpful:

- Keep `error.message` present.
- Avoid relying on top-level string `error` in new code.

## 4) Frontend Error Mapping Layer

Create a single error-normalization helper in `resources/js/core/`:

- `normalizeApiError(payloadOrError): NormalizedError`
- `messageForErrorCode(code): string` using i18n keys

Update all dialogs/components to use this consistently:

- `TranslationDialog.vue`
- `FieldComparisonDialog.vue`
- other translation UI surfaces

Behavior:

- Known `error.code` → localized, actionable message
- Unknown/missing code → generic fallback
- Never show raw provider stack/internal text by default

## 5) Logging Standard

Introduce centralized log helper/service (e.g. `TranslationErrorLogger`) to log once per boundary with structured payload.

Log fields:

- `error_code`, `retryable`, `provider`, `model`
- `entry_id`, `source_site`, `target_site`, `job_id`
- `exception_class`, `exception_message` (internal only)

Levels:

- `warning`: retryable provider issues (rate limits, transient outages)
- `error`: non-retryable config/auth/invalid payload

Add correlation id (`request_id`/`job_id`) where available.

---

## Implementation Plan

## Phase 1 — Error Taxonomy & Contracts

1. Add exception classes in `src/Exceptions/`.
2. Add `ApiError` value object/transformer (`toArray()`) in `src/Support/`.
3. Add helper to convert `Throwable -> ApiError` with safe fallback.
4. Define error codes and i18n keys in `resources/lang/en/messages.php`.

Deliverable: stable error model + code list.

## Phase 2 — Service Layer Mapping

1. Update `PrismTranslationService` to catch and map provider errors.
2. Update `DeepLTranslationService` similarly.
3. Keep existing unit tests green; add mapping tests for representative failures.

Deliverable: provider exceptions no longer escape as raw generic runtime failures.

## Phase 3 — Job + Controller Normalization

1. Update `TranslateEntryJob` cache payload failures to store structured `error` object (or transitional dual format).
2. Update `TranslationController::trigger/status` to always emit normalized envelopes.
3. Update `FieldValueController` error responses to same envelope format where applicable.
4. Ensure auth/forbidden/not-found validation paths remain semantically correct.

Deliverable: consistent backend API contract.

## Phase 4 — Frontend Unification

1. Add `normalizeApiError` + message mapping in `resources/js/core/`.
2. Update API client (`api.ts`) to parse non-2xx JSON error bodies before fallback to HTTP status text.
3. Update dialogs/components to consume normalized errors everywhere.
4. Remove ad-hoc per-component error branching where possible.

Deliverable: consistent and user-safe error copy across v6 UI.

## Phase 5 — Logging & Observability

1. Add structured logging helper/service.
2. Replace scattered ad-hoc backend logs in translation boundary paths.
3. Ensure sensitive data is never logged.

Deliverable: support-friendly logs with stable codes.

## Phase 6 — Verification & Rollout

1. Backend tests:
   - unit tests for exception mapping
   - feature tests for normalized response envelope
   - status endpoint failure shape tests
2. Frontend tests (where practical) + manual browser verification in v6 sandbox:
   - missing API key
   - auth failure
   - rate limit (simulated)
   - transient provider failure
3. Run full checks:
   - `./vendor/bin/pest`
   - `./vendor/bin/pint --test`
   - `npm run build`

Deliverable: verified end-to-end behavior.

---

## Acceptance Criteria

1. Editors never see raw provider/internal exception strings.
2. All translation-related API failures include stable `error.code`.
3. Frontend maps error codes to localized messages consistently.
4. Polling/status failures render meaningful messages, not generic unexpected errors.
5. Logs include structured context (`entry_id`, `target_site`, `job_id`, `error_code`).
6. Existing translation success paths remain unchanged.

## Proposed Initial Error Code Set

- `provider_not_configured`
- `provider_auth_failed`
- `provider_rate_limited`
- `provider_unavailable`
- `provider_response_invalid`
- `translation_config_invalid`
- `translation_dispatch_failed`
- `resource_not_found`
- `forbidden`
- `unauthorized`
- `validation_failed`
- `unexpected_error`

## Risks & Mitigation

- **Risk:** breaking existing frontend assumptions about error shape.
  - **Mitigation:** transitional compatibility layer + dual fields during migration.
- **Risk:** overfitting provider-specific error parsing.
  - **Mitigation:** conservative fallback mapping + comprehensive tests.
- **Risk:** duplicate logging noise.
  - **Mitigation:** log at boundary once (service/controller/job), not every catch.

## Suggested Commit Strategy

1. `feat: add translation error taxonomy and api error contract`
2. `fix: map prism and deepl provider failures to domain errors`
3. `fix: normalize translation controller and job failure payloads`
4. `fix: unify frontend error handling for translation ui`
5. `chore: add structured translation error logging and docs`
