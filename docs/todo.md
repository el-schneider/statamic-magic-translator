# TODO — harden Prism timeout/retry behavior for full-page translations

## Goal

Prevent translation failures caused by overly aggressive HTTP timeout defaults (e.g. cURL error 28 at 30s) when translating large pages.

## Execution Plan

1. Add addon-level Prism transport config in `config/content-translator.php`:
   - request/connect timeout envs
   - optional retry attempts + backoff
   - env-backed `max_units_per_request` default wiring
2. Update `PrismTranslationService` to apply request transport options via Prism client config (`withClientOptions` / `withClientRetry`) for structured translation requests.
3. Add/adjust unit tests for Prism service config behavior.
4. Run focused + full verification:
   - `./vendor/bin/pest tests/Unit/Services/PrismTranslationServiceTest.php`
   - `./vendor/bin/pest`
   - `./vendor/bin/pint --test`

## Replan Triggers

- Prism transport option keys are incompatible across providers.
- Retry callback behavior differs from Laravel HTTP client expectations.
