# Issue #11: Connect real OpenAI/Claude inference and provision config-driven Admin users

## Goal
Replace the current deterministic, read-only workspace contract with a secure, backend-owned inference path for supported OpenAI and Claude API providers, and add idempotent config-driven Admin provisioning with policy enforcement. Done means verified users can submit bounded inference requests and receive truthful provider/model/status responses without secrets in browser props or logs; unavailable credentials/provider eligibility produce explicit non-success states; configured Admin identities are created or promoted without overwriting passwords, 2FA, or unrelated user data; and PHPUnit coverage proves success, failure, authorization, idempotency, and secret-redaction behavior.

## Scope

### In scope
- Provider configuration and capability/status reporting using the already-declared `laravel/ai` dependency in `composer.json`.
- Backend service/contract boundary for OpenAI and Claude inference, with fake providers for tests and no live provider calls in automated tests.
- Authenticated, verified workspace inference endpoint and request validation; preserve the existing Inertia dashboard as the presentation surface while replacing its deferred provider contract with truthful server-provided state.
- Conversation/request response shape needed by the existing `resources/js/pages/dashboard` consumer, including correlation IDs and safe error states.
- User authorization model and policy/middleware for Admin-only operations.
- Config-driven exact-identity Admin provisioning, safe promotion of an existing user, and idempotent reruns.
- Feature/unit tests for provider contracts, workspace authorization and responses, Admin provisioning, and preservation of password/2FA fields.

### Explicitly out of scope
- Browser-session reuse, token scraping, unsupported Claude.ai login, personal-subscription proxies, or client-side provider credentials.
- Unbounded agent tools, publishing/ad side effects, AdKit/Zernio write operations, or unrelated issue #9 scope.
- Provider billing changes, deployment, production secret rotation, or live spend during tests.
- Destructive database resets such as `migrate:fresh`.
- Replacing the existing React/Inertia frontend architecture with a different UI framework.

## Phases

### Phase 1 — Establish configuration, domain contracts, and authorization schema

**Changes**
- Add provider settings to `config/services.php` or a dedicated config file using environment-backed credentials/model identifiers; never expose raw credentials through Inertia props, JSON responses, exceptions, or logs.
- Add a migration for the smallest role representation supported by the current single-user model (prefer a constrained `role` column/enum or equivalent policy-backed attribute over a new permissions package, because no authorization package is installed).
- Update `User` casts/fillable or role methods and add an Admin policy/gate. Keep exact identity matching in provisioning by normalized configured email, never by display name.
- Add a dedicated `AdminAccessSeeder` or provisioning action invoked from `DatabaseSeeder`, reading exact configured Admin email/name/password inputs without changing configured secrets. Existing matching users must be promoted in place and retain password, email verification, passkeys, 2FA, timestamps, and unrelated attributes.

**Tests**
- Migration/model tests verify role persistence and default non-Admin behavior.
- Seeder tests cover first creation, rerun idempotency, existing-user promotion, mismatched identity rejection, and preservation of password/2FA fields.
- Policy/feature tests prove Admin access is granted only to the configured role/identity and ordinary authenticated users are denied.

**Verification commands**
- `php artisan test --compact tests/Feature/AdminAccessTest.php tests/Unit/AdminAccessSeederTest.php`
- `vendor/bin/pint --dirty --format agent`

### Phase 2 — Implement provider abstraction and truthful capability states

**Changes**
- Define an application-level inference contract/data objects for prompt input, normalized response, provider/model metadata, correlation ID, and typed unavailable/authentication/rate-limit/provider-error outcomes.
- Bind OpenAI and Claude implementations through Laravel’s container using the installed `laravel/ai` APIs after confirming the installed package API from the lockfile/vendor tree; do not build direct ad hoc HTTP clients if the package supports the required provider boundary.
- Add fake implementations matching the same contract and deterministic fixture responses/errors for tests.
- Add a provider status service that reports configured/available/unavailable state and safe model metadata only. Keep credentials server-side and redact provider request/response payloads from logs.

**Tests**
- Unit contract tests verify both provider adapters normalize successful output identically.
- Failure tests cover missing credentials, unsupported/unconfigured providers, provider authentication failure, rate limits, and upstream errors with safe public messages.
- Logging/response assertions prove API keys, authorization headers, raw prompts where inappropriate, and provider payload secrets are not serialized.

**Verification commands**
- `php artisan test --compact tests/Unit/Inference*Test.php tests/Unit/Provider*Test.php`
- `vendor/bin/pint --dirty --format agent`

### Phase 3 — Replace the deferred workspace contract with authenticated inference

**Changes**
- Add a validated POST route/controller/action for workspace inference under the existing `auth` and `verified` middleware group; enforce request size and provider/model allowlists at the boundary.
- Refactor `WorkspaceController` to obtain provider status from the service instead of hard-coded `deferred` values, while preserving safe omission of `workspace.user.email` and the existing correlation-ID pattern.
- Update `resources/js/pages/dashboard` and generated route/types only as needed to submit requests, display loading/success/unavailable/error states, and avoid rendering secrets or unsupported actions.
- Keep AdKit/Zernio capabilities read-only/deferred unless their existing contracts are independently approved; do not couple this issue to their write paths.

**Tests**
- Update `tests/Feature/WorkspaceTest.php` to assert the new truthful configured/unavailable contract rather than the old unconditional deferred value.
- Add feature tests for guest/anonymous rejection, unverified-user rejection, verified-user success with a fake provider, validation failures, provider-unavailable responses, correlation IDs, and absence of sensitive user/provider data from Inertia props.
- Add frontend type/build checks for the changed dashboard contract.

**Verification commands**
- `php artisan test --compact tests/Feature/WorkspaceTest.php tests/Feature/WorkspaceInferenceTest.php`
- `npm run build`
- `vendor/bin/pint --dirty --format agent`

### Phase 4 — Integration hardening and operator verification

**Changes**
- Review route authorization, config cache behavior, exception rendering, logging context, and database migration rollback.
- Add explicit documentation only if requested by the implementation surface; otherwise encode operational requirements in config validation and tests rather than creating new docs.
- Verify seeded Admin login and current-team/context behavior against the existing authentication conventions before declaring completion.

**Tests and verification**
- `php artisan test --compact`
- `php artisan route:list --except-vendor`
- `php artisan config:clear`
- `vendor/bin/pint --dirty --format agent`
- If dependencies are absent, install them through the repository setup path before testing; never substitute destructive database resets.

## Risks
- `laravel/ai` API details may differ from assumptions: inspect `composer.lock` and installed package classes before implementation and keep the application contract independent of package-specific response types.
- Provider configuration can accidentally leak through serialized exceptions, logs, or Inertia props: add explicit redaction tests and inspect serialized responses.
- Admin provisioning can overwrite credentials or 2FA state: update only role/identity fields and assert preservation before and after reruns.
- A role column can become an authorization bypass if callers trust request data: derive authorization from the authenticated persisted user and policies, never from client props.
- Existing dashboard tests encode the deferred scaffold contract; update them deliberately while retaining guest, verified-user, and privacy assertions.
- Missing dependencies currently prevent baseline test execution because `vendor/autoload.php` is absent; dependency setup is a prerequisite for execution, not a reason to weaken coverage.

## Assumptions
- The target checkout is `/home/jkcachero/.mastracode/web/sandboxes/9bd6414d-2d31-45f0-b7a1-ace4a420236a/rwys-4.0`, with remote `https://github.com/jkcachero/rwys-4.0.git` and branch `factory/issue-11`.
- The inherited triage is confirmed: the current code is intentionally scaffolded/read-only, not a broken partial provider integration.
- `laravel/ai` is the supported integration path because it is already a direct dependency; adding another provider SDK or authorization package is rejected unless the installed package cannot satisfy the required boundary.
- A single persisted Admin role is sufficient for this issue; granular permissions and multi-tenant team authorization are deferred unless existing code proves they are required.
- Provider calls remain synchronous for the first verified contract because the current workspace is request/response based and has no established conversation/job persistence; queues/streaming are separate scope.
- Tests use fakes and HTTP/package mocks only; no real provider spend or side effects occur in CI.
- Configured Admin credentials remain sourced from environment/config and are never printed, changed, or committed.
- Existing AdKit/Zernio read-only/deferred contracts remain unchanged except for truthful composition in the workspace response.

## Rejected alternatives
- **Direct browser-to-provider calls:** rejected because it would expose credentials and bypass Laravel authorization/audit boundaries.
- **Reuse of ChatGPT/Claude web sessions or borrowed tokens:** rejected as unsupported and unsafe.
- **Installing a new role/permission package immediately:** rejected because the current app has one User model and no demonstrated need for package-level complexity; a minimal role policy is lower blast radius.
- **Keep the hard-coded deferred response and add only UI placeholders:** rejected because it would not deliver real inference or truthful status.
- **Couple provider inference to AdKit/Zernio writes:** rejected because those are separate capability contracts and would expand side-effect risk.
- **Queue/streaming-first architecture:** rejected for this pass because no existing persistence/streaming contract exists; synchronous inference is the smallest verifiable complete boundary.

## Open questions
- Which exact production model and spending limit should be approved for OpenAI and Claude?
- Should the initial product expose one provider selected by config or allow an authorized user to choose among both providers?
- Are granular Admin permissions required beyond the initial Admin role for the first release?

These are product/provider decisions; implementation can proceed with the safe config-selected single-provider default and a single Admin role until clarified.

## Completion criteria
- All phases are implemented with PHPUnit coverage and no secrets or unsupported auth flows.
- Workspace responses accurately distinguish configured, unavailable, and provider-error states.
- Admin provisioning is exact-identity, idempotent, non-destructive, and policy-enforced.
- The focused tests, frontend build, formatting, and full test suite pass, or any environment blocker is reported with the exact failing command and cause.
