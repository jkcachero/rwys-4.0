import { useId, useState } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { chatChoices, useAIConnections } from '@/hooks/ai-connections';
import type {
    AIConnection,
    AIProvider,
    OAuthAttempt,
} from '@/hooks/ai-connections';

type ProviderName = 'openai' | 'claude';
type ConnectionMethod = 'subscription' | 'api_key';
const providerOptions: {
    name: ProviderName;
    label: string;
    note: string;
    methods: { value: ConnectionMethod; label: string }[];
}[] = [
    {
        name: 'openai',
        label: 'OpenAI',
        note: 'Eligible OpenAI subscriptions can also connect with ChatGPT. API keys use separate billing.',
        methods: [
            { value: 'subscription', label: 'ChatGPT subscription' },
            { value: 'api_key', label: 'API key' },
        ],
    },
    {
        name: 'claude',
        label: 'Claude',
        note: 'Claude subscription OAuth is not supported: Anthropic does not provide an approved subscription OAuth flow for this application. Use a Claude API key instead.',
        methods: [{ value: 'api_key', label: 'API key' }],
    },
];

export function connectionMethods(provider: string) {
    return (
        providerOptions.find((option) => option.name === provider)?.methods ??
        []
    );
}

export function ProviderNote({ provider }: { provider: string }) {
    const selectedProvider = providerOptions.find(
        (option) => option.name === provider,
    );
    if (!selectedProvider) return null;
    return (
        <p className="text-sm text-muted-foreground">{selectedProvider.note}</p>
    );
}

export function OAuthStatus({
    attempt,
    message,
}: {
    attempt: OAuthAttempt | null;
    message: string;
}) {
    return (
        <div role="status" className="space-y-2 text-sm">
            {message && <p>{message}</p>}
            {attempt && (
                <>
                    <a
                        className="underline underline-offset-4"
                        href={attempt.url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Continue with ChatGPT
                    </a>
                    <p className="text-muted-foreground">
                        Use this link to open ChatGPT sign-in in a new tab.
                        Attempt expires:{' '}
                        <time dateTime={attempt.expiresAt}>
                            {attempt.expiresAt}
                        </time>
                    </p>
                </>
            )}
        </div>
    );
}

export function ConnectionList({
    connections,
    pending,
    onTest,
    onRevoke,
}: {
    connections: AIConnection[];
    pending: boolean;
    onTest: (connection: AIConnection) => void;
    onRevoke: (id: number) => void;
}) {
    if (!connections.length)
        return (
            <p className="text-sm text-muted-foreground">
                No saved API keys for this provider.
            </p>
        );
    return (
        <ul className="space-y-4">
            {connections.map((connection) => (
                <li
                    key={connection.id}
                    className="space-y-2 border-t pt-4 first:border-0 first:pt-0"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium">
                            {connection.label}
                        </span>
                        <Badge variant="outline">
                            {connection.provider === 'openai'
                                ? 'OpenAI'
                                : 'Claude'}{' '}
                            · API key
                        </Badge>
                        <Badge variant="secondary">{connection.status}</Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {connection.model || 'Provider model'} ·{' '}
                        {connection.lastValidatedAt
                            ? `Last validated: ${connection.lastValidatedAt}`
                            : 'Not tested'}
                    </p>
                    {connection.status !== 'revoked' && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pending}
                                onClick={() => onTest(connection)}
                                aria-label={`Test ${connection.label}`}
                            >
                                Test connection
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pending}
                                onClick={() => onRevoke(connection.id)}
                                aria-label={`Revoke ${connection.label}`}
                            >
                                Revoke
                            </Button>
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function AIConnections({
    connections,
    providers,
    onConnectionsChange,
    onProvidersChange,
    initialProvider = 'openai',
    initialMethod,
}: {
    connections: AIConnection[];
    providers: AIProvider[];
    onConnectionsChange: (connections: AIConnection[]) => void;
    onProvidersChange?: (providers: AIProvider[]) => void;
    initialProvider?: ProviderName;
    initialMethod?: ConnectionMethod;
}) {
    const id = useId();
    const [draft, setDraft] = useState({
        provider: initialProvider,
        label: '',
        apiKey: '',
        model: '',
    });
    const [method, setMethod] = useState(
        initialMethod ?? connectionMethods(initialProvider)[0].value,
    );
    const actions = useAIConnections(
        connections,
        providers,
        onConnectionsChange,
        onProvidersChange,
    );
    const methods = connectionMethods(draft.provider);
    const selectedMethod = methods.some((option) => option.value === method)
        ? method
        : methods[0].value;
    const selectedProvider = providerOptions.find(
        (provider) => provider.name === draft.provider,
    )!;
    const oauthConnected = chatChoices([], providers).length > 0;
    const oauthProvider = providers.find(
        (provider) => provider.name === 'openai',
    );

    return (
        <section
            className="space-y-6"
            aria-label="AI connections"
            aria-busy={actions.pending}
        >
            <Heading
                variant="small"
                title="AI connections"
                description="Private to your account. Choose a provider and how to connect."
            />
            <div className="space-y-2">
                <p id={`${id}-provider-label`} className="text-sm font-medium">
                    Provider
                </p>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={draft.provider}
                    disabled={actions.pending}
                    aria-labelledby={`${id}-provider-label`}
                    className="w-full justify-start sm:w-fit"
                    onValueChange={(value) => {
                        const provider = providerOptions.find(
                            (option) => option.name === value,
                        );
                        if (!provider) return;
                        setDraft((current) => ({
                            ...current,
                            provider: provider.name,
                            apiKey: '',
                            model: '',
                        }));
                        setMethod(provider.methods[0].value);
                    }}
                >
                    {providerOptions.map((provider) => (
                        <ToggleGroupItem
                            key={provider.name}
                            value={provider.name}
                            className="flex-1 px-6"
                        >
                            {provider.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </div>
            <div className="space-y-2">
                <p id={`${id}-method-label`} className="text-sm font-medium">
                    Connection type
                </p>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={selectedMethod}
                    disabled={actions.pending}
                    aria-labelledby={`${id}-method-label`}
                    className="w-full"
                    onValueChange={(value) => {
                        const option = methods.find(
                            (option) => option.value === value,
                        );
                        if (option) setMethod(option.value);
                    }}
                >
                    {methods.map((option) => (
                        <ToggleGroupItem
                            key={option.value}
                            value={option.value}
                            className="flex-1 px-3 text-xs sm:text-sm"
                        >
                            {option.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                <ProviderNote provider={draft.provider} />
            </div>

            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <CardTitle>
                            <h3>{selectedProvider.label}</h3>
                        </CardTitle>
                        <Badge variant="outline">
                            {selectedMethod === 'subscription'
                                ? 'Subscription'
                                : 'API key'}
                        </Badge>
                    </div>
                    <CardDescription>
                        {selectedMethod === 'subscription'
                            ? 'Use your eligible ChatGPT subscription. No API key required.'
                            : 'Separate API billing applies. Your subscription does not include API usage.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    {selectedMethod === 'subscription' ? (
                        <>
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h4 className="text-base font-medium">
                                        ChatGPT subscription
                                    </h4>
                                    <Badge
                                        variant={
                                            oauthConnected
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {oauthConnected
                                            ? 'Connected'
                                            : 'Not connected'}
                                    </Badge>
                                </div>
                                {oauthConnected ? (
                                    <>
                                        {oauthProvider?.expiresAt && (
                                            <p className="text-sm text-muted-foreground">
                                                Access token expiry:{' '}
                                                {oauthProvider.expiresAt}.
                                                Access tokens are refreshed
                                                automatically when needed while
                                                the subscription remains
                                                connected.
                                            </p>
                                        )}
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                disabled={actions.pending}
                                                onClick={() => {
                                                    void actions.testConnection();
                                                }}
                                            >
                                                Test OpenAI subscription
                                            </Button>
                                            <Button
                                                variant="outline"
                                                disabled={actions.pending}
                                                onClick={() => {
                                                    void actions.disconnectOAuth();
                                                }}
                                            >
                                                Disconnect OpenAI subscription
                                            </Button>
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            Start the local OAuth listener on
                                            the machine running your browser
                                            before connecting.
                                        </p>
                                        <Button
                                            disabled={actions.pending}
                                            onClick={() => {
                                                void actions.connectOAuth();
                                            }}
                                        >
                                            Connect with ChatGPT
                                        </Button>
                                    </>
                                )}
                                {actions.attempt && (
                                    <Button
                                        variant="outline"
                                        onClick={actions.cancel}
                                    >
                                        Cancel sign-in
                                    </Button>
                                )}
                            </div>
                            <details className="text-sm">
                                <summary className="cursor-pointer rounded-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    Local setup instructions
                                </summary>
                                <p className="mt-3 leading-6 text-muted-foreground">
                                    Before connecting, run{' '}
                                    <code>php artisan openai:oauth-listen</code>{' '}
                                    in the same checkout and environment on the
                                    machine running your browser. Keep it
                                    running during sign-in. This connects your
                                    eligible ChatGPT subscription; an API key is
                                    not a substitute for this setup.
                                </p>
                            </details>
                        </>
                    ) : (
                        <>
                            <h4 className="text-base font-medium">API key</h4>
                            <form
                                className="space-y-4"
                                onSubmit={async (event) => {
                                    event.preventDefault();
                                    if (await actions.save(draft))
                                        setDraft((current) => ({
                                            ...current,
                                            label: '',
                                            apiKey: '',
                                            model: '',
                                        }));
                                }}
                            >
                                <fieldset
                                    disabled={actions.pending}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor={`${id}-label`}>
                                            Connection label
                                        </Label>
                                        <Input
                                            id={`${id}-label`}
                                            required
                                            value={draft.label}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    label: event.target.value,
                                                })
                                            }
                                            placeholder="Personal or work"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`${id}-key`}>
                                            API key
                                        </Label>
                                        <Input
                                            id={`${id}-key`}
                                            type="password"
                                            autoComplete="off"
                                            required
                                            value={draft.apiKey}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    apiKey: event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                    <details className="text-sm">
                                        <summary className="cursor-pointer rounded-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                            Advanced options
                                        </summary>
                                        <div className="mt-3 grid gap-2">
                                            <Label htmlFor={`${id}-model`}>
                                                Model (optional)
                                            </Label>
                                            <Input
                                                id={`${id}-model`}
                                                value={draft.model}
                                                onChange={(event) =>
                                                    setDraft({
                                                        ...draft,
                                                        model: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </div>
                                    </details>
                                    <p className="text-sm text-muted-foreground">
                                        Saving stores the key; it is not
                                        validated until you choose Test
                                        connection.
                                    </p>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={
                                            actions.pending ||
                                            !draft.label.trim() ||
                                            !draft.apiKey.trim()
                                        }
                                    >
                                        Save API key
                                    </Button>
                                </fieldset>
                            </form>
                            <Separator />
                            <section
                                className="space-y-3"
                                aria-labelledby={`${id}-saved-keys`}
                            >
                                <h4
                                    id={`${id}-saved-keys`}
                                    className="text-sm font-medium"
                                >
                                    Saved {selectedProvider.label} API keys
                                </h4>
                                <ConnectionList
                                    connections={connections.filter(
                                        (connection) =>
                                            connection.provider ===
                                            draft.provider,
                                    )}
                                    pending={actions.pending}
                                    onTest={(connection) => {
                                        void actions.testConnection(connection);
                                    }}
                                    onRevoke={(connectionId) => {
                                        void actions.revoke(connectionId);
                                    }}
                                />
                            </section>
                        </>
                    )}
                </CardContent>
            </Card>
            <OAuthStatus
                attempt={actions.attempt}
                message={
                    actions.message ||
                    (actions.pending ? 'Request in progress.' : '')
                }
            />
            {actions.error && (
                <AlertError
                    errors={[actions.error]}
                    title="Connection request failed"
                />
            )}
        </section>
    );
}
