<?php

declare(strict_types=1);

return [
    // Site identity
    'site_name' => 'Cours et Exercices Léo Chiron',
    'site_logo' => '/assets/images/logo.png',
    'site_logo_text' => 'Cours et Exercices Léo Chiron',

    // Color scheme
    'colors' => [
        'primary' => '#F5B400',
        'secondary' => '#E43C78',
        'tertiary' => '#5CB88A',
        'structure' => '#3B1C4A',
        'text' => '#1A1A1A',
        'background' => '#FFFFFF',
    ],

    // Typography
    'fonts' => [
        'body' => "'Inter', system-ui, -apple-system, sans-serif",
        'heading' => "'Poppins', 'Inter', sans-serif",
        'ui' => "'Nunito', 'Inter', sans-serif",
    ],

    // Google Fonts import URL
    'google_fonts_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&family=Nunito:wght@400;600&display=swap',

    // Design system
    'design' => [
        'shadow' => '0 8px 20px rgba(0, 0, 0, 0.06)',
        'radius' => '12px',
        'spacing' => '12px',
    ],

    // Footer text
    'footer_text' => 'Cours et Exercices  Léo Chiron · MIT License',
    
    // Languages configuration
    'default_language' => 'fr',
    'available_languages' => ['fr', 'en'],
    'language_cookie' => 'ple_lang',
    'language_cookie_ttl' => 60 * 60 * 24 * 30, // 30 days
    
    // Features - Allow downloading directories
    'allow_directory_download' => false,
];
