import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

import Dashboard from './dashboard';

describe('Dashboard', () => {
    it('renders the starter placeholders without the retired AI workspace', () => {
        const markup = renderToStaticMarkup(<Dashboard />);

        expect(markup.match(/<svg\b/g)).toHaveLength(4);
        expect(markup).toContain('md:grid-cols-3');
        expect(markup).not.toContain('Authenticated AI workspace');
        expect(markup).not.toContain('Conversation');
        expect(markup).not.toContain('Capability access');
        expect(markup).not.toContain('Personal AI connections');
        expect(markup).not.toContain('Connect ChatGPT');
        expect(markup).not.toContain('Save connection');
        expect(markup).not.toContain('Source evidence');
        expect(markup).not.toContain('<input');
    });
});
