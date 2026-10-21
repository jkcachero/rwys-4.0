import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AIConnections from '@/components/ai-connections';
import AlertError from '@/components/alert-error';
import { useSavedAIConnections } from '@/hooks/ai-connections';
import type { AIConnection, AIProvider } from '@/hooks/ai-connections';
import { edit } from '@/routes/connections';

type Props = {
    connections?: AIConnection[];
    providers?: AIProvider[];
    flash?: { status?: string | null; error?: string | null };
};

export default function Connections({
    connections: initialConnections,
    providers: initialProviders = [],
    flash,
}: Props) {
    const { connections, setConnections, loading, error } =
        useSavedAIConnections(initialConnections);
    const [providers, setProviders] = useState(initialProviders);
    return (
        <>
            <Head title="AI connections" />
            <h1 className="sr-only">AI connections</h1>
            <div className="w-full max-w-2xl space-y-6">
                {flash?.status && (
                    <p role="status" className="text-sm">
                        {flash.status}
                    </p>
                )}
                {flash?.error && <AlertError errors={[flash.error]} />}
                {error && (
                    <AlertError
                        errors={[error]}
                        title="Connections could not be loaded"
                    />
                )}
                {loading ? (
                    <p role="status">Loading personal connections...</p>
                ) : (
                    <AIConnections
                        connections={connections}
                        providers={providers}
                        onConnectionsChange={setConnections}
                        onProvidersChange={setProviders}
                    />
                )}
            </div>
        </>
    );
}
Connections.layout = {
    breadcrumbs: [{ title: 'AI connections', href: edit() }],
};
