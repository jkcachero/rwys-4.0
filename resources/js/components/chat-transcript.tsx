import { MessageSquare } from 'lucide-react';
import type { Ref, UIEventHandler } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { ChatMessage } from '@/hooks/use-chat-sessions';

export default function ChatTranscript({
    messages,
    hasMore,
    pending,
    hasConnections,
    settingsUrl,
    logRef,
    onScroll,
    onLoadOlder,
}: {
    messages: ChatMessage[];
    hasMore?: boolean;
    pending: boolean;
    hasConnections: boolean;
    settingsUrl: string;
    logRef: Ref<HTMLDivElement>;
    onScroll: UIEventHandler<HTMLDivElement>;
    onLoadOlder: () => void;
}) {
    return (
        <div
            ref={logRef}
            role="log"
            tabIndex={0}
            aria-labelledby="conversation-title"
            aria-live="polite"
            aria-relevant="additions text"
            className="min-h-0 flex-1 space-y-6 overflow-y-auto overscroll-contain rounded-sm py-6 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            onScroll={onScroll}
        >
            {hasMore && (
                <Button
                    variant="outline"
                    size="sm"
                    disabled={pending}
                    onClick={onLoadOlder}
                >
                    Load older messages
                </Button>
            )}
            {!messages.length && (
                <div className="flex min-h-full flex-col items-center justify-center gap-3 px-4 text-center">
                    <MessageSquare
                        className="size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <h3 className="text-base font-medium">No messages yet</h3>
                    <p className="max-w-md text-sm text-muted-foreground">
                        {hasConnections ? (
                            'Choose a model below, then ask a question.'
                        ) : (
                            <>
                                Set up a connection in Settings &gt;{' '}
                                <a
                                    href={settingsUrl}
                                    className="underline underline-offset-4 outline-none focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    AI connections
                                </a>
                                .
                            </>
                        )}
                    </p>
                </div>
            )}
            {messages.map((message, index) => (
                <article
                    key={message.id ?? index}
                    aria-label={
                        message.role === 'user'
                            ? 'Your message'
                            : 'Assistant message'
                    }
                    className={
                        message.role === 'user'
                            ? 'flex justify-end px-1'
                            : 'px-1'
                    }
                >
                    <div
                        className={
                            message.role === 'user'
                                ? 'max-w-3xl space-y-1 rounded-2xl bg-muted px-4 py-3 text-sm'
                                : 'max-w-3xl space-y-2 text-sm'
                        }
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-xs font-medium text-muted-foreground">
                                {message.role === 'user' ? 'You' : 'Assistant'}
                            </p>
                            {message.status === 'failed' && (
                                <Badge variant="outline">Failed</Badge>
                            )}
                            {message.status === 'pending' && (
                                <Badge variant="outline">Pending</Badge>
                            )}
                        </div>
                        <p className="leading-7 wrap-anywhere whitespace-pre-wrap">
                            {message.content}
                        </p>
                        {message.status === 'failed' && (
                            <p className="text-xs text-muted-foreground">
                                {message.persisted === false
                                    ? 'This attempt failed and was not confirmed as saved. Your draft is available to retry.'
                                    : 'This attempt failed. No completed response was saved for this attempt.'}
                            </p>
                        )}
                    </div>
                </article>
            ))}
        </div>
    );
}
