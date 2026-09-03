---
paths:
  - resources/js/lib/document-scan.ts
---

# Lib

## Import OpenCV.js statically, and hand it canvases, not <img> elements
Never load `@techstark/opencv-js` with a dynamic `import()`. Its CommonJS export is a promise of itself, so the bundler's interop gives the module namespace `Promise.prototype` as its prototype — making the namespace a thenable. `import()`'s own promise machinery then adopts it, calls the inherited `then` with the namespace as receiver, and throws `Method Promise.prototype.then called on incompatible receiver` before any of our code runs. It reproduces only in a production build (never in dev, never in a plain HTML page), and also bites Vitest, which loads modules via dynamic import — hence the `vi.mock('@techstark/opencv-js')` in document-scan.test.ts. The static import costs code-splitting: OpenCV lands in the importing page's chunk.

`cv.imread()` reads an `<img>` at its *layout* size (`img.width`/`img.height`), not `naturalWidth`/`naturalHeight`. Passing the displayed element returns coordinates in CSS-pixel space — on a phone, roughly a quarter scale — which silently misaligns every corner. Always draw to a canvas sized to the intrinsic dimensions first (`toFullSizeCanvas()`).

jscanify reads a bare global `cv`; `loadOpenCv()` must assign `globalThis.cv` or its first call throws `cv is not defined`.
