import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import {
    PersonalAIConnections,
    type PersonalAIConnectionsProps,
} from './personal-ai-connections';

const baseProps: PersonalAIConnectionsProps = {
    connections: [],
    provider: 'openai',
    label: '',
    apiKey: '',
    model: '',
    connectionError: null,
    isSavingConnection: false,
    revokingConnectionId: null,
    chatGPTLogin: null,
    chatGPTError: null,
    isStartingChatGPTLogin: false,
    onProviderChange: vi.fn(),
    onLabelChange: vi.fn(),
    onApiKeyChange: vi.fn(),
    onModelChange: vi.fn(),
    onSaveConnection: vi.fn(),
    onRevokeConnection: vi.fn(),
    onStartChatGPTLogin: vi.fn(),
    onCancelChatGPTLogin: vi.fn(),
};

function render(overrides: Partial<PersonalAIConnectionsProps> = {}) {
    return renderToStaticMarkup(
        <PersonalAIConnections {...baseProps} {...overrides} />,
    );
}

describe('PersonalAIConnections', () => {
    it('renders labeled shadcn controls and separates supported methods', () => {
        const html = render();

        expect(html).toContain('Personal AI connections');
        expect(html).toContain('OpenAI subscription sign-in');
        expect(html).toContain('OpenAI only');
        expect(html).toContain('API key connection');
        expect(html).toContain('for="ai-provider"');
        expect(html).toContain('id="ai-provider"');
        expect(html).toContain('for="ai-connection-label"');
        expect(html).toContain('id="ai-connection-label"');
        expect(html).toContain('for="ai-api-key"');
        expect(html).toContain('id="ai-api-key"');
        expect(html).toContain('for="ai-model"');
        expect(html).toContain('id="ai-model"');
        expect(html).toContain('type="password"');
        expect(html).toContain('Connect with ChatGPT');
        expect(html).not.toContain('Connect with Claude');
    });

    it('uses provider configuration for Claude API key guidance', () => {
        const html = render({ provider: 'claude' });

        expect(html).toContain('Claude API key');
        expect(html).toContain('Claude model');
        expect(html).toContain('claude-3-5-sonnet-latest');
        expect(html).not.toContain('Claude subscription sign-in');
        expect(html).not.toContain('Connect with Claude');
    });

    it('announces safe errors and pending states', () => {
        const html = render({
            connectionError: 'The API key could not be saved.',
            chatGPTError: 'The local companion is unavailable.',
            isSavingConnection: true,
            isStartingChatGPTLogin: true,
        });

        expect(html).toContain('role="alert"');
        expect(html).toContain('The API key could not be saved.');
        expect(html).toContain('The local companion is unavailable.');
        expect(html).toContain('Saving…');
        expect(html).toContain('Connecting…');
        expect(html).toContain('disabled=""');
    });

    it('renders connection status without credential material', () => {
        const html = render({
            connections: [
                {
                    id: 'connection-1',
                    provider: 'openai',
                    label: 'Personal OpenAI',
                    authMode: 'api_key',
                    status: 'active',
                    model: 'gpt-4o-mini',
                    createdAt: '2026-10-26T00:00:00.000Z',
                },
            ],
            apiKey: '',
            revokingConnectionId: 'connection-1',
        });

        expect(html).toContain('Personal OpenAI');
        expect(html).toContain('Active');
        expect(html).toContain('gpt-4o-mini');
        expect(html).toContain('Revoking…');
        expect(html).not.toContain('sk-test-secret-value');
    });

    it('renders the current ChatGPT sign-in status as a live region', () => {
        const html = render({
            chatGPTLogin: {
                sessionId: 'session-1',
                status: 'pending',
                verificationUrl: 'https://example.test/device',
                userCode: 'ABCD-EFGH',
                accountLabel: null,
                message: 'Waiting for authorization.',
                expiresAt: '2026-10-26T00:10:00.000Z',
            },
        });

        expect(html).toContain('role="status"');
        expect(html).toContain('aria-live="polite"');
        expect(html).toContain('Waiting for authorization.');
        expect(html).toContain('Cancel sign-in');
    });
});
