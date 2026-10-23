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
        get: vi.fn(),
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
    owner: {
        name: 'John Karlo',
        email: 'john@example.com',
    },
    can_manage: true,
    can_assign_profiles: false,
    profiles: [],
    tokens: [],
};

const abilities = [
    { value: 'context.read' },
    { value: 'zernio.accounts.read' },
];

describe('Agent access settings', () => {
    it('renders a user-focused empty state without an eligible user', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                clients={[]}
                selectedClient={null}
                abilities={abilities}
            />,
        );

        expect(markup).toContain('No registered users available');
        expect(markup).toContain('no eligible users');
        expect(markup).not.toContain('Issue an agent token');
    });

    it('shows registered-user access without provider or internal settings', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                clients={[selectedClient]}
                selectedClient={selectedClient}
                abilities={abilities}
                token_plaintext="secret-token"
                token_notice="Store this key now."
            />,
        );

        expect(markup).toContain('Step 1 — Select a registered user');
        expect(markup).toContain('John Karlo');
        expect(markup).toContain('john@example.com');
        expect(markup).toContain('No profile has been assigned');
        expect(markup).toContain('Create secure access');
        expect(markup).toContain('Read');
        expect(markup).toContain('Store this key now.');
        expect(markup).toContain('secret-token');
        expect(markup).toContain('No secure access has been created');
        expect(markup).not.toContain('Zernio');
        expect(markup).not.toContain('Approved origins');
        expect(markup).not.toContain('capabilit');
        expect(markup).not.toContain('Context read');
    });

    it('shows a simple profile assignment flow to an authorized operator', () => {
        const operatorClient = {
            ...selectedClient,
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

        expect(markup).toContain('Step 1 — Select a registered user');
        expect(markup).toContain('Assign a profile');
        expect(markup).toContain('Step 2 — Load available profiles');
        expect(markup).toContain('Load profiles');
        expect(markup).toContain('Connection settings');
        expect(markup).not.toContain('Create secure access');
        expect(markup).not.toContain('Zernio');
    });
});
