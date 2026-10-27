import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import {
    profiles as discoverProfiles,
    store as assignProfile,
    sync as syncProfile,
} from '@/actions/App/Http/Controllers/Settings/AgentProfileAssignmentController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ClientSummary = {
    id: string;
    name: string;
    owner: { name: string; email: string } | null;
    social_account_count: number;
    ad_account_count: number;
};

type Account = {
    id: string;
    name: string;
    platform: string;
    available: boolean;
};
type Profile = {
    id: string;
    name: string;
    synchronized_at: string | null;
    accounts: Account[];
};
type AgentToken = {
    id: number;
    name: string;
    abilities: string[];
    allowed_account_ids: string[];
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
};
type SelectedClient = ClientSummary & {
    can_manage: boolean;
    can_assign_profiles: boolean;
    can_assign_adkit_accounts: boolean;
    adkit_accounts: Account[];
    profiles: Profile[];
    tokens: AgentToken[];
};
type Pagination = { page: number; has_more: boolean };
type Props = {
    clients: ClientSummary[];
    can_assign_accounts: boolean;
    selectedClient: SelectedClient | null;
    assigned_users: ClientSummary[];
    assigned_users_pagination: Pagination | null;
    client_pagination?: Pagination | null;
    token_plaintext?: string;
    token_notice?: string;
};
type TokenForm = {
    name: string;
    allowed_account_ids: string[];
    expires_at: string;
};
type DiscoverableProfile = {
    id: string;
    name: string;
    account_count: number | null;
};
type ProfileDiscovery = {
    profiles: DiscoverableProfile[];
    page: number;
    has_more: boolean;
};
type DiscoverableAdAccount = {
    id: string;
    name: string;
    currency: string | null;
    status: string | number | null;
    assigned_to_current_client: boolean;
    assigned_elsewhere: boolean;
};

const selectClassName =
    'h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const futureDateTime = () => {
    const date = new Date();
    date.setDate(date.getDate() + 30);
    return date.toISOString().slice(0, 16);
};

function formatDate(value: string | null) {
    return value === null ? 'Never' : new Date(value).toLocaleString();
}

function accessLabels(abilities: string[]) {
    return [
        abilities.some((ability) => ability.endsWith('.read')) ? 'Read' : null,
        abilities.some((ability) => ability.endsWith('.publish'))
            ? 'Publish'
            : null,
        abilities.some((ability) => ability.endsWith('.schedule'))
            ? 'Schedule'
            : null,
    ].filter((label): label is string => label !== null);
}

function eligibleAccounts(client: SelectedClient) {
    return Array.from(
        new Map(
            [
                ...client.profiles.flatMap((profile) => profile.accounts),
                ...client.adkit_accounts,
            ]
                .filter((account) => account.available)
                .map((account) => [account.id, account]),
        ).values(),
    );
}

function RevokeKeyButton({
    clientId,
    token,
}: {
    clientId: string;
    token: AgentToken;
}) {
    const [open, setOpen] = useState(false);
    const { delete: revoke, processing, errors } = useForm({});

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!processing) {
                    setOpen(nextOpen);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="destructive">
                    Revoke key
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Revoke {token.name}?</DialogTitle>
                <DialogDescription>
                    This key will no longer authorize API requests. Any agent
                    using it will lose access. This cannot be undone. Its record
                    will remain for audit history.
                </DialogDescription>
                <InputError
                    message={Object.values(errors).find(
                        (message): message is string =>
                            typeof message === 'string',
                    )}
                />
                <DialogFooter>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={() =>
                            revoke(
                                `/settings/agent-access/${clientId}/tokens/${token.id}`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                },
                            )
                        }
                    >
                        {processing ? 'Revoking...' : 'Revoke key'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function TokenCreationForm({
    client,
    onCreated,
    onPendingChange,
}: {
    client: SelectedClient;
    onCreated: () => void;
    onPendingChange: (pending: boolean) => void;
}) {
    const accounts = eligibleAccounts(client);
    const { data, setData, post, processing, errors, reset } =
        useForm<TokenForm>({
            name: '',
            allowed_account_ids: [],
            expires_at: futureDateTime(),
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!client.can_manage || accounts.length === 0 || processing) {
            return;
        }
        onPendingChange(true);
        post(`/settings/agent-access/${client.id}/tokens`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onCreated();
            },
            onFinish: () => onPendingChange(false),
        });
    };

    return (
        <form className="space-y-5" onSubmit={submit}>
            <div className="grid gap-2">
                <Label htmlFor="token-name">Key name</Label>
                <Input
                    id="token-name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    placeholder="Codex production"
                    required
                />
                <InputError message={errors.name} />
            </div>
            <fieldset className="space-y-2" disabled={processing}>
                <legend className="text-sm font-medium">Account scope</legend>
                <p className="text-sm text-muted-foreground">
                    Leave all unchecked to allow all assigned accounts. Select
                    accounts to limit this key's scope.
                </p>
                {accounts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Assign an available account before creating a key.
                    </p>
                ) : (
                    <div className="grid max-h-60 gap-2 overflow-y-auto">
                        {accounts.map((account) => (
                            <label
                                key={account.id}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    checked={data.allowed_account_ids.includes(
                                        account.id,
                                    )}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'allowed_account_ids',
                                            checked === true
                                                ? [
                                                      ...data.allowed_account_ids,
                                                      account.id,
                                                  ]
                                                : data.allowed_account_ids.filter(
                                                      (id) => id !== account.id,
                                                  ),
                                        )
                                    }
                                />
                                {account.name} ({account.platform})
                            </label>
                        ))}
                    </div>
                )}
                <InputError message={errors.allowed_account_ids} />
            </fieldset>
            <div className="grid gap-2">
                <Label htmlFor="token-expiry">Expires at</Label>
                <Input
                    id="token-expiry"
                    type="datetime-local"
                    value={data.expires_at}
                    onChange={(event) =>
                        setData('expires_at', event.target.value)
                    }
                    required
                />
                <p className="text-sm text-muted-foreground">
                    Keys expire within 90 days.
                </p>
                <InputError message={errors.expires_at} />
            </div>
            <DialogFooter>
                <Button
                    disabled={
                        processing ||
                        !client.can_manage ||
                        accounts.length === 0
                    }
                >
                    {processing ? 'Creating...' : 'Create key'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function CreateKeyDialog({ client }: { client: SelectedClient }) {
    const [open, setOpen] = useState(false);
    const [creating, setCreating] = useState(false);
    const hasAccounts = eligibleAccounts(client).length > 0;

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!creating) {
                    setOpen(nextOpen);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    disabled={!hasAccounts || creating}
                    aria-describedby={
                        !hasAccounts ? 'key-creation-help' : undefined
                    }
                >
                    Create key
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto">
                <DialogTitle>Create API key</DialogTitle>
                <DialogDescription>
                    Allow API access to read and publish where supported for
                    assigned accounts.
                </DialogDescription>
                <TokenCreationForm
                    client={client}
                    onCreated={() => setOpen(false)}
                    onPendingChange={setCreating}
                />
            </DialogContent>
        </Dialog>
    );
}

function ProfileAssignmentDialog({ client }: { client: SelectedClient }) {
    const [open, setOpen] = useState(false);
    const [discovery, setDiscovery] = useState<ProfileDiscovery | null>(null);
    const [selectedProfileId, setSelectedProfileId] = useState('');
    const [loading, setLoading] = useState(false);
    const [assigning, setAssigning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadProfiles = async () => {
        if (!client.can_assign_profiles || loading || assigning) {
            return;
        }
        setLoading(true);
        setError(null);
        setDiscovery(null);
        setSelectedProfileId('');
        try {
            const profiles: DiscoverableProfile[] = [];
            let page = 1;
            let hasMore = true;
            while (hasMore) {
                const response = await fetch(
                    discoverProfiles(client.id, { query: { page } }).url,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                const payload = (await response.json()) as
                    | ProfileDiscovery
                    | { message?: string };
                if (!response.ok || !('profiles' in payload)) {
                    throw new Error(
                        'message' in payload && payload.message
                            ? payload.message
                            : 'Unable to load profiles.',
                    );
                }
                profiles.push(...payload.profiles);
                hasMore = payload.has_more;
                page += 1;
            }
            setDiscovery({ profiles, page: 1, has_more: false });
        } catch (discoveryError) {
            setError(
                discoveryError instanceof Error
                    ? discoveryError.message
                    : 'Unable to load profiles.',
            );
        } finally {
            setLoading(false);
        }
    };

    const assignSelectedProfile = (event: FormEvent) => {
        event.preventDefault();
        if (
            !client.can_assign_profiles ||
            selectedProfileId === '' ||
            loading ||
            assigning
        ) {
            return;
        }
        setAssigning(true);
        setError(null);
        router.post(
            assignProfile(client.id).url,
            { provider_profile_id: selectedProfileId },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setAssigning(false),
                onError: (errors) =>
                    setError(
                        errors.provider_profile_id ??
                            'Unable to assign this profile.',
                    ),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (assigning) {
                    return;
                }
                setOpen(nextOpen);
                if (nextOpen) {
                    void loadProfiles();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    Assign Zernio profile
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Assign Zernio profile</DialogTitle>
                <DialogDescription>
                    Select the Zernio profile for this user, then click Assign.
                </DialogDescription>
                <form className="space-y-4" onSubmit={assignSelectedProfile}>
                    <div className="grid gap-2" aria-live="polite">
                        <Label htmlFor="profile-assignment">
                            Zernio profile
                        </Label>
                        {loading && (
                            <p className="text-sm text-muted-foreground">
                                Loading profiles...
                            </p>
                        )}
                        {discovery?.profiles.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No profiles are available.
                            </p>
                        )}
                        <select
                            id="profile-assignment"
                            className={selectClassName}
                            value={selectedProfileId}
                            disabled={loading || assigning}
                            onChange={(event) =>
                                setSelectedProfileId(event.target.value)
                            }
                        >
                            <option value="">Select a Zernio profile</option>
                            {discovery?.profiles.map((profile) => (
                                <option key={profile.id} value={profile.id}>
                                    {profile.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <InputError message={error ?? undefined} />
                    <DialogFooter>
                        {error && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={loading || assigning}
                                onClick={() => void loadProfiles()}
                            >
                                Retry loading
                            </Button>
                        )}
                        <Button
                            disabled={
                                loading || assigning || selectedProfileId === ''
                            }
                        >
                            {assigning ? 'Assigning...' : 'Assign'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function SocialAccounts({ client }: { client: SelectedClient }) {
    return (
        <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <CardTitle>Social accounts</CardTitle>
                {client.can_assign_profiles && (
                    <ProfileAssignmentDialog client={client} />
                )}
            </CardHeader>
            <CardContent className="space-y-5">
                {client.profiles.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No social accounts assigned yet.
                    </p>
                ) : (
                    client.profiles.map((profile) => (
                        <section
                            key={profile.id}
                            className="space-y-3 border-t pt-4 first:border-t-0 first:pt-0"
                            aria-label={profile.name}
                        >
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <h3 className="text-sm font-medium break-words">
                                        {profile.name}
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        Last updated:{' '}
                                        {formatDate(profile.synchronized_at)}
                                    </p>
                                </div>
                                {client.can_assign_profiles && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                syncProfile({
                                                    client: client.id,
                                                    profile: profile.id,
                                                }).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Refresh accounts
                                    </Button>
                                )}
                            </div>
                            {profile.accounts.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No accounts in this profile.
                                </p>
                            )}
                            {profile.accounts.map((account) => (
                                <div
                                    key={account.id}
                                    className="flex items-center justify-between gap-3"
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm break-words">
                                            {account.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {account.platform}
                                        </p>
                                    </div>
                                    <Badge
                                        variant={
                                            account.available
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {account.available
                                            ? 'Available'
                                            : 'Unavailable'}
                                    </Badge>
                                </div>
                            ))}
                        </section>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function AdKitAccounts({ client }: { client: SelectedClient }) {
    const [open, setOpen] = useState(false);
    const [accounts, setAccounts] = useState<DiscoverableAdAccount[] | null>(
        null,
    );
    const [selectedId, setSelectedId] = useState('');
    const [loading, setLoading] = useState(false);
    const [assigning, setAssigning] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [removingAccount, setRemovingAccount] = useState<Account | null>(
        null,
    );
    const [removing, setRemoving] = useState(false);
    const [removeError, setRemoveError] = useState<string | null>(null);
    const url = `/settings/agent-access/${client.id}/adkit-accounts`;

    const loadAccounts = async () => {
        if (
            !client.can_assign_adkit_accounts ||
            loading ||
            assigning ||
            removing
        ) {
            return;
        }
        setLoading(true);
        setError(null);
        setAccounts(null);
        setSelectedId('');
        try {
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = (await response.json()) as {
                accounts?: DiscoverableAdAccount[];
                message?: string;
            };
            if (!response.ok || !Array.isArray(payload.accounts)) {
                throw new Error(
                    payload.message || 'Unable to load ad accounts.',
                );
            }
            setAccounts(payload.accounts);
        } catch (discoveryError) {
            setError(
                discoveryError instanceof Error
                    ? discoveryError.message
                    : 'Unable to load ad accounts.',
            );
        } finally {
            setLoading(false);
        }
    };

    const assignAccount = (event: FormEvent) => {
        event.preventDefault();
        const account = accounts?.find((item) => item.id === selectedId);
        if (
            !client.can_assign_adkit_accounts ||
            assigning ||
            loading ||
            removing ||
            !account ||
            account.assigned_elsewhere ||
            account.assigned_to_current_client
        ) {
            return;
        }
        setAssigning(true);
        setError(null);
        router.post(
            url,
            { ad_account_id: selectedId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setAccounts(null);
                    setSelectedId('');
                },
                onError: (errors) =>
                    setError(
                        errors.ad_account_id ??
                            'Unable to assign this ad account.',
                    ),
                onFinish: () => setAssigning(false),
            },
        );
    };

    const removeAccess = () => {
        if (!client.can_assign_adkit_accounts || !removingAccount || removing) {
            return;
        }
        setRemoving(true);
        setRemoveError(null);
        router.delete(`${url}/${removingAccount.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setRemovingAccount(null);
                setAccounts(null);
                setSelectedId('');
            },
            onError: (errors) =>
                setRemoveError(
                    Object.values(errors)[0] ?? 'Unable to remove access.',
                ),
            onFinish: () => setRemoving(false),
        });
    };

    return (
        <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <CardTitle>Ad accounts</CardTitle>
                {client.can_assign_adkit_accounts && (
                    <Dialog
                        open={open}
                        onOpenChange={(nextOpen) => {
                            if (assigning) {
                                return;
                            }
                            setOpen(nextOpen);
                            if (nextOpen) {
                                void loadAccounts();
                            }
                        }}
                    >
                        <DialogTrigger asChild>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={loading || assigning || removing}
                            >
                                Assign ad account
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Assign ad account</DialogTitle>
                            <DialogDescription>
                                Choose an AdKit account for this user.
                            </DialogDescription>
                            <form
                                className="space-y-4"
                                onSubmit={assignAccount}
                            >
                                <div className="grid gap-2" aria-live="polite">
                                    <Label htmlFor="adkit-account">
                                        Ad account
                                    </Label>
                                    {loading && (
                                        <p className="text-sm text-muted-foreground">
                                            Loading ad accounts...
                                        </p>
                                    )}
                                    {accounts?.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No ad accounts are available.
                                        </p>
                                    )}
                                    <select
                                        id="adkit-account"
                                        className={selectClassName}
                                        value={selectedId}
                                        disabled={
                                            loading || assigning || removing
                                        }
                                        aria-invalid={!!error}
                                        aria-describedby="adkit-account-help adkit-account-error"
                                        onChange={(event) =>
                                            setSelectedId(event.target.value)
                                        }
                                    >
                                        <option value="">
                                            Select an ad account
                                        </option>
                                        {accounts?.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                                disabled={
                                                    account.assigned_elsewhere ||
                                                    account.assigned_to_current_client
                                                }
                                            >
                                                {account.name}
                                                {account.currency
                                                    ? ` (${account.currency})`
                                                    : ''}
                                                {account.status !== null
                                                    ? ` - Status: ${account.status}`
                                                    : ''}
                                                {account.assigned_elsewhere
                                                    ? ' - Assigned elsewhere'
                                                    : account.assigned_to_current_client
                                                      ? ' - Already assigned to this user'
                                                      : ''}
                                            </option>
                                        ))}
                                    </select>
                                    <p
                                        id="adkit-account-help"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Already assigned accounts cannot be
                                        selected.
                                    </p>
                                </div>
                                <InputError
                                    id="adkit-account-error"
                                    message={error ?? undefined}
                                />
                                <DialogFooter>
                                    {error && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={loading || assigning}
                                            onClick={() => void loadAccounts()}
                                        >
                                            Retry loading
                                        </Button>
                                    )}
                                    <Button
                                        disabled={
                                            !selectedId ||
                                            loading ||
                                            assigning ||
                                            removing
                                        }
                                    >
                                        {assigning ? 'Assigning...' : 'Assign'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                {client.adkit_accounts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No ad accounts assigned yet.
                    </p>
                ) : (
                    client.adkit_accounts.map((account) => (
                        <div
                            key={account.id}
                            className="flex flex-col gap-3 border-t pt-4 first:border-t-0 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div className="min-w-0">
                                <p className="text-sm font-medium break-words">
                                    {account.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {account.platform}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant={
                                        account.available
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {account.available
                                        ? 'Available'
                                        : 'Unavailable'}
                                </Badge>
                                {client.can_assign_adkit_accounts && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        disabled={
                                            removing || assigning || loading
                                        }
                                        onClick={() => {
                                            setRemoveError(null);
                                            setRemovingAccount(account);
                                        }}
                                    >
                                        Remove access
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))
                )}
                {client.can_assign_adkit_accounts && (
                    <Dialog
                        open={removingAccount !== null}
                        onOpenChange={(nextOpen) => {
                            if (!nextOpen && !removing) {
                                setRemovingAccount(null);
                            }
                        }}
                    >
                        <DialogContent>
                            <DialogTitle>
                                Remove access to {removingAccount?.name}?
                            </DialogTitle>
                            <DialogDescription>
                                This user will lose access to this ad account.
                                This does not delete the ad account.
                            </DialogDescription>
                            <InputError message={removeError ?? undefined} />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={removing}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={removing}
                                    onClick={removeAccess}
                                >
                                    {removing ? 'Removing...' : 'Remove access'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                )}
            </CardContent>
        </Card>
    );
}

function PlaintextToken({ token, notice }: { token: string; notice?: string }) {
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(token);
            setCopied(true);
            setError(null);
        } catch {
            setError(
                'Unable to copy. Select the key above and copy it manually.',
            );
        }
    };
    return (
        <Card className="border-amber-500/50 bg-amber-50 dark:bg-amber-950/20">
            <CardHeader>
                <CardTitle>Copy your new access key</CardTitle>
                <CardDescription>
                    {notice ?? 'This access key will only be shown once.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <Input aria-label="New access key" readOnly value={token} />
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => void copy()}
                >
                    {copied ? 'Copied' : 'Copy access key'}
                </Button>
                <InputError message={error ?? undefined} />
            </CardContent>
        </Card>
    );
}

export default function AgentAccess({
    clients,
    can_assign_accounts,
    selectedClient,
    assigned_users,
    assigned_users_pagination,
    client_pagination,
    token_plaintext,
    token_notice,
}: Props) {
    const [pickerOpen, setPickerOpen] = useState(false);
    const [pickedClientId, setPickedClientId] = useState('');
    const visit = (
        query: {
            client?: string;
            clients_page?: number;
            assigned_page?: number;
        },
        onSuccess?: () => void,
    ) => {
        router.get(
            '/settings/agent-access',
            {
                client: selectedClient?.id,
                clients_page: client_pagination?.page,
                assigned_page: assigned_users_pagination?.page,
                ...query,
            },
            { preserveState: true, preserveScroll: true, onSuccess },
        );
    };

    return (
        <>
            <Head title="Agent access" />
            <h1 className="sr-only">Agent access</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Accounts"
                    description="Manage assigned accounts and API access."
                />
                {token_plaintext && (
                    <PlaintextToken
                        key={token_plaintext}
                        token={token_plaintext}
                        notice={token_notice}
                    />
                )}
                <Card>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <CardTitle>Assigned users</CardTitle>
                        {can_assign_accounts &&
                            (clients.length > 0 || client_pagination) && (
                                <Dialog
                                    open={pickerOpen}
                                    onOpenChange={(open) => {
                                        setPickerOpen(open);
                                        if (open) {
                                            setPickedClientId('');
                                        }
                                    }}
                                >
                                    <DialogTrigger asChild>
                                        <Button type="button" size="sm">
                                            Assign accounts
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>
                                            Select a registered user
                                        </DialogTitle>
                                        <DialogDescription>
                                            Choose who to assign accounts to.
                                        </DialogDescription>
                                        <div className="grid gap-2">
                                            <Label htmlFor="registered-user">
                                                Registered user
                                            </Label>
                                            <select
                                                id="registered-user"
                                                className={selectClassName}
                                                value={pickedClientId}
                                                onChange={(event) =>
                                                    setPickedClientId(
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Select a user
                                                </option>
                                                {clients.map((client) => (
                                                    <option
                                                        key={client.id}
                                                        value={client.id}
                                                    >
                                                        {client.owner
                                                            ? `${client.owner.name} - ${client.owner.email}`
                                                            : client.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {clients.length === 0 && (
                                                <p className="text-sm text-muted-foreground">
                                                    No registered users on this
                                                    page.
                                                </p>
                                            )}
                                        </div>
                                        {client_pagination && (
                                            <nav
                                                aria-label="Registered user directory pages"
                                                className="flex flex-wrap items-center gap-3"
                                            >
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={
                                                        client_pagination.page ===
                                                        1
                                                    }
                                                    onClick={() => {
                                                        setPickedClientId('');
                                                        visit({
                                                            clients_page:
                                                                client_pagination.page -
                                                                1,
                                                        });
                                                    }}
                                                >
                                                    Previous users
                                                </Button>
                                                <span className="text-sm text-muted-foreground">
                                                    Page{' '}
                                                    {client_pagination.page}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={
                                                        !client_pagination.has_more
                                                    }
                                                    onClick={() => {
                                                        setPickedClientId('');
                                                        visit({
                                                            clients_page:
                                                                client_pagination.page +
                                                                1,
                                                        });
                                                    }}
                                                >
                                                    Next users
                                                </Button>
                                            </nav>
                                        )}
                                        <DialogFooter>
                                            <Button
                                                type="button"
                                                disabled={!pickedClientId}
                                                onClick={() =>
                                                    visit(
                                                        {
                                                            client: pickedClientId,
                                                        },
                                                        () =>
                                                            setPickerOpen(
                                                                false,
                                                            ),
                                                    )
                                                }
                                            >
                                                Continue
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div
                            className="overflow-x-auto rounded-md border"
                            role="region"
                            aria-label="Assigned users table"
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[28rem] text-sm">
                                <caption className="sr-only">
                                    Users and their assigned social and ad
                                    accounts
                                </caption>
                                <thead className="border-b bg-muted/40">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 text-left font-medium text-muted-foreground"
                                        >
                                            User
                                        </th>
                                        <th
                                            scope="col"
                                            className="w-24 px-3 py-3 text-center font-medium text-muted-foreground"
                                        >
                                            Social accounts
                                        </th>
                                        <th
                                            scope="col"
                                            className="w-24 px-3 py-3 text-center font-medium text-muted-foreground"
                                        >
                                            Ad accounts
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 text-right font-medium text-muted-foreground"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {assigned_users.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="px-3 py-8 text-center text-muted-foreground"
                                            >
                                                No assigned users yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        assigned_users.map((client) => (
                                            <tr
                                                key={client.id}
                                                data-state={
                                                    selectedClient?.id ===
                                                    client.id
                                                        ? 'selected'
                                                        : undefined
                                                }
                                                className="transition-colors hover:bg-muted/30 data-[state=selected]:bg-muted/40"
                                            >
                                                <td className="px-3 py-3 align-middle">
                                                    <p className="font-medium break-words">
                                                        {client.owner?.name ??
                                                            client.name}
                                                    </p>
                                                    {client.owner && (
                                                        <p className="text-xs break-all text-muted-foreground">
                                                            {client.owner.email}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-center align-middle tabular-nums">
                                                    {
                                                        client.social_account_count
                                                    }
                                                </td>
                                                <td className="px-3 py-3 text-center align-middle tabular-nums">
                                                    {client.ad_account_count}
                                                </td>
                                                <td className="px-3 py-3 text-right align-middle">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        aria-label={`Manage ${client.owner?.name ?? client.name}`}
                                                        onClick={() =>
                                                            visit({
                                                                client: client.id,
                                                            })
                                                        }
                                                    >
                                                        Manage
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {assigned_users_pagination && (
                            <nav
                                aria-label="Assigned users pages"
                                className="flex flex-wrap items-center gap-3 border-t pt-4"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        assigned_users_pagination.page === 1
                                    }
                                    onClick={() =>
                                        visit({
                                            assigned_page:
                                                assigned_users_pagination.page -
                                                1,
                                        })
                                    }
                                >
                                    Previous assigned users
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    Page {assigned_users_pagination.page}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        !assigned_users_pagination.has_more
                                    }
                                    onClick={() =>
                                        visit({
                                            assigned_page:
                                                assigned_users_pagination.page +
                                                1,
                                        })
                                    }
                                >
                                    Next assigned users
                                </Button>
                            </nav>
                        )}
                    </CardContent>
                </Card>
                {selectedClient && (
                    <>
                        <Heading
                            variant="small"
                            title={`Accounts for ${selectedClient.owner?.name ?? selectedClient.name}`}
                            description={selectedClient.owner?.email}
                        />
                        <SocialAccounts
                            key={`social-${selectedClient.id}`}
                            client={selectedClient}
                        />
                        <AdKitAccounts
                            key={`ads-${selectedClient.id}`}
                            client={selectedClient}
                        />
                        <Card>
                            <CardHeader>
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <CardTitle>API keys</CardTitle>
                                    {selectedClient.can_manage && (
                                        <CreateKeyDialog
                                            key={selectedClient.id}
                                            client={selectedClient}
                                        />
                                    )}
                                </div>
                                <CardDescription>
                                    Secret values are shown only when created or
                                    replaced. Revoked and expired keys are
                                    hidden from this list.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {!selectedClient.can_manage ? (
                                    <p className="text-sm text-muted-foreground">
                                        Only this user can manage their API
                                        keys.
                                    </p>
                                ) : (
                                    <>
                                        {eligibleAccounts(selectedClient)
                                            .length === 0 && (
                                            <p
                                                id="key-creation-help"
                                                className="text-sm text-muted-foreground"
                                            >
                                                Assign an available account
                                                before creating a key.
                                            </p>
                                        )}
                                        {selectedClient.tokens.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No active API keys for this
                                                user.
                                            </p>
                                        ) : (
                                            selectedClient.tokens.map(
                                                (token) => (
                                                    <div
                                                        key={token.id}
                                                        className="space-y-3 border-t pt-4 first:border-t-0 first:pt-0"
                                                    >
                                                        <div className="flex items-start justify-between gap-4">
                                                            <div className="min-w-0">
                                                                <p className="font-medium break-words">
                                                                    {token.name}
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Key #
                                                                    {token.id} ·
                                                                    Created:{' '}
                                                                    {formatDate(
                                                                        token.created_at,
                                                                    )}
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Expires:{' '}
                                                                    {formatDate(
                                                                        token.expires_at,
                                                                    )}
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Last used:{' '}
                                                                    {formatDate(
                                                                        token.last_used_at,
                                                                    )}
                                                                </p>
                                                            </div>
                                                            <Badge variant="secondary">
                                                                Active
                                                            </Badge>
                                                        </div>
                                                        <div className="flex flex-wrap gap-2">
                                                            {accessLabels(
                                                                token.abilities,
                                                            ).map((label) => (
                                                                <Badge
                                                                    key={label}
                                                                    variant="outline"
                                                                >
                                                                    {label}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                        {token.revoked_at ===
                                                            null && (
                                                            <div className="flex flex-wrap gap-2">
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            `/settings/agent-access/${selectedClient.id}/tokens/${token.id}/rotate`,
                                                                            {
                                                                                name: `${token.name} rotated`,
                                                                                allowed_account_ids:
                                                                                    token.allowed_account_ids,
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Replace
                                                                    access key
                                                                </Button>
                                                                <RevokeKeyButton
                                                                    clientId={
                                                                        selectedClient.id
                                                                    }
                                                                    token={
                                                                        token
                                                                    }
                                                                />
                                                            </div>
                                                        )}
                                                    </div>
                                                ),
                                            )
                                        )}
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}

AgentAccess.layout = {
    breadcrumbs: [{ title: 'Agent access', href: '/settings/agent-access' }],
};
