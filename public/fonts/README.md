# Barabara

`BARABARA-final.otf` is installed and wired up in `public/css/app.css`'s
`@font-face` declaration. `.poster-title` (the hero headline "DAVAO REGION"
and the "Explore DVO" wordmark in the header) uses it, with
`'Alfa Slab One', serif` as the fallback stack for anything that can't load
an OTF (functionally nonexistent among current browsers, but kept for
safety).

The typeface has uppercase glyphs only — no lowercase forms. `.poster-title`
forces `text-transform: uppercase` for exactly this reason; don't apply the
`Barabara` font-family anywhere that skips that rule, or lowercase source
text will hit missing/tofu glyphs.

## Licensing — check before shipping

This copy came from a third-party font-mirror site (ffonts.net), supplied
directly by the project owner — no license file was bundled with it. Prior
research into Barabara's origin found it's distributed by DOT as free for
personal use; commercial/government use requires confirming licensing terms
with the font owner (DOT) first. That confirmation has not been done here.
Get that sign-off before this ships to a live government platform.
