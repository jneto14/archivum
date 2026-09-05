<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Refusal;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every reason an action gives for refusing a write has to reach the person who
 * attempted it.
 *
 * A refusal is raised as a validation error, and a validation error is
 * addressed to a *field*. Inertia hands the whole set to the page, where each
 * one is rendered beside the input it names — so a message keyed to something
 * the page has no input for arrives, is read by nothing, and is dropped.
 *
 * That is what filing a document into a workspace at its document limit did:
 * the write was refused, the form stayed put, and nothing was said. Five other
 * guards were addressed to fields no page renders either, and the ones that
 * worked did so only because their key happened to match an input. Nothing
 * connected the two, so this is what connects them.
 *
 * Static on purpose. The alternative is a feature test per guard, which is
 * thirty-odd tests that each prove one case and none of which fails when the
 * thirty-first is written.
 */
class ActionRefusalsAreVisibleTest extends TestCase
{
    public function test_every_refusal_an_action_raises_is_rendered_somewhere()
    {
        $rendered = $this->keysRenderedByTheInterface();
        $invisible = [];

        foreach ($this->keysRefusedByActions() as $key => $sources) {
            if ($key === Refusal::KEY || in_array($key, $rendered, true)) {
                continue;
            }

            $invisible[] = "'{$key}' (" . implode(', ', array_unique($sources)) . ')';
        }

        $this->assertSame([], $invisible, implode("\n", [
            'These refusals are addressed to a field no page renders, so the write',
            'is refused and the person who attempted it is told nothing:',
            '',
            ...array_map(static fn (string $line): string => '  ' . $line, $invisible),
            '',
            'Either render the key beside the input it names, or — if the message is',
            'not about anything the user typed — raise it with Refusal::because(),',
            'which PageContainer always shows.',
        ]));
    }

    /**
     * Every field name an action addresses a validation message to.
     *
     * @return array<string, list<string>> Key to the files that raise it.
     */
    private function keysRefusedByActions(): array
    {
        $keys = [];

        foreach (Finder::create()->files()->in(app_path('Actions'))->name('*.php') as $file) {
            foreach ($this->messageArraysIn($file->getContents()) as $arguments) {
                foreach ($this->topLevelKeysIn($arguments) as $key) {
                    $keys[$key][] = $file->getRelativePathname();
                }
            }
        }

        return $keys;
    }

    /**
     * The array literal passed to each `withMessages()` call in a source file.
     *
     * Bracket-counted rather than matched, because a message often carries a
     * nested array of its own — `__('…', ['count' => $remaining])` — and a
     * pattern that stops at the first `]` reads that translation's replacements
     * as message keys.
     *
     * Scanned over an array of characters rather than over the string. Indexing
     * a string is by byte while `mb_strlen` counts characters, and one accented
     * character in a source file is enough to slide the two out of step — which
     * silently walked this scan past the end of a call and into whatever array
     * came next.
     *
     * @param string $source The file's contents.
     *
     * @return list<string> One entry per call, brackets excluded.
     */
    private function messageArraysIn(string $source): array
    {
        $characters = mb_str_split($source);
        $length = count($characters);
        $arrays = [];

        for ($start = 0; $start < $length; $start++) {
            if (implode('', array_slice($characters, $start, 14)) !== 'withMessages([') {
                continue;
            }

            $bracket = $start + 13;
            $depth = 0;

            for ($i = $bracket; $i < $length; $i++) {
                $depth += match ($characters[$i]) {
                    '[' => 1,
                    ']' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $arrays[] = implode('', array_slice($characters, $bracket + 1, $i - $bracket - 1));

                    break;
                }
            }
        }

        return $arrays;
    }

    /**
     * The keys of an array literal, ignoring any nested inside it.
     *
     * @param string $arguments The literal's contents.
     *
     * @return list<string> The field names it addresses.
     */
    private function topLevelKeysIn(string $arguments): array
    {
        $depth = 0;
        $outer = '';

        foreach (mb_str_split($arguments) as $character) {
            $depth += match ($character) {
                '[' => 1,
                ']' => -1,
                default => 0,
            };

            if ($depth === 0 && $character !== ']') {
                $outer .= $character;
            }
        }

        preg_match_all("/'([a-z_]+)'\s*=>/", $outer, $found);

        return $found[1];
    }

    /**
     * Every field name the interface reads an error for.
     *
     * Both spellings, because a page reaching into the errors bag by index is
     * as much a rendering of that key as one reading a property off a form.
     *
     * @return list<string> The keys, deduplicated.
     */
    private function keysRenderedByTheInterface(): array
    {
        $keys = [];

        foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.ts', '*.tsx']) as $file) {
            preg_match_all(
                '/errors(?:\.([a-zA-Z_]+)|\[[\'"]([a-zA-Z_]+)[\'"]\])/',
                $file->getContents(),
                $found,
            );

            foreach ([...$found[1], ...$found[2]] as $key) {
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }
}
