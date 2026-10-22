# Issue #25 implementation plan — Client-scoped REST/OpenAPI broker

## Status and decision

- **Work item:** `[Mastra] Build the Client-scoped REST/OpenAPI broker for external agents`
- **Repository / branch:** `jkcachero/rwys-4.0` / `factory/issue-25`
- **Baseline:** clean `dbe753f` (`origin/master` at planning time)
- **Plan date:** 2026-10-23
- **Recommended delivery:** phased. Complete the Client and read-only foundation before any write effects. Keep AdKit implementation and all live provider effects blocked until their separate evidence/approval gates are satisfied.

This issue is a large greenfield security boundary, not a single endpoint change. Execution should use small reviewable commits and test-driven vertical slices. A phase must not silently claim a later phase's acceptance criteria.

## Verified starting point

- `routes/api.php` contains only Sanctum token issuance and authenticated `/user`; no `/api/v1` broker exists.
- There is no `Client` tenancy model, Client ownership schema, profile/account assignment schema, operation ledger, webhook receipt store, audit store, OpenAPI artifact, or broker management UI.
- Existing `ProviderConnection.client_id` is an upstream OAuth client identifier, not the requested RWYS Client boundary.
- Existing Zernio and AdKit services are bounded demo readers backed by fakes registered with the runtime `default` scope. They must not be treated as production provider transports or live evidence.
- The application uses Laravel 13, Sanctum 4, Inertia React 3, React 19, Tailwind 4, PHPUnit 12, and Pint. Lockfile versions include Laravel `v13.37.0`, Sanctum `v4.3.3`, Inertia Laravel `v3.5.1`, PHPUnit `12.5.40`, and Pint `v1.32.1`.
- Dependencies are not installed in this checkout, so execution must install them before running framework documentation/search tools or tests. Do not modify dependency constraints.
- `.ai/rules` is absent; repository `AGENTS.md` and the Laravel/testing skills govern the work.

## Non-negotiable boundaries

1. External callers authenticate only with an RWYS-issued Bearer token. Provider credentials, provider profile IDs, and provider account IDs remain server-side.
2. Authorization is exact and current: active Client + verified/active principal + Client membership/role + exact token ability + assigned profile/account + operation-specific permission + enabled server gate.
3. Never expose a generic proxy, arbitrary URL, wildcard/prefix ability, generic dispatcher, SQL, shell, filesystem access, or raw provider payload.
4. Reads and writes must be bounded before provider retrieval. Revalidate ownership after every provider read and before disclosure or fallback reads.
5. Writes are default-off and require durable immutable intent before any provider effect. Never blindly retry a write or change its identity to bypass duplicate protection.
6. API responses are minimal JSON, include a server request UUID in body and `X-Request-ID`, carry `Cache-Control: no-store, private`, and preserve consistent safe error semantics.
7. AdKit routes remain unavailable until authoritative operation/version evidence and sanitized fixtures exist. No direct Meta fallback.
8. No live provider effect, deployment, data reset, credential change, account disconnect, cleanup, or spend occurs without separate explicit approval naming the environment and targets.

## Phase 0 — Re-establish an executable baseline and authoritative contracts

**Purpose:** make the checkout testable and freeze the implementation inputs before schema/API work.

1. Install locked Composer and npm dependencies without changing lockfiles; record exact installed versions and run the existing focused/full baseline suites.
2. Use version-specific Laravel documentation for Sanctum abilities/expiration, authorization, rate limiting, cache locks, HTTP client behavior, resources, queues, scheduling, and testing.
3. Fetch and archive the checked date/version/operation evidence from the current Zernio OpenAPI and official docs for profiles, accounts, posts, analytics, create-post, Instagram, and media uploads. Convert only evidenced shapes into internal DTO/fixture contracts.
4. Record AdKit as **Blocked** unless authoritative operation/version documentation or reviewed original transport source with sanitized fixtures is supplied. Identify the exact missing evidence per proposed operation.
5. Add no implementation dependency unless maintainers separately approve it. Prefer Laravel/Sanctum/JSON-schema test assertions and a repository-owned OpenAPI YAML artifact.

**Verification:** clean lockfiles; existing tests/checks pass or baseline failures are recorded; provider research table lists checked date, operation, source/version, limits, and selected status.

## Phase 1 — Client, principal, assignment, and permission domain

**Purpose:** establish the local authority model that every later route depends on.

### Schema and models

Create migrations, models, factories, policies, and relationships for:

- `clients`: stable local UUID/public ID, name, active state, approved application origin(s), timestamps.
- `client_user`: Client membership with owner/member role, active state, timestamps, and uniqueness constraints.
- `client_operator_grants`: explicit admin/operator assignment authority, separate from Client ownership.
- `provider_profiles`: provider + encrypted/opaque provider profile identity, indexed lookup hash, display metadata, freshness, active state.
- `client_provider_profiles`: exclusive whole-profile assignment with assignment actor/time and uniqueness enforcing one current Client per provider profile.
- `provider_accounts`: stable local UUID/public ID, provider/platform, encrypted/opaque provider account identity, lookup hash, profile relation, display/connection metadata, freshness.
- `client_account_permissions`: account-level active/read/publish/schedule grants; write grants default false.

Use foreign keys, compound unique constraints, and indexes for every ownership, cursor, lookup-hash, and bounded synchronization path. Provider identifiers must never become route authority.

### Services and authorization

- Add focused application services for Client membership, profile assignment/import, account synchronization, and permission changes.
- Whole-profile import must be atomic and bounded to at most `3 x 100` accounts. Reject excess, conflicts, stale profile snapshots, or partial ownership; preserve stable local account IDs on later sync.
- Centralize the exact ability vocabulary in an enum/value object. Reject unknown, wildcard, and prefix abilities.
- Add policies/middleware that separate Client owner actions from privileged operator assignment/provisioning actions.

### Tests first

Feature tests cover two Clients and two owners, operator/owner separation, profile exclusivity, empty profile assignment, four-account import, conflicting concurrent/backfill assignment, stable account IDs, stale snapshots, future sync, inactive/detached/unverified users, and cross-Client non-disclosure.

**Verification:** focused domain/authorization tests; migration rollback/forward tests; query-count checks on list surfaces; Pint.

## Phase 2 — Protected Agent Access management UI and token lifecycle

**Purpose:** let humans safely select a registered Client, assign/sync Zernio profiles, and issue scoped RWYS tokens.

### Backend

- Add authenticated/verified Inertia controllers and Form Requests for Client selection, profile discovery/assignment/sync, account permissions, token issue/rotate/revoke, and capability previews.
- Replace the public email/password `/sanctum/token` issuance route with the protected owner-management lifecycle required by this issue; do not preserve an escalation path that can mint broad tokens directly.
- Persist token metadata needed for safe management: Client binding, issuing principal, safe token ID/name, finite expiry, rotation lineage, revoked/last-used timestamps, and allowed account IDs. Use Sanctum personal access tokens for plaintext Bearer verification; plaintext is shown exactly once and never recoverable.
- Read-only issuance includes only selected read abilities by default. Writes require separately enabled account permission and explicit ability selection. Rotation must not broaden scope and must revoke/replace deliberately rather than retrying issuance automatically.
- Validate approved application origins as metadata/policy inputs; do not treat CORS alone as authentication. Never place provider credentials or token plaintext in persistent frontend props.

### UI

Create an Agent Access area using existing Inertia React layouts/components:

1. Operator Client selection and Zernio profile assignment/sync.
2. Owner account/permission overview.
3. Token create form with exact abilities, account restrictions, expiry, and origin summary.
4. One-time plaintext reveal/copy acknowledgement.
5. Rotation/revocation and truthful disabled/empty/error states.
6. Role-aware navigation that does not advertise inaccessible controls.

### Tests first

- Feature tests for protected routes, policy separation, CSRF through actual forms, malformed requests, one-time plaintext, finite expiry, revoke/rotate, no escalation, restricted tokens, and disabled provider prerequisites.
- React/Vitest tests for responsive/accessibility behavior, useful empty states, token-secret lifecycle, and malformed management responses.

**Verification:** focused PHP and frontend tests, `npm run check`, `npm run types:check`, and production build.

## Phase 3 — Read-only `/api/v1` foundation and OpenAPI 3.1

**Purpose:** ship the initial safe external-agent value without write effects.

### Cross-cutting API pipeline

Implement dedicated API middleware/services for:

- Always-JSON negotiation, server request UUID propagation, and `no-store, private` headers on successes and errors.
- Sanctum authentication plus current token existence/expiry/revocation, exact ability, active Client, principal verification/activity, membership, and account restriction checks.
- Atomic aggregate Client+token read admission (`60/minute`) and per-Client in-flight limit (`2`) using a bounded `120s` stale lease; return safe `429`/`Retry-After` and fail closed when the required store is unavailable.
- Strict query/body allowlists and Form Requests; unknown fields and unsupported overrides fail with `422`.
- Consistent API exceptions/resources for `401/403/404/409/422/429/502/503` without stacks, credentials, raw payloads, or cross-tenant existence disclosure.
- Request audit persistence containing only safe principal/token/Client/capability/resource/correlation/outcome/duration fields.

### Initial read routes

Implement under a named `/api/v1` route group:

- `GET /context`
- `GET /capabilities`
- `GET /zernio/accounts`
- `GET /zernio/accounts/{account}/status`
- `GET /zernio/accounts/{account}/posts`
- `GET /zernio/accounts/{account}/posts/{post}`
- `GET /zernio/accounts/{account}/posts/{post}/analytics`
- `GET /operations`
- `GET /operations/{operation}`

All account/operation route binding resolves local UUIDs through the authenticated Client. Capability discovery intersects implemented operation, exact token ability, current membership/ownership, account permission, mapping health, and server configuration.

### Real Zernio transport

- Replace runtime fake registration with a typed provider contract/HTTP implementation selected by configuration; retain fakes only in tests.
- Pin a fixed HTTPS origin, TLS verification, `5s` connect timeout, `15s` total deadline, max `1 MiB` JSON body, no redirects, bounded pagination, and no automatic write retries.
- Profiles explicitly send bounded `limit/skip`; accounts send `page/limit` and server-owned profile; posts/detail/analytics use only documented selectors and normalize string/populated references without inventing membership proof.
- Default list page is `25`, maximum `100`, maximum depth `1000`; never fetch all then slice.
- Post detail uses a bounded scoped fallback only when needed for membership evidence. Analytics preserves zero/absent/null/pending/partial/unavailable, provenance/freshness, and documented `200/202/402/424` distinctions.

### Operation/read audit schema

Create the operation ledger in read-ready form now: UUID, Client/principal/token/account/capability, idempotency key hash, payload hash, safe status/outcome, timestamps, indexed cursor fields, encrypted recovery fields where applicable. Operation history uses exclusive UUID/time-safe cursor semantics, `limit+1`, no full scans, and no provider calls while polling.

### OpenAPI

Publish `openapi/agent-api-v1.yaml` (or the repository-approved equivalent) with OpenAPI 3.1 security, strict schemas, named operation IDs, examples, pagination, abilities, status families, request IDs, nullable/availability distinctions, and only actually implemented routes. Add contract tests that compare route names/abilities and representative resources/errors to the artifact.

### Tests first

Cover persisted Bearer tokens, every auth failure, exact abilities, origin metadata policy, two-Client isolation, ownership changes during provider reads, aggregate suppression, token narrowing/revocation during reads, strict schemas, query limits, rate/concurrency leases, provider timeouts/oversized/invalid responses, stray HTTP prevention, JSON/header/error privacy, operation cursors, and no fetch-all/N+1 behavior.

**Verification:** focused API/transport/OpenAPI tests; full PHP suite; Pint.

## Phase 4 — Durable immediate Zernio text publishing

**Purpose:** add only the separately approved/evidenced immediate text operation for LinkedIn, Facebook, Threads, and Bluesky.

- Keep `AGENT_ZERNIO_ENABLED` and `AGENT_ZERNIO_PUBLISHING_ENABLED` default false.
- Add `POST /api/v1/zernio/accounts/{account}/posts` with exact `zernio.posts.publish`, allowed platforms, account-level permission, immutable caller idempotency key, strict text schema, and no Instagram implication.
- Before effect: atomically apply Client+token write admission (`6/minute`), in-flight lease, authority revalidation, provider/account preflight, audit persistence, and durable operation intent. Fail closed if required persistence fails.
- Enforce permanent local `(Client, capability, idempotency key)` deduplication. Same key + same canonical payload returns the original operation; same key + changed payload returns `409`, including after token rotation or provider idempotency expiry.
- Make one provider write attempt with no redirect/retry. Classify accepted, completed, failed, partial, and unknown separately; a `2xx`, draft, queued state, or provider ID alone is not proof of publication.
- Store encrypted recovery candidates and indexed hashes. Reconciliation is GET-only, bounded, fenced, fair, and never triggered by ordinary operation polling.

**Tests first:** disabled gates, platform/account permission, authority changes after admission, atomic admission across tokens/workers, persistence failures, same/different payload replay, timeout-after-success, partial/unknown outcomes, no blind duplicate effects, rotation/revocation, and bounded targeted reconciliation.

**Verification:** focused write/idempotency/reconciliation tests with HTTP fakes preventing stray traffic; full PHP suite; Pint.

## Phase 5 — Scheduled Instagram JPEG operation

**Purpose:** add the one narrow scheduled-media operation without conflating accepted schedule with publication.

- Keep `AGENT_ZERNIO_INSTAGRAM_SCHEDULING_ENABLED` default false and require all Phase 4 authority plus exact `zernio.posts.schedule` and separate account-level schedule permission.
- Add `POST /api/v1/zernio/accounts/{account}/scheduled-posts` accepting only a valid bounded JPEG, explicit caption, and future UTC instant within documented/provider-approved windows.
- Validate actual file bytes and dimensions/size requirements, reject truncated/spoofed/unsupported files, and copy media to durable private storage before effect.
- Bound provider calls to at most three target preflight reads plus upload plus create, with no automatic write retries. Persist upload/create evidence and recovery identity under the original immutable operation.
- Expose scheduled/accepted/published/failed/unknown as distinct states. Publication requires later matching provider/native evidence.

**Tests first:** valid/truncated JPEGs, UTC and time-window boundaries, private/permanent media handling, observed membership proof, call budget, upload/create uncertainty, duplicate requests, and scheduled-versus-published distinctions.

**Verification:** focused media/schedule tests; storage assertions; full PHP and frontend suites if management UI changes; Pint/build.

## Phase 6 — Webhooks, recovery, retention, and operator commands

**Purpose:** make uncertain operations recoverable without widening authority or creating duplicate effects.

- Add the fixed Zernio webhook route with exact raw-body HMAC-SHA256 verification, required secret, lowercase signature contract, constant-time comparison, and `1 MiB` body limit.
- Persist encrypted receipts and deduplicate event UUIDs before returning `204`; enqueue asynchronous reconciliation only after durability. Never trust webhook tenant/status/event claims as resource authority.
- Add bounded processors for early, duplicate, out-of-order, cross-profile, queue-failed, and unresolved receipts. Retain unmatched early receipts for 24 hours using indexed candidate batches, fences, leases, and cursors.
- Add scheduled recovery only when provider/schema prerequisites are ready. Distinguish overlap-lock expiry from runtime timeout.
- Add bounded pruning: raw payload eligibility at seven days, completed audit eligibility at 90 days, minimal deduplication tombstones, time budget, continuation while backlog remains, and no dependency on provider-enabled state.
- Add only concrete privileged commands needed for provisioning/grant/revoke, targeted UUID reconciliation, bounded drain, and pruning. Commands validate scope and do not become agent tools.

**Tests first:** signature/body bounds, durable ack, duplicate/early/out-of-order delivery, crash/queue failure, cross-profile suppression, fenced workers, bounded fairness/continuation, tombstones, disabled-provider pruning, and targeted UUID behavior.

**Verification:** focused webhook/queue/scheduler/command tests; production-database/cache concurrency verification documented separately because SQLite cannot prove it; full suite; Pint.

## Phase 7 — AdKit evidence gate and selected named operations

**Purpose:** implement no speculative AdKit API.

1. Maintainer supplies/approves authoritative AdKit operation/version documentation or original transport source plus sanitized fixtures.
2. Create an evidence matrix for selected account/campaign/ad-set/ad hierarchy, reporting semantics, assets, and controlled write operations.
3. Add exact abilities, local ancestry mappings, named REST routes, OpenAPI schemas, transport DTOs, and tests only for selected evidenced operations.
4. Route all Meta advertising effects through AdKit. Keep unsupported operations absent from capabilities/OpenAPI/runtime.
5. Treat paused creation, prerequisite objects, activation, delivery/spend, and cleanup as separate approvals/effects. No spend-capable live test without exact budget/status/cleanup approval.

**Exit condition:** every selected AdKit operation is implemented and tested against reviewed fixtures, or explicitly reported **Blocked** with missing evidence and next action. This phase must not block shipping the approved Zernio read-only phases unless maintainers require one combined release.

## Phase 8 — Documentation, quality gate, and separately approved acceptance

### Repository documentation

Add the approved quickstart/operator walkthrough covering RWYS-only Bearer setup, secret-store handling, one-time plaintext, local/provider identifier differences, assignment/sync, abilities/account restrictions, pagination/freshness/errors, write effects/idempotency/recovery, Postman and one named REST-capable client, trusted HTTPS/network reachability, operator controls, and explicit stop conditions. Use only fictional placeholders.

Document reset commands only as an explicit disposable-local option with a warning that a fresh migration destroys the selected database and its tokens, mappings, conversations, and evidence. Normal seeders must be repeatable and preserve security/Client state.

### Automated quality gate

Run and accurately report:

1. Focused PHPUnit tests after every vertical slice.
2. `php artisan test --compact` for the full backend suite.
3. Frontend/Vitest suite through the repository's configured runner.
4. `npm run check`.
5. `npm run types:check`.
6. `npm run build` (and SSR build if the changed app path requires it).
7. `vendor/bin/pint --dirty --format agent` after PHP changes.
8. Whitespace/diff checks and a secret scan of added fixtures/docs.

Do not report a full pass when only focused checks ran. Separate baseline failures from regressions.

### Manual/local and live acceptance

- Manual local verification uses fictional data and disabled providers first.
- Live reads require separate approval naming environment, Client/profile/platform/account IDs and read bounds; exercise the actual RWYS Bearer path.
- Every live write requires separate approval for exact content/media/schedule, target, budget/spend where relevant, and cleanup owner. Submit one immutable operation only; uncertainty triggers GET-only recovery, never another create.
- Revoke temporary credentials and restore temporary gates after approved testing, while noting that local revocation does not cancel an accepted provider schedule.
- Deployment, deletion/unpublish, ad cleanup, and disconnect each require separate approval.

## Suggested commit/review slices

1. Client/principal/profile/account schema, factories, policies, and domain tests.
2. Agent Access management routes/UI and token lifecycle.
3. API middleware, safe errors/resources, rate/concurrency/audit foundation.
4. Zernio typed read transport and account/post/analytics routes.
5. Operation-read API and OpenAPI/guide contract tests.
6. Durable immediate text publishing.
7. Instagram scheduled JPEG flow.
8. Webhooks/reconciliation/retention/operator commands.
9. Evidenced AdKit slices, one operation family per review.
10. Final documentation, full verification, and approved acceptance evidence.

Each slice should remain deploy-safe with write gates off by default.

## Risk controls and stop conditions

- **Stop** if current provider documentation contradicts the issue assumptions; update the evidence matrix and seek maintainer resolution before coding that operation.
- **Stop** before introducing a new dependency, changing the public contract, weakening an authorization invariant, or replacing durable persistence with process-local state.
- **Stop** if the selected cache/database cannot provide the required atomic admission/lease semantics; choose and document a production-capable store before enabling writes.
- **Stop** before any live provider call unless the exact environment/targets/bounds/effect/cleanup are explicitly approved.
- **Stop** AdKit work when authoritative contracts are absent; report it as blocked rather than inventing schemas.
- Do not merge a phase whose OpenAPI, implemented routes, ability matrix, and tests disagree.

## Acceptance reporting template

For every selected completion row, report one of:

- **Verified** — implemented and proven by named automated/manual/live evidence.
- **Implemented / Not Verified** — code exists, but the required environment/provider/manual proof has not run.
- **Blocked** — exact missing evidence/approval/infrastructure and next action are named.
- **Not Started** — deliberately deferred to a later approved phase.

Initial planning status:

| Area                               | Status      | Evidence / next action                                                              |
| ---------------------------------- | ----------- | ----------------------------------------------------------------------------------- |
| Client/principal/token ownership   | Not Started | Implement Phase 1, then Phase 2 tests.                                              |
| Zernio read REST/OpenAPI           | Not Started | Complete official-contract research, then Phase 3.                                  |
| Management UI                      | Not Started | Build after Client authority model exists.                                          |
| Durable Zernio writes              | Not Started | Requires Phases 1–3 and explicit write-scope approval; gates remain off.            |
| Webhooks/recovery/retention        | Not Started | Implement after operation ledger/write semantics.                                   |
| AdKit capabilities                 | Blocked     | Authoritative AdKit operation/version evidence and sanitized fixtures are missing.  |
| Automated verification             | Blocked     | Dependencies are not installed in this checkout; install locked dependencies first. |
| Live acceptance/deployment/cleanup | Blocked     | Requires separate exact environment/target/effect/budget/cleanup approval.          |
