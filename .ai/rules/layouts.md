---
paths:
  - 'resources/js/layouts/**'
---

# Layouts

## Layouts follow the page shell rules too
A layout that wraps app pages (e.g. `layouts/settings/layout.tsx`) is bound by everything in .ai/rules/pages.md — it must render through `PageContainer` and `PageHeader`, not roll its own padding, width or `h2`-as-page-title. Settings was skipped on the grounds of "it has its own layout" and ended up the least consistent area in the app.

The auth layouts are the genuine exception: they are full-page, have no sidebar, and legitimately do not use the app shell.

If a layout supplies the page `h1`, the pages inside it must not add their own — settings pages were each carrying an `sr-only` `h1` to compensate for the shell not having a visible one.
