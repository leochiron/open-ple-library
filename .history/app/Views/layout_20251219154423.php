<?php

declare(strict_types=1);

/** @var string $content */
/** @var App\Services\I18nService $i18n */
/** @var array $config */

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

// Detect asset base path. If document root contains assets/, use it; else fall back to /public/assets.
$assetBase = '/assets';
if ($docRoot !== '') {
    if (is_file($docRoot . '/assets/css/main.css')) {
        $assetBase = '/assets';
    } elseif (is_file($docRoot . '/public/assets/css/main.css')) {
        $assetBase = '/public/assets';
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18n->getLanguage(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&family=Nunito:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/css/main.css', ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<header class="app-header">
    <div class="brand">
        <div class="logo" aria-hidden="true">📚</div>
        <div>
            <div class="brand-title"><?php echo htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="brand-subtitle"><?php echo htmlspecialchars($i18n->t('app.subtitle'), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>
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
</header>
<main class="content-area">
    <?php echo $content; ?>
</main>
<footer class="app-footer">
    <p><?php echo htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8'); ?> · MIT</p>
</footer>
<script src="<?php echo htmlspecialchars($assetBase . '/js/main.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
