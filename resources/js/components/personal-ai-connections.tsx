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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type AIProvider = 'openai' | 'claude';

export type AIConnection = {
    id: number | string;
    authMode?: string;
    createdAt?: string;
    label: string;
    model?: string | null;
    provider: string;
    status: string;
};

export type ChatGPTLogin = {
    accountLabel?: string | null;
    expiresAt?: string;
    message?: string;
    sessionId: string;
    status: string;
    userCode: string;
    verificationUrl: string;
};

type ProviderConfiguration = {
    apiKeyLabel: string;
    apiKeyPlaceholder: string;
    description: string;
    labelPlaceholder: string;
    modelPlaceholder: string;
    name: string;
};

const providerConfigurations: Record<AIProvider, ProviderConfiguration> = {
    openai: {
        apiKeyLabel: 'OpenAI API key',
        apiKeyPlaceholder: 'sk-…',
        description:
            'Use ChatGPT sign-in when available, or connect with an OpenAI API key.',
        labelPlaceholder: 'Personal OpenAI',
        modelPlaceholder: 'gpt-5',
        name: 'OpenAI',
    },
    claude: {
        apiKeyLabel: 'Claude API key',
        apiKeyPlaceholder: 'sk-ant-…',
        description:
            'Claude consumer subscription sign-in is not supported. Connect with an Anthropic API key.',
        labelPlaceholder: 'Personal Claude',
        modelPlaceholder: 'claude-3-5-sonnet-latest',
        name: 'Claude',
    },
};

export type PersonalAIConnectionsProps = {
    apiKey: string;
    chatGPTError: string | null;
    chatGPTLogin: ChatGPTLogin | null;
    connectionError: string | null;
    connections: AIConnection[];
    isSavingConnection: boolean;
    isStartingChatGPTLogin: boolean;
    label: string;
    model: string;
    onApiKeyChange: (value: string) => void;
    onCancelChatGPTLogin: () => void;
    onLabelChange: (value: string) => void;
    onModelChange: (value: string) => void;
    onProviderChange: (provider: AIProvider) => void;
    onRevokeConnection: (connectionId: number | string) => void;
    onSaveConnection: () => void;
    onStartChatGPTLogin: () => void;
    provider: AIProvider;
    revokingConnectionId: number | string | null;
};

export function PersonalAIConnections({
    apiKey,
    chatGPTError,
    chatGPTLogin,
    connectionError,
    connections,
    isSavingConnection,
    isStartingChatGPTLogin,
    label,
    model,
    onApiKeyChange,
    onCancelChatGPTLogin,
    onLabelChange,
    onModelChange,
    onProviderChange,
    onRevokeConnection,
    onSaveConnection,
    onStartChatGPTLogin,
    provider,
    revokingConnectionId,
}: PersonalAIConnectionsProps) {
    const configuration = providerConfigurations[provider];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Personal AI connections</CardTitle>
                <CardDescription>
                    Add an OpenAI or Claude API key. Credentials remain
                    write-only after they are saved.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-6">
                <section
                    aria-labelledby="provider-configuration-heading"
                    className="grid gap-4 rounded-lg border p-4"
                >
                    <div className="grid gap-1">
                        <h3
                            className="font-medium"
                            id="provider-configuration-heading"
                        >
                            Configure a provider
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {configuration.description}
                        </p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="ai-provider">Provider</Label>
                        <Select
                            disabled={isSavingConnection}
                            onValueChange={(value) =>
                                onProviderChange(value as AIProvider)
                            }
                            value={provider}
                        >
                            <SelectTrigger
                                aria-label="Provider"
                                className="w-full"
                                id="ai-provider"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="openai">OpenAI</SelectItem>
                                <SelectItem value="claude">Claude</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {provider === 'openai' && (
                        <div className="grid gap-3 rounded-lg bg-muted/50 p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium">
                                    OpenAI subscription sign-in
                                </p>
                                <Badge variant="outline">OpenAI only</Badge>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Sign in through the local companion when it is
                                available. This is separate from API key access.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    disabled={isStartingChatGPTLogin}
                                    onClick={onStartChatGPTLogin}
                                    type="button"
                                >
                                    {isStartingChatGPTLogin
                                        ? 'Connecting…'
                                        : 'Connect with ChatGPT'}
                                </Button>
                                {chatGPTLogin && (
                                    <Button
                                        onClick={onCancelChatGPTLogin}
                                        type="button"
                                        variant="outline"
                                    >
                                        Cancel sign-in
                                    </Button>
                                )}
                            </div>
                            {chatGPTLogin?.message &&
                                chatGPTLogin.status !== 'error' && (
                                    <p
                                        aria-live="polite"
                                        className="text-sm text-muted-foreground"
                                        role="status"
                                    >
                                        {chatGPTLogin.message}
                                    </p>
                                )}
                            {chatGPTLogin?.userCode && (
                                <p className="text-sm">
                                    Code:{' '}
                                    <span className="font-mono font-medium">
                                        {chatGPTLogin.userCode}
                                    </span>
                                </p>
                            )}
                            <InputError
                                message={chatGPTError ?? undefined}
                                role="alert"
                            />
                        </div>
                    )}

                    <div className="grid gap-4">
                        <div className="grid gap-1">
                            <p className="font-medium">API key connection</p>
                            <p className="text-sm text-muted-foreground">
                                Configure server-side access with a provider API
                                key.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ai-connection-label">
                                Connection label
                            </Label>
                            <Input
                                disabled={isSavingConnection}
                                id="ai-connection-label"
                                onChange={(event) =>
                                    onLabelChange(event.target.value)
                                }
                                placeholder={configuration.labelPlaceholder}
                                value={label}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ai-api-key">
                                {configuration.apiKeyLabel}
                            </Label>
                            <Input
                                aria-describedby="ai-api-key-help"
                                autoComplete="off"
                                disabled={isSavingConnection}
                                id="ai-api-key"
                                onChange={(event) =>
                                    onApiKeyChange(event.target.value)
                                }
                                placeholder={configuration.apiKeyPlaceholder}
                                type="password"
                                value={apiKey}
                            />
                            <p
                                className="text-sm text-muted-foreground"
                                id="ai-api-key-help"
                            >
                                Saved credentials are encrypted and are never
                                returned to this page.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ai-model">
                                {provider === 'claude'
                                    ? 'Claude model (optional)'
                                    : 'OpenAI model (optional)'}
                            </Label>
                            <Input
                                disabled={isSavingConnection}
                                id="ai-model"
                                onChange={(event) =>
                                    onModelChange(event.target.value)
                                }
                                placeholder={configuration.modelPlaceholder}
                                value={model}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                disabled={isSavingConnection}
                                onClick={onSaveConnection}
                                type="button"
                            >
                                {isSavingConnection
                                    ? 'Saving…'
                                    : 'Save connection'}
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                Connects {configuration.name} with an API key.
                            </span>
                        </div>
                        <InputError
                            message={connectionError ?? undefined}
                            role="alert"
                        />
                    </div>
                </section>

                <section
                    aria-labelledby="saved-connections-heading"
                    className="grid gap-3"
                >
                    <div className="grid gap-1">
                        <h3
                            className="font-medium"
                            id="saved-connections-heading"
                        >
                            Saved connections
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Review connection status or revoke access. Secret
                            values are not displayed.
                        </p>
                    </div>
                    {connections.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No personal OpenAI or Claude connection is
                            configured yet.
                        </p>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {connections.map((connection) => {
                                const isRevoking =
                                    revokingConnectionId === connection.id;

                                return (
                                    <Card key={connection.id}>
                                        <CardContent className="grid gap-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="grid gap-1">
                                                    <p className="font-medium">
                                                        {connection.label}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {connection.provider}
                                                        {connection.model
                                                            ? ` · ${connection.model}`
                                                            : ''}
                                                    </p>
                                                </div>
                                                <Badge variant="secondary">
                                                    {connection.status
                                                        .charAt(0)
                                                        .toUpperCase() +
                                                        connection.status.slice(
                                                            1,
                                                        )}
                                                </Badge>
                                            </div>
                                            <Button
                                                disabled={isRevoking}
                                                onClick={() =>
                                                    onRevokeConnection(
                                                        connection.id,
                                                    )
                                                }
                                                type="button"
                                                variant="outline"
                                            >
                                                {isRevoking
                                                    ? 'Revoking…'
                                                    : 'Revoke'}
                                            </Button>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </section>
            </CardContent>
        </Card>
    );
}
