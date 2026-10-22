# External Agent API Operator Walkthrough

Status: Runbook

**Scheduling live status: one approved Instagram image test succeeded through RWYS. No second create is authorized.** The completed main-task evidence below supersedes the earlier pending scheduling status. The original read token remains unchanged, the temporary scheduling token was revoked, and write gates were restored off. Manual samples are retained only for separately approved future work, not automatic re-execution. This documentation finalization makes no further live calls, browser actions or actual database/environment changes. The API has eleven operations on ten paths.

Use this when an approved operator needs to verify the current token-only external agent API without mistaking isolated automated tests for live-environment proof.

## Quick Answer

Start with isolated tests, not another publication or an environment change. Main reports final global regression results of **1094 PHP tests / 12404 assertions**, **101 React tests**, TypeScript, production build and Pint all passing. These supplied results are not a fresh full-suite run by this documentation finalization; final scoped formatting confirmation remains separate. Earlier 831-backend/55-React results are historical. Fake-provider tests alone are not live integration or production concurrency proof.

## Completed Live Evidence

The main task completed the single user-approved scheduling test on **2026-10-22**, after code verification. Do not rerun the create sample to reproduce this record.

1. John used his legitimate owner browser login and password confirmation. Protected `POST /settings/agent-tokens` created temporary token **ID 2**, restricted to local account **1**, with exactly `context.read`, `operations.read`, `zernio.posts.schedule`. The client used RWYS only, never the direct server provider key. The original read-only token **ID 1** was not changed.
2. A Canvas-generated **1080 x 1080**, **13149-byte JPEG**, white with black `Test`, was submitted with caption `Test`. Exactly **one** multipart `POST /api/v1/zernio/accounts/1/scheduled-posts` used one idempotency UUID and frozen `scheduled_for=2026-10-22T16:44:25Z`, approximately five minutes after submission at **16:39:25Z**. RWYS operation: **`01a1647b-4ef7-7349-9320-b3c31eb8bd4f`**. Provider post: **`6ada3c3fa5b451177b6651ad`**.
3. Initial response was **202 `unknown`**, not `provider_pending` or published. Zernio had promoted its temporary path to permanent media with the same filename. An independent bounded CDN GET returned 200 and SHA-256 exactly matching the approved immutable image. The narrow promotion comparator described below was added without changing the source URL or frozen intent. No media asset URL, hash or credential is reproduced here.
4. Targeted recovery used candidate GETs only through `agent:reconcile-operations --operation 01a1647b-4ef7-7349-9320-b3c31eb8bd4f --limit=1`. No second upload/create occurred. At **2026-10-22T16:49:36Z**, ledger state was **`succeeded`**, with provider root/target `published` and expected profile/caption/time/media evidence. Native URL: **https://www.instagram.com/p/DezcZvwl9xq/**.
5. At **2026-10-22T16:51:04Z**, the unchanged original read token **ID 1** obtained **HTTP 200** for `GET /api/v1/zernio/accounts/1/posts/6ada3c3fa5b451177b6651ad`, with caption `Test`, root/target published and the native URL. No independent Instagram DOM visit is claimed. These observation times do not establish the exact publication instant; provider `updatedAt` **16:46:11Z** is not necessarily a publication timestamp.
6. Protected owner-browser `DELETE /settings/agent-tokens/2` returned **204** and token 2's absence was verified. Token 1 still exists and its read GET worked. Account 1 publishing permission, server publishing and Instagram scheduling gates were restored **false**; reads remain **true**. Browser authentication/vault temporary credentials were deleted and the isolated session closed. Other users, roles and profile data were unchanged, with no database reset.

The post remains available for the user to view. Gate restoration does not cancel an accepted provider post; cleanup requires separate approval. This record proves only this one Instagram image and the scoped owner-token creation/revocation/read/recovery path. Rotation, other platforms, analytics, AdKit, assignment UI checks, deployment and production concurrency are not thereby verified. See the [quickstart evidence](external-agent-api-quickstart.md#completed-live-evidence) for the matching record.

**Operator Agent access navigation and empty-state browser checks are complete.** The actual `test@admin.com` session opened `/settings/agent-access` and redirected to `/settings/provider-accounts`, with one **Agent access** Settings entry and both shared tabs. DOM activation of **My API tokens** navigated to `/settings/agent-tokens`. For this operator without an owned Client, **No personal client access** and **Manage account assignments** were visible, with no `#agent-client` select or creation form.

**Registered client dropdown desktop/mobile and selection checks are complete in the actual operator session.** At **1227 x 1345**, the canonical Agent access entry redirected to assignments; the snapshot showed one combobox labeled **Registered client**, placeholder **Select a registered client**, and the `Local agent test` option with owner `John Karlo` and email `johnkarlo.315@gmail.com`. No client-search input or **Find clients** button was present. At **390 x 844**, the dropdown and correct options remained present, the removed search/button remained absent, and document width **375px** stayed within viewport width **390px** without overflow. The viewport was restored to **1227 x 1345**; native option value `1` was selected and the resulting value confirmed as `1`. The request log was cleared before selection; filtering for discovery requests afterward returned **No requests**, confirming no automatic provider I/O. The local list has one page; multi-page pagination has unit coverage only, not live page-two verification.

**New profile UI readiness is browser-verified in the actual operator session.** The canonical Agent access entry redirected to **Account assignments**, showing John's registered Client dropdown and **Load Zernio profiles**, not an account-loading control. Selecting John's Client enabled that button. The native **Zernio profile** dropdown remained empty until explicit loading; **Assign profile** was disabled without a choice. At **390px** viewport width, document width was **375px** without overflow, both dropdowns were visible and the old account-search field was absent. The viewport was restored to **1227 x 1345**. **Load Zernio profiles**, **Assign profile** and **Sync accounts** were not clicked; no live profiles/accounts were fetched and no assignment/token mutation occurred. This is UI readiness/no-automatic-provider-I/O evidence only. Live profile listing, assignment and synchronization remain unverified.

The approved permission migration and separately approved operator setup below are historical. John remains a verified admin without assignment permission. His owner login/password confirmation and temporary scoped token creation/revocation now have the evidence above, but a fresh assignment-denial/navigation check, rotation, full token lifecycle, live assignment UI and deployment remain unverified. Do not assume the local operator exists elsewhere. Session navigation adds no external API abilities. The **full target is NOT complete**; see the [29-step acceptance checklist](external-agent-api-quickstart.md#acceptance-checklist).

## Normal Flow

```text
Isolated tests -> approve environment and backup -> review migrations
-> separately approve reviewed migrations -> approve existing-owner Client
-> separately approve narrow operator permission -> operator Settings
-> Agent access -> Account assignments -> choose RWYS Client first -> Load Zernio profiles
-> confirm exact profile and Client -> Assign profile -> import connected accounts read-only
-> separately approved Sync accounts when profile connections change
-> owner Settings -> Agent access -> My API tokens -> Create token -> local context read
-> approved REST import/off-host check -> optional approved provider reads
-> separately approved text publication/local poll -> Rotate -> Revoke
-> manual scheduler/runtime review -> sanitized acceptance evidence
```

## Before You Start

- URL: use `<APP_ORIGIN>`, replaced with the actual browser origin or approved `APP_URL`, without a trailing slash. Boost URL tools are unavailable here; no host/port is guessed. Use HTTPS except for an approved local environment.
- Account: an existing verified owner with the existing password and an approved active Client. Do not create/reset John Karlo's email, role, password, or any other existing user.
- Test record: approved existing Client and exclusive Zernio profile assignment only. No real profile has been imported; do not seed sample IDs or split a profile between Clients. If the Client is missing, stop for provisioning approval at step 3.
- Sample input: token name `Operator read check`; placeholder profile/account/post hex examples are fictional, not live IDs. Actual IDs appear only in the explicitly labeled completed evidence record.
- Required access: repository terminal for isolated tests, approved environment/OS operator for database/config changes and the permission CLI, separately permitted verified admin session for assignment, and owner browser session for token management. An admin role alone cannot grant this permission over HTTP.
- When to stop: wrong environment, missing backup/approval, unexpected Client, token exposure, unauthorized selector, live publication without approval, or inconsistent request IDs.
- Client setup needs only a RWYS token. No client provider API key, delegated sign-in flow, or model setup. Provider credentials belong on the server.

## 1. Run Isolated Tests

From the project root, confirm `phpunit.xml` and the effective test environment select SQLite `:memory:` with an empty database URL, then run the normal full backend verification command first:

```bash
php artisan test --compact
```

For focused documentation or guard/scheduler diagnosis, use:

```bash
php artisan test --compact tests/Feature/AgentApiDocumentationTest.php
php artisan test --compact tests/Feature/AgentRequestGuardTest.php
```

Expected result: the invoked suite passes; retain its actual summary without predicting totals. The documentation suite's database guard requires SQLite `:memory:` and an empty database URL before refresh; HTTP is faked and stray requests prohibited. It verifies the public contract, real route abilities, schema examples, safe resource fields and actual error envelopes. Passing local SQLite tests does not establish production database row locking or multi-worker concurrency, deployment or provider connectivity. Do not override test database guards to point at the existing database.

The earlier navigation-only documentation run passed **22 tests / 3,962 assertions** with SQLite `:memory:`, empty database URL and fake HTTP. It predates scheduling; use current output rather than this historical count for the scheduling contract. This does not imply a new full-suite run or browser verification.

Current isolated scheduling-contract verification on 2026-10-23: documentation **23 tests / 4,518 assertions**, scheduling **56 tests / 647 assertions**, **zero failures**. Scoped Pint passed for `AgentApiDocumentationTest.php` only. These are fake-provider/in-memory results, not live acceptance or a new full-suite run.

If this fails: stop, retain only sanitized failure output, and ask the maintainer to resolve it. Do not run a migration to make the isolated test pass.

## 2. Review Environment And Migrations

**Current local execution record:** after explicit user approval for the separate-operator approach, SQL was reviewed and only the following migration preview and application were run against local PostgreSQL `rwys_4`:

```bash
php artisan migrate --path=database/migrations/2026_10_22_121947_add_provider_account_assignment_permission_to_users_table.php --pretend --no-interaction
php artisan migrate --path=database/migrations/2026_10_22_121947_add_provider_account_assignment_permission_to_users_table.php --no-interaction
```

This migration is already applied locally. It adds `users.can_assign_provider_accounts` with default `false` for all users, including existing admins. The migration itself grants nobody permission, creates no user and performs no provider I/O. The later explicitly approved creation and permission grant for local `test@admin.com` were separate actions; John retains a false permission flag. This is a narrow persisted per-user capability, not an environment flag. Do not rerun generic all-pending migrations for this local setup.

**Other environments only:** the following instructions remain manual and approval-gated. Confirm the application/database target with the environment owner. Obtain a current restorable backup and an approved change window before any database action. Have the maintainer inspect migrations for clients/security, operations, webhook receipts, API audits and the permission column, their ordering and existing table state. Limit any application to the explicitly reviewed scope; use an approved `--path` when only one migration is authorized.

Only for another approved environment and reviewed pending scope, the general preview command is:

```bash
php artisan migrate --pretend --no-interaction
```

Expected result: proposed SQL can be reviewed against the approved schema and backup. Treat this as an environment-connected review command, not a guarantee that arbitrary migration PHP is side-effect-free. Stop on an unexpected connection, pending unrelated migrations, SQL errors, or schema ambiguity.

**Separate approval required:** only if the reviewer authorizes applying all reviewed pending migrations to that exact other database, the operator may run the following as a distinct action. Otherwise use only the approved path-scoped command. Do not run it merely because the preview succeeded. This generic all-pending command was not executed for the current local migration.

```bash
php artisan migrate --no-interaction
```

Expected result: only approved migrations apply. If a production confirmation or force requirement blocks the command, stop and obtain environment-specific approval rather than adding flags here. Do not use destructive migration/reset/fresh commands, a new seeder, ad hoc scripts, or a debug console to bypass review.

### Optional Local Reset

The local-only `LocalAgentAccessSeeder` is called by `DatabaseSeeder`. With **`APP_ENV=local` only**, it creates missing demo accounts with these defaults:

| Email                     | New Account Role | Email Verified | Assignment Permission |
| ------------------------- | ---------------- | -------------- | --------------------- |
| `test@admin.com`          | `admin`          | Yes            | `true`                |
| `johnkarlo.315@gmail.com` | `admin`          | Yes            | `false`               |

These are intended local-test fixtures, never production credentials. The literal password `password` is hashed and assigned **only to newly created demo accounts**. Reruns preserve every existing user's credentials, roles, verification, assignment permission and 2FA. A verified John gets an active owned Client named **John Karlo** only if he has no existing Client; existing Client names/state remain untouched. Default `DatabaseSeeder` retains the existing `test@example.com` fixture idempotently. No provider mappings, API tokens, keys, configuration or live I/O are seeded. Guard coverage proves the local seeder does not create these two demo principals in production, staging or testing.

**DESTRUCTIVE: deletes all tables and data in the selected database, including API tokens, mappings and conversations. Only if you explicitly intend to reset a disposable local database, have a backup, and have confirmed `APP_ENV=local` and the exact database target**, manually run:

```bash
php artisan migrate:fresh --seed --no-interaction
```

Expected result: reviewed migrations rebuild the disposable database and default seeding creates the local fixtures above. This command was **not executed** for this request. Stop on a shared/nonlocal/uncertain target, missing backup or absent explicit reset approval. This is not the normal upgrade path or a way to make isolated tests pass. Do not add force flags to bypass review.

For approved seeding of an already migrated local database **without dropping tables**, the narrower manual alternative is:

```bash
php artisan db:seed --class=LocalAgentAccessSeeder --no-interaction
```

Standard `php artisan db:seed --no-interaction` also invokes the local seeder and is repeatable. These commands still write the selected database, but do not reset existing passwords or rights. Neither seed command was executed against the existing database for this request. If an existing account cannot sign in or lacks permission, stop for its separate approved access procedure; do not assume the fixture password applies.

After a fresh local seed, sign in as the newly created operator, open **Settings > Agent access > Account assignments**, and choose the **John Karlo** registered Client with John as owner. Existing-database browser evidence used **Local agent test**; do not rename it to match the fresh fixture. The earlier separately approved creation of the two local users remains historical setup, not a new seeder invocation. Seeder coverage passed **6 tests / 62 assertions**, and the subsequent full backend run passed **607 tests / 8,307 assertions**. The existing application database was not reset or seeded for this request.

### Review Profile Migration

**Applied locally after explicit user approval and tests:** fixed SQL was reviewed with a path-scoped `--pretend` preview, then this application completed against PostgreSQL `rwys_4` in **39.24ms**, status **DONE**:

```bash
php artisan migrate --path=database/migrations/2026_10_22_143749_create_client_provider_profiles_table.php --no-interaction
```

After application: **1 Client, 0 profiles, 0 account mappings**, publishing **false**. No reset or provider I/O occurred. John's and the operator's credentials, roles, verification and assignment flags remained unchanged; no real profile was imported. Do not rerun this completed migration or seed/reset the existing database for verification. This documentation edit itself runs no migration.

The new `ClientProviderProfile` claim is globally unique by provider/environment/profile ID and exclusive to one Client; multiple profiles per Client are allowed. Account runtime scope requires `client_provider_profile_id` linkage to that Client's claim. Preflight checks all mappings, including inactive rows, and rejects split ownership **before DDL**. Sorted lazy batches of 100 profile groups backfill claim/FK linkage while preserving local account IDs, hashes, rights, tokens and timestamps. Profile `name` is database `text`, not `varchar(255)`, accepting the adapter's 1024-byte maximum. The fixed migration supports `--pretend`; its SQL preview is not runtime/backfill execution proof. No provider I/O occurs. Other environments need their own schema/data review, backup and explicit approval; stop on split ownership/ambiguity, never an unreviewed reset or reassignment. Local approval is not transferable.

## 3. Select The Approved Client And Assign A Profile

### Confirm The Existing Owner's Client

Prefer an already approved Client. This command is a **database write requiring explicit approval**, not a safe verification command. The exact owner email must already exist and be verified; the command never creates the owner. `owner@example.invalid` and `Operator approved client` below are fictional template values; replace them only with reviewer-approved values. Do not run the template unchanged.

For an approved client-only setup:

```bash
php artisan agent:provision-client 'owner@example.invalid' 'Operator approved client' --no-interaction
```

Expected result: `Client ID: <local integer>`. Repeat exact owner/name can select an existing active unambiguous Client. An inactive or ambiguous Client fails closed. A Client without mappings supports local discovery, not provider work.

Do not provision a fake profile or mapping to populate the picker. The approved flow selects a registered RWYS Client first, then discovers actual Zernio profiles for exclusive assignment and account import. Local demo seeding creates only users/Client, never real profile IDs, ownership claims, account mappings or API tokens. Legacy CLI mapping options are not profile discovery or an ownership bypass; do not use them to split a profile, resolve conflicts or enable publishing.

### Approve The Separate Operator Permission

The local permission migration and separately approved operator setup are complete: `test@admin.com` is a verified admin with explicit assignment permission. Do not repeat user creation or grant setup as a verification step. Use the approved existing credentials privately; do not document a password or collect credentials from the API client. John remains a verified admin without assignment permission.

**Other environments only:** first obtain their own migration review and approval. Before any grant, have the environment owner confirm that the exact selected email already belongs to a verified admin; do not assume the local `test@admin.com` exists there. If absent, unverified, or not an admin, stop. Do not assume a password, create a User or change identity, credentials, role or verification state merely to make the command succeed. Any user provisioning requires its own explicit approval outside this CLI setup.

Only in that separately reviewed environment, after explicit approval for this database write and confirmation of the exact existing verified admin, an authorized server/OS operator may run:

```bash
php artisan agent:account-operator 'test@admin.com' --no-interaction
```

Expected result: `Provider account assignment permission granted.` The CLI uses the exact existing email and updates only the capability and normal update timestamp, not identity, role or credentials. Failure is `Permission update failed; no changes were saved. Use an exact existing email; grants require a verified admin.` Stop rather than changing the user. Grant authority comes from server/OS command access, not an ordinary admin's HTTP session. Runtime authorization has no hardcoded email: it requires a verified admin plus the persisted explicit flag, rechecked server-side.

For a separately approved revocation of this operator, use:

```bash
php artisan agent:account-operator 'test@admin.com' --revoke --no-interaction
```

Expected result: `Provider account assignment permission revoked.` `--revoke` works for any exact existing user, even an unverified/non-admin user; it does not require restoring a role first. Missing users fail without creation. Do not revoke the intended operator before the approved assignment check. No permission is automatically assigned to John Karlo or any other admin; do not grant John this capability for this walkthrough.

### Provider Readiness

The earlier disabled-provider check is historical: server-side reads were subsequently enabled with explicit user approval and a valid server key. Provider controls are now ready, not blocked by missing configuration. Publishing remains false. This is not proof of live profile import. Seeding or `migrate:fresh` does **not** enable the provider.

For another environment only, after its separate configuration and provider-read approval, the server operator may set `AGENT_ZERNIO_ENABLED=true`, retain the required valid server-side `ZERNIO_API_KEY`, and keep `AGENT_ZERNIO_PUBLISHING_ENABLED=false`. Do not read `.env`, print/request the key or put provider credentials in the browser. After an approved configuration change, the manual cache-refresh command is:

```bash
php artisan config:clear --no-interaction
```

Expected result: effective configuration can enable read-only profile discovery; the operator may then recheck the page before any separately approved provider read. Restart application workers only if the environment's established procedure requires it. This documentation edit makes no configuration change, config-clear invocation or provider request. Stop if configuration remains disabled/unavailable; do not expose credentials or enable publishing to make the button work.

### Choose A Client, Then Discover And Assign

Operator entry navigation, desktop/mobile display of both dropdowns, Client selection without automatic discovery, enabled **Load Zernio profiles** after selection and disabled **Assign profile** before a profile choice are browser-verified. The local Client list has one page; multi-page Client pagination has unit coverage only. **Live profile discovery/assignment/sync has not been executed.** The approved local profile migration is applied. Obtain separate approval for exclusive ownership of the exact profile by the Client, importing all its accounts and the provider reads before proceeding. Do not change flags during UX checks; no provider credential belongs in the browser. A navigation-only check must stop before loading profiles or assigning.

1. Sign in as the separately approved operator using existing credentials. Open **Settings > Agent access** at `<APP_ORIGIN>/settings/agent-access`. Confirm the existing password at `/user/confirm-password` if prompted. This authenticated, verified, password-confirmed GET freshly checks permission and redirects an authorized operator to `/settings/provider-accounts`; everyone else goes to `/settings/agent-tokens`. Expect one Agent access Settings entry, the shared **Agent access** heading, active **Account assignments** tab with **Operator** badge, and **My API tokens** tab with **Personal** badge. Only the explicitly permitted operator gets an assignment link; nonoperators see the assignment tab locked with an explanation and no link. The route/service Gate still requires verified admin plus the persisted explicit flag for the page, discovery and assignment. Both concrete page routes remain functional. The operator entry/navigation check is browser-verified; the nonoperator redirect/locked tab has automated coverage, with John's new manual flow pending.
2. Under **1. Choose a client**, open the native **Registered client** dropdown, initially **Select a registered client**. Select the approved registered RWYS Client and verify name/owner/email. Existing-database example: `Local agent test`, owner `John Karlo`, email `johnkarlo.315@gmail.com`; a fresh local seed instead creates Client **John Karlo**. Options are active registered Clients with verified owners, not all Users or Zernio Clients. The first page is at most 25 records; **Previous clients** / **Next clients** appear only for multiple pages. Each local clients GET uses `page`/`limit`, no search GUI/query or fetch-all. Selection clears stale provider selection/results without provider I/O. Missing Client means stop for approved existing-owner provisioning.
3. Under **2. Choose a Zernio profile**, click **Load Zernio profiles** only after provider-read approval and wait for **Loading...** to clear. Opening the page or selecting a Client never loads provider data automatically. There is no account-search bar or profile-name search UI. If configuration is unavailable, stop; seeding does not enable discovery.
4. Open the native **Zernio profile** dropdown, initially **Select a Zernio profile**. Options show profile name and optionally `accountCount`; confirm the exact approved profile ID/name, not just a familiar name. **Previous profiles** / **Next profiles** fetch approved 25-profile pages only. This is the official profile list, not accounts grouped into synthetic profiles. Optional count metadata is not proof of a complete account inventory.
5. Verify the selected profile and Client together. Click **Assign profile** once only after approval to claim the entire profile exclusively for that Client and import all connected accounts. Expect **Profile assigned** (HTTP 201) for a new claim or **Profile synced** (HTTP 200) for the existing claim. A profile with zero accounts can still be reserved. Stop on a different Client's claim; different account IDs do not permit splitting or silent reassignment.
6. Inspect **Assigned profiles** and, for the current response, its account platform and active/inactive projection. Later, with explicit approval for provider reads/local synchronization, click **Sync accounts** on the owned profile to materialize newly connected accounts. New connections belong logically to its Client but do not enter API scope until explicitly synced. Record sanitized local evidence only; do not retain the global provider list or another owner's data.

Expected result: one exclusive provider/environment/profile claim for the Client, with connected accounts materialized under `client_provider_profile_id`. A Client can own multiple profiles. New imported accounts are read-only with `publishing_enabled=false`. Profile discovery uses official `GET /v1/profiles?limit=25&skip=0`, with bounded offsets for subsequent pages, never grouping accounts. Assignment/sync verifies the selected ID via `GET /v1/profiles/{id}`, then lists accounts pinned to that profile ID with `page` 1-3 and `limit=100`: at most 300 accounts, four provider GETs total, 15 seconds per call and a 60-second overall deadline. Incomplete lists, overflow, inconsistent totals or duplicates fail closed with no partial records or ownership claims. Secret transport guards remain; provider credentials stay server-side and no provider write occurs.

The session POST accepts **only `{client_id, profile_id}`**. Old `provider_account_id` payloads return **422**; arbitrary environment/platform/credential/publishing fields, query strings and extra inputs are rejected. This is not a generic proxy or a new external token ability. Sync imports new connections and deactivates removed/remote-inactive rows, but **never revives a manually inactive existing row**. Existing rights are not widened. Exclusive profile conflicts return **409** with prior state preserved, never partial claims, silent reassignment or split ownership. There is no unassign/delete/reassign shortcut. Stop rather than editing rows or changing Clients to force success.

Fresh authorization is checked after every provider GET. The optimistic `sync_version`, default `0`, is captured before provider I/O and compared under lock before committing; a stale snapshot is rejected. Database-only transaction retries are bounded to three and acquire sorted user locks before the Client lock. Local fake-provider tests cover these guards and atomic new/removed-account sync within the 300-account/four-GET/deadline bounds, not real provider behavior or PostgreSQL multi-worker concurrency. Existing inactive mappings/rights remain without hidden reactivation; original external selectors, local account IDs and permissions are unchanged.

The initial **Assigned profiles** list contains at most 25 profile metadata/count records, without eagerly listing child accounts. A current assignment/sync response projects at most 300 accounts with platform and active/inactive state. Reloaded linked-record counts can include retained inactive historical mappings; neither those counts nor provider `accountCount` metadata prove current remote completeness. To inspect the bounded initial profile list again, use the approved `<APP_ORIGIN>/settings/provider-accounts?client_id=<APPROVED_CLIENT_ID>` page and recheck the Client identity.

Stop on **401**, **403**, **423**, **419**, **409**, **422**, **502** or **503**, or unexpected redirects/session/network failures. 401 means authentication/session failure; 403 means denied/revoked permission; 423 means password confirmation is required; 419 means expired/invalid CSRF/session; 409 is an ownership/mapping conflict; 422 is rejected input, including the old account payload. For 423, use **Confirm password** with the existing password, then reopen and reselect only after access is restored. Do not disable CSRF or repeatedly submit. The root Blade page supplies the CSRF meta token read by assignment POST; source/fake tests are not proof of real browser mutation behavior. 502 indicates unavailable/invalid/incomplete provider evidence; 503 indicates unavailable configuration. Escalate sanitized status only, never upstream errors or credentials. Session routes retain web auth, verification, password confirmation, narrow permission, POST CSRF, no-store and 30/minute throttle, not the external API envelope/abilities.

### Verify The Owner Boundary And Token Scope

After assignment, sign in as the approved Client owner (John Karlo only when this is his approved Client). **Settings > Agent access > My API tokens** exposes only his owned Clients and assigned accounts. For the pending new-UX negative check, John must see one **Agent access** sidebar entry, not separate assignment/token sidebar links. It must redirect to `/settings/agent-tokens` after normal gates. The **Account assignments** tab, badge **Operator**, must be locked with no link and show **Only operators can assign accounts. Ask an operator for help.** Normal navigation must not send him to a 403 dead end. As a separate direct-route security check, `<APP_ORIGIN>/settings/provider-accounts` must still return **403** after normal session/password gates, even as admin. The earlier direct-page 403 is historical evidence only. Do not grant permission to make the negative check pass. John must not gain global provider discovery or assignment authority. His Client's bearer API cannot discover global provider IDs; foreign account selectors remain **404**. Even an assignment operator's own bearer API stays Client-bound with no admin bypass.

Review tokens before assignment/sync: `allowed_account_ids=null` grants all current and future eligible materialized accounts of the Client's owned profiles. Newly connected remote accounts require explicit **Sync accounts** before entering that scope. Explicit allowed-account IDs remain unchanged; create a fresh approved scope if needed. **Rotate** preserves old scope/rights. Assignment/sync adds no token abilities or publishing permission: immediate text publication and Instagram scheduling need their respective server, mapping and explicit write-ability gates. Operator profile ownership is not an external agent ability; the REST contract is eleven operations on ten paths using Client-bound local account IDs.

## 4. Open My API Tokens And Create A Read Token

The earlier operator navigation/empty-state checks remain historical. Owner login/password confirmation and creation/revocation of temporary scheduling token 2 were completed as recorded above. The read-token creation/lifecycle sample below is a separate future procedure; rotation and the full lifecycle are not verified by the scheduling test. Do not recreate, rotate or revoke original read token 1 to repeat it.

1. Open the approved application origin in your browser and sign in as the existing verified owner using the existing credentials.
2. Open **Settings > Agent access > My API tokens**. The single sidebar entry uses `<APP_ORIGIN>/settings/agent-access`; an authorized operator lands on assignments first and must click **My API tokens**. The concrete `<APP_ORIGIN>/settings/agent-tokens` link also remains functional.
3. If redirected to `/user/confirm-password`, enter the existing password. Do not reset it. Wait for the **Agent access** heading and active **My API tokens** tab with **Personal** badge.
4. In **Create API token**, under **Client**, select the approved active owned Client; a sole active owned Client is auto-selected. Under **Token name**, type `Operator read check`.
5. Under **Read permissions**, review the default all-read selection: `context.read`, `zernio.accounts.read`, `zernio.posts.read`, `zernio.analytics.read`, and `operations.read`. Leave only approved permissions checked. For discovery only, retain `context.read` and uncheck the others. For approved likes checks, explicitly retain `zernio.analytics.read`. Existing tokens do not acquire it automatically: create a fresh approved scope, since **Rotate** preserves rights.
6. If approved mappings exist and restricted scope is intended, check **Restrict to selected accounts** and select at least one approved entry under **Mapped accounts (select at least one)**. With no restriction, scope includes all current and future eligible materialized accounts linked to this Client's owned profiles; new remote connections require explicit operator **Sync accounts** first.
7. Leave **Allow publishing (write access)** and **Allow scheduled Instagram images (write access)** unchecked for this read token. Click **Create token** once and wait for **Your new token** and the new **Existing tokens** card.
8. Store the plaintext from **New agent token** in the approved secret store before navigating away; it is shown once. Do not photograph, log, or share it. Click **Dismiss token** after storing it.

Expected result: only owned Clients appear; inactive options are disabled. The form heading is **Create API token**, but the button remains **Create token**. The token card shows permissions, account scope, expiry and last use. New expiry is 30 days from issuance; server-wide expiration can shorten effective lifetime. Refresh once and confirm plaintext no longer appears. Do not attempt to recover it from history or browser storage.

If no active owned Client exists: expect **No personal client access** and no creation form. An operator also sees **Manage account assignments**, which returns to assignments; this never permits creating a token for John or another owner. Stop token creation and return to the approved existing-owner provisioning process, not another user's account. No ownership or ability permissions are widened. If the action fails, use **Confirm password** once, then recheck the selected scope; stop if failure persists. Repeated clicking can create more tokens and is not a recovery strategy.

## 5. Make One Local Context Read

This is an approved manual API check, not an executed action. It makes no provider calls but does write local audit/admission and last-use records. Open a terminal, start **Bash** explicitly even if your login shell is zsh, and replace `<APP_ORIGIN>` with the approved origin before running:

```bash
bash
set +x
APP_ORIGIN='<APP_ORIGIN>'
read -rsp 'RWYS token: ' RWYS_TOKEN
printf '\n'
curl -q --config - <<EOF
url = "${APP_ORIGIN}/api/v1/context"
header = "Authorization: Bearer ${RWYS_TOKEN}"
header = "Accept: application/json"
include
silent
show-error
EOF
unset RWYS_TOKEN
```

Paste the token only at the silent prompt, not into command text. Bash expands the header in stdin to curl, not in its process arguments. Do not use tracing, curl debug output, redirects, an insecure TLS flag, or export the token. Do not retain terminal capture containing secrets.

Expected result: HTTP 200, `data.client.id` equal to the approved Client ID, `data.capabilities`, a UUID `request_id`, identical `X-Request-ID`, and `Cache-Control` containing `no-store`. When unconfigured, Zernio capabilities are false and AdKit is `blocked` with reason `Not implemented.` There are no provider calls for context or capabilities.

If 401: check once that you used the newly issued unexpired token for an active owned Client, then stop. If 403: confirm `context.read` was granted. Any redirect, wrong Client ID, secret, or missing/mismatched request ID means stop and escalate. Never paste the token into an escalation ticket.

## 6. Optional Approved Read Checks

Do not run provider checks without operator approval for provider access. The same stdin-only curl pattern from step 5 can be repeated after reading the token again. Change **only** the `url` line to the desired approved path, and unset the token afterward.

1. Use `/api/v1/capabilities` for another local check. Expect only available granted abilities, Zernio booleans, and blocked AdKit.
2. If account-read access is approved, use `/api/v1/zernio/accounts?page=1&limit=25`. Although this makes no provider calls, disabled/unconfigured Zernio returns **503 `service_unavailable`**, not an empty success. Stop there; do not enable the provider merely to obtain a green result.
3. With provider reads separately approved and configured server-side, expect safe account fields exactly `id`, `provider`, `environment`, `platform`, `status`, `write_enabled`. Record the approved **local** account ID. Status `mapped` is not live connectivity.
4. For an approved local account ID, use `/api/v1/zernio/accounts/<LOCAL_ACCOUNT_ID>/status`. Expect `connected` or `disconnected`; at most three provider account GETs are attempted. Replace `<LOCAL_ACCOUNT_ID>`, never use a provider hex ID in this position.
5. If post reads are approved, use `/api/v1/zernio/accounts/<LOCAL_ACCOUNT_ID>/posts?page=1&limit=25`. Expect one provider page, potentially filtered, with `scope: provider_page`; `complete: false` or an empty filtered page does not prove absence. Do not retain private post content as evidence.
6. Optional post detail uses `/api/v1/zernio/accounts/<LOCAL_ACCOUNT_ID>/posts/<POST_ID>`, where the returned/approved post ID is 24 lowercase hex. Fictional example `cccccccccccccccccccccccc` is not a live ID. If provider detail omits membership, the fallback searches only 75 scoped posts, at most four GETs total. Safe 404 may mean access was not proven within that horizon, not universal absence.
7. If operation reads are approved, use `/api/v1/operations?limit=25`. It is local and accepts only `limit` (1-100) and optional UUID `after`, no `page` or `total`. Expect `pagination.limit`, `has_more`, `next_cursor`, `complete`. Follow `next_cursor` only while `has_more` is true. Poll `/api/v1/operations/<OPERATION_UUID>` for an existing authorized operation; this does not reconcile or call the provider.
8. For approved analytics reads, use `/api/v1/zernio/accounts/<LOCAL_ACCOUNT_ID>/posts/<POST_ID>/analytics` with `zernio.analytics.read`. Both Zernio Post IDs and Zernio ExternalPost record IDs are accepted when exactly 24 lowercase hexadecimal characters. Arbitrary native platform post IDs are not accepted by this bounded RWYS interface; this does not claim the broader upstream string schema has the same limitation. Send no query/body. Expect one provider GET with server-pinned profile/account and every target validated against current Client/account scope, with no admin bypass or generic proxy. The analytics response does not expose internal `profile_id`.

Expected result: authorized bounded reads only, matching request IDs and no-store on success/errors. Defaults are 60 reads/minute and 6 writes/minute for both Client and token, database-backed across workers, with two in-flight Client requests and a 120-second stale admission lease. Provider calls time out after 15 seconds. On 429 wait the indicated 60 seconds; do not flood polling. On 502/503 or an unexpected selector, stop and share sanitized code/request ID.

These are implemented admission rules, not a strict production guarantee from SQLite tests. Production database isolation/locking, concurrent requests across workers, and effective lease expiration require a separately approved environment check. Do not load-test the existing environment without approval.

### Postman Likes Check

Manual not executed. No real key, `.env` change, live database operation or live provider call is required for the isolated contract tests. This check instead requires separate operator approval for the actual provider read.

1. Open Postman and import `public/openapi/agent-api-v1.json`. Set the approved application origin, and disable automatic execution of write requests and redirect following.
2. Store only the RWYS token in **Postman Vault**. Open **Authorization**, select **Bearer Token**, and reference the protected secret as `{{vault:rwys-token}}`. Do not use a Zernio key, literal token in a shared environment/collection, URL, export or console log.
3. Open the imported `getAgentPostAnalytics` request or create **GET** `<APP_ORIGIN>/api/v1/zernio/accounts/<LOCAL_ACCOUNT_ID>/posts/<POST_ID>/analytics`. Replace the account with the same approved local mapping ID used above, not the provider account ID. Replace the post with an approved Zernio Post or ExternalPost record ID of exactly 24 lowercase hexadecimal characters, not a native platform post ID. Fictional examples `cccccccccccccccccccccccc` and `eeeeeeeeeeeeeeeeeeeeeeee` are not live IDs.
4. Leave **Params** empty and **Body** as **none**. Click **Send** once, then inspect the response status, matching `request_id` and `X-Request-ID`, and `Cache-Control: no-store, private`. Confirm every `platform_analytics` target is expected before inspecting metrics.
5. Inspect `data.sync_status`, `data.upstream_status`, and `data.platform_analytics[0].analytics.likes` only if entry 0 is the intended target. `likes` is an optional integer: a present `0` is measured zero; missing likes or null analytics is unavailable/unsupported, not zero. Do not insert fake values. Metrics keep documented Zernio camelCase, including optional `lastUpdated`; they are fetched/cached by Zernio, not universal live counters.
6. Treat 202 as sync pending and 424 as all platforms failed. Both carry `data`, not an error envelope, and neither demonstrates successful metrics. A 200 also requires inspecting sync/metric availability. On 402 expect only sanitized `error.code: analytics_access_required` and `error.message: Analytics access required.`; stop for operator entitlement review. Stop on 401/403/404, unexpected scope, raw upstream details or missing request IDs. Respect 429 `Retry-After: 60` rather than rapid polling.

Expected result: an authorized bounded analytics projection with `post_id`, nullable `zernio_post_id`, post `status`, `sync_status`, nullable `analytics`, `platform_analytics`, `upstream_status` (200/202/424), and `source: zernio`. Each target contains only `account_id`, `platform`, `status` (`published`/`failed`), `sync_status` (`synced`/`pending`/`unavailable`), nullable `analytics`, nullable `error_code` and nullable `is_owner`. No post text, usernames, profile ID or raw error message is exposed. Capability `zernio.read_enabled` can be true with an analytics-only grant when configured/mapped; it is not a live connectivity or subscription check.

Personal LinkedIn analytics is limited to posts published through Zernio. Platform/post support is not universal; do not enable optional publication just to obtain a metric. Authoritative source: [Zernio OpenAPI](https://zernio.com/openapi.yaml) **1.331.1**, checked **2026-10-22**, and [official docs root](https://docs.zernio.com). The one upstream call is `GET /v1/analytics` with `postId`, server-pinned `profileId` and `accountId`, not a public arbitrary query proxy. The [quickstart](external-agent-api-quickstart.md#get-likes-with-postman) and OpenAPI contain the complete field contract. Local 200/202/424/402 fake-HTTP evidence does not verify live likes or Postman behavior.

### Approved REST Consumer Check

1. In the approved REST consumer, use its **Import OpenAPI** action (or equivalent) and choose `public/openapi/agent-api-v1.json`. Do not import a modified copy containing a token.
2. Set the server/base origin to the actual approved application origin. The relative `/` server in the schema is not a remotely reachable hostname.
3. Select HTTP bearer authentication and store the RWYS token only in the consumer's protected credential store. Do not put it in chat, prompts, URLs, source, shared exports or the schema. Disable automatic execution of imported write operations.
4. From the actual off-host consumer runtime, select `getAgentContext` and send one approved request. This is an API call with local audit writes, not a provider call. Verify the approved Client ID, matching body/header request IDs and no-store.
5. If the request cannot connect, ask the operator to inspect DNS, TLS certificate trust, ingress/egress routing and network allowlists for that runtime. Do not bypass TLS, expose a private host publicly, or change network policy without separate approval.

Expected result: successful import and an authorized context response from the actual consumer location using only its protected RWYS credential. Off-host reachability, import compatibility and secret-store behavior are **manual not executed**, not inferred from local tests. On wrong Client, token leakage, unreachable origin or unexpected redirects, stop and escalate sanitized evidence.

## Conditional Instagram Scheduling

The approved caption `Test` exercise is **already completed**, as recorded above. This conditional operator procedure is retained only for separately approved future work; it does not authorize a second create. Before any future exercise, confirm code/test green and the newly approved Client, local Instagram account, public visibility, image and immutable intent record. Do not use the older immediate-text sample as an alternative retry. No direct provider-key request is permitted. Preserve the original read token unchanged, not rotated, revoked or upgraded.

### Gates And Token

1. Confirm the main task's green test results before any live action. Isolated contract verification is `php artisan test --compact tests/Feature/AgentApiDocumentationTest.php`; scheduling behavior is covered by `php artisan test --compact tests/Feature/AgentInstagramScheduleTest.php`. These use SQLite `:memory:` and fake HTTP, not the application database/provider.
2. The three config flags all default false: `AGENT_ZERNIO_ENABLED`, `AGENT_ZERNIO_PUBLISHING_ENABLED`, `AGENT_ZERNIO_INSTAGRAM_SCHEDULING_ENABLED`. Main reports current reads true and publishing false. A trusted server operator must separately approve/configure the write gates under the environment procedure; never read/print `.env` or provider credentials, and never enable them merely for a documentation check.
3. Confirm the exact local account is an active production Instagram mapping linked to the Client's exclusive active profile, with an active Client and verified owner. Instagram must be Business/Creator, not personal. Only after approval for the mapping write, use `php artisan agent:account-publishing <LOCAL_ACCOUNT_ID> --no-interaction` with the real positive local ID, not a provider ID. Expect `Account publishing enabled.` If it fails, stop rather than editing rows/identity. Trusted CLI authority is separate from assignment UI authority; assignment alone cannot mint the owner's token. The command retains four-platform text eligibility too.
4. Sign in as the existing verified Client owner, open **Settings > Agent access > My API tokens**, confirm the existing password, and create a NEW temporary token. Deselect unneeded read defaults; retain `operations.read` only if approved for polling, and any separately approved account/context reads. Check **Allow scheduled Instagram images (write access)**, leave **Allow publishing (write access)** unchecked, and **Restrict to selected accounts** with only the exact approved Instagram local mapping. Click **Create token** once. Store plaintext privately from **Your new token** and dismiss it; do not collect server provider credentials. `zernio.posts.schedule` is never a READ default and rotation adds no ability.
5. Preserve the original read token untouched. Fake-HTTP tests prove a read-only scope gets 403 on `scheduled-posts` without upload/create; do not send a live negative write unless separately approved. Capability discovery may advertise `zernio.posts.schedule` and `zernio.write_enabled` only under current gates/grants/mapping eligibility; account `write_enabled` follows platform/grant eligibility, not live connectivity.

### Freeze And Submit Once

Use one approved intent record with a stable 1..255 visible-ASCII/no-spaces `Idempotency-Key` (the sample uses a persisted UUID), exact local ID, caption, JPEG SHA-256/size and final UTC timestamp. JPEG actual bytes must be at most **2 MiB / 2097152 bytes**, dimensions each at most **4096**, at most **16000000 pixels**, width/height ratio **0.5625..1.91** inclusive. Use a generic locally approved image, no private content, embedded secrets or sensitive metadata. Do not substitute an arbitrary URL. No media file is generated by this runbook.

The body is exclusively multipart/form-data: `content`, `scheduled_for`, `image`; no query/body identity, profile, platform, draft or immediate flags. Caption is nonblank valid UTF-8, max 2200 characters, without Unicode controls including newlines. Time is exactly UTC `YYYY-MM-DDTHH:mm:ssZ`, no offset/fractions. NEW intent must be 2 minutes..24 hours ahead; the server rechecks at least 60 seconds remain before provider create, never a past-time immediate fallback.

Start Bash only after the gates. This first-submission sample computes five minutes ahead immediately before submission, then freezes it. **Do not rerun the whole block on retry:** reuse the persisted original time, file bytes and key unchanged. Store only the nonsecret printed time plus file identity/key in the approved intent record. The sample accepts a simple absolute image path without whitespace, quotes or curl-form metacharacters; move no files as part of verification. Bearer token is read silently and passed to curl only via stdin, never argv. Do not enable tracing, verbose logging, redirect following or insecure TLS.

```bash
bash
set +x
APP_ORIGIN='<APP_ORIGIN>'
read -rp 'Approved local Instagram account ID: ' LOCAL_ACCOUNT_ID
read -rp 'Persisted approved idempotency UUID: ' IDEMPOTENCY_KEY
read -rp 'Approved generic JPEG absolute path (simple path only): ' IMAGE_PATH
read -rsp 'NEW temporary RWYS scheduling token: ' RWYS_TOKEN
printf '\n'
if [[ "$LOCAL_ACCOUNT_ID" =~ ^[1-9][0-9]*$ && "$IDEMPOTENCY_KEY" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ && "$IMAGE_PATH" =~ ^/[a-zA-Z0-9_./-]+$ && -f "$IMAGE_PATH" ]]; then
    SCHEDULED_FOR=$(date -u -d '+5 minutes' '+%Y-%m-%dT%H:%M:%SZ')
    if [[ "$SCHEDULED_FOR" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]; then
        printf 'Frozen first-submission UTC time: %s\n' "$SCHEDULED_FOR"
        curl -q --config - <<EOF
url = "${APP_ORIGIN}/api/v1/zernio/accounts/${LOCAL_ACCOUNT_ID}/scheduled-posts"
request = "POST"
header = "Authorization: Bearer ${RWYS_TOKEN}"
header = "Accept: application/json"
header = "Idempotency-Key: ${IDEMPOTENCY_KEY}"
form-string = "content=Test"
form-string = "scheduled_for=${SCHEDULED_FOR}"
form = "image=@${IMAGE_PATH};type=image/jpeg"
include
silent
show-error
EOF
    fi
else
    printf '%s\n' 'Stopped: approved local ID, persisted UUID and safe existing image path required.'
fi
unset RWYS_TOKEN
```

This uses GNU `date` on the project's Linux environment; do not replace it with a stale sample timestamp. Curl supplies the multipart boundary; do not manually set Content-Type. If the first request has already been attempted, use only a separately reviewed replay of the frozen intent, never recompute its time or generate a new key.

### Interpret And Recover

HTTP 202 with safe operation `data.capability: zernio.posts.schedule`, `data.state: provider_pending` requires the exact caption, validated media identity, UTC instant and single pending Instagram target with root scheduled. This means **accepted for scheduling, not published**. An inconclusive result may instead be 202 `unknown`, as the completed test initially was. The new route never returns the upload/media URL. Record only sanitized HTTP/state, request ID, operation UUID, approved local mapping ID and nonsecret immutable intent evidence. HTTP 200 means terminal, which may be failed/cancelled, not necessarily succeeded. A read-only operation poll remains HTTP 200 and makes no provider calls or reconciliation; use the existing local poll sample below only with separately approved read access.

Preflight is at most three account GETs, then one fixed-host multipart `POST /v1/media/upload-direct`, then one `POST /v1/posts`: **five calls**, 15 seconds each, at most **75 network seconds**, not a whole-request wall-clock promise. Official unattached temporary uploads expire after **7 days** and hosting supports **4.5 MB**. Post attachment copies files to permanent media storage; the attached post asset is not covered by the temporary-upload expiry. RWYS permits only 2 MiB JPEGs and next-day scheduling. It never fetches an untrusted URL source or forwards provider secrets to third-party hosts. Encrypted execution metadata must be persisted before create. An ambiguous upload stops `unknown` without create/resend.

Media comparison uses `ZernioApiClient::isSameInstagramMediaUrl`: exact equality, or only strict HTTPS `media.zernio.com` `/temp/` to `/media/` promotion with the same ASCII basename (`[A-Za-z0-9_-]{1,251}.jpg`). The promotion exception excludes query, fragment, credentials, ports, encoding and traversal; other URL pairs require exact equality. The helper does not fetch/hash bytes or change source URL/immutable intent. For the completed image, an independent bounded CDN GET and matching SHA-256 corroborated byte identity; do not generalize this to arbitrary moved URLs or omit media-identity verification. Official permanent-copy behavior: https://docs.zernio.com/guides/media-uploads.mdx.

Local JPEG checks cover actual size, MIME, image header/dimensions and SOI/EOI markers, **not full bitmap decoding**. GD/Imagick are absent; no dependencies were added. The generated Canvas JPEG was valid, but malformed image data passing these structural checks may still be rejected by the provider. Do not claim local full-decode validation.

Permanent local deduplication uses Client/capability/key hash and immutable frozen mapping, caption, schedule, file SHA-256/size/MIME, not filename. An identical authorized replay returns the original operation even after its original deadline, without upload/create. Changed intent is 409. Stop on 401/403/404/409/422/429/502/503 or transport ambiguity; do not force retries, widen permissions or swap keys. Laravel-rendered body-limit 413 is `request_failed`; ordinary invalid/oversized JPEG validation is 422, and upstream proxy 413 may not use the app envelope.

Read-only evidence reconciliation uses exact post/scoped-list GETs with internal media validation. The URL remains encrypted/private and retained for matching; it is not exposed in public resources. Evidence reads can continue after publishing/scheduling flags or account publishing permission are revoked, but still require provider-read configuration, active Client, active unchanged mapping and current profile ownership. Published evidence requires **both root and Instagram target published plus a native HTTPS Instagram post URL**, not a lookalike hostname, accepted operation or provider post ID alone. Without a candidate or conclusive evidence, leave `unknown` for operator investigation, never automatic resend. Use recovery commands below only under their separate approval.

For any separately approved future exercise, revoke only its NEW temporary token through the owner's **My API tokens** page after recording the outcome. Restore approved write-gate/account permission state under the environment procedure; `php artisan agent:account-publishing <LOCAL_ACCOUNT_ID> --disable --no-interaction` disables any existing mapping, including an ineligible one. For the completed exercise, token 2 is already revoked and write gates/account permission are already false; do not repeat those mutations. Preserve the original read token. Revoking write permission does not cancel an accepted provider post; no cancellation endpoint is added. The completed evidence above is limited to the observed Instagram test.

Authoritative sources: https://docs.zernio.com/posts/create-post.mdx and https://docs.zernio.com/platforms/instagram.mdx, with cached https://zernio.com/openapi.yaml **1.331.1** inspected **2026-10-22**. Upstream past-time scheduling can publish synchronously, which is why RWYS enforces narrower future-time gates. The original four-platform immediate text endpoint below remains unchanged.

## 7. Rotate And Revoke The Test Token

These are separate lifecycle checks, not part of the approved scheduling test. Preserve the original read token unchanged for that test; do not apply this section to it without separate lifecycle approval.

1. Return to **Settings > Agent access > My API tokens** and find **Operator read check** under **Existing tokens**. Record its exact **Expires** timestamp without recording plaintext.
2. With approval to replace this test token, click **Rotate** once. Wait for **Your new token**. Store the replacement securely, then **Dismiss token**.
3. Confirm the replacement card's **Expires** timestamp is exactly unchanged. Rotation immediately revokes the old token; it does not grant a fresh 30 days.
4. If an API check is approved, repeat step 5 using the old token at the silent prompt. Expect 401 with no-store and matching request IDs. Repeat using the replacement; expect 200 and the same Client.
5. Click **Revoke** only on the approved test token. Wait for its card to disappear; refresh once to confirm. A check with its replacement token must now return 401.

Expected result: old token invalid immediately after rotation, replacement invalid after revocation, no redisplayed plaintext after refresh. Revocation remains supported for inactive owned Clients; their tokens cannot authenticate and **Rotate** is disabled. Do not deactivate a Client just to verify this case. Automated tests cover that behavior.

If expiry changes, the old token still succeeds, revocation fails, or plaintext reappears: stop, avoid further token creation, and escalate using sanitized evidence. Token-management browser requests require session, verified owner, password confirmation, CSRF protection and their own throttle.

## 8. Publication And Recovery Are Separate Approvals

No four-platform immediate text publication is claimed by the completed Instagram exercise. This separate conditional sample is **ONLY for separately approved text publication**, not another way to repeat the completed post. Before running it, agree the exact Client, local account, public text, immutable `Idempotency-Key`, and public visibility with the reviewer. The body accepts `content` only. Supported text limits are LinkedIn 3000, Facebook 63206, Threads 500, Bluesky 300 characters. Credentials, mapping and server gates must already be configured by the operator.

Use the exact nonsecret public sample text `Approved operator test publication` only if the reviewer approves publishing that text. Otherwise stop and have the request reviewed again; do not substitute unreviewed content. Have the operator supply a UUID generated once and persisted in the approved intent record, along with the exact account/text. The sample prompts for that existing approved UUID; it never generates a new random key on retry. Create a separately approved write token through **Settings > Agent access > My API tokens**, using **Create token**, with **Allow publishing (write access)** and `operations.read`, for the exact approved scope. Do not widen the read-only test token silently.

Start Bash, replace `<APP_ORIGIN>`, and run **only after that approval**. No body file is created; the fixed public JSON body and bearer header enter curl through stdin:

```bash
bash
set +x
APP_ORIGIN='<APP_ORIGIN>'
read -rp 'Approved local account ID: ' LOCAL_ACCOUNT_ID
read -rp 'Persisted approved idempotency UUID (reuse for this exact intent): ' IDEMPOTENCY_KEY
read -rsp 'Approved write RWYS token: ' RWYS_TOKEN
printf '\n'
if [[ "$LOCAL_ACCOUNT_ID" =~ ^[1-9][0-9]*$ && "$IDEMPOTENCY_KEY" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then
    curl -q --config - <<EOF
url = "${APP_ORIGIN}/api/v1/zernio/accounts/${LOCAL_ACCOUNT_ID}/posts"
request = "POST"
header = "Authorization: Bearer ${RWYS_TOKEN}"
header = "Accept: application/json"
header = "Content-Type: application/json"
header = "Idempotency-Key: ${IDEMPOTENCY_KEY}"
data = "{\"content\":\"Approved operator test publication\"}"
include
silent
show-error
EOF
else
    printf '%s\n' 'Stopped: a positive local ID and the persisted approved UUID are required.'
fi
unset RWYS_TOKEN
```

Expected result: 200 or 202 with a safe operation in `data`, a local UUID `data.id`, matching request IDs and no-store. Record that operation UUID and the existing immutable intent/key in the approved record, never token plaintext. A transport failure is not permission to repeat with a fresh key. On 409, changed mapping, unexpected content or ambiguous outcome, stop publication and use read-only polling/recovery. Never resend `unknown` by changing the key.

### Poll The Local Operation

Use the returned local operation UUID, not a provider post ID or an invented UUID. This separate approved read uses the same stdin-only pattern as context, performs no provider calls and does not trigger reconciliation:

```bash
bash
set +x
APP_ORIGIN='<APP_ORIGIN>'
read -rp 'Returned local operation UUID: ' OPERATION_ID
read -rsp 'RWYS token with operations.read: ' RWYS_TOKEN
printf '\n'
if [[ "$OPERATION_ID" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then
    curl -q --config - <<EOF
url = "${APP_ORIGIN}/api/v1/operations/${OPERATION_ID}"
header = "Authorization: Bearer ${RWYS_TOKEN}"
header = "Accept: application/json"
include
silent
show-error
EOF
else
    printf '%s\n' 'Stopped: use the operation UUID actually returned by the API.'
fi
unset RWYS_TOKEN
```

Expected result: 200 with the authorized operation's current local state, or safe 404 if mapping/scope no longer authorizes it. `unknown` can remain unresolved; polling is not proof of delivery. Respect rate limits and do not automate an infinite polling loop. If no operation UUID was received, stop and ask the operator to inspect approved local history/evidence rather than inventing an ID or a new publication key.

Publication performs at most three preflight GETs plus one POST, four calls total. The adapter's publish method sends POST only. An HTTP 200 terminal operation can be `failed` or `cancelled`, not just `succeeded`. A 202 may remain `unknown`. Never resend an ambiguous publication with a new key. Durable deduplication replays the same intent without another POST; changed intent is 409. Provider 207 may retain a **private** recovery candidate, not a verified public post ID.

Recovery is read-only toward the provider, but changes local evidence. Only after operator approval may these commands be run:

```bash
php artisan agent:process-webhooks --limit=25 --no-interaction
php artisan agent:reconcile-operations --limit=25 --no-interaction
php artisan agent:prune-evidence --limit=100 --batches=1 --no-interaction
```

Expected result: bounded inspection summaries, not automatic proof of publication success. Webhook processing/reconciliation default to 25 items, maximum 100. The standalone prune CLI defaults to `--limit=100 --batches=1`, permits 1-1000 rows **per evidence type per batch** and 1-10 batches, and checks a **ten-second wall-clock budget between batches**. It does not interrupt SQL already in flight or guarantee a ten-second runtime. A batch can stop the invocation early when both evidence types return fewer rows than the limit. The scheduled `--limit=1000 --batches=10` invocation can process at most **10000 rows per evidence type**, potentially fewer due to budget or eligibility; remaining backlog continues on the next scheduler tick.

The webhook processor handles at most **five operation candidates per receipt pass**, fetching six candidate IDs for lookahead and persisting its cursor. This is separate from the 25-receipt command limit. Reconciliation makes at most four GETs per operation attempt and never republishes; this is not a whole-command/receipt ceiling, and five candidates can require up to 20 provider GETs per receipt pass. These are local source/test bounds, not verified worker timing.

Raw webhook payloads older than 7 days and completed audits older than 90 days become **eligible** for redaction/pruning, subject to receipt status and processing leases. Those thresholds are not guaranteed exact expiry times: actual retention depends on cron execution, eligible backlog and database/batch budget. Durable deduplication records remain. If eligible data persists, ask the operator to inspect these factors; do not delete records manually or claim a retention deadline was met without evidence.

Recovery and retention schedules are **installed in source** in `routes/console.php`:

| Scheduled Command                                | Cadence      | Eligibility Gate                                                    | Overlap-Lock Expiry                  |
| ------------------------------------------------ | ------------ | ------------------------------------------------------------------- | ------------------------------------ |
| `agent:process-webhooks --limit=25`              | Every minute | Provider enabled and all four recovery tables exist                 | `withoutOverlapping(60)`: 60 minutes |
| `agent:reconcile-operations --limit=25`          | Every minute | Provider enabled and all four recovery tables exist                 | `withoutOverlapping(60)`: 60 minutes |
| `agent:prune-evidence --limit=1000 --batches=10` | Every minute | Only receipts and audits tables exist; independent of provider flag | `withoutOverlapping(5)`: 5 minutes   |

The two recovery schedules require `agent-api.zernio.enabled === true` and `Schema::hasTable` for `agent_operations`, `clients`, `agent_webhook_receipts`, and `agent_api_audits`; the disabled-provider check short-circuits their schema inspection. Pruning instead requires only `agent_webhook_receipts` and `agent_api_audits`, so scheduled retention remains eligible even when the provider is disabled. Overlap-lock expiry is not a command-runtime timeout or an interruption of running SQL.

For a separately approved environment review, have the operator inspect host cron/scheduler invocation, application timezone and effective gates, required tables, supported cache-lock storage and any configured asynchronous queue worker/service. Ask for sanitized runtime evidence, not secrets. Expected result: the operator can explain whether each installed schedule is actually eligible and invoked, and whether queued work has a running consumer. If evidence is missing, record **installed, runtime not verified** and stop; do not start cron, workers or recovery jobs just to close the checklist. No cron/worker, deployment, lock, or live scheduler execution checks were performed here, and source registration does not establish that schedules are deployed or active.

## What Not To Touch

- Do not reset John Karlo's or any existing user's email, role, or password; do not replace the retained application UI or modify unrelated settings.
- Do not grant John account-assignment permission or assume local `test@admin.com` exists in another environment. Its local creation/grant had separate explicit approval; the migration alone grants nothing. Do not treat the shared Agent access entry or locked assignment tab as authority. No live provider discovery was performed for this UX task.
- Do not put `ZERNIO_API_KEY` or `ZERNIO_WEBHOOK_SECRET` on the client. Operators manage those server-side. Both provider and publishing gates default false.
- Do not run migrations, seeders, provisioning, recovery, pruning, cache changes, or worker restarts without their separate approvals and required backup/review.
- Do not publish, force retries, widen account scope, enable write access, or edit provider mapping records just to make verification pass.
- Do not treat AdKit as available; it remains blocked. Narrow single-post analytics and single-JPEG Instagram scheduling are implemented, but broader reporting/analytics, media, draft creation and scheduling expansion remain pending. Reading draft states does not enable draft creation. Do not change server flags during read-only verification; the conditional scheduling procedure has separate write gates.
- Do not extend the completed owner-token/single-image Instagram evidence or earlier operator UI checks into full token lifecycle, rotation, live assignment UI, other provider/platform or deployment verification. John's earlier 403 remains historical. Do not load provider accounts during a UX-only check or change the application database to populate an empty state.

## Success Checklist

- [x] Focused isolated contract tests pass: 22 tests / 3,962 assertions.
- [x] Main-reported final regression evidence: 1094 PHP tests / 12404 assertions; 101 React tests, TypeScript, production build and Pint passed. Focused documentation rerun is separate. Fake-provider tests are not live integration or PostgreSQL concurrency proof; scoped formatting is checked separately.
- [x] One approved Instagram image/caption Test submitted through RWYS, recovered via candidate GETs without a second create, and verified published through the unchanged original read token. Temporary token 2 revoked, write gates/account permission restored false, reads true; no independent Instagram DOM or exact publication-time claim.
- [ ] Any manual environment work has explicit approval, target confirmation and appropriate backup/review.
- [ ] Existing owner identity and credentials are unchanged.
- [ ] The incremental permission migration and any operator grant were separately approved, or remain explicitly not run; no user was created or granted permission implicitly.
- [x] Approved profile migration applied after tests and fixed SQL preview on local PostgreSQL rwys_4: 39.24ms, DONE; 1 Client, 0 profiles, 0 mappings, publishing false. No reset/provider I/O or user/permission change; no real profile imported. Other environments require separate review/approval.
- [x] Actual operator profile UI readiness: canonical entry redirects to Account assignments; selecting John's Registered client enables Load Zernio profiles, native Zernio profile dropdown is empty before explicit load, and Assign profile is disabled before a choice. Both dropdowns visible at 390px with document width 375px, no overflow or old account-search field; viewport restored to 1227 x 1345. No loading/assignment/sync was clicked and no live profiles/accounts were fetched.
- [ ] Operator explicitly loads bounded official Zernio profiles, selects the exact profile and confirms exclusive ownership/import for the approved Client. Live profile listing/import/sync remains unexecuted, distinct from completed UI readiness.
- [ ] Assign profile / Sync accounts verifies a complete bounded list before local mutation; new accounts are read-only, removed/remote-inactive rows deactivate, manually inactive rows never revive. Empty profile reservation works, conflicts preserve state, and no partial claims/imports occur. These are new profile-flow checks, not covered by the historical Client dropdown browser observations.
- [x] Actual operator browser: one Agent access Settings entry redirects to Account assignments; My API tokens tab activation navigates correctly, with shared heading, Operator/Personal badges and correct active tab.
- [x] Actual operator without an owned Client sees No personal client access and Manage account assignments, with no creation form or #agent-client select.
- [x] Earlier desktop 1227 x 1345 and both pages at 390px verified; document width 375px stayed within the mobile viewport, tabs and the then-current assignment Client picker remained visible. Original viewport restored; no provider loading, assignment/token mutations or application-database changes during the UX task. This predates the dropdown change.
- [x] Actual operator dropdown desktop 1227 x 1345 and mobile 390 x 844 verified: one Registered client combobox, Select a registered client placeholder, correct Local agent test / John Karlo / johnkarlo.315@gmail.com option, no client-search input or Find clients button, mobile document width 375px within viewport 390px.
- [x] Restored desktop viewport and selected native option value 1; resulting value confirmed as 1. Request log cleared before selection and discovery filter afterward showed No requests. No provider calls, assignments, token mutations or application-database writes.
- [ ] Live multi-page Client/profile pagination remains unverified: local Client list has one page and no live profile list was loaded. Conditional Client pagination, bounded page/limit requests without search/fetch-all and stale-provider-field clearing have unit coverage; no live page-two claim. Profile dropdown readiness is browser-verified, not its live options or pagination.
- [ ] John's new manual flow redirects Agent access to My API tokens. Automated entry-redirect/role-view tests pass, but the current browser verification used the operator session, not John.
- [ ] John's assignment tab is visible but locked with an explanation and no link; direct assignment-route access still returns 403 even as admin. No global discovery or assignment authority is added; owner/token scope is correct and foreign selectors remain hidden.
- [ ] Only active owned Clients permit creation, a sole one is auto-selected, and no active owned Client shows No personal client access without a creation form. The operator CTA returns to assignments and cannot mint another owner's token.
- [ ] Only approved Clients and scopes appear; plaintext appears once and is securely stored or revoked.
- [ ] Local context identifies the correct Client with matching body/header request IDs and no-store.
- [ ] Optional provider reads are separately approved, or correctly recorded as not run/disabled.
- [ ] Rotation preserves the exact expiry and immediately invalidates the old token; revocation invalidates the replacement.
- [ ] Evidence contains only sanitized statuses, codes, request IDs and pass/fail results, no tokens, keys or private content.
- [ ] Unrun live checks and unimplemented scope are explicitly recorded, not marked complete.
- [ ] The 29-step acceptance checklist preserves all target areas, including blocked AdKit and unimplemented broader Zernio actions.
- [ ] REST import/off-host, production database concurrency and scheduler/worker runtime checks are either separately verified or explicitly marked manual not executed.

Full-target acceptance remains **NOT complete**. The missing authoritative AdKit contract and unapproved broader Zernio media, analytics/reporting, draft and scheduling actions remain blockers. Single-JPEG Instagram scheduling has the one completed live record above; narrow analytics remains locally covered, not live verified. Local tests, installed recovery schedules and that one post do not satisfy other capabilities or deployment/runtime checks. Do not silently reduce the target to the currently implemented subset.

## Escalate When

Stop and contact the maintainer/environment owner on schema ambiguity, missing approvals/backups, wrong Client, exposed credentials, missing request IDs/no-store, unexpected token validity, provider 502/503, mapping conflicts or unresolved ambiguous publication. Supply the timestamp, approved environment label, sanitized status/code and request ID only. Do not create new keys or mutate data to conceal the failure.
