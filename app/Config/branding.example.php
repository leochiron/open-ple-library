<?php

declare(strict_types=1);

/**
 * Branding Configuration Example
 * 
 * Copy this file to branding.php and customize the values for your PLE site.
 * 
 * IMPORTANT: branding.php is excluded from Git, so each deployment needs its own file.
 */

return [
    // Site identity
    'site_name' => 'My PLE Library',
    'site_logo' => '/assets/images/logo.png',  // Path to your logo file
    'site_logo_text' => 'My PLE Library',      // Optional text to display instead of logo
    
    // Color scheme (CSS hex values)
    // Choose colors that represent your brand and ensure good contrast for readability
    'colors' => [
        'primary' => '#F5B400',      // Main accent color (used for highlights, buttons)
        'secondary' => '#E43C78',    // Secondary accent color (used for secondary actions)
        'tertiary' => '#5CB88A',     // Third accent color (additional highlights)
        'structure' => '#3B1C4A',    // Navigation, headers, structural elements
        'text' => '#1A1A1A',         // Main text color (dark gray for readability)
        'background' => '#FFFFFF',   // Page background color
    ],
    
    // Typography (Google Fonts or system fonts)
    // Use CSS font-family format with fallbacks
    'fonts' => [
        'body' => "'Inter', system-ui, -apple-system, sans-serif",       // Body text
        'heading' => "'Poppins', 'Inter', sans-serif",                   // Headings (h1, h2, etc.)
        'ui' => "'Nunito', 'Inter', sans-serif",                         // UI elements (buttons, labels)
    ],
    
    // Google Fonts import URL
    // Generate this at https://fonts.google.com/
    // Set to empty string '' if you're using only system fonts
    'google_fonts_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&family=Nunito:wght@400;600&display=swap',
    
    // Design system values
    'design' => [
        'shadow' => '0 8px 20px rgba(0, 0, 0, 0.06)',   // Box shadow for cards and elements
        'radius' => '12px',                              // Border radius for rounded corners
        'spacing' => '12px',                             // Base spacing unit
    ],
    
    // Footer text
    // Customize with your organization name or copyright info
    'footer_text' => 'My PLE Library · MIT License',

    // Localization
    // Update the list if you add new translations under app/Config/i18n.php
    'default_language' => 'fr',
    'available_languages' => ['fr', 'en', 'eo', 'ar'],
    'language_cookie' => 'ple_lang',
    'language_cookie_ttl' => 60 * 60 * 24 * 30,
    
    // Features - Allow downloading directories
    // Set to false to disable folder downloads
    'allow_directory_download' => true,

    // Features - Show download button for previewable files
    // If true, files like PDF, MP3, MP4 show both 'open' (viewer) and 'download' buttons
    // If false, only 'open' button is shown
    'allow_download_previewable' => true,

    // SEO & Contact
    // Provide a concise description for search engines
    'meta_description' => 'Open PLE Library — accès pédagogique en lecture seule, ressources partagées et projet participatif.',
    // Public contact email used on the login page (mailto link)
    'contact_email' => 'contact@example.org',

    // Password Protection
    // Set passwords to protect access. Leave empty array to disable authentication.
    // Each password grants access to all content.
    'passwords' => [
        // 'password1',
        // 'password2',
    ],

    // Password session
    // Cookie name and TTL (in seconds) for authenticated sessions
    'password_cookie' => 'ple_auth',
    'password_cookie_ttl' => 60 * 60 * 24 * 7, // 7 days

    // Google Drive API (manual sync via /sync URL)
    // 1. Create a project in Google Cloud Console
    // 2. Enable Google Drive API
    // 3. Create Service Account credentials
    // 4. Download JSON credentials file
    // 5. Share your Google Drive folder with the service account email
    'google_drive_credentials' => null, // Path to credentials JSON file, e.g., __DIR__ . '/../../storage/google-credentials.json'
    'google_drive_folder_id' => null,   // Google Drive folder ID (from URL: https://drive.google.com/drive/folders/[FOLDER_ID])
    'google_drive_enabled' => false,    // Enable Google Drive integration
    'sync_password' => null,            // Strong password for /sync endpoint (REQUIRED if enabled)
    'sync_delete_existing' => true,     // Delete local content/ before sync (recommended for clean sync)
];
