# Open PLE Library

A lightweight, public, read-only PHP interface for managing pedagogical libraries. Content is managed via FTP; the web interface simply reflects the filesystem. Optimized for tablets with full desktop and mobile support.

## What is open source?
- ✅ Code in this repository is released under MIT (see `LICENSE`).
- ⛔ Pedagogical content inside `/content` is **not** open source. Only the code ships with the repository.

## Features
- 📁 **File browsing** — Navigate folders of unlimited depth with breadcrumbs and back navigation
- 👁️ **Content preview** — Inline preview for PDF, MP3, and MP4; direct download for all other files
- 🔒 **Read-only interface** — No database, no authentication by default; content managed purely via FTP
- 🎨 **Fully customizable branding** — Colors, fonts, logos, and site names via simple PHP config
- 🌍 **Multilingual** — Full i18n support (French, English, Esperanto, Arabic); easily add more
- ♿ **Accessible** — WCAG-compliant design with keyboard navigation and screen reader support
- 📱 **Responsive design** — Tablet-optimized, mobile-friendly, desktop-ready
- 🔐 **Security hardened** — Protection against directory traversal; automatic exclusion of hidden/system files
- 🚀 **Zero dependencies** — Pure PHP, no external libraries or build tools required

## Project Structure

```
├── public/                      # Web root (point your hosting document root here)
│   ├── index.php               # Front controller and router
│   ├── debug.php               # Debugging page for path resolution
│   └── assets/
│       ├── css/
│       │   ├── main.css        # Compiled stylesheet
│       │   └── main.scss       # SCSS source
│       ├── js/
│       │   └── main.js         # Client-side interactivity
│       └── images/
│           └── logo.png        # Placeholder for your brand logo
│
├── app/                         # Application code
│   ├── Controllers/
│   │   ├── LibraryController.php    # Handles file/folder browsing
│   │   ├── SyncController.php       # Handles Google Drive sync
│   │   └── ErrorController.php      # Error rendering
│   ├── Services/
│   │   ├── FileSystemService.php    # File operations & directory listing
│   │   ├── SecurityService.php      # Path validation & security
│   │   ├── MimeService.php          # File type detection
│   │   ├── ZipService.php           # Directory compression
│   │   ├── GoogleDriveService.php   # Google Drive integration
│   │   ├── AuthService.php          # Password authentication
│   │   └── I18nService.php          # Localization
│   ├── Views/
│   │   ├── layout.php          # Master template
│   │   ├── login.php           # Password entry form
│   │   └── library/
│   │       ├── index.php       # Root folder view
│   │       ├── folder.php      # Subfolder view with breadcrumbs
│   │       ├── file.php        # File preview/download
│   │       └── redirect.php    # Redirect handler
│   ├── Config/
│   │   ├── config.php          # Core runtime config (auto-generated)
│   │   ├── branding.example.php # Branding template (copy to branding.php)
│   │   ├── branding.php        # Your customization (Git-ignored)
│   │   ├── i18n.php            # Translation strings
│   │   └── README.md           # Configuration guide
│   └── Helpers/
│       ├── url.php             # URL building helpers
│       └── view.php            # Template rendering
│
├── content/                     # Your pedagogical files (Git-ignored)
│   ├── My-Project-1/
│   ├── My-Project-2/
│   └── README.md               # Not served; optional notes
│
├── storage/                     # Runtime data (Google Drive credentials, logs)
│   ├── php-error.log           # PHP error log
│   └── google-credentials.json # Google Drive service account (if using sync)
│
├── vendor/                      # Composer dependencies (Google API client)
├── LICENSE                      # MIT License
└── README.md                    # This file
```

## Quick Start (Local Development)

### Prerequisites
- PHP 8.1+ with standard libraries
- No external dependencies (pure PHP)

### Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/leochiron/open-ple-library.git
   cd open-ple-library
   ```

2. **Create branding configuration:**
   ```bash
   cp app/Config/branding.example.php app/Config/branding.php
   ```

3. **Customize `app/Config/branding.php`** with your site details (see Configuration section below)

4. **Add content files:**
   ```bash
   mkdir -p content
   # Copy your pedagogical files into content/
   ```

5. **Run the local server:**
   ```bash
   php -S localhost:8000 -t public
   ```

6. **Open in browser:**
   ```
   http://localhost:8000
   ```

## Production Deployment

### On Shared Hosting (IONOS, OVH, O2Switch, etc.)

1. **Upload project files:**
   - Use FTP/SFTP to upload all files to your hosting space
   - Keep the project at root level (not inside `public_html`)

2. **Configure document root:**
   - In your hosting control panel, set the web root to point to the `/public` folder
   - Example: If project is at `/home/user/ple`, set document root to `/home/user/ple/public`
   - If your host doesn't allow custom document roots, see the `.htaccess` fallback below

3. **Create branding file:**
   ```bash
   cp app/Config/branding.example.php app/Config/branding.php
   # Edit branding.php via FTP with your editor
   ```

4. **Upload content via FTP:**
   - Create folders in `/content` for your projects
   - Upload your files into these folders
   - Changes appear instantly in the browser

5. **Set file permissions:**
   ```bash
   chmod 755 content/           # Directory readable by web server
   chmod 644 storage/           # Allow logs to be written
   ```

### Document Root Fallback (.htaccess)

If your host doesn't support custom document roots, the root `index.php` includes a shim that routes to `public/index.php`:

```php
// In /index.php (at project root)
require __DIR__ . '/public/index.php';
```

This allows the site to work even if your document root is the repository root.

## Configuration Guide

### Creating your `branding.php`

Copy `app/Config/branding.example.php` to `app/Config/branding.php` and customize:

```php
<?php
return [
    // Site Identity
    'site_name' => 'My PLE Library',           // Site title (in H1, SEO)
    'site_logo' => '/assets/images/logo.png',  // Path to logo image
    'site_logo_text' => 'My PLE',              // Alt text for logo
    
    // Color Scheme (CSS hex values)
    'colors' => [
        'primary' => '#F5B400',        // Main accent (buttons, highlights)
        'secondary' => '#E43C78',      // Secondary accent (alternative buttons)
        'tertiary' => '#5CB88A',       // Tertiary accent (additional highlights)
        'structure' => '#3B1C4A',      // Navigation, borders, headers
        'text' => '#1A1A1A',           // Body text (dark gray)
        'background' => '#FFFFFF',     // Page background
    ],
    
    // Typography (Google Fonts or system fonts)
    'fonts' => [
        'body' => "'Inter', system-ui, -apple-system, sans-serif",
        'heading' => "'Poppins', 'Inter', sans-serif",
        'ui' => "'Nunito', 'Inter', sans-serif",
    ],
    
    // Google Fonts import (generate at https://fonts.google.com/)
    'google_fonts_url' => 'https://fonts.googleapis.com/css2?family=...',
    
    // Design System
    'design' => [
        'shadow' => '0 8px 20px rgba(0, 0, 0, 0.06)',
        'radius' => '12px',
        'spacing' => '12px',
    ],
    
    // Footer
    'footer_text' => 'My PLE Library © 2026',
    
    // Localization
    'default_language' => 'fr',                    // French by default
    'available_languages' => ['fr', 'en', 'eo'],  // Languages to offer
    'language_cookie' => 'ple_lang',              // Cookie name
    'language_cookie_ttl' => 60 * 60 * 24 * 30,  // 30 days
    
    // Features
    'allow_directory_download' => true,  // Allow .zip downloads of folders
    
    // SEO & Contact
    'meta_description' => 'Description for search engines...',
    'contact_email' => 'contact@example.org',
    
    // Optional: Password Protection
    'passwords' => [
        // 'mypassword123',
        // Leave empty [] to disable
    ],
    'password_cookie' => 'ple_auth',
    'password_cookie_ttl' => 60 * 60 * 24 * 7,  // 7 days
    
    // Optional: Google Drive Integration
    'google_drive_credentials' => null,   // Path to credentials JSON
    'google_drive_folder_id' => null,     // Folder ID from Drive URL
    'google_drive_enabled' => false,      // Enable sync
    'sync_password' => null,              // Password for /sync endpoint
    'sync_delete_existing' => true,       // Clear content/ before sync
];
```

### Configuration Files Explained

#### `config.php` (Auto-generated)
This file is automatically generated from `branding.php`. You should **not edit it directly**. It loads branding settings and provides defaults.

#### `branding.php` (Your customization)
- **Created from:** `branding.example.php`
- **Ignored by Git:** Each deployment has its own version
- **Purpose:** All visual and site-specific customization
- **How to edit:** Via FTP editor or local editor + upload via FTP

#### `i18n.php` (Translations)
Contains all translatable strings for supported languages (FR, EN, EO, AR).

To add a new language:
1. Add a new locale object in `i18n.php`
2. Include it in `branding.php` under `available_languages`

Example adding Spanish:
```php
// In i18n.php
'es' => [
    'nav.back' => 'Atrás',
    'file.download' => 'Descargar',
    // ... translate all keys
],

// In branding.php
'available_languages' => ['fr', 'en', 'eo', 'es'],
```

## Content Management (FTP)

The UI is **read-only**. To manage content:

1. **Add files/folders:** Use your FTP client to upload files to `/content`
2. **Rename:** Rename directly in FTP client
3. **Delete:** Delete directly in FTP client
4. **Changes appear instantly** in the browser (no caching)

### Naming Tips
- **Avoid leading/trailing spaces** (may cause filesystem issues)
- **Use descriptive names** with dashes or underscores (no spaces)
- **UTF-8 compatible:** Accents, emoji, and special characters work fine

## Languages & Localization

Supported out-of-the-box:
- 🇫🇷 French (`fr`) — Default
- 🇬🇧 English (`en`)
- 🇫🇷 Esperanto (`eo`)
- 🇸🇦 Arabic (`ar`)

**How it works:**
- User's language preference is stored in a cookie (30-day expiration)
- Selector in header allows switching
- All UI strings come from `app/Config/i18n.php`

**To add a new language:**
1. Open `app/Config/i18n.php`
2. Add a new locale array with translations for every key
3. Update `branding.php`: add language to `available_languages`

## Troubleshooting

### 404 errors on file access
- **Check:** Does the file exist in `/content`?
- **Check permissions:** Ensure `/content` is readable by web server (755)
- **Check naming:** Avoid leading/trailing spaces in filenames
- **Debug:** Visit `/debug?path=FOLDERNAME` to see path resolution details

### Missing branding
- **Solution:** Copy `branding.example.php` to `branding.php` and edit it
- **Remember:** Git ignores `branding.php`; create it on every deployment

### Styles not loading
- **Check:** Is `/public` the actual web root?
- **Check:** Do CSS files exist in `/public/assets/css/`?
- **Check:** Clear browser cache (Ctrl+Shift+Delete)

### Content not appearing
- **Check:** Are files uploaded to `/content`?
- **Check:** Permissions on `/content` (should be 755)
- **Check:** Hidden files (starting with `.`) are automatically excluded

### Language not switching
- **Check:** Is the language in `branding.php` under `available_languages`?
- **Check:** Browser cookies enabled?
- **Check:** Language files in `i18n.php` contain that locale?

## Security Features

- ✅ **Directory traversal protection** — Paths like `../../etc/passwd` are blocked
- ✅ **Hidden file exclusion** — Files starting with `.` are never served
- ✅ **System file exclusion** — `Thumbs.db`, `.DS_Store` etc. ignored
- ✅ **UTF-8 safe** — Proper encoding handling prevents filename exploits
- ✅ **Fuzzy path matching** — Handles directories with trailing spaces automatically
- ✅ **No code execution** — PHP files are served for download, not executed
- ✅ **Optional password protection** — Add `passwords` array to `branding.php` to require login

## Tech Stack

- **Language:** PHP 8.1+
- **No dependencies:** Pure PHP, no Composer required
- **Frontend:** Vanilla JavaScript (no frameworks)
- **Styling:** CSS with optional SCSS source
- **Architecture:** Single front controller + MVC-style routing

## Optional Features

### Google Drive Integration
Sync your Google Drive folder to `/content` via `/sync` endpoint:

1. Create Google Cloud Console project
2. Enable Google Drive API
3. Create Service Account credentials (download JSON)
4. Share Drive folder with service account email
5. Configure in `branding.php`:
   ```php
   'google_drive_credentials' => __DIR__ . '/../../storage/google-credentials.json',
   'google_drive_folder_id' => 'FOLDER_ID_FROM_URL',
   'google_drive_enabled' => true,
   'sync_password' => 'strong-sync-password',
   ```
6. Visit `https://yoursite.com/sync?password=strong-sync-password` to trigger sync

### Password Protection
Add this to `branding.php`:
```php
'passwords' => [
    'mypassword123',
    'anotherpassword',
],
```

Users must enter a password on first visit. Cookie expires after 7 days.

## API Routes

| Route | Method | Purpose |
|-------|--------|---------|
| `/` | GET | Root folder view |
| `/{path}` | GET | Browse folder or preview file |
| `/{path}+download` | GET | Download file or folder as ZIP |
| `/{path}+open` | GET | Force inline preview (no download dialog) |
| `/sync` | GET/POST | Google Drive sync (if enabled) |
| `/debug` | GET | Path resolution debugging tool |

## License

MIT License. See `LICENSE` file for details.

## Contributing

Issues and pull requests welcome on [GitHub](https://github.com/leochiron/open-ple-library).
