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
