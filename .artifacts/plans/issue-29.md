# Issue #29 Implementation Plan — Instagram Single-Image and Carousel Publishing

## Objective

Extend the existing authorized RWYS immediate-publish endpoint so a Client API token with `zernio.posts.publish` can publish an Instagram caption with either one JPEG image or an exactly two-image JPEG carousel. Preserve the current account-scoping, provider ownership verification, audit, idempotency, publishing safety switch, response limits, TLS verification, and sanitized error boundary. Do not add scheduling, queue controls, arbitrary provider forwarding, remote URL fetching, or provider administration.

## Verified Baseline

- Target repository: `jkcachero/rwys-4.0`, branch `factory/issue-29`, baseline commit `dec45ef` (merged PR #28).
- Immediate route: `POST /api/v1/zernio/accounts/{account}/posts`, guarded by `zernio.posts.publish`, `agent.admit`, and `agent.audit`.
- `PublishZernioPostRequest` currently accepts only `content`; `ZernioPostPublishController` forwards only content and the idempotency key.
- `ZernioBrokerWriteService::publish()` hashes/persists a text-only intent and calls `/v1/posts` without `mediaItems`.
- The separate scheduling path already contains reusable JPEG validation/storage/direct-upload behavior, but supports one image and must not be exposed as scheduling behavior on the immediate route.
- `ZernioHttpClient::uploadDirect()` already calls `POST /v1/media/upload-direct`; `createPost()` calls `POST /v1/posts` with an upstream idempotency key.
- The authoritative Zernio contract documents `mediaItems` as an array of media objects with `type` and public `url`, `publishNow: true` for immediate publication, direct media upload, per-platform `status`, and `platformPostUrl` when available. It also documents HTTP 207 for inline publication outcomes that are partial, failed, transiently scheduled, or otherwise not a simple confirmed success.
- Dependencies are not installed in this checkout, so implementation must install the locked Composer dependencies before running tests. No dependency changes are planned.

## Chosen Public Contract

Use the existing immediate route and preserve existing JSON text-only requests. Add a narrow multipart representation for Instagram media posts:

- `content`: required caption, existing 1–3000 character limit.
- `images[]`: required for the new media mode; exactly 1 or 2 uploaded JPEG files.
- `Idempotency-Key`: required header, unchanged.

One image means a single-image post; two images mean a carousel. RWYS uploads each validated file through Zernio's documented direct-upload endpoint and sends the returned HTTPS URLs as ordered `mediaItems` entries. Caller-supplied media URLs are deliberately not included in this increment: direct multipart upload meets the issue's documented upload-or-URL requirement, avoids SSRF/arbitrary fetching, and gives RWYS stable byte hashes for idempotency. Existing content-only immediate requests remain supported. If `images` are supplied, the selected account must be Instagram. Scheduling, queue, account/profile, provider payload, and unknown fields remain rejected.

## Phase 1 — Add Failing Contract and Feature Tests

Update `tests/Feature/AgentWriteApiTest.php` first, using realistic Zernio direct-upload and create-post fixtures.

Add coverage for:

1. Instagram single-image success:
   - multipart request with caption and one valid JPEG;
   - one `/v1/media/upload-direct` call;
   - one `/v1/posts` call with server-owned profile/account IDs, `publishNow: true`, no scheduling/queue fields, and one ordered `{type: image, url: ...}` media item;
   - response includes RWYS operation ID, confirmed operation status, provider publication status, and platform URL when returned.
2. Instagram two-image carousel success:
   - two ordered direct-upload calls and two ordered `mediaItems` entries;
   - caption preserved.
3. Validation failures with no provider side effects and no operation creation:
   - zero images in media form, more than two images, malformed/truncated JPEG, unsupported MIME/type, dimensions/pixel/aspect-ratio violations, missing caption, and unknown fields;
   - explicit `scheduled_for`, `scheduledFor`, `queue_id`, `queueId`, `publishNow`, `profileId`, `accountIds`, `platforms`, or arbitrary `mediaItems` inputs rejected.
4. Platform and tenancy isolation:
   - uploaded images rejected for a non-Instagram account;
   - unassigned, inactive, revoked, or token-disallowed accounts retain existing 403/404 behavior and make no upload/create calls.
5. Safety switch:
   - `services.zernio.publish_enabled=false` fails closed before operation creation, file persistence/upload, or post creation.
6. Idempotency:
   - retrying the same key, caption, and image bytes returns the same operation without duplicate uploads or post creation;
   - same key with changed caption, image bytes, image order, or image count returns `idempotency_conflict`;
   - retain the existing atomic key-claim test.
7. Provider outcomes and sanitization:
   - upstream 207/response fixtures distinguish confirmed `published`, terminal `failed`, and transient `scheduled`/pending outcomes instead of treating every accepted response as confirmed publication;
   - platform URL is exposed only when supplied in the verified target entry;
   - upstream error body, credentials, provider IDs, and raw provider messages are not exposed through the RWYS API.
8. Backward compatibility:
   - existing content-only immediate publishing and scheduling tests continue to pass unchanged.

If focused helpers become unwieldy, add unit coverage only for extracted pure media-validation/result-mapping logic; otherwise keep behavior in the feature test.

## Phase 2 — Extend Validation and Controller Input

Modify `app/Http/Requests/Api/V1/PublishZernioPostRequest.php`:

- preserve `content` and `Idempotency-Key` rules;
- allow only the `images` field in addition to `content`;
- validate `images` as an ordered list with 1–2 uploaded files when present;
- apply the established scheduled-image JPEG safety checks to every item: complete JPEG bytes, detected MIME, dimensions, megapixel ceiling, and Instagram-compatible aspect ratio;
- normalize validated uploads without accepting caller-controlled account/profile/platform/publish/schedule/queue/media payload fields.

Modify `app/Http/Controllers/Api/V1/ZernioPostPublishController.php` to pass the ordered uploaded image list into `ZernioBrokerWriteService::publish()` while retaining the existing response envelope and 202 API behavior.

Prefer a small reusable validator/service only if needed to remove literal duplication between immediate and scheduled JPEG checks; do not broaden accepted formats or refactor unrelated request behavior.

## Phase 3 — Implement Media-Aware Durable Publishing

Modify `app/Services/ZernioBrokerWriteService.php`:

1. Keep the publishing safety-switch check and authorization/ownership verification before any durable or upstream side effect.
2. Reject media publishing unless the verified local provider account platform is exactly Instagram.
3. Read each validated upload once, compute a SHA-256 hash, and build an ordered media identity before claiming the idempotency key.
4. Extend the operation's encrypted intent/recovery data with media count, ordered hashes, and controlled local paths as needed; include caption, account, platform, and ordered media hashes in the payload hash. Never include temporary provider URLs in idempotency identity.
5. Claim/create the operation before provider writes. On replay, return the existing operation before storing or uploading duplicate files.
6. Store validated files on the existing private media disk/path convention, then upload each in order through `ZernioHttpClient::uploadDirect()`.
7. Validate each upload response as a bounded HTTPS media URL. Treat malformed/missing URLs as sanitized provider errors.
8. Build the allowlisted create-post payload using server-resolved values only: profile/account/platform IDs, caption, `isDraft: false`, `publishNow: true`, and ordered `mediaItems`. Do not forward request fields.
9. Re-run authorization before the final post creation, matching the existing protection against revocation during a write.
10. Preserve the existing uncertain-write rule: after a provider write starts, ambiguous exceptions leave the operation `unknown` and retries return that operation rather than duplicating publication.
11. Map the verified selected platform result into a minimal outcome containing RWYS-safe fields such as provider publication status and `platform_url` when it is a valid URL. Mark confirmed `published` as succeeded; preserve transient pending/scheduled state; represent terminal failed/partial outcomes honestly rather than claiming publication.
12. Keep persisted `ProviderPost` status/timestamps synchronized from the verified provider response.

Modify `app/Zernio/ZernioHttpClient.php` only as required to accept the documented inline publication response statuses (including 207) while retaining existing timeout, TLS, response-size, and sanitized exception behavior.

## Phase 4 — Update the OpenAPI Contract

Modify `openapi/agent-api-v1.yaml`:

- document that the immediate endpoint accepts the existing JSON text-only request and the new multipart Instagram media request;
- define `images` as one or two binary JPEG items and `content` as the caption;
- keep `additionalProperties: false` on explicit schemas;
- document single-image versus two-image carousel semantics, Instagram-only media behavior, required idempotency header, 202 operation response, safety-switch failure, validation errors, conflicts, and sanitized provider failures;
- document `outcome.provider_status` and optional `outcome.platform_url` without exposing provider credentials or unrestricted provider response shapes;
- state clearly that scheduling, queue controls, provider IDs, arbitrary media URLs/payloads, and provider-management operations are unsupported.

Update OpenAPI contract assertions in `tests/Feature/AgentReadApiTest.php` if required so route/ability/schema parity and response-shape checks continue to enforce the curated surface.

## Phase 5 — Configuration Guidance

Update the existing project-facing configuration documentation or example environment comments only where this repository already documents Zernio setup; do not create a new documentation file unless necessary. Explain:

- `ZERNIO_AGENT_API_ENABLED=true` and `ZERNIO_AGENT_PUBLISH_ENABLED=true` are required server-side gates;
- configuration must be reloaded with the project's normal cache-clear/rebuild deployment procedure (for Laravel, `php artisan config:clear` during local verification or `php artisan config:cache` in deployment);
- enabling the switch permits writes but does not bypass Client token abilities or account authorization;
- live tests require explicit operator approval and an explicitly approved Instagram account.

Do not alter defaults or silently enable publishing.

## Verification Commands

Run from `rwys-4.0`:

```bash
composer install --no-interaction
php artisan test --compact tests/Feature/AgentWriteApiTest.php
php artisan test --compact tests/Feature/AgentReadApiTest.php
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/AgentWriteApiTest.php tests/Feature/AgentReadApiTest.php
```

If a focused unit test is added, run it individually before the combined feature tests. After formatting, inspect `git diff --check` and `git status --short`.

Do not run seeders. Do not perform live publication during normal implementation. Mocked tests prove the RWYS contract and orchestration, not real Instagram publication.

## Operator-Gated Live Verification

Only after explicit operator approval, confirmed target account, and deliberate publishing-switch enablement:

1. Use an authorized Client token against the actual RWYS endpoint—not a direct Zernio call.
2. Publish one image reading `API test` with an unmistakable API-test caption.
3. Publish a two-image carousel whose images read `API test`, with an unmistakable API-test caption.
4. Use unique idempotency keys and never retry an `unknown` result automatically.
5. Confirm the operation IDs, operation states, provider publication statuses, and platform URLs when available; independently verify the Instagram posts if possible.
6. Publish only to the approved Instagram account; do not publish to Facebook and do not schedule.
7. Restore the publishing switch to its operator-requested state and reload configuration.

Report live verification as blocked/unverified unless that approval and account information are supplied during execution.

## Risks and Mitigations

- **Duplicate posts after ambiguous responses:** claim idempotency before uploads/writes, persist ordered media identity, preserve `unknown`, and never auto-retry uncertain provider writes.
- **Idempotent replay after temporary upload URLs expire:** replay the durable operation/result without re-uploading; hashes, not URLs, define request identity.
- **SSRF or unrestricted provider access:** accept uploaded bytes only, never fetch caller URLs, and construct provider payloads from an allowlist.
- **Memory/storage pressure:** retain strict per-file limits and image dimensions; read each bounded file once; clean up newly stored files if failure occurs before any provider effect where the existing lifecycle permits safe cleanup.
- **Misreporting accepted versus published:** interpret provider post/platform statuses and 207 responses; expose confirmed state and URL only when present.
- **Authorization revoked mid-flight:** preserve the second authorization check before create-post.
- **Breaking existing text publishing:** keep `images` optional and retain current JSON request behavior/tests.

## Assumptions

- The existing immediate route is the correct public surface; no new route or token ability is needed.
- JPEG-only input is acceptable for the first increment because it matches the repository's existing validated Instagram upload path and direct-upload client behavior.
- A carousel is exactly two images for this issue.
- Direct upload satisfies the issue's requirement for documented media upload or URL handling; public media URLs can be a later separately reviewed feature.
- Provider response fixtures will follow the authoritative Zernio schema available during implementation, especially `post.platforms[].status` and `platformPostUrl`.

## Rejected Alternatives

- **Reuse the scheduled-post endpoint:** rejected because scheduling is explicitly out of scope and uses a different ability/safety gate.
- **Accept raw Zernio payloads or arbitrary `mediaItems`:** rejected because RWYS must remain a curated authorization and validation boundary.
- **Fetch caller-provided remote URLs:** rejected due to SSRF, content-validation, stability, and idempotency risks.
- **Add a new Instagram-specific token ability:** rejected because `zernio.posts.publish` already represents the approved immediate-publish capability and account authorization remains independently enforced.
- **Treat every 2xx/202 as successful publication:** rejected because Zernio documents non-confirmed and partial inline outcomes, and the issue requires confirmed status rather than acceptance alone.

## Completion Criteria

- One- and two-image Instagram posts pass focused automated tests with exact allowlisted provider calls.
- Invalid media, non-Instagram media attempts, cross-account access, disabled publishing, conflicting retries, schedule/queue fields, and sanitized failures are covered.
- Existing text publishing, scheduling, authorization, auditing, idempotency, and OpenAPI parity remain green.
- Formatting and diff checks pass.
- No live write occurs without explicit operator approval; any missing live proof is reported honestly.
