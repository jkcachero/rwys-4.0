# Personal AI Connections Operator Walkthrough

Status: Runbook. Automated HTTP/model tests use fakes. Actual provider consent, subscription eligibility, billing and browser layout require the manual checks below; they are not established by a passing test suite.

## Quick Answer

The application now uses a Laravel loopback callback for eligible OpenAI subscription OAuth, not the experimental Node device-code companion. OpenAI and Claude API-key connections use each signed-in user's own encrypted credentials. A saved API key is not called validated until a genuine response completes.

Start the callback listener before signing in with ChatGPT. The Laravel app, browser and listener must run on the same machine using the same checkout, database, encryption key and persistent cache. A remote hosted app requires a separately approved topology; its `127.0.0.1` is not the browser's machine.

**Dashboard** is a left-aligned overview. **Chat** is a separate sidebar destination at `https://rwys-4.test/chat`: its history rail stays left, while the conversation is centered in the remaining space. The model menu and thinking-level submenu sit in the bottom message composer, not a separate top form. Connection setup lives in **Settings > AI connections**; choose a provider first, then **ChatGPT subscription** or **API key**. Chat does not have live AdKit/Zernio tools and cannot post or create ads.

Chat sessions and messages now persist privately in the database for each user. Titles and message content are encrypted at rest. Only the authenticated owner can list, reopen, send to or delete a conversation, including when a different user is an Admin. Actual browser/provider checks below remain manual.

## Normal Flow

```text
Sign into RWYS -> Settings > AI connections -> choose provider and connection type
    -> API key: Save API key -> Test connection
    OR
    -> Start local Artisan listener -> Connect with ChatGPT
        -> Continue with ChatGPT -> provider consent -> loopback callback
    -> Chat sidebar -> New chat -> composer model menu
        -> choose connection -> model -> Thinking level -> Send
    -> reopen Recent chats after refresh
```

## Before You Start

- Application origin resolved from this checkout: `https://rwys-4.test`. Confirm it with `php artisan config:show app.url` if your environment differs.
- Existing local user: `johnkarlo.315@gmail.com`, displayed as **John Karlo**. Use the existing password. This implementation did not reset passwords, change roles or run seeders. The approved local bootstrap default `password` only applies if that is how your account was created; do not reset the database to log in.
- Personal connections are available to every authenticated, verified user; Admin is not required. Keep provider account ownership and RWYS role separate.
- Run commands from the repository root. Existing `provider_connections`, `ai_connections`, `chat_conversations` and `chat_messages` migrations must be applied. `php artisan migrate:status --no-interaction` confirmed the chat migration was already applied in the inspected local database. If a required migration is pending in another environment, review and approve its normal non-destructive migration; do not use `migrate:fresh` or reset user data.
- Cache must be persistent and shared by the web request and CLI listener. This checkout resolves `cache.default` to `database`; `array` would not share authorization state across processes. Do not print cache contents or encryption keys.
- Existing Valet serving stays unchanged. No Node companion or publicly exposed token relay is needed.
- Live model calls may consume API money or subscription allowance. Only press a provider test/sign-in/Send control after approving that account, auth mode, model and a bounded test. Start with one tool-free request, not advertising operations.

## 1. Refresh Local Assets And Configuration

These are the requested local verification commands:

```bash
npm run build
php artisan config:clear
```

Open `https://rwys-4.test/dashboard` and refresh the page. If Vite development mode is running, the corresponding dev process must serve the current source; a build does not replace a running stale dev process.

Expected result: Dashboard is a short overview with **Chat** and **AI connections** links. Click **Chat** in the sidebar for a left history rail and centered conversation in the remaining area. The bottom rounded composer contains the compact model menu and Send icon; the previous top picker form, extra AI connections buttons and request-ID footer are gone. Credential setup stays in Settings. With no valid connection/model selected, sending is disabled.

If this fails: check that the browser and build use this checkout, refresh once, and report the actual error. Do not remove authentication, CSRF or credential protection.

## 2. Open Your Personal Connections

1. Sign in using your existing `johnkarlo.315@gmail.com` account.
2. Click **AI connections** on Dashboard, or open **Settings > AI connections** at `https://rwys-4.test/settings/connections`. Chat itself does not repeat connection-management controls.
3. Under **Provider**, choose **OpenAI** or **Claude**.
4. Under **Connection type**, OpenAI offers **ChatGPT subscription** and **API key**. Claude offers **API key** with an explicit subscription-OAuth limitation.
5. Choose **API key** to show the labeled key form. Choose **ChatGPT subscription** to show its connection status/actions instead. There is no need to scroll through an unrelated form to find the selected method.

Expected result: one shared provider configuration renders consistent shadcn controls, only the chosen method and your safe connection metadata. No stored API key or OAuth token is displayed. Use **Chat** in the sidebar to return to the separate conversation page.

If this fails: report whether it is a route, verification or rendering problem. Do not grant everyone Admin or change roles to bypass a personal-connection error.

## 3. Manually Test OpenAI Subscription OAuth

This section is a real provider sign-in. It requires your explicit account/plan-consent approval; the agent did not perform it.

1. If an old Node companion is running in a terminal, stop that process with **Ctrl+C** in its own terminal. Do not kill unrelated processes or start the retired companion again.
2. In a separate terminal at the Laravel repository root, run:

    ```bash
    php artisan openai:oauth-listen
    ```

3. Wait for the terminal message saying it is listening on `127.0.0.1:1455` for up to 600 seconds. Keep the terminal open. If the port is occupied, stop and identify the correct process rather than killing it blindly.
4. In **Settings > AI connections**, choose **OpenAI**, then **ChatGPT subscription**. Expand **Local setup instructions** if needed. Click **Connect with ChatGPT**.
5. Wait for the link **Continue with ChatGPT**. Click it; it opens the official authorization page in a new tab. There is no device code to copy and no API key required for this route.
6. Confirm the intended OpenAI account/workspace and review the app identity and requested plan-use permission. Decline and stop if the account, workspace, requested effect or provider origin is wrong.
7. If eligible and approved, complete provider consent. The browser must return to `http://127.0.0.1:1455/auth/callback` for the default configuration. Do not replace `127.0.0.1` with `localhost` or the Valet site URL.
8. Wait for the callback page's safe completion message. Click **Return to connections**, or return to the original tab. The originating tab polls your own attempt every two seconds for up to five minutes and should update to connected. Refresh once if you completed sign-in after polling ended.

Expected result: the listener handles only the callback, exits after a successful/declined callback, and the correct RWYS user's subscription connection becomes available. No token/code is printed to the terminal or UI. A valid identity login without the required inference grants is rejected rather than counted as an eligible connection.

If you see **ChatGPT device sign-in could not be started**, the old device-code UI or process is still in use. Stop and recheck the checkout/assets; the new connection path does not call the device-auth endpoint.

If authorization is declined, expires, fails identity validation or does not grant plan usage, the UI shows a safe failure and keeps the requirement unverified. Close the old provider tab, restart the listener if it exited, then start a fresh attempt only after reviewing the reason. Never substitute an API key or import a Codex/browser session to force subscription success.

### Alternative Local Port

The port is configurable, not universally fixed to 1455. Only change it deliberately if needed:

1. Configure `OPENAI_OAUTH_REDIRECT_URI=http://127.0.0.1:54321/auth/callback` in the local environment through your approved configuration process.
2. Run `php artisan config:clear`.
3. Run `php artisan openai:oauth-listen --port=54321`.
4. Start a NEW authorization attempt. Its authorize request and code exchange use the same saved redirect URI. An already-started attempt must not be repointed at another port.

Expected result: the listener and configured callback use exactly the same unprivileged port/path. A mismatched port, wrong host, malformed callback or unrelated request is rejected without dispatching another Laravel route.

## 4. Manually Test A Personal API-Key Connection

Use only a provider key you explicitly approve for this application and its account/billing scope. Do not paste it into an issue, screenshot, model prompt, shell command or chat message.

1. Open **Settings > AI connections**.
2. Choose **OpenAI** or **Claude** under **Provider**, then **API key** under **Connection type**.
3. Enter **Personal OpenAI** or **Personal Claude** in **Connection label**.
4. Enter the provider key in the masked **API key** field.
5. If you have an approved available model ID, expand **Advanced options** and enter it under **Model (optional)**. Otherwise confirm the application's provider default is available for your account before making a model call.
6. Click **Save API key**.

Expected result: the key field clears on successful save and the entry is shown as configured/not tested. Saving does not call a model or fabricate a validation timestamp. Errors preserve useful feedback without flashing the key into the session or returning it to the browser.

7. Only after approving one model request for this account, click that entry's **Test connection**. It requests the bounded tool-free reply **RWYS connection test passed.**

Expected result: an actual completed response marks that owner entry tested and updates its validation time. Invalid credentials, a model you cannot access, refusal or incomplete output cause a safe error, not a false success or a fallback to someone else's key. API-key output defaults to 512 tokens, or 4,096 for effort-capable models, with a server configuration ceiling of 8,192; model/account limits still apply. The subscription route does not send unsupported token-limit fields and instead retains its timeout, context and application call limits.

Claude API access is supported separately from OpenAI subscription OAuth. The application does not provide a generic Claude.ai consumer-subscription OAuth flow; the UI explains the official support limitation. A Claude API-key success must not be reported as completed Claude subscription OAuth.

## 5. Send A Real Tool-Free Question

This section consumes the selected provider account's API/subscription allowance. Approve one request first.

1. Click **Chat** in the main sidebar, or open `https://rwys-4.test/chat`. Connection settings no longer occupy the conversation page.
2. Click **New chat** in the history rail. On a narrow screen, open **Chat history** first. Creating a session does not call a model.
3. Open **Choose model** in the bottom composer. Under **Connection**, choose the intended personal API entry or **OpenAI subscription (OAuth)**. Nothing silently chooses a shared Admin credential.
4. Wait for **Models** to load from that connection, then choose an available model. The menu trigger now displays its human-readable name. Open the menu again and use **Thinking level** to choose **Provider default** or a verified supported level.
5. Enter this exact prompt in the message field:

    ```text
    Reply with exactly: RWYS chat connection works.
    ```

6. Click the arrow button labeled **Send** once, or press **Enter**. **Shift+Enter** adds a line break; input-method composition is not submitted prematurely. Wait for completion or the explicit error state. Do not repeatedly send to work around a provider limit.

Expected result: your question and actual model answer appear with distinct **You** and **Assistant** labels. Your selected credential/auth mode is used. Subscription inference discovers eligible models using that same account and requires a completed Responses event before claiming success. A failed/incomplete/refused request is not displayed as a successful empty answer.

Refresh behavior: the URL contains `?conversation=<UUID>`. Refreshing reopens that owner's saved session rather than losing it. The selected connection/model/thinking level are restored when still available; a removed model or revoked connection must be reselected, not silently substituted. Live AdKit/Zernio reads, posting and ad creation remain unavailable. Do not interpret general model answers as live account data.

### Model And Thinking Availability

- **Refresh models** is inside the model menu. Catalogs are short-lived and owner-scoped, and the backend rechecks current availability before inference. A failed refresh does not fall back to old data or another account.
- OpenAI subscription models come from its eligible account catalog. API-key OpenAI models additionally intersect a maintained server policy of officially reviewed Responses-compatible text models in `config/workspace.php`; that policy controls endpoint compatibility/display names, NOT reasoning levels. Arbitrary image/audio/embedding or unreviewed model IDs are not offered.
- OpenAI reasoning choices come from each returned model's `supported_reasoning_levels` metadata, not a per-model fixed list. Claude discovery excludes deprecated/retired models and dynamically reads the provider's explicitly supported effort capabilities. New advertised effort names remain selectable without adding a model-name mapping.
- A standard OpenAI API catalog does not guarantee reasoning metadata. If the selected provider/model does not publish configurable levels, the menu shows **Provider default** and **No reasoning levels were advertised for this model**. That is missing advertised metadata, not a claim that the model cannot reason. Do not invent levels or import another account's catalog to fill the gap. Use **Refresh models** after an implementation update or provider capability change.
- Switching models resets the thinking choice. High effort can increase latency/allowance use; it does not expose or save hidden chain-of-thought. Unsupported model/level combinations are rejected server-side before generation.
- If a previously selected model disappears, Send stays disabled until a valid selection is made. Do not manually override the catalog or alter billing methods to bypass that state.

### Verify Saved Sessions And Context

1. After the approved first response, confirm **Recent chats** contains the first-question title.
2. Refresh the page. Confirm the same question, answer and session UUID return without another inference call.
3. If approving another small model request, ask `What exact phrase did I ask you to reply with?` in that same session. The backend sends only bounded completed history from this owned session.
4. Click **New chat** and confirm a separate empty session, not a copy of the previous transcript. Reopen the first entry to return to it.
5. Use **Load more chats** when the session list has another page, and **Load older messages** when a transcript exceeds the latest message window. These are database reads, not model requests.
6. To remove a disposable session, click its delete icon, review **Delete conversation?**, then choose **Delete conversation**. **Cancel** leaves it unchanged. Deletion removes that session's stored messages, not provider credentials or another conversation.

Expected result: saved messages stay private to the owner and remain after a reload; another user/Admin cannot open their UUID. Full history is stored, but each model turn receives at most 20 recent completed messages and 16,000 characters from the current session, not unlimited context or another session's text. Failed attempts stay visible and are not passed as completed context. Do not paste credentials into chat text merely because storage is encrypted.

If a response is lost in transit, the application reconciles the original request UUID against saved messages. It must not display a recovered completed reply as failure and invite an automatic second paid request. If the result is still uncertain, inspect/reload the saved session before explicitly trying again; the same unresolved request ID is retained while the page remains mounted. Do not use a full reload as proof that an unknown provider request never happened.

## 6. Disconnect Or Revoke Deliberately

1. For an API-key entry, click its **Revoke** button. This clears the saved application credential and removes it from usable chat choices; it does not revoke the provider's API key globally. Revoke the key in the provider console separately if that is your intended cleanup.
2. For OpenAI subscription OAuth, click **Disconnect OpenAI subscription**. The backend requests revocation through the official discovery endpoint and removes the local connection only after confirmation.

Expected result: the correct user's connection is no longer selectable. A remote-revocation failure is shown honestly and retains the evidence/connection for retry; do not assume the remote session was revoked. If necessary, disconnect the app in ChatGPT Settings and then retry the application cleanup. No secret values should appear in the error.

If this fails: do not delete arbitrary database rows or another user's credentials to make the status green. Report the provider/mode and safe error category, without tokens or private response bodies.

## 7. Review Layout And Ownership

1. Check the dashboard and settings in light and dark mode at desktop, tablet and mobile widths.
2. Navigate **Chat** and **Settings > AI connections** by keyboard. Open the model dropdown, change the connection/model, navigate the **Thinking level** submenu, and confirm that unavailable choices and errors are understandable. Change provider/type and local setup/advanced options in Settings separately.
3. Confirm that the history rail stays left, is 256 pixels wide on desktop and 288 pixels on larger screens, and the reading lane is centered within the remaining area. Below the desktop breakpoint, use **Chat history** rather than squeezing the transcript beside a wide rail.
4. Hover a long recent-chat title, then focus that same item with **Tab**. The row remains truncated, but its tooltip and accessible name expose the entire stored title. The delete icon remains a separate action, not part of the tooltip trigger.
5. Confirm the bottom composer remains accessible. Its model menu opens above it, and older messages are not forcibly scrolled away while you read them. Do not expect fake attachment controls or a made-up context percentage; only implemented controls are shown.
6. If two authorized test users are available, sign into each in separate browser profiles. Each must see only their own connections and cannot test/revoke the other's IDs. Do not create real users or expose real keys merely to take a screenshot.

Expected result: Dashboard, Chat and AI connections have distinct purposes; the connection method hierarchy is clear and provider forms share one implementation. If actual browser behavior has not been observed, mark these checks manual/unverified rather than inferring them from SSR tests.

## Automated Verification

```bash
php artisan test --compact
npx vitest run
node --test tools/chatgpt-companion/connector.test.mjs tools/chatgpt-companion/server.test.mjs
npm run types:check
npm run check
vendor/bin/pint --dirty --format agent
npm run build
php artisan config:clear
```

PHPUnit is configured for SQLite `:memory:` with an array test cache/session. The Node module tests are isolated legacy/helper/retirement checks; they do not demonstrate live OAuth. Frontend transport/state and PHP OAuth/gateway tests use fakes, not real sign-ins. Keep any pre-existing unrelated formatting failures visible and separate from the feature's scoped results.

## What Not To Touch

- No `migrate:fresh`, production resets, seeding/password changes or real-user role updates for this walkthrough.
- Do not run the retired Node companion or paste tokens from another tool.
- Do not disable CSRF, identity/signature/nonce/scope validation, ownership checks or browser origin protections.
- Do not test posting, ad creation, ad activation/spending, webhook registration or unrelated account access. Those remain separately scoped and authorized provider milestones.
- Do not label API-key access as subscription OAuth, or model-only answers as live advertising/social data.

## Success Checklist

- [ ] Updated chat-first assets and local config are loaded.
- [ ] Ordinary verified users can view only their own redacted connections.
- [ ] The supported loopback listener starts and the documented sign-in completes for the approved account, or exact eligibility/consent blockers remain explicit.
- [ ] Saving an API key does not claim validation; an approved real test changes only the correct owner's metadata.
- [ ] An approved tool-free question returns actual model output through the selected connection, with no hidden credential fallback.
- [ ] Model and thinking choices use current supported catalog data; unsupported or removed selections do not silently fall back.
- [ ] Private sessions reopen after refresh, restore permitted settings, and cannot be read by another user/Admin.
- [ ] New chat, older-message/session pagination, failure recovery and confirmed history-only deletion behave as described.
- [ ] Wrong/expired credentials and incomplete/refused responses remain safe visible errors.
- [ ] Revocation/disconnect outcome and any unconfirmed remote revocation are accurately reported.
- [ ] Mobile/desktop/theme/keyboard checks are actually observed or explicitly left unverified.

## Escalate And Stop When

- Account/workspace, billing mode, callback origin or selected connection is unexpected.
- A token, key, code or private provider response appears in logs/UI/evidence.
- A callback requires disabling validation, using a different client's credentials or calling an undocumented device flow.
- Provider eligibility/usage limits or remote revocation cannot be confirmed.
- The UI claims advertising tools/live data that are not configured, or any external side effect occurs during a tool-free model test.

## UI And Capability References

The composer/menu and transcript follow the installed Radix/shadcn design language, using these official patterns rather than installing a second AI backend or copying demo-only behavior:

- https://ui.shadcn.com/docs/ai-elements/radix/prompt-input.md
- https://ui.shadcn.com/docs/ai-elements/radix/message.md
- https://ui.shadcn.com/docs/ai-elements/base/chat
- https://developers.openai.com/api/reference/resources/models/methods/list
- https://platform.claude.com/docs/en/api/models/list
- https://platform.claude.com/docs/en/build-with-claude/effort
- https://developers.openai.com/api/docs/models/gpt-6.1-sol.md
- https://github.com/openai/codex/blob/main/codex-rs/protocol/src/openai_models.rs documents the official client model-metadata shape, including advertised reasoning presets. This is a schema reference only: it does not authorize a Codex credential/token workaround or guarantee every Models API response includes those fields.

Model catalog presence is not a guarantee that a paid generation will succeed; entitlement, provider limits and runtime changes can still fail. Such failures must remain safe errors, not verified successes. Browser layout and live provider acceptance remain manual until actually observed.
