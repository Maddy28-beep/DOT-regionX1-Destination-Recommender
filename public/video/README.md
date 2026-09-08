# Hero footage

Drop a file here and the landing page uses it as the hero background. Remove
it and the illustrated poster hero returns. Nothing else needs changing.

| File | Required | Purpose |
| --- | --- | --- |
| `hero.mp4` | yes | The clip. H.264 in MP4 plays everywhere. |
| `hero.webm` | no | Offered first when present; smaller at the same quality. |
| `hero-poster.jpg` | yes, in practice | The clip's **first frame**, used as the hero background. |

## Why the still matters

The video cannot paint until it has decoded, so whatever sits behind it is
visible on every single load. A colour there reads as the page showing you
something else first. The clip's own first frame reads as the video simply not
having started yet -- because that is exactly what it is.

It must be frame 0, or the swap becomes visible again:

```
ffmpeg -y -i hero.mp4 -vf "select=eq(n\,0),scale=1280:-2" -frames:v 1 -q:v 6 hero-poster.jpg
```

It is set as the section's `background-image`, not as a `<video poster>` --
a poster paints over the video element, one layer above the ground, so it
arrives and departs as its own visible step.

The video files themselves are deliberately **not committed** (see
`.gitignore`) — footage belongs in storage or a CDN, not in git history.

## What the footage has to be

Genuine Davao Region footage that DOT or the project owns or is licensed to
use. Stock coastline standing in for the region on a government tourism site
is the same misrepresentation as a stock photo on a named establishment: it
tells a traveller something about a real place that isn't true.

## Keep it small

Most visitors arrive on a phone, often roaming. Aim for **under 3 MB**:

- 8–15 seconds, looping seamlessly
- 1280×720 is plenty — it sits behind text and is never viewed closely
- No audio track at all (it is muted anyway; the bytes are wasted)
- `-crf 30` or similar; compression artefacts are invisible under the scrim

## Who actually sees it

`public/js/app.js` refuses to download the clip at all when any of these hold,
and the illustrated hero stays instead:

- the visitor asked for reduced motion (`prefers-reduced-motion`)
- the viewport is under 900px — i.e. most phones
- the browser reports Data Saver, or a 2g/3g connection

That is why the markup carries no `src`: a source in the HTML would start
downloading before any of those checks could run.
