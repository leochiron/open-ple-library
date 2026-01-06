# Open PLE Library

A lightweight, public, read-only PHP interface for an immaterial pedagogical library. Content is managed via FTP; the web UI simply reflects the real filesystem. Tablets are the primary target; desktop and smartphones are supported.

## What is open source?
- ✅ Code in this repository is released under MIT (see `LICENSE`).
- ⛔ Pedagogical content inside `/content` is **not** open source and must never be published here. Only the code ships with the repository.

## Features
- Browse folders of unlimited depth with breadcrumbs and back navigation.
- Inline preview for PDF, MP3, and MP4; direct download for everything else.
- No authentication, no database, no admin UI. All content comes from FTP.
- Mobile-first, tablet-optimized UI with accessible controls and language switcher (FR/EN).
- Security guards against directory traversal and hidden/system files.

## Project structure
```
/public              Front controller and assets
/app                 Controllers, services, views, config, helpers
/content             Pedagogical files (managed by FTP, not versioned)
/storage             Reserved for future runtime data (kept empty)
```
The web root of your hosting must point to `/public`.

## Getting started (local)
1. Place your pedagogical files under `content/` (via FTP or locally). Do **not** commit them.
2. Serve the site with any recent PHP runtime. Example: `php -S 0.0.0.0:8000 -t public` from the project root.
3. Open `http://localhost:8000` and browse.

## Deployment Server (shared hosting)
1. Upload the repository to your space (via FTP/SSH). Keep `content/` at the project root.
2. Point the hosting web root to the `public/` folder (IONOS allows custom document root in the control panel; if not, move the contents of `public/` to your configured web directory and update paths in `config.php`).
3. Upload pedagogical files via FTP into `/content`. They appear instantly in the UI.

## FTP-based content management
- The UI is read-only. To add, rename, or delete items, use your FTP client directly in `/content`.
- Hidden/system files (e.g., `.DS_Store`, `Thumbs.db`) are ignored by the UI.
- The app never exposes files outside `/content`.

## Languages
- Default: French.
- Supported: French (`fr`), English (`en`).
- Language can be switched via the header selector. Cookies remember the choice for 30 days.
- To add a language: update `app/Config/i18n.php` with a new locale array and include it in `config.php` under `languages`.

## Tech notes
- No Composer or external JS/CSS dependencies.
- MVC-style layout with a single front controller (`public/index.php`).
- SCSS source is provided alongside compiled CSS.

## License
MIT. See `LICENSE`.
