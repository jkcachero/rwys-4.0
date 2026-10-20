import { Head } from '@inertiajs/react';
import { Bot, CircleAlert, FileSearch, LockKeyhole } from 'lucide-react';
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

type WorkspaceCapability = {
    name: string;
    status: 'deferred' | 'disabled';
};

type Workspace = {
    status: 'ready';
    greeting: string;
    correlationId: string;
    conversation: {
        status: 'empty';
        messages: unknown[];
    };
    sources: unknown[];
    provider: {
        status: 'deferred';
        message: string;
    };
    capabilities: WorkspaceCapability[];
};

type DashboardProps = {
    workspace: Workspace;
};

export default function Dashboard({ workspace }: DashboardProps) {
    return (
        <>
            <Head title="AI Workspace" />
            <div className="flex flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
                <header className="flex flex-col gap-2">
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Bot className="size-4" aria-hidden="true" />
                        <span>Authenticated AI workspace</span>
                        <Badge variant="outline">Read-only</Badge>
                    </div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {workspace.greeting}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Explore approved read capabilities and conversation
                        context from one secure workspace. Provider actions
                        remain unavailable until separately approved.
                    </p>
                </header>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(18rem,1fr)]">
                    <Card className="min-h-80">
                        <CardHeader>
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <CardTitle>Conversation</CardTitle>
                                    <CardDescription>
                                        Start with an approved read or analysis
                                        request.
                                    </CardDescription>
                                </div>
                                <Badge variant="secondary">Empty</Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="flex flex-1 flex-col items-center justify-center gap-4 text-center">
                            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <FileSearch
                                    className="size-6"
                                    aria-hidden="true"
                                />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">No messages yet</h2>
                                <p className="max-w-md text-sm text-muted-foreground">
                                    Read and analyze capabilities will appear
                                    here after their provider contracts and
                                    evidence requirements are approved.
                                </p>
                            </div>
                            <Button disabled aria-disabled="true">
                                Start a read request
                            </Button>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <CircleAlert
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Provider status
                                </CardTitle>
                                <CardDescription>
                                    {workspace.provider.message}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Badge variant="outline">Deferred</Badge>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <LockKeyhole
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Capability access
                                </CardTitle>
                                <CardDescription>
                                    Only explicitly approved capabilities can
                                    become available.
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
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                    <span>
                        Source evidence will be shown with each approved read
                        result.
                    </span>
                    <span>Request: {workspace.correlationId}</span>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
