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
    
    // Features - Allow downloading directories
    // Set to false to disable folder downloads
    'allow_directory_download' => true,
];
