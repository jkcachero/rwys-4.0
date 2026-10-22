import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    profiles as discoverProfiles,
    store as assignProfile,
    sync as syncProfile,
} from '@/actions/App/Http/Controllers/Settings/AgentProfileAssignmentController';

const futureDateTime = () => {
    const date = new Date();
    date.setDate(date.getDate() + 30);

    return date.toISOString().slice(0, 16);
};

type ClientSummary = {
    id: string;
    name: string;
    role: string;
};

type Permission = {
    id: number;
    is_active: boolean;
    can_read: boolean;
    can_publish: boolean;
    can_schedule: boolean;
};

type Account = {
    id: string;
    name: string;
    platform: string;
    is_active: boolean;
    permission: Permission | null;
};

type Profile = {
    id: string;
    name: string;
    provider: string;
    synchronized_at: string | null;
    accounts: Account[];
};

type Capability = {
    available: boolean;
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
    approved_origins: string[];
    profiles: Profile[];
    capabilities: Record<string, Capability>;
    tokens: AgentToken[];
};

type Ability = {
    value: string;
    label: string;
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

type Props = {
    clients: ClientSummary[];
    selectedClient: SelectedClient | null;
    abilities: Ability[];
    token_plaintext?: string;
    token_notice?: string;
};

type TokenForm = {
    name: string;
    abilities: string[];
    allowed_account_ids: string[];
    expires_at: string;
};

function formatDate(value: string | null) {
    return value === null ? 'Never' : new Date(value).toLocaleString();
}

function PermissionForm({
    client,
    account,
}: {
    client: SelectedClient;
    account: Account;
}) {
    const permission = account.permission;
    const { data, setData, patch, processing, errors } = useForm({
        is_active: permission?.is_active ?? false,
        can_read: permission?.can_read ?? false,
        can_publish: permission?.can_publish ?? false,
        can_schedule: permission?.can_schedule ?? false,
    });

    if (permission === null) {
        return (
            <p className="text-sm text-muted-foreground">
                This account has not been approved for this Client.
            </p>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(
            `/settings/agent-access/${client.id}/permissions/${permission.id}`,
            { preserveScroll: true },
        );
    };

    return (
        <form className="space-y-3" onSubmit={submit}>
            <div className="grid gap-3 sm:grid-cols-2">
                {[
                    ['is_active', 'Enabled'],
                    ['can_read', 'Read'],
                    ['can_publish', 'Publish'],
                    ['can_schedule', 'Schedule'],
                ].map(([field, label]) => (
                    <label
                        key={field}
                        className="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            checked={data[field as keyof typeof data]}
                            disabled={!client.can_manage || !account.is_active}
                            onCheckedChange={(checked) =>
                                setData(
                                    field as keyof typeof data,
                                    checked === true,
                                )
                            }
                        />
                        {label}
                    </label>
                ))}
            </div>
            <InputError message={errors.can_read} />
            {client.can_manage && (
                <Button size="sm" variant="outline" disabled={processing}>
                    Save permissions
                </Button>
            )}
        </form>
    );
}

function TokenCreationForm({
    client,
    abilities,
}: {
    client: SelectedClient;
    abilities: Ability[];
}) {
    const accounts = client.profiles.flatMap((profile) => profile.accounts);
    const { data, setData, post, processing, errors, reset } =
        useForm<TokenForm>({
            name: '',
            abilities: ['context.read'],
            allowed_account_ids: [],
            expires_at: futureDateTime(),
        });

    const toggleValue = (
        field: 'abilities' | 'allowed_account_ids',
        value: string,
        checked: boolean,
    ) => {
        setData(
            field,
            checked
                ? [...data[field], value]
                : data[field].filter((item) => item !== value),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/settings/agent-access/${client.id}/tokens`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Issue an agent token</CardTitle>
                <CardDescription>
                    Tokens are bound to this Client, have exact abilities, and
                    expire within 90 days.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form className="space-y-5" onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="token-name">Token name</Label>
                        <Input
                            id="token-name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="Codex production"
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Abilities
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {abilities.map((ability) => {
                                const available =
                                    client.capabilities[ability.value]
                                        ?.available ?? false;

                                return (
                                    <label
                                        key={ability.value}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={data.abilities.includes(
                                                ability.value,
                                            )}
                                            disabled={!available}
                                            onCheckedChange={(checked) =>
                                                toggleValue(
                                                    'abilities',
                                                    ability.value,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <span>{ability.label}</span>
                                        {!available && (
                                            <Badge variant="outline">
                                                Unavailable
                                            </Badge>
                                        )}
                                    </label>
                                );
                            })}
                        </div>
                        <InputError
                            message={errors.abilities ?? errors['abilities.0']}
                        />
                    </fieldset>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Allowed accounts
                        </legend>
                        {accounts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No approved accounts are available.
                            </p>
                        ) : (
                            <div className="grid gap-2">
                                {accounts.map((account) => {
                                    const readable =
                                        account.is_active &&
                                        account.permission?.is_active ===
                                            true &&
                                        account.permission.can_read;

                                    return (
                                        <label
                                            key={account.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={data.allowed_account_ids.includes(
                                                    account.id,
                                                )}
                                                disabled={!readable}
                                                onCheckedChange={(checked) =>
                                                    toggleValue(
                                                        'allowed_account_ids',
                                                        account.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            {account.name} ({account.platform})
                                        </label>
                                    );
                                })}
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
                        <InputError message={errors.expires_at} />
                    </div>

                    <Button
                        disabled={processing || data.abilities.length === 0}
                    >
                        Issue token
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function ProfileAssignment({ client }: { client: SelectedClient }) {
    const [discovery, setDiscovery] = useState<ProfileDiscovery | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadProfiles = async (page: number) => {
        setLoading(true);
        setError(null);

        try {
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
                        : 'Unable to load Zernio profiles.',
                );
            }

            setDiscovery(payload);
        } catch (discoveryError) {
            setError(
                discoveryError instanceof Error
                    ? discoveryError.message
                    : 'Unable to load Zernio profiles.',
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Assign a Zernio profile</CardTitle>
                <CardDescription>
                    Load profiles from your connected Zernio account, then
                    assign one complete profile and its accounts to this Client.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {discovery === null ? (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={loading}
                        onClick={() => loadProfiles(1)}
                    >
                        {loading ? 'Loading profiles…' : 'Load Zernio profiles'}
                    </Button>
                ) : discovery.profiles.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No Zernio profiles are available on this page.
                    </p>
                ) : (
                    <div className="space-y-3">
                        {discovery.profiles.map((profile) => (
                            <div
                                key={profile.id}
                                className="flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {profile.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {profile.account_count === null
                                            ? 'Account count unavailable'
                                            : `${profile.account_count} account${profile.account_count === 1 ? '' : 's'}`}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            assignProfile(client.id).url,
                                            {
                                                provider_profile_id: profile.id,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Assign profile
                                </Button>
                            </div>
                        ))}
                    </div>
                )}

                {discovery !== null && (
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={loading || discovery.page === 1}
                            onClick={() => loadProfiles(discovery.page - 1)}
                        >
                            Previous
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={loading || !discovery.has_more}
                            onClick={() => loadProfiles(discovery.page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}

                {error && <InputError message={error} />}
            </CardContent>
        </Card>
    );
}

function PlaintextToken({ token, notice }: { token: string; notice?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(token);
        setCopied(true);
    };

    return (
        <Card className="border-amber-500/50 bg-amber-50 dark:bg-amber-950/20">
            <CardHeader>
                <CardTitle>Copy your new token</CardTitle>
                <CardDescription>
                    {notice ?? 'This plaintext token will only be shown once.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <Input aria-label="New agent token" readOnly value={token} />
                <Button type="button" variant="outline" onClick={copy}>
                    {copied ? 'Copied' : 'Copy token'}
                </Button>
            </CardContent>
        </Card>
    );
}

export default function AgentAccess({
    clients,
    selectedClient,
    abilities,
    token_plaintext,
    token_notice,
}: Props) {
    return (
        <>
            <Head title="Agent access" />
            <h1 className="sr-only">Agent access</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Agent access"
                    description="Manage Client-scoped accounts, capabilities, and API tokens for external agents."
                />

                {clients.length > 1 && (
                    <div className="flex flex-wrap gap-2">
                        {clients.map((client) => (
                            <Button
                                key={client.id}
                                variant={
                                    selectedClient?.id === client.id
                                        ? 'default'
                                        : 'outline'
                                }
                                size="sm"
                                asChild
                            >
                                <Link
                                    href={`/settings/agent-access?client=${client.id}`}
                                >
                                    {client.name}
                                </Link>
                            </Button>
                        ))}
                    </div>
                )}

                {selectedClient === null ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>No Agent Access Clients</CardTitle>
                            <CardDescription>
                                You do not have an active Client membership.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                ) : (
                    <>
                        <Card>
                            <CardHeader>
                                <div className="flex items-start justify-between gap-4">
                                    <div className="space-y-1">
                                        <CardTitle>
                                            {selectedClient.name}
                                        </CardTitle>
                                        <CardDescription>
                                            Role: {selectedClient.role}
                                        </CardDescription>
                                    </div>
                                    <Badge
                                        variant={
                                            selectedClient.can_manage
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {selectedClient.can_manage
                                            ? 'Can manage'
                                            : 'Read only'}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        Approved origins
                                    </p>
                                    {selectedClient.approved_origins.length ===
                                    0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No browser origins are approved.
                                        </p>
                                    ) : (
                                        <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
                                            {selectedClient.approved_origins.map(
                                                (origin) => (
                                                    <li key={origin}>
                                                        {origin}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {abilities.map((ability) => (
                                        <Badge
                                            key={ability.value}
                                            variant={
                                                selectedClient.capabilities[
                                                    ability.value
                                                ]?.available
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {ability.label}:{' '}
                                            {selectedClient.capabilities[
                                                ability.value
                                            ]?.available
                                                ? 'available'
                                                : 'unavailable'}
                                        </Badge>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        {token_plaintext && (
                            <PlaintextToken
                                token={token_plaintext}
                                notice={token_notice}
                            />
                        )}

                        {selectedClient.can_assign_profiles && (
                            <ProfileAssignment client={selectedClient} />
                        )}

                        <div className="space-y-4">
                            <Heading
                                variant="small"
                                title="Provider accounts"
                                description="Review synchronized accounts and the permissions granted to this Client."
                            />
                            {selectedClient.profiles.length === 0 ? (
                                <Card>
                                    <CardContent>
                                        <p className="text-sm text-muted-foreground">
                                            No provider profile is assigned to
                                            this Client.
                                        </p>
                                    </CardContent>
                                </Card>
                            ) : (
                                selectedClient.profiles.map((profile) => (
                                    <Card key={profile.id}>
                                        <CardHeader>
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                <CardTitle>
                                                    {profile.name}
                                                </CardTitle>
                                                {selectedClient.can_assign_profiles && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                syncProfile({
                                                                    client: selectedClient.id,
                                                                    profile:
                                                                        profile.id,
                                                                }).url,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Sync accounts
                                                    </Button>
                                                )}
                                            </div>
                                            <CardDescription>
                                                {profile.provider} · Last
                                                synchronized:{' '}
                                                {formatDate(
                                                    profile.synchronized_at,
                                                )}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-5">
                                            {profile.accounts.map((account) => (
                                                <div
                                                    key={account.id}
                                                    className="space-y-3 border-t pt-4 first:border-t-0 first:pt-0"
                                                >
                                                    <div className="flex items-center justify-between gap-3">
                                                        <div>
                                                            <p className="text-sm font-medium">
                                                                {account.name}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {
                                                                    account.platform
                                                                }
                                                            </p>
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                account.is_active
                                                                    ? 'secondary'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {account.is_active
                                                                ? 'Active'
                                                                : 'Inactive'}
                                                        </Badge>
                                                    </div>
                                                    <PermissionForm
                                                        client={selectedClient}
                                                        account={account}
                                                    />
                                                </div>
                                            ))}
                                        </CardContent>
                                    </Card>
                                ))
                            )}
                        </div>

                        {selectedClient.can_manage && (
                            <TokenCreationForm
                                client={selectedClient}
                                abilities={abilities}
                            />
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle>Issued tokens</CardTitle>
                                <CardDescription>
                                    Plaintext values are never displayed after
                                    issuance or rotation.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {selectedClient.tokens.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No tokens have been issued for this
                                        Client.
                                    </p>
                                ) : (
                                    selectedClient.tokens.map((token) => (
                                        <div
                                            key={token.id}
                                            className="space-y-3 border-t pt-4 first:border-t-0 first:pt-0"
                                        >
                                            <div className="flex items-start justify-between gap-4">
                                                <div>
                                                    <p className="font-medium">
                                                        {token.name}
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
                                                <Badge
                                                    variant={
                                                        token.revoked_at ===
                                                        null
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                >
                                                    {token.revoked_at === null
                                                        ? 'Active'
                                                        : 'Revoked'}
                                                </Badge>
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                {token.abilities.map(
                                                    (ability) => (
                                                        <Badge
                                                            key={ability}
                                                            variant="outline"
                                                        >
                                                            {ability}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                            {selectedClient.can_manage &&
                                                token.revoked_at === null && (
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
                                                                        abilities:
                                                                            token.abilities,
                                                                        allowed_account_ids:
                                                                            token.allowed_account_ids,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Rotate
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.delete(
                                                                    `/settings/agent-access/${selectedClient.id}/tokens/${token.id}`,
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Revoke
                                                        </Button>
                                                    </div>
                                                )}
                                        </div>
                                    ))
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
    breadcrumbs: [
        {
            title: 'Agent access',
            href: '/settings/agent-access',
        },
    ],
};
