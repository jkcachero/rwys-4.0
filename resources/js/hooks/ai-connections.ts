import { useEffect, useRef, useState } from 'react';
import { disconnect, openai, test } from '@/routes/connections';
import { prompt as promptRoute } from '@/routes/dashboard';
import { destroy, index, store } from '@/routes/workspace/connections';

export type AIConnection = {
    id: number;
    provider: string;
    label: string;
    authMode: string;
    model: string | null;
    status: string;
    lastValidatedAt: string | null;
};
export type AIProvider = {
    name: string;
    label: string;
    model: string;
    status?: string;
    authentication?: string[];
    capabilities?: string[];
    connected?: boolean;
    expiresAt?: string | null;
    message?: string | null;
};
export type OAuthAttempt = { url: string; state: string; expiresAt: string };

export function validateOAuthAttempt(attempt: OAuthAttempt): OAuthAttempt {
    let url: URL;
    try {
        url = new URL(attempt.url);
    } catch {
        throw new Error('Invalid OpenAI sign-in response.');
    }
    if (
        url.origin !== 'https://auth.openai.com' ||
        url.pathname !== '/api/accounts/authorize' ||
        url.username ||
        url.password ||
        !Number.isFinite(Date.parse(attempt.expiresAt)) ||
        !attempt.state
    ) {
        throw new Error('Invalid OpenAI sign-in response.');
    }
    return attempt;
}
export type ChatChoice = {
    value: string;
    label: string;
    provider: 'openai' | 'claude';
    connectionId?: number;
    model?: string;
    thinkingLevel?: string | null;
    conversationId?: string;
    requestId?: string;
};
export type InferenceResult = {
    provider: string;
    model: string;
    text: string;
    requestId?: string;
    correlationId?: string;
    conversationId?: string;
};

export function requestHeaders(cookie = '', meta = ''): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };
    const token = cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    if (token) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
    } else if (meta) {
        headers['X-CSRF-TOKEN'] = meta;
    }
    return headers;
}

export async function jsonRequest<T>(
    url: string,
    method = 'GET',
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const headers = requestHeaders(
        typeof document === 'undefined' ? '' : document.cookie,
        typeof document === 'undefined'
            ? ''
            : (document
                  .querySelector('meta[name="csrf-token"]')
                  ?.getAttribute('content') ?? ''),
    );
    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        redirect: 'error',
        ...(method !== 'GET' && body !== undefined
            ? { body: JSON.stringify(body) }
            : {}),
        signal,
    });
    if (!response.headers.get('content-type')?.includes('application/json')) {
        throw new Error(
            'Expected a JSON response. Refresh your session and try again.',
        );
    }
    const data = await response.json();
    signal?.throwIfAborted();
    if (!response.ok) {
        const labels: Record<string, string> = {
            apiKey: 'API key',
            label: 'Connection label',
            model: 'Model',
            provider: 'Provider',
            connectionId: 'Connection',
            prompt: 'Question',
            thinkingLevel: 'Thinking level',
            conversationId: 'Conversation',
            requestId: 'Request',
        };
        const errors = Object.entries(data.errors ?? {}).flatMap(
            ([field, messages]) =>
                (Array.isArray(messages) ? messages : [messages]).map(
                    (message) =>
                        `${labels[field] ?? field}: ${String(message)}`,
                ),
        );
        throw new Error(
            errors.length
                ? errors.join(' ')
                : (data.message ?? 'The request could not be completed.'),
        );
    }
    return data as T;
}

export async function pollOAuth(
    state: string,
    expires: number,
    parentSignal: AbortSignal,
): Promise<{ status: 'connected'; message?: string }> {
    const deadline = Math.min(expires, Date.now() + 5 * 60 * 1000);
    const timeout = new AbortController();
    const signal = AbortSignal.any([parentSignal, timeout.signal]);
    const expiryError = new Error(
        'OpenAI sign-in expired. Start a new connection attempt.',
    );
    const expiryTimer = setTimeout(
        () => timeout.abort(expiryError),
        Math.max(0, deadline - Date.now()),
    );
    try {
        while (Date.now() < deadline) {
            signal.throwIfAborted();
            await new Promise<void>((resolve, reject) => {
                const abort = () => {
                    clearTimeout(timer);
                    reject(signal.reason);
                };
                const timer = setTimeout(
                    () => {
                        signal.removeEventListener('abort', abort);
                        resolve();
                    },
                    Math.min(2000, deadline - Date.now()),
                );
                signal.addEventListener('abort', abort, { once: true });
            });
            signal.throwIfAborted();
            if (Date.now() >= deadline) break;
            const result = await jsonRequest<{
                status: 'pending' | 'connected' | 'error';
                message?: string;
                expiresAt?: string;
            }>(
                `${openai.url()}/attempts/${encodeURIComponent(state)}`,
                'GET',
                undefined,
                signal,
            );
            if (result.status === 'connected')
                return { status: 'connected', message: result.message };
            if (result.status === 'error')
                throw new Error(result.message ?? 'OpenAI sign-in failed.');
            if (result.status !== 'pending')
                throw new Error('Unexpected sign-in status.');
            if (result.expiresAt && Date.parse(result.expiresAt) <= Date.now())
                break;
        }
        throw expiryError;
    } finally {
        clearTimeout(expiryTimer);
    }
}

export function chatChoices(
    connections: AIConnection[],
    providers: AIProvider[],
): ChatChoice[] {
    const choices: ChatChoice[] = connections
        .filter(
            (connection) =>
                connection.status !== 'revoked' &&
                ['openai', 'claude'].includes(connection.provider),
        )
        .map((connection) => ({
            value: `api:${connection.id}`,
            label: `${connection.label} (${connection.provider} API key)`,
            provider: connection.provider as 'openai' | 'claude',
            connectionId: connection.id,
            ...(connection.model ? { model: connection.model } : {}),
        }));
    if (
        providers.some(
            (provider) =>
                provider.name === 'openai' && provider.connected === true,
        )
    ) {
        choices.push({
            value: 'oauth:openai',
            label: 'OpenAI subscription (OAuth)',
            provider: 'openai',
        });
    }
    return choices;
}

export function promptPayload(prompt: string, choice: ChatChoice) {
    return {
        provider: choice.provider,
        prompt,
        ...(choice.connectionId !== undefined
            ? { connectionId: choice.connectionId }
            : {}),
        ...(choice.model ? { model: choice.model } : {}),
        ...(choice.model && choice.thinkingLevel !== undefined
            ? { thinkingLevel: choice.thinkingLevel }
            : {}),
        ...(choice.conversationId
            ? { conversationId: choice.conversationId }
            : {}),
        ...(choice.requestId ? { requestId: choice.requestId } : {}),
    };
}

export const aiRequests = {
    list: (signal?: AbortSignal) =>
        jsonRequest<{ connections: AIConnection[] }>(
            index.url(),
            'GET',
            undefined,
            signal,
        ),
    save: (
        draft: {
            provider: string;
            label: string;
            apiKey: string;
            model: string;
        },
        signal?: AbortSignal,
    ) =>
        jsonRequest<{ connection: AIConnection }>(
            store.url(),
            'POST',
            { ...draft, authMode: 'api_key', model: draft.model || null },
            signal,
        ),
    revoke: (id: number, signal?: AbortSignal) =>
        jsonRequest<{ status: string }>(
            destroy.url(id),
            'DELETE',
            undefined,
            signal,
        ),
    test: (connection?: AIConnection, signal?: AbortSignal) =>
        jsonRequest(
            test.url(connection?.provider ?? 'openai'),
            'POST',
            connection
                ? {
                      connectionId: connection.id,
                      ...(connection.model ? { model: connection.model } : {}),
                  }
                : {},
            signal,
        ),
    beginOAuth: (signal?: AbortSignal) =>
        jsonRequest<OAuthAttempt>(openai.url(), 'POST', {}, signal),
    disconnectOAuth: (signal?: AbortSignal) =>
        jsonRequest(disconnect.url('openai'), 'DELETE', undefined, signal),
    prompt: (question: string, choice: ChatChoice, signal?: AbortSignal) =>
        jsonRequest<InferenceResult>(
            promptRoute.url(),
            'POST',
            promptPayload(question, choice),
            signal,
        ),
};

export function useAIConnections(
    connections: AIConnection[],
    providers: AIProvider[],
    onConnectionsChange: (connections: AIConnection[]) => void,
    onProvidersChange?: (providers: AIProvider[]) => void,
) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [attempt, setAttempt] = useState<OAuthAttempt | null>(null);
    const controller = useRef<AbortController | null>(null);
    const busy = useRef(false);
    useEffect(
        () => () => {
            controller.current?.abort();
        },
        [],
    );
    async function run(
        action: (
            signal: AbortSignal,
            isCurrent: () => boolean,
        ) => Promise<void>,
    ) {
        if (busy.current) return false;
        busy.current = true;
        controller.current?.abort();
        const current = new AbortController();
        controller.current = current;
        const isCurrent = () =>
            controller.current === current && !current.signal.aborted;
        setPending(true);
        setError('');
        setMessage('');
        try {
            await action(current.signal, isCurrent);
            return isCurrent();
        } catch (error) {
            if (isCurrent()) {
                setMessage('');
                setAttempt(null);
                setError(
                    error instanceof Error
                        ? error.message
                        : 'The request failed.',
                );
            }
            return false;
        } finally {
            if (controller.current === current) {
                busy.current = false;
                if (!current.signal.aborted) setPending(false);
            }
        }
    }
    const refresh = async (signal: AbortSignal, isCurrent: () => boolean) => {
        const data = await aiRequests.list(signal);
        if (isCurrent()) onConnectionsChange(data.connections);
    };
    return {
        pending,
        error,
        message,
        attempt,
        save: (draft: {
            provider: string;
            label: string;
            apiKey: string;
            model: string;
        }) =>
            run(async (signal, isCurrent) => {
                const data = await aiRequests.save(draft, signal);
                if (!isCurrent()) return;
                onConnectionsChange([
                    ...connections.filter(
                        (item) => item.id !== data.connection.id,
                    ),
                    data.connection,
                ]);
                setMessage(
                    'Connection saved, not validated until you test it.',
                );
            }),
        revoke: (id: number) =>
            run(async (signal, isCurrent) => {
                await aiRequests.revoke(id, signal);
                if (!isCurrent()) return;
                onConnectionsChange(
                    connections.filter((item) => item.id !== id),
                );
                setMessage('Connection revoked.');
            }),
        testConnection: (connection?: AIConnection) =>
            run(async (signal, isCurrent) => {
                await aiRequests.test(connection, signal);
                if (!isCurrent()) return;
                if (connection) await refresh(signal, isCurrent);
                if (!isCurrent()) return;
                setMessage('Connection test succeeded.');
            }),
        disconnectOAuth: () =>
            run(async (signal, isCurrent) => {
                await aiRequests.disconnectOAuth(signal);
                if (!isCurrent()) return;
                onProvidersChange?.(
                    providers.map((provider) =>
                        provider.name === 'openai'
                            ? {
                                  ...provider,
                                  connected: false,
                                  status: 'unavailable',
                              }
                            : provider,
                    ),
                );
                setAttempt(null);
                setMessage('OpenAI subscription disconnected.');
            }),
        connectOAuth: () =>
            run(async (signal, isCurrent) => {
                setAttempt(null);
                const data = await aiRequests.beginOAuth(signal);
                if (!isCurrent()) return;
                validateOAuthAttempt(data);
                setAttempt(data);
                setMessage(
                    'Waiting for sign-in. Use the link below to continue.',
                );
                const result = await pollOAuth(
                    data.state,
                    Date.parse(data.expiresAt),
                    signal,
                );
                if (!isCurrent()) return;
                onProvidersChange?.([
                    ...providers.filter(
                        (provider) => provider.name !== 'openai',
                    ),
                    {
                        name: 'openai',
                        label: 'OpenAI',
                        model: '',
                        connected: true,
                        status: 'available',
                    },
                ]);
                setAttempt(null);
                setMessage(result.message ?? 'OpenAI subscription connected.');
            }),
        cancel: () => {
            controller.current?.abort();
            controller.current = null;
            busy.current = false;
            setPending(false);
            setAttempt(null);
            setMessage('Sign-in cancelled.');
        },
    };
}

export function useSavedAIConnections(initialConnections?: AIConnection[]) {
    const [connections, setConnections] = useState(initialConnections ?? []);
    const [loading, setLoading] = useState(initialConnections === undefined);
    const [error, setError] = useState('');
    useEffect(() => {
        if (initialConnections !== undefined) return;
        const controller = new AbortController();
        aiRequests
            .list(controller.signal)
            .then((data) => setConnections(data.connections))
            .catch((error: unknown) => {
                if (!controller.signal.aborted)
                    setError(
                        error instanceof Error
                            ? error.message
                            : 'Connections could not be loaded.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [initialConnections]);
    return { connections, setConnections, loading, error };
}

export function useAIChat() {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const controller = useRef<AbortController | null>(null);
    useEffect(
        () => () => {
            controller.current?.abort();
        },
        [],
    );
    return {
        pending,
        error,
        send: async (question: string, choice: ChatChoice) => {
            if (controller.current) return null;
            const current = new AbortController();
            controller.current = current;
            setPending(true);
            setError('');
            try {
                const result = await aiRequests.prompt(
                    question,
                    choice,
                    current.signal,
                );
                if (typeof result.text !== 'string')
                    throw new Error('The model response did not contain text.');
                return result;
            } catch (error) {
                if (!current.signal.aborted)
                    setError(
                        error instanceof Error
                            ? error.message
                            : 'The request failed.',
                    );
                return null;
            } finally {
                controller.current = null;
                if (!current.signal.aborted) setPending(false);
            }
        },
    };
}
