import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { jsonRequest, useAIChat } from '@/hooks/ai-connections';
import type { ChatChoice } from '@/hooks/ai-connections';
import { chat as chatRoute } from '@/routes';
import { models as modelCatalogRoute } from '@/routes/chat';
import {
    destroy as destroyConversation,
    index as conversations,
    show as showConversation,
    store as storeConversation,
} from '@/routes/chat/conversations';

export type ChatModel = {
    slug: string;
    displayName: string;
    thinkingLevels: string[];
};
export type ChatMessage = {
    id?: number | string;
    role: string;
    content: string;
    status?: string;
    requestId?: string | null;
    persisted?: boolean;
    createdAt?: string;
    sources?: { provider: string; label: string; value: string }[];
};
export type Conversation = {
    id: string;
    title: string;
    provider: 'openai' | 'claude' | null;
    authMode: string | null;
    connectionId: number | null;
    model: string | null;
    thinkingLevel: string | null;
    updatedAt?: string | null;
};
export type InitialConversation = Partial<Omit<Conversation, 'id'>> & {
    id?: string | null;
    status?: string;
    messages: ChatMessage[];
    hasMore?: boolean;
    nextBeforeId?: number | null;
};
export type ConversationPage = {
    data: Conversation[];
    current_page: number;
    last_page: number;
};
export type ConversationDetail = {
    conversation: Conversation;
    messages: ChatMessage[];
    hasMore: boolean;
    nextBeforeId?: number | null;
};
type Thread = {
    conversation: InitialConversation;
    selection: string;
    configurationVersion: number;
};

export function conversationUrl(id?: string | null): string {
    return chatRoute.url(id ? { query: { conversation: id } } : undefined);
}

export const chatSessionRequests = {
    models: (choice: ChatChoice, refresh = false, signal?: AbortSignal) => {
        return jsonRequest<{ models: ChatModel[]; cacheSeconds: number }>(
            modelCatalogRoute.url({
                query: {
                    provider: choice.provider,
                    ...(choice.connectionId !== undefined
                        ? { connectionId: choice.connectionId }
                        : {}),
                    ...(refresh ? { refresh: 1 } : {}),
                },
            }),
            'GET',
            undefined,
            signal,
        );
    },
    list: (page = 1, signal?: AbortSignal) =>
        jsonRequest<ConversationPage>(
            conversations.url({ query: { page } }),
            'GET',
            undefined,
            signal,
        ),
    create: (signal?: AbortSignal) =>
        jsonRequest<{ conversation: Conversation }>(
            storeConversation.url(),
            'POST',
            {},
            signal,
        ),
    show: (id: string, beforeId?: number, signal?: AbortSignal) =>
        jsonRequest<ConversationDetail>(
            showConversation.url(
                encodeURIComponent(id),
                beforeId !== undefined ? { query: { beforeId } } : undefined,
            ),
            'GET',
            undefined,
            signal,
        ),
    remove: (id: string, signal?: AbortSignal) =>
        jsonRequest<{ status: 'deleted' }>(
            destroyConversation.url(encodeURIComponent(id)),
            'DELETE',
            undefined,
            signal,
        ),
};

export function createRequestGate() {
    let current: AbortController | null = null;
    return {
        start() {
            current?.abort();
            current = new AbortController();
            return current.signal;
        },
        isCurrent(signal: AbortSignal) {
            return current?.signal === signal && !signal.aborted;
        },
        cancel() {
            current?.abort();
            current = null;
        },
    };
}

export function selectModelConfiguration(
    models: ChatModel[],
    preferred?: string | null,
    thinking?: string | null,
) {
    const model = preferred
        ? (models.find((model) => model.slug === preferred) ?? null)
        : (models[0] ?? null);
    return {
        model,
        thinkingLevel:
            thinking && model?.thinkingLevels.includes(thinking)
                ? thinking
                : null,
    };
}

export function thinkingLabel(value: string): string {
    return (
        (
            {
                low: 'Low',
                medium: 'Medium',
                high: 'High',
                xhigh: 'Extra high',
                extra_high: 'Extra high',
                max: 'Maximum',
                maximum: 'Maximum',
                none: 'None',
            } as Record<string, string>
        )[value] ?? value
    );
}

export function conversationSelection(
    conversation: Partial<
        Pick<Conversation, 'provider' | 'authMode' | 'connectionId'>
    >,
): string {
    if (
        conversation.authMode === 'api_key' &&
        conversation.connectionId != null
    )
        return `api:${conversation.connectionId}`;
    return conversation.provider === 'openai' &&
        conversation.authMode === 'oauth'
        ? 'oauth:openai'
        : '';
}

export function mergeMessages(
    older: ChatMessage[],
    current: ChatMessage[],
): ChatMessage[] {
    const saved = new Map(
        older
            .filter(
                (message) =>
                    typeof message.id === 'number' && message.requestId,
            )
            .map((message) => [
                `${message.role}:${message.requestId}`,
                message,
            ]),
    );
    const latest = current.map((message) =>
        typeof message.id !== 'number' && message.requestId
            ? (saved.get(`${message.role}:${message.requestId}`) ?? message)
            : message,
    );
    const requests = new Set(
        latest
            .filter((message) => message.requestId)
            .map((message) => `${message.role}:${message.requestId}`),
    );
    const ids = new Set(
        latest
            .filter((message) => message.id !== undefined)
            .map((message) => message.id),
    );
    return [
        ...older.filter(
            (message) =>
                (message.id === undefined || !ids.has(message.id)) &&
                (!message.requestId ||
                    !requests.has(`${message.role}:${message.requestId}`)),
        ),
        ...latest,
    ];
}

export function reconcileConversationDetail(
    current: InitialConversation,
    detail: ConversationDetail,
): ConversationDetail {
    const freshIds = new Set(detail.messages.map((message) => message.id));
    const overlap = current.messages.some(
        (message) => typeof message.id === 'number' && freshIds.has(message.id),
    );
    return {
        ...detail,
        messages: mergeMessages(
            overlap
                ? current.messages
                : current.messages.filter(
                      (message) => typeof message.id !== 'number',
                  ),
            detail.messages,
        ),
        hasMore: overlap ? (current.hasMore ?? detail.hasMore) : detail.hasMore,
        nextBeforeId:
            overlap && current.hasMore !== undefined
                ? current.nextBeforeId
                : detail.nextBeforeId,
    };
}

export function requestOutcome(messages: ChatMessage[], requestId: string) {
    const saved = messages.filter(
        (message) =>
            typeof message.id === 'number' && message.requestId === requestId,
    );
    if (
        ['user', 'assistant'].every((role) =>
            saved.some(
                (message) =>
                    message.role === role && message.status === 'completed',
            ),
        )
    )
        return 'completed';
    return saved.some(
        (message) => message.role === 'user' && message.status === 'failed',
    )
        ? 'failed'
        : 'unknown';
}

export function useChatModels(
    choice: ChatChoice | undefined,
    scope: string | null | undefined,
    preferredModel?: string | null,
    preferredThinking?: string | null,
) {
    const key = `${scope ?? 'new'}:${choice?.provider ?? ''}:${choice?.value ?? ''}`;
    const gate = useRef(createRequestGate());
    const [state, setState] = useState<{
        key: string;
        models: ChatModel[];
        model: string;
        thinkingLevel: string | null;
        loading: boolean;
        error: string;
    }>({
        key: '',
        models: [],
        model: '',
        thinkingLevel: null,
        loading: false,
        error: '',
    });
    const current =
        state.key === key
            ? state
            : {
                  key,
                  models: [],
                  model: '',
                  thinkingLevel: null,
                  loading: Boolean(choice),
                  error: '',
              };
    async function load(refresh = false) {
        if (!choice) return;
        const signal = gate.current.start();
        setState((previous) => ({
            key,
            models: [],
            model:
                previous.key === key
                    ? previous.model
                    : (preferredModel ?? choice.model ?? ''),
            thinkingLevel:
                previous.key === key
                    ? previous.thinkingLevel
                    : (preferredThinking ?? null),
            loading: true,
            error: '',
        }));
        try {
            const result = await chatSessionRequests.models(
                choice,
                refresh,
                signal,
            );
            if (!gate.current.isCurrent(signal)) return;
            setState((previous) => {
                const selected = selectModelConfiguration(
                    result.models,
                    previous.model,
                    previous.thinkingLevel,
                );
                return {
                    key,
                    models: result.models,
                    model: selected.model?.slug ?? '',
                    thinkingLevel: selected.thinkingLevel,
                    loading: false,
                    error: '',
                };
            });
        } catch (error) {
            if (gate.current.isCurrent(signal))
                setState((previous) => ({
                    ...previous,
                    models: [],
                    loading: false,
                    error:
                        error instanceof Error
                            ? error.message
                            : 'Models could not be loaded.',
                }));
        }
    }
    const loadInitial = useEffectEvent(() => {
        if (choice) void load();
    });
    useEffect(() => {
        loadInitial();
        const requests = gate.current;
        return () => requests.cancel();
    }, [key]);
    const model =
        current.models.find((model) => model.slug === current.model) ?? null;
    return {
        ...current,
        selectedModel: model,
        refresh: () => load(true),
        selectModel: (slug: string) => {
            if (current.models.some((model) => model.slug === slug))
                setState((previous) => ({
                    ...previous,
                    model: slug,
                    thinkingLevel: null,
                }));
        },
        selectThinking: (level: string | null) => {
            if (level === null || model?.thinkingLevels.includes(level))
                setState((previous) => ({ ...previous, thinkingLevel: level }));
        },
    };
}

export function useChatSessions(
    initial: InitialConversation,
    initialHistory?: ConversationPage,
    onThreadChange?: (conversation: InitialConversation) => void,
) {
    const [thread, setThread] = useState<Thread>({
        conversation: initial,
        selection: conversationSelection(initial),
        configurationVersion: 0,
    });
    const current = useRef(thread);
    const [history, setHistory] = useState<ConversationPage>(
        initialHistory ?? { data: [], current_page: 1, last_page: 1 },
    );
    const [historyLoading, setHistoryLoading] = useState(!initialHistory);
    const [historyError, setHistoryError] = useState('');
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const [showPromptError, setShowPromptError] = useState(false);
    const busy = useRef(false);
    const gate = useRef(createRequestGate());
    const historyGate = useRef(createRequestGate());
    const unresolved = useRef(new Map<string, string>());
    const chat = useAIChat();

    function commit(
        conversation: InitialConversation,
        selection = conversationSelection(conversation),
        syncUrl = false,
        resetConfiguration = false,
    ) {
        const next = {
            conversation,
            selection,
            configurationVersion:
                current.current.configurationVersion +
                (resetConfiguration ? 1 : 0),
        };
        current.current = next;
        setThread(next);
        if (syncUrl) onThreadChange?.(conversation);
    }
    async function loadHistory(page = 1) {
        const signal = historyGate.current.start();
        setHistoryLoading(true);
        setHistoryError('');
        try {
            const data = await chatSessionRequests.list(page, signal);
            if (!historyGate.current.isCurrent(signal)) return;
            setHistory((previous) => ({
                ...data,
                data:
                    page === 1
                        ? data.data
                        : [
                              ...new Map(
                                  [...previous.data, ...data.data].map(
                                      (conversation) => [
                                          conversation.id,
                                          conversation,
                                      ],
                                  ),
                              ).values(),
                          ],
            }));
        } catch (error) {
            if (historyGate.current.isCurrent(signal))
                setHistoryError(
                    error instanceof Error
                        ? error.message
                        : 'History could not be loaded.',
                );
        } finally {
            if (historyGate.current.isCurrent(signal)) setHistoryLoading(false);
        }
    }
    const initialize = useEffectEvent(() => {
        if (!initialHistory) void loadHistory();
    });
    useEffect(() => {
        initialize();
        const requests = gate.current;
        const historyRequests = historyGate.current;
        return () => {
            requests.cancel();
            historyRequests.cancel();
        };
    }, []);

    async function run(action: (signal: AbortSignal) => Promise<void>) {
        if (busy.current || chat.pending) return false;
        busy.current = true;
        const signal = gate.current.start();
        setPending(true);
        setError('');
        setShowPromptError(false);
        try {
            await action(signal);
            return gate.current.isCurrent(signal);
        } catch (error) {
            if (gate.current.isCurrent(signal))
                setError(
                    error instanceof Error
                        ? error.message
                        : 'The conversation request failed.',
                );
            return false;
        } finally {
            if (gate.current.isCurrent(signal)) {
                busy.current = false;
                setPending(false);
            }
        }
    }
    function applyDetail(
        detail: ConversationDetail,
        syncUrl = false,
        resetConfiguration = false,
        selection?: string,
    ) {
        commit(
            {
                ...detail.conversation,
                messages: detail.messages,
                hasMore: detail.hasMore,
                nextBeforeId: detail.nextBeforeId,
            },
            selection,
            syncUrl,
            resetConfiguration,
        );
    }
    return {
        conversation: thread.conversation,
        selection: thread.selection,
        modelScope: `${thread.conversation.id ?? 'new'}:${thread.configurationVersion}`,
        history,
        historyLoading,
        historyError,
        pending: pending || chat.pending,
        sending: chat.pending,
        error: (showPromptError && chat.error) || error,
        selectConnection: (selection: string) => {
            if (!busy.current && !chat.pending) {
                const next = { ...current.current, selection };
                current.current = next;
                setThread(next);
            }
        },
        loadMore: () => loadHistory(history.current_page + 1),
        retryHistory: () => loadHistory(),
        newChat: () =>
            run(async (signal) => {
                const { conversation } =
                    await chatSessionRequests.create(signal);
                if (!gate.current.isCurrent(signal)) return;
                commit(
                    {
                        ...conversation,
                        messages: [],
                        hasMore: false,
                        nextBeforeId: null,
                    },
                    '',
                    true,
                );
                void loadHistory();
            }),
        open: (id: string) =>
            run(async (signal) => {
                const detail = await chatSessionRequests.show(
                    id,
                    undefined,
                    signal,
                );
                if (gate.current.isCurrent(signal))
                    applyDetail(detail, true, true);
            }),
        remove: (id: string) =>
            run(async (signal) => {
                await chatSessionRequests.remove(id, signal);
                if (!gate.current.isCurrent(signal)) return;
                if (current.current.conversation.id === id)
                    commit(
                        {
                            id: null,
                            messages: [],
                            hasMore: false,
                            nextBeforeId: null,
                        },
                        '',
                        true,
                    );
                setHistory((previous) => ({
                    ...previous,
                    data: previous.data.filter(
                        (conversation) => conversation.id !== id,
                    ),
                }));
                void loadHistory();
            }),
        loadOlder: () =>
            run(async (signal) => {
                const before = current.current.conversation;
                if (
                    !before.id ||
                    !before.hasMore ||
                    before.nextBeforeId == null
                )
                    return;
                const detail = await chatSessionRequests.show(
                    before.id,
                    before.nextBeforeId,
                    signal,
                );
                if (
                    !gate.current.isCurrent(signal) ||
                    current.current.conversation.id !== before.id
                )
                    return;
                commit(
                    {
                        ...before,
                        messages: mergeMessages(
                            detail.messages,
                            before.messages,
                        ),
                        hasMore: detail.hasMore,
                        nextBeforeId: detail.nextBeforeId,
                    },
                    current.current.selection,
                );
            }),
        send: (question: string, choice: ChatChoice) =>
            run(async (signal) => {
                if (!choice.model)
                    throw new Error(
                        'Choose an available model before sending.',
                    );
                let conversation = current.current.conversation;
                if (!conversation.id) {
                    const created = await chatSessionRequests.create(signal);
                    if (!gate.current.isCurrent(signal)) return;
                    conversation = {
                        ...created.conversation,
                        provider: choice.provider,
                        authMode:
                            choice.connectionId !== undefined
                                ? 'api_key'
                                : 'oauth',
                        connectionId: choice.connectionId ?? null,
                        model: choice.model,
                        thinkingLevel: choice.thinkingLevel ?? null,
                        messages: [],
                        hasMore: false,
                        nextBeforeId: null,
                    };
                    commit(conversation, choice.value, true);
                }
                const id = conversation.id!;
                const requestKey = JSON.stringify([
                    id,
                    question,
                    choice.provider,
                    choice.connectionId ?? null,
                    choice.model,
                    choice.thinkingLevel ?? null,
                ]);
                const previousRequestId = unresolved.current.get(requestKey);
                if (previousRequestId) {
                    const outcome = requestOutcome(
                        conversation.messages,
                        previousRequestId,
                    );
                    if (outcome !== 'unknown')
                        unresolved.current.delete(requestKey);
                    if (outcome === 'completed') return;
                }
                const requestId =
                    unresolved.current.get(requestKey) ?? crypto.randomUUID();
                unresolved.current.set(requestKey, requestId);
                const attempt: ChatMessage = {
                    id: `request:${requestId}`,
                    requestId,
                    role: 'user',
                    content: question,
                    status: 'pending',
                    persisted: false,
                    createdAt: new Date().toISOString(),
                };
                commit(
                    {
                        ...conversation,
                        messages: mergeMessages(conversation.messages, [
                            attempt,
                        ]),
                    },
                    choice.value,
                );
                setShowPromptError(true);
                const result = await chat.send(question, {
                    ...choice,
                    thinkingLevel: choice.thinkingLevel ?? null,
                    conversationId: id,
                    requestId,
                });
                if (
                    !gate.current.isCurrent(signal) ||
                    current.current.conversation.id !== id
                )
                    return;
                let completed = Boolean(result);
                try {
                    const detail = await chatSessionRequests.show(
                        id,
                        undefined,
                        signal,
                    );
                    if (
                        !gate.current.isCurrent(signal) ||
                        current.current.conversation.id !== id
                    )
                        return;
                    const outcome = requestOutcome(detail.messages, requestId);
                    completed ||= outcome === 'completed';
                    if (outcome === 'failed')
                        unresolved.current.delete(requestKey);
                    applyDetail(
                        reconcileConversationDetail(
                            {
                                ...conversation,
                                messages: mergeMessages(conversation.messages, [
                                    {
                                        ...attempt,
                                        status: result ? 'completed' : 'failed',
                                    },
                                ]),
                            },
                            detail,
                        ),
                        true,
                        false,
                        choice.value,
                    );
                } catch (error) {
                    if (!gate.current.isCurrent(signal)) return;
                    commit(
                        {
                            ...current.current.conversation,
                            messages: mergeMessages(conversation.messages, [
                                {
                                    ...attempt,
                                    status: result ? 'completed' : 'failed',
                                },
                                ...(result
                                    ? [
                                          {
                                              id: `reply:${requestId}`,
                                              requestId,
                                              persisted: false,
                                              role: 'assistant',
                                              content: result.text,
                                              status: 'completed',
                                          },
                                      ]
                                    : []),
                            ]),
                        },
                        choice.value,
                    );
                    setError(
                        error instanceof Error
                            ? `Saved messages could not be refreshed: ${error.message}`
                            : 'Saved messages could not be refreshed.',
                    );
                }
                void loadHistory();
                if (completed) {
                    unresolved.current.delete(requestKey);
                    setShowPromptError(false);
                } else
                    throw new Error(
                        'The response was not confirmed. An explicit retry will reconcile the same request unless the saved attempt is known to have failed.',
                    );
            }),
    };
}
