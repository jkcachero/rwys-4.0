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
        patch: vi.fn(),
        post: vi.fn(),
        processing: false,
        reset: vi.fn(),
        setData: vi.fn(),
    }),
}));

import AgentAccess from './agent-access';

const selectedClient = {
    id: 'client-123',
    name: 'Acme',
    role: 'owner',
    can_manage: true,
    can_assign_profiles: false,
    approved_origins: ['https://agents.example.com'],
    profiles: [],
    capabilities: {
        'context.read': { available: true },
        'zernio.accounts.read': { available: true },
    },
    tokens: [],
};

const abilities = [
    { value: 'context.read', label: 'Context read' },
    { value: 'zernio.accounts.read', label: 'Zernio accounts read' },
];

describe('Agent access settings', () => {
    it('renders a useful empty state without a client membership', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                clients={[]}
                selectedClient={null}
                abilities={abilities}
            />,
        );

        expect(markup).toContain('No Agent Access Clients');
        expect(markup).toContain('active Client membership');
        expect(markup).not.toContain('Issue an agent token');
    });

    it('shows scoped management state and one-time token plaintext', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                clients={[selectedClient]}
                selectedClient={selectedClient}
                abilities={abilities}
                token_plaintext="secret-token"
                token_notice="Store this token now."
            />,
        );

        expect(markup).toContain('Acme');
        expect(markup).toContain('https://agents.example.com');
        expect(markup).toContain('Context read: available');
        expect(markup).toContain('No provider profile is assigned');
        expect(markup).toContain('Issue an agent token');
        expect(markup).toContain('Store this token now.');
        expect(markup).toContain('secret-token');
        expect(markup).toContain('No tokens have been issued');
        expect(markup).not.toContain('Load Zernio profiles');
    });

    it('shows assignment controls to an authorized operator without owner token controls', () => {
        const operatorClient = {
            ...selectedClient,
            role: 'operator',
            can_manage: false,
            can_assign_profiles: true,
        };
        const markup = renderToStaticMarkup(
            <AgentAccess
                clients={[operatorClient]}
                selectedClient={operatorClient}
                abilities={abilities}
            />,
        );

        expect(markup).toContain('Assign a Zernio profile');
        expect(markup).toContain('Load Zernio profiles');
        expect(markup).toContain('Manage Zernio connection');
        expect(markup).toContain('href="/settings/connections"');
        expect(markup).not.toContain('Issue an agent token');
    });
});
