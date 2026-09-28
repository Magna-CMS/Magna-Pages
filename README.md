<h1 align="center">Magna Pages</h1>

<p align="center">
  <strong>A visual page builder for Laravel.</strong><br>
  Drag-and-drop pages, templates, menus and global styles — built on Laravel 13 and Filament 5.
</p>

<p align="center">
  <a href="LICENSE.md"><img alt="License: source-available" src="https://img.shields.io/badge/license-source--available-orange.svg"></a>
  <img alt="Status: alpha" src="https://img.shields.io/badge/status-alpha-red.svg">
  <a href="composer.json"><img alt="PHP ^8.3" src="https://img.shields.io/badge/php-%5E8.3-777bb4.svg"></a>
  <img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13-ff2d20.svg">
  <a href="https://github.com/Magna-CMS"><img alt="Magna CMS" src="https://img.shields.io/badge/Magna%20CMS-%5E1.0-6366f1.svg"></a>
</p>

---

Magna Pages turns a headless [Magna CMS](https://github.com/Magna-CMS) install into a rendered
website. It adds pages, reusable templates, headers and footers, navigation menus, a design-token
system, and a visual builder for putting it all together — the drag-and-drop editing experience people
expect from Webflow or Elementor, running inside a Laravel application you own.

It is a Laravel package. There is no SaaS, no external editor service, and no account to sign up for.
Your content lives in your database and renders through your Blade views.

## ⚠️ This is alpha software

Read this part before you install anything.

Magna Pages is published at **0.1.0-alpha**. It is incomplete and it has bugs — some of them known,
most of them not yet found. Data formats, block schemas and internal APIs will change in ways that
break existing pages. There is no upgrade path between alpha releases.

Use it on a scratch install. Don't put it on a client site yet.

It is published this early because the architecture is worth looking at and discussing, and because
real feedback finds problems faster than another six months in private. Issue reports are welcome.
Polish is not promised.

## What it does

**Visual editing on the real page.** The builder canvas is not a preview or an approximation — it is
your actual rendered page, in an iframe, drawn by the same Blade views a visitor gets. Click a
heading in the canvas and edit it in place.

**Structure.** Pages are a tree of sections, columns and blocks. Drop a row, split it, drag elements
between columns, nest containers.

**Templates and chrome.** Headers and footers are template parts a page can choose, override, or
switch off. Design one header and every page picks it up.

**Menus.** A menu builder with nested items, and a `nav` block that renders any menu you've defined.

**Design tokens and responsive styles.** Global colours, type and spacing as tokens; per-node styles
that can differ per breakpoint. Light and dark are emitted in one stylesheet, so every visitor is
served identical bytes and the page stays cacheable.

**Revisions, locks and review.** Every change is a revision you can preview and restore. Two editors
cannot silently overwrite each other. Editors without publish rights can request publication instead.

**Checks built in.** Accessibility findings and performance notes are computed for the page you are
editing, in the builder, rather than discovered after launch.

**Dynamic content.** A `loop` block iterates data sources that plugins register, so an installed
plugin's content lands on a visually built page without the builder knowing anything about it.

## How the canvas works

Most builders maintain two renderers: one that draws the editor preview and one that renders the
published page. They drift, and the drift is where "it looked right in the editor" bugs come from.

Magna Pages has one. The canvas loads the real page through the real route, with the production
renderer, and the server stamps `data-magna-node` attributes onto each node so the editor can map a
click back to a document position. Editing is a JSON Patch sent to the server, classified by what it
touches — content, structure, style, binding — and authorised per capability before it is applied. An
editor with content rights can retype a heading but cannot delete the section it sits in.

The practical consequence: what you see while editing is what ships, because it is the same code
path. The trade-off is that the canvas is a full page render, so it is heavier than a virtual DOM
preview.

## Requirements

- PHP 8.3+
- Laravel 13
- A [Magna CMS](https://github.com/Magna-CMS) install (^1.0)
- Filament 5 (comes with Magna)

No Node.js required to run it. The builder is a Vue 3 + TypeScript application, but the compiled
bundle is committed — you only need Node if you want to rebuild the editor yourself.

## Install

```bash
composer require magna/pages
php artisan magna:plugin:enable magna/pages
php artisan migrate
```

Then open the admin panel, create a page, and choose **Open builder**.

## Blocks

Ships with the standard library: headings, text, images, galleries, buttons, icons, video, HTML,
tables, code, quotes, callouts, file downloads, FAQs, heroes, features, pricing, stats, testimonials,
team, logos, search, containers, spacers and dividers — plus dynamic blocks for menus, entry listings
and plugin data sources.

Plugins add their own. A plugin implements `RegistersBlocks`, ships a block schema and a Blade view,
and its blocks appear in the Add panel with a working inspector — no change to the builder.

```php
public function blocks(): array
{
    return [
        BlockDefinition::fromArray([
            'handle' => 'testimonial-wall',
            'label' => 'Testimonial wall',
            'icon' => 'blocks:grid',
            'category' => 'marketing',
            'fields' => [
                ['handle' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                ['handle' => 'limit', 'type' => 'number', 'label' => 'How many', 'default' => 6],
            ],
        ]),
    ];
}
```

The inspector is driven entirely by that schema, so a third-party block gets a real editing UI
without writing any Vue.

## Known gaps

Being honest about where it stands:

- Repeater fields are new and lightly tested.
- An edit can be silently dropped if you change a setting while the canvas is mid-reload.
- The block library has uneven depth — some blocks are thorough, others are placeholders.
- Theme integration works but is under-documented.
- Test coverage is good on the server, thinner in the editor.

## Tech

Laravel 13 · Filament 5 · Vue 3 · TypeScript · Pinia · Vite · Pest · PHPStan level 9 · Playwright

107 PHP classes, 23 Vue components, 22 Blade views at the time of this release.

## License

Source-available, **not** open source — please don't call it that, and read
[LICENSE.md](LICENSE.md) before using it.

The short version:

- ✅ Run it, modify it for your own site, and **make money with it** — client sites, agency work,
  commercial products built *with* Magna CMS are all fine.
- ❌ Don't copy the code into another product, fork it into a separate distribution, redistribute it,
  or sell it as a builder of its own.

You may earn from what you build. You may not build a product out of this.

Want to do something the license doesn't allow? Open an issue — a commercial license may be
available.

## Links

- [Magna CMS](https://github.com/Magna-CMS/Magna) — the core
- [Magna Plugin SDK](https://github.com/Magna-CMS/Magna-Plugin-SDK) — build your own plugins
- [Magna Blog](https://github.com/Magna-CMS/Blog) · [Magna Docs](https://github.com/Magna-CMS/Magna-Docs) · [Magna SEO](https://github.com/Magna-CMS/Magna-SEO)
