# Changelog

All notable changes to Magna Pages are recorded here.

This project is in alpha. Until 1.0, breaking changes can land in any release, and
there is no supported upgrade path between alpha versions.

## [0.2.0-alpha] — 2026-10-06

Authoring fixes, most of them found by building a real marketing site on the
alpha rather than by reading the code. Three were silent: they lost an
author's work without saying anything, which is the worst way for an editor to
fail.

Requires Magna CMS 1.4.7 or newer — the site-root toggle and the site-identity
fields both read core settings this release introduced.

### Fixed

- **Relative links survived sanitization.** A URL with no scheme was dropped by
  the scheme allowlist, so `<a href="/pricing">` was stored and rendered as
  `<a rel="noopener noreferrer">` — the words kept, the destination gone,
  nothing reported. Linking to another page of your own site is the most
  ordinary thing a CMS does and it failed silently every time. Images carried
  the same fault: one dragged from the library has a `/storage/...` src, and it
  rendered blank. `javascript:` is still refused — it has a scheme, and that
  scheme is not on the list.
- **Blur stopped rewriting text nobody edited.** The inline rich editor
  committed on every blur with no dirty check, so focusing a text block and
  clicking away rewrote its body as the editor's own round trip — dropping
  tables, definition lists, figures, `details` and every `h1`–`h6` the server
  sanitizer allows but the inline schema does not model.
- **A `json` field had no editor.** It fell through to the single-line text
  input and read as empty, so a `features` block whose items were a real array
  showed a blank box, and the first keystroke replaced the array with that
  string. It now gets a textarea, and an edit that does not parse is refused
  and shown rather than saved over the list.

### Added

- **Section CSS class and anchor** in the builder's Advanced tab. Both have
  always rendered and both were editable in the old editor, but pages are only
  edited here — so a theme whose vocabulary is "the class chooses the band" had
  no way to say which band a section is.
- **Patterns travel in the site kit.** A `patterns` key on the bundle, upserted
  by name, so a theme can ship a composite instead of asking an editor to
  rebuild it by hand. Validated through the same gate as a user's own "save as
  pattern"; one that fails is skipped rather than aborting the sync.
- **`blockSeeds` in `theme.json`** — what a freshly inserted block starts with,
  merged over the schema's seed per handle, so a theme states only the keys it
  wants to differ.
- **Site identity in Site settings** — the site's name and tagline, mirrored
  from core's own settings screen. Every page title carries the name, so a
  published site no longer introduces itself as "Magna CMS".
- **Site root toggle** — hand `/` to the site and move the admin panel to
  `/admin`. The mechanism is core's; this screen offers it, because freeing the
  root only means anything on a site with a frontend to put there.
- **Accessible names on containers** — an optional `role` and `label`, so a
  composite that reads as one picture announces as one thing rather than a pile
  of fragments. `figure` and `nav` join the tag options.
- **Table header columns and footers** — `th[scope=row]` and `tfoot`, so a
  table of figures says what each row is and distinguishes a total from one
  more row of data.

### Changed

- A settings change made anywhere now flushes the rendered page cache when it
  could affect what was rendered, rather than leaving the old value on the
  public site until the hour-long TTL expired.

## [0.1.0-alpha] — 2026-09-28

First public release. Published early so the architecture can be read and
discussed, not because it is finished.

### Added

- **Visual builder** — a Vue 3 + TypeScript editor whose canvas is the real
  rendered page, drawn by the production Blade views in an iframe, with nodes
  mapped back to document positions by server-stamped attributes.
- **Document model** — pages as a tree of sections, columns and blocks, written
  through JSON Patch operations that are classified by what they touch and
  authorised per capability before being applied.
- **Block library** — headings, text, images, galleries, buttons, icons, video,
  HTML, tables, code, quotes, callouts, file downloads, FAQs, heroes, features,
  pricing, stats, testimonials, team, logos, search, containers, spacers and
  dividers.
- **Templates and chrome** — reusable template parts; a page may choose a header
  and footer, inherit the site default, or switch either off.
- **Menus** — nested menu builder with a `nav` block that renders any defined menu.
- **Design tokens and responsive styles** — global colour, type and spacing
  tokens, per-node styles per breakpoint, with light and dark emitted into one
  stylesheet so pages stay cacheable.
- **Revisions, locks and review** — restorable revisions, document locking so two
  editors cannot silently overwrite each other, and publish requests for editors
  without publish rights.
- **Checks** — accessibility findings and performance notes computed for the page
  being edited.
- **Dynamic content** — a `loop` block over data sources that plugins register,
  plus dynamic tags and display conditions.
- **Patterns and library** — save a section as a reusable pattern; browse and
  install layout assets.
- **Extensibility** — plugins contribute blocks, data sources, dynamic tags and
  display conditions through SDK contracts; the inspector is driven entirely by a
  block's own field schema, so a third-party block gets a working editor with no
  builder change.
- **Performance** — page cache keyed by URL, a render budget bounding plugin data
  fetches, and automatic redirects when a page's slug changes.

### Known issues

- Repeater fields are new and lightly tested.
- An edit can be silently dropped if a setting is changed while the canvas is
  mid-reload.
- Block library depth is uneven; some blocks are placeholders.
- Theme integration is functional but under-documented.
- Editor test coverage is thinner than server coverage.

[0.1.0-alpha]: https://github.com/Magna-CMS/Magna-Pages/releases/tag/v0.1.0-alpha
