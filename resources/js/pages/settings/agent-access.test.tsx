import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { delete: vi.fn(), get: vi.fn(), post: vi.fn() },
    useForm: <T extends object>(initialData: T) => ({
        data: initialData,
        delete: vi.fn(),
        errors: {},
        post: vi.fn(),
        processing: false,
        reset: vi.fn(),
        setData: vi.fn(),
    }),
}));

import AgentAccess, { TokenCreationForm } from './agent-access';

const selectedClient = {
    id: 'client-123',
    name: 'Acme',
    owner: { name: 'John Karlo', email: 'john@example.com' },
    social_account_count: 3,
    ad_account_count: 2,
    can_manage: true,
    can_assign_profiles: false,
    can_assign_adkit_accounts: false,
    adkit_accounts: [],
    profiles: [],
    tokens: [],
} satisfies NonNullable<ComponentProps<typeof AgentAccess>['selectedClient']>;

const baseProps = {
    clients: [selectedClient],
    can_assign_accounts: true,
    selectedClient,
    assigned_users: [selectedClient],
    assigned_users_pagination: null,
} satisfies ComponentProps<typeof AgentAccess>;

afterEach(() => vi.unstubAllGlobals());

describe('Agent access settings', () => {
    it('keeps the user-directory assignment action hidden from regular users', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess {...baseProps} can_assign_accounts={false} />,
        );

        expect(markup).toContain('Assigned users');
        expect(markup).not.toContain('Assign accounts</button>');
        expect(markup).not.toContain('Select a registered user');
    });

    it('renders assigned users in a table independently from the registered-user directory', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                {...baseProps}
                clients={[
                    {
                        ...selectedClient,
                        id: 'directory-only',
                        owner: null,
                        name: 'Directory only',
                    },
                ]}
                assigned_users_pagination={{ page: 2, has_more: false }}
                client_pagination={{ page: 4, has_more: true }}
            />,
        );

        expect(markup).toContain('Assigned users');
        expect(markup).toContain('<table');
        expect(markup.match(/scope="col"/g)).toHaveLength(4);
        expect(markup).toContain('Social accounts</th>');
        expect(markup).toContain('Ad accounts</th>');
        expect(markup).toContain('Actions</th>');
        expect(markup).toContain('john@example.com');
        expect(markup).toMatch(/<td[^>]*>3<\/td>/);
        expect(markup).toMatch(/<td[^>]*>2<\/td>/);
        expect(markup).toContain('Manage');
        expect(markup).toContain('Assign accounts');
        expect(markup).toContain('Previous assigned users');
        expect(markup).toContain('Next assigned users');
        expect(markup).toContain('Page 2');
        expect(markup).not.toContain('Page 4');
        expect(markup).not.toContain('Directory only');
        expect(markup).not.toContain('Registered user</label>');
    });

    it('renders an empty roster even when directory users exist', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                {...baseProps}
                assigned_users={[]}
                selectedClient={null}
            />,
        );

        expect(markup).toContain('No assigned users yet');
        expect(markup).toContain('<table');
        expect(markup).toContain('Assign accounts');
        expect(markup).not.toContain('Manage</button>');
        expect(markup).not.toContain('No registered users available');
    });

    it('renders an empty directory without an unusable assignment action', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                {...baseProps}
                clients={[]}
                assigned_users={[]}
                selectedClient={null}
            />,
        );

        expect(markup).toContain('No assigned users yet');
        expect(markup).not.toContain('Assign accounts</button>');
    });

    it('shows compact account groups without setup steps or permanent form fields', () => {
        const markup = renderToStaticMarkup(<AgentAccess {...baseProps} />);

        expect(markup).toContain('Accounts for John Karlo');
        expect(
            markup.match(/data-slot="card-title"[^>]*>Social accounts</g),
        ).toHaveLength(1);
        expect(
            markup.match(/data-slot="card-title"[^>]*>Ad accounts</g),
        ).toHaveLength(1);
        expect(
            markup.match(/data-slot="card-title"[^>]*>API keys</g),
        ).toHaveLength(1);
        expect(markup).not.toMatch(
            /Step [123]|Assign a profile|Create secure access|Connected accounts/,
        );
        expect(markup).not.toContain('token-name');
        expect(markup).not.toContain('profile-assignment');
        expect(markup).not.toContain('adkit-account-help');
        expect(markup).not.toMatch(
            /Provider credentials|Project ID|Project header|API secret|type="password"/i,
        );
        expect(markup).not.toContain('/settings/connections');
    });

    it.each([false, true])(
        'shows assigned AdKit accounts with management permission %s',
        (canAssign) => {
            const client = {
                ...selectedClient,
                can_manage: !canAssign,
                can_assign_adkit_accounts: canAssign,
                adkit_accounts: [
                    {
                        id: 'ad',
                        name: 'Acme ads',
                        platform: 'meta',
                        available: true,
                    },
                    {
                        id: 'paused',
                        name: 'Paused ads',
                        platform: 'meta',
                        available: false,
                    },
                ],
            };

            const markup = renderToStaticMarkup(
                <AgentAccess {...baseProps} selectedClient={client} />,
            );

            expect(markup).toContain('Acme ads');
            expect(markup).toContain('Available');
            expect(markup).toContain('Paused ads');
            expect(markup).toContain('Unavailable');
            expect(markup.includes('Assign ad account')).toBe(canAssign);
            expect(markup.includes('Remove access')).toBe(canAssign);
            expect(markup).not.toContain('Load available ad accounts');
        },
    );

    it('includes only available unique social and ad accounts in key scope choices', () => {
        const account = {
            id: 'social',
            name: 'Social account',
            platform: 'instagram',
            available: true,
        };
        const client = {
            ...selectedClient,
            profiles: [
                {
                    id: 'profile',
                    name: 'Existing profile',
                    synchronized_at: null,
                    accounts: [
                        account,
                        account,
                        {
                            ...account,
                            id: 'inactive',
                            name: 'Inactive social',
                            available: false,
                        },
                    ],
                },
            ],
            adkit_accounts: [
                account,
                {
                    id: 'ad',
                    name: 'Acme ads',
                    platform: 'meta',
                    available: true,
                },
                {
                    id: 'paused',
                    name: 'Paused ads',
                    platform: 'meta',
                    available: false,
                },
            ],
        };

        const markup = renderToStaticMarkup(
            <TokenCreationForm
                client={client}
                onCreated={() => {}}
                onPendingChange={() => {}}
            />,
        );
        const choices =
            markup.match(/<fieldset[\s\S]*?<\/fieldset>/)?.[0] ?? '';

        expect(choices).toContain('Social account');
        expect(choices).toContain('Acme ads');
        expect(choices).not.toContain('Inactive social');
        expect(choices).not.toContain('Paused ads');
        expect(choices.match(/role="checkbox"/g)).toHaveLength(2);
        expect(markup).toContain(
            'Leave all unchecked to allow all assigned accounts',
        );
    });

    it('disables key creation without eligible accounts and explains how to proceed', () => {
        const markup = renderToStaticMarkup(<AgentAccess {...baseProps} />);

        expect(markup).toMatch(
            /<button[^>]*disabled=""[^>]*>Create key<\/button>/,
        );
        expect(markup).toContain(
            'Assign an available account before creating a key',
        );
    });

    it('shows one owner-only create-key action when eligible accounts exist', () => {
        const client = {
            ...selectedClient,
            adkit_accounts: [
                { id: 'ad', name: 'Ads', platform: 'meta', available: true },
            ],
        };
        const markup = renderToStaticMarkup(
            <AgentAccess {...baseProps} selectedClient={client} />,
        );

        expect(markup.match(/>Create key</g)).toHaveLength(1);
        expect(markup).not.toMatch(
            /<button[^>]*disabled=""[^>]*>Create key<\/button>/,
        );
        expect(markup).not.toContain('token-expiry');
    });

    it('does not infer ad management or key ownership from social assignment permission', () => {
        const client = {
            ...selectedClient,
            can_manage: false,
            can_assign_profiles: true,
        };
        const markup = renderToStaticMarkup(
            <AgentAccess {...baseProps} selectedClient={client} />,
        );

        expect(markup).toContain('Assign Zernio profile');
        expect(markup).not.toContain('Assign ad account');
        expect(markup).not.toContain('Remove access');
        expect(markup).not.toContain('Create key');
        expect(markup).toContain('Only this user can manage their API keys');
        expect(markup).not.toContain('No active API keys for this user');
    });

    it('groups social profiles and availability rows in a single card', () => {
        const client = {
            ...selectedClient,
            can_assign_profiles: true,
            profiles: [
                {
                    id: 'one',
                    name: 'First profile',
                    synchronized_at: null,
                    accounts: [
                        {
                            id: 'one-account',
                            name: 'Social one',
                            platform: 'instagram',
                            available: true,
                        },
                    ],
                },
                {
                    id: 'two',
                    name: 'Second profile',
                    synchronized_at: null,
                    accounts: [
                        {
                            id: 'two-account',
                            name: 'Social two',
                            platform: 'facebook',
                            available: false,
                        },
                    ],
                },
            ],
        };
        const markup = renderToStaticMarkup(
            <AgentAccess {...baseProps} selectedClient={client} />,
        );
        const social =
            markup
                .match(
                    /<div data-slot="card"[\s\S]*?(?=<div data-slot="card"|$)/g,
                )
                ?.find((card) =>
                    /data-slot="card-title"[^>]*>Social accounts</.test(card),
                ) ?? '';

        expect(social).toContain('First profile');
        expect(social).toContain('Second profile');
        expect(social).toContain('Social one');
        expect(social).toContain('Social two');
        expect(social).toContain('Unavailable');
        expect(social.match(/>Refresh accounts</g)).toHaveLength(2);
        expect(social.match(/data-slot="card"/g)).toHaveLength(1);
    });

    it.each([true, false])(
        'retains key actions only for the owner: %s',
        (canManage) => {
            const client = {
                ...selectedClient,
                can_manage: canManage,
                tokens: [
                    {
                        id: 6,
                        name: 'Agent key',
                        abilities: ['zernio.posts.read', 'adkit.ads.publish'],
                        allowed_account_ids: [],
                        last_used_at: null,
                        expires_at: '2026-11-23T20:00:00Z',
                        revoked_at: null,
                        created_at: '2026-10-23T20:00:00Z',
                    },
                ],
            };
            const markup = renderToStaticMarkup(
                <AgentAccess {...baseProps} selectedClient={client} />,
            );

            expect(markup).toContain('API keys');
            expect(markup.includes('Revoke key')).toBe(canManage);
            expect(markup.includes('Replace access key')).toBe(canManage);
            if (canManage) {
                expect(markup).toContain('Agent key');
                expect(markup).toContain('Read');
                expect(markup).toContain('Publish');
                expect(markup).toContain('Revoked and expired keys are hidden');
            }
        },
    );

    it('keeps the one-time plaintext notice outside dialogs even without a selected user', () => {
        const markup = renderToStaticMarkup(
            <AgentAccess
                {...baseProps}
                selectedClient={null}
                token_plaintext="secret-token"
                token_notice="Store this key now."
            />,
        );

        expect(markup).toContain('Store this key now.');
        expect(markup.match(/value="secret-token"/g)).toHaveLength(1);
        expect(markup).toContain('Copy access key');
    });

    it('never calls providers during page rendering', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);

        renderToStaticMarkup(
            <AgentAccess
                {...baseProps}
                selectedClient={{
                    ...selectedClient,
                    can_assign_profiles: true,
                    can_assign_adkit_accounts: true,
                }}
            />,
        );

        expect(fetch).not.toHaveBeenCalled();
    });
});
