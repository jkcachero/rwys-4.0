import { ChevronDown, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ChatChoice } from '@/hooks/ai-connections';
import { thinkingLabel } from '@/hooks/use-chat-sessions';
import type { ChatModel } from '@/hooks/use-chat-sessions';

type Props = {
    choices: ChatChoice[];
    choice?: ChatChoice;
    models: ChatModel[];
    selectedModel: ChatModel | null;
    thinkingLevel: string | null;
    loading: boolean;
    error: string;
    pending: boolean;
    onConnectionChange: (value: string) => void;
    onModelChange: (value: string) => void;
    onThinkingChange: (value: string | null) => void;
    onRefresh: () => void;
};

export function ChatThinkingMenuItems({
    selectedModel,
    thinkingLevel,
    pending,
    onThinkingChange,
}: Pick<
    Props,
    'selectedModel' | 'thinkingLevel' | 'pending' | 'onThinkingChange'
>) {
    return (
        <>
            <DropdownMenuLabel>Thinking level</DropdownMenuLabel>
            <DropdownMenuRadioGroup
                aria-label="Thinking level"
                value={thinkingLevel ?? 'provider_default'}
                onValueChange={(value) => {
                    if (pending || !selectedModel) return;
                    if (value === 'provider_default') onThinkingChange(null);
                    else if (selectedModel.thinkingLevels.includes(value))
                        onThinkingChange(value);
                }}
            >
                <DropdownMenuRadioItem
                    value="provider_default"
                    disabled={pending || !selectedModel}
                >
                    Provider default
                </DropdownMenuRadioItem>
                {selectedModel?.thinkingLevels.map((level) => (
                    <DropdownMenuRadioItem
                        key={level}
                        value={level}
                        disabled={pending}
                    >
                        {thinkingLabel(level)}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
            {!selectedModel?.thinkingLevels.length && (
                <p className="px-2 py-1.5 text-xs text-muted-foreground">
                    Provider-managed. No reasoning levels were advertised for
                    this model.
                </p>
            )}
            <DropdownMenuSeparator />
            <p className="max-w-56 px-2 py-1.5 text-xs text-muted-foreground">
                Higher thinking levels can use more allowance.
            </p>
        </>
    );
}

export function ChatModelMenuItems({
    choices,
    choice,
    models,
    selectedModel,
    thinkingLevel,
    loading,
    error,
    pending,
    onConnectionChange,
    onModelChange,
    onThinkingChange,
    onRefresh,
}: Props) {
    return (
        <>
            <DropdownMenuLabel className="space-y-1 whitespace-normal">
                <span className="block text-xs font-normal text-muted-foreground">
                    Connection
                </span>
                <span className="block">
                    {choice?.label ?? 'Choose a connection'}
                </span>
            </DropdownMenuLabel>
            <DropdownMenuRadioGroup
                aria-label="Connection"
                value={choice?.value ?? ''}
                onValueChange={(value) => {
                    if (
                        !pending &&
                        choices.some((item) => item.value === value)
                    )
                        onConnectionChange(value);
                }}
            >
                {choices.map((item) => (
                    <DropdownMenuRadioItem
                        key={item.value}
                        value={item.value}
                        disabled={pending}
                        onSelect={(event) => event.preventDefault()}
                        className="whitespace-normal"
                    >
                        {item.label}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
            {!choices.length && (
                <DropdownMenuItem disabled>No connections yet</DropdownMenuItem>
            )}
            <DropdownMenuSeparator />
            <DropdownMenuLabel>Models</DropdownMenuLabel>
            {loading ? (
                <DropdownMenuItem disabled>Loading models...</DropdownMenuItem>
            ) : error ? (
                <DropdownMenuItem disabled>Models unavailable</DropdownMenuItem>
            ) : !choice ? (
                <DropdownMenuItem disabled>
                    Choose a connection first
                </DropdownMenuItem>
            ) : !models.length ? (
                <DropdownMenuItem disabled>
                    No models available
                </DropdownMenuItem>
            ) : (
                <DropdownMenuRadioGroup
                    aria-label="Model"
                    value={selectedModel?.slug ?? ''}
                    onValueChange={(value) => {
                        if (
                            !pending &&
                            models.some((model) => model.slug === value)
                        )
                            onModelChange(value);
                    }}
                >
                    {models.map((model) => (
                        <DropdownMenuRadioItem
                            key={model.slug}
                            value={model.slug}
                            disabled={pending}
                            className="items-start"
                        >
                            <span className="min-w-0">
                                <span className="block whitespace-normal">
                                    {model.displayName}
                                </span>
                                <span className="block text-xs break-all text-muted-foreground">
                                    {model.slug}
                                </span>
                            </span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            )}
            <DropdownMenuSeparator />
            <DropdownMenuSub>
                <DropdownMenuSubTrigger
                    disabled={pending || loading || !choice || !selectedModel}
                >
                    Thinking level
                    <span className="ml-auto pr-2 pl-4 text-xs text-muted-foreground">
                        {thinkingLevel
                            ? thinkingLabel(thinkingLevel)
                            : 'Provider default'}
                    </span>
                </DropdownMenuSubTrigger>
                <DropdownMenuPortal>
                    <DropdownMenuSubContent>
                        <ChatThinkingMenuItems
                            selectedModel={selectedModel}
                            thinkingLevel={thinkingLevel}
                            pending={pending}
                            onThinkingChange={onThinkingChange}
                        />
                    </DropdownMenuSubContent>
                </DropdownMenuPortal>
            </DropdownMenuSub>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                disabled={!choice || loading || pending}
                onSelect={(event) => {
                    event.preventDefault();
                    onRefresh();
                }}
            >
                <RefreshCw aria-hidden="true" />
                Refresh models
            </DropdownMenuItem>
        </>
    );
}

export default function ChatModelControls(props: Props) {
    const label = props.selectedModel?.displayName ?? 'Choose model';
    return (
        <div className="min-w-0 flex-1 space-y-1">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="max-w-full gap-1.5 rounded-full"
                        disabled={props.pending}
                        aria-label={`Model options: ${label}`}
                    >
                        <span className="truncate">{label}</span>
                        <ChevronDown
                            className="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    side="top"
                    align="start"
                    className="max-h-[var(--radix-dropdown-menu-content-available-height)] w-80 max-w-[calc(100vw-2rem)] overflow-y-auto"
                >
                    <ChatModelMenuItems {...props} />
                </DropdownMenuContent>
            </DropdownMenu>
            {props.loading && (
                <p role="status" className="px-3 text-xs text-muted-foreground">
                    Loading models for your selected connection...
                </p>
            )}
            {props.error && (
                <p role="alert" className="px-3 text-xs text-destructive">
                    {props.error}
                </p>
            )}
        </div>
    );
}
