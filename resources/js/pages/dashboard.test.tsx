import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/routes', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/routes')>()),
    chat: Object.assign(() => ({ url: '/chat', method: 'get' as const }), {
        url: () => '/chat',
    }),
}));
import Dashboard from './dashboard';

const workspace = {
    status: 'ready' as const,
    greeting: 'Welcome back, Ada.',
    correlationId: 'request-123',
    conversation: { status: 'empty', messages: [] },
    connections: [],
    provider: {
        status: 'unavailable',
        message: 'No advertising tools are connected.',
    },
    capabilities: [],
};

describe('Dashboard overview', () => {
    it('offers Chat and AI connections without embedding a conversation or credential form', () => {
        const html = renderToStaticMarkup(<Dashboard workspace={workspace} />);
        expect(html).toContain('Welcome back, Ada.');
        expect(html).toContain('href="/chat"');
        expect(html).toContain('href="/settings/connections"');
        expect(html).toContain('Chat');
        expect(html).toContain('AI connections');
        expect(html).toContain('AdKit and Zernio tools are not yet connected');
        expect(html).not.toContain('role="log"');
        expect(html).not.toContain('type="password"');
        expect(html).not.toContain('chart');
    });
    it('summarizes real connection metadata without treating configured keys as validated', () => {
        const html = renderToStaticMarkup(
            <Dashboard
                workspace={{
                    ...workspace,
                    connections: [
                        {
                            id: 3,
                            provider: 'claude',
                            label: 'Work',
                            authMode: 'api_key',
                            model: 'claude-model',
                            status: 'configured',
                            lastValidatedAt: null,
                        },
                    ],
                    providers: [
                        {
                            name: 'openai',
                            label: 'OpenAI',
                            model: '',
                            connected: true,
                        },
                    ],
                }}
            />,
        );
        expect(html).toContain('Work');
        expect(html).toContain('API key');
        expect(html).toContain('configured');
        expect(html).toContain('Not tested');
        expect(html).toContain('ChatGPT subscription');
        expect(html).toContain('Connected');
    });
});
