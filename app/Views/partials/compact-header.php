<?php
declare(strict_types=1);
$branding = $config['branding'];
$escapeTool = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$displayHost = parse_url((string)($config['public_base_url'] ?? ''), PHP_URL_HOST) ?: '';
$showContact = !empty($branding['student_contact_email']) && filter_var($branding['student_contact_email'], FILTER_VALIDATE_EMAIL);
$showQuiz = $showQuiz ?? (($branding['show_student_quiz_link'] ?? true) && ($branding['quiz']['enabled'] ?? true));
$brandedHeader = ($branding['header_style'] ?? 'plain') === 'branded';
$headerClasses = 'compact-header' . ($brandedHeader ? ' compact-header--branded' : '') . (!empty($branding['header_logo_separator']) && $showLogo ? ' compact-header--separated' : '');
$headerPalette = $branding['header_palette'] ?? [];
$headerStyle = '';
foreach (['background', 'foreground', 'accent', 'accent-text', 'subtitle'] as $token) {
    if (isset($headerPalette[$token]) && preg_match('/^#[0-9a-fA-F]{3,8}$/D', (string)$headerPalette[$token])) {
        $headerStyle .= '--compact-' . $token . ':' . $headerPalette[$token] . ';';
    }
}
?>
<header class="<?= $escapeTool($headerClasses) ?>"<?= $headerStyle !== '' ? ' style="' . $escapeTool($headerStyle) . '"' : '' ?>>
    <div class="compact-header__inner">
        <a class="compact-header__brand" href="<?= htmlspecialchars(buildUrl(['path' => '']), ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($showLogo): ?><img class="compact-header__logo" src="<?= $escapeTool($logoSrc) ?>" alt="<?= $escapeTool($config['app_name']) ?>"><?php endif; ?>
            <span class="compact-header__identity">
                <span class="compact-header__url"><?= $escapeTool($displayHost !== '' ? $displayHost : $brandText) ?></span>
                <?php if ($showText && $displayHost !== ''): ?><span class="compact-header__title"><?= $escapeTool((string)($branding['header_subtitle'] ?? $brandText)) ?></span><?php endif; ?>
            </span>
        </a>
        <nav class="compact-header__actions" aria-label="Accès utiles">
            <?php if ($showContact): ?><button type="button" class="compact-header__action" id="student-contact-open" aria-haspopup="dialog">Me contacter</button><?php endif; ?>
            <?php if ($showQuiz): ?><a class="compact-header__quiz" href="/quiz">Quiz</a><?php endif; ?>
            <?php if (($branding['show_language_switcher'] ?? true) && count($i18n->getLanguages()) > 1): ?>
            <select class="compact-header__language" id="language" name="lang" aria-label="<?= $escapeTool($i18n->t('nav.language')) ?>">
                <?php foreach ($i18n->getLanguages() as $lang): ?><option value="<?= $escapeTool($lang) ?>"<?= $lang === $i18n->getLanguage() ? ' selected' : '' ?>><?= $escapeTool($i18n->t('language.' . $lang)) ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php global $auth; if (isset($auth) && $auth->isEnabled() && $auth->isAuthenticated()): ?>
            <form method="get"><button class="compact-header__action" type="submit" name="logout" value="1"><?= $escapeTool($i18n->t('auth.logout')) ?></button></form>
            <?php endif; ?>
        </nav>
    </div>
</header>
