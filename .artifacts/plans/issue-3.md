# Issue #3 execution plan — Zernio read access, selected actions, lifecycle, and automation

## Goal

Deliver the approved Zernio provider scope in independently gated increments. The first releasable milestone is a read-only, normalized, scoped provider boundary. Only after that milestone is accepted may explicitly selected publishing, media, queue, retry, webhook, lifecycle, inbox, or workflow capabilities be implemented. Every unselected or insufficiently evidenced capability remains disabled and visible as deferred or blocked.

This plan does not authorize deployment, production mutation, live provider calls, broad autonomous permission, credential collection in chat, or unsupported all-platform claims. The user is the approver at each gate.

## Verified baseline

- Repository: `jkcachero/rwys-4.0`; remote: `https://github.com/jkcachero/rwys-4.0.git`.
- Branch: `factory/issue-3`; planning baseline is clean at merge commit `92bdae3`.
- Current implementation contains only the AdKit read scaffold: `AdKitProvider`, immutable `AdKitAccount`, `AdKitReadService`, and `FakeAdKitProvider`.
- `WorkspaceController` exposes provider reads as deferred and provider actions as disabled. No Zernio transport, action registration, webhook route, or live provider call exists.
- Existing tests establish the intended pattern: scope-keyed fake providers, bounded reads, PHPUnit coverage, authenticated/verified workspace access, and omission of sensitive user email from Inertia props.
- Focused tests cannot currently execute because `vendor/autoload.php` is absent from the checkout. This is an environment blocker, not evidence of an application failure.

## Ownership and boundaries

- **Shared UI/AI foundation:** current actor/effective-user/Client context, generic proposal and exact-intent confirmation, expiry/cancellation/replay, durable action ledger, orchestration, and generic status display.
- **Zernio provider module:** Client/profile/account authorization and mapping, provider/platform capability schemas, normalization, transport, preflight, dispatch, idempotency, reconciliation, provider-specific audit/webhook behavior, and typed fixtures.
- **Backend boundary:** authorization, database access, provider calls, secrets, audit, redaction, and mutations remain server-side. UI/AI consumes accepted typed contracts; it does not connect to Zernio directly.

Do not create a second ownership model or duplicate shared proposal/ledger infrastructure inside Zernio. Stop if a required shared dependency is absent or conflicts with its approved contract.

## Phased implementation plan

### Phase 0 — Gate 001: evidence, capability matrix, and design approval

**Scope**

1. Inventory current code, schema, config, installed packages, tests, and historical Zernio material. Confirm the provider SDK/transport decision with `composer show --direct`; do not install dependencies yet.
2. Build an operation- and platform-specific matrix for accounts, health, posts, analytics, media, drafts, scheduling, queue, lifecycle, inbox, webhooks, and workflows. Record official evidence, normalized shapes, freshness/provenance, permissions, entitlements, limits, error categories, and numerical request/retry/cache/query budgets.
3. Decide reuse/port/build for authorization, mapping, transport, cache, audit, webhook, and shared action boundaries. Reject blind copying of historical aliases, synchronous callbacks, or unverified schemas.
4. Approve exact data ownership, constraints/indexes, package or typed-HTTP choice, base host/path composition, secrets/feature flags, and any optional HTTP/MCP consumer before implementation.

**Exit evidence**

- Approved read contract and platform matrix.
- Explicit selected/deferred/blocked dispositions for action rows.
- Approved schema/dependency decisions, or recorded blockers.
- No application, schema, package, environment, or provider mutation.

### Phase 1 — Gates 002–003: authorization mapping and normalized read transport

**Scope**

1. Write failing PHPUnit tests for Admin scope, Client membership, effective-Client impersonation, guessed IDs, cross-Client/profile targets, revoked membership, stale mappings, replacement connection IDs, and lifecycle eligibility.
2. Implement the approved Client → profile → account mapping using project conventions, with server-derived scope and scope-bearing cache keys. Assert denied work never reaches transport.
3. Implement fake-backed normalized reads for selected accounts/health, posts/details/status, and analytics/followers. Keep provider reads strictly non-mutating.
4. Cover empty/malformed/unknown fields, disconnected/expired states, missing credentials, timeout, rate limit, pagination, unsupported metrics, delayed/failed analytics, freshness/provenance, redaction, and approved request/retry/cache budgets.

**Exit evidence**

- Authorization and mapping tests pass.
- Read normalization and failure tests pass without live network access.
- No create, upload, reconnect, synchronization write, or hidden mutation occurs in a read path.
- Scope, cache, audit, and budget behavior is measured or explicitly blocked.

### Phase 2 — Gates 004–006: typed read tools, audit/redaction, and READ acceptance

**Scope**

1. Add only approved typed account/post/status/analytics read entry points with validated identifiers, date ranges, pagination, bounded output, and redacted schemas.
2. Reuse the single authorized service path. Reject client-supplied identity overrides, arbitrary URLs/queries, unknown capabilities, revoked access, and prompt-injected provider content.
3. Add audit correlation, safe output limits, retention/redaction behavior, and explicit unavailable/deferred states. Add HTTP/MCP adapters only if Gate 001 approved an actual consumer.
4. Verify the shared UI/AI handoff contract and stable fake fixtures. Keep action capabilities unregistered.

**Exit evidence**

- READ acceptance criteria pass: typed normalized data, provenance/freshness, authorization isolation, fake-backed tests, redaction/audit, and no remote writes.
- Workspace still visibly distinguishes available reads from deferred/blocked actions.
- User accepts the READ milestone before any action phase starts.

### Phase 3 — Gate 007: selected action scope and shared confirmation handoff

**Scope**

1. Record the exact selected action rows, target platforms/accounts, permissions, risk tiers, approval expiry, retry and idempotency rules, live-verification limits, and deferred/blocked rows.
2. Confirm the shared immutable proposal → exact confirmation → action-ledger handoff. The server derives actor, effective user, Client, provider profile, target, and capability; model/provider content is never authority.
3. Define typed preview, preflight, dispatch, status, partial-outcome, uncertain-outcome, reconciliation, and error contracts for each selected action.

**Exit evidence**

- Every selected row has a testable contract and exact-target intent.
- Every unselected row remains unreachable and visibly classified.
- No action is registered based solely on fake tests or broad provider API availability.

### Phase 4 — Gates 008–011: selected media, drafts/publishing, queue, retry, and reconciliation

**Scope**

1. Implement only selected media validation/upload or approved external-media flows, including per-platform preflight and safe URL handling.
2. Implement selected draft/edit/promotion and immediate/scheduled publishing with exact target, platform options, stale-version checks, timezone handling, and no implicit publish from draft operations.
3. Implement selected queue discovery/mutation only where approved, distinguishing next-slot preview from dispatch.
4. Implement retry/recovery for eligible failed targets and ambiguous dispatch using idempotency keys, duplicate prevention, partial outcomes, bounded retries, and reconciliation. Never infer success from a timeout.
5. Keep all transport fake-backed by default; live proof, if separately approved, must record exact target and actual effect.

**Exit evidence**

- Selected operations pass success, denial, validation, unsupported/entitlement, lifecycle, timeout, throttling, malformed-response, audit, and budget tests as applicable.
- No unconfirmed media, draft, publish, schedule, queue, or retry effect can dispatch.
- Uncertain and partial states are durable, visible, and recoverable within documented provider guarantees.

### Phase 5 — Gate 012: signed durable webhooks

**Scope**

1. Implement only approved webhook event families with constant-time signature verification, fail-closed missing/invalid secret handling, durable receipt before acknowledgment, scoped event mapping, and safe redaction.
2. Deduplicate by provider event identity while preserving distinct same-account events; handle unknown events, wrong-profile events, out-of-order delivery, late events, worker crash/replay, persistence failure, and bounded acknowledgment latency.
3. Reconcile provider state without regressing newer local state; quarantine unknown mappings and retain correlation/audit metadata without unrestricted private payloads.

**Exit evidence**

- Fake deliveries pass signature, durability, replay, ordering, latency, redaction, and tenant-isolation tests.
- No live webhook registration occurs.

### Phase 6 — Gates 013–015: selected lifecycle, inbox, and workflow capabilities

**Scope**

1. For selected connect/reconnect/disconnect/delete/cancel/queue-removal actions, enforce consent, single-use callback state, callback replay protection, cross-Client isolation, exact current lifecycle state, race handling, changed connection IDs, and honest irreversible-effect warnings. Do not claim deleting a Zernio post unpublishes platform copies or guarantees in-flight cancellation.
2. For selected comments/messages/replies/moderation, enforce evidenced permission, account scope, entitlement, privacy/reply windows, feature flags, exact recipient/thread/content confirmation, redaction, duplicate webhook handling, and bounded reconciliation. If no inbox rows are selected, keep them disabled.
3. For selected workflows, keep definition/edit/enable/disable/manual-run distinct from ongoing future effects. Recheck authorization/entitlement before every run; enforce limits, expiry, attribution, duplicate/replay protection, concurrency handling, budget exhaustion, revocation, disable-in-flight limits, and an emergency stop. If none are selected, prove disabled behavior.

**Exit evidence**

- Selected lifecycle, inbox, and workflow tests pass; unselected operations remain unreachable.
- Destructive, communication, and ongoing effects require separate exact authorization.
- No credentials are collected by AI or hidden autonomous publishing/reply permission is introduced.

### Phase 7 — Gates 016–017: regression and final provider acceptance

**Scope**

1. Run focused provider tests and applicable backend regressions. Verify shared proposal/confirmation/ledger compatibility, stable read tools, scoped caches/audit/webhooks, query/request/latency budgets, denial before transport, restart recovery, and AdKit compatibility without live services.
2. Re-run the outcome matrix for every selected row using isolated persistence and provider/storage/model fakes. Separate fixture, browser, and any explicitly approved live evidence.
3. Classify every capability/platform row as selected-and-accepted, deferred with reason, or blocked with missing evidence/dependency. Deliver typed capability/preview/status/error contracts and fixtures to UI/AI workstream 019–025.

**Required verification commands**

```bash
composer install --no-interaction   # only when dependency installation is approved/available
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run check
npm run types:check
npx vitest run
npm run build
```

Run the narrowest applicable commands after each phase; do not treat missing `vendor/autoload.php` as a test result. Review `git status` after builds and do not retain unrelated generated changes.

**Exit evidence**

- Selected provider regression passes, or failures are explicit blockers.
- Final scope is accepted only with transparent deferred/blocked rows and remaining live/platform gaps.
- No deployment, production mutation, live webhook registration, or claim of UI/AI completion is included.

## Risks and mitigations

- **Missing shared identity/ledger contracts:** stop at Gate 001/007; do not recreate them inside Zernio.
- **Unverified provider/API evidence or package mismatch:** use typed fakes and unavailable states; verify installed versions before relying on APIs.
- **Cross-Client leakage:** derive context server-side, include scope in every mapping/cache/audit key, and test guessed IDs and revocation.
- **Uncertain remote effects:** use idempotency, durable pending/unknown states, and reconciliation; never convert timeout into success.
- **Webhook replay/order/persistence failures:** acknowledge only after durable acceptance, deduplicate safely, and quarantine unknown mappings.
- **Destructive or ongoing effects:** require separate exact confirmation, expiry, kill switch, and honest provider-limit warnings.
- **Environment limitations:** vendor dependencies and live credentials are absent from the current checkout; report them rather than weakening tests or committing secrets.

## Assumptions

- Issue #3 is an approval-gated roadmap; transition to execution authorizes following this plan, not skipping gates.
- The existing AdKit scaffold is the reusable local pattern, not a reason to copy provider-specific semantics.
- Laravel/Inertia conventions and installed package versions are authoritative; no dependency or schema change is assumed approved.
- Reddit remains unavailable and Snapchat remains deferred/beta unless new official evidence and explicit approval change those dispositions; platform support is operation-specific.
- Fake-backed tests prove contracts only. They never authorize live provider writes, webhooks, deployment, or production data changes.

## Rejected alternatives

- Implementing all Zernio endpoints up front: rejected because capability selection and evidence are gate-controlled.
- Calling Zernio directly from React or an AI model: rejected because it bypasses authorization, audit, redaction, and exact confirmation.
- Treating account enumeration as post-target or analytics/inbox permission: rejected because platform permissions and operation entitlements differ.
- Copying historical Zernio code blindly: rejected because API versions, callback behavior, schemas, and security assumptions require current verification.
- Treating fake tests as live acceptance: rejected because fakes establish contract behavior, not provider availability or real-world effects.
- Enabling autonomous workflows from one-time confirmation: rejected because ongoing effects require separate authorization, expiry, limits, attribution, and kill switch.

## Open questions requiring approval

1. Which exact READ capabilities and platform operation rows are selected for Gate 001–006?
2. Which Client/profile/account ownership, retention, audit, budget, and live-verification contracts are approved?
3. Which transport choice, package/version, schema changes, secrets, feature flags, and optional HTTP/MCP consumer are authorized?
4. Which publishing/lifecycle/inbox/workflow rows are selected at Gate 007, with what target allowlists, risk tiers, expiry, retry, and reconciliation guarantees?
5. Which live checks, if any, are approved after fake/browser evidence, and what exact environment and side-effect limits apply?

## Definition of done

- The approved READ milestone is implemented and accepted before any action is enabled.
- Every selected action has authorization, exact intent, preflight, idempotency, reconciliation, audit, redaction, bounded failures, and automated coverage.
- Every unselected or insufficiently evidenced capability remains disabled and classified.
- Scope isolation and revocation are proven for actor, effective user, Client, profile, account, cache, action ledger, and webhook paths.
- Verification commands and results are reported with fixture/browser/live evidence separated.
- No deployment, production mutation, unsupported all-platform claim, or implicit autonomous permission is included.

## Planning verification

- Artifact: `.artifacts/plans/issue-3.md`
- Repository verified: `jkcachero/rwys-4.0`
- Branch verified: `factory/issue-3`
- Baseline verified: clean working tree at `92bdae3`
- Planning pass changed only this plan artifact; no application, schema, package, credential, or environment mutation was performed.
- Execution must re-verify repository identity, read applicable project rules, confirm current dependencies, and stop at each user approval gate.

## Initial execution sequence

1. Re-verify repository identity and read the approved Gate 001 decisions.
2. Implement the smallest approved gate with failing tests first; stop and report evidence/blockers.
3. Advance only after the user accepts the current gate and its selected scope.
4. Keep provider actions unregistered until Gate 007 and keep unselected capabilities disabled through final acceptance.
5. Run focused checks after each phase and the full selected-scope checks before handoff.
6. Report exact changed files, commands/results, fake/browser/live evidence, exclusions, and unresolved blockers.

This is an executable staged handoff, not blanket authorization for provider effects.