import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { createSign, generateKeyPairSync } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    RESOURCE,
    SCOPES,
    createAuthorizationUrl,
    createPendingAuthorization,
    exchangeCode,
    invoke,
    loadConnection,
    saveConnection,
} from './connector.mjs';

test('authorization URL uses PKCE, dynamic registration, required scopes, and host binding', () => {
    const pending = createPendingAuthorization({
        clientId: 'dynamic_agent_client',
        hostId: 'host-123',
        redirectUri: 'http://127.0.0.1:4321/auth/callback',
    });
    const url = createAuthorizationUrl({
        authorizationEndpoint: 'https://auth.openai.com/oauth/authorize',
        ...pending,
    });
    const params = url.searchParams;

    assert.equal(params.get('client_id'), 'dynamic_agent_client');
    assert.equal(params.get('code_challenge_method'), 'S256');
    assert.match(params.get('code_challenge'), /^[A-Za-z0-9_-]+$/);
    assert.equal(params.get('scope'), SCOPES);
    assert.equal(params.get('resource'), RESOURCE);
    assert.equal(params.get('ext_agent_host_id'), 'host-123');
});

test('connection credentials are encrypted and round-trip locally', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'rwys-chatgpt-'));
    const storePath = join(directory, 'connection.json.enc');
    const keyPath = join(directory, 'connection.key');
    const connection = {
        version: 1,
        clientId: 'client-123',
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        tokenType: 'Bearer',
        expiresAt: Date.now() + 3600000,
    };

    try {
        await saveConnection(connection, storePath, keyPath);
        const encrypted = await readFile(storePath, 'utf8');
        assert.doesNotMatch(encrypted, /access-token|refresh-token/);
        assert.deepEqual(await loadConnection(storePath, keyPath), connection);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('authorization exchange verifies the returned identity token', async () => {
    const pending = createPendingAuthorization({
        clientId: 'dynamic_agent_client',
        hostId: 'host-123',
        redirectUri: 'http://127.0.0.1:4321/auth/callback',
    });
    const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
    const header = Buffer.from(JSON.stringify({ alg: 'RS256', kid: 'test-key' })).toString('base64url');
    const payload = Buffer.from(JSON.stringify({
        iss: 'https://auth.openai.com',
        sub: 'chatgpt-user',
        aud: ['registered-client'],
        nonce: pending.nonce,
        exp: Math.floor(Date.now() / 1000) + 600,
    })).toString('base64url');
    const signed = `${header}.${payload}`;
    const signer = createSign('RSA-SHA256');
    signer.update(signed);
    const signature = signer.sign(privateKey).toString('base64url');
    const idToken = `${signed}.${signature}`;
    const connection = await exchangeCode({
        tokenEndpoint: 'https://auth.openai.com/oauth/token',
        issuer: 'https://auth.openai.com',
        jwksUri: 'https://auth.openai.com/.well-known/jwks.json',
        pending,
        code: 'one-time-code',
        clientId: 'registered-client',
        fetchImpl: async (url, options) => {
            if (url.endsWith('jwks.json')) return new Response(JSON.stringify({ keys: [{ kid: 'test-key', ...publicKey.export({ format: 'jwk' }) }] }), { status: 200 });
            assert.equal(options.body.get('code_verifier'), pending.verifier);
            assert.equal(options.body.get('resource'), RESOURCE);
            return new Response(JSON.stringify({ access_token: 'access', refresh_token: 'refresh', id_token: idToken, expires_in: 600 }), { status: 200 });
        },
    });

    assert.equal(connection.clientId, 'registered-client');
    assert.equal(connection.refreshToken, 'refresh');
});

test('inference sends the locally held bearer token and bounded prompt', async () => {
    let request;
    const result = await invoke({ tokenType: 'Bearer', accessToken: 'local-access' }, 'hello', {
        fetchImpl: async (_url, options) => {
            request = options;
            return new Response(JSON.stringify({ id: 'resp_123', output: [] }), { status: 200 });
        },
    });

    assert.equal(request.headers.authorization, 'Bearer local-access');
    assert.deepEqual(JSON.parse(request.body), { model: 'gpt-4o-mini', input: 'hello' });
    assert.equal(result.id, 'resp_123');
});
