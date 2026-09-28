# Changelog

All notable changes to Magna Pages are recorded here.

This project is in alpha. Until 1.0, breaking changes can land in any release, and
there is no supported upgrade path between alpha versions.

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
