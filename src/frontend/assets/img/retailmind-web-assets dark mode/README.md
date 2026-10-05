# RetailMind web assets

## Logo selection

- `logos/light/logo-header.png` — standard header on white/light surfaces.
- `logos/light/logo-header@2x.png` — retina version of light header logo.
- `logos/dark/logo-header-small.png` — compact header on dark surfaces.
- `logos/dark/logo-header.png` — standard header on dark surfaces.
- `logos/dark/logo-header@2x.png` — retina version of dark header logo.
- `logo-master.png` — high-resolution source; avoid serving directly.

```html
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="/assets/retailmind/logos/dark/logo-header.png 1x, /assets/retailmind/logos/dark/logo-header@2x.png 2x">
  <img src="/assets/retailmind/logos/light/logo-header.png" srcset="/assets/retailmind/logos/light/logo-header@2x.png 2x" alt="RetailMind" width="300" height="100">
</picture>
```

## Icons

- `icon-512.png` — PWA splash/install icon.
- `icon-192.png` — PWA manifest icon.
- `icon-64.png` — navigation or desktop shortcut icon.
- `apple-touch-icon.png` — iPhone/iPad home-screen icon.
- `favicon-32.png` — browser tab icon.
- `icon-master.png` — high-resolution source; avoid serving directly.

```html
<link rel="icon" type="image/png" sizes="32x32" href="/assets/retailmind/icons/light/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/retailmind/icons/light/apple-touch-icon.png">
```

Use `icons/dark/` when icon appears directly on a dark UI surface. Browser and PWA icons normally use `icons/light/`.

## Landing hero

- `hero/landing-hero.jpg` — desktop, 1200x800.
- `hero/landing-hero-small.jpg` — mobile/tablet, 768x512.
- `hero/landing-hero-master.png` — high-resolution source; avoid serving directly.

```html
<picture>
  <source media="(max-width: 768px)" srcset="/assets/retailmind/hero/landing-hero-small.jpg">
  <img src="/assets/retailmind/hero/landing-hero.jpg" alt="Organized retail inventory with barcode scanner" width="1200" height="800">
</picture>
```
