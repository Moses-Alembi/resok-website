# ReSoK — editorial homepage concept

Open `index.html` directly, or serve the website folder and visit `/prototype/editorial/`.

From the website folder (`cpd-admin`), an optional local preview command is:

```sh
python -m http.server 8080 --bind 127.0.0.1
```

Then open http://127.0.0.1:8080/prototype/editorial/.

This independent direction preserves both the production site and the earlier `/prototype/` concept. There are no build dependencies, API calls, forms, authentication changes or database changes. Google Fonts enhance the typography; system fonts are fallbacks. Images are local, reused from `../img/` and the website's assets; keep this folder inside the project.

## Design direction

Warm paper, forest green, a restrained ReSoK red accent, generous editorial headings and documentary photography. Manrope carries the institutional voice; Instrument Serif introduces a human note. Eight chapters follow the brief: Society, Education, Research, Advocacy, Events, Membership, Knowledge and a closing statement. Mobile uses a distinct stacked composition.

## Content and asset provenance

- Identity, vision and PUMZI values: `../../about.html` and the existing ReSoK logo.
- Documentary images: existing optimized photographs in `../img/`, reused without modifying the originals.
- Research programme names: existing ReSoK project content in `../../projects.html`.
- Conference photo and dates: `../../conferences.html` and `../../assets/img/kislhc-2026-hero.jpg`.
- October CME: `../../resok-portal/server/seed-cme-2026-10-01-bronchiectasis-chest-ct.sql`, read only.
- September webinar: existing webinar seed and events content, read only.
- Reading room: existing LIGHT writing workshop and Kiambu health promoter articles; existing guidelines collection.
- Dates are an editorial snapshot for September 2026. No invented statistics, awards or partnerships.

## Review interactions

- Anchor navigation, mobile menu with keyboard handling, and back-to-top.
- Research/community/guidelines reading-room filters with screen-reader status.
- Membership, My ReSoK, Academy and event preview dialogs. No registration or payment is performed.
- Other editorial links open existing local site pages. PHP-backed pages require the existing site server; static layout works by opening the file.
- Subtle one-time photo reveals and hover transitions. Reduced motion disables animation; content remains visible with JavaScript disabled. Interactive previews and filters require JavaScript.

Review the hero, typography, photo crops, pacing and mobile composition before considering any production implementation.

## Validation

Chromium smoke checks passed at 1440, 1024, 768, 390 and 320 pixels with no horizontal overflow. Checks cover mobile navigation, Escape and focus return, preview dialogs, all reading-room filters, image loading, reduced-motion mode, JavaScript-disabled content and browser script errors. Local HTML resource paths and anchors also resolve.

`verify.cjs` reruns these checks against `PROTOTYPE_URL` (default `http://127.0.0.1:8088/prototype/editorial/`). It uses an existing Playwright installation, optionally supplied through `PLAYWRIGHT_MODULE`; it does not add dependencies to the production application. Desktop, mobile and full-page PNG previews are saved alongside the page.
