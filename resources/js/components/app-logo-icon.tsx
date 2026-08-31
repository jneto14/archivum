import type { SVGAttributes } from 'react';

/**
 * The Archivum mark — the "Folio" monogram: an A whose crossbar reads as a
 * shelf label.
 *
 * Drawn on a square 32×32 grid with ~3 units of optical padding, so it can be
 * dropped into a square container at any size without further insetting. It
 * paints with `fill`, not `stroke`, and takes its colour from `fill-current` —
 * every caller sets the colour with a text utility.
 *
 * The two shapes are a deliberate union under the default nonzero fill rule:
 * the crossbar bridges the legs rather than being cut out of them, which keeps
 * the counter open and legible down to 16px. Do not add `fill-rule="evenodd"`
 * here — it would punch the crossbar into a hole.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" {...props}>
            <path d="M16 3.2 3.4 29h6.1L16 15.2 22.5 29h6.1L16 3.2Z" />
            <rect x="11" y="19.5" width="10" height="4" rx="2" />
        </svg>
    );
}
