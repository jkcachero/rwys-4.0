import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AgentAccessNavigation from '@/components/agent-access-navigation';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';

type Pagination = {
    page: number;
    limit: number;
    total: number;
    pages: number;
    has_more?: boolean;
};
type Listing<T> = { data: T[]; pagination: Pagination };
type Client = {
    id: number;
    name: string;
    owner: { id: number; name: string; email: string };
};
type Profile = {
    id: string;
    name: string;
    account_count: number | null;
};
type LocalAccount = {
    id: number;
    client_id: number;
    provider_account_id: string;
    profile_id: string;
    platform: string;
    active: boolean;
    publishing_enabled: boolean;
};
type Mapping = {
    id: number;
    client_id: number;
    profile_id: string;
    name: string | null;
    active: boolean;
    last_synced_at: string | null;
    account_count: number;
    accounts?: LocalAccount[];
};
type Props = {
    clients: Listing<Client>;
    selectedClientId: number | null;
    selectedClient?: Client | null;
    mappings: Mapping[];
    providerEnabled: boolean;
    profileAssignmentsReady?: boolean;
};

const baseUrl = '/settings/provider-accounts';
const emptyProfiles: Listing<Profile> = {
    data: [],
    pagination: { page: 1, limit: 25, total: 0, pages: 0 },
};

class AccountRequestError extends Error {
    constructor(public status: number) {
        super(
            (
                {
                    403: 'You no longer have permission to assign accounts.',
                    409: 'Profile is already assigned elsewhere or its mapping changed. No assignment was changed.',
                    423: 'Confirm your password before assigning accounts.',
                    502: 'Zernio profile discovery is unavailable. Try again later.',
                    503: 'Zernio discovery is unavailable. Ask an operator to check configuration and required migrations.',
                } as Record<number, string>
            )[status] ??
                'Profile action failed. Check your session and selection, then try again.',
        );
    }
}

export async function requestProviderAccounts<T extends object>(
    url: string,
    signal: AbortSignal,
    assignment?: { client_id: number; profile_id: string },
): Promise<T & { already_assigned?: boolean }> {
    try {
        const response = await fetch(url, {
            method: assignment ? 'POST' : 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            signal,
            headers: {
                Accept: 'application/json',
                ...(assignment
                    ? {
                          'Content-Type': 'application/json',
                          'X-CSRF-TOKEN':
                              document
                                  .querySelector('meta[name="csrf-token"]')
                                  ?.getAttribute('content') ?? '',
                      }
                    : {}),
            },
            body: assignment
                ? JSON.stringify({
                      client_id: assignment.client_id,
                      profile_id: assignment.profile_id,
                  })
                : undefined,
        });
        if (!response.ok) throw new AccountRequestError(response.status);
        const result: T = await response.json();
        return assignment
            ? { ...result, already_assigned: response.status === 200 }
            : result;
    } catch (error) {
        if (error instanceof AccountRequestError) throw error;
        throw new AccountRequestError(0);
    }
}

export default function ProviderAccounts({
    clients,
    selectedClientId,
    selectedClient = null,
    mappings,
    providerEnabled,
    profileAssignmentsReady = true,
}: Props) {
    const { auth } = usePage().props;
    const canAssignProviderAccounts =
        auth?.permissions?.assignProviderAccounts === true;
    const [clientList, setClientList] = useState(clients);
    const [clientId, setClientId] = useState<number | null>(selectedClientId);
    const [profiles, setProfiles] = useState(emptyProfiles);
    const [profileId, setProfileId] = useState<string | null>(null);
    const [assigned, setAssigned] = useState(mappings);
    const [persistedListing, setPersistedListing] = useState(true);
    const [busy, setBusy] = useState<'clients' | 'discover' | 'assign' | null>(
        null,
    );
    const [error, setError] = useState<AccountRequestError | null>(null);
    const [message, setMessage] = useState('');
    const [stopped, setStopped] = useState(false);
    const pending = useRef<AbortController | null>(null);
    const client =
        clientList.data.find((item) => item.id === clientId) ??
        (selectedClient?.id === clientId ? selectedClient : null);
    const clientOptions =
        client && !clientList.data.some((item) => item.id === client.id)
            ? [client, ...clientList.data]
            : clientList.data;
    const profile = profiles.data.find(
        (item) => item.id === profileId && /^[a-f\d]{24}$/i.test(item.id),
    );
    const canDiscover =
        !!client &&
        providerEnabled &&
        profileAssignmentsReady &&
        !stopped &&
        !busy;

    function clearProfiles() {
        pending.current?.abort();
        pending.current = null;
        setBusy(null);
        setProfiles(emptyProfiles);
        setProfileId(null);
        setError(null);
        setMessage('');
    }

    function clearClient() {
        clearProfiles();
        setClientId(null);
        setAssigned([]);
        setPersistedListing(false);
    }

    useEffect(() => {
        pending.current?.abort();
        pending.current = null;
        setBusy(null);
        setClientList(clients);
        setClientId(selectedClientId);
        setAssigned(mappings);
        setPersistedListing(true);
        setProfiles(emptyProfiles);
        setProfileId(null);
        setError(null);
        setMessage('');
        setStopped(false);
    }, [
        clients,
        selectedClientId,
        selectedClient,
        mappings,
        providerEnabled,
        profileAssignmentsReady,
    ]);

    useEffect(() => {
        const clear = () => {
            pending.current?.abort();
            pending.current = null;
            setBusy(null);
            setClientId(null);
            setProfiles(emptyProfiles);
            setProfileId(null);
            setAssigned([]);
            setPersistedListing(false);
            setMessage('');
            setError(null);
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
        action: 'clients' | 'discover' | 'assign',
        page = 1,
        sync?: Mapping,
    ) {
        // The ref closes the gap before React commits disabled button state.
        if (pending.current || stopped) return;
        if (action !== 'clients' && !canDiscover) return;
        if (
            action === 'assign' &&
            (sync
                ? sync.client_id !== client!.id ||
                  !assigned.some(
                      (item) =>
                          item.id === sync.id &&
                          item.client_id === client!.id &&
                          item.profile_id === sync.profile_id,
                  ) ||
                  !/^[a-f\d]{24}$/i.test(sync.profile_id)
                : !profile)
        )
            return;
        if (action === 'clients') clearClient();
        if (action === 'discover') clearProfiles();
        const controller = new AbortController();
        pending.current = controller;
        setBusy(action);
        setError(null);
        setMessage('');
        try {
            const query = new URLSearchParams({
                page: String(page),
                limit: '25',
            });
            if (action === 'clients') {
                const result = await requestProviderAccounts<Listing<Client>>(
                    `${baseUrl}/clients?${query}`,
                    controller.signal,
                );
                if (
                    !controller.signal.aborted &&
                    pending.current === controller
                )
                    setClientList(result);
            } else if (action === 'discover') {
                query.set('client_id', String(client!.id));
                const result = await requestProviderAccounts<Listing<Profile>>(
                    `${baseUrl}/discover?${query}`,
                    controller.signal,
                );
                if (
                    !controller.signal.aborted &&
                    pending.current === controller
                )
                    setProfiles(result);
            } else {
                const result = await requestProviderAccounts<{
                    data: Mapping;
                    already_assigned?: boolean;
                }>(baseUrl, controller.signal, {
                    client_id: client!.id,
                    profile_id: sync?.profile_id ?? profile!.id,
                });
                if (controller.signal.aborted || pending.current !== controller)
                    return;
                setAssigned((current) => [
                    ...current.filter((item) => item.id !== result.data.id),
                    result.data,
                ]);
                setMessage(
                    result.already_assigned
                        ? 'Profile synced'
                        : 'Profile assigned',
                );
                setProfileId(null);
            }
        } catch (caught) {
            if (controller.signal.aborted || pending.current !== controller)
                return;
            const failure =
                caught instanceof AccountRequestError
                    ? caught
                    : new AccountRequestError(0);
            setError(failure);
            if (failure.status === 403 || failure.status === 423) {
                setStopped(true);
                setProfiles(emptyProfiles);
                setProfileId(null);
            }
        } finally {
            if (pending.current === controller) {
                pending.current = null;
                setBusy(null);
            }
        }
    }

    return (
        <>
            <Head title="Agent access - Account assignments" />
            <div className="min-w-0 space-y-6">
                <AgentAccessNavigation
                    active="accounts"
                    canAssignProviderAccounts={canAssignProviderAccounts}
                />
                <p className="text-sm text-muted-foreground">
                    Choose a registered RWYS client, then assign a Zernio
                    profile and all its social accounts. Clients create their
                    own API tokens afterward.
                </p>
                <p className="text-sm text-muted-foreground">
                    As an operator, you assign profiles to registered clients,
                    not just clients you own. This does not let you create
                    tokens for another client, change publishing access, or
                    reassign accounts.
                </p>
                {error && (
                    <div
                        role="alert"
                        className="space-y-2 text-sm text-destructive"
                    >
                        <p>{error.message}</p>
                        {error.status === 423 && (
                            <Link
                                href="/user/confirm-password"
                                className="underline"
                            >
                                Confirm password
                            </Link>
                        )}
                    </div>
                )}
                {message && (
                    <p role="status" className="text-sm">
                        {message}
                    </p>
                )}
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>1. Choose a client</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Label htmlFor="assignment-client">
                            Registered client
                        </Label>
                        <select
                            id="assignment-client"
                            className="w-full min-w-0 rounded-md border bg-background p-2 text-sm"
                            value={clientId ?? ''}
                            disabled={
                                stopped ||
                                busy === 'assign' ||
                                busy === 'clients'
                            }
                            onChange={(event) => {
                                clearClient();
                                setClientId(
                                    event.target.value
                                        ? Number(event.target.value)
                                        : null,
                                );
                            }}
                        >
                            <option value="">Select a registered client</option>
                            {clientOptions.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name} ({item.owner.name},{' '}
                                    {item.owner.email})
                                </option>
                            ))}
                        </select>
                        {clientList.data.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No registered clients found.
                            </p>
                        )}
                        {clientList.pagination.pages > 1 && (
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                <Button
                                    variant="outline"
                                    disabled={
                                        !!busy ||
                                        stopped ||
                                        clientList.pagination.page <= 1
                                    }
                                    onClick={() =>
                                        void perform(
                                            'clients',
                                            clientList.pagination.page - 1,
                                        )
                                    }
                                >
                                    Previous clients
                                </Button>
                                <span>
                                    Page {clientList.pagination.page} of{' '}
                                    {clientList.pagination.pages || 1}
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={
                                        !!busy ||
                                        stopped ||
                                        !(
                                            clientList.pagination.has_more ??
                                            clientList.pagination.page <
                                                clientList.pagination.pages
                                        )
                                    }
                                    onClick={() =>
                                        void perform(
                                            'clients',
                                            clientList.pagination.page + 1,
                                        )
                                    }
                                >
                                    Next clients
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>2. Choose a Zernio profile</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!providerEnabled && (
                            <div className="space-y-2 text-sm text-muted-foreground">
                                <p>
                                    Zernio discovery is not configured on this
                                    server. Client selection is available; an
                                    operator must enable provider access before
                                    profiles can be loaded.
                                </p>
                                <p>
                                    Profile discovery requires{' '}
                                    <code className="break-all">
                                        AGENT_ZERNIO_ENABLED=true
                                    </code>{' '}
                                    and a server-side{' '}
                                    <code>ZERNIO_API_KEY</code>. Publishing is
                                    not required for discovery.
                                </p>
                            </div>
                        )}
                        {!profileAssignmentsReady && (
                            <p
                                role="alert"
                                className="text-sm text-muted-foreground"
                            >
                                Profile assignments require a database migration
                                on this server. Ask an operator to run the
                                required migrations before loading or syncing
                                profiles.
                            </p>
                        )}
                        <Button
                            disabled={!canDiscover}
                            onClick={() => void perform('discover')}
                        >
                            Load Zernio profiles
                        </Button>
                        <p className="text-sm text-muted-foreground">
                            Profiles load only when you request them, 25 at a
                            time, from the operator's Zernio profile list, not
                            from accounts already assigned to the selected
                            client.
                        </p>
                        <Label htmlFor="assignment-profile">
                            Zernio profile
                        </Label>
                        <select
                            id="assignment-profile"
                            className="w-full min-w-0 rounded-md border bg-background p-2 text-sm"
                            value={profileId ?? ''}
                            disabled={
                                !canDiscover || profiles.data.length === 0
                            }
                            onChange={(event) => {
                                setProfileId(event.target.value || null);
                                setMessage('');
                            }}
                        >
                            <option value="">Select a Zernio profile</option>
                            {profiles.data.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                    {item.account_count != null
                                        ? ` (${item.account_count} accounts)`
                                        : ''}
                                </option>
                            ))}
                        </select>
                        {client && profiles.data.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No profiles loaded. Choose Load Zernio profiles
                                to find profiles.
                            </p>
                        )}
                        {profiles.pagination.pages > 1 && (
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                <Button
                                    variant="outline"
                                    disabled={
                                        !canDiscover ||
                                        profiles.pagination.page <= 1
                                    }
                                    onClick={() =>
                                        void perform(
                                            'discover',
                                            profiles.pagination.page - 1,
                                        )
                                    }
                                >
                                    Previous profiles
                                </Button>
                                <span>
                                    Page {profiles.pagination.page} of{' '}
                                    {profiles.pagination.pages}
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={
                                        !canDiscover ||
                                        !(
                                            profiles.pagination.has_more ??
                                            profiles.pagination.page <
                                                profiles.pagination.pages
                                        )
                                    }
                                    onClick={() =>
                                        void perform(
                                            'discover',
                                            profiles.pagination.page + 1,
                                        )
                                    }
                                >
                                    Next profiles
                                </Button>
                            </div>
                        )}
                        {client && profile && (
                            <p className="text-sm break-words">
                                Assign <strong>{profile.name}</strong> and all
                                its social accounts to{' '}
                                <strong>{client.name}</strong>?
                            </p>
                        )}
                        <p className="text-sm text-muted-foreground">
                            All currently connected accounts are imported
                            together after the server verifies the complete,
                            bounded account list. New accounts are linked
                            read-only; publishing requires a separate
                            permission.
                        </p>
                        <Button
                            disabled={!canDiscover || !profile}
                            onClick={() => void perform('assign')}
                        >
                            Assign profile
                        </Button>
                        {busy && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {busy === 'assign'
                                    ? 'Assigning or syncing profile...'
                                    : 'Loading...'}
                            </p>
                        )}
                    </CardContent>
                </Card>
                {assigned.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Next: the client creates their own token in{' '}
                        <Link
                            href="/settings/agent-tokens"
                            className="underline underline-offset-4"
                        >
                            Agent access &gt; My API tokens
                        </Link>
                        . Existing client My API tokens are unaffected by
                        assigning or syncing a profile.
                    </p>
                )}
                <section className="space-y-3" aria-label="Assigned profiles">
                    <h2 className="text-lg font-medium">Assigned profiles</h2>
                    <p className="text-sm text-muted-foreground">
                        {persistedListing
                            ? 'Showing up to 25 existing assignments for the initially selected client, plus assignments made here.'
                            : 'Only assignments made for this selected client in this session are shown.'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        New connections are not automatically imported. Choose
                        Sync accounts to import newly connected social accounts
                        for this profile. This explicitly contacts Zernio; there
                        is no automatic background discovery here. Existing
                        null-scope tokens include eligible imported future
                        accounts; tokens restricted to specific account IDs keep
                        those restrictions unchanged.
                    </p>
                    {assigned.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No assignments to show.
                        </p>
                    )}
                    {assigned.map((item) => (
                        <div
                            key={item.id}
                            className="rounded-md border p-3 text-sm break-all"
                        >
                            <span className="font-medium">
                                {item.name?.trim() || item.profile_id}
                            </span>
                            {!item.active && (
                                <span className="ml-2 rounded border px-1.5 py-0.5 text-xs">
                                    Inactive
                                </span>
                            )}
                            <span className="block text-muted-foreground">
                                {item.account_count} accounts / Profile{' '}
                                {item.profile_id}
                            </span>
                            {item.accounts && item.accounts.length > 0 && (
                                <ul
                                    className="mt-2 space-y-1"
                                    aria-label="Known imported accounts"
                                >
                                    {item.accounts.map((account) => (
                                        <li key={account.id}>
                                            {account.platform} /{' '}
                                            {account.provider_account_id}
                                            {!account.active && (
                                                <span className="ml-2 rounded border px-1.5 py-0.5 text-xs">
                                                    Inactive
                                                </span>
                                            )}
                                            <span className="ml-2 text-muted-foreground">
                                                {account.publishing_enabled
                                                    ? 'Publishing permitted'
                                                    : 'Read-only'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {client?.id === item.client_id && (
                                <Button
                                    className="mt-3"
                                    variant="outline"
                                    disabled={!canDiscover}
                                    onClick={() =>
                                        void perform('assign', 1, item)
                                    }
                                >
                                    Sync accounts
                                </Button>
                            )}
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}

ProviderAccounts.layout = {
    breadcrumbs: [
        { title: 'Agent access', href: '/settings/agent-access' },
        { title: 'Account assignments', href: baseUrl },
    ],
};
