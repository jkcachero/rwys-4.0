import type { ComponentProps } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: (props: ComponentProps<'a'>) => <a {...props} />,
}));

import AgentAccessNavigation from './agent-access-navigation';

describe('Agent access navigation', () => {
    it.each(['accounts', 'tokens'] as const)(
        'marks only the %s section current for operators',
        (active) => {
            const markup = renderToStaticMarkup(
                <AgentAccessNavigation
                    active={active}
                    canAssignProviderAccounts
                />,
            );
            expect(markup).toContain('<h1');
            expect(markup).toContain('Agent access</h1>');
            expect(markup).toContain('Account assignments');
            expect(markup).toContain(
                'Assign Zernio profiles and their social accounts to clients',
            );
            expect(markup).toContain('My API tokens');
            expect(markup).toContain('Operator');
            expect(markup).toContain('Personal');
            expect(markup).toContain('href="/settings/provider-accounts"');
            expect(markup).toContain('href="/settings/agent-tokens"');
            expect(markup.match(/aria-current="page"/g)).toHaveLength(1);
            expect(markup).toMatch(
                new RegExp(
                    `href="/settings/${active === 'accounts' ? 'provider-accounts' : 'agent-tokens'}"[^>]*aria-current="page"[^>]*class="[^"]*bg-accent`,
                ),
            );
        },
    );

    it('explains operator-only assignments without a forbidden link', () => {
        const markup = renderToStaticMarkup(
            <AgentAccessNavigation
                active="tokens"
                canAssignProviderAccounts={false}
            />,
        );
        expect(markup).toContain('aria-disabled="true"');
        expect(markup).toContain('Only operators can assign accounts');
        expect(markup).not.toContain('href="/settings/provider-accounts"');
        expect(markup).toContain(
            'href="/settings/agent-tokens" aria-current="page"',
        );
    });
});
