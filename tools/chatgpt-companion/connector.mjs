import { createCipheriv, createDecipheriv, createHash, createPublicKey, randomBytes, verify } from 'node:crypto';
import { chmod, mkdir, readFile, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

export const ISSUER = 'https://auth.openai.com';
export const RESOURCE = 'https://api.openai.com/v1';
export const SCOPES = 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct';
export const CALLBACK_PATH = '/auth/callback';
export const DEFAULT_STORE = join(homedir(), '.rwys-chatgpt-companion', 'connection.json.enc');
export const DEFAULT_KEY = join(homedir(), '.rwys-chatgpt-companion', 'connection.key');

const json = (value) => JSON.stringify(value);
const randomValue = () => randomBytes(32).toString('base64url');

export function createPkceChallenge(verifier) {
    return createHash('sha256').update(verifier).digest('base64url');
}

export function createAuthorizationUrl({ authorizationEndpoint, clientId = 'dynamic_agent_client', redirectUri, state, nonce, verifier, hostId, loginHint }) {
    const url = new URL(authorizationEndpoint);
    url.search = new URLSearchParams({
        client_id: clientId,
        response_type: 'code',
        redirect_uri: redirectUri,
        scope: SCOPES,
        resource: RESOURCE,
        state,
        nonce,
        code_challenge_method: 'S256',
        code_challenge: createPkceChallenge(verifier),
        ext_agent_host_id: hostId,
        ...(clientId === 'dynamic_agent_client' ? { agent_name_hint: 'RWYS AI Workspace' } : {}),
        ...(loginHint ? { login_hint: loginHint } : {}),
    }).toString();
    return url;
}

export function createPendingAuthorization({ redirectUri, clientId, hostId }) {
    return {
        clientId,
        hostId,
        redirectUri,
        state: randomValue(),
        nonce: randomValue(),
        verifier: randomValue(),
    };
}

export async function loadOrCreateKey(path = process.env.CHATGPT_COMPANION_KEY ?? DEFAULT_KEY) {
    try {
        const key = await readFile(path);
        if (key.length !== 32) throw new Error('The companion key must contain 32 bytes.');
        return key;
    } catch (error) {
        if (error.code !== 'ENOENT') throw error;
        await mkdir(dirname(path), { recursive: true, mode: 0o700 });
        const key = randomBytes(32);
        await writeFile(path, key, { mode: 0o600 });
        await chmod(path, 0o600);
        return key;
    }
}

export async function saveConnection(connection, storePath = process.env.CHATGPT_COMPANION_STORE ?? DEFAULT_STORE, keyPath) {
    const key = await loadOrCreateKey(keyPath);
    const iv = randomBytes(12);
    const cipher = createCipheriv('aes-256-gcm', key, iv);
    const ciphertext = Buffer.concat([cipher.update(json(connection), 'utf8'), cipher.final()]);
    const payload = {
        version: 1,
        iv: iv.toString('base64url'),
        tag: cipher.getAuthTag().toString('base64url'),
        ciphertext: ciphertext.toString('base64url'),
    };
    await mkdir(dirname(storePath), { recursive: true, mode: 0o700 });
    await writeFile(storePath, json(payload), { mode: 0o600 });
    await chmod(storePath, 0o600);
}

export async function loadConnection(storePath = process.env.CHATGPT_COMPANION_STORE ?? DEFAULT_STORE, keyPath) {
    const key = await loadOrCreateKey(keyPath);
    const payload = JSON.parse(await readFile(storePath, 'utf8'));
    if (payload.version !== 1) throw new Error('Unsupported companion connection format.');
    const decipher = createDecipheriv('aes-256-gcm', key, Buffer.from(payload.iv, 'base64url'));
    decipher.setAuthTag(Buffer.from(payload.tag, 'base64url'));
    return JSON.parse(Buffer.concat([
        decipher.update(Buffer.from(payload.ciphertext, 'base64url')),
        decipher.final(),
    ]).toString('utf8'));
}

async function verifyIdentityToken(idToken, { issuer, jwksUri, clientId, nonce, fetchImpl = fetch }) {
    const [encodedHeader, encodedPayload, encodedSignature] = idToken.split('.');
    if (!encodedHeader || !encodedPayload || !encodedSignature) throw new Error('ChatGPT returned an invalid identity token.');
    const header = JSON.parse(Buffer.from(encodedHeader, 'base64url').toString('utf8'));
    const payload = JSON.parse(Buffer.from(encodedPayload, 'base64url').toString('utf8'));
    if (header.alg !== 'RS256' || typeof header.kid !== 'string') throw new Error('ChatGPT identity token uses an unsupported signing key.');
    if (payload.iss !== issuer || payload.sub === undefined || payload.nonce !== nonce || payload.exp <= Math.floor(Date.now() / 1000)) {
        throw new Error('ChatGPT identity token claims could not be verified.');
    }
    const audiences = Array.isArray(payload.aud) ? payload.aud : [payload.aud];
    if (!audiences.includes(clientId)) throw new Error('ChatGPT identity token audience could not be verified.');
    const keysResponse = await fetchImpl(jwksUri, { headers: { accept: 'application/json' } });
    const keys = await keysResponse.json();
    const jwk = keys.keys?.find((key) => key.kid === header.kid);
    if (!jwk) throw new Error('ChatGPT identity signing key was not found.');
    const publicKey = createPublicKey({ key: jwk, format: 'jwk' });
    const valid = verify('RSA-SHA256', Buffer.from(`${encodedHeader}.${encodedPayload}`), publicKey, Buffer.from(encodedSignature, 'base64url'));
    if (!valid) throw new Error('ChatGPT identity token signature could not be verified.');
    return payload;
}

export async function exchangeCode({ tokenEndpoint, issuer = ISSUER, jwksUri, pending, code, clientId, fetchImpl = fetch }) {
    const response = await fetchImpl(tokenEndpoint, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            grant_type: 'authorization_code',
            client_id: clientId,
            code,
            code_verifier: pending.verifier,
            redirect_uri: pending.redirectUri,
            resource: RESOURCE,
        }),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error_description ?? data.error ?? 'ChatGPT authorization failed.');
    if (typeof data.access_token !== 'string' || typeof data.refresh_token !== 'string' || typeof data.id_token !== 'string') {
        throw new Error('ChatGPT did not return the required token pair and identity token.');
    }
    const identity = await verifyIdentityToken(data.id_token, {
        issuer,
        jwksUri: jwksUri ?? `${issuer}/.well-known/jwks.json`,
        clientId,
        nonce: pending.nonce,
        fetchImpl,
    });
    return {
        version: 1,
        clientId,
        hostId: pending.hostId,
        accessToken: data.access_token,
        refreshToken: data.refresh_token,
        tokenType: data.token_type ?? 'Bearer',
        expiresAt: Date.now() + Number(data.expires_in ?? 3600) * 1000,
        scope: data.scope ?? SCOPES,
        connectedAt: new Date().toISOString(),
    };
}

export async function refreshConnection(connection, { tokenEndpoint, fetchImpl = fetch } = {}) {
    const response = await fetchImpl(tokenEndpoint, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            grant_type: 'refresh_token',
            client_id: connection.clientId,
            refresh_token: connection.refreshToken,
            resource: RESOURCE,
        }),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error_description ?? data.error ?? 'ChatGPT token refresh failed.');
    return {
        ...connection,
        accessToken: data.access_token,
        refreshToken: data.refresh_token ?? connection.refreshToken,
        tokenType: data.token_type ?? connection.tokenType,
        expiresAt: Date.now() + Number(data.expires_in ?? 3600) * 1000,
        scope: data.scope ?? connection.scope,
    };
}

export async function invoke(connection, prompt, { responseEndpoint = `${RESOURCE}/responses`, fetchImpl = fetch } = {}) {
    const response = await fetchImpl(responseEndpoint, {
        method: 'POST',
        headers: {
            accept: 'application/json',
            authorization: `${connection.tokenType} ${connection.accessToken}`,
            'content-type': 'application/json',
        },
        body: JSON.stringify({ model: 'gpt-4o-mini', input: prompt }),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error?.message ?? 'ChatGPT inference failed.');
    return data;
}

export { randomValue };
