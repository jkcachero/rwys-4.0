import { createServer } from 'node:http';
import { execFile } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { loadConnection, createAuthorizationUrl, createPendingAuthorization, exchangeCode, invoke, refreshConnection, saveConnection, CALLBACK_PATH, ISSUER } from './connector.mjs';

const port = Number(process.env.CHATGPT_COMPANION_PORT ?? 8787);
const configuredHostId = process.env.CHATGPT_EXT_AGENT_HOST_ID;
const storePath = process.env.CHATGPT_COMPANION_STORE;
const keyPath = process.env.CHATGPT_COMPANION_KEY;
const proxyToken = process.env.CHATGPT_COMPANION_PROXY_TOKEN ?? randomBytes(32).toString('base64url');
const discoveryUrl = `${ISSUER}/.well-known/openid-configuration`;
const deviceSessions = new Map();

const jsonResponse = (response, status, body, headers = {}) => {
    const payload = JSON.stringify(body);
    response.writeHead(status, {
        'access-control-allow-credentials': 'true',
        'access-control-allow-headers': 'authorization, content-type',
        'access-control-allow-methods': 'GET, POST, OPTIONS',
        'access-control-allow-origin': process.env.CHATGPT_COMPANION_ALLOWED_ORIGIN ?? 'null',
        'cache-control': 'no-store',
        'content-type': 'application/json',
        ...headers,
    });
    response.end(payload);
};

const readBody = async (request) => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
};

const openBrowser = (url) => {
    const command = process.platform === 'darwin' ? 'open' : process.platform === 'win32' ? 'cmd' : 'xdg-open';
    const args = process.platform === 'win32' ? ['/c', 'start', '', url] : [url];
    execFile(command, args, { windowsHide: true }, (error) => {
        if (error) console.error(`Open this URL in your browser:\n${url}`);
    });
};

async function discover() {
    const response = await fetch(discoveryUrl);
    if (!response.ok) throw new Error('OpenAI OAuth discovery failed.');
    const data = await response.json();
    if (data.issuer !== ISSUER || !data.authorization_endpoint || !data.token_endpoint) {
        throw new Error('OpenAI OAuth discovery response was not trusted.');
    }
    return data;
}

async function login() {
    const provider = await discover();
    let previous;
    try {
        previous = await loadConnection(storePath, keyPath);
    } catch (error) {
        if (error.code !== 'ENOENT') throw error;
    }
    const hostId = configuredHostId ?? previous?.hostId ?? randomBytes(16).toString('hex');
    const callbackPort = Number(process.env.CHATGPT_COMPANION_CALLBACK_PORT ?? 0);
    const pending = createPendingAuthorization({
        clientId: previous?.clientId ?? 'dynamic_agent_client',
        hostId,
        redirectUri: `http://127.0.0.1:${callbackPort || port}${CALLBACK_PATH}`,
    });
    const callback = await new Promise((resolve, reject) => {
        const server = createServer((request, response) => {
            const url = new URL(request.url ?? '/', pending.redirectUri);
            if (request.method !== 'GET' || url.pathname !== CALLBACK_PATH) {
                response.writeHead(404).end();
                return;
            }
            if (url.searchParams.get('state') !== pending.state) {
                response.writeHead(400).end('Invalid sign-in state.');
                return;
            }
            response.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' });
            response.end('<h1>Sign-in received</h1><p>Return to the local companion.</p>');
            server.close();
            if (url.searchParams.get('error')) reject(new Error(url.searchParams.get('error_description') ?? url.searchParams.get('error')));
            else if (!url.searchParams.get('code')) reject(new Error('ChatGPT did not return an authorization code.'));
            else resolve({ code: url.searchParams.get('code'), clientId: url.searchParams.get('client_id') ?? pending.clientId });
        });
        server.listen(callbackPort, '127.0.0.1', () => {
            const address = server.address();
            const actualPort = typeof address === 'object' && address ? address.port : callbackPort;
            pending.redirectUri = `http://127.0.0.1:${actualPort}${CALLBACK_PATH}`;
            const url = createAuthorizationUrl({
                authorizationEndpoint: provider.authorization_endpoint,
                clientId: pending.clientId,
                redirectUri: pending.redirectUri,
                state: pending.state,
                nonce: pending.nonce,
                verifier: pending.verifier,
                hostId,
                loginHint: previous?.email,
            });
            console.log(`Opening ChatGPT sign-in for host ${hostId}.`);
            openBrowser(url.toString());
        });
        server.on('error', reject);
    });
    const connection = await exchangeCode({
        tokenEndpoint: provider.token_endpoint,
        issuer: provider.issuer,
        jwksUri: provider.jwks_uri,
        pending,
        code: callback.code,
        clientId: callback.clientId,
    });
    await saveConnection(connection, storePath, keyPath);
    console.log('ChatGPT connection saved locally.');
}

async function currentConnection(provider) {
    let connection = await loadConnection(storePath, keyPath);
    if (connection.expiresAt < Date.now() + 60_000) {
        connection = await refreshConnection(connection, { tokenEndpoint: provider.token_endpoint });
        await saveConnection(connection, storePath, keyPath);
    }
    return connection;
}

function requestToken(request) {
    const authorization = request.headers.authorization;
    if (authorization === `Bearer ${proxyToken}`) return true;
    return request.headers.cookie?.split(';').some((cookie) => cookie.trim() === `rwys_companion_token=${proxyToken}`) ?? false;
}

async function requestDeviceCode() {
    const response = await fetch(`${ISSUER}/api/accounts/deviceauth/usercode`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json' },
        body: JSON.stringify({ client_id: 'dynamic_agent_client' }),
    });
    const data = await response.json();
    if (!response.ok || typeof data.user_code !== 'string' || typeof data.device_auth_id !== 'string') {
        throw new Error(data.error_description ?? 'ChatGPT device sign-in could not be started.');
    }
    return data;
}

async function pollDeviceCode(session) {
    const response = await fetch(`${ISSUER}/api/accounts/deviceauth/token`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json' },
        body: JSON.stringify({
            device_auth_id: session.deviceAuthId,
            user_code: session.userCode,
        }),
    });
    const data = await response.json();
    if (response.status === 403 || data.status === 'pending') return { status: 'pending' };
    if (!response.ok || typeof data.authorization_code !== 'string' || typeof data.code_verifier !== 'string') {
        throw new Error(data.error_description ?? 'ChatGPT device authorization failed.');
    }
    const pending = createPendingAuthorization({
        clientId: 'dynamic_agent_client',
        hostId: session.hostId,
        redirectUri: `${ISSUER}/deviceauth/callback`,
    });
    pending.verifier = data.code_verifier;
    const connection = await exchangeCode({
        tokenEndpoint: session.provider.token_endpoint,
        issuer: session.provider.issuer,
        jwksUri: session.provider.jwks_uri,
        pending,
        code: data.authorization_code,
        clientId: 'dynamic_agent_client',
    });
    await saveConnection(connection, storePath, keyPath);
    return { status: 'connected', connection: { hostId: connection.hostId, expiresAt: connection.expiresAt } };
}

async function serve() {
    const server = createServer(async (request, response) => {
        if (request.method === 'OPTIONS') {
            jsonResponse(response, 204, {}, { 'access-control-allow-origin': request.headers.origin ?? 'null' });
            return;
        }
        try {
            if (request.method === 'POST' && request.url === '/v1/chatgpt/login') {
                const device = await requestDeviceCode();
                const sessionId = randomBytes(18).toString('base64url');
                const provider = await discover();
                deviceSessions.set(sessionId, {
                    deviceAuthId: device.device_auth_id,
                    expiresAt: Date.now() + Number(device.expires_in ?? 900) * 1000,
                    hostId: configuredHostId ?? randomBytes(16).toString('hex'),
                    provider,
                    userCode: device.user_code,
                });
                jsonResponse(response, 200, {
                    expiresIn: Number(device.expires_in ?? 900),
                    sessionId,
                    userCode: device.user_code,
                    verificationUrl: device.verification_url ?? `${ISSUER}/codex/device`,
                }, {
                    'access-control-allow-origin': request.headers.origin ?? 'null',
                    'set-cookie': `rwys_companion_token=${proxyToken}; HttpOnly; SameSite=Lax; Path=/; Max-Age=900`,
                });
                return;
            }
            if (request.method === 'GET' && request.url?.startsWith('/v1/chatgpt/login/')) {
                const sessionId = request.url.split('/').pop();
                const session = deviceSessions.get(sessionId);
                if (!session || session.expiresAt < Date.now()) {
                    deviceSessions.delete(sessionId);
                    jsonResponse(response, 410, { error: 'This ChatGPT sign-in request expired.' });
                    return;
                }
                const result = await pollDeviceCode(session);
                if (result.status === 'connected') deviceSessions.delete(sessionId);
                jsonResponse(response, 200, result, { 'access-control-allow-origin': request.headers.origin ?? 'null' });
                return;
            }
            if (!requestToken(request)) {
                jsonResponse(response, 401, { error: 'Companion authorization is required.' });
                return;
            }
            if (request.method === 'GET' && request.url === '/v1/chatgpt/status') {
                const connection = await loadConnection(storePath, keyPath);
                jsonResponse(response, 200, { connected: true, hostId: connection.hostId, expiresAt: connection.expiresAt });
                return;
            }
            if (request.method === 'POST' && request.url === '/v1/chatgpt/infer') {
                const body = await readBody(request);
                if (typeof body.prompt !== 'string' || body.prompt.trim() === '' || body.prompt.length > 10_000) {
                    jsonResponse(response, 422, { error: 'prompt must be a non-empty string of at most 10000 characters.' });
                    return;
                }
                const provider = await discover();
                const connection = await currentConnection(provider);
                jsonResponse(response, 200, await invoke(connection, body.prompt.trim()));
                return;
            }
            jsonResponse(response, 404, { error: 'Not found.' });
        } catch (error) {
            jsonResponse(response, 502, { error: error instanceof Error ? error.message : 'Companion request failed.' });
        }
    });
    server.listen(port, '127.0.0.1', () => {
        console.log(`ChatGPT companion listening on http://127.0.0.1:${port}`);
        console.log(`Use Authorization: Bearer ${proxyToken}`);
    });
}

const command = process.argv[2] ?? 'serve';
if (command === 'login') await login();
else if (command === 'serve') await serve();
else {
    console.error('Usage: node tools/chatgpt-companion/server.mjs [login|serve]');
    process.exitCode = 1;
}
