import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * The environment is the test. `navigator.clipboard` exists under jsdom exactly
 * as it does on HTTPS, so a hook with no fallback passes there and still leaves
 * the copy button dead on a self-hosted box reached over plain HTTP — which is
 * where an API token, shown once, is copied.
 */
const execCommand = vi.fn(() => true);

beforeEach(() => {
    vi.stubGlobal('document', Object.assign(document, { execCommand }));
    execCommand.mockClear();
});

afterEach(() => vi.unstubAllGlobals());

it('uses the async clipboard API where there is one', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });

    const { result } = renderHook(() => useClipboard());

    await act(async () => {
        expect(await result.current[1]('a token')).toBe(true);
    });

    expect(writeText).toHaveBeenCalledWith('a token');
    expect(execCommand).not.toHaveBeenCalled();
    expect(result.current[0]).toBe('a token');
});

it('still copies where navigator.clipboard does not exist', async () => {
    // What a browser exposes over http://192.168.x.x.
    vi.stubGlobal('navigator', {});

    const { result } = renderHook(() => useClipboard());

    await act(async () => {
        expect(await result.current[1]('a token')).toBe(true);
    });

    expect(execCommand).toHaveBeenCalledWith('copy');
    expect(result.current[0]).toBe('a token');
});

it('falls back when the clipboard API is present but refuses', async () => {
    const writeText = vi.fn().mockRejectedValue(new Error('not allowed'));
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    vi.spyOn(console, 'warn').mockImplementation(() => {});

    const { result } = renderHook(() => useClipboard());

    await act(async () => {
        expect(await result.current[1]('a token')).toBe(true);
    });

    expect(execCommand).toHaveBeenCalledWith('copy');
});

it('reports failure when nothing can copy, rather than claiming success', async () => {
    vi.stubGlobal('navigator', {});
    execCommand.mockReturnValue(false);

    const { result } = renderHook(() => useClipboard());

    await act(async () => {
        expect(await result.current[1]('a token')).toBe(false);
    });

    expect(result.current[0]).toBeNull();
});
