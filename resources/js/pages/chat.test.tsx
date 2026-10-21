import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@radix-ui/react-dialog', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@radix-ui/react-dialog')>()),
    Portal: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/routes', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/routes')>()),
    chat: Object.assign(() => ({ url: '/chat', method: 'get' as const }), {
        url: () => '/chat',
    }),
}));

import { DeleteConversationDialog } from '@/components/chat-history';
import Chat, { shouldSendOnEnter } from './chat';
import * as aiHooks from '@/hooks/ai-connections';
import * as sessionHooks from '@/hooks/use-chat-sessions';

const workspace: ComponentProps<typeof Chat>['workspace'] = {
    status: 'ready',
    greeting: 'Welcome back, Ada.',
    correlationId: 'request-123',
    conversation: { status: 'empty', messages: [] },
    sources: [],
    connections: [],
    provider: {
        status: 'deferred',
        message: 'Provider reads are deferred until approved.',
    },
    capabilities: [{ name: 'AdKit read access', status: 'deferred' }],
};

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Chat workspace', () => {
    it.each([
        ['Enter', false, false, true],
        ['Enter', true, false, false],
        ['Enter', false, true, false],
        ['a', false, false, false],
    ] as const)(
        'handles composer key %s with shift=%s and composition=%s',
        (key, shift, composing, send) => {
            expect(shouldSendOnEnter(key, shift, composing)).toBe(send);
        },
    );
    it('requires an accessible confirmation for history deletion, not connection deletion', () => {
        const html = renderToStaticMarkup(
            <DeleteConversationDialog
                conversation={{
                    id: 'thread-1',
                    title: 'My saved chat',
                    provider: 'openai',
                    authMode: 'oauth',
                    connectionId: null,
                    model: 'model-1',
                    thinkingLevel: null,
                }}
                pending={false}
                onClose={() => {}}
                onConfirm={() => {}}
            />,
        );
        expect(html).toContain('role="dialog"');
        expect(html).toContain('Delete conversation?');
        expect(html).toContain('My saved chat');
        expect(html).toContain('Your AI connections will not be changed.');
        expect(html).toContain('Cancel');
        expect(html).toContain('Delete conversation');
    });
    it('disables sending for catalog errors and exposes the model error accessibly', () => {
        vi.spyOn(sessionHooks, 'useChatModels').mockReturnValue({
            key: 'scope',
            models: [],
            model: '',
            thinkingLevel: null,
            loading: false,
            error: 'This model catalog is unavailable.',
            selectedModel: null,
            refresh: vi.fn(),
            selectModel: vi.fn(),
            selectThinking: vi.fn(),
        });
        const html = renderToStaticMarkup(<Chat workspace={workspace} />);
        expect(html).toContain('role="alert"');
        expect(html).toContain('This model catalog is unavailable.');
        expect(html).toMatch(/<button[^>]*type="submit"[^>]*disabled=""/);
    });
    it('displays a verified model and provider default without claiming unknown thinking support is unsupported', () => {
        const model = {
            slug: 'verified-model',
            displayName: 'Verified model',
            thinkingLevels: [],
        };
        vi.spyOn(sessionHooks, 'useChatModels').mockReturnValue({
            key: 'scope',
            models: [model],
            model: model.slug,
            thinkingLevel: null,
            loading: false,
            error: '',
            selectedModel: model,
            refresh: vi.fn(),
            selectModel: vi.fn(),
            selectThinking: vi.fn(),
        });
        const html = renderToStaticMarkup(<Chat workspace={workspace} />);
        expect(html).toContain('Verified model');
        expect(html).not.toContain('Thinking is unsupported');
        expect(html).toContain('aria-label="Model options: Verified model"');
        expect(html).not.toContain('role="combobox"');
    });
    it('keeps the question and connection picker disabled while waiting for a reply', () => {
        vi.spyOn(aiHooks, 'useAIChat').mockReturnValue({
            pending: true,
            error: '',
            send: vi.fn(),
        });
        const markup = renderToStaticMarkup(<Chat workspace={workspace} />);
        expect(markup).toMatch(
            /<textarea[^>]*id="chat-question"[^>]*disabled=""/,
        );
        expect(markup).toMatch(
            /<button[^>]*disabled=""[^>]*aria-label="Model options: Choose model"/,
        );
        expect(markup).toContain('aria-busy="true"');
        expect(markup).toContain('Waiting for the model response.');
        expect(markup).toContain('Sending...');
    });
    it('keeps history left, centers the reading lane in the remaining width and removes topbar controls', () => {
        const markup = renderToStaticMarkup(<Chat workspace={workspace} />);
        expect(markup).toContain('Welcome back, Ada.');
        expect(markup).toContain('AI connections');
        expect(markup).toContain('No messages yet');
        expect(markup).toContain('Choose model');
        expect(markup).not.toContain('role="combobox"');
        expect(markup).not.toContain('max-w-5xl');
        expect(markup).not.toMatch(/class="mx-auto flex h-/);
        expect(markup).toContain('mx-auto flex min-h-0 w-full max-w-4xl');
        expect(markup).toContain('hidden w-64 shrink-0');
        expect(markup).toContain('xl:w-72');
        const header = markup.match(/<header[^>]*>.*?<\/header>/s)?.[0] ?? '';
        expect(header).not.toContain('AI connections');
        expect(header).not.toContain('Model options');
        expect(header).not.toContain('Refresh models');
        expect(markup).not.toContain('type="password"');
        expect(markup).not.toContain('Save connection');
        expect(markup).toContain('href="/settings/connections"');
        expect(markup).not.toContain('role="dialog"');
        expect(markup).toContain(
            'AdKit and Zernio tools are not yet connected',
        );
        expect(markup).not.toContain('request-123');
        expect(markup).toContain('Set up a connection in Settings');
        expect(markup).not.toContain('scopeId');
        expect(markup).not.toContain('127.0.0.1');
    });

    it('has a keyboard-accessible scroll log and a sticky, labelled compact composer', () => {
        const markup = renderToStaticMarkup(<Chat workspace={workspace} />);
        expect(markup).toMatch(/role="log"[^>]*tabindex="0"/);
        expect(markup).toContain('aria-labelledby="conversation-title"');
        expect(markup).toContain('overflow-y-auto');
        expect(markup).toContain('sticky bottom-0');
        expect(markup).toContain('rounded-2xl border bg-card');
        expect(markup).toContain('data-slot="textarea"');
        expect(markup).toMatch(
            /<label[^>]*class="[^"]*sr-only[^"]*"[^>]*>Question<\/label>/,
        );
        expect(markup).toContain('Send');
        expect(markup).toMatch(/<button[^>]*type="submit"[^>]*disabled=""/);
    });

    it('distinguishes right-aligned user bubbles from readable assistant replies', () => {
        const markup = renderToStaticMarkup(
            <Chat
                workspace={{
                    ...workspace,
                    conversation: {
                        status: 'active',
                        messages: [
                            {
                                role: 'user',
                                content: 'Explain this result.',
                                sources: [],
                            },
                            {
                                role: 'assistant',
                                content: 'Here is the actual model response.',
                                sources: [],
                            },
                        ],
                    },
                }}
            />,
        );
        expect(markup).toContain('aria-label="Your message"');
        expect(markup).toContain('aria-label="Assistant message"');
        expect(markup).toContain('justify-end');
        expect(markup).toContain('bg-muted');
        expect(markup).toContain('max-w-3xl');
        expect(markup).toContain('Explain this result.');
        expect(markup).toContain('Here is the actual model response.');
        expect(markup).not.toContain('No messages yet');
    });

    it('requires a choice even when an owned API key and OAuth are available', () => {
        const markup = renderToStaticMarkup(
            <Chat
                workspace={{
                    ...workspace,
                    connections: [
                        {
                            id: 4,
                            provider: 'claude',
                            label: 'Personal',
                            authMode: 'api_key',
                            model: null,
                            status: 'configured',
                            lastValidatedAt: null,
                        },
                    ],
                    providers: [
                        {
                            name: 'openai',
                            label: 'OpenAI',
                            model: '',
                            connected: true,
                        },
                    ],
                }}
            />,
        );
        expect(markup).toContain('Choose model');
        expect(markup).toMatch(/<button[^>]*type="submit"[^>]*disabled=""/);
        expect(markup).not.toContain('type="password"');
    });
    it('keeps a compact composer model menu and private history without extra topbar controls', () => {
        const html = renderToStaticMarkup(<Chat workspace={workspace} />);
        expect(html).toContain('Model');
        expect(html).toContain('aria-haspopup="menu"');
        expect(html).toContain('New chat');
        expect(html).toContain('Recent chats');
        expect(html).toContain('Private to you');
        expect(html).not.toContain('role="combobox"');
    });
    it('renders stored failed and pending attempts and paginated history controls', () => {
        const html = renderToStaticMarkup(
            <Chat
                workspace={{
                    ...workspace,
                    conversation: {
                        id: 'thread-1',
                        title: 'A stored question',
                        status: 'active',
                        provider: 'openai',
                        authMode: 'oauth',
                        connectionId: null,
                        model: 'model-1',
                        thinkingLevel: 'high',
                        hasMore: true,
                        nextBeforeId: 10,
                        messages: [
                            {
                                id: 11,
                                role: 'user',
                                content: 'Failed attempt',
                                status: 'failed',
                            },
                            {
                                id: 12,
                                role: 'user',
                                content: 'In progress',
                                status: 'pending',
                            },
                        ],
                    },
                    conversations: {
                        data: [
                            {
                                id: 'thread-1',
                                title: 'A stored question',
                                provider: 'openai',
                                authMode: 'oauth',
                                connectionId: null,
                                model: 'model-1',
                                thinkingLevel: 'high',
                                updatedAt: '2026-10-22T12:00:00Z',
                            },
                        ],
                        current_page: 1,
                        last_page: 2,
                    },
                }}
            />,
        );
        expect(html).toContain('A stored question');
        expect(html).toContain('Failed');
        expect(html).toContain('Pending');
        expect(html).toContain('Load older messages');
        expect(html).toContain('Load more chats');
        expect(html).toContain('Delete conversation');
    });
});
