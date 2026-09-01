import { useId } from 'react';
import type { SVGAttributes } from 'react';

/**
 * The Archivum mark: an `A` rising out of a bar, with a slash cutting through
 * its counter down to the bottom left.
 *
 * Drawn in `currentColor` rather than the brand blues, because every place it
 * renders is already coloured by its surround — white on the primary tile in
 * the sidebar and the workspace switcher, foreground on the auth pages. The
 * two tones of the full lockup survive as an opacity step, so the overlap
 * between the `A` and the bar still reads at 16px.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    // Scoped because the mark renders more than once per page — the sidebar
    // header and the workspace switcher — and duplicate ids would make every
    // instance after the first resolve its mask against the wrong element.
    const scope = useId().replace(/:/g, '');
    const cut = `${scope}-cut`;
    const letter = `${scope}-letter`;
    const clipBar = `${scope}-clip-bar`;
    const clipColumn = `${scope}-clip-column`;

    return (
        <svg
            {...props}
            viewBox="181.75 151.75 136 136"
            xmlns="http://www.w3.org/2000/svg"
        >
            <defs>
                <mask id={cut}>
                    <rect
                        x="181.75"
                        y="151.75"
                        width="136"
                        height="136"
                        fill="#fff"
                    />
                    <path
                        d="M249.5 198 L269 237 L239 237 L220.5 272 L210.5 272 Z"
                        fill="#000"
                    />
                </mask>

                <path
                    id={letter}
                    d="M240 167.5 L259.5 167.5 L311.75 272 L187.75 272 Z
                       M249.5 198 L286.5 272 L210.5 272 Z"
                    fillRule="evenodd"
                />

                <clipPath id={clipBar}>
                    <rect x="196" y="210" width="107.5" height="61.5" />
                </clipPath>
                <clipPath id={clipColumn}>
                    <rect x="196" y="0" width="107.5" height="271.5" />
                </clipPath>
            </defs>

            <rect
                x="196"
                y="210"
                width="107.5"
                height="61.5"
                fill="currentColor"
                fillOpacity="0.65"
                mask={`url(#${cut})`}
            />

            <use
                href={`#${letter}`}
                fill="currentColor"
                fillOpacity="0.65"
                clipPath={`url(#${clipColumn})`}
            />

            <use
                href={`#${letter}`}
                fill="currentColor"
                clipPath={`url(#${clipBar})`}
            />
        </svg>
    );
}
