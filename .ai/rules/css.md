---
paths:
  - resources/css/app.css
---

# Css

## Keep the four surface tokens on a distinct ramp
`--background`, `--muted`, `--secondary` and `--accent` must stay four distinct values, in order. Light: background 1.0 > muted 0.972 > secondary 0.955 > accent 0.93. Dark runs the other way, with card/popover lifted above background so panels have an edge.

This is load-bearing, not taste. Components express selected and hover state purely by stepping between these tokens — `toggleVariants` pairs `hover:bg-muted` with `data-[state=on]:bg-accent`, and the sidebar pairs `hover:bg-sidebar-accent` with `data-[active=true]:bg-sidebar-accent` plus `font-medium`. The stock shadcn palette shipped all three of muted/secondary/accent at the *same* value, which made the documents table/cards toggle's active state completely invisible.

Ordering matters too, not just distinctness: `accent` (hover) must never sit more prominent than whatever marks the active state, or hovering an inactive item reads as more selected than the selected one. That happened to the settings tabs when they used `bg-muted` for active.
