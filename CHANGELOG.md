# Changelog

All notable changes to Magna Pages are recorded here.

This project is in alpha. Until 1.0, breaking changes can land in any release, and
there is no supported upgrade path between alpha versions.

## [0.3.0-alpha] - 2026-10-19

### Added

- **A theme layout can resolve any menu, not only the header's.** The renderer
  resolved one hardcoded handle into `headerMenu`, so a second menu was stored,
  synced, editable in the admin, and unreachable from `layout.blade.php`. A
  design shipping five header links beside a fifteen-link drawer had to clone
  the header's nav client-side, and the drawer silently carried five of the
  fifteen. The layout payload now carries a `menu` closure beside `pageUrl`:
  `@php($drawer = $menu('secondary'))`. `headerMenu` is untouched, so a layout
  that has never heard of it keeps working, and an unknown handle resolves to
  an empty list rather than erroring — a theme shipping a drawer must not 500
  every page on a site whose owner has not built that menu yet.

### Added

- **A row can now store the width its columns actually render at.** A
  column's `span` is twelfths, and twelfths cannot say 1.15 / 0.85 — a ratio
  a designer reaches for constantly. Until now the only way to render one was
  a theme class that switched the row to `display: grid` behind the
  document's back, which left the builder faithfully showing the only number
  the document could hold while the page showed something else: in one real
  design, 26 of 28 multi-column bands stored `6, 6` and rendered 1.15fr /
  0.85fr, and dragging the width fields edited a number that controlled
  nothing. `settings.row.columnTemplate` is where that ratio lives now — a
  list of `fr` tracks, one per column, written by the same width fields that
  used to show twelfths, so what the editor sets is what the page renders.

  Four decisions worth knowing, because each one had an alternative:

  - **Setting it derives `display: grid`**, the way a column's align setting
    already derives `display: flex`. It is not a tidiness: each column
    carries an inline `flex: N N 0%` that a grid template on the row would
    otherwise lose to, and under grid that inline value stops applying on its
    own — no `!important` anywhere. `span` therefore survives untouched as
    the fallback the row returns to the moment the template is removed, and
    the producers keep the two in step, so the twelfths never end up
    describing a different layout from the tracks.
  - **Fr numbers only.** `auto`, `min-content` and percentages are refused by
    the renderer. A track the width fields cannot show is the exact
    disagreement this key exists to end; a row that genuinely needs one is a
    theme's layout, through a section class, where the theme is the stated
    owner rather than something the builder pretends to edit.
  - **The narrow-width behaviour is a value in the document, not a default.**
    A grid row does not inherit the one-column collapse the shell gives a
    flex row (the inline `flex` that collapse overrides is already inert), so
    "Stack on mobile" writes `mobile: "1fr"` where the builder can show it.
    A hand-written template with no mobile value applies at every width,
    which is what the document says.
  - **A row that stores no template is untouched** — no grid, no class, no
    stylesheet, byte for byte the page it was.

  The key is classified as a STRUCTURE patch rather than a style one: it is
  the decision the span field makes, it has to agree with `span`, and one
  gesture must not need two capabilities. Changing a column width still needs
  `pages.layout`, exactly as it did.

  Two things stop applying while a template is set, by the nature of grid:
  the row's Direction and Wrapping controls.

## [0.2.1-alpha] — 2026-10-13

Requires Magna CMS 1.4.9 or newer for the block-behaviour mechanism below to
do anything. It is safe to ship against an older core, and deliberately so:
the renderer's include is `@includeIf`, so on a core with no
`block-behaviour/` views it resolves to nothing at all. Verified against core
1.4.8's views with this release's renderer — pages render, no view-not-found,
and a block that carries its behaviour inline keeps working exactly as it did.
The theme audit's new warnings are likewise correct on an older core: there,
`scheme-toggle`'s default view really does still ship inline `<script>` that
an override drops, which is the defect being warned about.

### Fixed

- **A page's own settings now travel with the site kit.** `page_settings` —
  the per-page background and chrome choices the builder's settings tab
  writes — was absent from the kit's entry keys, so an export silently
  dropped it, `magna:site:diff` could not see it drift between environments,
  and a sync left whatever the target already had. A stale override could
  therefore live in one environment's database and nowhere else, invisible
  to every tool. The semantics are deliberate: a kit row without the key
  (one exported before this release) leaves existing settings alone on
  apply, a present-and-empty value is an explicit clear and applies, and the
  diff treats cleared and never-set as equal so an old kit does not read as
  permanent drift.

- **Overriding a block view no longer drops the block's behaviour.** A block's
  behaviour now ships as a sibling view
  (`block-behaviour/{handle}.blade.php`) that the renderer includes beside
  whichever markup view the theme chain picked, resolved on the `magna::`
  namespace alone and never through `theme::`. Overriding
  `views/blocks/{handle}.blade.php` therefore replaces markup and nothing
  else. Before this, `scheme-toggle` carried its un-hide, click handler and
  stored choice in an `@once` script inside its own view, and `ThemeAuditor`
  refuses a theme's block view any `<script>` — so a theme that overrode the
  view purely to restyle the button lost the behaviour and was forbidden from
  putting it back. The symptom was not a styling regression anyone would spot
  while looking at the page: the markup renders `hidden`, so what shipped was
  a permanently invisible, dead control, with nothing reporting that an
  override had dropped anything.

### Added

- **The pages list says when a page overrides the theme.** A page whose
  `page_settings` is non-empty now carries a badge naming the groups it
  overrides (`style`, `style:tablet`, `style:mobile`, `header`, `footer`).
  Before this, a per-page style set in the builder was visible in exactly
  one place — the builder's own settings tab — so a stale background from an
  old experiment repainted the page and nothing in the admin said why; the
  one that prompted this was found by querying the database. The badge reads
  the attribute the list query already loads, so it costs no extra query.

- **The site kit now carries other plugins' entities.** The kit was a closed
  list of what this plugin owns — pages, templates, menus, patterns, settings
  — so a site whose pages reference another plugin's entity by handle applied
  cleanly and rendered a hole: the Lekha contact page shipped with a README'd
  "create the form by hand" step, which is the instruction a kit exists to
  make unnecessary. A plugin now registers a `SiteKitSection` (SDK 1.7's
  `RegistersSiteKitSections` contract, collected by core under
  `magna.site_kit.sections`) keyed `plugin:entity`, and export, diff, and
  sync carry it alongside the kit's own keys, inside the same all-or-nothing
  transaction. A section key no enabled plugin claims is skipped and
  reported, never fatal — an older installation reads a newer kit and says
  what it could not place. `magna:site:sync` also finally prints the pattern
  count it already recorded, and both sync and diff name any unknown keys.

- **The theme audit reports what an override drops.** Every other rule in
  `ThemeAuditor` reads one file and judges it alone, which is precisely why
  the failure above was invisible. The audit now also compares an overriding
  block view against the default view it replaces, and warns on two things:
  inline `<script>` in the default that the override drops and may not
  legally reproduce, and a `data-magna-*` hook missing from the override —
  the one remaining way a repaired block can end up bound to nothing.
  Warnings rather than errors, because dropping either may be deliberate;
  dropping it unknowingly is the bug.

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
