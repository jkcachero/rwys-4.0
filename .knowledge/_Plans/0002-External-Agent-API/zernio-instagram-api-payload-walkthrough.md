# Zernio Instagram API Payload Walkthrough

Status: Historical walkthrough of the approved local test on 2026-10-22 UTC.

This document records how the Client token was used for reads and how a separate authorized scheduling token was used to create the Instagram test post. It is distinct from [the intended Client AI-agent workflow](client-ai-agent-walkthrough.md) and is not a claim that a fresh-master implementation contains this feature.

**Do not replay these historical write requests.** The post exists, the timestamp is in the past, and the temporary write token is revoked. A past schedule sent directly to Zernio can cause immediate publication. Saving this document does not approve another upload, post or cleanup action.

All bearer values, session credentials, CSRF tokens and the uploaded asset URL are redacted. Request shapes and the stated non-secret identifiers come from the test; multipart boundaries below are placeholders rather than raw wire captures. Saving the walkthrough does not reverify the current remote post state.

## Quick Answer

The original RWYS token was read-only. It was used to inspect Client context, authorized account mappings and the published post. It was not upgraded or used to publish.

The scheduling request used a separate temporary token created through John Karlo's normal owner login, password confirmation and protected token-management route. That token was restricted to local Instagram account 1 and was revoked afterward.

```text
Client -> RWYS: scoped RWYS bearer credential
RWYS -> Zernio: server-side Zernio API key
Zernio -> Instagram: provider-managed scheduling and publication
```

RWYS does not forward its Client token to Zernio. It resolves and validates local authority first, then uses the provider credential held on the server.

## Credentials And Scope

| Credential | Purpose |
| --- | --- |
| Original RWYS read token | Client context, mapped-account list and published-post detail |
| Temporary RWYS scheduling token | One approved scheduling submission, restricted to local account 1 |
| Owner web session and CSRF token | Protected creation/revocation of the temporary token after login and password confirmation |
| Server-side Zernio API key | Laravel's requests to Zernio; never returned to the Client/browser |

The original token's read abilities were:

```text
context.read
zernio.accounts.read
zernio.posts.read
zernio.analytics.read
operations.read
```

## 1. Read Client Context

The original RWYS token was sent to the local RWYS origin:

```http
GET /api/v1/context
Accept: application/json
Authorization: Bearer <ORIGINAL_RWYS_READ_TOKEN>
```

There was no JSON body and no provider request for this call.

RWYS checked the persisted token, hash, expiry, abilities, verified owner and Client binding. The result identified Client 1 with read capabilities and no write capability.

Observed result: HTTP 200, matching response/header request IDs and `Cache-Control: no-store, private`.

## 2. Read Authorized Account Mappings

```http
GET /api/v1/zernio/accounts?limit=5
Accept: application/json
Authorization: Bearer <ORIGINAL_RWYS_READ_TOKEN>
```

This endpoint read local authorized mappings, not Zernio's global account list.

| RWYS Local Account ID | Platform |
| --- | --- |
| 1 | Instagram |
| 2 | Facebook |

For the Instagram target, the server-side mapping was:

```json
{
  "local_account_id": 1,
  "client_id": 1,
  "zernio_profile_id": "69f50705b51d7a351a664fb9",
  "zernio_account_id": "6a87467b77555aae0177c94d"
}
```

The Client selected local account 1. RWYS resolved the upstream profile/account from its canonical ownership records; the caller did not supply arbitrary provider IDs as authority. The profile assignment was a prerequisite, not repeated by these token-test requests.

Observed result: HTTP 200 with two mappings and complete local pagination.

## 3. Authorize A Temporary Scheduling Token

The original read token could not create another token or acquire scheduling rights. Instead, the separately authorized John Karlo owner session completed normal login and password confirmation.

The protected management request used session authentication and CSRF protection:

```http
POST /settings/agent-tokens
Accept: application/json
Content-Type: application/json
X-CSRF-TOKEN: <SESSION_CSRF_TOKEN>
Cookie: <OWNER_SESSION_COOKIE>
```

Payload:

```json
{
  "client_id": 1,
  "name": "Temporary Instagram Test - single approved schedule",
  "abilities": [
    "context.read",
    "operations.read",
    "zernio.posts.schedule"
  ],
  "allowed_account_ids": [1]
}
```

The response created temporary token ID 2. Its one-time plaintext value stayed inside the isolated browser execution and is not recorded here.

The server publishing switch, Instagram scheduling feature switch and publishing permission on local account 1 were temporarily enabled for the approved test. Facebook was not enabled or targeted. These changes did not alter the original read token's abilities.

## 4. Submit The Scheduled Post To RWYS

The test image was a genuine browser-generated JPEG: white background, centered black `Test` text, 1080x1080 pixels and 13,149 bytes. The approved caption was exactly `Test`.

```http
POST /api/v1/zernio/accounts/1/scheduled-posts
Accept: application/json
Authorization: Bearer <TEMPORARY_RWYS_SCHEDULING_TOKEN>
Idempotency-Key: 0467e2f7-6423-41b6-867a-fafa8680b1da
Content-Type: multipart/form-data; boundary=<GENERATED_BOUNDARY>
```

Multipart fields:

| Field | Type | Actual Value |
| --- | --- | --- |
| `content` | Text | `Test` |
| `scheduled_for` | Text | `2026-10-22T16:44:25Z` |
| `image` | File | `test.jpg`, JPEG, 13,149 bytes |

RWYS validated the exact scheduling ability, current verified principal, Client/profile/account ownership, account narrowing, publishing gates, image constraints and future UTC schedule.

Before provider effects, it persisted an immutable operation:

```text
01a1647b-4ef7-7349-9320-b3c31eb8bd4f
```

The ledger bound the target, caption, schedule, image hash/size/type and logical idempotency key. Raw image bytes were not stored in the operation record. Upload execution metadata was encrypted separately from immutable intent.

## 5. Preflight The Zernio Target

The bounded account preflight used server-resolved identifiers:

```http
GET https://zernio.com/api/v1/accounts?profileId=69f50705b51d7a351a664fb9&page=1&limit=25&search=6a87467b77555aae0177c94d
Accept: application/json
Authorization: Bearer <SERVER_ZERNIO_API_KEY>
```

There was no JSON body. RWYS required the exact account, expected profile/platform and active connection indication, then rechecked local authority before proceeding.

The server key authenticated RWYS to Zernio. It did not replace the Client-token ownership and permission checks.

## 6. Upload The JPEG To Zernio

```http
POST https://zernio.com/api/v1/media/upload-direct
Accept: application/json
Authorization: Bearer <SERVER_ZERNIO_API_KEY>
Content-Type: multipart/form-data; boundary=<GENERATED_BOUNDARY>
```

Multipart parts:

```text
contentType: image/jpeg

file:
  filename: 01a1647b-4ef7-7349-9320-b3c31eb8bd4f.jpg
  Content-Type: image/jpeg
  contents: <THE APPROVED JPEG BYTES>
```

The filename is an attribute of the `file` part, not a separate text field. The upload used the same approved JPEG bytes received by RWYS.

Zernio returned an image URL, represented here as `<ZERNIO_TEMP_IMAGE_URL>`. RWYS validated it and durably stored it as encrypted execution metadata before creating the post. The server key was not sent to a CDN/storage URL or exposed to the browser.

## 7. Send The Create Payload To Zernio

Headers:

```http
POST https://zernio.com/api/v1/posts
Accept: application/json
Content-Type: application/json
Authorization: Bearer <SERVER_ZERNIO_API_KEY>
Idempotency-Key: 01a1647b-4ef7-7349-9320-b3c31eb8bd4f
```

Actual JSON shape and values, with the uploaded asset URL replaced by a placeholder:

```json
{
  "content": "Test",
  "mediaItems": [
    {
      "type": "image",
      "url": "<ZERNIO_TEMP_IMAGE_URL>"
    }
  ],
  "platforms": [
    {
      "platform": "instagram",
      "accountId": "6a87467b77555aae0177c94d"
    }
  ],
  "scheduledFor": "2026-10-22T16:44:25Z",
  "timezone": "UTC",
  "isDraft": false,
  "publishNow": false
}
```

Important translations:

- RWYS `scheduled_for` becomes Zernio `scheduledFor`.
- Local account 1 becomes the mapped Zernio connected-account ID.
- The Client's logical key is bound to the local ledger; the upstream create uses the operation UUID as its idempotency key.
- Profile identity is used for authorization and preflight, not invented as a root field in this create payload.
- `isDraft: false` and `publishNow: false` request a real future schedule, not a draft or immediate-publication fallback.
- Zernio manages the publication timer. No separate RWYS publishing scheduler was used.

Only one provider upload and one create request were submitted for the logical operation.

## 8. Resolve The Initial Unverified Result

The RWYS scheduling request returned HTTP 202 with an operation initially marked `unknown`. This was not treated as success or permission to resend.

Zernio copied the image from temporary `/temp/` storage to permanent `/media/` storage, preserving its filename. The conservative URL equality check initially withheld verification because the returned URL differed from the original upload reference.

No second create request was sent. Read-only verification used:

```http
GET https://zernio.com/api/v1/posts/6ada3c3fa5b451177b6651ad
Accept: application/json
Authorization: Bearer <SERVER_ZERNIO_API_KEY>
```

The matching post evidence included the approved caption, UTC schedule, observed profile, sole Instagram account target and one image. A separate bounded GET of the promoted public CDN image, without provider credentials, returned 13,149 bytes whose SHA-256 matched the approved original image.

The bridge's media matching was tightened to support only the specific trusted same-host HTTPS `/temp/<basename>.jpg` to `/media/<identical-basename>.jpg` promotion, while rejecting unrelated hosts, filenames, directories, credentials, queries, fragments and traversal. It did not modify the frozen intent or use broad same-basename matching.

Targeted reconciliation used GET evidence only:

```bash
php artisan agent:reconcile-operations --operation=01a1647b-4ef7-7349-9320-b3c31eb8bd4f --limit=1 --no-interaction
```

Historical command shown for explanation, not authorization to execute recovery or another effect now.

The verified final evidence was:

```json
{
  "operation_state": "succeeded",
  "provider_post_id": "6ada3c3fa5b451177b6651ad",
  "post_status": "published",
  "instagram_target_status": "published",
  "url": "https://www.instagram.com/p/DezcZvwl9xq/"
}
```

The scheduled instant is recorded; the test did not establish an exact publication second from a general updated timestamp. Native Instagram-page rendering was not independently checked by logging into Instagram.

## 9. Read The Published Post With The Original Token

The unchanged original read token retrieved the published post through RWYS:

```http
GET /api/v1/zernio/accounts/1/posts/6ada3c3fa5b451177b6651ad
Accept: application/json
Authorization: Bearer <ORIGINAL_RWYS_READ_TOKEN>
```

RWYS resolved local account ownership, requested the provider detail, revalidated the token/current scope after provider I/O, and authorized every returned target before disclosure.

Observed result: HTTP 200 at 2026-10-22 16:51:04 UTC. Relevant response fields:

```json
{
  "data": {
    "id": "6ada3c3fa5b451177b6651ad",
    "content": "Test",
    "status": "published",
    "scheduled_for": "2026-10-22T16:44:25.000Z",
    "platforms": [
      {
        "account_id": "6a87467b77555aae0177c94d",
        "platform": "instagram",
        "status": "published",
        "url": "https://www.instagram.com/p/DezcZvwl9xq/"
      }
    ]
  }
}
```

This is a selected-field excerpt, not the entire response. The full response also included source identity/timestamps and a request ID matching its response header, with `Cache-Control: no-store, private`.

Likes/analytics were not fetched as part of this posting test. A successful post-detail read must not be described as verified live analytics.

## 10. Revoke Temporary Access

The temporary token was revoked through protected owner management:

```http
DELETE /settings/agent-tokens/2
Accept: application/json
X-CSRF-TOKEN: <SESSION_CSRF_TOKEN>
Cookie: <OWNER_SESSION_COOKIE>
```

Observed result: HTTP 204. Final cleanup checks confirmed:

- Temporary scheduling token ID 2 no longer existed.
- Original read token ID 1 remained unchanged and performed the post-detail read successfully.
- Local Instagram account publishing permission was restored to false.
- Server publishing and Instagram scheduling switches were restored to false.
- Provider reads remained enabled.
- Temporary browser auth credentials were deleted and the isolated browser session was closed.
- There was one scheduling operation and no second create request.

Restoring switches and revoking the token did not cancel or delete the accepted provider post. The test post was left in place; deletion/unpublishing would require a separate approved operation.

## Evidence Boundaries

- Verified: one approved local Client-to-RWYS-to-Zernio scheduled JPEG operation, provider-reported publication with matching Instagram target/native URL, and original-token post-detail access.
- Not claimed: all platforms, generic media types, live likes, independent Instagram browser rendering, precise delivery latency, production concurrency or production deployment.
- Local image validation checks MIME, headers, dimensions and truncation markers, not full bitmap decoding. The test file itself was genuinely browser-encoded and its promoted bytes were verified.
- Local TLS certificate verification was bypassed only under the user's explicit local-test approval. Upstream provider requests did not disable TLS verification.
- No raw bearer value, server API key, password, session cookie, CSRF token or signed storage URL is stored in this walkthrough.

## Code References

- `app/Http/Controllers/AgentApiController.php`: account-scoped reads, fresh post-read authorization and the scheduled-post entry point.
- `app/Services/AgentOperationService.php`: immutable scheduling intent, execution admission, upload metadata, deduplication and evidence-based reconciliation.
- `app/Zernio/Services/ZernioApiClient.php`: fixed-origin upload/create/read translation, exact provider fields and strict media-promotion matching.
- `app/Services/AgentTokenService.php`: Client-bound issuance, explicit ability/account restrictions and revocation.
- `app/Http/Requests/ScheduleInstagramPostRequest.php`: closed multipart input contract.

These references describe the implementation used for the historical test. They are not a statement that those files or behavior exist in a fresh-master implementation.
