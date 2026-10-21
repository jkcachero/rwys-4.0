import { Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Conversation, ConversationPage } from '@/hooks/use-chat-sessions';

export function ConversationHistory({
    history,
    selectedId,
    pending,
    loading,
    error,
    onNew,
    onOpen,
    onDelete,
    onRetry,
    onLoadMore,
}: {
    history: ConversationPage;
    selectedId?: string | null;
    pending: boolean;
    loading: boolean;
    error: string;
    onNew: () => void;
    onOpen: (id: string) => void;
    onDelete: (conversation: Conversation) => void;
    onRetry: () => void;
    onLoadMore: () => void;
}) {
    return (
        <TooltipProvider delayDuration={250}>
            <div className="flex min-h-0 flex-1 flex-col gap-4">
                <Button variant="outline" onClick={onNew} disabled={pending}>
                    <Plus aria-hidden="true" />
                    New chat
                </Button>
                <div>
                    <h2 className="text-sm font-medium">Recent chats</h2>
                    <p className="text-xs text-muted-foreground">
                        Private to you
                    </p>
                </div>
                <nav
                    aria-label="Chat history"
                    className="min-h-0 flex-1 space-y-2 overflow-y-auto"
                >
                    {history.data.map((conversation) => (
                        <div
                            className="flex min-w-0 items-start gap-1"
                            key={conversation.id}
                        >
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        variant={
                                            selectedId === conversation.id
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        className="h-auto min-w-0 flex-1 flex-col items-start gap-1 px-2 py-2 text-left"
                                        disabled={pending}
                                        onClick={() => onOpen(conversation.id)}
                                        aria-label={`Open conversation: ${conversation.title || 'Untitled chat'}`}
                                        aria-current={
                                            selectedId === conversation.id
                                                ? 'page'
                                                : undefined
                                        }
                                    >
                                        <span className="w-full truncate">
                                            {conversation.title ||
                                                'Untitled chat'}
                                        </span>
                                        {conversation.updatedAt && (
                                            <time
                                                className="text-xs font-normal text-muted-foreground"
                                                dateTime={
                                                    conversation.updatedAt
                                                }
                                            >
                                                {conversation.updatedAt.slice(
                                                    0,
                                                    10,
                                                )}
                                            </time>
                                        )}
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="right"
                                    align="start"
                                    className="max-w-xs wrap-anywhere whitespace-normal"
                                >
                                    {conversation.title || 'Untitled chat'}
                                </TooltipContent>
                            </Tooltip>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 shrink-0"
                                disabled={pending}
                                onClick={() => onDelete(conversation)}
                                aria-label={`Delete conversation: ${conversation.title || 'Untitled chat'}`}
                            >
                                <Trash2
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                            </Button>
                        </div>
                    ))}
                    {!history.data.length && !loading && !error && (
                        <p className="text-sm text-muted-foreground">
                            No saved chats yet.
                        </p>
                    )}
                    {loading && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Loading chat history...
                        </p>
                    )}
                    {error && (
                        <div className="space-y-2">
                            <AlertError
                                title="History unavailable"
                                errors={[error]}
                            />
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={loading}
                                onClick={() => {
                                    onRetry();
                                }}
                            >
                                Retry history
                            </Button>
                        </div>
                    )}
                    {history.current_page < history.last_page && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="w-full"
                            disabled={loading || pending}
                            onClick={() => {
                                onLoadMore();
                            }}
                        >
                            Load more chats
                        </Button>
                    )}
                </nav>
            </div>
        </TooltipProvider>
    );
}

export function DeleteConversationDialog({
    conversation,
    pending,
    onClose,
    onConfirm,
}: {
    conversation: Conversation | null;
    pending: boolean;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog
            open={Boolean(conversation)}
            onOpenChange={(open) => {
                if (!open && !pending) onClose();
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete conversation?</DialogTitle>
                    <DialogDescription>
                        Permanently delete{' '}
                        {conversation?.title || 'this conversation'} and its
                        messages. Your AI connections will not be changed.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        disabled={pending}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={pending || !conversation}
                        onClick={onConfirm}
                    >
                        Delete conversation
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
