import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { aiRequests, useAIConnections } from '@/hooks/ai-connections';
import type { AIConnection } from '@/hooks/ai-connections';
import AIConnections, {
    ConnectionList,
    OAuthStatus,
    ProviderNote,
    connectionMethods,
} from './ai-connections';

afterEach(() => vi.restoreAllMocks());

describe('shared AI connections', () => {
    it('lists only API keys belonging to the selected provider and labels their connection type', () => {
        const base = {
            authMode: 'api_key',
            model: null,
            status: 'configured',
            lastValidatedAt: null,
        };
        const html = renderToStaticMarkup(
            <AIConnections
                initialProvider="claude"
                connections={[
                    {
                        ...base,
                        id: 1,
                        provider: 'claude',
                        label: 'Claude work',
                    },
                    {
                        ...base,
                        id: 2,
                        provider: 'openai',
                        label: 'OpenAI work',
                    },
                ]}
                providers={[]}
                onConnectionsChange={() => {}}
            />,
        );
        expect(html).toContain('Claude work');
        expect(html).not.toContain('OpenAI work');
        expect(html).toContain('Claude · API key');
        expect(html).toContain('configured');
    });
    it('offers subscription first for OpenAI and only API keys for Claude', () => {
        expect(
            connectionMethods('openai').map((method) => method.value),
        ).toEqual(['subscription', 'api_key']);
        expect(
            connectionMethods('claude').map((method) => method.value),
        ).toEqual(['api_key']);
    });
    it('renders only the selected API-key pane with separately billed credentials and advanced model options', () => {
        const html = renderToStaticMarkup(
            <AIConnections
                initialProvider="openai"
                initialMethod="api_key"
                connections={[]}
                providers={[]}
                onConnectionsChange={() => {}}
            />,
        );
        expect(html).toContain('type="password"');
        expect(html).toContain('Separate API billing');
        expect(html).toContain('Advanced options');
        expect(html).toContain('Save API key');
        expect(html).not.toContain('Connect with ChatGPT');
        expect(html).not.toContain('Local setup instructions');
    });
    it('shows Claude API keys and an explicit unsupported subscription reason, never a subscription connect CTA', () => {
        const html = renderToStaticMarkup(
            <AIConnections
                initialProvider="claude"
                initialMethod="subscription"
                connections={[]}
                providers={[]}
                onConnectionsChange={() => {}}
            />,
        );
        expect(html).toContain('type="password"');
        expect(html).toContain('Claude subscription OAuth is not supported');
        expect(html).not.toContain('Connect with ChatGPT');
        expect(html).not.toContain('ChatGPT subscription');
    });
    it('only explains the selected API provider, including the unsupported Claude OAuth reason', () => {
        const openai = renderToStaticMarkup(<ProviderNote provider="openai" />);
        const claude = renderToStaticMarkup(<ProviderNote provider="claude" />);
        expect(openai).toContain('Eligible OpenAI subscriptions');
        expect(openai).not.toContain('Claude subscription');
        expect(claude).toContain('Claude subscription OAuth is not supported');
        expect(claude).toContain(
            'does not provide an approved subscription OAuth flow',
        );
        expect(claude).not.toContain('Eligible OpenAI subscriptions');
    });
    it('ignores a cancelled action completing after its replacement starts', async () => {
        const onConnectionsChange = vi.fn();
        let actions!: ReturnType<typeof useAIConnections>;
        function Harness() {
            actions = useAIConnections([], [], onConnectionsChange);
            return null;
        }
        renderToStaticMarkup(<Harness />);
        let resolveFirst!: (data: { connection: AIConnection }) => void;
        let resolveSecond!: (data: { connection: AIConnection }) => void;
        const save = vi
            .spyOn(aiRequests, 'save')
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveFirst = resolve;
                    }),
            )
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveSecond = resolve;
                    }),
            );
        const draft = {
            provider: 'openai',
            label: 'Personal',
            apiKey: 'secret',
            model: '',
        };
        const connection: AIConnection = {
            id: 1,
            provider: 'openai',
            label: 'Personal',
            authMode: 'api_key',
            model: null,
            status: 'configured',
            lastValidatedAt: null,
        };
        const first = actions.save(draft);
        actions.cancel();
        const second = actions.save(draft);
        resolveFirst({ connection });
        await expect(first).resolves.toBe(false);
        expect(onConnectionsChange).not.toHaveBeenCalled();
        await expect(actions.save(draft)).resolves.toBe(false);
        expect(save).toHaveBeenCalledTimes(2);
        resolveSecond({ connection: { ...connection, id: 2 } });
        await expect(second).resolves.toBe(true);
        expect(onConnectionsChange).toHaveBeenCalledExactlyOnceWith([
            { ...connection, id: 2 },
        ]);
    });
    it('defaults to ChatGPT subscription with visible listener prerequisite and disclosed setup details', () => {
        const html = renderToStaticMarkup(
            <AIConnections
                connections={[]}
                providers={[]}
                onConnectionsChange={() => {}}
            />,
        );
        expect(html).toContain('OpenAI');
        expect(html).toContain('Claude');
        expect(html).not.toContain('type="password"');
        expect(html).toContain('AI connections');
        expect(html).toContain('Connect with ChatGPT');
        expect(html).toContain('Start the local OAuth listener');
        expect(html).toContain('<details');
        expect(html).toContain('Local setup instructions');
        expect(html).toContain('php artisan openai:oauth-listen');
        expect(html).toContain(
            'Eligible OpenAI subscriptions can also connect',
        );
        expect(html).not.toContain(
            'Claude subscription OAuth is not supported',
        );
        expect(html).not.toContain('127.0.0.1');
    });
    it('shows configured versus validated and revoked items accurately', () => {
        const html = renderToStaticMarkup(
            <ConnectionList
                connections={[
                    {
                        id: 7,
                        provider: 'claude',
                        label: 'Work',
                        authMode: 'api_key',
                        model: null,
                        status: 'configured',
                        lastValidatedAt: null,
                    },
                ]}
                pending={true}
                onTest={() => {}}
                onRevoke={() => {}}
            />,
        );
        expect(html).toContain('Work');
        expect(html).toContain('Not tested');
        expect(html).toContain('disabled');
        expect(html).toContain('Revoke Work');
        expect(html).toContain('API key');
    });
    it('provides an accessible fallback sign-in link and expiry', () => {
        const html = renderToStaticMarkup(
            <OAuthStatus
                attempt={{
                    url: 'https://auth.openai.com/api/accounts/authorize',
                    state: 'state',
                    expiresAt: '2026-10-22T12:00:00Z',
                }}
                message="Waiting for sign-in."
            />,
        );
        expect(html).toContain('role="status"');
        expect(html).toContain('rel="noopener noreferrer"');
        expect(html).toContain('Continue with ChatGPT');
        expect(html).toContain('2026-10-22T12:00:00Z');
    });
    it('shows an expired refreshable access token as refresh information, not a disconnected subscription', () => {
        const html = renderToStaticMarkup(
            <AIConnections
                connections={[]}
                providers={[
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: '',
                        connected: true,
                        expiresAt: '2000-01-01T00:00:00Z',
                    },
                ]}
                onConnectionsChange={() => {}}
            />,
        );
        expect(html).toContain('Disconnect OpenAI subscription');
        expect(html).toContain('Access token expiry: 2000-01-01T00:00:00Z');
        expect(html).toContain('refreshed automatically when needed');
        expect(html).not.toContain('No personal OpenAI or Claude connection');
        expect(html).not.toContain('No saved API keys');
    });
});
