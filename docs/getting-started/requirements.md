# Requirements

## Platform

| Requirement | Source |
|---|---|
| Drupal core `^11.2 || ^12` | `conreg.info.yml` and most submodule `*.info.yml` |
| Drupal core `^11.3 || ^12` | `conreg_mailing_list`, `conreg_mailerlite`, `conreg_simplenews` `*.info.yml` — narrower than the rest, due to core plugin-autowiring and entity-attribute features they depend on |
| Contrib module `key` | `conreg.info.yml` dependency — Stripe keys, per-event category keys, and the badge-label-printing API key are stored as Key entities, not plaintext config |
| PHP package `stripe/stripe-php` | `composer.json` |
| PHP extension `gd` | `Drupal\conreg\Service\LabelRenderer`'s always-available rendering backend (`GdLabelCanvas`) - needed wherever ConReg itself runs, not just on print-server devices |
| PHP extension `imagick` (optional) | If loaded, `LabelRenderer` prefers it (`ImagickLabelCanvas`) over GD, since GD mis-decodes emoji and other 4-byte-UTF-8 characters into garbled glyphs; not required - falls back to GD automatically. The Label Printing Settings page shows which backend is actually in use |
| Font file `/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf` | Same reason as `gd`/`imagick` above - `LabelRenderer::FONT_PATH` is hardcoded to this path (the same font the print agent used before rendering moved into ConReg); see `site-building/label-printing.md`. Primary font for Latin/Cyrillic/Greek/etc; has no CJK or emoji glyphs, hence the two fallback fonts below |
| Font package `fonts-noto-cjk` (`NotoSansCJK-Bold.ttc`) | `LabelRenderer::CJK_FONT_PATH` - fallback font for CJK ideographs/kana/hangul, routed to per-run by `SplitsFontRunsTrait`. ~90MB installed; needed wherever ConReg itself runs if badge names may contain CJK text |
| Bundled font `assets/fonts/NotoEmoji-Regular.ttf` (ships with the module, no install step) | `LabelRenderer::EMOJI_FONT_RELATIVE_PATH` - fallback font for emoji/astral-plane codepoints. Bundled rather than a system package since no monochrome emoji font is available as one in common distro repos, and the packaged alternative (Noto Color Emoji) is a color/bitmap format neither GD nor Imagick can use for text rendering. Only actually drawn with when `imagick` is active - GD can't decode astral-plane codepoints at all, regardless of font (see `LabelRenderer::sanitizeText()`). SIL Open Font License - see `assets/fonts/NotoEmoji-LICENSE.txt`. Bundled (not a Composer dependency) because no maintained package for it exists on Packagist; see `assets/fonts/NotoEmoji-PROVENANCE.txt` for exactly where it came from and how to re-fetch it |

## Drupal capabilities used

- Form API and controllers for public/admin workflows.
- Config API with per-event keys (`conreg.settings.{eid}`).
- Database API over custom tables from `hook_schema()`.
- Dynamic permissions via `permission_callbacks`.

## Optional integrations

| Integration | Module |
|---|---|
| Airtable API | `conreg_airtable` |
| ClickUp API | `conreg_clickup` |
| Discord invite bot | `conreg_discord` |
| PlanZ database bridge | `conreg_planz` |
| Mailing-list subscription framework | `conreg_mailing_list` |
| MailerLite API | `conreg_mailerlite` |
| Simplenews subscription linking | `conreg_simplenews` (`MailingListProvider` plugin) — also requires the contrib `simplenews` module |
