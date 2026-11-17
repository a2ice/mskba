# Site Media browser cache and cache-first LQIP (2026-11-04)

## Why images were downloaded or visibly reconstructed on every refresh

The stable Site Media routes `/media/site/{uuid}` and
`/media/site/{uuid}/lqip` previously emitted
`Cache-Control: public, no-cache, must-revalidate`: a browser could retain
bytes, but was required to revalidate them on each navigation.

Separately, the Page Builder first-paint renderer replaced every original
`img src` and authored CSS background URL with an LQIP URL. Client JS
then decoded the real image and changed the URL back. This deliberately
replayed the miniature-to-original transition on **every** refresh, even
with a full original already present in the browser cache.

Static public files in `/images/` also lacked a freshness lifetime.

## New behavior

* Stable Site Media URLs carry `Cache-Control: public, max-age=300,
  stale-while-revalidate=3600` and existing ETag validation. The URLs may
  point to a new current Media version after an editor replaces an asset,
  therefore **they are not immutable**. An editor may see the previous image
  until normal cache freshness or background revalidation catches up. A hard
  refresh explicitly bypasses the normal warm-cache behavior.
* Public static image files from the application Nginx are cached for seven
  days (these paths can be overwritten by a deploy, so not immutable).
  Fingerprinted Vite build assets are immutable for one year.
* Page Builder outputs the **full original image in `img src` immediately**,
  along with an LQIP CSS background on the same element while the network is
  cold. Successful decode removes that fallback; no JS changes `img.src`.
  `picture/source` similarly keeps the real responsive URL from first HTML.
* For CSS `background-image`, the original URL is the frontmost image
  and the optional low-res URL is a fallback behind it. This means a cached
  original paints directly on later loads. Other CSS URL declarations,
  element positions, classes, animation timings, z-index and image geometry
  remain unchanged.
* Late API-driven backgrounds get the same ordering; the progressive
  renderer never inserts cloned player images or extra DOM wrappers.
* With JavaScript disabled, original URLs remain usable, with the preview
  providing only a background while the image loads.

## Validation

Check dev with a clean browser profile for the initial transition, then reload
normally while watching Network: a warm static image or Site Media original
should be served from browser cache and the full image should not be swapped
to an LQIP `src`. In DevTools, **Disable cache**, forced reloads, browser
cache eviction, and brand-new media naturally produce fresh network requests.

Tests cover header policy and ETag 304, first-paint markup and CSS,
mobile `picture`, dynamic background handoff, warm-cache initial images,
failed originals retaining previews and source integrity.
