---
paths:
  - 'resources/js/**'
---

# Js

## In any row mixing user text with actions, say explicitly what may shrink
This single pattern caused most of the layout bugs found during ARC-88: a flex row holding user-controlled text (document titles, filenames, workspace names, scheme names) next to buttons, with nothing told what gives.

The default is that everything refuses to shrink, so the row overflows and clips whatever is last — the "Carregar" button left the attachments card the moment a filename appeared beside it. Always mark it up:
- text that may shrink: `min-w-0` plus `truncate` (a `max-w-*` is a cap, not permission to shrink)
- actions that must not: `shrink-0`
- the row itself: `flex-wrap` and a `gap`, so it drops to a second line rather than compressing

Related: a fixed-height bar (`h-16` header) must not hold fixed-width controls that can't collapse. Give them a compact form — the header's 256px search field and labelled button become icons when the header is narrow — and stop children wrapping into a height the bar doesn't have (`BreadcrumbList` ships with `flex-wrap`; the trail was overridden to `flex-nowrap` with the current page truncating).

Don't ship a control that is `disabled` forever. Either wire it up or remove it, and remove whatever only existed to feed it — the documents bulk-action bar went, and row selection went with it, because selecting did nothing.

## Table cells never wrap until you say so
shadcn's `TableCell` (components/ui/table.tsx) ships `whitespace-nowrap`. Until you override it with `whitespace-normal` on the cell, `max-w-*`, `truncate`, `line-clamp-*` and `break-words` on a child do nothing at all — there is no line break for them to act on.

Two symptoms this caused on the Tasks page: a failure message ran off the right edge, pushing the actions column past the table's `overflow-x-auto` where it could not be clicked; and a long type label overlapped the next column.

Fixing the wrapping is only half of it. An auto-layout table sizes columns from content, so a long unbreakable token (a path, a UUID) still widens the column no matter what max-width the child carries. Prose columns need `table-fixed` with explicit `w-[n%]` heads, plus `min-w-[...]` on the table so narrow screens scroll instead of crushing every column.

Cap anything a backend can make arbitrarily long — an exception message, a filename — with `line-clamp-*` plus a `title`, rather than trusting it to be short.
