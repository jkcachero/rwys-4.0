import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: {
        delete: vi.fn(),
        post: vi.fn(),
    },
    useForm: <T extends object>(initialData: T) => ({
        data: initialData,
        errors: {},
        processing: false,
        put: vi.fn(),
        reset: vi.fn(),
        setData: vi.fn(),
    }),
}));

import Connections from './connections';

const zernio = {
    name: 'zernio',
    label: 'Zernio',
    model: null,
    authentication: ['api_token'],
    capabilities: ['profile_discovery', 'account_discovery'],
    message:
        'Connect a Zernio API token to discover and assign provider profiles to registered Clients.',
    connected: false,
    expiresAt: null,
};

describe('Provider connections settings', () => {
    it('shows a secure Zernio API token connection form', () => {
        const markup = renderToStaticMarkup(
            <Connections providers={[zernio]} />,
        );

        expect(markup).toContain('Zernio API token');
        expect(markup).toContain('type="password"');
        expect(markup).toContain('Connect Zernio');
        expect(markup).toContain('profile discovery');
        expect(markup).not.toContain('Model:');
    });

    it('shows test and disconnect controls for a connected Zernio account', () => {
        const markup = renderToStaticMarkup(
            <Connections providers={[{ ...zernio, connected: true }]} />,
        );

        expect(markup).toContain('Connected');
        expect(markup).toContain('Test connection');
        expect(markup).toContain('Disconnect');
        expect(markup).not.toContain('type="password"');
        expect(markup).not.toContain('Connect Zernio');
    });
});
