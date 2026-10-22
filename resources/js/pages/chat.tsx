import { Head, router } from '@inertiajs/react';
import { ArrowUp, History, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import AlertError from '@/components/alert-error';
import {
    ConversationHistory,
    DeleteConversationDialog,
} from '@/components/chat-history';
import ChatModelControls from '@/components/chat-model-controls';
import ChatTranscript from '@/components/chat-transcript';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { chatChoices } from '@/hooks/ai-connections';
import type { AIConnection, AIProvider } from '@/hooks/ai-connections';
import {
    conversationSelection,
    conversationUrl,
    useChatModels,
    useChatSessions,
} from '@/hooks/use-chat-sessions';
import type {
    Conversation,
    ConversationPage,
    InitialConversation,
} from '@/hooks/use-chat-sessions';
import { chat as chatRoute } from '@/routes';
import { edit as connectionSettings } from '@/routes/connections';

export type Workspace = {
    status: 'ready' | 'unavailable';
    greeting: string;
    correlationId: string;
    conversation: InitialConversation;
    conversations?: ConversationPage;
    sources?: unknown[];
    provider: { status: string; message: string };
    connections: AIConnection[];
    capabilities: { name: string; status: string }[];
    providers?: AIProvider[];
};

export function shouldSendOnEnter(
    key: string,
    shift: boolean,
    composing: boolean,
): boolean {
    return key === 'Enter' && !shift && !composing;
}

export default function Chat({
    workspace,
    providers: initialProviders = [],
}: {
    workspace: Workspace;
    providers?: AIProvider[];
}) {
    const [question, setQuestion] = useState('');
    const [historyOpen, setHistoryOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<Conversation | null>(null);
    const log = useRef<HTMLDivElement>(null);
    const followMessages = useRef(true);
    const preserveScroll = useRef<{ height: number; top: number } | null>(null);
    const submitting = useRef(false);
    const sessions = useChatSessions(
        workspace.conversation,
        workspace.conversations,
        (conversation) => {
            router.replace({
                url: conversationUrl(conversation.id),
                preserveState: true,
                preserveScroll: true,
                props: (current) => ({
                    ...current,
                    workspace: { ...workspace, conversation },
                }),
            });
        },
    );
    const choices = chatChoices(
        workspace.connections,
        workspace.providers ?? initialProviders,
    );
    const choice = choices.find((item) => item.value === sessions.selection);
    const restoredChoice =
        conversationSelection(sessions.conversation) === sessions.selection;
    const catalog = useChatModels(
        choice,
        sessions.modelScope,
        restoredChoice ? sessions.conversation.model : choice?.model,
        restoredChoice ? sessions.conversation.thinkingLevel : null,
    );
    const historyProps = {
        history: sessions.history,
        selectedId: sessions.conversation.id,
        pending: sessions.pending,
        loading: sessions.historyLoading,
        error: sessions.historyError,
        onRetry: sessions.retryHistory,
        onLoadMore: sessions.loadMore,
        onNew: async () => {
            if (await sessions.newChat()) {
                setQuestion('');
                setHistoryOpen(false);
                followMessages.current = true;
            }
        },
        onOpen: async (id: string) => {
            followMessages.current = true;
            if (await sessions.open(id)) {
                setQuestion('');
                setHistoryOpen(false);
            }
        },
        onDelete: setDeleteTarget,
    };

    useEffect(() => {
        if (!log.current) return;
        if (preserveScroll.current) {
            log.current.scrollTop =
                preserveScroll.current.top +
                log.current.scrollHeight -
                preserveScroll.current.height;
            preserveScroll.current = null;
        } else if (followMessages.current) {
            log.current.scrollTop = log.current.scrollHeight;
        }
    }, [sessions.conversation.messages]);

    async function loadOlderMessages() {
        if (preserveScroll.current || sessions.pending) return;
        if (log.current)
            preserveScroll.current = {
                height: log.current.scrollHeight,
                top: log.current.scrollTop,
            };
        followMessages.current = false;
        if (!(await sessions.loadOlder())) preserveScroll.current = null;
    }

    async function submitQuestion(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (
            !choice ||
            !catalog.selectedModel ||
            catalog.loading ||
            catalog.error ||
            !question.trim() ||
            sessions.pending ||
            submitting.current
        )
            return;
        submitting.current = true;
        const submitted = question;
        followMessages.current = true;
        try {
            if (
                await sessions.send(submitted.trim(), {
                    ...choice,
                    model: catalog.selectedModel.slug,
                    thinkingLevel: catalog.thinkingLevel,
                })
            )
                setQuestion((current) =>
                    current === submitted ? '' : current,
                );
        } finally {
            submitting.current = false;
        }
    }

    return (
        <>
            <Head title="Chat" />
            <div className="flex h-[calc(100dvh-4rem)] min-h-0 w-full gap-4 px-4 group-has-data-[collapsible=icon]/sidebar-wrapper:h-[calc(100dvh-3rem)] md:h-[calc(100dvh-5rem)] md:px-6 md:group-has-data-[collapsible=icon]/sidebar-wrapper:h-[calc(100dvh-4rem)]">
                <aside
                    aria-label="Recent conversations"
                    className="hidden w-64 shrink-0 flex-col border-r py-4 pr-4 lg:flex xl:w-72"
                >
                    <ConversationHistory {...historyProps} />
                </aside>
                <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                    <div className="mx-auto flex min-h-0 w-full max-w-4xl flex-1 flex-col">
                        <header className="shrink-0 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h1 className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                    {sessions.conversation.title ||
                                        workspace.greeting}
                                </h1>
                                <Sheet
                                    open={historyOpen}
                                    onOpenChange={setHistoryOpen}
                                >
                                    <SheetTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="lg:hidden"
                                        >
                                            <History aria-hidden="true" />
                                            Chat history
                                        </Button>
                                    </SheetTrigger>
                                    <SheetContent
                                        side="left"
                                        className="w-full sm:max-w-sm"
                                    >
                                        <SheetHeader>
                                            <SheetTitle>
                                                Chat history
                                            </SheetTitle>
                                            <SheetDescription>
                                                Private conversations saved to
                                                your account.
                                            </SheetDescription>
                                        </SheetHeader>
                                        <div className="flex min-h-0 flex-1 flex-col px-4 pb-4">
                                            <ConversationHistory
                                                {...historyProps}
                                            />
                                        </div>
                                    </SheetContent>
                                </Sheet>
                            </div>
                        </header>

                        <section
                            className="flex min-h-0 flex-1 flex-col"
                            aria-label="Conversation"
                            aria-busy={sessions.pending}
                        >
                            <h2 id="conversation-title" className="sr-only">
                                Conversation
                            </h2>
                            <ChatTranscript
                                messages={sessions.conversation.messages}
                                hasMore={sessions.conversation.hasMore}
                                pending={sessions.pending}
                                hasConnections={choices.length > 0}
                                settingsUrl={connectionSettings.url()}
                                logRef={log}
                                onScroll={(event) => {
                                    const region = event.currentTarget;
                                    followMessages.current =
                                        region.scrollHeight -
                                            region.scrollTop -
                                            region.clientHeight <
                                        80;
                                }}
                                onLoadOlder={loadOlderMessages}
                            />
                            <div className="sticky bottom-0 shrink-0 space-y-3 bg-background pt-3 pb-4">
                                {sessions.error && (
                                    <AlertError
                                        errors={[sessions.error]}
                                        title="Conversation request failed"
                                    />
                                )}
                                {sessions.sending && (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Waiting for the model response.
                                    </p>
                                )}
                                {sessions.pending && !sessions.sending && (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Updating conversation...
                                    </p>
                                )}
                                <form
                                    className="space-y-2 rounded-2xl border bg-card p-2 focus-within:ring-2 focus-within:ring-ring/30"
                                    onSubmit={submitQuestion}
                                >
                                    <Label
                                        htmlFor="chat-question"
                                        className="sr-only"
                                    >
                                        Question
                                    </Label>
                                    <Textarea
                                        id="chat-question"
                                        className="max-h-64 min-h-24 resize-none border-0 px-3 py-2 shadow-none focus-visible:ring-0"
                                        rows={3}
                                        maxLength={4000}
                                        required
                                        value={question}
                                        disabled={sessions.pending}
                                        placeholder="Ask a question..."
                                        onChange={(event) =>
                                            setQuestion(event.target.value)
                                        }
                                        onKeyDown={(event) => {
                                            if (
                                                shouldSendOnEnter(
                                                    event.key,
                                                    event.shiftKey,
                                                    event.nativeEvent
                                                        .isComposing,
                                                )
                                            ) {
                                                event.preventDefault();
                                                event.currentTarget.form?.requestSubmit();
                                            }
                                        }}
                                    />
                                    <div className="flex items-end justify-between gap-2">
                                        <ChatModelControls
                                            choices={choices}
                                            choice={choice}
                                            models={catalog.models}
                                            selectedModel={
                                                catalog.selectedModel
                                            }
                                            thinkingLevel={
                                                catalog.thinkingLevel
                                            }
                                            loading={catalog.loading}
                                            error={catalog.error}
                                            pending={sessions.pending}
                                            onConnectionChange={
                                                sessions.selectConnection
                                            }
                                            onModelChange={catalog.selectModel}
                                            onThinkingChange={
                                                catalog.selectThinking
                                            }
                                            onRefresh={catalog.refresh}
                                        />
                                        <Button
                                            type="submit"
                                            size="icon"
                                            className="shrink-0 rounded-full"
                                            disabled={
                                                sessions.pending ||
                                                !choice ||
                                                catalog.loading ||
                                                Boolean(catalog.error) ||
                                                !catalog.selectedModel ||
                                                !question.trim()
                                            }
                                        >
                                            {sessions.sending ? (
                                                <LoaderCircle
                                                    className="animate-spin"
                                                    aria-hidden="true"
                                                />
                                            ) : (
                                                <ArrowUp aria-hidden="true" />
                                            )}
                                            <span className="sr-only">
                                                {sessions.sending
                                                    ? 'Sending...'
                                                    : 'Send'}
                                            </span>
                                        </Button>
                                    </div>
                                </form>
                                <p className="px-2 text-center text-xs text-muted-foreground">
                                    AdKit and Zernio tools are not yet
                                    connected.
                                </p>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
            <DeleteConversationDialog
                conversation={deleteTarget}
                pending={sessions.pending}
                onClose={() => setDeleteTarget(null)}
                onConfirm={async () => {
                    const deletingCurrent =
                        deleteTarget?.id === sessions.conversation.id;
                    if (
                        deleteTarget &&
                        (await sessions.remove(deleteTarget.id))
                    ) {
                        setDeleteTarget(null);
                        if (deletingCurrent) setQuestion('');
                    }
                }}
            />
        </>
    );
}
Chat.layout = { breadcrumbs: [{ title: 'Chat', href: chatRoute() }] };
