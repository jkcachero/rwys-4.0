# Issue #34 Implementation Plan — Prevent Client Owners from Restoring AdKit Assignments

## Objective

Make AdKit assignment activation exclusively administrator-controlled. A verified Client owner must not be able to use the generic agent-permission endpoint to reactivate, deactivate, or otherwise modify an AdKit-backed `ClientAccountPermission`, including a historical permission row that an administrator disabled before assigning the same account to another Client. Preserve the existing permission-management behavior for non-AdKit providers and preserve the administrator assignment service's validated removal and reassignment lifecycle.

## Verified Baseline

- Target repository: `jkcachero/rwys-4.0`; issue branch `factory/issue-34`; implementation baseline is current `origin/master` at `74cfce7`, which includes PR #33 and commit `d42a439`.
- Client permission mutation route: `PATCH settings/agent-access/{client}/permissions/{permission}`, protected by password confirmation.
- `UpdateAgentPermissionRequest::authorize()` currently verifies the authenticated user, email verification, active Client, active owner relationship, and permission ownership, but does not distinguish AdKit assignment permissions.
- `AgentAccessController::updatePermission()` directly persists validated `is_active` and `can_schedule` values.
- `AdKitAccountAssignmentService::remove()` intentionally deactivates the Client/account permission while retaining history; `assign()` intentionally reactivates that permission after all current-assignment and provider validations pass.
- A focused reproduction proved that the former Client owner can reactivate the disabled historical permission after the AdKit account is reassigned, creating conflicting active claims.
- Existing tests cover normal verified-owner permission updates and the administrator AdKit remove/reassign lifecycle, but not the cross-boundary restoration attempt.

## Chosen Fix Boundary

Reject every Client-owner mutation of an AdKit-backed permission in `UpdateAgentPermissionRequest::authorize()`. Determine the provider from the permission's linked provider account/profile using a database-backed relationship check, not request input. Keep validation and controller persistence unchanged for permissions belonging to other providers.

This is preferable to changing `AdKitAccountAssignmentService` or `ProviderAccount::assignedAdKitTo()` because the defect is the unauthorized generic HTTP mutation path. AdKit assignment state is an administrator-owned authority decision; allowing selected owner-side changes would require revocation provenance that the current schema does not record and could recreate the same ambiguity.

## Phase 1 — Refresh and Add Failing Regression Coverage

### Changes

- Fetch and merge current `origin/master` into `factory/issue-34` without rewriting history; confirm `74cfce7` or a descendant is present before editing.
- Re-check `.ai/rules/index.md` and matching rules if `.ai/rules` appears after refresh, and activate the Laravel/testing skills.
- Extend `tests/Feature/AgentAccessManagementTest.php` using existing factories and password-confirmed owner request conventions.
- Add focused cases proving:
  1. a verified active owner receives `403` when attempting to reactivate an administrator-disabled AdKit permission;
  2. the denial still holds after the same AdKit account has been validly assigned to another Client, and neither Client's permission state is changed;
  3. a verified active owner still can update an equivalent non-AdKit permission, preserving the endpoint's existing contract.
- Assert database state after each denied request, not only the HTTP status.

### Verification

Run the new regression test before implementation and confirm the AdKit restoration case fails against the vulnerable baseline while the existing non-AdKit case remains green.

```bash
git fetch origin master
git merge --no-edit origin/master
git merge-base --is-ancestor 74cfce7 HEAD
php artisan test --compact tests/Feature/AgentAccessManagementTest.php --filter=adkit
```

## Phase 2 — Deny AdKit Permission Mutations at Authorization

### Changes

- Modify `app/Http/Requests/Settings/UpdateAgentPermissionRequest.php` (using the exact post-refresh path) so `authorize()` retains all existing user, email, Client, owner-role, active-membership, and permission-ownership checks.
- Add one provider-specific fail-closed check that returns `false` when the bound permission's provider account belongs to an AdKit provider profile.
- Resolve provider identity through model relationships/a fresh query. Do not accept a provider discriminator from the request and do not infer authority solely from `is_active`.
- Keep `rules()` unchanged (`is_active` and `can_schedule`) and do not add a controller branch, schema column, policy abstraction, or assignment-service bypass.

### Verification

```bash
php artisan test --compact tests/Feature/AgentAccessManagementTest.php
vendor/bin/pint --dirty --format agent
```

Expected result: crafted AdKit permission mutations return `403`; ordinary provider permission updates retain their current behavior.

## Phase 3 — Regress Administrator Assignment Authority

### Changes

- Do not change `AdKitAccountAssignmentService`, its validation order, or `ProviderAccount::assignedAdKitTo()`.
- Run the existing assignment-focused tests that prove administrators can remove and validly reassign AdKit accounts, historical permissions are reactivated only through the service, `can_schedule` is preserved, foreign/current active claims are rejected, and authority is freshly checked.
- If an existing test does not explicitly combine removal, reassignment to another Client, and the former owner's denied HTTP restoration attempt, keep that scenario in `AgentAccessManagementTest`; avoid duplicating the assignment service's broader matrix.

### Verification

```bash
php artisan test --compact tests/Feature/AdKitAccountAssignmentTest.php
php artisan test --compact tests/Feature/AgentProfileAssignmentTest.php
php artisan test --compact tests/Feature/AgentAccessManagementTest.php
vendor/bin/pint --dirty --format agent
git diff --check
```

## Completion Criteria

- Client owners cannot mutate any AdKit-backed `ClientAccountPermission` through the generic settings endpoint.
- A former owner cannot reactivate a permission disabled by administrator removal, including after reassignment to another Client.
- Denied requests leave both historical and current assignment rows unchanged.
- Administrator removal and reassignment continue to work through `AdKitAccountAssignmentService` with existing validation and history-preservation behavior.
- Non-AdKit owner permission management remains unchanged.
- Focused PHPUnit suites pass, PHP changes are Pint-formatted, and the final diff contains no dependency, schema, route, or unrelated UI changes.
