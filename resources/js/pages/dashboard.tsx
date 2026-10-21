import { Head } from '@inertiajs/react';
import { Bot, FileSearch, LockKeyhole, Send } from 'lucide-react';
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
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const submit = () => {
        if (!question.trim()) return;
        setLoading(true);
        setError('');
        fetch('/workspace/read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') ?? '',
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
                <Card>
                    <CardHeader>
                        <CardTitle>Personal AI connections</CardTitle>
                        <CardDescription>
                            Connections are encrypted on the server and visible only to your account.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {workspace.connections.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No personal OpenAI or Claude connection is configured yet.
                            </p>
                        ) : (
                            workspace.connections.map((connection) => (
                                <div className="flex items-center justify-between rounded-md border p-3 text-sm" key={connection.id}>
                                    <span>{connection.provider} · {connection.label}</span>
                                    <Badge variant="secondary">{connection.status}</Badge>
                                </div>
                            ))
                        )}
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
