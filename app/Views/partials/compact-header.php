<?php declare(strict_types=1); ?>
<header class="compact-header">
    <div class="compact-header__inner">
        <a class="compact-header__brand" href="<?= htmlspecialchars(buildUrl(['path' => '']), ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($showLogo): ?><img class="compact-header__logo" src="<?= $escapeTool($logoSrc) ?>" alt="<?= $escapeTool($config['app_name']) ?>"><?php endif; ?>
            <span class="compact-header__identity">
                <span class="compact-header__url"><?= $escapeTool($displayHost !== '' ? $displayHost : $brandText) ?></span>
                <?php if ($showText && $displayHost !== ''): ?><span class="compact-header__title"><?= $escapeTool($brandText) ?></span><?php endif; ?>
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
