<?php

declare(strict_types=1);

/**
 * Branding Configuration
 * 
 * This file contains all the visual and branding customization for your PLE site.
 * Copy branding.example.php to branding.php and customize the values below.
 */

return [
    // Site identity
    'site_name' => 'Open PLE Library',
    'site_logo' => '/assets/images/logo.png',
    
    // Color scheme (CSS hex values)
    'colors' => [
        'primary' => '#F5B400',      // Main accent color
        'secondary' => '#E43C78',    // Secondary accent color
        'tertiary' => '#5CB88A',     // Third accent color
        'structure' => '#3B1C4A',    // Navigation, headers, structural elements
        'text' => '#1A1A1A',         // Main text color
        'background' => '#FFFFFF',   // Page background color
    ],
    
    // Typography (Google Fonts or system fonts)
    'fonts' => [
        'body' => "'Inter', system-ui, -apple-system, sans-serif",
        'heading' => "'Poppins', 'Inter', sans-serif",
        'ui' => "'Nunito', 'Inter', sans-serif",
    ],
    
    // Google Fonts import URL (leave empty if not using Google Fonts)
    'google_fonts_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&family=Nunito:wght@400;600&display=swap',
    
    // Design system values
    'design' => [
        'shadow' => '0 8px 20px rgba(0, 0, 0, 0.06)',
        'radius' => '12px',
        'spacing' => '12px',
    ],
    
    // Footer text
    'footer_text' => 'Open PLE Library · MIT',
];
