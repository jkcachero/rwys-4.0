import * as React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('react', { spy: true });
vi.mock('@inertiajs/react', () => ({
    Head: vi.fn(() => null),
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: vi.fn(() => ({ props: {} })),
    router: { on: vi.fn(() => vi.fn()) },
}));
vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ isCurrentOrParentUrl: () => false }),
}));

import { router, usePage } from '@inertiajs/react';
import AgentAccessNavigation from '@/components/agent-access-navigation';
import SettingsLayout from '@/layouts/settings/layout';
import ProviderAccounts, { requestProviderAccounts } from './provider-accounts';

const pagination = { page: 1, limit: 25, total: 1, pages: 1, has_more: false };
const multiplePages = { ...pagination, total: 26, pages: 2, has_more: true };
const client = {
    id: 1,
    name: 'RWYS client',
    owner: { id: 2, name: 'Owner', email: 'owner@example.test' },
};
const outsideClient = {
    id: 26,
    name: 'Outside-page client',
    owner: { id: 27, name: 'Outside owner', email: 'outside@example.test' },
};
const profile = {
    id: '0123456789abcdef01234567',
    name: 'Studio',
    account_count: 4,
};
const claim = {
    id: 5,
    client_id: 1,
    profile_id: profile.id,
    name: profile.name,
    active: true,
    last_synced_at: null,
    account_count: 4,
};
const props = {
    clients: { data: [client], pagination },
    selectedClientId: null,
    mappings: [],
    providerEnabled: true,
};

type ElementProps = {
    children?: React.ReactNode;
    id?: string;
    value?: string | number;
    disabled?: boolean;
    onClick?: () => void;
    onChange?: (event: { target: { value: string } }) => void;
};
function elements(node: React.ReactNode): React.ReactElement<ElementProps>[] {
    return React.Children.toArray(node).flatMap((child) =>
        React.isValidElement<ElementProps>(child)
            ? [child, ...elements(child.props.children)]
            : [],
    );
}

// Follow the sibling's hook harness without adding a DOM dependency.
function mountPage(
    overrides: Partial<React.ComponentProps<typeof ProviderAccounts>> = {},
) {
    const state: unknown[] = [];
    const refs: { current: unknown }[] = [];
    let stateIndex = 0;
    let refIndex = 0;
    vi.mocked(React.useState).mockImplementation((initial?: unknown) => {
        const index = stateIndex++;
        if (!(index in state)) state[index] = initial;
        return [
            state[index],
            (value: unknown) => {
                state[index] =
                    typeof value === 'function' ? value(state[index]) : value;
            },
        ];
    });
    vi.mocked(React.useRef).mockImplementation(
        (initial?: unknown) => (refs[refIndex++] ??= { current: initial }),
    );
    vi.mocked(React.useEffect).mockImplementation(() => {});
    let tree: React.ReactNode;
    const render = () => {
        stateIndex = 0;
        refIndex = 0;
        tree = ProviderAccounts({
            ...props,
            selectedClientId: 1,
            ...overrides,
        });
    };
    render();
    return {
        render,
        button: (label: string) =>
            elements(tree).find((node) => node.props.children === label)!,
        input: (id: string) =>
            elements(tree).find((node) => node.props.id === id)!,
        nodes: () => elements(tree),
        markup: () => renderToStaticMarkup(tree),
    };
}
function success(data: unknown, status = 200) {
    return { ok: true, status, json: async () => data };
}
async function loadProfiles(page: ReturnType<typeof mountPage>) {
    page.button('Load Zernio profiles').props.onClick!();
    await vi.waitFor(() => {
        page.render();
        expect(
            elements(page.input('assignment-profile').props.children),
        ).toHaveLength(2);
    });
}
function selectProfile(page: ReturnType<typeof mountPage>, id = profile.id) {
    page.input('assignment-profile').props.onChange!({ target: { value: id } });
    page.render();
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Profile assignments', () => {
    it('renders native client and profile dropdowns without search or automatic discovery', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const markup = renderToStaticMarkup(<ProviderAccounts {...props} />);
        expect(markup.match(/<select\b/g)).toHaveLength(2);
        expect(markup).toContain(
            'for="assignment-client">Registered client</label>',
        );
        expect(markup).toContain('Owner, owner@example.test');
        expect(markup).toContain(
            'for="assignment-profile">Zernio profile</label>',
        );
        expect(markup).toContain('Select a Zernio profile');
        expect(markup).not.toContain('<input');
        expect(markup).not.toContain('search');
        expect(markup).not.toContain('Previous profiles');
        expect(markup).not.toContain('Next profiles');
        expect(markup).not.toContain('Next clients');
        expect(markup).toMatch(/disabled=""[^>]*>Load Zernio profiles/);
        expect(markup).toMatch(/disabled=""[^>]*>Assign profile/);
        expect(markup).toContain('not just clients you own');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('keeps the initial outside-page client selectable without duplicating on-page clients', () => {
        const markup = renderToStaticMarkup(
            <ProviderAccounts
                {...props}
                selectedClientId={26}
                selectedClient={outsideClient}
            />,
        );
        expect(markup).toMatch(
            /<option value="26" selected="">Outside-page client/,
        );
        expect(markup).not.toMatch(/disabled=""[^>]*>Load Zernio profiles/);
        const onPage = renderToStaticMarkup(
            <ProviderAccounts
                {...props}
                selectedClientId={1}
                selectedClient={client}
            />,
        );
        expect(onPage.match(/<option value="1"/g)).toHaveLength(1);
    });

    it('clears outside-page selection and makes no request on client selection', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const page = mountPage({
            selectedClientId: 26,
            selectedClient: outsideClient,
        });
        page.input('assignment-client').props.onChange!({
            target: { value: '' },
        });
        page.render();
        expect(page.button('Load Zernio profiles').props.disabled).toBe(true);
        expect(
            page
                .nodes()
                .some(
                    (node) => node.type === 'option' && node.props.value === 26,
                ),
        ).toBe(false);
        page.input('assignment-client').props.onChange!({
            target: { value: '1' },
        });
        page.render();
        expect(page.button('Load Zernio profiles').props.disabled).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('pages clients with bounded local GETs, blocks duplicate clicks and clears client scope', async () => {
        const fetch = vi.fn().mockResolvedValue(
            success({
                data: [outsideClient],
                pagination: { ...multiplePages, page: 2, has_more: false },
            }),
        );
        vi.stubGlobal('fetch', fetch);
        const page = mountPage({
            clients: { data: [client], pagination: multiplePages },
        });
        expect(page.button('Previous clients').props.disabled).toBe(true);
        const next = page.button('Next clients');
        next.props.onClick!();
        next.props.onClick!();
        page.render();
        expect(page.input('assignment-client').props.value).toBe('');
        await vi.waitFor(() => {
            page.render();
            expect(page.input('assignment-client').props.disabled).toBe(false);
        });
        expect(fetch).toHaveBeenCalledExactlyOnceWith(
            '/settings/provider-accounts/clients?page=2&limit=25',
            expect.objectContaining({
                method: 'GET',
                credentials: 'same-origin',
                body: undefined,
            }),
        );
        expect(page.button('Next clients').props.disabled).toBe(true);
        expect(page.button('Previous clients').props.disabled).toBe(false);
    });

    it('preserves the provider config hint independently of schema readiness', () => {
        const markup = renderToStaticMarkup(
            <ProviderAccounts
                {...props}
                selectedClientId={1}
                providerEnabled={false}
            />,
        );
        expect(markup).toContain('AGENT_ZERNIO_ENABLED=true');
        expect(markup).toContain('ZERNIO_API_KEY');
        expect(markup).toContain('Publishing is not required for discovery.');
        expect(markup).toMatch(/disabled=""[^>]*>Load Zernio profiles/);
        expect(markup).not.toMatch(/id="assignment-client"[^>]*disabled/);
    });

    it('requires migration rather than a provider-key change when schema is not ready', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const page = mountPage({
            profileAssignmentsReady: false,
            mappings: [claim],
        });
        expect(page.markup()).toContain('migration');
        expect(page.markup()).not.toContain('ZERNIO_API_KEY');
        expect(page.button('Load Zernio profiles').props.disabled).toBe(true);
        expect(page.button('Sync accounts').props.disabled).toBe(true);
        page.button('Load Zernio profiles').props.onClick!();
        page.button('Sync accounts').props.onClick!();
        expect(fetch).not.toHaveBeenCalled();
    });

    it.each([201, 200])(
        'uses actual profiles and posts only selected IDs once, status %s',
        async (status) => {
            vi.stubGlobal('document', { querySelector: () => null });
            const fetch = vi
                .fn()
                .mockResolvedValueOnce(success({ data: [profile], pagination }))
                .mockResolvedValueOnce(success({ data: claim }, status));
            vi.stubGlobal('fetch', fetch);
            const page = mountPage();
            await loadProfiles(page);
            expect(fetch.mock.calls[0][0]).toBe(
                '/settings/provider-accounts/discover?page=1&limit=25&client_id=1',
            );
            expect(page.markup()).toContain('Studio (4 accounts)');
            selectProfile(page);
            expect(page.markup()).toContain(
                'Assign <strong>Studio</strong> and all its social accounts to <strong>RWYS client</strong>?',
            );
            const assign = page.button('Assign profile');
            assign.props.onClick!();
            assign.props.onClick!();
            await vi.waitFor(() => {
                page.render();
                expect(page.markup()).toContain(
                    status === 201 ? 'Profile assigned' : 'Profile synced',
                );
            });
            expect(fetch).toHaveBeenCalledTimes(2);
            expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({
                client_id: 1,
                profile_id: profile.id,
            });
            expect(page.button('Assign profile').props.disabled).toBe(true);
            expect(page.markup()).toContain('My API tokens');
            expect(page.markup()).toContain('unaffected');
            expect(page.markup()).toContain('read-only');
            expect(page.markup()).toContain('separate permission');
        },
    );

    it('does not invent a count for a profile whose provider count is unknown', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                success({
                    data: [{ ...profile, account_count: null }],
                    pagination,
                }),
            ),
        );
        const page = mountPage();
        await loadProfiles(page);
        const options = elements(
            page.input('assignment-profile').props.children,
        );
        expect(renderToStaticMarkup(options[1])).toBe(
            `<option value="${profile.id}">Studio</option>`,
        );
        expect(page.button('Next profiles')).toBeUndefined();
    });

    it('rejects forged, non-24-hex and off-page profile selections', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(success({ data: [profile], pagination }));
        vi.stubGlobal('fetch', fetch);
        const page = mountPage();
        await loadProfiles(page);
        for (const id of ['bad', 'ffffffffffffffffffffffff']) {
            selectProfile(page, id);
            expect(page.button('Assign profile').props.disabled).toBe(true);
            page.button('Assign profile').props.onClick!();
        }
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('does not assign a malformed profile ID even when returned by discovery', async () => {
        const fetch = vi.fn().mockResolvedValue(
            success({
                data: [{ ...profile, id: 'not-a-profile-id' }],
                pagination,
            }),
        );
        vi.stubGlobal('fetch', fetch);
        const page = mountPage();
        await loadProfiles(page);
        selectProfile(page, 'not-a-profile-id');
        expect(page.button('Assign profile').props.disabled).toBe(true);
        page.button('Assign profile').props.onClick!();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('clears a loaded profile and session claims when changing to another registered client', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(success({ data: [profile], pagination }));
        vi.stubGlobal('fetch', fetch);
        const page = mountPage({
            clients: { data: [client, outsideClient], pagination },
            mappings: [claim],
        });
        await loadProfiles(page);
        selectProfile(page);
        page.input('assignment-client').props.onChange!({
            target: { value: '26' },
        });
        page.render();
        expect(page.input('assignment-profile').props.value).toBe('');
        expect(page.button('Assign profile').props.disabled).toBe(true);
        expect(page.button('Sync accounts')).toBeUndefined();
        expect(page.markup()).not.toContain('Next: the client creates');
        expect(page.markup()).toContain(
            'Only assignments made for this selected client',
        );
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('clears the profile choice immediately on profile pagination and does not group social accounts', async () => {
        let resolve!: (value: unknown) => void;
        const fetch = vi
            .fn()
            .mockResolvedValueOnce(
                success({ data: [profile], pagination: multiplePages }),
            )
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
        vi.stubGlobal('fetch', fetch);
        const page = mountPage();
        await loadProfiles(page);
        selectProfile(page);
        expect(page.button('Previous profiles').props.disabled).toBe(true);
        page.button('Next profiles').props.onClick!();
        page.render();
        expect(page.input('assignment-profile').props.value).toBe('');
        expect(page.button('Assign profile').props.disabled).toBe(true);
        expect(fetch.mock.calls[1][0]).toBe(
            '/settings/provider-accounts/discover?page=2&limit=25&client_id=1',
        );
        resolve(
            success({
                data: [{ ...profile, name: 'Page two' }],
                pagination: { ...multiplePages, page: 2, has_more: false },
            }),
        );
        await vi.waitFor(() => {
            page.render();
            expect(page.markup()).toContain('Page two');
        });
        expect(page.button('Next profiles').props.disabled).toBe(true);
        expect(page.input('assignment-profile').props.value).toBe('');
    });

    it.each(['clients', 'discover'] as const)(
        'aborts %s and ignores late results on client change',
        async (action) => {
            let resolve!: (value: unknown) => void;
            const fetch = vi.fn(
                (_url: string, _options: RequestInit) =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
            vi.stubGlobal('fetch', fetch);
            const page = mountPage({
                clients: { data: [client], pagination: multiplePages },
            });
            page.button(
                action === 'clients' ? 'Next clients' : 'Load Zernio profiles',
            ).props.onClick!();
            page.input('assignment-client').props.onChange!({
                target: { value: '' },
            });
            expect(fetch.mock.calls[0][1].signal?.aborted).toBe(true);
            resolve(
                success({
                    data: action === 'clients' ? [outsideClient] : [profile],
                    pagination,
                }),
            );
            await new Promise((done) => setTimeout(done, 0));
            page.render();
            expect(page.input('assignment-client').props.value).toBe('');
            expect(
                elements(page.input('assignment-profile').props.children),
            ).toHaveLength(1);
            expect(page.markup()).not.toContain('Outside-page client');
            expect(page.button('Load Zernio profiles').props.disabled).toBe(
                true,
            );
        },
    );

    it('shows bounded profile claims without fabricating child accounts, and no unscoped sync action', () => {
        const markup = renderToStaticMarkup(
            <ProviderAccounts
                {...props}
                mappings={[{ ...claim, name: null, active: false }]}
            />,
        );
        expect(markup).toContain('Assigned profiles');
        expect(markup).toContain(profile.id);
        expect(markup).toContain('4 accounts');
        expect(markup).toContain('Inactive');
        expect(markup).toContain('Showing up to 25');
        expect(markup).not.toContain('Sync accounts</button>');
        expect(markup).not.toContain('<li');
        expect(markup).not.toContain('Allow publishing');
        expect(markup).not.toContain('Create token');
        expect(markup).not.toMatch(/href="[^"]*(?:client_id|clientId|mint)/);
    });

    it('syncs only the matching client/profile explicitly, replaces the claim and displays known returned accounts', async () => {
        vi.stubGlobal('document', { querySelector: () => null });
        const accounts = [
            {
                id: 8,
                client_id: 1,
                profile_id: profile.id,
                provider_account_id: 'local-social',
                platform: 'instagram',
                active: false,
                publishing_enabled: false,
            },
        ];
        const fetch = vi
            .fn()
            .mockResolvedValue(
                success({ data: { ...claim, account_count: 5, accounts } }),
            );
        vi.stubGlobal('fetch', fetch);
        const page = mountPage({
            mappings: [claim, { ...claim, id: 6, client_id: 26 }],
        });
        expect(
            page
                .nodes()
                .filter((node) => node.props.children === 'Sync accounts'),
        ).toHaveLength(1);
        expect(fetch).not.toHaveBeenCalled();
        const sync = page.button('Sync accounts');
        sync.props.onClick!();
        sync.props.onClick!();
        await vi.waitFor(() => {
            page.render();
            expect(page.markup()).toContain('Profile synced');
        });
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
            client_id: 1,
            profile_id: profile.id,
        });
        expect(page.markup()).toContain('5 accounts');
        expect(page.markup()).toContain('local-social');
        expect(page.markup()).toContain('Inactive');
        expect(page.markup()).toContain('Sync accounts');
        expect(page.markup()).toContain('null-scope');
        expect(page.markup()).toContain('restricted');
    });

    it('ignores a late assignment after changing client scope', async () => {
        vi.stubGlobal('document', { querySelector: () => null });
        let resolve!: (value: unknown) => void;
        const fetch = vi.fn().mockImplementation(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        vi.stubGlobal('fetch', fetch);
        const page = mountPage({ mappings: [claim] });
        page.button('Sync accounts').props.onClick!();
        page.input('assignment-client').props.onChange!({
            target: { value: '26' },
        });
        resolve(success({ data: claim }));
        await new Promise((done) => setTimeout(done, 0));
        page.render();
        expect(page.markup()).not.toContain('Profile synced');
        expect(page.button('Sync accounts')).toBeUndefined();
        expect(page.markup()).toContain('Only assignments made');
    });

    it('renders every known returned social account under one profile, without importing on render', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const accounts = ['instagram', 'facebook', 'linkedin', 'tiktok'].map(
            (platform, index) => ({
                id: index + 1,
                client_id: 1,
                profile_id: profile.id,
                provider_account_id: `social-${index}`,
                platform,
                active: index !== 0,
                publishing_enabled: false,
            }),
        );
        const markup = renderToStaticMarkup(
            <ProviderAccounts
                {...props}
                selectedClientId={1}
                mappings={[{ ...claim, accounts }]}
            />,
        );
        expect(markup.match(/<li>/g)).toHaveLength(4);
        expect(markup.match(/>Sync accounts<\/button>/g)).toHaveLength(1);
        expect(markup.match(/>Read-only<\/span>/g)).toHaveLength(4);
        expect(fetch).not.toHaveBeenCalled();
    });

    it.each([409, 502, 503])(
        'keeps a failed assignment retryable without changing local claims (%s)',
        async (status) => {
            vi.stubGlobal('document', { querySelector: () => null });
            const fetch = vi
                .fn()
                .mockResolvedValueOnce(success({ data: [profile], pagination }))
                .mockResolvedValueOnce({ ok: false, status });
            vi.stubGlobal('fetch', fetch);
            const page = mountPage();
            await loadProfiles(page);
            selectProfile(page);
            page.button('Assign profile').props.onClick!();
            await vi.waitFor(() => {
                page.render();
                expect(page.markup()).toContain('role="alert"');
            });
            expect(page.button('Assign profile').props.disabled).toBe(false);
            expect(page.button('Load Zernio profiles').props.disabled).toBe(
                false,
            );
            expect(page.markup()).toContain('No assignments to show.');
            expect(page.button('Sync accounts')).toBeUndefined();
        },
    );

    it('posts a strict DTO with secure session options, ignoring all metadata', async () => {
        vi.stubGlobal('document', {
            querySelector: () => ({ getAttribute: () => 'csrf-value' }),
        });
        const fetch = vi.fn().mockResolvedValue(success({ data: claim }, 201));
        vi.stubGlobal('fetch', fetch);
        const signal = new AbortController().signal;
        const selection = {
            client_id: 1,
            profile_id: profile.id,
            provider_account_id: 'never-send',
            name: 'never-send',
            account_count: 999,
        };
        await requestProviderAccounts(
            '/settings/provider-accounts',
            signal,
            selection,
        );
        expect(fetch).toHaveBeenCalledWith(
            '/settings/provider-accounts',
            expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                signal,
                body: JSON.stringify({ client_id: 1, profile_id: profile.id }),
                headers: expect.objectContaining({
                    'X-CSRF-TOKEN': 'csrf-value',
                }),
            }),
        );
    });

    it.each([403, 409, 423, 500, 502, 503])(
        'uses safe local errors without reading server bodies (%s)',
        async (status) => {
            const json = vi.fn(() => ({ message: 'private stack trace' }));
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue({ ok: false, status, json }),
            );
            await expect(
                requestProviderAccounts(
                    '/settings/provider-accounts/discover',
                    new AbortController().signal,
                ),
            ).rejects.toThrow(
                (
                    {
                        403: 'You no longer have permission',
                        409: 'Profile is already assigned',
                        423: 'Confirm your password',
                        502: 'Zernio profile discovery is unavailable',
                        503: 'Zernio discovery is unavailable',
                    } as Record<number, string>
                )[status] ?? 'Profile action failed',
            );
            expect(json).not.toHaveBeenCalled();
        },
    );

    it('sanitizes malformed JSON errors', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => {
                    throw new Error('private stack');
                },
            }),
        );
        await expect(
            requestProviderAccounts(
                '/settings/provider-accounts/clients',
                new AbortController().signal,
            ),
        ).rejects.toThrow('Profile action failed');
    });

    it.each([403, 423])(
        'stops actions and offers password confirmation only for 423 (%s)',
        async (status) => {
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue({ ok: false, status }),
            );
            const page = mountPage();
            page.button('Load Zernio profiles').props.onClick!();
            await vi.waitFor(() => {
                page.render();
                expect(page.input('assignment-client').props.disabled).toBe(
                    true,
                );
            });
            expect(page.button('Load Zernio profiles').props.disabled).toBe(
                true,
            );
            expect(page.markup().includes('Confirm password</a>')).toBe(
                status === 423,
            );
        },
    );

    it.each([
        ['admin', true],
        ['admin', false],
        ['user', true],
        ['user', false],
        ['admin', undefined],
    ])(
        'preserves single sidebar entry for %s with permission %s',
        (role, permission) => {
            vi.mocked(usePage).mockReturnValue({
                props: {
                    auth: {
                        user: { role },
                        permissions: { assignProviderAccounts: permission },
                    },
                },
            } as unknown as ReturnType<typeof usePage>);
            const markup = renderToStaticMarkup(<SettingsLayout />);
            expect(
                markup.match(/href="\/settings\/agent-access"/g),
            ).toHaveLength(1);
            expect(markup).not.toContain('href="/settings/provider-accounts"');
            expect(markup).not.toContain('href="/settings/agent-tokens"');
        },
    );

    it.each([true, false, undefined])(
        'passes only explicit operator permission to navigation (%s)',
        (permission) => {
            vi.mocked(usePage).mockReturnValue({
                props: {
                    auth: {
                        user: { role: 'admin' },
                        permissions: { assignProviderAccounts: permission },
                    },
                },
            } as unknown as ReturnType<typeof usePage>);
            expect(
                mountPage()
                    .nodes()
                    .find((node) => node.type === AgentAccessNavigation)?.props,
            ).toEqual({
                active: 'accounts',
                canAssignProviderAccounts: permission === true,
            });
            expect(ProviderAccounts.layout.breadcrumbs).toEqual([
                { title: 'Agent access', href: '/settings/agent-access' },
                {
                    title: 'Account assignments',
                    href: '/settings/provider-accounts',
                },
            ]);
        },
    );

    it('clears and aborts state on navigation and registers browser restore cleanup', () => {
        const setters: ReturnType<typeof vi.fn>[] = [];
        vi.mocked(React.useState).mockImplementation((initial?: unknown) => {
            const setter = vi.fn();
            setters.push(setter);
            return [initial, setter] as ReturnType<typeof React.useState>;
        });
        const cleanup: (() => void)[] = [];
        vi.mocked(React.useEffect).mockImplementation((effect) => {
            const result = effect();
            if (result) cleanup.push(result);
        });
        const addEventListener = vi.fn();
        vi.stubGlobal('window', {
            addEventListener,
            removeEventListener: vi.fn(),
        });
        const routerOn = vi.spyOn(router, 'on');
        renderToStaticMarkup(<ProviderAccounts {...props} />);
        const before = routerOn.mock.calls.find(
            ([event]) => event === 'before',
        )?.[1];
        expect(before).toBeDefined();
        (before as () => void)();
        expect(
            setters.some((setter) =>
                setter.mock.calls.some(([value]) => value === null),
            ),
        ).toBe(true);
        expect(addEventListener.mock.calls.map(([event]) => event)).toEqual([
            'pagehide',
            'pageshow',
            'popstate',
        ]);
        cleanup.forEach((callback) => callback());
    });
});
