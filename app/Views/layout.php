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
$cookieConsentJsPath = $docRoot !== '' ? $docRoot . $assetBase . '/js/cookie-consent.js' : __DIR__ . '/../../public/assets/js/cookie-consent.js';
$cookieConsentJsVersion = file_exists($cookieConsentJsPath) ? filemtime($cookieConsentJsPath) : time();
$cssHref = $assetBase . '/css/main.css?v=' . $cssVersion;
$styleDebug = isset($_GET['style_debug']) && (string)$_GET['style_debug'] === '1';

// Resolve logo path so it works whether assets are under /assets or /public/assets
$siteLogo = $config['branding']['site_logo'] ?? '';
if ($siteLogo !== '' && filter_var($siteLogo, FILTER_VALIDATE_URL)) {
    $logoSrc = $siteLogo;
} elseif ($siteLogo !== '' && strpos($siteLogo, '/assets/') === 0) {
    $logoSrc = $assetBase . substr($siteLogo, strlen('/assets'));
} elseif ($siteLogo !== '' && $siteLogo[0] === '/') {
    $logoSrc = $siteLogo;
} elseif ($siteLogo !== '') {
    $logoSrc = $assetBase . '/images/' . ltrim($siteLogo, '/');
} else {
    $logoSrc = $assetBase . '/images/logo.png';
}

$logoFilePath = $docRoot !== '' ? $docRoot . $logoSrc : __DIR__ . '/../../public' . $logoSrc;
if ($logoSrc !== null && $docRoot !== '' && !filter_var($logoSrc, FILTER_VALIDATE_URL) && !is_file($logoFilePath)) {
    $logoSrc = null; // fallback to text when logo is missing
}

$headerBrandModeRaw = (string)($config['branding']['header_brand_mode'] ?? 'auto');
$allowedHeaderBrandModes = ['auto', 'logo', 'text', 'logo_text'];
$headerBrandMode = in_array($headerBrandModeRaw, $allowedHeaderBrandModes, true) ? $headerBrandModeRaw : 'auto';
$brandText = trim((string)($config['branding']['site_logo_text'] ?? ''));
if ($brandText === '') {
    $brandText = (string)($config['app_name'] ?? 'PLE');
}

$hasLogo = $logoSrc !== null;
$showLogo = false;
$showText = false;

if ($headerBrandMode === 'auto') {
    // Legacy behavior: prefer text when configured, otherwise use logo.
    $showText = !empty($config['branding']['site_logo_text']) || !$hasLogo;
    $showLogo = !$showText && $hasLogo;
} elseif ($headerBrandMode === 'logo') {
    $showLogo = $hasLogo;
    $showText = !$hasLogo;
} elseif ($headerBrandMode === 'text') {
    $showText = true;
} elseif ($headerBrandMode === 'logo_text') {
    $showLogo = $hasLogo;
    $showText = true;
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18n->getLanguage(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
        $metaDescription = $config['branding']['meta_description'] ?? null;
        if (!$metaDescription) {
            // Fallback description using site name
            $metaDescription = ($config['app_name'] ?? 'PLE') . ' — ressources pédagogiques partagées et accès en lecture.';
        }
    ?>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($assetBase . '/images/favicon/favicon-96x96.png', ENT_QUOTES, 'UTF-8'); ?>" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($assetBase . '/images/favicon/favicon.svg', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($assetBase . '/images/favicon/favicon.ico', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($assetBase . '/images/favicon/apple-touch-icon.png', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="manifest" href="<?php echo htmlspecialchars($assetBase . '/images/favicon/site.webmanifest', ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (!empty($config['branding']['google_fonts_url'])): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?php echo htmlspecialchars($config['branding']['google_fonts_url'], ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
    <?php endif; ?>
    <?php if ($styleDebug): ?>
    <style>
        .style-debug-panel {
            position: fixed;
            right: 12px;
            bottom: 12px;
            width: min(560px, calc(100vw - 24px));
            max-height: 70vh;
            overflow: auto;
            z-index: 2000;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            padding: 12px;
            font: 12px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            color: #1a1a1a;
        }
        .style-debug-panel h2 {
            margin: 0 0 8px;
            font-size: 13px;
        }
        .style-debug-panel table {
            width: 100%;
            border-collapse: collapse;
        }
        .style-debug-panel td {
            border-top: 1px solid rgba(0, 0, 0, 0.08);
            padding: 5px 4px;
            vertical-align: top;
        }
        .style-debug-panel td:first-child {
            width: 165px;
            font-weight: 700;
        }
    </style>
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
            --font-body: <?php echo htmlspecialchars($config['branding']['fonts']['body'], ENT_NOQUOTES, 'UTF-8'); ?>;
            --font-heading: <?php echo htmlspecialchars($config['branding']['fonts']['heading'], ENT_NOQUOTES, 'UTF-8'); ?>;
            --font-ui: <?php echo htmlspecialchars($config['branding']['fonts']['ui'], ENT_NOQUOTES, 'UTF-8'); ?>;
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<header class="app-header">
    <!-- Logout button (visible only if authenticated and auth is enabled) -->
    <?php global $auth; if (isset($auth) && $auth->isEnabled() && $auth->isAuthenticated()): ?>
        <form method="get" style="position: absolute; top: 24px; left: 16px; margin: 0;">
            <button type="submit" name="logout" value="1" style="background: none; border: none; color: rgba(0, 0, 0, 0.4); cursor: pointer; font-size: 0.85rem; padding: 0; text-decoration: none; transition: color 0.2s; font-family: var(--font-body);">
                <?php echo htmlspecialchars($i18n->t('auth.logout'), ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </form>
    <?php endif; ?>

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
        <a href="<?php echo htmlspecialchars(buildUrl(['path' => '']), ENT_QUOTES, 'UTF-8'); ?>" class="brand-link brand-link--<?php echo htmlspecialchars($headerBrandMode, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($showLogo): ?>
                <img src="<?php echo htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8'); ?>" class="logo">
            <?php endif; ?>
            <?php if ($showText): ?>
                <span class="brand-text"><?php echo htmlspecialchars($brandText, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </a>
    </div>
<main class="content-area">
    <?php echo $content; ?>
</main>
<footer class="app-footer">
    <p><?php echo htmlspecialchars($config['branding']['footer_text'], ENT_QUOTES, 'UTF-8'); ?></p>
    <p class="footer-github">
        <a href="https://github.com/leochiron/open-ple-library" target="_blank" rel="noopener noreferrer">
            GitHub
        </a>
    </p>
</footer>
<div id="cookie-consent-banner" class="cookie-consent-banner" hidden aria-live="polite" aria-label="Bannière de consentement aux cookies">
    <div class="cookie-consent-banner__content">
        <p class="cookie-consent-banner__message"><?php echo htmlspecialchars($i18n->t('cookies.banner.message'), ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="cookie-consent-banner__actions">
            <button id="cookie-consent-accept" type="button" class="btn primary"><?php echo htmlspecialchars($i18n->t('cookies.banner.accept'), ENT_QUOTES, 'UTF-8'); ?></button>
            <button id="cookie-consent-decline" type="button" class="btn ghost"><?php echo htmlspecialchars($i18n->t('cookies.banner.decline'), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
    </div>
</div>
<?php if ($styleDebug): ?>
<aside class="style-debug-panel" id="style-debug-panel" aria-live="polite">
    <h2>Style Debug (`style_debug=1`)</h2>
    <table>
        <tr><td>Time</td><td><?php echo htmlspecialchars(date('c'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>Document root</td><td><?php echo htmlspecialchars($docRoot === '' ? '(empty)' : $docRoot, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>Asset base</td><td><?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>CSS href</td><td><?php echo htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>CSS file exists</td><td><?php echo htmlspecialchars(is_file($cssPath) ? 'yes' : 'no', ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>Google fonts URL</td><td><?php echo htmlspecialchars((string)($config['branding']['google_fonts_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>Config font body</td><td><?php echo htmlspecialchars((string)($config['branding']['fonts']['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>Config font heading</td><td><?php echo htmlspecialchars((string)($config['branding']['fonts']['heading'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>Config font ui</td><td><?php echo htmlspecialchars((string)($config['branding']['fonts']['ui'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>CSS var --font-body</td><td id="dbg-font-body-var">...</td></tr>
        <tr><td>CSS var --font-heading</td><td id="dbg-font-heading-var">...</td></tr>
        <tr><td>CSS var --font-ui</td><td id="dbg-font-ui-var">...</td></tr>
        <tr><td>Computed body font</td><td id="dbg-font-body-computed">...</td></tr>
        <tr><td>Computed h1 font</td><td id="dbg-font-h1-computed">...</td></tr>
        <tr><td>Computed select font</td><td id="dbg-font-select-computed">...</td></tr>
        <tr><td>Google stylesheet link</td><td id="dbg-google-link">...</td></tr>
        <tr><td>`document.fonts`</td><td id="dbg-document-fonts">...</td></tr>
    </table>
</aside>
<?php endif; ?>
<script>
    window.PLE_GA_MEASUREMENT_ID = <?php echo json_encode($config['branding']['ga_measurement_id'] ?? ''); ?>;
</script>
<script src="<?php echo htmlspecialchars($assetBase . '/js/main.js?v=' . $jsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($assetBase . '/js/cookie-consent.js?v=' . $cookieConsentJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($styleDebug): ?>
<script>
    (function () {
        function setText(id, value) {
            var el = document.getElementById(id);
            if (el) {
                el.textContent = String(value);
            }
        }

        var rootStyle = getComputedStyle(document.documentElement);
        setText('dbg-font-body-var', rootStyle.getPropertyValue('--font-body').trim() || '(empty)');
        setText('dbg-font-heading-var', rootStyle.getPropertyValue('--font-heading').trim() || '(empty)');
        setText('dbg-font-ui-var', rootStyle.getPropertyValue('--font-ui').trim() || '(empty)');
        setText('dbg-font-body-computed', getComputedStyle(document.body).fontFamily);

        var firstH1 = document.querySelector('h1');
        setText('dbg-font-h1-computed', firstH1 ? getComputedStyle(firstH1).fontFamily : '(no h1 found)');

        var select = document.getElementById('language');
        setText('dbg-font-select-computed', select ? getComputedStyle(select).fontFamily : '(no #language select found)');

        var googleLink = document.querySelector('link[href*="fonts.googleapis.com"]');
        setText('dbg-google-link', googleLink ? ('present: ' + googleLink.getAttribute('href')) : 'missing');

        if (!document.fonts) {
            setText('dbg-document-fonts', 'unsupported in this browser');
            return;
        }

        Promise.all([document.fonts.ready]).then(function () {
            var checks = [
                'Inter: ' + document.fonts.check('16px "Inter"'),
                'Poppins: ' + document.fonts.check('16px "Poppins"'),
                'Nunito: ' + document.fonts.check('16px "Nunito"')
            ];
            setText('dbg-document-fonts', checks.join(' | '));
        }).catch(function (err) {
            setText('dbg-document-fonts', 'error: ' + (err && err.message ? err.message : err));
        });
    })();
</script>
<?php endif; ?>
</body>
</html>
