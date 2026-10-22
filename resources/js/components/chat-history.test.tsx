import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { ConversationHistory } from './chat-history';

describe('Conversation history titles', () => {
    it('keeps a long title compact while exposing its full accessible name and tooltip trigger', () => {
        const title =
            'Planning a safe database migration and model rollout with complete rollback and verification steps';
        const html = renderToStaticMarkup(
            <ConversationHistory
                history={{
                    data: [
                        {
                            id: 'conversation-1',
                            title,
                            provider: 'openai',
                            authMode: 'oauth',
                            connectionId: null,
                            model: null,
                            thinkingLevel: null,
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                }}
                selectedId="conversation-1"
                pending={false}
                loading={false}
                error=""
                onNew={vi.fn()}
                onOpen={vi.fn()}
                onDelete={vi.fn()}
                onRetry={vi.fn()}
                onLoadMore={vi.fn()}
            />,
        );

        expect(html).toContain('class="w-full truncate"');
        expect(html).toContain(`aria-label="Open conversation: ${title}"`);
        expect(html).toContain('data-slot="tooltip-trigger"');
        expect(html).toContain('aria-current="page"');
        expect(html).toContain(`aria-label="Delete conversation: ${title}"`);
    });
});
