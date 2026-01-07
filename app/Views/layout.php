<?php

declare(strict_types=1);

/** @var string $content */
/** @var App\Services\I18nService $i18n */
/** @var array $config */

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

// Detect asset base path. Default to /assets; fall back to /public/assets if needed.
$assetBase = '/assets';
if ($docRoot !== '' && !is_file($docRoot . '/assets/css/main.css') && is_file($docRoot . '/public/assets/css/main.css')) {
    $assetBase = '/public/assets';
}

// Cache busting: use file modification time as version
$cssPath = $docRoot !== '' ? $docRoot . $assetBase . '/css/main.css' : __DIR__ . '/../../public/assets/css/main.css';
$cssVersion = file_exists($cssPath) ? filemtime($cssPath) : time();

$jsPath = $docRoot !== '' ? $docRoot . $assetBase . '/js/main.js' : __DIR__ . '/../../public/assets/js/main.js';
$jsVersion = file_exists($jsPath) ? filemtime($jsPath) : time();

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18n->getLanguage(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if (!empty($config['branding']['google_fonts_url'])): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?php echo htmlspecialchars($config['branding']['google_fonts_url'], ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
    <?php endif; ?>
    <style>
        :root {
            --primary: <?php echo htmlspecialchars($config['branding']['colors']['primary'], ENT_QUOTES, 'UTF-8'); ?>;
            --secondary: <?php echo htmlspecialchars($config['branding']['colors']['secondary'], ENT_QUOTES, 'UTF-8'); ?>;
            --tertiary: <?php echo htmlspecialchars($config['branding']['colors']['tertiary'], ENT_QUOTES, 'UTF-8'); ?>;
            --structure: <?php echo htmlspecialchars($config['branding']['colors']['structure'], ENT_QUOTES, 'UTF-8'); ?>;
            --text: <?php echo htmlspecialchars($config['branding']['colors']['text'], ENT_QUOTES, 'UTF-8'); ?>;
            --background: <?php echo htmlspecialchars($config['branding']['colors']['background'], ENT_QUOTES, 'UTF-8'); ?>;
            --shadow: <?php echo htmlspecialchars($config['branding']['design']['shadow'], ENT_QUOTES, 'UTF-8'); ?>;
            --radius: <?php echo htmlspecialchars($config['branding']['design']['radius'], ENT_QUOTES, 'UTF-8'); ?>;
            --spacing: <?php echo htmlspecialchars($config['branding']['design']['spacing'], ENT_QUOTES, 'UTF-8'); ?>;
            --font-body: <?php echo htmlspecialchars($config['branding']['fonts']['body'], ENT_QUOTES, 'UTF-8'); ?>;
            --font-heading: <?php echo htmlspecialchars($config['branding']['fonts']['heading'], ENT_QUOTES, 'UTF-8'); ?>;
            --font-ui: <?php echo htmlspecialchars($config['branding']['fonts']['ui'], ENT_QUOTES, 'UTF-8'); ?>;
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/css/main.css?v=' . $cssVersion, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<header class="app-header">
    <div class="language-switcher">
        <label for="language" class="sr-only"><?php echo htmlspecialchars($i18n->t('nav.language'), ENT_QUOTES, 'UTF-8'); ?></label>
        <select id="language" name="lang" aria-label="<?php echo htmlspecialchars($i18n->t('nav.language'), ENT_QUOTES, 'UTF-8'); ?>">
            <?php foreach ($i18n->getLanguages() as $lang): ?>
                <option value="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $lang === $i18n->getLanguage() ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($i18n->t('language.' . $lang), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="brand">
        <a href="<?php echo htmlspecialchars(buildUrl(['path' => '']), ENT_QUOTES, 'UTF-8'); ?>" class="brand-link">
            <?php if (!empty($config['branding']['site_logo_text'])): ?>
                <span class="brand-text"><?php echo htmlspecialchars($config['branding']['site_logo_text'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php else: ?>
                <img src="<?php echo htmlspecialchars($config['branding']['site_logo'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8'); ?>" class="logo">
            <?php endif; ?>
        </a>
    </div>
</header>
<main class="content-area">
    <?php echo $content; ?>
</main>
<footer class="app-footer">
    <p><?php echo htmlspecialchars($config['branding']['footer_text'], ENT_QUOTES, 'UTF-8'); ?></p>
</footer>
<script src="<?php echo htmlspecialchars($assetBase . '/js/main.js?v=' . $jsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
