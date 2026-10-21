import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    aiRequests,
    chatChoices,
    jsonRequest,
    pollOAuth,
    promptPayload,
    requestHeaders,
    validateOAuthAttempt,
} from './ai-connections';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('AI JSON transport', () => {
    it('extends persisted prompts without changing unrelated connection payloads', () => {
        expect(
            promptPayload('Question', {
                value: 'api:7',
                label: 'Work',
                provider: 'claude',
                connectionId: 7,
                model: 'model-1',
                thinkingLevel: null,
                conversationId: 'thread-1',
                requestId: 'request-uuid',
            }),
        ).toEqual({
            provider: 'claude',
            prompt: 'Question',
            connectionId: 7,
            model: 'model-1',
            thinkingLevel: null,
            conversationId: 'thread-1',
            requestId: 'request-uuid',
        });
    });
    it('uses the API key and OAuth JSON contracts without mixing connection identifiers', async () => {
        const fetch = vi
            .fn()
            .mockImplementation(() =>
                Promise.resolve(Response.json({ status: 'ok' })),
            );
        vi.stubGlobal('fetch', fetch);
        const connection = {
            id: 7,
            provider: 'claude',
            label: 'Work',
            authMode: 'api_key',
            model: 'model',
            status: 'configured',
            lastValidatedAt: null,
        };
        await aiRequests.list();
        await aiRequests.save({
            provider: 'claude',
            label: 'Work',
            apiKey: 'secret',
            model: '',
        });
        await aiRequests.test(connection);
        await aiRequests.revoke(7);
        await aiRequests.beginOAuth();
        await aiRequests.test();
        await aiRequests.disconnectOAuth();
        await aiRequests.prompt('Hello', {
            value: 'api:7',
            label: 'Work',
            provider: 'claude',
            connectionId: 7,
        });
        expect(
            fetch.mock.calls.map(([url, options]) => [
                url,
                options.method,
                options.body && JSON.parse(options.body),
            ]),
        ).toEqual([
            ['/workspace/connections', 'GET', undefined],
            [
                '/workspace/connections',
                'POST',
                {
                    provider: 'claude',
                    label: 'Work',
                    apiKey: 'secret',
                    authMode: 'api_key',
                    model: null,
                },
            ],
            [
                '/settings/connections/claude/test',
                'POST',
                { connectionId: 7, model: 'model' },
            ],
            ['/workspace/connections/7', 'DELETE', undefined],
            ['/settings/connections/openai', 'POST', {}],
            ['/settings/connections/openai/test', 'POST', {}],
            ['/settings/connections/openai', 'DELETE', undefined],
            [
                '/dashboard/prompt',
                'POST',
                { provider: 'claude', prompt: 'Hello', connectionId: 7 },
            ],
        ]);
    });
    it('only offers owned non-revoked API keys', () => {
        const connection = {
            id: 1,
            label: 'Mine',
            provider: 'claude',
            authMode: 'api_key',
            model: null,
            status: 'configured',
            lastValidatedAt: null,
        };
        expect(
            chatChoices(
                [connection, { ...connection, id: 2, status: 'revoked' }],
                [
                    {
                        name: 'anthropic',
                        label: 'Claude',
                        model: '',
                        status: 'available',
                    },
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: '',
                        status: 'expired',
                    },
                ],
            ).map((choice) => choice.value),
        ).toEqual(['api:1']);
    });
    it('does not invent OAuth for an API-only available provider', () => {
        expect(
            chatChoices(
                [],
                [
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: '',
                        status: 'available',
                        connected: false,
                    },
                ],
            ),
        ).toEqual([]);
        expect(
            chatChoices(
                [],
                [
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: '',
                        status: 'available',
                    },
                ],
            ),
        ).toEqual([]);
    });
    it('offers explicitly connected refreshable OAuth despite an expired access token', () => {
        expect(
            chatChoices(
                [],
                [
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: '',
                        connected: true,
                        expiresAt: '2000-01-01T00:00:00Z',
                    },
                ],
            ),
        ).toEqual([
            {
                value: 'oauth:openai',
                label: 'OpenAI subscription (OAuth)',
                provider: 'openai',
            },
        ]);
        expect(
            chatChoices(
                [],
                [
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: '',
                        status: 'available',
                        connected: true,
                    },
                ],
            )[0].value,
        ).toBe('oauth:openai');
    });
    it('accepts only the approved OpenAI authorization URL', () => {
        const attempt = {
            url: 'https://auth.openai.com/api/accounts/authorize?state=state',
            state: 'state',
            expiresAt: '2026-10-22T12:00:00Z',
        };
        expect(validateOAuthAttempt(attempt)).toEqual(attempt);
        for (const url of [
            'https://example.com/api/accounts/authorize',
            'https://auth.openai.com.evil.example/api/accounts/authorize',
            'http://auth.openai.com/api/accounts/authorize',
            'https://auth.openai.com/not-authorize',
            'https://auth.openai.com/api/accounts/authorize-evil',
            'https://auth.openai.com:8443/api/accounts/authorize',
            'https://user:password@auth.openai.com/api/accounts/authorize',
            'javascript:alert(1)',
            'not a URL',
        ]) {
            expect(() => validateOAuthAttempt({ ...attempt, url })).toThrow(
                'Invalid OpenAI sign-in response.',
            );
        }
        expect(() => validateOAuthAttempt({ ...attempt, state: '' })).toThrow();
        expect(() =>
            validateOAuthAttempt({ ...attempt, expiresAt: 'invalid' }),
        ).toThrow();
    });
    it('rejects a response completed after cancellation even when fetch ignores abort', async () => {
        let resolveResponse!: (response: Response) => void;
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise<Response>((resolve) => {
                        resolveResponse = resolve;
                    }),
            ),
        );
        const controller = new AbortController();
        const result = jsonRequest(
            '/settings/connections/openai',
            'POST',
            {},
            controller.signal,
        );
        controller.abort();
        resolveResponse(Response.json({ status: 'connected' }));
        await expect(result).rejects.toThrow();
    });
    it('aborts an in-flight poll at the attempt deadline', async () => {
        vi.useFakeTimers();
        vi.stubGlobal(
            'fetch',
            vi.fn(
                (_url, options) =>
                    new Promise((_resolve, reject) =>
                        options.signal.addEventListener(
                            'abort',
                            () => reject(options.signal.reason),
                            { once: true },
                        ),
                    ),
            ),
        );
        const result = pollOAuth(
            'state',
            Date.now() + 3000,
            new AbortController().signal,
        );
        const assertion = expect(result).rejects.toThrow('expired');
        await vi.advanceTimersByTimeAsync(3000);
        await assertion;
    });
    it('cleans up polling when cancelled during the wait', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const controller = new AbortController();
        const result = pollOAuth(
            'state',
            Date.now() + 10000,
            controller.signal,
        );
        const assertion = expect(result).rejects.toThrow();
        controller.abort();
        await assertion;
        await vi.advanceTimersByTimeAsync(10000);
        expect(fetch).not.toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });
    it('decodes Laravel XSRF cookies and supports the meta fallback', () => {
        expect(
            requestHeaders('XSRF-TOKEN=abc%3D%3D', 'fallback')['X-XSRF-TOKEN'],
        ).toBe('abc==');
        expect(requestHeaders('', 'fallback')['X-CSRF-TOKEN']).toBe('fallback');
    });
    it('sends same-origin JSON and surfaces labeled validation errors', async () => {
        const fetch = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({ errors: { apiKey: ['Invalid key.'] } }),
                {
                    status: 422,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );
        vi.stubGlobal('fetch', fetch);
        await expect(
            jsonRequest('/workspace/connections', 'POST', { apiKey: 'secret' }),
        ).rejects.toThrow('API key: Invalid key.');
        expect(fetch).toHaveBeenCalledWith(
            '/workspace/connections',
            expect.objectContaining({
                credentials: 'same-origin',
                redirect: 'error',
                body: '{"apiKey":"secret"}',
            }),
        );
    });
    it('rejects HTML success pages rather than claiming success', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response('<html>Login</html>', {
                    headers: { 'Content-Type': 'text/html' },
                }),
            ),
        );
        await expect(
            jsonRequest('/settings/connections/openai', 'POST'),
        ).rejects.toThrow('JSON');
    });
    it('requires an explicit owned connection or OAuth selection', () => {
        expect(
            promptPayload('Why?', {
                value: 'api:4',
                label: 'Personal',
                provider: 'claude',
                connectionId: 4,
                model: 'model',
            }),
        ).toEqual({
            prompt: 'Why?',
            provider: 'claude',
            connectionId: 4,
            model: 'model',
        });
        expect(
            promptPayload('Hi', {
                value: 'oauth:openai',
                label: 'OAuth',
                provider: 'openai',
            }),
        ).toEqual({ prompt: 'Hi', provider: 'openai' });
    });
    it('polls every two seconds and stops on connection', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn().mockImplementation(() =>
            Promise.resolve(
                Response.json({
                    status:
                        fetch.mock.calls.length === 1 ? 'pending' : 'connected',
                }),
            ),
        );
        vi.stubGlobal('fetch', fetch);
        const result = pollOAuth(
            'state/1',
            Date.now() + 10000,
            new AbortController().signal,
        );
        await vi.advanceTimersByTimeAsync(4000);
        await expect(result).resolves.toMatchObject({ status: 'connected' });
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(fetch.mock.calls[0][0]).toBe(
            '/settings/connections/openai/attempts/state%2F1',
        );
    });
    it('bounds polling by expiry and supports abort cleanup', async () => {
        await expect(
            pollOAuth('state', Date.now() - 1, new AbortController().signal),
        ).rejects.toThrow('expired');
        const controller = new AbortController();
        controller.abort();
        await expect(
            pollOAuth('state', Date.now() + 10000, controller.signal),
        ).rejects.toThrow();
    });
});
