import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Provider = {
    name: string;
    label: string;
    model: string | null;
    authentication: string[];
    capabilities: string[];
    message?: string | null;
    connected: boolean;
    expiresAt?: string | null;
};

type Props = {
    providers: Provider[];
};

function ZernioConnectionForm() {
    const { data, setData, put, processing, errors, reset } = useForm({
        api_token: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        put('/settings/connections/zernio', {
            preserveScroll: true,
            onSuccess: () => reset('api_token'),
        });
    };

    return (
        <form className="grid gap-3" onSubmit={submit}>
            <div className="grid gap-2">
                <Label htmlFor="zernio-api-token">Zernio API token</Label>
                <Input
                    id="zernio-api-token"
                    type="password"
                    autoComplete="off"
                    value={data.api_token}
                    onChange={(event) =>
                        setData('api_token', event.target.value)
                    }
                    required
                />
                <InputError message={errors.api_token} />
            </div>
            <div>
                <Button disabled={processing || data.api_token.length === 0}>
                    {processing ? 'Verifying…' : 'Connect Zernio'}
                </Button>
            </div>
        </form>
    );
}

export default function Connections({ providers }: Props) {
    return (
        <>
            <Head title="Provider connections" />
            <h1 className="sr-only">Provider connections</h1>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Provider connections"
                    description="Connect approved provider accounts for bounded, tool-free inference."
                />

                <div className="grid gap-4">
                    {providers.map((provider) => (
                        <Card key={provider.name}>
                            <CardHeader>
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <CardTitle>{provider.label}</CardTitle>
                                        {provider.model && (
                                            <CardDescription>
                                                Model: {provider.model}
                                            </CardDescription>
                                        )}
                                    </div>
                                    <Badge
                                        variant={
                                            provider.connected
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {provider.connected
                                            ? 'Connected'
                                            : 'Not connected'}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    {provider.message ??
                                        (provider.authentication.length > 0
                                            ? `Supported authentication: ${provider.authentication.join(', ')}`
                                            : 'No supported authentication method is configured.')}
                                </p>
                                <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                    {provider.capabilities.map((capability) => (
                                        <span
                                            key={capability}
                                            className="rounded-md border px-2 py-1"
                                        >
                                            {capability.replace('_', ' ')}
                                        </span>
                                    ))}
                                </div>
                                {provider.name === 'openai' &&
                                    !provider.connected && (
                                        <Button asChild>
                                            <Link href="/settings/connections/openai">
                                                Connect with OpenAI
                                            </Link>
                                        </Button>
                                    )}
                                {provider.name === 'zernio' &&
                                    !provider.connected && (
                                        <ZernioConnectionForm />
                                    )}
                                {provider.connected && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/connections/${provider.name}/test`,
                                                )
                                            }
                                        >
                                            Test connection
                                        </Button>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.delete(
                                                    `/settings/connections/${provider.name}`,
                                                )
                                            }
                                        >
                                            Disconnect
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </>
    );
}

Connections.layout = {
    breadcrumbs: [
        {
            title: 'Provider connections',
            href: '/settings/connections',
        },
    ],
};
