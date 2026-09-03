---
paths:
  - resources/js/lib/document-scan.ts
  - resources/js/lib/document-scan-runtime.ts
---

# Lib

## Never `import()` OpenCV.js directly, and hand it canvases, not <img> elements
`@techstark/opencv-js`'s CommonJS export is a promise of itself, so the bundler's interop gives its module namespace `Promise.prototype` as its prototype — making the namespace a thenable. A dynamic `import()` of the package has its own promise machinery adopt that namespace, call the inherited `then` with the namespace as receiver, and throw `Method Promise.prototype.then called on incompatible receiver` before any of our code runs. It reproduces only in a production build: never in dev, never in a plain HTML page. It also bites Vitest, which loads modules via dynamic import, so a test importing the package needs `vi.mock('@techstark/opencv-js')`.

This is why the package is imported statically by `document-scan-runtime.ts`, and why that module — not the package — is what `document-scan.ts` loads with `import()`. Keeping the wrapper is what preserves both: the ~13MB stays out of the page's own chunk, and the namespace `import()` adopts is an ordinary one.

`cv.imread()` reads an `<img>` at its *layout* size (`img.width`/`img.height`), not `naturalWidth`/`naturalHeight`. Passing the displayed element returns coordinates in CSS-pixel space — on a phone, roughly a quarter scale — which silently misaligns every corner. Always draw to a canvas sized to the intrinsic dimensions first (`toFullSizeCanvas()`).

jscanify reads a bare global `cv`; `createScanner()` must assign `globalThis.cv` or its first call throws `cv is not defined`.
