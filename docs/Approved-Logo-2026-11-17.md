# Owner-supplied ExploreDVO logo

The owner requested the attached circular eagle, sun, mountain and durian emblem for the website header and favicon on 17 November 2026. The artwork was not regenerated or redrawn. The original transparent 1024 × 1024 PNG is preserved at `public/images/logo/exploredvo-eagle-durian-original.png`, SHA-256 `eb717d38d9ae300b7b1fe318eb9fe2d42cfc1c5e9b710995bd58f7782ea4f97a`.

## Assets and use

- `eagle-durian-96.webp` and `eagle-durian-192.webp`: responsive shared brand component images, preserving its existing decorative empty alt and adjacent readable ExploreDVO name.
- `eagle-durian-512.webp`: larger version for reuse.
- `eagle-durian-favicon-{16,32,48}.png`: browser icon sizes.
- `eagle-durian-apple-touch.png`: 180 × 180 Apple touch icon.
- `public/favicon.ico`: multi-image ICO containing 16, 32 and 48 px PNG frames.

The shared `brand-mark` component updates the public header, mobile drawer, footer and sign-in views that already use it. The shared head-assets partial publishes the new favicon URLs to its layouts. Versioned filenames and the ICO query parameter avoid reusing the old asset URL in browser caches. Original logo assets remain available for previously cached pages. Existing header dimensions and navigation layout remain unchanged.

## Publication

Deployment verified the archive hash `f69bd8a108fd0d5bb692dde924835b3787f80bd694ae42680fc354cb3fae1d00`, checked the prior hashes of both Blade files and the root favicon, and refused differing files at the new asset paths. Every installed file matched staging. Backup: `/var/backups/exploredvo/exploredvo-logo-20261117T141208Z`. Compiled views were cleared and PHP-FPM reloaded. No database records changed.

Live browser verification checks four routes, loading of the responsive brand images, five icon links, PNG dimensions and ICO frames. Desktop and mobile header screenshots are reviewed. This is a focused visual/asset change; no new automated application tests or full PHPUnit run are required.
