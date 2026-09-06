---
paths:
  - resources/js/lib/document-scan.ts
  - resources/js/lib/document-scan-runtime.ts
  - resources/js/components/document-camera-dialog.tsx
  - resources/js/components/document-scan-review.tsx
---

# Lib

## Never `import()` OpenCV.js directly, and hand it canvases, not <img> elements
`@techstark/opencv-js`'s CommonJS export is a promise of itself, so the bundler's interop gives its module namespace `Promise.prototype` as its prototype — making the namespace a thenable. A dynamic `import()` of the package has its own promise machinery adopt that namespace, call the inherited `then` with the namespace as receiver, and throw `Method Promise.prototype.then called on incompatible receiver` before any of our code runs. It reproduces only in a production build: never in dev, never in a plain HTML page. It also bites Vitest, which loads modules via dynamic import, so a test importing the package needs `vi.mock('@techstark/opencv-js')`.

This is why the package is imported statically by `document-scan-runtime.ts`, and why that module — not the package — is what `document-scan.ts` loads with `import()`. Keeping the wrapper is what preserves both: the ~13MB stays out of the page's own chunk, and the namespace `import()` adopts is an ordinary one.

`cv.imread()` reads an `<img>` at its *layout* size (`img.width`/`img.height`), not `naturalWidth`/`naturalHeight`. Passing the displayed element returns coordinates in CSS-pixel space — on a phone, roughly a quarter scale — which silently misaligns every corner. Always draw to a canvas sized to the intrinsic dimensions first (`toFullSizeCanvas()`).

## Detection is ours; jscanify does the warp and nothing else
It used to detect too, and could not: `findPaperContour()` took the largest contour of any shape, and `getCornerPoints()` named corners by which axis-aligned quadrant of the shape they fell in. A page rotated towards 45° puts a true corner on a quadrant boundary, so jitter moved it across and a quadrant left empty returned a quad with a corner missing — which reads as detection dropping out (ARC-117). Its edge pipeline was also wrong end to end: Canny over four interleaved RGBA channels, a blur applied *after* the edge detector, then Otsu over an already-binary image.

Do not reach back for `findPaperContour`/`getCornerPoints`. `detectCorners()` in `document-scan-runtime.ts` is gray → blur → Canny → dilate → `findContours` → `approxPolyDP` over the largest few → the first convex quadrilateral that passes `isImplausibleDocument()`. The dilate is load-bearing: a page on a desk of similar tone gives a broken Canny edge, and a contour that does not close encloses no area, which is exactly how a printed box outranks the page.

Order corners by angle around the centroid (`orderCorners()`), never by quadrant or by min/max of `x±y`. Contour tracing usually hands points over already in perimeter order, so an ordering bug hides completely unless a test scrambles them first — and it only surfaces near 45°, where two vertices share a quadrant.

`isImplausibleDocument()` refuses a quad on either side of the plausible range, which puts the corners back at their inset default for the user to drag. Do not delete any bound thinking it is dead code; each was added after a real photo went wrong. The viewfinder passes a lower area floor (`VIEWFINDER_MIN_AREA_RATIO`) than a framed photo gets, because a page is legitimately small in the frame while it is still being aimed at — and the side-ratio guard is only *reachable* under that lower floor, since above it a band that thin is already too small to offer.

jscanify still reads a bare global `cv`; `createScanner()` must assign `globalThis.cv` or `extractPaper()` throws `cv is not defined`.

## The viewfinder outline needs to be temporal, or it strobes
Detection is an independent guess several times a second over a moving picture, and it misses for reasons that say nothing about where the page is: a frame caught mid-exposure, a hand crossing a corner. Clearing the outline on the first miss is what made it unusable to aim with. `document-camera-dialog.tsx` holds the last accepted quad for `VIEWFINDER_MISS_TOLERANCE` consecutive misses and averages successive detections (`smoothCorners`) — except across a real change of subject (`isDifferentSubject`), where averaging would draw a quad matching neither document.

Testing that loop: the dialog renders through a portal, so query `document.body`, not `render`'s container. And `waitFor` polls on the globals `vi.useFakeTimers()` replaced, so it deadlocks — drive the passes by hand with `act` + `advanceTimersByTimeAsync`.
