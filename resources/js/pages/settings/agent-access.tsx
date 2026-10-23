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
    owner: {
        name: string;
        email: string;
    } | null;
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
    profiles: Profile[];
    tokens: AgentToken[];
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
    token_plaintext?: string;
    token_notice?: string;
};

type TokenForm = {
    name: string;
    allowed_account_ids: string[];
    expires_at: string;
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

function TokenCreationForm({ client }: { client: SelectedClient }) {
    const accounts = client.profiles.flatMap((profile) => profile.accounts);
    const { data, setData, post, processing, errors, reset } =
        useForm<TokenForm>({
            name: '',
            allowed_account_ids: [],
            expires_at: futureDateTime(),
        });

    const toggleAccount = (value: string, checked: boolean) => {
        setData(
            'allowed_account_ids',
            checked
                ? [...data.allowed_account_ids, value]
                : data.allowed_account_ids.filter((item) => item !== value),
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
                <CardTitle>Create secure access</CardTitle>
                <CardDescription>
                    Access can read and publish for the connected accounts you
                    select. Access expires within 90 days.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form className="space-y-5" onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="token-name">Access name</Label>
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
                            Allowed accounts
                        </legend>
                        {accounts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No approved accounts are available.
                            </p>
                        ) : (
                            <div className="grid gap-2">
                                {accounts.map((account) => {
                                    return (
                                        <label
                                            key={account.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={data.allowed_account_ids.includes(
                                                    account.id,
                                                )}
                                                disabled={!account.available}
                                                onCheckedChange={(checked) =>
                                                    toggleAccount(
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

                    <Button disabled={processing}>Create access</Button>
                </form>
            </CardContent>
        </Card>
    );
}

function ProfileAssignment({ client }: { client: SelectedClient }) {
    const [discovery, setDiscovery] = useState<ProfileDiscovery | null>(null);
    const [selectedProfileId, setSelectedProfileId] = useState('');
    const [loading, setLoading] = useState(false);
    const [assigning, setAssigning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadProfiles = async () => {
        setLoading(true);
        setError(null);
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

    const assignSelectedProfile = () => {
        if (selectedProfileId === '') {
            return;
        }

        router.post(
            assignProfile(client.id).url,
            { provider_profile_id: selectedProfileId },
            {
                preserveScroll: true,
                onStart: () => {
                    setAssigning(true);
                    setError(null);
                },
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
        <Card>
            <CardHeader>
                <CardTitle>Assign a profile</CardTitle>
                <CardDescription>
                    Load the available profiles, choose one, and assign it to
                    the selected user.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                <div className="space-y-2">
                    <p className="text-sm font-medium">
                        Step 2 — Load available profiles
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={loading}
                            onClick={loadProfiles}
                        >
                            {loading ? 'Loading profiles…' : 'Load profiles'}
                        </Button>
                        <Button asChild type="button" variant="ghost">
                            <Link href="/settings/connections">
                                Connection settings
                            </Link>
                        </Button>
                    </div>
                </div>

                {discovery !== null && (
                    <div className="space-y-3">
                        <Label htmlFor="profile-assignment">
                            Step 3 — Choose a profile
                        </Label>
                        {discovery.profiles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No profiles are available.
                            </p>
                        ) : (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <select
                                    id="profile-assignment"
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    value={selectedProfileId}
                                    onChange={(event) =>
                                        setSelectedProfileId(event.target.value)
                                    }
                                >
                                    <option value="">Select a profile</option>
                                    {discovery.profiles.map((profile) => (
                                        <option
                                            key={profile.id}
                                            value={profile.id}
                                        >
                                            {profile.name}
                                            {profile.account_count === null
                                                ? ''
                                                : ` (${profile.account_count} account${profile.account_count === 1 ? '' : 's'})`}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    type="button"
                                    disabled={
                                        assigning || selectedProfileId === ''
                                    }
                                    onClick={assignSelectedProfile}
                                >
                                    {assigning
                                        ? 'Assigning…'
                                        : 'Assign profile'}
                                </Button>
                            </div>
                        )}
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
                <CardTitle>Copy your new access key</CardTitle>
                <CardDescription>
                    {notice ?? 'This access key will only be shown once.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <Input aria-label="New access key" readOnly value={token} />
                <Button type="button" variant="outline" onClick={copy}>
                    {copied ? 'Copied' : 'Copy access key'}
                </Button>
            </CardContent>
        </Card>
    );
}

export default function AgentAccess({
    clients,
    selectedClient,
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
                    description="Assign connected profiles and create secure access for registered users."
                />

                {clients.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Step 1 — Select a registered user
                            </CardTitle>
                            <CardDescription>
                                Choose the user who should receive profile
                                access.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <select
                                aria-label="Registered user"
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                value={selectedClient?.id ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        '/settings/agent-access',
                                        { client: event.target.value },
                                        { preserveState: true },
                                    )
                                }
                            >
                                {clients.map((client) => (
                                    <option key={client.id} value={client.id}>
                                        {client.owner === null
                                            ? client.name
                                            : `${client.owner.name} — ${client.owner.email}`}
                                    </option>
                                ))}
                            </select>
                        </CardContent>
                    </Card>
                )}

                {selectedClient === null ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>No registered users available</CardTitle>
                            <CardDescription>
                                There are no eligible users available for access
                                setup.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                ) : (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {selectedClient.owner?.name ??
                                        selectedClient.name}
                                </CardTitle>
                                <CardDescription>
                                    {selectedClient.owner?.email ??
                                        'Registered user'}
                                    {' · '}
                                    {selectedClient.name}
                                </CardDescription>
                            </CardHeader>
                        </Card>

                        {token_plaintext && (
                            <PlaintextToken
                                token={token_plaintext}
                                notice={token_notice}
                            />
                        )}

                        {selectedClient.can_assign_profiles && (
                            <ProfileAssignment
                                key={selectedClient.id}
                                client={selectedClient}
                            />
                        )}

                        <div className="space-y-4">
                            <Heading
                                variant="small"
                                title="Connected accounts"
                                description="Accounts available to the selected user."
                            />
                            {selectedClient.profiles.length === 0 ? (
                                <Card>
                                    <CardContent>
                                        <p className="text-sm text-muted-foreground">
                                            No profile has been assigned to this
                                            user yet.
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
                                                        Refresh accounts
                                                    </Button>
                                                )}
                                            </div>
                                            <CardDescription>
                                                Last updated:{' '}
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
                                                </div>
                                            ))}
                                        </CardContent>
                                    </Card>
                                ))
                            )}
                        </div>

                        {selectedClient.can_manage && (
                            <TokenCreationForm client={selectedClient} />
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle>Secure access</CardTitle>
                                <CardDescription>
                                    Access keys are shown once when created or
                                    replaced.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {selectedClient.tokens.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No secure access has been created for
                                        this user.
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
                                                        : 'Disabled'}
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
                                                                        allowed_account_ids:
                                                                            token.allowed_account_ids,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Replace access key
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
                                                            Disable
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
