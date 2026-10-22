# Client AI Agent Walkthrough

Status: Intended workflow for the fresh implementation in issue #25.

Use this guide to understand how a Client's own AI agent, such as OpenCode or Codex, should use RWYS. This is a product and verification walkthrough, not a claim that the fresh-master implementation or any manual/live check has been completed.

## Quick Answer

The Client uses their own AI and model subscription. RWYS provides authorized REST tools, not the AI itself. The Client supplies only their RWYS token and the approved application origin; upstream provider credentials stay on the RWYS server.

```text
Client asks their AI
    -> AI calls a documented RWYS endpoint with the Client's RWYS token
    -> RWYS checks Client, profile, account and operation permissions
    -> RWYS calls Zernio or an implemented AdKit operation
    -> Structured results return to the AI
    -> AI explains the result to the Client
```

## Normal Flow

```text
Operator assigns a provider profile
    -> Client owner creates a scoped RWYS token
    -> Client configures OpenCode/Codex securely
    -> Agent checks context and capabilities
    -> Agent performs approved bounded reads
    -> Optional separately authorized write
    -> Agent polls the operation and reports evidence
    -> Client rotates or revokes access when needed
```

## Before You Start

- Application origin: `<APP_ORIGIN>` means the actual approved RWYS origin, including scheme and port, without a trailing slash. Use trusted HTTPS outside an explicitly approved local exception.
- Account: an existing verified RWYS Client owner. Being an admin does not grant another Client's provider accounts.
- Provider setup: an authorized operator must configure server-side provider access and assign the Client's profile before provider operations can work.
- Agent: OpenCode, Codex or another client with an approved HTTP-capable tool or terminal. Do not assume every client supports OpenAPI import.
- Credentials: store the RWYS token in the agent/tool's protected credential mechanism, outside conversation text, source files and shared exports.
- Workspace: configure the Client's own agent environment. Do not give a Client agent the RWYS server checkout, database access or upstream secrets as a shortcut.
- Contract: the implementation must publish its OpenAPI artifact and tool instructions. Use only operations that the actual contract and capability response expose.
- Sample read request: "Show my recent Instagram posts and the likes on the latest one."
- Sample write request: "Schedule this approved image on my Instagram five minutes from now with the caption 'Test'." This example is not approval to perform a live write.
- Stop when: the origin, Client, account, permission or effect is unclear; credentials appear in output; or the response is uncertain.

## 1. Operator Assigns The Profile

1. The separately authorized operator opens **Settings > Agent access > Account assignments**.
2. In **Registered client**, the operator selects the exact RWYS Client and verifies the displayed owner.
3. After approval for provider discovery, the operator clicks **Load Zernio profiles**.
4. The operator selects the approved **Zernio profile**, verifies its identity, and clicks **Assign profile** after confirming the receiving Client.
5. RWYS verifies the profile and completely imports its account mappings within the documented bounds, or rejects the assignment without partial ownership.

Expected result: a profile containing Instagram, Facebook and two other social accounts belongs to that Client. Other Clients cannot claim the same profile or access those accounts. Assignment does not enable publishing.

New connections to that profile should be imported using the operator's explicit **Sync accounts** action. Account-restricted tokens must not automatically gain a newly imported account.

If this fails: stop for operator review. Do not guess IDs, choose an account by display name alone, collect the Client's provider key or force a reassignment.

## 2. Client Creates A Token

1. The Client signs in to RWYS using their own existing credentials.
2. Open **Settings > Agent access > My API tokens** and confirm the existing password if requested.
3. Select the owned Client, or verify the sole automatically selected Client.
4. Enter a descriptive token name, such as `Codex read access`.
5. Select the minimum approved read permissions and, if appropriate, restrict the token to selected assigned accounts.
6. Leave publishing and scheduling write permissions unchecked for the first read-only test.
7. Click **Create token** once.
8. Store the one-time plaintext token securely before dismissing it or leaving the page.

Expected result: a finite-lived Client-bound token with explicit abilities. The Client can select only owned Clients and permitted mapped accounts. The operator cannot mint a token for a different owner merely by selecting that owner in assignment UI.

Do not put the token in chat, screenshots, repository files, URL parameters or logs. The implementation should store only its hash. Refreshing the page must not redisplay the secret.

If this fails: check the session, recent authentication and selected scope once, then stop. Do not repeatedly submit an uncertain issuance request or bypass ownership checks.

## 3. Configure OpenCode Or Codex

Configure these values through the selected agent/tool's actual supported settings:

| Setting | Purpose |
| --- | --- |
| RWYS origin | The approved application origin, represented here as `<APP_ORIGIN>` |
| RWYS bearer credential | The scoped token, supplied securely to the HTTP tool rather than pasted into conversation text |
| OpenAPI contract or tool instructions | Named operations, input schemas, abilities, limits and response meanings |

If environment variables are the approved integration mechanism, examples are `RWYS_ORIGIN` for the origin and `RWYS_AUTH` for the bearer credential. Ensure the tool actually receives them; a terminal variable does not necessarily propagate to an already-running agent or a filtered tool environment. Never dump the environment to troubleshoot credentials.

The HTTP tool should construct requests using:

```http
Authorization: Bearer <securely supplied RWYS token>
Accept: application/json
```

Use the real client's documented OpenAPI import mechanism only if it exists. Otherwise provide the agent with the documented REST operations and an approved HTTP tool. Do not invent a native connector or configuration screen.

Expected result: the agent can make a bounded RWYS request from its actual runtime using only the RWYS token. The Client continues using their own AI provider/subscription; RWYS does not need those model credentials.

If the agent cannot reach the origin: stop for approved DNS, TLS and network review. A cloud agent cannot be assumed to reach a private developer hostname. Do not expose a private server, bypass TLS globally or request upstream credentials as a workaround.

## 4. Ask A Read Question

Example Client prompt:

```text
Show my recent Instagram posts and the likes on the latest one.
Use my configured RWYS tools. Do not publish or modify anything.
```

The agent should follow the documented workflow:

```http
GET /api/v1/context
GET /api/v1/zernio/accounts
GET /api/v1/zernio/accounts/{account}/posts?limit=5
GET /api/v1/zernio/accounts/{account}/posts/{post}/analytics
```

1. Check that context identifies the expected Client and that the required capabilities are available.
2. List authorized account mappings. Use the returned local account ID, not an invented provider ID or a display name as authority.
3. If more than one account matches the requested platform, ask the Client to choose. Do not silently use the first account.
4. Read one bounded page of posts from the selected account.
5. Use a returned, authorized post ID to request analytics.
6. Summarize only supplied metrics, including sync status and freshness when available.

Expected result: RWYS resolves the Client from the persisted token, verifies current profile/account ownership and every returned target, calls the provider, and returns safe structured data. An AI's statement that an account belongs to the user is not authorization.

Missing likes, null analytics or a pending response must not be described as zero likes. A present measured zero is different from unavailable data. Provider platform/subscription limits still apply.

If this fails: stop on an unexpected Client, unauthorized account or private data. Follow the documented error meaning rather than widening scope or trying random IDs.

## 5. Request An Optional Write

Example Client prompt, only after approval of the exact account, image and caption:

```text
Schedule this approved image on my Instagram five minutes from now
with the caption 'Test'. Use RWYS and report the operation result.
```

1. Check whether the actual RWYS capability response and OpenAPI expose this operation.
2. Require an explicit scheduling write ability, eligible mapped account and the necessary operator/server publishing gates. Read-only access must remain unable to publish.
3. If new rights are needed, obtain a separately approved token through normal protected management. Do not modify a read token or use the server's provider key directly.
4. Calculate the future UTC instant at actual submission time and freeze it with the exact account, caption and image as one logical intent.
5. Generate and retain one idempotency key for that intent.
6. Submit the documented multipart request:

```http
POST /api/v1/zernio/accounts/{account}/scheduled-posts
Authorization: Bearer <securely supplied RWYS token>
Idempotency-Key: <one persistent key for this intent>
```

The multipart body contains exactly:

| Field | Value |
| --- | --- |
| `content` | The approved caption, for example `Test` |
| `scheduled_for` | The approved future UTC instant in the documented format |
| `image` | The approved JPEG file within the API's size/dimension limits |

Expected result: RWYS validates and durably records the intent, rechecks current authority, uploads/schedules through Zernio and returns an authorized operation. Zernio performs the actual scheduling and publication; RWYS does not run a separate publishing scheduler.

The agent must not interpret HTTP 200/202 or a provider ID alone as proof of publication. If submission times out or becomes uncertain, preserve the intent/key and investigate the operation. Never send another create with a fresh key merely to obtain a successful-looking response.

## 6. Poll And Explain The Outcome

Using the operation ID actually returned by RWYS:

```http
GET /api/v1/operations/{operation}
```

1. Poll within the documented budgets; honor backoff and avoid an infinite loop.
2. Report the current state accurately: pending/executing, provider-pending, succeeded, failed, cancelled or unknown.
3. Distinguish a scheduled provider post from a published post.
4. Report publication only when the documented evidence supports it, including matching provider/target status and native publication evidence where required.
5. If unknown persists, stop further writes and ask for operator investigation using the safe operation/request IDs.

Expected result: local operation polling returns evidence without recreating the post or implicitly performing another provider write. Backend reconciliation may update evidence through bounded provider reads according to the documented implementation.

Do not invent an operation ID if the submission did not return one. Do not automatically change the key, caption, image or schedule to work around an uncertain result.

## 7. Rotate Or Revoke Access

1. Open **Settings > Agent access > My API tokens**.
2. Find the specific token used by the agent.
3. To rotate, click **Rotate**, store the replacement securely and update the agent's credential configuration. The old token must stop working; rights and original expiry must remain unchanged.
4. To remove access, click **Revoke** and verify a subsequent authenticated request is rejected.
5. Revoke temporary write tokens after the approved test and restore temporary account/server write permissions through the separately authorized operator process.

Expected result: the agent loses future API authority when the token is revoked. It cannot grant itself new permissions.

Important: revocation or turning local publishing switches off does not automatically cancel a post already accepted by Zernio. Cancellation/deletion is a separate operation requiring its own support, permission and approval.

## Example Agent Instructions

The Client may give OpenCode or Codex this read-only task after secure tool configuration:

```text
Use the configured RWYS REST tools and protected bearer credential.
Never print credentials, dump the environment, put secrets in URLs,
or call Zernio/AdKit directly with server credentials.

First request RWYS context and report my Client and capabilities.
If authorized, list my mapped accounts. Ask me to choose if multiple
accounts match the platform I requested.

Read at most five posts from the selected account. For the selected
post, request analytics and report likes only when supplied, together
with sync/freshness information when available. Missing is not zero.

Do not publish, schedule, change permissions or alter assignments.
If access or configuration is missing, stop and explain the blocker.
Use only documented endpoints, respect limits and never invent data.
```

Write delegation must be a separate, explicit request with the required token scope and exact approved effect. Do not add standing publication authority to the read-only instructions.

## What Not To Touch

- Do not give the Client agent provider keys, RWYS database access, developer-only tools or the server's secret-bearing workspace.
- Do not let an admin role or a model-generated confirmation bypass Client/profile/account ownership.
- Do not enable publishing just to get an analytics result or make a read test pass.
- Do not reset users, seed production credentials, run destructive migrations or change profile ownership as part of Client agent setup.
- Do not retry uncertain writes with new keys or report a scheduled/draft post as published.
- Do not expose a private application publicly or disable TLS verification globally to make an external client connect.
- Do not advertise unsupported AdKit/Zernio operations as available. The provider contract and implemented capability response remain authoritative.

## Success Checklist

- [ ] The operator assigns the exact approved profile to the correct RWYS Client.
- [ ] The Client receives only a scoped RWYS token; provider credentials remain server-side.
- [ ] The actual OpenCode/Codex tool runtime reaches the approved origin securely.
- [ ] Context identifies the expected Client and truthful capabilities.
- [ ] Only authorized mapped accounts/posts/metrics are returned, including for admin-owned tokens.
- [ ] The agent uses bounded requests and distinguishes unavailable/pending metrics from zero.
- [ ] Read-only tokens cannot perform writes or manage account assignments.
- [ ] Any separately approved write uses one immutable intent/key and truthful operation reporting.
- [ ] Rotation/revocation works without automatic scope escalation or duplicate effects.
- [ ] Unexecuted manual/live checks remain explicitly unverified.

## Stop And Escalate

- **401:** check token validity/session assumptions once, then stop. Do not request upstream credentials.
- **403:** required ability or operation authority is missing; do not widen it automatically.
- **404:** resource access was not established; do not enumerate IDs to bypass the boundary.
- **402 / pending / unavailable analytics:** explain entitlement or availability instead of fabricating metrics.
- **409:** preserve the original intent and key; investigate the conflict without creating another effect.
- **422:** correct the documented input only with approval of any changed write intent.
- **429:** honor Retry-After/backoff rather than flooding or rotating tokens.
- **502/503, timeouts or unknown write outcomes:** stop new writes, retain safe correlation/operation IDs and use documented recovery.
- **Credential exposure, wrong Client or cross-Client data:** stop immediately and revoke affected access under the approved process.

Share sanitized statuses, request IDs, operation IDs and approved evidence during investigation, never bearer values, provider keys, signed URLs or unrelated private content.

## Planning Reference

Fresh implementation specification: https://github.com/jkcachero/rwys-4.0/issues/25.

This walkthrough describes intended behavior for that implementation. The implementation must supply its own tests, operator guide and actual acceptance evidence. Saving this guide does not execute the workflow, grant a live provider action or establish deployment/client compatibility.
