# Issue #27 — Fix Zernio posts 502 caused by unsupported account lookup

## Goal

Replace every broker use of Zernio's unsupported single-account endpoint with a bounded, paginated lookup through the documented `GET /v1/accounts` collection. The completed change must continue to enforce local client/token/account authorization, verify the exact upstream account ID belongs to the expected profile, retain the stricter active-account requirement for writes, avoid `/v1/accounts/{accountId}` entirely, and return successful Client-scoped read responses for valid assigned Instagram and Facebook accounts.

## Scope

### In

- Refresh `factory/issue-27` from the current `origin/master`, which now contains merged PR #26 and the affected broker implementation.
- Add one reusable account lookup/verification service for broker reads and writes.
- Add a profile-authenticated collection request to `ZernioHttpClient` using paired 1-based `page` and `limit` query parameters.
- Replace all three unsupported call sites in `ZernioBrokerReadService` and `ZernioBrokerWriteService`.
- Add regression coverage for first-page success, later-page success, missing account, account/profile mismatch, inactive write account, and proof that the unsupported URL is never requested.
- Verify the real RWYS read endpoint with authorized credentials when they are available.

### Out

- Publishing or scheduling new live Zernio content during real-provider verification.
- Changes to roles, token abilities, client/profile/account assignment rules, seeders, database schema, OpenAPI route shapes, or response resource shapes.
- TLS verification bypasses or changes to Zernio timeout/retry policy.
- Refactoring profile assignment's existing bounded snapshot import beyond sharing the same documented upstream endpoint contract.

## Phases

### Phase 1 — Bring the issue branch onto the merged broker baseline

**Changes**

- Fetch `origin/master` and merge it into `factory/issue-27` without rewriting history. Confirm commit `3830019` (PR #26 merge) or its descendant is present before editing.
- Re-read `.ai/rules/index.md` and all matching rules if `.ai/rules` exists after the refresh; run the required `.ai/rules` keyword scan before code edits.
- Activate the Laravel and testing skills, then confirm installed package versions before relying on framework APIs.

**Tests / proof**

- Confirm the branch contains `app/Services/ZernioBrokerReadService.php`, `app/Services/ZernioBrokerWriteService.php`, and the broker feature tests.
- Confirm exactly three runtime calls to `ZernioHttpClient::account()` exist before replacement and that the method targets `/v1/accounts/{accountId}`.

**Verification commands**

```bash
git fetch origin master
git merge --no-edit origin/master
git merge-base --is-ancestor 3830019 HEAD
git grep -n --fixed-strings -- '->account(' -- '*.php'
composer show --direct
```

### Phase 2 — Implement one paginated exact-account lookup

**Changes**

- In `app/Zernio/ZernioHttpClient.php`:
  - Add a profile-authenticated method that performs one `GET /v1/accounts` request with `profileId`, `limit`, and 1-based `page` query parameters, using the existing private `get()` path so provider availability, credentials, expiry, HTTPS, timeouts, status decoding, and TLS verification remain unchanged.
  - Remove the unsupported `account()` method after all callers migrate.
- Create a focused service under the existing `app/Services` convention (for example, `ZernioAccountLookupService`) that:
  - Requests collection pages at a fixed page size.
  - Requires a list-shaped `accounts` payload and coherent pagination metadata (`page`, `limit`, `total`, and `pages`).
  - Iterates only through the declared finite page count, rejects inconsistent/invalid pagination, and stops as soon as the exact account is found.
  - Accepts `_id` or `id` for the account identity and scalar or embedded `_id`/`id` forms for `profileId`/`profile`, matching the read broker's existing normalization behavior.
  - Returns only an account whose exact provider ID matches the local `ProviderAccount` and whose upstream profile reference matches the expected local `ProviderProfile`; otherwise throws the existing `ZernioRequestException` provider error after the collection is exhausted or a matching ID has mismatched ownership.
- In `app/Services/ZernioBrokerReadService.php`:
  - Inject the lookup service and use its returned payload for account status and `verifyProfileAndAccount()`.
  - Preserve current local authorization, profile identity verification, account identity/ownership verification, post ownership checks, response mapping, and auditing.
- In `app/Services/ZernioBrokerWriteService.php`:
  - Inject the same lookup service and replace the unsupported lookup in `verifyProfileAndAccount()`.
  - Preserve the existing exact profile/account checks and `isActive === true` write gate.

**Tests / proof**

- Add a focused PHPUnit unit test for the lookup service covering:
  1. exact match on page 1;
  2. exact match on a later page, with requests made in 1-based order;
  3. complete pagination with no matching account;
  4. matching account ID with a different profile owner;
  5. malformed or inconsistent pagination failing closed;
  6. no request to `/v1/accounts/{accountId}`.
- Assert query parameters include both `page` and `limit` on every collection request.

**Verification commands**

```bash
php artisan test --compact tests/Unit/ZernioAccountLookupServiceTest.php
```

### Phase 3 — Regress the Client-scoped read and write broker behavior

**Changes**

- Update `tests/Feature/AgentBrokerReadApiTest.php` so its provider fake returns realistic paginated `/api/v1/accounts?profileId=...&limit=...&page=...` payloads rather than accepting `/api/v1/accounts/{accountId}`.
- Keep the existing account status, post list, post detail, and analytics assertions, and add explicit `Http::assertNotSent()` coverage for the unsupported endpoint.
- Add/adjust feature cases proving a valid account found on a later page succeeds, while missing and profile-mismatched accounts fail with the existing sanitized provider error and do not proceed to posts/analytics calls.
- Update `tests/Feature/AgentWriteApiTest.php` to use the collection response and assert writes still reject an inactive account before any `/v1/posts` mutation. Do not broaden live write verification.

**Tests / proof**

- Existing Client-scoped authorization and assignment tests remain green.
- Valid read requests return 200 and local public IDs only.
- Missing/mismatched provider accounts fail closed and do not call downstream post endpoints.
- Immediate and scheduled write tests retain idempotency and active-account behavior.
- No test fake recognizes or permits `/api/v1/accounts/{accountId}`.

**Verification commands**

```bash
php artisan test --compact tests/Feature/AgentBrokerReadApiTest.php
php artisan test --compact tests/Feature/AgentWriteApiTest.php
php artisan test --compact tests/Feature/AgentProfileAssignmentTest.php
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/ZernioAccountLookupServiceTest.php tests/Feature/AgentBrokerReadApiTest.php tests/Feature/AgentWriteApiTest.php tests/Feature/AgentProfileAssignmentTest.php
git grep -n -E '/v1/accounts/.+|api/v1/accounts/.+' -- app tests
```

### Phase 4 — Verify against the real RWYS provider boundary

**Changes**

- When authorized local Client token and assigned Zernio credentials are available, call the existing RWYS read-only endpoint for one assigned Instagram or Facebook account, preferably the reporter's failing posts route.
- Record only sanitized request/response evidence: RWYS route, HTTP status, account platform, and whether the provider collection lookup and posts request succeeded. Do not print bearer tokens, provider IDs, secrets, or full provider payloads.
- Do not publish, schedule, mutate assignments, alter roles, seed data, or disable certificate verification.

**Tests / proof**

- `GET /api/v1/zernio/accounts/{public_id}/posts?limit=25` returns HTTP 200 for an authorized assigned account.
- Logs/audits show no 405 and no call to `/v1/accounts/{accountId}`.
- If credentials are unavailable, explicitly report that live acceptance remains unverified; mocked coverage alone must not be represented as satisfying this acceptance item.

**Verification commands**

```bash
php artisan route:list --path=api/v1/zernio --except-vendor
# Execute the read-only request with existing authorized credentials through the configured local HTTPS app URL.
```

## Risks

- **Pagination drift or malformed metadata:** An unbounded loop could amplify provider failures. Validate page metadata on every response and iterate only through a coherent declared page count.
- **Payload-shape variation:** Zernio account/profile references may be scalar or embedded objects. Reuse the read broker's current normalization semantics and cover both forms in tests.
- **Security regression through partial matching:** Never accept a collection result by position or profile filter alone; require exact account ID and exact profile ownership.
- **Write-gate regression:** A shared lookup must not weaken the write service's current `isActive === true` check.
- **Stale checkout:** The issue branch started before PR #26 merged. Refresh from current `origin/master` before editing or tests will target code absent from the checkout.
- **False acceptance from mocks:** Automated tests prove request construction and failure behavior, but the issue also requires a real read-only RWYS endpoint check when credentials exist.

## Assumptions

- PR #26 is the required baseline: it merged into `master` as commit `3830019` on October 23, 2026, while the current issue branch was created from older commit `dbe753f`.
- The documented collection endpoint is the source of truth; the single-account endpoint is unsupported and should be removed rather than retained as a fallback.
- One shared lookup service is preferable to duplicating pagination and ownership logic across read and write brokers; `ZernioHttpClient` remains responsible for one HTTP request at a time.
- Lookup may stop after finding the exact account because ownership is validated from that result; absence requires exhausting all coherent declared pages.
- Existing local client/token/account authorization and profile verification remain unchanged and run in addition to upstream collection verification.
- Existing provider-error sanitization remains the API contract for missing, mismatched, or malformed upstream account data.
- Real-provider acceptance is read-only and conditional on credentials already authorized in the environment; no fixture creation or credential modification is permitted.

## Rejected alternatives

- **Call `/v1/accounts/{accountId}` and special-case HTTP 405:** rejected because the endpoint is unsupported and the issue explicitly requires the collection contract.
- **Trust the local `ProviderAccount` mapping without upstream verification:** rejected because it removes the broker's defense against stale or cross-profile mappings.
- **Duplicate pagination loops in read and write services:** rejected because the security-sensitive lookup rules could diverge, especially the write-only active check.
- **Put the entire multi-page loop inside `ZernioHttpClient`:** rejected because existing client methods represent single upstream requests while services own pagination and domain validation.
- **Reuse the profile-assignment snapshot importer directly:** rejected because it is intentionally bounded to a complete 300-account synchronization workflow and returns normalized assignment data, not an on-demand exact broker verification payload.

## Open questions

None. The codebase, merged dependency, issue acceptance criteria, and existing tests provide enough information to execute the fix without a product decision.
