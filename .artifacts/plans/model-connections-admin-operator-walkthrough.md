# RWYS provider connections operator walkthrough

This walkthrough verifies the provider-connection UI and local OAuth handoff without performing a live provider sign-in or model request.

## Preconditions

- Run from the `rwys-4.0` checkout on `factory/issue-11`.
- Keep the existing Valet site unchanged.
- The configured admin identity is the email in `.env` under `ADMIN_EMAIL`; do not print or change `ADMIN_PASSWORD`.
- The application URL is the value returned by `php artisan config:show app.url` (the checked-in default is `http://localhost`).
- No API key is an OAuth substitute. Claude remains unavailable in this connection flow because consumer-subscription OAuth is unsupported; Claude API-key setup is outside this walkthrough.

## Verify the local callback listener

Start the one-shot listener in a separate terminal before opening the browser:

```bash
php artisan openai:oauth-listen
```

Expected behavior:

- It binds only to `127.0.0.1:1455`.
- It accepts one `GET /auth/callback?...` request and then exits.
- It rejects non-loopback hosts and ports other than `1455`.
- The browser callback is exactly `http://127.0.0.1:1455/auth/callback`.

The listener is intentionally short-lived and is not a public relay. The Valet application URL is unchanged; the loopback listener only receives the provider redirect and returns the Laravel callback response.

## Verify the UI

1. Open the resolved application URL and sign in as the configured admin identity.
2. Open **Settings → Connections**.
3. Confirm the page renders provider cards from `config/workspace.php`, including labels, supported authentication methods, capabilities, model, and connection state.
4. Confirm OpenAI exposes **Connect with OpenAI** and Claude explains that no supported consumer-subscription authentication method is configured.
5. Start the OpenAI connection only when the account is eligible and the operator has approved a live sign-in.
6. After a successful callback, confirm the OpenAI card shows **Connected** and exposes **Test connection** and **Disconnect**.
7. Confirm a non-admin verified user receives HTTP 403 for the connections page and connection actions.

## Live verification boundary

The automated suite uses HTTP fakes and does not sign in, exchange a real code, call a real model, incur provider spend, publish content, or touch AdKit/Zernio. A live operator run must separately verify:

- the selected OpenAI account is the intended account;
- the required direct-inference entitlement is granted;
- the displayed model is allowed by RWYS configuration;
- a bounded tool-free test returns the exact requested response;
- the safe request/correlation ID is recorded without tokens or authorization headers;
- disconnect removes the saved provider connection and subsequent inference is unavailable.

If the account lacks the required entitlement, callback, refresh, or inference must remain unavailable with the safe UI error; do not fall back silently to a demo provider or API-key path.

## Verification commands

```bash
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test --compact tests/Feature/ProviderConnectionTest.php
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test --compact tests/Feature/WorkspaceTest.php
npm run check
npm run types:check
npx vitest run
npm run build
vendor/bin/pint --dirty --format agent
```

The temporary `APP_KEY` above is for local test execution only and must not be committed.
