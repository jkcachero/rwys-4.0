import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
import Connections from './connections';

describe('connection settings', () => {
    it('loads saved keys when the settings controller omits connection props', () => {
        const html = renderToStaticMarkup(<Connections />);
        expect(html).toContain('role="status"');
        expect(html).toContain('Loading personal connections');
    });
    it('uses shared controls and reports server flash errors accessibly', () => {
        const html = renderToStaticMarkup(
            <Connections
                connections={[]}
                providers={[]}
                flash={{ error: 'Sign-in failed.' }}
            />,
        );
        expect(html).toContain('AI connections');
        expect(html).toContain('max-w-2xl');
        expect(html).toContain('Sign-in failed.');
        expect(html).toContain('role="alert"');
        expect(html).not.toContain('bg-gradient');
    });
    it('treats OAuth separately from API keys and does not offer Claude OAuth', () => {
        const html = renderToStaticMarkup(
            <Connections
                connections={[]}
                providers={[
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: 'model',
                        authentication: ['oauth'],
                        capabilities: [],
                        connected: true,
                    },
                ]}
            />,
        );
        expect(html).toContain('Disconnect OpenAI subscription');
        expect(html).toContain('Test OpenAI subscription');
        expect(html).not.toContain('Connect Claude');
    });
});
