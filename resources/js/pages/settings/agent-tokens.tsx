import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AgentAccessNavigation from '@/components/agent-access-navigation';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Account = { id: number; platform: string; publishing_enabled: boolean };
type Client = {
    id: number;
    name: string;
    active: boolean;
    accounts: Account[];
};
type Token = {
    id: number;
    client_id: number;
    name: string;
    abilities: string[];
    allowed_account_ids: number[] | null;
    created_at: string;
    last_used_at: string | null;
    expires_at: string;
};
type Props = {
    clients: Client[];
    tokens: {
        data: Token[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    readAbilities: string[];
    publishingEnabled: boolean;
    instagramSchedulingEnabled: boolean;
};

const uncertainTokenAction =
    'Token action could not be confirmed. Refresh the token list before trying again.';

export async function mutateAgentToken(
    url: string,
    method: 'POST' | 'DELETE',
    payload: Record<string, unknown> | undefined,
    signal: AbortSignal,
): Promise<{ plain_text_token?: string; token?: Token }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '',
        },
        body: payload === undefined ? undefined : JSON.stringify(payload),
    });
    if (response.status === 423) {
        throw new Error('Confirm your password again before managing tokens.');
    }
    if (response.status !== (method === 'DELETE' ? 204 : 201)) {
        throw new Error(uncertainTokenAction);
    }
    if (method === 'DELETE') return {};

    let body: unknown;
    try {
        body = await response.json();
    } catch {
        throw new Error(uncertainTokenAction);
    }
    if (body === null || typeof body !== 'object' || Array.isArray(body)) {
        throw new Error(uncertainTokenAction);
    }
    const result = body as Record<string, unknown>;
    if (
        typeof result.plain_text_token !== 'string' ||
        !/^[1-9][0-9]*\|[A-Za-z0-9]{40,128}(?![\s\S])/.test(
            result.plain_text_token,
        ) ||
        result.token === null ||
        typeof result.token !== 'object' ||
        Array.isArray(result.token)
    ) {
        throw new Error(uncertainTokenAction);
    }
    const token = result.token as Record<string, unknown>;
    const positiveId = (value: unknown): value is number =>
        typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
    if (
        !positiveId(token.id) ||
        !positiveId(token.client_id) ||
        result.plain_text_token.split('|')[0] !== String(token.id) ||
        typeof token.name !== 'string' ||
        !Array.isArray(token.abilities) ||
        !token.abilities.every((ability) => typeof ability === 'string') ||
        (token.allowed_account_ids !== null &&
            (!Array.isArray(token.allowed_account_ids) ||
                !token.allowed_account_ids.every(positiveId))) ||
        typeof token.created_at !== 'string' ||
        (token.last_used_at !== null &&
            typeof token.last_used_at !== 'string') ||
        typeof token.expires_at !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})(?![\s\S])/.test(
            token.expires_at,
        ) ||
        !Number.isFinite(Date.parse(token.expires_at))
    ) {
        throw new Error(uncertainTokenAction);
    }
    return { plain_text_token: result.plain_text_token, token: token as Token };
}

export default function AgentTokens({
    clients,
    tokens,
    readAbilities,
    publishingEnabled,
    instagramSchedulingEnabled,
}: Props) {
    const { auth } = usePage().props;
    const canAssignProviderAccounts =
        auth.permissions?.assignProviderAccounts === true;
    const activeClients = clients.filter((item) => item.active);
    const [clientId, setClientId] = useState(() =>
        activeClients.length === 1 ? String(activeClients[0].id) : '',
    );
    const [name, setName] = useState('');
    const [abilities, setAbilities] = useState(readAbilities);
    const [restricted, setRestricted] = useState(false);
    const [accountIds, setAccountIds] = useState<number[]>([]);
    const [publish, setPublish] = useState(false);
    const [schedule, setSchedule] = useState(false);
    const [listedTokens, setListedTokens] = useState(tokens.data);
    const [plainText, setPlainText] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const pending = useRef<AbortController | null>(null);
    const client = clients.find((item) => String(item.id) === clientId);
    const canPublish =
        publishingEnabled &&
        client?.active &&
        client?.accounts.some(
            (account) =>
                account.publishing_enabled &&
                ['linkedin', 'facebook', 'threads', 'bluesky'].includes(
                    account.platform,
                ) &&
                (!restricted || accountIds.includes(account.id)),
        );

    const canSchedule =
        publishingEnabled &&
        instagramSchedulingEnabled &&
        client?.active &&
        client.accounts.some(
            (account) =>
                account.platform === 'instagram' &&
                account.publishing_enabled &&
                (!restricted || accountIds.includes(account.id)),
        );

    useEffect(() => {
        setListedTokens(tokens.data);
    }, [tokens.data]);

    useEffect(() => {
        const clear = () => {
            pending.current?.abort();
            setPlainText(null);
            setBusy(false);
        };
        const unsubscribe = router.on('before', clear);
        window.addEventListener('pagehide', clear);
        window.addEventListener('pageshow', clear);
        window.addEventListener('popstate', clear);
        return () => {
            pending.current?.abort();
            unsubscribe();
            window.removeEventListener('pagehide', clear);
            window.removeEventListener('pageshow', clear);
            window.removeEventListener('popstate', clear);
        };
    }, []);

    async function perform(
        action: 'create' | 'rotate' | 'revoke',
        token?: Token,
    ) {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setPlainText(null);
        setError(null);
        setBusy(true);
        try {
            const url =
                action === 'create'
                    ? '/settings/agent-tokens'
                    : `/settings/agent-tokens/${token!.id}${action === 'rotate' ? '/rotate' : ''}`;
            const result = await mutateAgentToken(
                url,
                action === 'revoke' ? 'DELETE' : 'POST',
                action === 'create'
                    ? {
                          client_id: Number(clientId),
                          name,
                          abilities: [
                              ...abilities,
                              ...(publish && canPublish
                                  ? ['zernio.posts.publish']
                                  : []),
                              ...(schedule && canSchedule
                                  ? ['zernio.posts.schedule']
                                  : []),
                          ],
                          allowed_account_ids: restricted ? accountIds : null,
                      }
                    : undefined,
                controller.signal,
            );
            // A completed response must not redisplay a secret after navigation.
            if (controller.signal.aborted) return;
            setListedTokens((current) => [
                ...(result.token ? [result.token] : []),
                ...current.filter((item) => item.id !== token?.id),
            ]);
            setPlainText(result.plain_text_token ?? null);
            if (action === 'create') setName('');
        } catch {
            if (!controller.signal.aborted) {
                setPlainText(null);
                setError(uncertainTokenAction);
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }

    return (
        <>
            <Head title="Agent access - My API tokens" />
            <div className="space-y-6">
                <AgentAccessNavigation
                    active="tokens"
                    canAssignProviderAccounts={canAssignProviderAccounts}
                />
                <p className="text-sm text-muted-foreground">
                    Token-only access does not connect a provider or share
                    provider keys. Keep tokens out of URLs. Tokens expire in 30
                    days; rotation preserves the original expiry and immediately
                    revokes the old token.
                </p>
                {error && (
                    <div
                        role="alert"
                        className="space-y-2 text-sm text-destructive"
                    >
                        <p>{error}</p>
                        <Link href="/user/confirm-password">
                            Confirm password
                        </Link>
                    </div>
                )}
                {plainText && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Your new token</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm">
                                Shown once. Store it securely in your agent
                                before leaving this page.
                            </p>
                            <code
                                className="block rounded border p-3 text-sm break-all"
                                aria-label="New agent token"
                            >
                                {plainText}
                            </code>
                            <Button
                                variant="outline"
                                onClick={() => setPlainText(null)}
                            >
                                Dismiss token
                            </Button>
                        </CardContent>
                    </Card>
                )}
                {activeClients.length === 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>No personal client access</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p className="text-muted-foreground">
                                Operators assign provider accounts to client
                                owners. You can create API tokens only for a
                                client you own that is active, not for other
                                client owners.
                            </p>
                            {canAssignProviderAccounts ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-auto text-left whitespace-normal"
                                >
                                    <Link href="/settings/provider-accounts">
                                        Manage account assignments
                                    </Link>
                                </Button>
                            ) : (
                                <p className="text-muted-foreground">
                                    Ask an operator to help with your personal
                                    client access and account assignments.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void perform('create');
                        }}
                    >
                        <div className="space-y-1">
                            <h2 className="text-lg font-medium">
                                Create API token
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Create a personal token for a client you own,
                                not for other client owners.
                            </p>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="agent-client">Client</Label>
                            <select
                                id="agent-client"
                                required
                                value={clientId}
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                onChange={(event) => {
                                    setClientId(event.target.value);
                                    setAccountIds([]);
                                    setPublish(false);
                                    setSchedule(false);
                                }}
                            >
                                <option value="">Select a client</option>
                                {clients.map((item) => (
                                    <option
                                        key={item.id}
                                        value={item.id}
                                        disabled={!item.active}
                                    >
                                        {item.name}
                                        {!item.active ? ' (disabled)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="agent-name">Token name</Label>
                            <Input
                                id="agent-name"
                                required
                                maxLength={100}
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                autoComplete="off"
                            />
                        </div>
                        <fieldset className="space-y-3">
                            <legend className="mb-2 text-sm font-medium">
                                Read permissions
                            </legend>
                            {readAbilities.map((ability) => (
                                <Label
                                    key={ability}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        checked={abilities.includes(ability)}
                                        onCheckedChange={(checked) =>
                                            setAbilities((current) =>
                                                checked === true
                                                    ? [...current, ability]
                                                    : current.filter(
                                                          (item) =>
                                                              item !== ability,
                                                      ),
                                            )
                                        }
                                    />
                                    {ability}
                                </Label>
                            ))}
                        </fieldset>
                        <Label className="flex items-center gap-2">
                            <Checkbox
                                checked={restricted}
                                onCheckedChange={(checked) => {
                                    setRestricted(checked === true);
                                    setPublish(false);
                                    setSchedule(false);
                                }}
                            />
                            Restrict to selected accounts
                        </Label>
                        {!restricted && (
                            <p className="text-sm text-muted-foreground">
                                All current and future active accounts mapped to
                                this client are in scope.
                            </p>
                        )}
                        {restricted && (
                            <fieldset className="space-y-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Mapped accounts (select at least one)
                                </legend>
                                {client?.accounts.map((account) => (
                                    <Label
                                        key={account.id}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            checked={accountIds.includes(
                                                account.id,
                                            )}
                                            onCheckedChange={(checked) => {
                                                setAccountIds((current) =>
                                                    checked === true
                                                        ? [
                                                              ...current,
                                                              account.id,
                                                          ]
                                                        : current.filter(
                                                              (id) =>
                                                                  id !==
                                                                  account.id,
                                                          ),
                                                );
                                                setPublish(false);
                                                setSchedule(false);
                                            }}
                                        />
                                        {account.platform} (account {account.id}
                                        )
                                    </Label>
                                ))}
                            </fieldset>
                        )}
                        <Label className="flex items-center gap-2">
                            <Checkbox
                                disabled={!canPublish}
                                checked={publish && !!canPublish}
                                onCheckedChange={(checked) =>
                                    setPublish(checked === true)
                                }
                            />
                            Allow publishing (write access)
                        </Label>
                        <Label className="flex items-center gap-2">
                            <Checkbox
                                disabled={!canSchedule}
                                checked={schedule && !!canSchedule}
                                onCheckedChange={(checked) =>
                                    setSchedule(checked === true)
                                }
                            />
                            Allow scheduled Instagram images (write access)
                        </Label>
                        <Button
                            type="submit"
                            disabled={
                                busy ||
                                !client?.active ||
                                !name.trim() ||
                                (abilities.length === 0 &&
                                    !(publish && canPublish) &&
                                    !(schedule && canSchedule)) ||
                                (restricted && accountIds.length === 0)
                            }
                        >
                            Create token
                        </Button>
                    </form>
                )}
                <section className="space-y-4" aria-label="Existing tokens">
                    <h2 className="text-lg font-medium">Existing tokens</h2>
                    {listedTokens.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No tokens on this page.
                        </p>
                    )}
                    {listedTokens.map((token) => {
                        const tokenClient = clients.find(
                            (item) => item.id === token.client_id,
                        );
                        return (
                            <Card key={token.id}>
                                <CardHeader>
                                    <CardTitle className="break-all">
                                        {token.name}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <p>
                                        {tokenClient?.name}
                                        {tokenClient?.active
                                            ? ' (active)'
                                            : ' (disabled)'}
                                    </p>
                                    <p className="break-words text-muted-foreground">
                                        {token.abilities.join(', ')}
                                    </p>
                                    <p>
                                        Accounts:{' '}
                                        {token.allowed_account_ids?.join(
                                            ', ',
                                        ) ?? 'All mapped accounts'}
                                    </p>
                                    <p>
                                        Expires: {token.expires_at}; Last used:{' '}
                                        {token.last_used_at ?? 'Never'}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="outline"
                                            disabled={
                                                busy || !tokenClient?.active
                                            }
                                            onClick={() =>
                                                void perform('rotate', token)
                                            }
                                        >
                                            Rotate
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            disabled={busy}
                                            onClick={() =>
                                                void perform('revoke', token)
                                            }
                                        >
                                            Revoke
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                    <div className="flex gap-4 text-sm">
                        {tokens.prev_page_url && (
                            <Link href={tokens.prev_page_url}>Previous</Link>
                        )}
                        {tokens.next_page_url && (
                            <Link href={tokens.next_page_url}>Next</Link>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

AgentTokens.layout = {
    breadcrumbs: [
        { title: 'Agent access', href: '/settings/agent-access' },
        { title: 'My API tokens', href: '/settings/agent-tokens' },
    ],
};
