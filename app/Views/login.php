<?php

declare(strict_types=1);

/** @var string $content */
/** @var App\Services\I18nService $i18n */
/** @var array $config */
/** @var string $error */

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

// Detect asset base path
$assetBase = '/assets';
if ($docRoot !== '' && !is_file($docRoot . '/assets/css/main.css') && is_file($docRoot . '/public/assets/css/main.css')) {
    $assetBase = '/public/assets';
}

// Cache busting
$cssPath = $docRoot !== '' ? $docRoot . $assetBase . '/css/main.css' : __DIR__ . '/../../public/assets/css/main.css';
$cssVersion = file_exists($cssPath) ? filemtime($cssPath) : time();

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
        
        .login-container {
            max-width: 550px;
            margin: 5vh auto;
            padding: calc(var(--spacing) * 4);
            border: none;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            background: #fff;
        }
        
        .login-form {
            display: flex;
            flex-direction: column;
            gap: calc(var(--spacing) * 3);
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: calc(var(--spacing) * 1.5);
        }
        
        label {
            font-weight: 600;
            color: var(--structure);
            font-size: 0.95rem;
        }
        
        input[type="password"] {
            padding: calc(var(--spacing) * 2);
            border: 1px solid #ddd;
            border-radius: calc(var(--radius) / 2);
            font-size: 1rem;
            font-family: var(--font-body);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(245, 180, 0, 0.1);
        }
        
        .login-form button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: calc(var(--spacing) * 1.5) calc(var(--spacing) * 2.5);
            background: linear-gradient(90deg, var(--secondary), var(--structure));
            color: white;
            border: none;
            border-radius: calc(var(--radius) / 2);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            font-family: var(--font-ui);
            transition: transform 0.12s ease, box-shadow 0.12s ease;
            box-shadow: var(--shadow);
        }
        
        .login-form button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }
        
        .login-form button:focus-visible {
            outline: 3px solid rgba(228, 60, 120, 0.35);
            outline-offset: 2px;
        }
        
        .error-message {
            padding: calc(var(--spacing) * 1.5);
            background-color: #ffebee;
            color: #c62828;
            border-radius: calc(var(--radius) / 2);
            border-left: 4px solid #c62828;
            margin-bottom: calc(var(--spacing) * 1);
        }
        
        .language-switcher {
            position: absolute;
            top: var(--spacing);
            right: var(--spacing);
        }
        
        .language-switcher select {
            padding: calc(var(--spacing) / 2);
            border: 1px solid #ddd;
            border-radius: calc(var(--radius) / 2);
            background-color: white;
            cursor: pointer;
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/css/main.css?v=' . $cssVersion, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body style="display: flex; flex-direction: column; min-height: 100vh; background-color: var(--background);">

<div class="language-switcher">
    <label for="language" class="sr-only"><?php echo htmlspecialchars($i18n->t('nav.language'), ENT_QUOTES, 'UTF-8'); ?></label>
    <select id="language" name="lang" onchange="location.href = location.pathname + '?lang=' + this.value;">
        <?php foreach ($i18n->getLanguages() as $lang): ?>
            <option value="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $lang === $i18n->getLanguage() ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($i18n->t('language.' . $lang), ENT_QUOTES, 'UTF-8'); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div style="flex: 1; display: flex; align-items: center; justify-content: center;">
    <div class="login-container">
        <h1 style="text-align: center; color: var(--structure); margin-bottom: calc(var(--spacing) * 4); margin-top: 0; font-family: var(--font-heading);">
            <?php echo htmlspecialchars($i18n->t('auth.password_label'), ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        
        <form method="post" class="login-form">
            <div class="form-group">
                <input type="password" id="password" name="password" placeholder="<?php echo htmlspecialchars($i18n->t('auth.password_label'), ENT_QUOTES, 'UTF-8'); ?>" required autofocus>
            </div>
            <button type="submit"><?php echo htmlspecialchars($i18n->t('auth.login_button'), ENT_QUOTES, 'UTF-8'); ?></button>
        </form>
    </div>
</div>

<footer style="text-align: center; padding: var(--spacing); color: rgba(0, 0, 0, 0.5); font-size: 0.85rem;">
</footer>

</body>
</html>
