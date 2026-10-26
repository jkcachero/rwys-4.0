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
        delete: vi.fn(),
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

describe('Agent access settings', () => {
    it.each([
        { canManage: true, revokedAt: null, canRevoke: true },
        { canManage: false, revokedAt: null, canRevoke: false },
    ])(
        'shows key revocation only for an owner with an unrevoked key: %j',
        ({ canManage, revokedAt, canRevoke }) => {
            const client = {
                ...selectedClient,
                can_manage: canManage,
                tokens: [
                    {
                        id: 6,
                        name: 'Old agent key',
                        abilities: ['zernio.posts.read'],
                        allowed_account_ids: [],
                        last_used_at: null,
                        expires_at: '2026-11-23T20:00:00Z',
                        revoked_at: revokedAt,
                        created_at: '2026-10-23T20:00:00Z',
                    },
                ],
            };
            const markup = renderToStaticMarkup(
                <AgentAccess clients={[client]} selectedClient={client} />,
            );

            expect(markup).toContain('Stored API keys');
            expect(markup).toContain(
                'Revoked and expired keys are hidden from this list',
            );
            expect(markup.includes('Revoke key')).toBe(canRevoke);
        },
    );

    it('renders a user-focused empty state without an eligible user', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess clients={[]} selectedClient={null} />,
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
                token_plaintext="secret-token"
                token_notice="Store this key now."
            />,
        );

        expect(markup).toContain('Step 1 — Select a registered user');
        expect(markup).toContain('John Karlo');
        expect(markup).toContain('john@example.com');
        expect(markup).toContain('No profile has been assigned');
        expect(markup).toContain('Create secure access');
        expect(markup).toContain('Access can read and publish');
        expect(markup).toContain('Store this key now.');
        expect(markup).toContain('secret-token');
        expect(markup).toContain('No active API keys for this user');
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
            />,
        );

        expect(markup).toContain('Step 1 — Select a registered user');
        expect(markup).toContain('Assign a profile');
        expect(markup).toContain('Step 2 — Load available profiles');
        expect(markup).toContain('Load profiles');
        expect(markup).not.toContain('Connection settings');
        expect(markup).not.toContain('/settings/connections');
        expect(markup).not.toContain('Create secure access');
        expect(markup).not.toContain('Zernio');
    });
});
