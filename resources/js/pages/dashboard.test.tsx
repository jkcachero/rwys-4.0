import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

import Dashboard from './dashboard';

describe('Dashboard workspace', () => {
    it('renders the read-only workspace state', () => {
        const markup = renderToStaticMarkup(
            <Dashboard
                workspace={{
                    status: 'ready',
                    greeting: 'Welcome back, Ada.',
                    correlationId: 'request-123',
                    conversation: { status: 'empty', messages: [] },
                    sources: [],
                    connections: [],
                    provider: {
                        status: 'deferred',
                        message: 'Provider reads are deferred until approved.',
                    },
                    capabilities: [
                        { name: 'AdKit read access', status: 'deferred' },
                        { name: 'Zernio read access', status: 'deferred' },
                        { name: 'Personal OpenAI / Claude connections', status: 'available' },
                    ],
                }}
            />,
        );

        expect(markup).toContain('Welcome back, Ada.');
        expect(markup).toContain('No messages yet');
        expect(markup).toContain('Personal AI connections');
        expect(markup).toContain('request-123');
        expect(markup).toContain('No personal OpenAI or Claude connection is configured yet.');
    });
});
