# Icons

Magna ships a named icon vocabulary. A block definition names an icon, an
`icon` field stores a name, and the registry is the only thing that turns
a name into SVG.

That indirection is the whole security argument. A document holds
`core:star`, never markup, so no stored value can put SVG on a page — and
a name the registry does not know draws nothing rather than guessing.

## Using an icon

Name one in your block's `block.json`, both for the definition's own icon
and for any `icon` field:

```json
{
    "handle": "feature-card",
    "label": "Feature card",
    "icon": "blocks:grid",
    "fields": [
        { "handle": "glyph", "type": "icon", "label": "Icon", "default": "core:star" }
    ]
}
```

The builder draws the definition's icon on its tile in the Add panel, and
renders an `icon` field as a picker — an editor chooses from the
vocabulary rather than typing a name from memory.

In a Blade view, ask the registry:

```php
{!! app(\Magna\Blocks\Icons\IconRegistry::class)
    ->svg($block['data']['glyph'] ?? '', label: null, class: 'my-icon', size: 24) !!}
```

`svg()` returns `null` for a name it does not have, so guard on it rather
than printing an empty string into your markup.

### Labels and assistive technology

An icon beside a label is decoration, and announcing it twice is worse
than not announcing it. `svg()` therefore emits `aria-hidden="true"` by
default. Pass a `label` only when the icon carries meaning of its own —
then it emits `role="img"` and that label instead.

### Size

Icons are stroked in `currentColor` with no fill, so one icon works on any
background, in either colour scheme, at any size. Colour it by setting
`color` on an ancestor — including through a design token, which is what
makes an icon follow the palette into dark mode.

Pass `size` for an intrinsic pixel size. Core block views ship no
stylesheet of their own, and an `<svg>` with only a `viewBox` lays out at
the SVG default of 300×150.

## Shipping your own icons

Register them at boot from your plugin's entry class:

```php
public function boot(): void
{
    app(\Magna\Blocks\Icons\IconRegistry::class)
        ->loadFromDirectory(__DIR__.'/../resources/icons');
}
```

A set file is namespaced by its `set`, so two sets may both offer a
`star` and neither silently wins:

```json
{
    "set": "acme",
    "icons": {
        "rocket": "<path d=\"M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2\"/>"
    }
}
```

Icons are then addressed as `acme:rocket`.

### What the registry will accept

Your icons pay the same inspection core's do, because "we ship the files"
stops being true the moment a plugin registers one:

- **Geometry only.** `path`, `circle`, `ellipse`, `rect`, `line`,
  `polyline`, `polygon`, `g`. Anything else is refused — `use` and `image`
  take a reference, `foreignObject` opens the door to arbitrary HTML, and
  `style` escapes the icon.
- **No event handlers**, no `href`/`src`/`xlink:href`/`style` attributes,
  and no `javascript:` or `data:` URLs.
- **A namespaced, lowercase name**: `set:name`.
- **Geometry drawn on a 24×24 viewBox**, unfilled, so `currentColor` and
  the caller's size both work.

Refusal is silent and per-icon: a plugin shipping one malformed icon
loses that icon, not the site. If an icon of yours does not appear, check
it against the list above.
