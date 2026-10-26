import { Head } from '@inertiajs/react';
import { Bot, FileSearch, LockKeyhole, Send } from 'lucide-react';
import { useState } from 'react';
import {
    PersonalAIConnections,
    type AIProvider,
} from '@/components/personal-ai-connections';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';

type Message = {
    role: string;
    content: string;
    sources: { provider: string; label: string; value: string }[];
};
type Connection = {
    id: number;
    provider: string;
    label: string;
    authMode: string;
    model: string | null;
    status: string;
    lastValidatedAt: string | null;
};
type Workspace = {
    status: 'ready';
    greeting: string;
    correlationId: string;
    conversation: { status: string; messages: Message[] };
    sources?: unknown[];
    provider: { status: string; message: string };
    connections: Connection[];
    capabilities: { name: string; status: string }[];
};

export default function Dashboard({ workspace }: { workspace: Workspace }) {
    const [question, setQuestion] = useState('');
    const [provider, setProvider] = useState('adkit');
    const [messages, setMessages] = useState(workspace.conversation.messages);
    const [connections, setConnections] = useState(workspace.connections);
    const [connectionProvider, setConnectionProvider] =
        useState<AIProvider>('openai');
    const [connectionLabel, setConnectionLabel] = useState('');
    const [connectionApiKey, setConnectionApiKey] = useState('');
    const [connectionModel, setConnectionModel] = useState('');
    const [loading, setLoading] = useState(false);
    const [connectionLoading, setConnectionLoading] = useState(false);
    const [revokingConnectionId, setRevokingConnectionId] = useState<
        number | null
    >(null);
    const [error, setError] = useState('');
    const [connectionError, setConnectionError] = useState('');
    const [chatgptLogin, setChatgptLogin] = useState<{
        sessionId: string;
        userCode: string;
        verificationUrl: string;
        status: 'ready' | 'waiting' | 'connected' | 'error';
        message?: string;
    } | null>(null);

    const csrfToken = () =>
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    const submit = () => {
        if (!question.trim()) return;
        setLoading(true);
        setError('');
        fetch('/workspace/read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ provider, scopeId: 'default', question }),
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error('The request could not be completed.');
                return response.json();
            })
            .then((data) => {
                setMessages((current) => [...current, data.message]);
                setQuestion('');
            })
            .catch((requestError: Error) => setError(requestError.message))
            .finally(() => setLoading(false));
    };

    const saveConnection = () => {
        if (!connectionLabel.trim() || !connectionApiKey.trim()) {
            setConnectionError('Enter a label and API key.');
            return;
        }

        setConnectionLoading(true);
        setConnectionError('');
        fetch('/workspace/connections', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                provider: connectionProvider,
                authMode: 'api_key',
                label: connectionLabel,
                apiKey: connectionApiKey,
                model: connectionModel || null,
            }),
        })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(
                        data.message ?? 'The connection could not be saved.',
                    );
                }
                return data;
            })
            .then((data) => {
                setConnections((current) => [
                    ...current.filter(
                        (connection) => connection.id !== data.connection.id,
                    ),
                    data.connection,
                ]);
                setConnectionLabel('');
                setConnectionApiKey('');
                setConnectionModel('');
            })
            .catch((requestError: Error) =>
                setConnectionError(requestError.message),
            )
            .finally(() => setConnectionLoading(false));
    };

    const revokeConnection = (connectionId: number) => {
        setRevokingConnectionId(connectionId);
        setConnectionError('');
        fetch(`/workspace/connections/${connectionId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('The connection could not be revoked.');
                }
                setConnections((current) =>
                    current.filter(
                        (connection) => connection.id !== connectionId,
                    ),
                );
            })
            .catch((requestError: Error) =>
                setConnectionError(requestError.message),
            )
            .finally(() => setRevokingConnectionId(null));
    };

    const pollChatgptLogin = (sessionId: string) => {
        fetch(`http://127.0.0.1:8787/v1/chatgpt/login/${sessionId}`, {
            credentials: 'include',
        })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok)
                    throw new Error(data.error ?? 'ChatGPT sign-in failed.');
                return data;
            })
            .then((data) => {
                if (data.status === 'connected') {
                    setChatgptLogin((current) =>
                        current
                            ? {
                                  ...current,
                                  status: 'connected',
                                  message:
                                      'ChatGPT is connected on this device.',
                              }
                            : current,
                    );
                    return;
                }
                window.setTimeout(() => pollChatgptLogin(sessionId), 2000);
            })
            .catch((requestError: Error) =>
                setChatgptLogin((current) =>
                    current
                        ? {
                              ...current,
                              status: 'error',
                              message: requestError.message,
                          }
                        : current,
                ),
            );
    };

    const startChatgptLogin = () => {
        setChatgptLogin({
            sessionId: '',
            userCode: '',
            verificationUrl: '',
            status: 'waiting',
        });
        fetch('http://127.0.0.1:8787/v1/chatgpt/login', {
            method: 'POST',
            credentials: 'include',
        })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok)
                    throw new Error(
                        data.error ??
                            'Start the local companion first, then try again.',
                    );
                return data;
            })
            .then((data) => {
                setChatgptLogin({ ...data, status: 'ready' });
                window.open(
                    data.verificationUrl,
                    '_blank',
                    'noopener,noreferrer',
                );
                pollChatgptLogin(data.sessionId);
            })
            .catch((requestError: Error) =>
                setChatgptLogin({
                    sessionId: '',
                    userCode: '',
                    verificationUrl: '',
                    status: 'error',
                    message: requestError.message,
                }),
            );
    };

    return (
        <>
            <Head title="AI Workspace" />
            <div className="flex flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
                <header>
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Bot className="size-4" /> Authenticated AI workspace{' '}
                        <Badge variant="outline">Read + analyze</Badge>
                    </div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {workspace.greeting}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Ask bounded questions about approved AdKit and Zernio
                        account data. Provider actions require a separate
                        approval flow.
                    </p>
                </header>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(18rem,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileSearch className="size-5" /> Conversation{' '}
                                <Badge variant="secondary">
                                    {loading
                                        ? 'Working'
                                        : messages.length
                                          ? 'Active'
                                          : 'Ready'}
                                </Badge>
                            </CardTitle>
                            <CardDescription>
                                Requests stay authenticated and provider-scoped
                                on the server.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {messages.length === 0 && !loading && (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    No messages yet. Ask for an approved
                                    provider read.
                                </p>
                            )}
                            {messages.map((message, index) => (
                                <div
                                    className="rounded-lg border p-3 text-sm"
                                    key={index}
                                >
                                    <p>{message.content}</p>
                                    {message.sources.length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {message.sources.map((source) => (
                                                <Badge
                                                    variant="outline"
                                                    key={source.label}
                                                >
                                                    {source.provider}:{' '}
                                                    {source.label} (
                                                    {source.value})
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {error && (
                                <p className="text-sm text-destructive">
                                    {error}
                                </p>
                            )}
                            <div className="flex gap-2">
                                <select
                                    aria-label="Provider"
                                    className="rounded-md border bg-background px-2 text-sm"
                                    value={provider}
                                    onChange={(event) =>
                                        setProvider(event.target.value)
                                    }
                                >
                                    <option value="adkit">AdKit</option>
                                    <option value="zernio">Zernio</option>
                                </select>
                                <input
                                    aria-label="Question"
                                    className="min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
                                    value={question}
                                    onChange={(event) =>
                                        setQuestion(event.target.value)
                                    }
                                    onKeyDown={(event) =>
                                        event.key === 'Enter' && submit()
                                    }
                                    placeholder="What accounts are available?"
                                />
                                <Button
                                    onClick={submit}
                                    disabled={loading || !question.trim()}
                                >
                                    <Send className="mr-2 size-4" />
                                    Ask
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <LockKeyhole className="size-4" /> Capability
                                access
                            </CardTitle>
                            <CardDescription>
                                {workspace.provider.message}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {workspace.capabilities.map((capability) => (
                                <div
                                    className="flex items-center justify-between gap-3 text-sm"
                                    key={capability.name}
                                >
                                    <span>{capability.name}</span>
                                    <Badge variant="secondary">
                                        {capability.status}
                                    </Badge>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
                <PersonalAIConnections
                    apiKey={connectionApiKey}
                    chatGPTError={
                        chatgptLogin?.status === 'error'
                            ? (chatgptLogin.message ??
                              'ChatGPT sign-in failed.')
                            : null
                    }
                    chatGPTLogin={chatgptLogin}
                    connectionError={connectionError || null}
                    connections={connections}
                    isSavingConnection={connectionLoading}
                    isStartingChatGPTLogin={chatgptLogin?.status === 'waiting'}
                    label={connectionLabel}
                    model={connectionModel}
                    onApiKeyChange={setConnectionApiKey}
                    onCancelChatGPTLogin={() => setChatgptLogin(null)}
                    onLabelChange={setConnectionLabel}
                    onModelChange={setConnectionModel}
                    onProviderChange={setConnectionProvider}
                    onRevokeConnection={(connectionId) =>
                        revokeConnection(Number(connectionId))
                    }
                    onSaveConnection={saveConnection}
                    onStartChatGPTLogin={startChatgptLogin}
                    provider={connectionProvider}
                    revokingConnectionId={revokingConnectionId}
                />
                <div className="rounded-lg border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                    Source evidence is shown with each result. Request:{' '}
                    {workspace.correlationId}
                </div>
            </div>
        </>
    );
}
Dashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] };
