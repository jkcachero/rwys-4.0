import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import * as menuPrimitives from '@/components/ui/dropdown-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
} from '@/components/ui/dropdown-menu';
import ChatModelControls, {
    ChatModelMenuItems,
    ChatThinkingMenuItems,
} from './chat-model-controls';

vi.mock('@radix-ui/react-dropdown-menu', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@radix-ui/react-dropdown-menu')>()),
    Portal: ({ children }: { children: ReactNode }) => children,
}));

const choice = {
    value: 'api:7',
    label: 'Work (claude API key)',
    provider: 'claude' as const,
    connectionId: 7,
};
const model = {
    slug: 'verified-model',
    displayName: 'Verified model',
    thinkingLevels: ['low', 'high', 'xhigh', 'max', 'none'],
};
const props: ComponentProps<typeof ChatModelControls> = {
    choices: [choice],
    choice,
    models: [model],
    selectedModel: model,
    thinkingLevel: null,
    loading: false,
    error: '',
    pending: false,
    onConnectionChange: vi.fn(),
    onModelChange: vi.fn(),
    onThinkingChange: vi.fn(),
    onRefresh: vi.fn(),
};

afterEach(() => vi.restoreAllMocks());

function renderMenu(items: ReactNode) {
    return renderToStaticMarkup(
        <DropdownMenu defaultOpen>
            <DropdownMenuContent forceMount>{items}</DropdownMenuContent>
        </DropdownMenu>,
    );
}

describe('composer model menu', () => {
    it('forwards only owned connection, catalogued model and verified thinking selections and blocks pending changes', () => {
        const handlers: Record<string, (value: string) => void> = {};
        const RadioGroup = menuPrimitives.DropdownMenuRadioGroup;
        vi.spyOn(menuPrimitives, 'DropdownMenuRadioGroup').mockImplementation(
            (groupProps) => {
                if (groupProps['aria-label'] && groupProps.onValueChange)
                    handlers[groupProps['aria-label']] =
                        groupProps.onValueChange;
                return <RadioGroup {...groupProps} />;
            },
        );
        const callbacks = {
            ...props,
            onConnectionChange: vi.fn(),
            onModelChange: vi.fn(),
            onThinkingChange: vi.fn(),
        };
        renderMenu(<ChatModelMenuItems {...callbacks} />);
        renderMenu(<ChatThinkingMenuItems {...callbacks} />);
        handlers.Connection(choice.value);
        handlers.Connection('api:999');
        handlers.Model(model.slug);
        handlers.Model('unverified-model');
        handlers['Thinking level']('provider_default');
        handlers['Thinking level']('high');
        handlers['Thinking level']('unverified');
        expect(callbacks.onConnectionChange).toHaveBeenCalledExactlyOnceWith(
            choice.value,
        );
        expect(callbacks.onModelChange).toHaveBeenCalledExactlyOnceWith(
            model.slug,
        );
        expect(callbacks.onThinkingChange.mock.calls).toEqual([
            [null],
            ['high'],
        ]);
        renderMenu(<ChatModelMenuItems {...callbacks} pending />);
        renderMenu(<ChatThinkingMenuItems {...callbacks} pending />);
        handlers.Connection(choice.value);
        handlers.Model(model.slug);
        handlers['Thinking level']('low');
        expect(callbacks.onConnectionChange).toHaveBeenCalledTimes(1);
        expect(callbacks.onModelChange).toHaveBeenCalledTimes(1);
        expect(callbacks.onThinkingChange).toHaveBeenCalledTimes(2);
    });
    it('uses one model trigger without exposing account labels or a picker grid while closed', () => {
        const html = renderToStaticMarkup(<ChatModelControls {...props} />);
        expect(html).toContain('Model options: Verified model');
        expect(html).toContain('aria-haspopup="menu"');
        expect(html).not.toContain('Work (claude API key)');
        expect(html).not.toContain('role="combobox"');
        expect(html).not.toContain('Refresh models');
    });
    it('keeps owned billing scope, verified models, refresh and the thinking submenu inside the menu', () => {
        const html = renderMenu(<ChatModelMenuItems {...props} />);
        expect(html).toContain('Work (claude API key)');
        expect(html).toContain('role="menuitemradio"');
        expect(html).toContain('aria-checked="true"');
        expect(html).toContain('Verified model');
        expect(html).toContain('verified-model');
        expect(html).toContain('Thinking level');
        expect(html).toContain('data-slot="dropdown-menu-sub-trigger"');
        expect(html).toContain('Refresh models');
        expect(html).not.toContain('unverified-model');
    });
    it('offers only provider default and the verified thinking levels with their real labels', () => {
        const html = renderMenu(
            <ChatThinkingMenuItems
                selectedModel={model}
                thinkingLevel="high"
                pending={false}
                onThinkingChange={vi.fn()}
            />,
        );
        for (const label of [
            'Provider default',
            'Low',
            'High',
            'Extra high',
            'Maximum',
            'None',
        ])
            expect(html).toContain(label);
        expect(html).not.toContain('Medium');
        expect(html).toContain('Higher thinking levels can use more allowance');
    });
    it('uses provider-managed default for unknown thinking support without unsupported claims', () => {
        const html = renderMenu(
            <ChatThinkingMenuItems
                selectedModel={{ ...model, thinkingLevels: [] }}
                thinkingLevel={null}
                pending={false}
                onThinkingChange={vi.fn()}
            />,
        );
        expect(html).toContain('Provider default');
        expect(html).toContain('Provider-managed');
        expect(html).toContain(
            'No reasoning levels were advertised for this model.',
        );
        expect(html.match(/role="menuitemradio"/g)).toHaveLength(1);
        expect(html).not.toContain('unsupported');
    });
    it('disables configuration during pending requests, and refresh while loading or unselected', () => {
        const trigger = renderToStaticMarkup(
            <ChatModelControls {...props} pending />,
        );
        expect(trigger).toMatch(/<button[^>]*disabled=""/);
        const loading = renderMenu(<ChatModelMenuItems {...props} loading />);
        expect(loading).toContain('Loading models');
        expect(loading).not.toContain('verified-model');
        const unselected = renderMenu(
            <ChatModelMenuItems
                {...props}
                choice={undefined}
                selectedModel={null}
                models={[]}
            />,
        );
        expect(unselected).toContain('Choose a connection');
        expect(unselected).toContain('data-disabled');
    });
    it('reports errors accessibly near the composer even when the menu is closed', () => {
        const html = renderToStaticMarkup(
            <ChatModelControls {...props} error="Catalog unavailable." />,
        );
        expect(html).toContain('role="alert"');
        expect(html).toContain('Catalog unavailable.');
    });
});
