import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export default function AgentAccessNavigation({
    active,
    canAssignProviderAccounts,
}: {
    active: 'accounts' | 'tokens';
    canAssignProviderAccounts: boolean;
}) {
    return (
        <header className="space-y-4">
            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    Agent access
                </h1>
                <p className="text-sm text-muted-foreground">
                    Operators assign Zernio profiles to clients. Client owners
                    create personal API tokens for their agents.
                </p>
            </div>
            <nav
                aria-label="Agent access"
                className="grid gap-2 sm:grid-cols-2"
            >
                {(
                    [
                        {
                            id: 'accounts',
                            href: '/settings/provider-accounts',
                            label: 'Account assignments',
                            badge: 'Operator',
                            description:
                                'Assign Zernio profiles and their social accounts to clients',
                        },
                        {
                            id: 'tokens',
                            href: '/settings/agent-tokens',
                            label: 'My API tokens',
                            badge: 'Personal',
                            description:
                                'Create and manage tokens for clients you own.',
                        },
                    ] as const
                ).map((section) => {
                    const locked =
                        section.id === 'accounts' && !canAssignProviderAccounts;
                    const className = cn(
                        'min-w-0 rounded-md border p-3 text-sm',
                        locked
                            ? 'text-muted-foreground'
                            : 'transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                        !locked &&
                            active === section.id &&
                            'border-foreground/30 bg-accent text-accent-foreground',
                    );
                    const content = (
                        <>
                            <span className="flex flex-wrap items-center gap-2 font-semibold">
                                {section.label}
                                <span className="rounded border px-1.5 py-0.5 text-xs font-normal text-muted-foreground">
                                    {section.badge}
                                </span>
                            </span>
                            <span className="mt-1 block text-sm text-muted-foreground">
                                {locked
                                    ? 'Only operators can assign accounts. Ask an operator for help.'
                                    : section.description}
                            </span>
                        </>
                    );
                    return locked ? (
                        <div
                            key={section.id}
                            aria-disabled="true"
                            className={className}
                        >
                            {content}
                        </div>
                    ) : (
                        <Link
                            key={section.id}
                            href={section.href}
                            aria-current={
                                active === section.id ? 'page' : undefined
                            }
                            className={className}
                        >
                            {content}
                        </Link>
                    );
                })}
            </nav>
        </header>
    );
}
