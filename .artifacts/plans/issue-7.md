# Issue #7 Plan — Zernio live verification for John Karlo Cachero

## Goal

Deliver a safe, verifiable read-only Zernio account verification path for the exact maintainer-approved account and environment identified in issue #7. Done means the system cannot select an account by display name or default credentials alone; an approved target is bound to an isolated verification scope; the real provider path can fetch bounded account data; responses are normalized to the existing `ZernioAccount` contract; requests and outcomes are auditable without secrets; and automated tests prove target isolation, authorization boundaries, provider failures, bounded reads, and ambiguous-result handling. No write or side-effect operation is included until a separate explicit approval defines one exact operation and cleanup policy.

## Scope

### In scope

- Add an explicit verification-target configuration/value object containing the approved Zernio profile ID, connected platform/account ID, RWYS Client scope, environment, and display-name assertion.
- Add a real Zernio provider adapter behind `App\Zernio\Contracts\ZernioProvider`, preserving `ZernioReadService` as the bounded application-facing read service.
- Add authenticated, read-only application entrypoint(s) following the repository’s existing route/controller conventions; reject missing, mismatched, or unapproved target identifiers before any provider request.
- Add structured outbound request/result audit records with redaction and correlation IDs, using the project’s established outbound HTTP logging conventions if applicable.
- Normalize successful provider responses into `ZernioAccount` and represent provider errors, authorization failures, timeouts, malformed responses, and ambiguous outcomes without blind retries.
- Add PHPUnit coverage for happy paths, failure paths, target isolation, limit bounds, response normalization, and audit redaction.
- Document the exact operator verification sequence in the issue handoff/implementation notes only if documentation is explicitly part of the selected implementation surface; do not add unrelated product documentation.

### Explicitly out of scope

- Publishing, uploading, scheduling, drafting, deleting, or any other write/side-effect operation.
- Broad Zernio feature coverage, engagement automation, webhooks, AdKit behavior, deployment changes, credential rotation, or unrelated providers.
- Database migrations unless the existing audit boundary demonstrably requires durable persistence; prefer the project’s existing logging/audit mechanism first.
- Live testing against any account until exact identifiers, environment, and read-operation approval are supplied.

## Phases

### Phase 0 — Approval and target binding

**Changes**

- Confirm the exact approved values before implementation: Zernio profile ID, connected platform/account ID, RWYS Client scope, isolated environment, allowed read operation(s), and evidence destination.
- Add a target value object/configuration under `app/Zernio` with strict equality checks for profile, platform/account, scope, environment, and expected display name.
- Make the verification entrypoint require this target; never infer it from a display name, default account, or ambient credentials.

**Tests**

- Target matching succeeds only when every approved identifier matches.
- Display-name-only and partial-identifier requests are rejected.
- A target from one Client scope cannot be used in another scope.
- Unapproved environment or operation is rejected before the provider is called.

**Verification commands**

```bash
php artisan test --compact tests/Unit/ZernioTargetTest.php
```

### Phase 1 — Real provider read boundary

**Changes**

- Implement the real provider adapter behind `ZernioProvider`, using the project’s configured HTTP/client conventions and dependency versions.
- Keep provider-specific request/response parsing inside the adapter; return `list<ZernioAccount>` to the existing service.
- Preserve `ZernioReadService`’s 1–100 limit and scope behavior, extending it only where the approved target must be enforced.
- Add explicit handling for non-success HTTP responses, timeouts, malformed payloads, and missing account identifiers/status fields.
- Do not add automatic retries for ambiguous provider outcomes.

**Tests**

- Fake HTTP/provider responses normalize to `ZernioAccount`.
- Provider errors and malformed payloads produce the project-standard failure result/exception.
- Limits remain bounded and scope isolation remains intact.
- No provider request is made when target authorization fails.

**Verification commands**

```bash
php artisan test --compact tests/Unit/ZernioReadServiceTest.php tests/Unit/ZernioProviderTest.php
vendor/bin/pint --dirty --format agent
```

### Phase 2 — Authenticated read-only verification entrypoint

**Changes**

- Add the smallest authenticated route/controller or command matching existing repository conventions.
- Validate the request at the boundary and pass only the approved target plus bounded read parameters into the service.
- Return a stable, redacted response containing account ID, name, platform, status, fetched timestamp, and correlation ID; never return credentials or raw provider payloads.
- Ensure authorization is evaluated before external I/O.

**Tests**

- Authorized read-only requests return normalized account data.
- Unauthenticated, wrong-scope, wrong-account, wrong-environment, and unapproved-operation requests are rejected.
- Response shape excludes credentials and provider secrets.
- Limit validation and provider failure mapping are covered at the HTTP/command boundary.

**Verification commands**

```bash
php artisan test --compact tests/Feature/ZernioVerificationTest.php
php artisan route:list --except-vendor | grep -i zernio
```

### Phase 3 — Audit, reconciliation, and operator verification

**Changes**

- Add correlation IDs and structured audit events for attempted, authorized, completed, rejected, failed, and ambiguous reads.
- Redact tokens, authorization headers, raw credentials, and sensitive provider payload fields.
- Record the target identifiers, environment, operation, result classification, timestamps, and provider request identity needed to reconcile outcomes.
- Add a no-blind-retry rule in the service/entrypoint for ambiguous timeouts or unknown provider outcomes.
- Execute the bounded live read only after Phase 0 approval, then reconcile the provider response with the audit event and report verified versus deferred scope.

**Tests**

- Audit events are emitted for success, rejection, provider failure, and ambiguous outcome.
- Sensitive values are absent from audit payloads and application responses.
- Reconciliation preserves a single operation identity and does not issue a duplicate request after an ambiguous result.

**Verification commands**

```bash
php artisan test --compact tests/Unit/ZernioAuditTest.php tests/Feature/ZernioVerificationTest.php
php artisan test --compact tests/Unit/ZernioReadServiceTest.php tests/Unit/ZernioProviderTest.php tests/Unit/ZernioAuditTest.php tests/Feature/ZernioVerificationTest.php
vendor/bin/pint --dirty --format agent
```

## Risks

- **Wrong-account verification:** prevent by requiring exact profile, connected account, Client scope, and environment identifiers; test rejection before I/O.
- **Credential leakage:** prevent by centralizing redaction and testing serialized responses/logs for secret absence.
- **Provider contract drift:** isolate parsing in the adapter and use fixture-based contract tests for expected payloads and malformed responses.
- **Ambiguous network outcomes:** avoid blind retries; retain correlation/request identity and require reconciliation.
- **Over-expanding into writes:** keep the provider interface read-only for this issue and require a separate approved work item for side effects.
- **Missing local dependencies:** install dependencies only through the repository’s normal setup process; do not interpret the current missing `vendor/autoload.php` as a product failure.

## Assumptions

- The current checkout is the target repository `jkcachero/rwys-4.0`, with remote `https://github.com/jkcachero/rwys-4.0.git` and branch `factory/issue-7`.
- The inherited triage remains accurate: the current Zernio surface is the minimal fake-backed boundary introduced by merge `401a307`; no real provider, live route, or side-effect path exists.
- The existing `ZernioProvider` contract and `ZernioReadService` are the compatibility seam; extend them minimally rather than replacing them with a new integration architecture.
- A real provider adapter is preferable to putting HTTP calls in controllers because it preserves the existing contract and keeps provider-specific parsing isolated.
- An authenticated read-only route/controller is preferable to a one-off script because the issue concerns the real authorized backend path and the application already has HTTP boundaries.
- Audit persistence should reuse the repository’s established outbound HTTP/audit mechanism if one exists; introduce a migration only if no adequate existing mechanism is available and the implementation requires durable reconciliation.
- Exact target identifiers and operation approvals are prerequisites, not values that can be safely inferred during implementation.
- The current unit tests are pure PHPUnit tests; new isolated logic should follow that convention, while authenticated application behavior should use feature tests.

### Rejected alternatives

- **Display-name/default-account lookup:** rejected because it cannot prove the exact John Karlo Cachero account and violates the issue’s safety boundary.
- **Direct provider calls from a controller or test script:** rejected because it bypasses the existing provider contract, weakens testability, and risks credential/audit leakage.
- **Implementing writes alongside reads:** rejected because the issue explicitly separates read approval from side-effect approval.
- **Blind retries for timeouts:** rejected because an accepted provider operation may have completed despite an unknown client result.
- **Building a broad Zernio SDK layer now:** rejected as unrelated scope; the issue requires a bounded verification path first.

## Open questions

- Which exact Zernio profile ID, connected platform/account ID, RWYS Client scope, and isolated environment are approved for the verification run?
- Which specific read operation(s) and evidence destination are approved?
- Does the repository already designate a durable audit store for outbound provider calls, or is structured application logging sufficient for this verification?
- If a later side effect is desired, what single operation, payload, visibility, schedule, and cleanup authorization apply?

## Completion criteria

- The approved target and environment are explicit and enforced before provider I/O.
- The real provider read path is behind the existing `ZernioProvider` boundary and returns normalized `ZernioAccount` data.
- Authenticated read-only behavior, failures, redaction, audit correlation, and ambiguous outcomes are covered by automated tests.
- No write or side-effect capability is introduced.
- The narrow test set and Pint pass, and the final live verification report distinguishes verified scope from deferred scope.

## Verification baseline

The initial repository checkout was verified as `jkcachero/rwys-4.0` with remote `https://github.com/jkcachero/rwys-4.0.git`. At planning time `vendor/autoload.php` is absent, so PHPUnit cannot run until normal Composer dependencies are installed.

<!-- mastra-factory-plan -->
Status: Ready for execution after the governed transition to execute.
