# Issue #13 — User-owned AI connections and exact Admin provisioning

## Goal

Implement the requested personal AI workflow for RWYS users and safely provision the confirmed maintainer identity. Done means ordinary authenticated users can create, inspect, use, and revoke their own supported OpenAI/Claude connections; credentials are encrypted at rest and isolated by owner; inference is routed only through the authenticated owner’s selected connection; the exact `johnkarlo.315@gmail.com` identity is provisioned as persistent `admin` only through an explicitly local-development-approved bootstrap path; and automated tests prove the security and failure boundaries without live provider calls, paid model usage, destructive resets, plaintext credentials, or production role changes.

The existing workspace `ready` state remains scaffolding until a real connection has been validated. Live provider/OAuth eligibility checks remain a separate, explicitly approved manual gate.

## Current baseline and confirmed decisions

- Repository: `jkcachero/rwys-4.0`, branch `factory/issue-13`, current checkout at merge commit `b366338` (PR #10 / issue #9).
- `app/Models/User.php` has no role field or provider-connection relationship; fillable attributes are limited to `name`, `email`, and `password`, with Laravel's hashed password cast.
- `WorkspaceController` exposes an authenticated dashboard and `/workspace/read`; the dashboard payload hardcodes provider status `ready` and currently supports only AdKit/Zernio read capabilities.
- `routes/ai.php` contains no connection or OAuth routes.
- `DatabaseSeeder` creates only the generic factory user; no exact Admin bootstrap exists.
- Existing tests cover guest denial, bounded read requests, fake-driver behavior, and workspace scaffolding, but not personal credentials, isolation, inference, OAuth, or role provisioning.
- Exact identity requirement: email `johnkarlo.315@gmail.com`, name `John Karlo`, persistent role `admin`.
- The local-only bootstrap credential may be `password` only when explicitly enabled for local development; it must be Laravel-hashed, never stored in plaintext, never become a production/shared default, and must not overwrite an existing user password unless an operator explicitly performs a targeted local-only reset.
- Related issue #11 covers real OpenAI/Claude inference and config-driven Admin users; implementation must preserve that ownership boundary and avoid duplicating an incompatible provider/auth design.

## Scope

### In scope

- A durable user role representation and authorization boundary for `admin`.
- Idempotent, guarded local-development Admin provisioning by exact email.
- Owner-scoped AI connection persistence with encrypted secrets and provider metadata.
- Shared connection lifecycle endpoints/UI: create, validate/status, select/use, and revoke/delete.
- Supported OpenAI and Claude authentication forms and server-side provider adapters, limited to the auth modes confirmed by current package/provider documentation and approved for this delivery.
- Personal inference routing through the authenticated owner’s connection.
- Offline fakes and feature/unit coverage for happy paths, validation, isolation, authorization, provider failures, and provisioning safety.
- An operator walkthrough that distinguishes automated fake-backed verification from separately approved live checks.

### Explicitly out of scope

- Live provider calls, paid model usage, production OAuth verification, or production role changes during automated execution.
- Broad provider parity, arbitrary tool execution, account discovery, or sharing one user’s connection with another user.
- Plaintext API keys, OAuth tokens, refresh tokens, or provider secrets in database columns, logs, responses, fixtures, screenshots, or frontend state.
- Blanket password resets, `migrate:fresh` against an existing database, automatic production email verification, or production auto-provisioning of the maintainer identity.
- Replacing the existing Laravel/Inertia/Vue stack or changing dependencies without approval.

## Phases

### Phase 1 — Lock contracts, package capability, and schema

**Changes**

- Confirm installed Laravel AI SDK and framework APIs before implementation; document the selected supported OpenAI/Claude auth modes and any package limitations in the execution notes.
- Define the role contract (`admin` plus ordinary user behavior), authorization policy/gate, and exact local bootstrap configuration boundary.
- Add migrations/models/factory support for the role and owner-scoped AI connections. The connection record should include owner ID, provider, display label, encrypted credential payload, non-secret metadata, status, validation timestamps, selected/default state, and revocation timestamps as needed by the existing conventions.
- Add appropriate unique/index constraints for owner/provider identity and lookup paths; never index or expose encrypted secret contents.
- Add typed data objects or request classes for connection input, status, and inference requests, following existing workspace/provider conventions.

**Tests**

- Schema/model tests for role persistence, encrypted casts, owner relationship, revocation/status fields, and uniqueness constraints.
- Authorization tests proving ordinary users cannot read or mutate another user’s connection and non-admin users cannot access Admin-only operations.
- Configuration tests proving the bootstrap path is disabled outside explicitly allowed local/test environments.

**Verification**

```bash
composer show --direct
php artisan test --compact tests/Feature/WorkspaceTest.php
php artisan test --compact tests/Unit
```

### Phase 2 — Implement safe exact-email Admin provisioning

**Changes**

- Add a dedicated provisioning action/command or seeder path that targets exactly `johnkarlo.315@gmail.com` and `John Karlo` when the local-development bootstrap flag is explicitly enabled.
- Create the user with persistent `admin` role and a Laravel-hashed password on fresh disposable local/test setup.
- Make existing-user provisioning idempotent: preserve an existing password and unrelated account attributes by default; permit a targeted local-only reset only through an explicit operator option for that exact email.
- Reject or no-op the bootstrap in production/shared environments and never auto-verify an account there.
- Keep credentials out of source, logs, API responses, and committed fixtures; use environment/config boundaries for the opt-in bootstrap secret if the existing application requires one.

**Tests**

- Fresh local bootstrap creates the exact identity, role, and hashed password.
- Re-running bootstrap does not duplicate the user or overwrite an existing password.
- Explicit targeted local reset changes only the exact local user.
- Production/shared-environment guard rejects the operation.
- Wrong email, similar name, blanket reset, and automatic verification paths are rejected.

**Verification**

```bash
php artisan test --compact tests/Feature
php artisan test --compact --filter=Admin
```

### Phase 3 — Build owner-scoped connection lifecycle

**Changes**

- Add authenticated routes/controllers/actions for listing redacted connection status, creating/updating a connection, validating credentials through the approved adapter boundary, selecting a connection for inference, and revoking/deleting it.
- Encrypt credential/token material using Laravel-supported encrypted casts or an equivalent application convention; decrypt only inside the server-side provider adapter.
- Enforce current-user ownership on every query and mutation. Return only provider, label, capability, status, and timestamps; never return secret material.
- Add provider adapters/contracts for OpenAI and Claude with explicit auth-mode boundaries. Keep live calls behind a provider interface and fake implementations for tests.
- Add rate/size validation and safe error mapping so provider errors do not leak credentials or raw upstream secrets.

**Tests**

- Create/list/update/revoke happy paths for each supported provider.
- Invalid provider/auth payload, missing credentials, malformed callback/state, revoked connection, and upstream failure paths.
- Cross-user read/update/delete attempts return denial/not-found without revealing existence.
- Responses, logs, and serialized models contain no secret values.
- Fake adapters assert that only the authenticated owner’s decrypted credentials are passed to the provider boundary.

**Verification**

```bash
php artisan test --compact tests/Feature/AIConnectionTest.php
php artisan test --compact tests/Unit/AIConnection*Test.php
vendor/bin/pint --dirty --format agent
```

### Phase 4 — Add shared provider auth/OAuth completion where supported

**Changes**

- Implement the shared connection form/status/disconnect UI using existing Inertia/Vue components and route patterns.
- Add CSRF/state/redirect validation for any OAuth flow. Bind callback completion to the initiating authenticated user and a short-lived server-side state record; do not trust a client-supplied owner ID.
- Preserve the official issue #11 OAuth guide and verify provider/version eligibility before enabling any live mode.
- If a provider does not support the required OAuth topology in the installed SDK or approved account type, ship the explicit API-key/manual-credential path and mark OAuth as blocked rather than faking completion.
- Update dashboard capability/status data so `ready` means a usable owner connection exists, not merely that scaffolding is present.

**Tests**

- Form validation and redacted status rendering.
- OAuth state creation, callback success, expiration, mismatch, replay, and wrong-user rejection using fakes.
- UI tests for empty, connected, validating, revoked, unsupported, and failure states.
- Assertions that no live OAuth/provider request occurs in automated tests.

**Verification**

```bash
npm run types:check
npm run check
php artisan test --compact tests/Feature/AIConnectionTest.php tests/Feature/WorkspaceTest.php
npm run build
```

### Phase 5 — Route personal inference through the selected connection

**Changes**

- Extend the existing workspace orchestration boundary so inference requires an authenticated user and an active, owner-owned connection selected by provider/model policy.
- Keep AdKit/Zernio read behavior intact while adding personal OpenAI/Claude inference behind explicit capability/status checks.
- Use the installed Laravel AI SDK’s agent/provider APIs only after documentation/version verification; otherwise keep the adapter interface narrow and deterministic until the provider capability is confirmed.
- Add bounded request validation, correlation IDs, timeout/error mapping, and safe audit metadata without logging prompts or secrets unnecessarily.
- Update the workspace UI to show connection/provider state and actionable failure messages, while keeping all database/provider operations server-side.

**Tests**

- Fake-backed OpenAI and Claude inference success tests.
- No connection, revoked connection, wrong selected owner, unsupported model, timeout, malformed response, and provider failure tests.
- Cross-user isolation tests at both HTTP and service boundaries.
- Assertions that existing AdKit/Zernio read tests remain passing and that fake-backed tests never call live providers.

**Verification**

```bash
php artisan test --compact tests/Feature/WorkspaceTest.php tests/Feature/AIConnectionTest.php
php artisan test --compact tests/Unit
npm run types:check
npm run check
vendor/bin/pint --dirty --format agent
```

### Phase 6 — Operator walkthrough and separate live gates

**Changes**

- Add the requested walkthrough evidence/checklist covering local Admin login, hashed-password verification, idempotent provisioning, connection creation/status/revocation, owner isolation, and fake-backed inference.
- Explicitly label automated results as offline/fake-backed and list unmet live requirements: approved provider credentials, callback URLs/secrets, model/cost limits, account eligibility, and operator confirmation.
- Perform live provider/OAuth/manual checks only in a separately approved environment and record pass/fail/block status without treating screenshots or fake tests as live proof.

**Verification**

```bash
php artisan test --compact
npm run types:check
npm run check
npm run build
```

## Acceptance criteria

- Exact `johnkarlo.315@gmail.com` / `John Karlo` identity can be provisioned as persistent `admin` only through the guarded local-development path.
- Existing-user provisioning is idempotent and password-preserving by default; targeted reset is explicit and local-only.
- Ordinary users can own and use their own supported AI connections.
- Encrypted credentials are owner-isolated, redacted from responses/logs, and never accessible to another user.
- OpenAI/Claude auth behavior matches verified installed-package/provider support; unsupported live modes remain visibly blocked.
- Workspace inference uses only the authenticated user’s active connection and does not regress existing bounded read behavior.
- Automated verification is offline and non-destructive; no live provider calls, paid usage, production role changes, plaintext credentials, or destructive resets occur.
- Operator walkthrough separates proven fake-backed behavior from separately approved live requirements.

## Risks and blockers

- Provider OAuth support and account eligibility may differ from the desired product flow; resolve through current official documentation and package inspection before selecting the first live mode.
- Issue #11 may establish overlapping Admin/configuration contracts; reconcile before adding a competing role or provisioning abstraction.
- Encryption key rotation and credential revocation semantics need to follow existing deployment/configuration practices before production enablement.
- Live verification is blocked until the maintainer supplies approved provider accounts, callback configuration, model/cost limits, and explicit permission for manual checks.

## Implementation order and stop conditions

Implement phases in order. Do not begin live OAuth/provider checks until Phases 1–5 pass their offline tests and the maintainer approves the live gate. Stop and return to planning if the installed SDK cannot support a requested provider/auth mode, if issue #11 defines a conflicting contract, or if production safety cannot be demonstrated through tests.

## Plan status

Planning complete; ready for Execute once the governed work-item transition is accepted.

## Verification evidence expected from Execute

- Repository diff limited to issue #13 scope.
- Focused PHPUnit/feature test output for each phase.
- Pint/type/check/build output where applicable.
- Explicit report of blocked live requirements and confirmation that no live provider or production mutation was performed.
- Walkthrough artifact or issue comment that distinguishes fake-backed automated proof from genuine provider effects.

## Source references

- GitHub issue #13: “Enable user-owned AI connections and provision John Karlo as Admin by exact email”.
- Existing workspace boundary: `app/Http/Controllers/WorkspaceController.php`.
- Existing identity model: `app/Models/User.php`.
- Existing workspace tests: `tests/Feature/WorkspaceTest.php`.
- Existing AI configuration boundary: `config/services.php`, `routes/ai.php`, and `app/Workspace/Services/LaravelAiWorkspaceAnalyst.php`.
- Related feature planning precedent: `.artifacts/plans/issue-9.md`.
- Related issue #11: real OpenAI/Claude inference and config-driven Admin users.
