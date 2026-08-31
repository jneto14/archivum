---
paths:
  - 'resources/js/components/**'
---

# Components

## Responsive: pick the signal that actually governs the space
Two traps, both of which shipped broken layouts here and neither of which `ci:check` can catch.

**Viewport breakpoints lie when the sidebar is in play.** The app sidebar is 16rem expanded and 3rem collapsed, so the content area swings by 208px at a fixed viewport. An iPad Mini in portrait is exactly `md` (768px) yet leaves the header ~464px — the header's `md:` layout overlapped the breadcrumb with the search field. Where available width depends on the sidebar, use a container query (Tailwind v4, built in): `@container/name` on the element, `@2xl/name:` on its children. Raising the breakpoint only moves the failure.

**Never derive layout from a measured number.** The PDF preview computed a scale from a `ResizeObserver` width and sized the canvas in CSS pixels from it; a stale or slightly-off measurement clipped the page. Let CSS do the fitting (`width: <zoom>%`) so it is correct by construction, and demote measurement to things where being wrong only costs quality, like bitmap resolution.

If you write a container-query class, check the built stylesheet actually contains the rule (`container: name/inline-size` and `@container name (width>=...)`). A class Tailwind fails to detect degrades silently to no styling at all.
