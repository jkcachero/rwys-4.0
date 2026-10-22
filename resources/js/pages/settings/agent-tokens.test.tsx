import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

const { page } = vi.hoisted(() => ({
    page: {
        props: { auth: { permissions: { assignProviderAccounts: false } } },
    },
}));

vi.mock('react', { spy: true });

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { on: vi.fn(() => vi.fn()) },
    usePage: () => page,
}));

import AgentTokens, { mutateAgentToken } from './agent-tokens';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    page.props.auth.permissions.assignProviderAccounts = false;
});

describe('Agent token settings', () => {
    const props = {
        clients: [
            {
                id: 1,
                name: 'Owned client',
                active: true,
                accounts: [
                    { id: 2, platform: 'instagram', publishing_enabled: true },
                ],
            },
        ],
        tokens: {
            data: [
                {
                    id: 3,
                    client_id: 1,
                    name: 'My agent',
                    abilities: ['context.read'],
                    allowed_account_ids: null,
                    created_at: '2026-10-22',
                    last_used_at: null,
                    expires_at: '2026-11-21',
                },
            ],
            prev_page_url: null,
            next_page_url: null,
        },
        readAbilities: [
            'context.read',
            'zernio.accounts.read',
            'zernio.posts.read',
            'zernio.analytics.read',
            'operations.read',
        ],
        publishingEnabled: true,
        instagramSchedulingEnabled: false,
    };
    const issuedResponse = {
        plain_text_token: `3|${'a'.repeat(40)}`,
        token: {
            ...props.tokens.data[0],
            created_at: '2026-10-22T12:00:00.000000Z',
            expires_at: '2026-11-21T12:00:00.000000Z',
        },
    };
    const uncertainMessage =
        'Token action could not be confirmed. Refresh the token list before trying again.';

    it('renders token-only onboarding, bounded scope, and management actions without a plaintext token', () => {
        const markup = renderToStaticMarkup(<AgentTokens {...props} />);
        expect(markup).toContain('Create token');
        expect(markup).toContain('Rotate');
        expect(markup).toContain('Revoke');
        expect(markup).toContain('does not connect a provider');
        expect(markup).toContain('30 days');
        expect(markup).toContain('Allow publishing');
        expect(markup).toContain('zernio.analytics.read');
        expect(markup).not.toContain('Your new token');
        expect(markup).not.toContain('localStorage');
    });

    it('explains missing personal client access without exposing an unusable form or operator link', () => {
        const markup = renderToStaticMarkup(
            <AgentTokens {...props} clients={[]} publishingEnabled={false} />,
        );
        expect(markup).toContain('No personal client access');
        expect(markup).toContain('Ask an operator');
        expect(markup).not.toContain('<form');
        expect(markup).not.toContain('agent-client');
        expect(markup).not.toContain('Token name');
        expect(markup).not.toContain('Read permissions');
        expect(markup).not.toContain('href="/settings/provider-accounts"');
        expect(markup).not.toContain('Connect with');
    });

    it('offers account assignments to operators without implying they can mint tokens for other owners', () => {
        page.props.auth.permissions.assignProviderAccounts = true;
        const markup = renderToStaticMarkup(
            <AgentTokens {...props} clients={[]} />,
        );
        expect(markup).toContain('No personal client access');
        expect(markup).toContain('Manage account assignments');
        expect(markup).toContain('href="/settings/provider-accounts"');
        expect(markup).toContain('only for a client you own');
        expect(markup).not.toContain('<form');
    });

    it('selects the sole active owned client without issuing a token', () => {
        const markup = renderToStaticMarkup(<AgentTokens {...props} />);
        expect(markup).toContain(
            '<option value="1" selected="">Owned client</option>',
        );
        expect(markup).toContain('Create API token');
        expect(markup).toContain('not for other client owners');
        expect(markup).not.toContain('Your new token');
    });

    it('requires an explicit selection when multiple owned clients are active', () => {
        const markup = renderToStaticMarkup(
            <AgentTokens
                {...props}
                clients={[
                    ...props.clients,
                    { ...props.clients[0], id: 2, name: 'Second client' },
                ]}
            />,
        );
        expect(markup).toContain(
            '<option value="" selected="">Select a client</option>',
        );
    });

    it('disables publishing for an unsupported selected account', () => {
        vi.mocked(React.useState).mockImplementationOnce(() => ['1', vi.fn()]);
        const markup = renderToStaticMarkup(<AgentTokens {...props} />);
        expect(markup).toMatch(/role="checkbox"[^>]*disabled=""/);
    });

    it('shows disabled clients and blocks creation and rotation but still permits revocation', () => {
        vi.mocked(React.useState)
            .mockImplementationOnce(() => ['1', vi.fn()])
            .mockImplementationOnce(() => ['Agent', vi.fn()]);
        const markup = renderToStaticMarkup(
            <AgentTokens
                {...props}
                clients={[{ ...props.clients[0], active: false }]}
            />,
        );
        expect(markup).toContain('Owned client (disabled)');
        expect(markup).toContain('No personal client access');
        expect(markup).not.toContain('<form');
        expect(markup).toMatch(/disabled=""[^>]*>Rotate/);
        expect(markup).not.toMatch(/disabled=""[^>]*>Revoke/);
        expect(markup).toContain('>Revoke</button>');
    });

    it('enables publishing for a supported selected account and renders metadata-only pagination', () => {
        vi.mocked(React.useState).mockImplementationOnce(() => ['1', vi.fn()]);
        const markup = renderToStaticMarkup(
            <AgentTokens
                {...props}
                clients={[
                    {
                        ...props.clients[0],
                        accounts: [
                            {
                                id: 2,
                                platform: 'linkedin',
                                publishing_enabled: true,
                            },
                        ],
                    },
                ]}
                tokens={{
                    ...props.tokens,
                    prev_page_url: '/settings/agent-tokens?page=1',
                    next_page_url: '/settings/agent-tokens?page=3',
                }}
            />,
        );
        expect(
            markup
                .match(/<label\b[^>]*>.*?<\/label>/gs)
                ?.find((label) => label.includes('Allow publishing')),
        ).not.toContain('disabled=""');
        expect(markup).toContain('href="/settings/agent-tokens?page=1"');
        expect(markup).toContain('href="/settings/agent-tokens?page=3"');
        expect(markup).not.toContain('plain_text_token');
        expect(markup).not.toContain('Your new token');
    });

    it('offers scheduling only as unchecked explicit write access for an eligible Instagram scope', () => {
        const markup = renderToStaticMarkup(
            <AgentTokens {...props} instagramSchedulingEnabled />,
        );
        const label = markup
            .match(/<label\b[^>]*>.*?<\/label>/gs)
            ?.find((item) => item.includes('Allow scheduled Instagram images'));
        expect(label).toContain('aria-checked="false"');
        expect(label).not.toContain('disabled=""');
    });

    it.each(['server', 'publishing', 'account', 'platform'])(
        'disables scheduling when the %s gate is unavailable',
        (gate) => {
            const markup = renderToStaticMarkup(
                <AgentTokens
                    {...props}
                    instagramSchedulingEnabled={gate !== 'server'}
                    publishingEnabled={gate !== 'publishing'}
                    clients={[
                        {
                            ...props.clients[0],
                            accounts: [
                                {
                                    id: 2,
                                    platform:
                                        gate === 'platform'
                                            ? 'linkedin'
                                            : 'instagram',
                                    publishing_enabled: gate !== 'account',
                                },
                            ],
                        },
                    ]}
                />,
            );
            expect(
                markup
                    .match(/<label\b[^>]*>.*?<\/label>/gs)
                    ?.find((label) =>
                        label.includes('Allow scheduled Instagram images'),
                    ),
            ).toContain('disabled=""');
        },
    );

    it('uses a CSRF-protected no-store session fetch, never Inertia or redirects', async () => {
        vi.stubGlobal('document', {
            querySelector: () => ({ getAttribute: () => 'csrf-value' }),
        });
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 201,
            json: async () => issuedResponse,
        });
        vi.stubGlobal('fetch', fetchMock);
        const signal = new AbortController().signal;
        await expect(
            mutateAgentToken(
                '/settings/agent-tokens',
                'POST',
                { name: 'Agent', client_id: 1 },
                signal,
            ),
        ).resolves.toEqual(issuedResponse);
        expect(fetchMock).toHaveBeenCalledWith(
            '/settings/agent-tokens',
            expect.objectContaining({
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                signal,
                headers: expect.objectContaining({
                    'X-CSRF-TOKEN': 'csrf-value',
                    Accept: 'application/json',
                }),
            }),
        );
    });

    it('does not expose arbitrary error response bodies', async () => {
        vi.stubGlobal('document', { querySelector: () => null });
        const json = vi.fn();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, status: 423, json }),
        );
        await expect(
            mutateAgentToken(
                '/settings/agent-tokens',
                'POST',
                {},
                new AbortController().signal,
            ),
        ).rejects.toThrow('Confirm your password');
        expect(json).not.toHaveBeenCalled();
    });

    it.each([
        ['POST', 200],
        ['POST', 202],
        ['POST', 204],
        ['DELETE', 200],
        ['DELETE', 201],
        ['DELETE', 202],
    ] as const)(
        'rejects unexpected %s status %s without reading a body or retrying',
        async (method, status) => {
            vi.stubGlobal('document', { querySelector: () => null });
            const json = vi.fn().mockResolvedValue(issuedResponse);
            const fetchMock = vi
                .fn()
                .mockResolvedValue({ ok: true, status, json });
            vi.stubGlobal('fetch', fetchMock);
            await expect(
                mutateAgentToken(
                    '/settings/agent-tokens/3',
                    method,
                    undefined,
                    new AbortController().signal,
                ),
            ).rejects.toThrow(uncertainMessage);
            expect(json).not.toHaveBeenCalled();
            expect(fetchMock).toHaveBeenCalledTimes(1);
        },
    );

    it('accepts only a 204 for revocation without parsing a body', async () => {
        vi.stubGlobal('document', { querySelector: () => null });
        const json = vi.fn();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: true, status: 204, json }),
        );
        await expect(
            mutateAgentToken(
                '/settings/agent-tokens/3',
                'DELETE',
                undefined,
                new AbortController().signal,
            ),
        ).resolves.toEqual({});
        expect(json).not.toHaveBeenCalled();
    });

    it.each([
        null,
        [],
        {},
        { ...issuedResponse, plain_text_token: 'one-time' },
        { ...issuedResponse, plain_text_token: `4|${'a'.repeat(40)}` },
        { ...issuedResponse, plain_text_token: `3|${'a'.repeat(39)}` },
        { ...issuedResponse, plain_text_token: `3|${'a'.repeat(129)}` },
        { ...issuedResponse, plain_text_token: `3|${'a'.repeat(39)} ` },
        { ...issuedResponse, plain_text_token: `3|${'a'.repeat(40)}\n` },
        ...[
            { id: 0 },
            { id: 3.5 },
            { id: '3' },
            { id: Infinity },
            { client_id: -1 },
            { client_id: '1' },
            { client_id: NaN },
            { name: null },
            { abilities: 'context.read' },
            { abilities: [1] },
            { expires_at: 'invalid' },
            { expires_at: null },
            { allowed_account_ids: '1' },
            { allowed_account_ids: [0] },
            { allowed_account_ids: ['1'] },
            { created_at: null },
            { last_used_at: 1 },
        ].map((token) => ({
            ...issuedResponse,
            token: { ...issuedResponse.token, ...token },
        })),
    ])(
        'rejects malformed issued token response %# with safe uncertainty guidance',
        async (body) => {
            vi.stubGlobal('document', { querySelector: () => null });
            const fetchMock = vi.fn().mockResolvedValue({
                ok: true,
                status: 201,
                json: async () => body,
            });
            vi.stubGlobal('fetch', fetchMock);
            await expect(
                mutateAgentToken(
                    '/settings/agent-tokens',
                    'POST',
                    {},
                    new AbortController().signal,
                ),
            ).rejects.toThrow(uncertainMessage);
            expect(fetchMock).toHaveBeenCalledTimes(1);
        },
    );

    it('does not expose malformed JSON errors or retry an uncertain issuance', async () => {
        vi.stubGlobal('document', { querySelector: () => null });
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 201,
            json: async () => {
                throw new SyntaxError('private response body');
            },
        });
        vi.stubGlobal('fetch', fetchMock);
        await expect(
            mutateAgentToken(
                '/settings/agent-tokens',
                'POST',
                {},
                new AbortController().signal,
            ),
        ).rejects.toThrow(uncertainMessage);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it.each(['/settings/agent-tokens', '/settings/agent-tokens/2/rotate'])(
        'accepts a validated issuance at %s with bounded scope and a 128-character secret',
        async (url) => {
            vi.stubGlobal('document', { querySelector: () => null });
            const body = {
                plain_text_token: `3|${'a'.repeat(128)}`,
                token: {
                    ...issuedResponse.token,
                    allowed_account_ids: [1, 2],
                    last_used_at: '2026-10-22T12:00:00Z',
                },
            };
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue({
                    ok: true,
                    status: 201,
                    json: async () => body,
                }),
            );
            await expect(
                mutateAgentToken(url, 'POST', {}, new AbortController().signal),
            ).resolves.toEqual(body);
        },
    );

    it.each([
        ['create', 204],
        ['rotate', 204],
        ['create', 201],
        ['rotate', 201],
        ['revoke', 200],
    ] as const)(
        'keeps token list and name unchanged after uncertain %s status %s',
        async (action, status) => {
            vi.stubGlobal('document', { querySelector: () => null });
            const fetchMock = vi.fn().mockResolvedValue({
                ok: true,
                status,
                json: async () => ({
                    plain_text_token: 'private malformed body',
                }),
            });
            vi.stubGlobal('fetch', fetchMock);
            const setters = Array.from({ length: 11 }, () => vi.fn());
            let stateIndex = 0;
            vi.mocked(React.useState).mockImplementation(
                (initial?: unknown): [unknown, React.Dispatch<unknown>] => [
                    typeof initial === 'function' ? initial() : initial,
                    setters[stateIndex++],
                ],
            );
            vi.mocked(React.useEffect).mockImplementation(() => {});
            vi.spyOn(React, 'useRef').mockReturnValue({ current: null });
            const tree = AgentTokens(props);
            let handler: (() => void) | undefined;
            function visit(node: React.ReactNode): void {
                React.Children.forEach(node, (child) => {
                    if (!React.isValidElement<Record<string, unknown>>(child))
                        return;
                    if (action === 'create' && child.type === 'form') {
                        handler = () =>
                            (
                                child.props.onSubmit as (event: {
                                    preventDefault: () => void;
                                }) => void
                            )({ preventDefault: vi.fn() });
                    } else if (
                        action !== 'create' &&
                        child.props.children ===
                            (action === 'rotate' ? 'Rotate' : 'Revoke')
                    ) {
                        handler = child.props.onClick as () => void;
                    }
                    visit(child.props.children as React.ReactNode);
                });
            }
            visit(tree);
            expect(handler).toBeDefined();
            handler!();
            await vi.waitFor(() =>
                expect(setters[10]).toHaveBeenLastCalledWith(false),
            );
            expect(setters[7]).not.toHaveBeenCalled();
            expect(setters[1]).not.toHaveBeenCalled();
            expect(setters[8]).toHaveBeenLastCalledWith(null);
            expect(setters[9]).toHaveBeenLastCalledWith(uncertainMessage);
            expect(fetchMock).toHaveBeenCalledTimes(1);
        },
    );
});
import * as React from 'react';
