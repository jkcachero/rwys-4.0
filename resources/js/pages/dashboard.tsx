import { Head } from '@inertiajs/react';
import { ArrowRight, MessageSquare, Settings2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { chatChoices } from '@/hooks/ai-connections';
import type { AIProvider } from '@/hooks/ai-connections';
import { chat, dashboard } from '@/routes';
import { edit as connectionSettings } from '@/routes/connections';
import type { Workspace } from './chat';

export default function Dashboard({
    workspace,
    providers = [],
}: {
    workspace: Workspace;
    providers?: AIProvider[];
}) {
    const oauthConnected =
        chatChoices([], workspace.providers ?? providers).length > 0;
    return (
        <>
            <Head title="Dashboard" />
            <div className="w-full max-w-5xl space-y-8 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {workspace.greeting}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Your workspace for conversations with OpenAI and Claude.
                    </p>
                </header>
                <nav aria-label="Workspace" className="flex flex-wrap gap-3">
                    <Button asChild>
                        <a href={chat.url()}>
                            <MessageSquare aria-hidden="true" />
                            Chat
                            <ArrowRight aria-hidden="true" />
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={connectionSettings.url()}>
                            <Settings2 aria-hidden="true" />
                            AI connections
                        </a>
                    </Button>
                </nav>
                <Separator />
                <section
                    aria-labelledby="connections-title"
                    className="max-w-2xl space-y-4"
                >
                    <h2
                        id="connections-title"
                        className="text-base font-medium"
                    >
                        Your connections
                    </h2>
                    {oauthConnected && (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span>OpenAI · ChatGPT subscription</span>
                            <Badge variant="secondary">Connected</Badge>
                        </div>
                    )}
                    {workspace.connections.length > 0 && (
                        <ul className="divide-y">
                            {workspace.connections.map((connection) => (
                                <li
                                    key={connection.id}
                                    className="space-y-2 py-3 first:pt-0"
                                >
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-medium">
                                            {connection.label}
                                        </span>
                                        <Badge variant="outline">
                                            {connection.provider === 'openai'
                                                ? 'OpenAI'
                                                : 'Claude'}{' '}
                                            · API key
                                        </Badge>
                                        <Badge variant="secondary">
                                            {connection.status}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {connection.model || 'Provider model'} ·{' '}
                                        {connection.lastValidatedAt
                                            ? `Last validated: ${connection.lastValidatedAt}`
                                            : 'Not tested'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                    {!oauthConnected && !workspace.connections.length && (
                        <p className="text-sm text-muted-foreground">
                            No AI connections yet. Connect a ChatGPT
                            subscription or save an API key in AI connections.
                        </p>
                    )}
                </section>
                <Separator />
                <section
                    className="max-w-2xl space-y-2"
                    aria-labelledby="advertising-title"
                >
                    <h2
                        id="advertising-title"
                        className="text-base font-medium"
                    >
                        Advertising tools
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        AdKit and Zernio tools are not yet connected. Chat
                        responses do not include live advertising account data.
                    </p>
                </section>
            </div>
        </>
    );
}
Dashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] };
