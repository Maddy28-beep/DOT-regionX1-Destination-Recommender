# Vendored front-end libraries (reports page only)

Nothing here is loaded from a CDN at runtime. The files were downloaded once from
cdn.jsdelivr.net and are served from this site, so visitors' browsers make no
third-party requests for them (no privacy-policy change needed).

Used only by `resources/views/admin/reports.blade.php` (DOT admin Reports & Export).

| File | Upstream | SHA-256 |
|---|---|---|
| `public/vendor/bootstrap/bootstrap.bundle.min.js` | https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js (unmodified) | `0833b2e9c3a26c258476c46266e6877fc75218625162e0460be9a3a098a61c6c` |
| `public/vendor/bootstrap/bootstrap-scoped.min.css` | derived from https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css | derived file: `58a7e287cfacc1f921f0a9a94bd08497c693b5dc5feae71ef9555821792c171f`; upstream original: `3c8f27e6009ccfd710a905e6dcf12d0ee3c6f2ac7da05b0572d3e0d12e736fc8` |
| `public/vendor/chartjs/chart.umd.js` | https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.js (unmodified) | `6e708cb2c2b41604db1f5dec01724856ec53ac867899a1e4e2cfb8f0ace6bed9` |

Bootstrap 5.3.3 and Chart.js 4.4.3 are both MIT licensed (licence headers are kept in the files).

## Why the CSS is "scoped"

Bootstrap's stock stylesheet restyles the whole page (`body`, headings, links,
buttons, tables). The rest of this site has its own CSS, so the stylesheet is
loaded in a prefixed form: every selector is prefixed with `.bs-scope`
(`:root`, `html` and `body` rules become `.bs-scope`). Bootstrap therefore only
affects markup inside an element carrying `class="bs-scope"`, which on the
reports page is the chart block. The admin sidebar, top bar and the existing
panels are outside it. `@keyframes` are untouched.

Regenerate with Node (postcss 8.4.47, postcss-prefix-selector 1.16.1):

```js
const postcss = require('postcss');
const prefixer = require('postcss-prefix-selector');
const fs = require('fs');
postcss([prefixer({
  prefix: '.bs-scope',
  transform(prefix, selector, prefixed) {
    if (selector === ':root' || selector === 'html' || selector === 'body') return prefix;
    if (selector.startsWith(':root')) return prefix + selector.slice(5);
    return prefixed;
  }
})]).process(fs.readFileSync('bootstrap.min.css', 'utf8'), { from: undefined })
  .then(r => fs.writeFileSync('bootstrap-scoped.min.css', r.css));
```

Verify the unmodified files with `sha256sum` against the table above.
