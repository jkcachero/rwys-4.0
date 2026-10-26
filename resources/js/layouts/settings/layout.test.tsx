import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ isCurrentOrParentUrl: () => false }),
}));

import SettingsLayout from './layout';

describe('Settings navigation', () => {
    it('keeps account settings without the retired Connections page', () => {
        const markup = renderToStaticMarkup(
            <SettingsLayout>Settings content</SettingsLayout>,
        );

        expect(markup).toContain('Profile');
        expect(markup).toContain('Security');
        expect(markup).toContain('Appearance');
        expect(markup).toContain('Agent access');
        expect(markup).toContain('Settings content');
        expect(markup).not.toContain('Connections');
        expect(markup).not.toContain('/settings/connections');
    });
});
