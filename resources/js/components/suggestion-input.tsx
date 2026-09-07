import { useId, useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = Omit<
    React.ComponentProps<'input'>,
    'value' | 'onChange' | 'type'
> & {
    value: string;
    onChange: (value: string) => void;
    /** What to offer, already narrowed to what has been typed and ranked. Empty means the field behaves as a plain input. */
    suggestions: string[];
    /** Names the list for assistive technology — the inputs themselves are placeholder-labelled. */
    suggestionsLabel: string;
};

/**
 * A text input that offers what is already in use, without ever standing in the
 * way of typing something new.
 *
 * Deliberately not a `<select>` or a Radix popover. The vocabulary is a hint,
 * not a closed list: any of these fields can hold a value nobody has filed
 * before, so the control has to be an input first and a menu second. A popover
 * would also move focus out of the input to make its list reachable, which is
 * wrong for something read while typing continues.
 *
 * Not a native `<datalist>` either: browsers disagree on whether it filters by
 * prefix or substring and on whether it shows anything at all before the first
 * keystroke, and none of it can be styled or tested. The ranking is the point
 * here — the most-used key has to be the first one offered — so it cannot be
 * left to the browser.
 */
export function SuggestionInput({
    value,
    onChange,
    suggestions,
    suggestionsLabel,
    className,
    onKeyDown,
    onFocus,
    onBlur,
    ...props
}: Props) {
    const listId = useId();
    const [isOpen, setIsOpen] = useState(false);
    const [highlighted, setHighlighted] = useState(-1);

    // Clamped rather than reset in an effect: the list is re-narrowed on every
    // keystroke, and an index left pointing past the end of the shorter list
    // would select whatever slid into that position.
    const active = highlighted < suggestions.length ? highlighted : -1;
    const isShowing = isOpen && suggestions.length > 0;

    const select = (suggestion: string) => {
        onChange(suggestion);
        setIsOpen(false);
        setHighlighted(-1);
    };

    const move = (offset: number) => {
        const next = active + offset;

        setHighlighted(
            next < 0
                ? suggestions.length - 1
                : next >= suggestions.length
                  ? 0
                  : next,
        );
    };

    const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        onKeyDown?.(event);

        if (event.key === 'Escape') {
            setIsOpen(false);
            setHighlighted(-1);

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            // Otherwise the caret jumps to the end or the start of the value
            // while the highlight moves.
            event.preventDefault();
            setIsOpen(true);

            if (isShowing) {
                move(event.key === 'ArrowDown' ? 1 : -1);
            }

            return;
        }

        if (event.key === 'Enter' && isShowing && active >= 0) {
            // The row sits inside the document form: without this, picking a
            // suggestion with the keyboard saves the document.
            event.preventDefault();
            select(suggestions[active]);
        }
    };

    return (
        <div className="relative w-full min-w-0">
            <Input
                {...props}
                type="text"
                value={value}
                autoComplete="off"
                role="combobox"
                aria-expanded={isShowing}
                aria-controls={isShowing ? listId : undefined}
                aria-autocomplete="list"
                aria-activedescendant={
                    isShowing && active >= 0 ? `${listId}-${active}` : undefined
                }
                className={className}
                onChange={(event) => {
                    onChange(event.target.value);
                    setIsOpen(true);
                    setHighlighted(-1);
                }}
                onFocus={(event) => {
                    onFocus?.(event);
                    setIsOpen(true);
                }}
                onBlur={(event) => {
                    onBlur?.(event);
                    setIsOpen(false);
                    setHighlighted(-1);
                }}
                onKeyDown={handleKeyDown}
            />
            {isShowing && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label={suggestionsLabel}
                    className="absolute top-full right-0 left-0 z-50 mt-1 max-h-56 overflow-y-auto rounded-md border bg-popover py-1 text-popover-foreground shadow-md"
                >
                    {suggestions.map((suggestion, index) => (
                        <li
                            key={suggestion}
                            id={`${listId}-${index}`}
                            role="option"
                            aria-selected={index === active}
                            className={cn(
                                'cursor-pointer truncate px-3 py-1.5 text-sm',
                                index === active &&
                                    'bg-accent text-accent-foreground',
                            )}
                            // Mouse down rather than click: the input's own blur
                            // fires first and would close the list out from
                            // under the pointer before the click landed.
                            onMouseDown={(event) => {
                                event.preventDefault();
                                select(suggestion);
                            }}
                            onMouseEnter={() => setHighlighted(index)}
                        >
                            {suggestion}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
