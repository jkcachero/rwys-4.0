# Issue #9 — Functional AI workspace with gated provider tools

## Goal

Replace the intentionally read-only dashboard with an authenticated, verifiable AI workspace that can perform approved AdKit/Zernio reads and selected proposal/approval-gated actions through server-side orchestration. Done means the workspace exposes capability state and conversation requests through Laravel endpoints, provider operations are represented by explicit contracts and fake implementations, every provider mutation is bound to an authorized target and durable proposal/approval/audit lifecycle, automated tests prove happy paths and failure/concurrency safeguards without live calls, and the operator walkthrough separately verifies approved Zernio posting and paused/non-delivering AdKit ad creation. Live provider effects must remain unavailable unless their explicit manual gates are satisfied.

## Scope

### In scope

- Extend the authenticated workspace contract and Inertia/React page beyond the current deferred/disabled state.
- Add server-side AI orchestration for bounded read/analyze requests using the existing AdKit and Zernio read boundaries.
- Add only the selected provider actions required by the issue, using explicit provider contracts, preview/proposal, user consent, durable execution, recovery, and audit evidence.
- Preserve exact Zernio target verification and scope/account/platform/display-name matching.
- Add fake providers and PHPUnit/React tests; prevent accidental live provider/model calls in automated tests.
- Add operator-verification artifacts or test coverage for the approval-gated manual workflow, with live Zernio posting and paused/non-delivering AdKit creation treated as separate gates.

### Explicitly out of scope

- Broad provider parity or arbitrary tool execution.
- Unapproved live credentials, account discovery beyond existing bounded read contracts, or inferring approval from fake tests.
- Enabling AdKit ads to deliver, spend, or publish live by default.
- Replacing the existing Laravel/Inertia/React stack or adding a new frontend framework/dependency.
- Database/schema changes unless the durable proposal/audit/recovery lifecycle cannot be implemented safely with existing persistence; if required, use migrations with protected test-database verification.

## Phases

### Phase 1 — Lock the workspace and orchestration contracts

**Changes**

- Inspect and preserve the current route boundary in `routes/web.php` and the authenticated `WorkspaceController` entry point.
- Replace the controller’s hard-coded workspace payload with a server-derived capability snapshot and explicit correlation/request identifiers.
- Define typed request/response data for conversation messages, source evidence, provider capability states, proposed actions, and operation status under the existing `app/AdKit/Data`, `app/Zernio/Data`, or a narrowly scoped workspace/application boundary.
- Add authenticated endpoints for submitting read/analyze requests and retrieving operation/proposal status. Keep authorization and provider selection on the backend; do not place credentials or database access in React.
- Use the existing provider service and fake-provider patterns rather than introducing a generic provider registry.

**Tests**

- Extend `tests/Feature/WorkspaceTest.php` for authenticated access, capability payloads, correlation IDs, request validation, and guest rejection.
- Add feature coverage proving users cannot select arbitrary provider accounts/scopes or invoke disabled capabilities.
- Add unit/feature coverage for serialization of source evidence and operation states.

**Verification**

```bash
php artisan test --compact tests/Feature/WorkspaceTest.php
php artisan test --compact tests/Unit
npm run types:check
npm run check
```

### Phase 2 — Implement fake-backed read and analysis flow

**Changes**

- Add an application service/action that accepts a validated workspace request, calls the approved read services, and returns bounded, source-attributed results.
- Integrate the AI agent/tool layer only behind the server orchestration boundary. If the installed AI SDK is used, follow its agent/tool and fake APIs; otherwise keep analysis deterministic and provider-service based until the provider contract is explicit.
- Ensure read requests are authenticated, scoped to the current user/workspace, bounded in size, and represented with stable operation/correlation IDs.
- Add loading, empty, success, and failure states to `resources/js/pages/dashboard.tsx`; retain source evidence and provider status in the rendered conversation.
- Update `resources/js/pages/dashboard.test.tsx` to cover the new states and ensure disabled controls remain disabled when capability state is not approved.

**Tests**

- Fake-backed AdKit and Zernio read success tests.
- Missing, ambiguous, out-of-scope, and provider-failure tests.
- Frontend rendering tests for read results, evidence, pending state, and failure state.
- Assert that no live provider/model call occurs in the automated suite.

**Verification**

```bash
php artisan test --compact tests/Feature/WorkspaceTest.php
php artisan test --compact tests/Unit/AdKitReadServiceTest.php tests/Unit/ZernioReadServiceTest.php
npm run types:check
npm run check
```

### Phase 3 — Add selected proposal/approval-gated actions

**Changes**

- Extend `app/AdKit/Contracts/AdKitProvider.php` and `app/Zernio/Contracts/ZernioProvider.php` only for the explicitly selected actions; keep read methods backward-compatible.
- Implement action data objects and fake provider behavior beside the existing provider data/support classes.
- Add a durable operation/proposal model or equivalent persistence only if required by the existing application conventions. Include immutable requested target, provider, scope, payload digest, approval actor/time, execution status, idempotency key, and failure/recovery metadata.
- Add authorization/policy checks and server-side validation before proposal creation.
- Require explicit user consent after preview; atomically claim operations so duplicate submissions cannot execute twice.
- Use Laravel jobs for provider execution, invoked through the repository’s approved job boundary, with retries/recovery and audit events/log records.
- Keep Zernio target verification mandatory immediately before execution and ensure AdKit action semantics explicitly produce a paused/non-delivering result.

**Tests**

- Proposal creation, preview immutability, approval, execution, retry, and terminal failure tests.
- Unauthorized user, mismatched target, stale approval, duplicate/idempotency, concurrent claim, and provider timeout/failure tests.
- Assertions that no action executes before approval and that no action executes against a changed target/payload.
- Fake provider assertions for Zernio posting and paused/non-delivering AdKit creation.

**Verification**

```bash
php artisan test --compact tests/Feature
php artisan test --compact tests/Unit
vendor/bin/pint --dirty --format agent
```

### Phase 4 — Complete the operator workspace UI

**Changes**

- Evolve `resources/js/pages/dashboard.tsx` into the Open WebUI-style workspace requested by the issue using existing shadcn-style UI components under `resources/js/components/ui`.
- Add typed forms for read requests, proposal preview, consent, approval status, execution status, source evidence, and recoverable failures.
- Make capability badges reflect server state (`deferred`, `available`, `approval_required`, `disabled`, `failed`) and never imply live access from frontend state alone.
- Preserve the current authenticated layout and route generation conventions; do not add direct database/provider calls to React.

**Tests**

- Update `resources/js/pages/dashboard.test.tsx` for all meaningful workspace states and action gating.
- Run TypeScript and frontend checks after each UI slice.

**Verification**

```bash
npm run types:check
npm run check
npm run build
```

### Phase 5 — Manual verification and release evidence

**Changes**

- Add a tracked operator walkthrough/runbook artifact only if the repository’s existing planning/evidence convention requires a committed file; otherwise record the checklist in the issue/PR handoff.
- Verify separately with approved credentials/targets:
  1. Zernio posting reaches the approved target only after preview and consent.
  2. AdKit ad creation produces a paused/non-delivering object and cannot spend or deliver.
- Capture correlation IDs, proposal IDs, target identity, provider response, audit evidence, and recovery result.
- Keep missing accounts, schemas, credentials, or approvals explicitly blocked rather than substituting fake success.

**Tests and verification**

```bash
php artisan test --compact
npm run types:check
npm run check
npm run build
```

Manual verification must be labeled separately from automated fake-backed verification and must not be inferred from CI success.

## Risks

- Provider APIs may differ from assumed action shapes; resolve this by extending contracts from confirmed provider schemas and keeping unknown live paths blocked.
- A frontend capability may appear enabled while the backend rejects it; derive all authority from the server and cover this with authorization tests.
- Duplicate submissions could create duplicate posts/ads; enforce idempotency and atomic claims, then test concurrent requests.
- Retry behavior could repeat a provider mutation; persist provider request IDs/results and make retries recovery-aware.
- AdKit “paused/non-delivering” could be interpreted incorrectly; assert the returned delivery/status fields and verify manually before treating it as complete.
- Missing dependencies in the current checkout (`vendor/autoload.php` was absent during triage) can block local test execution; install dependencies in the executor environment without committing generated/vendor files.
- Live verification may remain blocked by credentials or approvals; report the exact blocker and do not weaken safeguards.

## Assumptions

- The target repository is `jkcachero/rwys-4.0`; checkout verification confirmed root `/home/jkcachero/.mastracode/web/sandboxes/44e6b3cf-3790-464b-ab61-b0a4e5d5d4b0/rwys-4.0` and remote `https://github.com/jkcachero/rwys-4.0.git`.
- The current branch is a shallow checkout at merge `1b3f336`, so the inherited triage findings are verified against the available current code and no uncommitted changes exist.
- The current read-only dashboard is intentional baseline behavior, not a regression; implementation should evolve it rather than remove its authentication/verified-user boundary.
- Existing AdKit/Zernio contracts, data objects, read services, fake providers, and exact Zernio verification are the preferred extension points.
- Laravel/Inertia/React and the existing shadcn-style components remain the UI architecture; no dependency or framework migration is needed.
- Automated tests use fake providers and fake AI/model interactions; live calls are never required for CI.
- Server-side authorization, proposal binding, idempotency, auditability, and recovery are mandatory for every provider mutation.
- Database persistence is added only when required for durable operation semantics; if added, migrations and test guards follow repository conventions.
- The UI can expose capability states without exposing provider credentials or user email data.

## Open questions

- Which exact Zernio action variant, approved account/target, and environment will be used for the operator walkthrough?
- What concrete AdKit ad-creation fields and provider response guarantee the required paused/non-delivering state?
- Which model/provider and production credential set are approved for the manual AI analysis and live-provider verification?

These are genuine product/provider approvals and cannot be resolved safely from the current repository.

## Handoff

The plan is ready for execution. The implementation should proceed phase-by-phase, keeping each phase independently testable and preserving the explicit boundary between fake-backed automated verification and live operator verification.
