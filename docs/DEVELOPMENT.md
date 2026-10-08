# Development

The `vereinsflieger_login/` directory is the installable app. It uses Nextcloud framework services and has no bundled third-party runtime dependencies. PHP source follows PSR-12; formatting rules are in `.php-cs-fixer.dist.php`.

## Local checks

Requires PHP 8.3+, `mbstring`, `pdo_sqlite`, Python 3 and Node 20+. SQLite is used only for isolated tests; the installed app uses Nextcloud’s configured database.

```sh
php tools/check.php
python tools/l10n.py --check
npm install
npm run check:js
php tools/preview.php > /tmp/vereinsflieger_login-preview.html
npm run test:ui -- /tmp/vereinsflieger_login-preview.html
python tools/package.py
```

Browser checks use Playwright. Install its Chromium browser or set `VF_BROWSER_PATH` to a suitable local Chromium executable. Generated previews, screenshots, fixtures and test outputs belong outside the repository.

`tools/package.py` builds a deterministic archive containing only the app directory and writes its checksum into ignored `dist/`.

## Translations

Use English source messages and Nextcloud’s `IL10N`/`OC.L10N` APIs. Edit `vereinsflieger_login/l10n/de.json`, then run `python tools/l10n.py`. This regenerates the German, German (Germany) and English JSON/JavaScript catalogs and checks parameter placeholders and source-message coverage. Do not translate provider role names or account/group IDs.

## Nextcloud runtime checks

Native session, two-factor and token APIs are isolated in the session adapters. Test each supported Nextcloud major version before release; a source comparison is not a runtime acceptance test.

The CLI and browser helpers in `tools/` and `tests/runtime-*.cjs` use disposable fixtures. Run configuration-changing helpers only on an isolated instance without operator configuration. `tools/nextcloud-protection-runtime.php` uses separate synthetic counter keys without provider calls or application configuration changes. Browser helpers take a protected external JSON fixture with temporary `uid`, `password` and `url`; never commit this fixture.

For source comparison, provide downloaded official server sources under version-tag directories to `tools/compatibility.php`. Keep downloaded sources and generated comparison output outside the repository.

Release candidates also require real provider authentication, both two-factor paths, local emergency access, linked-account local password rejection, remember cookies, app passwords, WebDAV and complete desktop/mobile login flows. Never use production member credentials in fixtures.

## Licensing and releases

Maintain SPDX identifiers and [third-party notices](../vereinsflieger_login/THIRD_PARTY_NOTICES.md). Provider documents, keys, host configuration and internal work notes are not distributable app assets. Bump app metadata, admin version display and changelog together. Nextcloud App Store distribution requires the appropriate app ownership, package signing and publishing process.
