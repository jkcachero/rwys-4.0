import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createElement } from 'react';
import { aiRequests } from './ai-connections';
import {
    chatSessionRequests,
    conversationSelection,
    conversationUrl,
    createRequestGate,
    mergeMessages,
    reconcileConversationDetail,
    requestOutcome,
    selectModelConfiguration,
    thinkingLabel,
    useChatSessions,
} from './use-chat-sessions';
import type { ChatMessage } from './use-chat-sessions';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const conversation = {
    id: 'thread-1',
    title: 'My question',
    provider: 'openai' as const,
    authMode: 'oauth',
    connectionId: null,
    model: 'model-1',
    thinkingLevel: null,
    updatedAt: '2026-10-22T12:00:00Z',
};
const model = {
    slug: 'model-1',
    displayName: 'Model One',
    thinkingLevels: ['low', 'high', 'xhigh'],
};

describe('chat session transport and state', () => {
    const choice = {
        value: 'oauth:openai',
        label: 'Subscription',
        provider: 'openai' as const,
        model: 'model-1',
        thinkingLevel: null,
    };
    function harness(messages: ChatMessage[] = []) {
        const changed = vi.fn();
        let sessions!: ReturnType<typeof useChatSessions>;
        function Harness() {
            sessions = useChatSessions(
                { ...conversation, messages, hasMore: false },
                { data: [], current_page: 1, last_page: 1 },
                changed,
            );
            return null;
        }
        renderToStaticMarkup(createElement(Harness));
        vi.spyOn(chatSessionRequests, 'list').mockResolvedValue({
            data: [],
            current_page: 1,
            last_page: 1,
        });
        return { sessions, changed };
    }
    function pair(requestId: string, firstId = 1): ChatMessage[] {
        return [
            {
                id: firstId,
                role: 'user',
                content: 'Question',
                status: 'completed',
                requestId,
            },
            {
                id: firstId + 1,
                role: 'assistant',
                content: 'Answer',
                status: 'completed',
                requestId,
            },
        ];
    }
    it('recovers a lost POST response from the exact completed saved pair without another model request', async () => {
        const { sessions, changed } = harness();
        let requestId = '';
        const prompt = vi
            .spyOn(aiRequests, 'prompt')
            .mockImplementation(async (_, selection) => {
                requestId = selection.requestId!;
                throw new Error('Response lost');
            });
        vi.spyOn(chatSessionRequests, 'show').mockImplementation(async () => ({
            conversation,
            messages: pair(requestId),
            hasMore: false,
        }));
        await expect(sessions.send('Question', choice)).resolves.toBe(true);
        expect(prompt).toHaveBeenCalledTimes(1);
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({ messages: pair(requestId) }),
        );
    });
    it('reuses an unresolved UUID on explicit retry, not a same-content saved exchange', async () => {
        const { sessions } = harness();
        const prompt = vi
            .spyOn(aiRequests, 'prompt')
            .mockRejectedValue(new Error('Response lost'));
        vi.spyOn(chatSessionRequests, 'show').mockResolvedValue({
            conversation,
            messages: pair('another-request'),
            hasMore: false,
        });
        await expect(sessions.send('Question', choice)).resolves.toBe(false);
        expect(prompt).toHaveBeenCalledTimes(1);
        await expect(sessions.send('Question', choice)).resolves.toBe(false);
        expect(prompt).toHaveBeenCalledTimes(2);
        expect(prompt.mock.calls[1][1].requestId).toBe(
            prompt.mock.calls[0][1].requestId,
        );
    });
    it('retains unresolved UUIDs across selection changes and a failed GET', async () => {
        const { sessions } = harness();
        const prompt = vi
            .spyOn(aiRequests, 'prompt')
            .mockRejectedValue(new Error('Response lost'));
        vi.spyOn(chatSessionRequests, 'show').mockRejectedValue(
            new Error('Offline'),
        );
        await sessions.send('Question', choice);
        await sessions.send('Question', { ...choice, model: 'other-model' });
        await sessions.send('Question', { ...choice, thinkingLevel: 'high' });
        await sessions.send('Question', {
            ...choice,
            value: 'api:7',
            connectionId: 7,
        });
        await sessions.send('Other question', choice);
        await sessions.send('Question', choice);
        const ids = prompt.mock.calls.map((call) => call[1].requestId);
        expect(new Set(ids.slice(0, 5)).size).toBe(5);
        expect(ids[5]).toBe(ids[0]);
    });
    it('allows a new explicit attempt only after the exact saved attempt is known failed', async () => {
        const { sessions } = harness();
        const prompt = vi
            .spyOn(aiRequests, 'prompt')
            .mockRejectedValue(new Error('Failed'));
        vi.spyOn(chatSessionRequests, 'show').mockImplementation(async () => ({
            conversation,
            messages: [
                {
                    ...pair(prompt.mock.calls.at(-1)![1].requestId!)[0],
                    status: 'failed',
                },
            ],
            hasMore: false,
        }));
        await sessions.send('Question', choice);
        await sessions.send('Question', choice);
        expect(prompt.mock.calls[1][1].requestId).not.toBe(
            prompt.mock.calls[0][1].requestId,
        );
    });
    it.each(['pending', 'missing-user'])(
        'does not treat %s as a recovered success',
        async (incomplete) => {
            const { sessions } = harness();
            const prompt = vi
                .spyOn(aiRequests, 'prompt')
                .mockRejectedValue(new Error('Response lost'));
            vi.spyOn(chatSessionRequests, 'show').mockImplementation(
                async () => {
                    const messages = pair(
                        prompt.mock.calls.at(-1)![1].requestId!,
                    );
                    return {
                        conversation,
                        hasMore: false,
                        messages:
                            incomplete === 'pending'
                                ? [{ ...messages[0], status: 'pending' }]
                                : [messages[1]],
                    };
                },
            );
            await expect(sessions.send('Question', choice)).resolves.toBe(
                false,
            );
            await sessions.send('Question', choice);
            expect(prompt.mock.calls[1][1].requestId).toBe(
                prompt.mock.calls[0][1].requestId,
            );
        },
    );
    it('replaces fallback messages with saved copies on a later synchronization', async () => {
        const { sessions, changed } = harness();
        const prompt = vi.spyOn(aiRequests, 'prompt').mockResolvedValue({
            provider: 'openai',
            model: 'model-1',
            text: 'Answer',
            conversationId: conversation.id,
        });
        const show = vi
            .spyOn(chatSessionRequests, 'show')
            .mockRejectedValueOnce(new Error('Offline'));
        await expect(sessions.send('Question', choice)).resolves.toBe(true);
        const firstId = prompt.mock.calls[0][1].requestId!;
        show.mockImplementation(async () => ({
            conversation,
            hasMore: false,
            messages: [
                ...pair(firstId),
                ...pair(prompt.mock.calls[1][1].requestId!, 3),
            ],
        }));
        await sessions.send('Next question', choice);
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({
                messages: [
                    ...pair(firstId),
                    ...pair(prompt.mock.calls[1][1].requestId!, 3),
                ],
            }),
        );
    });
    it('merges by role and UUID, preferring saved messages regardless of merge direction', () => {
        const saved = pair('request');
        const temporary = saved.map((message) => ({
            ...message,
            id: `temp:${message.role}`,
            persisted: false,
        }));
        expect(mergeMessages(temporary, saved)).toEqual(saved);
        expect(mergeMessages(saved, temporary)).toEqual(saved);
        expect(mergeMessages(pair('other', 3), saved)).toHaveLength(4);
        expect(
            mergeMessages(
                [{ ...temporary[0], id: 'another', requestId: 'other' }],
                saved,
            ),
        ).toHaveLength(3);
    });
    it('replaces a disjoint persisted window and adopts its reloadable cursor', () => {
        const current = {
            ...conversation,
            messages: pair('old'),
            hasMore: false,
            nextBeforeId: null,
        };
        const detail = {
            conversation,
            messages: pair('new', 101),
            hasMore: true,
            nextBeforeId: 101,
        };
        expect(reconcileConversationDetail(current, detail)).toEqual(detail);
    });
    it('preserves contiguous loaded history and its cursor when fresh messages overlap', () => {
        const old = pair('old');
        const overlap = pair('overlap', 3);
        const current = {
            ...conversation,
            messages: [...old, ...overlap],
            hasMore: true,
            nextBeforeId: 1,
        };
        const detail = {
            conversation,
            messages: [...overlap, ...pair('new', 5)],
            hasMore: true,
            nextBeforeId: 3,
        };
        expect(reconcileConversationDetail(current, detail)).toEqual({
            ...detail,
            messages: [...old, ...detail.messages],
            nextBeforeId: 1,
        });
    });
    it('only confirms an exact persisted completed pair, not provisional messages or matching content', () => {
        const messages = pair('request');
        expect(requestOutcome(messages, 'request')).toBe('completed');
        expect(requestOutcome(messages, 'other')).toBe('unknown');
        expect(requestOutcome([messages[0]], 'request')).toBe('unknown');
        expect(
            requestOutcome(
                messages.map((message) => ({
                    ...message,
                    id: `temp:${message.role}`,
                })),
                'request',
            ),
        ).toBe('unknown');
        expect(
            requestOutcome([{ ...messages[0], status: 'failed' }], 'request'),
        ).toBe('failed');
    });
    it('keeps an unresolved UUID per thread and resolves a saved completion on reopening without a paid retry', async () => {
        const { sessions } = harness();
        const prompt = vi
            .spyOn(aiRequests, 'prompt')
            .mockRejectedValue(new Error('Response lost'));
        const show = vi.spyOn(chatSessionRequests, 'show').mockResolvedValue({
            conversation,
            messages: [],
            hasMore: false,
        });
        await sessions.send('Question', choice);
        const firstRequestId = prompt.mock.calls[0][1].requestId!;
        show.mockResolvedValue({
            conversation: { ...conversation, id: 'thread-2' },
            messages: [],
            hasMore: false,
        });
        await sessions.open('thread-2');
        await sessions.send('Question', choice);
        expect(prompt.mock.calls[1][1].requestId).not.toBe(firstRequestId);
        show.mockResolvedValue({
            conversation,
            messages: pair(firstRequestId),
            hasMore: false,
        });
        await sessions.open(conversation.id);
        await expect(sessions.send('Question', choice)).resolves.toBe(true);
        expect(prompt).toHaveBeenCalledTimes(2);
    });
    it('adopts a disjoint fresh cursor after sending and can reload the intervening messages', async () => {
        const { sessions, changed } = harness(pair('old'));
        const prompt = vi.spyOn(aiRequests, 'prompt').mockResolvedValue({
            provider: 'openai',
            model: 'model-1',
            text: 'Answer',
            conversationId: conversation.id,
        });
        const show = vi
            .spyOn(chatSessionRequests, 'show')
            .mockImplementation(async (_, beforeId) => ({
                conversation,
                messages: beforeId
                    ? pair('middle', 51)
                    : pair(prompt.mock.calls[0][1].requestId!, 101),
                hasMore: true,
                nextBeforeId: beforeId ? 51 : 101,
            }));
        await sessions.send('Question', choice);
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({
                messages: pair(prompt.mock.calls[0][1].requestId!, 101),
                nextBeforeId: 101,
                hasMore: true,
            }),
        );
        await sessions.loadOlder();
        expect(show).toHaveBeenLastCalledWith(
            conversation.id,
            101,
            expect.any(AbortSignal),
        );
        await sessions.loadOlder();
        expect(show).toHaveBeenLastCalledWith(
            conversation.id,
            51,
            expect.any(AbortSignal),
        );
    });
    it('rejects a late model response for a previously selected connection', async () => {
        const responses: ((response: Response) => void)[] = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise<Response>((resolve) => {
                        responses.push(resolve);
                    }),
            ),
        );
        const gate = createRequestGate();
        const old = chatSessionRequests.models(
            {
                value: 'api:1',
                label: 'Old',
                provider: 'openai',
                connectionId: 1,
            },
            false,
            gate.start(),
        );
        const latest = chatSessionRequests.models(
            {
                value: 'api:2',
                label: 'New',
                provider: 'openai',
                connectionId: 2,
            },
            false,
            gate.start(),
        );
        responses[0](
            Response.json({
                models: [{ ...model, slug: 'old-model' }],
                cacheSeconds: 60,
            }),
        );
        responses[1](Response.json({ models: [model], cacheSeconds: 60 }));
        await expect(old).rejects.toThrow();
        await expect(latest).resolves.toEqual({
            models: [model],
            cacheSeconds: 60,
        });
    });
    it('only deletes the requested conversation and clears its selection when it was active', async () => {
        const remove = vi
            .spyOn(chatSessionRequests, 'remove')
            .mockResolvedValue({ status: 'deleted' });
        vi.spyOn(chatSessionRequests, 'list').mockResolvedValue({
            data: [],
            current_page: 1,
            last_page: 1,
        });
        const changed = vi.fn();
        let sessions!: ReturnType<typeof useChatSessions>;
        function Harness() {
            sessions = useChatSessions(
                { ...conversation, messages: [] },
                undefined,
                changed,
            );
            return null;
        }
        renderToStaticMarkup(createElement(Harness));
        await sessions.remove('another-thread');
        expect(changed).not.toHaveBeenCalled();
        await sessions.remove(conversation.id);
        expect(remove).toHaveBeenNthCalledWith(
            1,
            'another-thread',
            expect.any(AbortSignal),
        );
        expect(remove).toHaveBeenNthCalledWith(
            2,
            conversation.id,
            expect.any(AbortSignal),
        );
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({ id: null, messages: [] }),
        );
    });
    it('keeps a failed attempt visible when validation rejects it before persistence', async () => {
        vi.spyOn(aiRequests, 'prompt').mockRejectedValue(
            new Error('The selected model is no longer available.'),
        );
        vi.spyOn(chatSessionRequests, 'show').mockResolvedValue({
            conversation,
            messages: [],
            hasMore: false,
            nextBeforeId: null,
        });
        vi.spyOn(chatSessionRequests, 'list').mockResolvedValue({
            data: [conversation],
            current_page: 1,
            last_page: 1,
        });
        const changed = vi.fn();
        let sessions!: ReturnType<typeof useChatSessions>;
        function Harness() {
            sessions = useChatSessions(
                { ...conversation, messages: [] },
                undefined,
                changed,
            );
            return null;
        }
        renderToStaticMarkup(createElement(Harness));
        await expect(
            sessions.send('Please answer', {
                value: 'oauth:openai',
                label: 'Subscription',
                provider: 'openai',
                model: 'removed-model',
            }),
        ).resolves.toBe(false);
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({
                messages: [
                    expect.objectContaining({
                        role: 'user',
                        content: 'Please answer',
                        status: 'failed',
                        persisted: false,
                    }),
                ],
            }),
        );
        expect(
            changed.mock.calls
                .at(-1)?.[0]
                .messages.some(
                    (message: { role: string }) => message.role === 'assistant',
                ),
        ).toBe(false);
    });
    it('keeps older loaded messages and their pagination cursor when a fresh reply is synchronized', async () => {
        const prompt = vi.spyOn(aiRequests, 'prompt').mockResolvedValue({
            provider: 'openai',
            model: 'model-1',
            text: 'Latest answer',
            conversationId: conversation.id,
        });
        vi.spyOn(chatSessionRequests, 'show').mockImplementation(async () => ({
            conversation,
            messages: [
                {
                    id: 1,
                    role: 'user',
                    content: 'Earlier question',
                    status: 'completed',
                },
                {
                    id: 21,
                    requestId: prompt.mock.calls[0][1].requestId,
                    role: 'user',
                    content: 'Question',
                    status: 'completed',
                },
                {
                    id: 22,
                    requestId: prompt.mock.calls[0][1].requestId,
                    role: 'assistant',
                    content: 'Latest answer',
                    status: 'completed',
                },
            ],
            hasMore: true,
            nextBeforeId: 21,
        }));
        vi.spyOn(chatSessionRequests, 'list').mockResolvedValue({
            data: [conversation],
            current_page: 1,
            last_page: 1,
        });
        const changed = vi.fn();
        const oldest = {
            id: 1,
            role: 'user',
            content: 'Earlier question',
            status: 'completed',
        };
        let sessions!: ReturnType<typeof useChatSessions>;
        function Harness() {
            sessions = useChatSessions(
                {
                    ...conversation,
                    messages: [oldest],
                    hasMore: false,
                    nextBeforeId: null,
                },
                undefined,
                changed,
            );
            return null;
        }
        renderToStaticMarkup(createElement(Harness));
        await sessions.send('Question', {
            value: 'oauth:openai',
            label: 'Subscription',
            provider: 'openai',
            model: 'model-1',
            thinkingLevel: null,
        });
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({
                hasMore: false,
                messages: [
                    oldest,
                    expect.objectContaining({ id: 21 }),
                    expect.objectContaining({ id: 22 }),
                ],
            }),
        );
    });
    it('queries only the selected owner connection, with explicit refresh and bounded history pages', async () => {
        const fetch = vi
            .fn()
            .mockImplementation(() => Promise.resolve(Response.json({})));
        vi.stubGlobal('fetch', fetch);
        await chatSessionRequests.models(
            {
                provider: 'claude',
                value: 'api:7',
                label: 'Work',
                connectionId: 7,
            },
            true,
        );
        await chatSessionRequests.list(2);
        await chatSessionRequests.create();
        await chatSessionRequests.show('thread/1', 42);
        await chatSessionRequests.remove('thread/1');
        expect(
            fetch.mock.calls.map(([url, options]) => [url, options.method]),
        ).toEqual([
            ['/chat/models?provider=claude&connectionId=7&refresh=1', 'GET'],
            ['/chat/conversations?page=2', 'GET'],
            ['/chat/conversations', 'POST'],
            ['/chat/conversations/thread%2F1?beforeId=42', 'GET'],
            ['/chat/conversations/thread%2F1', 'DELETE'],
        ]);
        expect(conversationUrl('thread/1')).toBe(
            '/chat?conversation=thread%2F1',
        );
    });
    it('surfaces owner authorization errors rather than treating another user history as empty', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    Response.json(
                        { message: 'Conversation not found.' },
                        { status: 404 },
                    ),
                ),
        );
        await expect(chatSessionRequests.show('not-owned')).rejects.toThrow(
            'Conversation not found.',
        );
    });
    it('aborts old model/history requests and rejects stale completions', () => {
        const gate = createRequestGate();
        const old = gate.start();
        const latest = gate.start();
        expect(old.aborted).toBe(true);
        expect(gate.isCurrent(old)).toBe(false);
        expect(gate.isCurrent(latest)).toBe(true);
        gate.cancel();
        expect(latest.aborted).toBe(true);
        expect(gate.isCurrent(latest)).toBe(false);
    });
    it('only restores catalogued models and verified thinking levels', () => {
        expect(selectModelConfiguration([model], 'model-1', 'high')).toEqual({
            model,
            thinkingLevel: 'high',
        });
        expect(
            selectModelConfiguration([model], 'removed-model', 'high'),
        ).toEqual({ model: null, thinkingLevel: null });
        expect(selectModelConfiguration([model], null, 'unverified')).toEqual({
            model,
            thinkingLevel: null,
        });
        expect(selectModelConfiguration([], null, null)).toEqual({
            model: null,
            thinkingLevel: null,
        });
        expect(thinkingLabel('xhigh')).toBe('Extra high');
        expect(thinkingLabel('max')).toBe('Maximum');
        expect(thinkingLabel('none')).toBe('None');
    });
    it('prepends older messages without duplicate IDs or stale status overwrites', () => {
        const old = [
            { id: 1, role: 'user', content: 'First', status: 'completed' },
            { id: 2, role: 'assistant', content: 'Reply', status: 'pending' },
        ];
        const current = [
            { id: 2, role: 'assistant', content: 'Reply', status: 'completed' },
            { id: 3, role: 'user', content: 'Next', status: 'failed' },
        ];
        expect(mergeMessages(old, current)).toEqual([old[0], ...current]);
        expect(conversationSelection(conversation)).toBe('oauth:openai');
        expect(
            conversationSelection({
                ...conversation,
                provider: 'claude',
                authMode: 'api_key',
                connectionId: 7,
            }),
        ).toBe('api:7');
    });
    it('creates the conversation before inference and supplies explicit model, nullable thinking, history ID and request UUID', async () => {
        const create = vi
            .spyOn(chatSessionRequests, 'create')
            .mockResolvedValue({ conversation });
        const prompt = vi.spyOn(aiRequests, 'prompt').mockResolvedValue({
            provider: 'openai',
            model: 'model-1',
            text: 'Answer',
            conversationId: conversation.id,
        });
        vi.spyOn(chatSessionRequests, 'show').mockResolvedValue({
            conversation,
            messages: [
                {
                    id: 1,
                    role: 'assistant',
                    content: 'Answer',
                    status: 'completed',
                },
            ],
            hasMore: false,
            nextBeforeId: null,
        });
        vi.spyOn(chatSessionRequests, 'list').mockResolvedValue({
            data: [conversation],
            current_page: 1,
            last_page: 1,
        });
        const onThreadChange = vi.fn();
        let sessions!: ReturnType<typeof useChatSessions>;
        function Harness() {
            sessions = useChatSessions(
                { messages: [] },
                undefined,
                onThreadChange,
            );
            return null;
        }
        renderToStaticMarkup(createElement(Harness));
        await expect(
            sessions.send('Question', {
                value: 'oauth:openai',
                label: 'Subscription',
                provider: 'openai',
                model: 'model-1',
                thinkingLevel: null,
            }),
        ).resolves.toBe(true);
        expect(create.mock.invocationCallOrder[0]).toBeLessThan(
            prompt.mock.invocationCallOrder[0],
        );
        expect(prompt).toHaveBeenCalledWith(
            'Question',
            expect.objectContaining({
                model: 'model-1',
                thinkingLevel: null,
                conversationId: 'thread-1',
                requestId: expect.stringMatching(/^[0-9a-f-]{36}$/),
            }),
            expect.any(AbortSignal),
        );
        expect(onThreadChange).toHaveBeenCalled();
    });
    it('blocks replacing or deleting a thread during an in-flight prompt', async () => {
        let finish!: (value: {
            provider: string;
            model: string;
            text: string;
            conversationId: string;
        }) => void;
        vi.spyOn(aiRequests, 'prompt').mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const create = vi.spyOn(chatSessionRequests, 'create');
        const remove = vi.spyOn(chatSessionRequests, 'remove');
        vi.spyOn(chatSessionRequests, 'show').mockResolvedValue({
            conversation,
            messages: [],
            hasMore: false,
            nextBeforeId: null,
        });
        vi.spyOn(chatSessionRequests, 'list').mockResolvedValue({
            data: [conversation],
            current_page: 1,
            last_page: 1,
        });
        let sessions!: ReturnType<typeof useChatSessions>;
        function Harness() {
            sessions = useChatSessions({ ...conversation, messages: [] });
            return null;
        }
        renderToStaticMarkup(createElement(Harness));
        const pending = sessions.send('Question', {
            value: 'oauth:openai',
            label: 'Subscription',
            provider: 'openai',
            model: 'model-1',
            thinkingLevel: 'high',
        });
        await expect(sessions.newChat()).resolves.toBe(false);
        await expect(sessions.open('other')).resolves.toBe(false);
        await expect(sessions.remove('thread-1')).resolves.toBe(false);
        expect(create).not.toHaveBeenCalled();
        expect(remove).not.toHaveBeenCalled();
        finish({
            provider: 'openai',
            model: 'model-1',
            text: 'Answer',
            conversationId: 'thread-1',
        });
        await pending;
    });
});
