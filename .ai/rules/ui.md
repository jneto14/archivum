---
paths:
  - 'resources/js/components/ui/**'
---

# Ui

## Variants in ui/ that nothing uses yet are unverified
The vendored shadcn components carry variants no screen has ever rendered, and two of them were wrong when first used. Before being the first caller of one, open the component and check the token it names actually exists here with the meaning shadcn assumes.

`dropdown-menu.tsx` painted `variant="destructive"` with `text-destructive-foreground`, which in this app is `oklch(0.985 0 0)` — the near-white meant to sit ON a destructive background. White text on a white popover (ARC-124). It wanted `text-destructive`.

Same mixup still stands in `alert.tsx`'s destructive variant, which `AlertError` uses on the two-factor screens. `toggle.tsx`'s `outline` variant gives hover and selected the same `bg-accent`, so the documents index table/cards switch cannot show which is on (found in ARC-127). Both are unfixed and untouched.

A wrong token is invisible to `ci:check`: tsc sees a valid class name and Tailwind compiles it happily. Open the screen and look at it.
