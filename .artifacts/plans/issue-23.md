# Issue #23 — Restore shadcn design consistency for personal AI connection forms

## Goal

Replace the dashboard's oversized, mixed-style Personal AI connections UI with a focused shadcn composition that clearly separates OpenAI subscription sign-in from API-key connections, uses accessible labeled controls and truthful provider-specific copy, and preserves all existing connection transport, polling, authorization, and secret-handling behavior. Done means the reusable presentation is configuration-driven for OpenAI and Claude, its reachable visual/error/disabled states have automated coverage, existing backend connection tests still pass, and the finished page is manually verified in light/dark and responsive layouts with fixture-backed evidence.

## Scope

### In scope

- Redesign the Personal AI connections surface currently rendered in `resources/js/pages/dashboard.tsx`.
- Extract the presentation into `resources/js/components/personal-ai-connections.tsx`, with the dashboard continuing to own the existing fetch/polling handlers and state.
- Reuse existing shadcn primitives: `Card`, `Button`, `Badge`, `Input`, `Label`, `Select`, `Separator`, and the existing error component/patterns.
- Drive OpenAI/Claude API-key field copy, default model hints, and method availability from a typed provider configuration rather than duplicated provider markup.
- Make provider, label, API key, and model fields explicitly labeled and keyboard reachable; expose pending, success, and failure text with appropriate live-region semantics.
- Clarify that ChatGPT/OpenAI subscription sign-in is OpenAI-only and separate from API-key access; do not present Claude consumer subscription OAuth as available.
- Preserve save, revoke, refresh, and ChatGPT polling behavior and endpoint payloads.
- Extend frontend tests for provider-specific rendering and the disconnected, connected, pending, validation/error, and unavailable-method states.
- Retain the focused Laravel feature tests that prove owner isolation, supported-provider validation, and credential redaction.
- Capture fixture-backed before/after screenshots and an operator walkthrough for light/dark desktop and narrow/mobile widths.

### Out of scope

- Fixing OpenAI OAuth/device-code behavior, provider credentials, callback security, or inference behavior; those remain owned by issue #22.
- Refactoring request transport, polling, stale-request cleanup, or broader dashboard architecture; those remain owned by issue #16 except for passing the existing state/handlers through the new presentational boundary.
- Adding Claude OAuth, treating API keys as OAuth, or enabling any unsupported provider method.
- Changing Laravel routes, validation rules, database schema, credential storage, authorization, or API response contracts.
- Adding dependencies, changing the global shadcn theme, rewriting unrelated dashboard sections, or making live provider calls.

## Phases

### Phase 1 — Establish focused state and accessibility coverage

**Changes**

- Add `resources/js/components/personal-ai-connections.test.tsx` using the repository's Vitest and `renderToStaticMarkup` convention.
- Define fixtures that cover:
    - no saved connections;
    - existing OpenAI and Claude API-key connections;
    - ChatGPT sign-in pending, ready, connected, and error states;
    - API-key save/revoke pending states;
    - visible safe error output;
    - provider selection for both OpenAI and Claude.
- Assert user-facing behavior and semantics rather than implementation snapshots: section headings, explicit labels/control IDs, provider-specific method/capability copy, disabled buttons during pending states, status/error live-region roles, no Claude OAuth action, and no rendered secret value.
- Update `resources/js/pages/dashboard.test.tsx` only as needed to keep a page-level integration assertion that the dashboard supplies the Personal AI connection surface and existing workspace copy.

**Tests that prove it**

- The focused component test fails against the current raw form because the accessible labels, method separation, provider-specific configuration, and state semantics do not yet exist.
- The dashboard test continues to prove page integration without duplicating every component-state assertion.

**Verification commands**

```bash
npx vitest run resources/js/components/personal-ai-connections.test.tsx resources/js/pages/dashboard.test.tsx
npm run types:check
```

### Phase 2 — Implement the shadcn Personal AI connection component

**Changes**

- Create `resources/js/components/personal-ai-connections.tsx` as a controlled presentational component.
- Move only the Personal AI connection markup and provider presentation configuration out of `resources/js/pages/dashboard.tsx`; accept current connection data, form values, pending/error/status state, and callback props for start/cancel/save/revoke actions.
- Add a typed `openai`/`claude` configuration map for display name, API-key guidance, model hint, and supported methods. Render one shared API-key form from the selected provider configuration.
- Compose the surface as one restrained card with two clearly separated sections instead of nested decorative panels:
    - **OpenAI subscription sign-in**: OpenAI-only badge/copy, current device-code status, and existing action controls. State clearly that this path depends on the local companion and do not imply Claude support.
    - **API-key connection**: labeled shadcn `Select`, `Input`, and `Button` controls, optional model guidance, per-control help/error association, and existing saved-connection rows/actions.
- Use `Label` with stable `htmlFor`/`id` pairs, `aria-describedby` for helper/error text, `aria-invalid` for invalid fields, `role="status"`/`aria-live="polite"` for progress/success, and `role="alert"` for actionable failures.
- Use responsive `grid`/`flex` layouts and `gap` utilities that stack at narrow widths; preserve existing light/dark tokens and avoid hard-coded theme colors.
- Keep all credentials write-only: render the key field as `type="password"`, clear it through the existing post-save flow, and never pass or display stored credential material.

**Tests that prove it**

- Focused component fixtures pass for both provider configurations and every reachable pending/success/error state.
- Assertions prove only OpenAI exposes subscription sign-in, while both OpenAI and Claude expose the shared API-key form with accurate provider copy.
- Accessibility assertions prove every form control has an explicit label and error/status messaging has a programmatic announcement role.

**Verification commands**

```bash
npx vitest run resources/js/components/personal-ai-connections.test.tsx
npm run types:check
npm run check
```

### Phase 3 — Integrate without crossing the transport boundary

**Changes**

- Update `resources/js/pages/dashboard.tsx` to import and render `PersonalAIConnections`.
- Keep `loadConnections`, `saveConnection`, `revokeConnection`, `startChatGPTLogin`, `cancelChatGPTLogin`, and the existing polling/effect cleanup in the dashboard unchanged in behavior; adapt only their state/handler wiring to the controlled component props.
- Preserve endpoint URLs, request bodies, success reconciliation, error sanitization, owner-scoped connection data, and empty-state behavior.
- Remove obsolete raw form markup/imports from the dashboard after the component is wired.
- Do not change backend code unless a failing existing test proves an accidental contract regression; if that occurs, correct the frontend integration rather than widening issue scope.

**Tests that prove it**

- The dashboard integration smoke test renders the new component with the ready-workspace fixture.
- Existing Laravel feature coverage continues to prove API-key creation/list redaction, unsupported-provider rejection, and cross-owner revoke protection.
- The production bundle compiles with no unresolved shadcn or TypeScript imports.

**Verification commands**

```bash
npx vitest run resources/js/components/personal-ai-connections.test.tsx resources/js/pages/dashboard.test.tsx
php artisan test --compact tests/Feature/AIConnectionTest.php
npm run types:check
npm run check
npm run build
```

### Phase 4 — Browser state matrix and evidence

**Changes**

- Install/setup the existing repository dependencies if the execution checkout remains setup-skipped; do not add packages.
- Run the application with fixture/local data that exercises empty, connected, pending, and safe-error states without real provider credentials or network calls.
- Verify the Personal AI connections card at desktop and narrow/mobile widths in both light and dark themes.
- Keyboard-walk provider selection, every input, save/revoke actions, and ChatGPT start/cancel actions; confirm visible focus, sensible tab order, no clipped controls, and no keyboard trap.
- Trigger fixture-backed validation/transport errors and confirm the visible message is associated with the relevant surface and announced by its live-region semantics.
- Save before/after screenshots under `.artifacts/evidence/issue-23/` and record an operator walkthrough there with exact fixture/setup steps, observed states, and any blocked checks. Do not claim live OAuth/provider success and do not include credentials, device codes, tokens, or raw sensitive responses.

**Tests that prove it**

- Screenshot matrix: before/after at desktop light, desktop dark, narrow light, and narrow dark.
- Operator record confirms keyboard/focus/error behavior and identifies any environment limitation instead of marking an unperformed check as passed.

**Verification commands**

```bash
npm run build
# Start the existing local Laravel/Vite workflow, then perform the documented browser walkthrough.
find .artifacts/evidence/issue-23 -maxdepth 1 -type f -print
```

## Risks

- **Issue #16 overlap:** moving request or polling logic would create conflicting ownership. Keep the new component controlled and presentational; compare endpoint/payload/polling code before and after.
- **Issue #22 overlap:** revised copy could accidentally imply that subscription OAuth works or that Claude supports it. Assert provider-method text and absence of a Claude OAuth action in tests.
- **Hidden credential leakage:** fixtures or errors could expose keys/device data. Use obviously fake redacted fixtures, assert secrets are absent from rendered markup, and inspect evidence before saving it.
- **Static-render test limits:** server rendering proves structure and state branches but not real focus/live announcement behavior. Treat the browser keyboard/error walkthrough as required complementary evidence.
- **Setup-skipped checkout:** frontend commands currently fail because dependencies are absent. Restore only declared dependencies from the lockfile; if setup or browser tooling remains unavailable, report the exact blocker and do not claim those checks passed.
- **Responsive regression:** action rows may overflow at narrow widths. Verify stacking and long error/capability copy at mobile width before completion.

## Assumptions

- The target is `jkcachero/rwys-4.0`; the verified checkout is branch `factory/issue-23` at merge commit `d96927e`, where the current form was introduced.
- The bug is presentation/accessibility drift, not a provider protocol defect; severity/impact/effort remain medium with high confidence from triage.
- `resources/js/components/personal-ai-connections.tsx` is the smallest useful boundary: it removes provider markup from the dashboard while deliberately leaving issue #16's transport/state refactor untouched.
- A typed configuration map plus one shared API-key form is preferred over separate OpenAI/Claude components because their supported API-key behavior is the same and the issue explicitly rejects duplicated provider markup.
- The existing Radix-backed shadcn `Select` is preferred over a native `<select>` because it is already installed and matches the settings UI conventions; no dependency change is necessary.
- Existing Profile and Settings connection pages are the design source of truth: labeled controls, `grid gap-2` field groups, shared errors, cards/badges, dark tokens, and disabled processing actions.
- OpenAI subscription sign-in remains visible but clearly separate and OpenAI-only; Claude consumer-subscription OAuth remains unavailable pending issue #22's product/technical disposition.
- Frontend static-state tests plus a real browser keyboard/responsive/theme walkthrough are the available evidence strategy; no browser-test dependency should be introduced for this issue.
- Backend routes and tests remain unchanged because the existing `/workspace/connections` contract already supports OpenAI and Claude API keys and protects/redacts credentials.

## Considered and rejected alternatives

- **Keep all markup in `dashboard.tsx`:** rejected because the file already mixes page rendering, request state, and a large provider form; a controlled presentational extraction reduces visual complexity without taking over issue #16's transport work.
- **Create separate OpenAI and Claude forms:** rejected because it duplicates common fields/actions and invites the exact provider drift reported by the issue.
- **Move the form into Settings instead of redesigning the dashboard:** rejected because it changes product navigation and does not fix the reported dashboard surface.
- **Add a form/testing/UI dependency:** rejected because the required shadcn primitives and Vitest setup already exist, and the issue forbids dependency expansion.
- **Alter OAuth behavior while touching the UI:** rejected because issue #22 owns that independent defect and requires substantially different security/contract evidence.

## Open questions

None. Provider behavior, UI source patterns, ownership boundaries, and verification strategy are sufficiently established by the current code, related issues, and repository conventions.
