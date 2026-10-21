import { Head } from '@inertiajs/react';
import {
    Bot,
    CheckCircle2,
    Copy,
    ExternalLink,
    FileSearch,
    LockKeyhole,
    Send,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
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
    const [connectionProvider, setConnectionProvider] = useState('openai');
    const [connectionLabel, setConnectionLabel] = useState('');
    const [connectionApiKey, setConnectionApiKey] = useState('');
    const [connectionModel, setConnectionModel] = useState('');
    const [loading, setLoading] = useState(false);
    const [connectionLoading, setConnectionLoading] = useState(false);
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
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

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
                    throw new Error(data.message ?? 'The connection could not be saved.');
                }
                return data;
            })
            .then((data) => {
                setConnections((current) => [
                    ...current.filter((connection) => connection.id !== data.connection.id),
                    data.connection,
                ]);
                setConnectionLabel('');
                setConnectionApiKey('');
                setConnectionModel('');
            })
            .catch((requestError: Error) => setConnectionError(requestError.message))
            .finally(() => setConnectionLoading(false));
    };

    const revokeConnection = (connectionId: number) => {
        setConnectionLoading(true);
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
                    current.filter((connection) => connection.id !== connectionId),
                );
            })
            .catch((requestError: Error) => setConnectionError(requestError.message))
            .finally(() => setConnectionLoading(false));
    };

    const pollChatgptLogin = (sessionId: string) => {
        fetch(`http://127.0.0.1:8787/v1/chatgpt/login/${sessionId}`, {
            credentials: 'include',
        })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok) throw new Error(data.error ?? 'ChatGPT sign-in failed.');
                return data;
            })
            .then((data) => {
                if (data.status === 'connected') {
                    setChatgptLogin((current) => current ? { ...current, status: 'connected', message: 'ChatGPT is connected on this device.' } : current);
                    return;
                }
                window.setTimeout(() => pollChatgptLogin(sessionId), 2000);
            })
            .catch((requestError: Error) =>
                setChatgptLogin((current) => current ? { ...current, status: 'error', message: requestError.message } : current),
            );
    };

    const startChatgptLogin = () => {
        setChatgptLogin({ sessionId: '', userCode: '', verificationUrl: '', status: 'waiting' });
        fetch('http://127.0.0.1:8787/v1/chatgpt/login', {
            method: 'POST',
            credentials: 'include',
        })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok) throw new Error(data.error ?? 'Start the local companion first, then try again.');
                return data;
            })
            .then((data) => {
                setChatgptLogin({ ...data, status: 'ready' });
                window.open(data.verificationUrl, '_blank', 'noopener,noreferrer');
                pollChatgptLogin(data.sessionId);
            })
            .catch((requestError: Error) =>
                setChatgptLogin({ sessionId: '', userCode: '', verificationUrl: '', status: 'error', message: requestError.message }),
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
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-muted/20">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex gap-3">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                    <Sparkles className="size-5" />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <CardTitle>Personal AI connections</CardTitle>
                                    <CardDescription>
                                        Connect ChatGPT or save an API key. Add an OpenAI or Claude API key. Your credentials stay under your control and are never shown here.
                                    </CardDescription>
                                </div>
                            </div>
                            <Badge variant="outline" className="w-fit gap-1.5">
                                <LockKeyhole className="size-3" /> Private to you
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6 p-4 md:p-6">
                        <div className="rounded-xl border bg-gradient-to-br from-primary/10 via-background to-background p-4">
                            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                                <div className="flex gap-3">
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-background shadow-sm">
                                        <Bot className="size-5" />
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <p className="font-medium">Sign in with ChatGPT</p>
                                        <p className="text-sm text-muted-foreground">
                                            Use your ChatGPT plan through a small local companion. No ChatGPT OAuth token is uploaded to this app.
                                        </p>
                                    </div>
                                </div>
                                <Button className="shrink-0" onClick={startChatgptLogin} disabled={chatgptLogin?.status === 'waiting'}>
                                    {chatgptLogin?.status === 'waiting' ? 'Starting…' : 'Connect ChatGPT'}
                                    <ExternalLink className="ml-2 size-4" />
                                </Button>
                            </div>
                            {chatgptLogin?.status === 'ready' && (
                                <div className="mt-4 flex flex-col gap-3 rounded-lg border bg-background p-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex flex-col gap-1">
                                        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Enter this code in ChatGPT</span>
                                        <span className="font-mono text-xl font-semibold tracking-[0.2em]">{chatgptLogin.userCode}</span>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button size="sm" variant="outline" onClick={() => navigator.clipboard?.writeText(chatgptLogin.userCode)}>
                                            <Copy className="mr-2 size-4" /> Copy code
                                        </Button>
                                        <Button size="sm" variant="outline" onClick={() => window.open(chatgptLogin.verificationUrl, '_blank', 'noopener,noreferrer')}>
                                            Open ChatGPT <ExternalLink className="ml-2 size-4" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                            {chatgptLogin?.status === 'connected' && (
                                <p className="mt-4 flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                                    <CheckCircle2 className="size-4" /> {chatgptLogin.message}
                                </p>
                            )}
                            {chatgptLogin?.status === 'error' && <p className="mt-4 text-sm text-destructive">{chatgptLogin.message}</p>}
                        </div>
                        <div className="flex flex-col gap-3">
                            <div>
                                <p className="font-medium">Or connect with an API key</p>
                                <p className="text-sm text-muted-foreground">Use a provider key for server-side requests. It will be encrypted at rest.</p>
                            </div>
                            <div className="grid gap-3 md:grid-cols-2">
                                <select aria-label="AI provider" className="rounded-md border bg-background px-3 py-2 text-sm" value={connectionProvider} onChange={(event) => setConnectionProvider(event.target.value)}>
                                    <option value="openai">OpenAI</option>
                                    <option value="claude">Claude</option>
                                </select>
                                <input aria-label="Connection label" className="rounded-md border bg-background px-3 py-2 text-sm" placeholder="Label, e.g. Personal" value={connectionLabel} onChange={(event) => setConnectionLabel(event.target.value)} />
                                <input aria-label="API key" className="rounded-md border bg-background px-3 py-2 text-sm md:col-span-2" placeholder="Paste your API key" type="password" value={connectionApiKey} onChange={(event) => setConnectionApiKey(event.target.value)} />
                                <input aria-label="Model (optional)" className="rounded-md border bg-background px-3 py-2 text-sm" placeholder="Model (optional)" value={connectionModel} onChange={(event) => setConnectionModel(event.target.value)} />
                                <Button onClick={saveConnection} disabled={connectionLoading}>{connectionLoading ? 'Saving…' : 'Save connection'}</Button>
                            </div>
                        </div>
                        {connectionError && <p className="text-sm text-destructive">{connectionError}</p>}
                        {connections.length === 0 ? <p className="text-sm text-muted-foreground">No personal OpenAI or Claude connection is configured yet.</p> : connections.map((connection) => (
                            <div className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm" key={connection.id}>
                                <div className="flex items-center gap-2"><span>{connection.provider} · {connection.label}</span><Badge variant="secondary">{connection.status}</Badge></div>
                                <Button disabled={connectionLoading} onClick={() => revokeConnection(connection.id)} size="sm" variant="outline">Revoke</Button>
                            </div>
                        ))}
                    </CardContent>
                </Card>
                <div className="rounded-lg border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                    Source evidence is shown with each result. Request:{' '}
                    {workspace.correlationId}
                </div>
            </div>
        </>
    );
}
Dashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] };
