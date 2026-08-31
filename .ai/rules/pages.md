---
paths:
  - 'resources/js/pages/**'
---

# Pages

## Every page goes through PageContainer; never set your own width or padding
Wrap page content in `PageContainer` (components/page-container.tsx). Never put `max-w-*`, `mx-auto` or `p-*` on a page's own root — that is how the app drifted to five different widths across sixteen screens.

Pick the tier by what the page *is*, never by how much content it holds. "This list feels wide" is the judgement call that caused the drift the first time round, when two equivalent admin lists sat at 3xl and 4xl:
- `narrow` — a single column of form fields. Forms and settings only.
- `default` — everything else. Every list, every detail screen, the dashboard. More columns does not earn a wider tier.
- `wide` — laying out N panels side by side where the column count comes from data. Currently only the storage browser.

A page with several states (loaded vs. onboarding vs. empty) must use the same tier for all of them.

Use the shared pieces rather than re-rolling them: `PageHeader` (the page `h1` + actions), `EmptyState`, `Panel`/`PanelHeader` for lists and tables, `Card` for padded titled sections on detail/settings screens. `Heading` is NOT a page title — it is an `h2` for sections within a page.

## Design work has no safety net in CI — verify it in a browser
`sail composer ci:check` passes on layouts that are visibly broken. eslint, Prettier, tsc, Pint, PHPStan and Pest do not know what overflow, clipping, an invisible active state or an unreadable colour ramp look like. During ARC-88 every one of those shipped green.

So: for any change to layout, spacing, colour tokens or responsive behaviour, open the app and look at it — at a phone width, at a tablet width (with the sidebar both open and collapsed), and in both light and dark. "CI is green" is not "the layout is validated", and saying so is misleading.

Also do not trust "this has its own layout" as a reason to skip a screen. The settings section was left out of the shell pass on exactly that reasoning and turned out to be the least consistent area in the app — no visible `h1`, a double width cap, and an inverted active tab.
