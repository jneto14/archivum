// Credit: https://usehooks-ts.com/
import { useState } from 'react';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

/**
 * Copy text, in a browser that may not have the async clipboard API.
 *
 * `navigator.clipboard` is restricted to secure contexts, and a self-hosted
 * installation is routinely reached over plain HTTP on a LAN — where it is
 * undefined and the copy button does nothing at all. That matters most in the
 * one place it is least recoverable: an API token is shown once, and a user who
 * cannot copy it has to go and make another.
 *
 * So the deprecated `document.execCommand('copy')` is kept as the fallback. It
 * is the only thing that works on an insecure origin, and it works everywhere.
 * It has to run inside the user gesture that asked for the copy, which is why
 * the absence of `navigator.clipboard` is checked synchronously rather than
 * after awaiting anything.
 */
function copyWithSelection(text: string): boolean {
    const textarea = document.createElement('textarea');

    textarea.value = text;
    textarea.setAttribute('readonly', '');
    // Off-screen rather than hidden: a `display: none` element cannot be
    // selected, and an unselected textarea copies nothing.
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';

    document.body.appendChild(textarea);
    textarea.select();
    // Safari on iOS ignores select() on its own.
    textarea.setSelectionRange(0, text.length);

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        textarea.remove();
    }
}

export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy: CopyFn = async (text) => {
        if (navigator?.clipboard) {
            try {
                await navigator.clipboard.writeText(text);
                setCopiedText(text);

                return true;
            } catch (error) {
                // Present but refused — a permission policy, or a document that
                // is not focused. Worth one more try the old way.
                console.warn('Copy failed', error);
            }
        }

        if (copyWithSelection(text)) {
            setCopiedText(text);

            return true;
        }

        setCopiedText(null);

        return false;
    };

    return [copiedText, copy];
}
