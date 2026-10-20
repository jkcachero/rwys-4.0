# Issue #1 execution plan — authenticated AI workspace with scoped provider capabilities

## Goal

Replace the placeholder authenticated dashboard with a staged, verifiable AI workspace in the existing Laravel/Inertia React application. The first releasable milestone is a read/analyze workspace only: authenticated users can reach a typed, responsive workspace, inspect approved provider-backed read results with freshness/source/error states, and keep conversations/history isolated by authorized identity and current scope. Provider mutations, autonomous actions, live writes, and unapproved capabilities remain unavailable until their separate approval gates and companion issue evidence are complete. “Done” means every implemented boundary has automated authorization, redaction, failure, audit-correlation, and fake-backed coverage, and the selected local scope passes the required Laravel, TypeScript, frontend check, Vitest, and production-build commands.

## Scope

### In scope

- Existing Laravel 13.37 / PHP 8.5 application shell, authenticated `auth` + `verified` dashboard route, Inertia React 3 page loading, and current React 19/Tailwind/Radix conventions.
- A server-owned AI workspace boundary under `app/`, `routes/`, and tests, with no database or provider access from React.
- Shared identity/session/CSRF/authorization decisions needed by the approved read milestone, including current user, client/team scope if approved, revoked membership, guessed identifiers, and impersonation boundaries.
- Conversation lifecycle and history behavior only after storage/retention/model/context contracts are approved; bounded Laravel AI orchestration and a provider capability registry using fake adapters for tests.
- A typed React workspace composed from existing `resources/js/components/ui` primitives and feature-local components. It must represent loading, empty, source/evidence, freshness, provider errors, terminal states, retry/stop behavior, and disabled/deferred capabilities without implying executable actions.
- Correlated audit records with explicit redaction, access, retention, and failure behavior; operation visibility is read-only and never an implicit retry authorization.
- Automated feature/unit/frontend coverage and the exact verification commands listed below.

### Explicitly out of scope

- Installing Open WebUI, replacing the Laravel/Inertia app with Next.js, or introducing a second frontend architecture.
- AdKit or Zernio provider-specific implementation owned by issues #2 and #3 beyond consuming their separately approved capability contracts and fake adapters.
- Provider writes, publishing, connection mutations, webhooks, autonomous runs, background dispatch, or any live provider call.
- Production data, credentials, deployments, live webhook registration, or commits made as part of this issue.
- New dependencies, migrations, tables, retention policies, model/provider budgets, impersonation semantics, or action contracts before their specific approval gates.
- Enabling action controls before READ/ANALYZE acceptance and subsequent action gates.

## Confirmed baseline

- Checkout is `jkcachero/rwys-4.0` on `factory/issue-1`, remote `https://github.com/jkcachero/rwys-4.0.git`, baseline commit `ffaf701`; source tree is clean apart from the requested `.artifacts/` plan directory.
- `routes/web.php` exposes public `/` and authenticated, verified `/dashboard`; `resources/js/pages/dashboard.tsx` is still the placeholder three-card/large-panel page.
- `resources/js/app.tsx` applies `AppLayout` to ordinary pages, and the existing sidebar layout/breadcrumb convention should be retained.
- Existing UI primitives include Radix-based `Button` and `Card`; `package.json` already provides React 19, Inertia React 3, Tailwind 4, Vite Plus, TypeScript, Radix, and Lucide. It does not provide AI Elements or a provider SDK.
- Direct PHP dependencies include Laravel Framework 13.37, Laravel AI 1.3, Fortify 1.41, Sanctum 4.3, and MCP 1.0.2. No provider implementation or active AI route was found in the baseline.
- Baseline verification required local `.env`/app-key setup and a frontend build for the Vite manifest. After that setup, the existing Laravel tests passed; TypeScript passed; `npm run check` reported a pre-existing formatting issue in `AGENTS.md`. The build regenerated Wayfinder files, which were restored so no unrelated generated source changes remain.

## Phases

### Phase 0 — Decisions and executable baseline (gates 001–002)

**Changes**

1. Reconfirm repository identity, current routes, installed package versions, auth/session configuration, database schema, and existing React/layout primitives.
2. Record the approved identity model: user/client/main-account relationship, current-team semantics if used, admin/impersonation rules, revocation behavior, session/CSRF transport, and authorization policy boundary.
3. Record approved storage, audit, retention, model, query/provider, and execution budgets. Do not invent missing contracts in code.
4. Decide whether existing Laravel AI/MCP APIs are sufficient. Do not add AI Elements, provider SDKs, migrations, or dependencies without explicit approval.

**Proof**

- A decision record links each implemented contract to an approval and identifies deferred/blocked capabilities.
- A guest, expired-session, wrong-scope, revoked-membership, guessed-ID, and impersonation test matrix is written before implementation.

**Commands**

```bash
composer show --direct
php artisan route:list --except-vendor
php artisan config:show database.default
php artisan test --compact
npm run types:check
npm run check
```

### Phase 1 — Authenticated transport and audit foundation (gates 003–004)

**Changes**

1. Add the smallest server-owned authenticated workspace endpoint/page contract following existing route/controller/request conventions. Preserve the existing Fortify/web login and Inertia navigation.
2. Implement validation and authorization at the Laravel boundary; React receives only serialized, redacted props/results. Ensure CSRF/session handling uses the application’s existing web middleware and does not create a parallel token flow.
3. Add a correlation identifier propagated through request, provider-read attempt, response, and audit entry. Define redaction, access, retention, and audit-failure behavior before recording private content.
4. Keep provider adapters behind interfaces and return explicit unavailable/deferred states when no approved provider capability exists.

**Tests**

- Feature tests for authenticated success, guest/expired session denial, CSRF failure, wrong user/client scope, revoked membership, guessed IDs, impersonation isolation, and redaction.
- Unit tests for correlation generation/propagation, audit serialization, redaction, and audit failure terminal behavior.
- Tests assert no provider adapter is called on unauthorized requests.

**Commands**

```bash
php artisan test --compact tests/Feature
vendor/bin/pint --dirty --format agent
npm run types:check
```

### Phase 2 — Read/analyze capability registry and conversation boundary (gates 005–008)

**Changes**

1. Approve a finite first-milestone capability list and target disposition for AdKit/Zernio; register only capabilities with companion-issue source/version evidence and explicit budgets.
2. Implement backend adapter contracts and fake adapters. Each result carries source/provider version, freshness/support/completeness metadata, safe evidence references, query/provider budget usage, and terminal error classification. No live provider calls are made by tests.
3. Implement authorized conversation creation/listing/message/history behavior only for the approved storage and retention model. Enforce current identity/client scope on every read and mutation; never accept a client-provided authorization identity.
4. Add bounded Laravel AI orchestration and validated/redacted response or stream transport. Stop/retry must be explicit and safe; retry cannot silently re-run a provider mutation because none is registered.

**Tests**

- Independent fake-backed capability tests for successful reads, unsupported capability, stale/partial results, provider timeout/rate limit, malformed provider data, budget exhaustion, and audit correlation.
- Conversation/history isolation tests across users, clients, revoked memberships, guessed IDs, deleted/expired context, and malformed input.
- Tests prove provider calls stay within the declared budget and no fabricated zero metrics or causal claims are emitted.

**Commands**

```bash
php artisan test --compact tests/Feature tests/Unit
vendor/bin/pint --dirty --format agent
npm run types:check
```

### Phase 3 — Responsive read workspace UI (gates 009–015)

**Changes**

1. Replace `resources/js/pages/dashboard.tsx` placeholder content with an authenticated workspace page while retaining `AppLayout` and breadcrumbs.
2. Compose feature-local React components from existing `Card`, `Button`, and other primitives. Add typed props for conversation state, messages, source/evidence rows, freshness, provider status, loading/empty/error/terminal states, and deferred capabilities.
3. Add safe context switching, stop/retry affordances, keyboard/focus behavior, responsive mobile layout, and explicit disabled/deferred labels. Do not render unapproved provider actions as tools or buttons.
4. Keep API/business logic outside presentational components; use the approved Inertia/server transport convention. Do not add a new state-management or UI dependency unless a gate explicitly approves it.

**Tests**

- Frontend tests for initial loading/empty state, message rendering, source/evidence visibility, freshness/error states, disabled action rows, retry/stop transitions, scope switching, and accessible names/focus behavior.
- Laravel feature tests for the props/transport shape and authorization behind each UI state.
- Browser walkthrough against authorized fixtures, recording desktop/mobile/keyboard evidence separately from fake-backed tests.

**Commands**

```bash
npm run check
npm run types:check
npx vitest run
npm run build
php artisan test --compact
```

Exit condition: the READ/ANALYZE milestone is accepted only after all gates 001–015 have evidence. Actions remain disabled.

### Phase 4 — Action proposal and durable execution contracts (gates 016–018)

**Changes**

1. Obtain approval for shared action permission/risk/confirmation/expiry/ledger/recovery contracts before adding write behavior.
2. Add immutable action proposals containing exact target, payload digest, actor, authorization snapshot, expiry, required confirmation text, and audit correlation. Store no live provider secret in the browser.
3. Add durable claims/idempotency, worker-time reauthorization, child-operation outcomes, timeout/crash/restart/unknown-outcome classifications, revocation handling, and an unregistered provider dispatch boundary.

**Tests**

- Tampered payload, stale approval, duplicate click/job, revocation, timeout-after-acceptance, crash/restart, audit outage, webhook replay/reordering, and partial/unknown/recovery tests.
- Assert zero live provider dispatches; fake dispatch counts must be exact.

**Commands**

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

### Phase 5 — Selected provider actions and completion evidence (gates 019–025)

**Changes**

1. Import only individually approved AdKit/Zernio actions and connection flows from issues #2/#3. Keep deferred/blocked capabilities unregistered.
2. Add exact target/payload confirmation UI, result/status/recovery visibility, and authorization checks for each selected action.
3. Complete the operator walkthrough for success, partial, unknown, recovery, audit, media/timezone, reload, and post-dispatch revocation journeys.
4. Reconcile every capability as verified, deferred, or blocked; do not claim the master issue complete while a selected blocked capability remains unresolved.

**Tests and commands**

Run the complete matrix and all required checks:

```bash
php artisan test --compact
npm run check
npm run types:check
npx vitest run
npm run build
vendor/bin/pint --dirty --format agent
```

No live provider operation, deployment, webhook registration, or production-data mutation is part of this plan.

## Risks

- Missing identity, membership, storage, retention, or budget decisions can create a security boundary that tests cannot repair. Stop at the relevant gate.
- Provider evidence may not match installed Laravel AI APIs or may be absent from this checkout. Use fake adapters and explicit unavailable states; do not fabricate capability support.
- Generated Wayfinder output can change during builds. Review `git status` after every build and restore unrelated generated changes.
- Inertia prop growth can make every navigation expensive. Keep workspace props typed and bounded; use deferred/partial loading only after verifying the installed Inertia 3 behavior.
- Audit failures, revoked access, and uncertain provider outcomes are security-sensitive terminal states. Test them before exposing retry or action controls.
- The baseline `npm run check` currently reports formatting in `AGENTS.md`; do not rewrite repository instructions as part of feature work. Report it separately unless the owner requests cleanup.

## Assumptions

- The target is the existing Laravel/Inertia React application in `jkcachero/rwys-4.0`; Open WebUI is an interaction reference, not a dependency or replacement application.
- `routes/web.php` and the existing authenticated `AppLayout` remain the navigation boundary; no separate SPA shell or API-only authentication path will be introduced.
- Laravel AI 1.3 is the first orchestration surface to evaluate because it is already installed; Laravel MCP remains for explicitly approved MCP capabilities and is not assumed to be a provider transport.
- Provider-specific ownership stays with issues #2 and #3. This issue consumes their accepted contracts and evidence rather than reimplementing them.
- Existing Radix/Tailwind UI primitives are preferred over adding AI Elements or another component package because the current package manifest has no such dependency and the repository already has a working component layer.
- Backend-owned validation, authorization, database access, provider calls, audit writes, and redaction are mandatory; React is presentational and transport-driven.
- All tests use local factories/fakes and cannot authorize live effects. Any dependency, migration, provider credential, live operation, or deployment requires separate approval.
- The plan’s phases are independently verifiable and may stop at any unchecked approval gate; a transition to execute authorizes following the plan, not skipping those gates.

## Rejected alternatives

- **Install Open WebUI or replace the app with Next.js:** rejected because issue #1 explicitly targets the existing Laravel/Inertia application and would discard its auth/layout conventions.
- **Add provider SDKs and AI Elements immediately:** rejected because no provider capability, dependency, or UI contract is approved yet; the existing Laravel AI and Radix/Tailwind layers provide the safer starting boundary.
- **Expose provider APIs directly from React:** rejected because it would violate backend-only database/provider access, leak credentials/context, and bypass authorization/audit controls.
- **Implement all actions while building the dashboard:** rejected because actions require separate exact consent, durable execution, recovery, and provider-specific approvals after READ/ANALYZE acceptance.
- **Use a client-supplied tenant/user identifier or broad admin bypass:** rejected because it undermines isolation and cannot satisfy revocation/impersonation acceptance evidence.
- **Treat fake-provider tests as live approval:** rejected because fake evidence proves contracts only and does not authorize provider writes or autonomous runs.

## Open questions

These are the only decisions that genuinely require maintainer/product-owner input before their gates can execute:

1. Which exact AdKit and Zernio read/analyze capabilities and target dispositions are approved for the first milestone?
2. Which identity, client/membership, admin/impersonation, retention/storage, model, and query/provider-budget contracts are accepted?
3. Which dependencies, migrations, provider evidence, and local/browser walkthrough environments are permitted at each gate?
4. After READ/ANALYZE acceptance, which provider write actions, risk tiers, approval expiry, retry policy, and live-operation boundaries are selected?

Until these are recorded, execution may complete repository inventory and fake-independent scaffolding only; it must not register affected capabilities, change production data, or make live provider calls.

## Definition of done

- The approved read/analyze milestone is implemented before any action is enabled.
- Every implemented capability has authorization, audit correlation, redaction, bounded failure behavior, and automated tests.
- Conversation/history/cache/model context cannot cross authorized identity or client boundaries.
- UI is responsive and accessible for the tested desktop/mobile/keyboard journeys and does not imply unavailable actions are executable.
- Required checks pass for the selected scope, with baseline/pre-existing failures clearly separated from feature failures.
- The final handoff lists exact commands/results, fake versus browser/live evidence, and every verified/deferred/blocked capability.
- Any pull request is created only after re-verifying the target remote/root and only for approved, tested changes.

## Plan verification

- Artifact: `.artifacts/plans/issue-1.md`
- Repository: `jkcachero/rwys-4.0`
- Planning baseline: `factory/issue-1` at `ffaf701`
- No application source, dependency, schema, credential, or production-data changes are included by this planning pass.
- The only generated files changed during baseline build were restored before finalizing this plan.

## Initial execution sequence

1. Re-verify the repository guard and read the approved gate decisions.
2. Implement and test the smallest approved gate, stopping when its evidence is complete.
3. Run focused checks after each phase and the full selected-scope commands before handoff.
4. Keep provider actions disabled/unregistered until gates 016–025 are explicitly satisfied.
5. Re-verify repository identity immediately before any requested PR operation.
6. Report exact evidence and capability dispositions; do not infer approval from this plan or from fake tests.

This plan is executable as a staged handoff, but it does not replace the product approvals named above.
