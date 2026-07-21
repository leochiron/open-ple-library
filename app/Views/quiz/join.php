<?php

declare(strict_types=1);

/** @var string $error */
/** @var App\Services\I18nService $i18n */
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">

<?php if (($config['branding']['quiz']['show_admin_link'] ?? true) === true): ?>
    <nav class="quiz-entry-actions" aria-label="<?php echo htmlspecialchars($i18n->t('quiz.join.admin_link'), ENT_QUOTES, 'UTF-8'); ?>">
        <a href="/quiz-admin" class="btn ghost quiz-admin-link">
            <?php echo htmlspecialchars($i18n->t('quiz.join.admin_link'), ENT_QUOTES, 'UTF-8'); ?>
        </a>
    </nav>
<?php endif; ?>

<section class="card quiz-join-card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($i18n->t('quiz.join.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <div class="card-body">
        <?php if ($error !== ''): ?>
            <p class="quiz-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <form method="post" action="/quiz/join" class="quiz-join-form" autocomplete="off">
            <div class="quiz-field">
                <label for="quiz-pin"><?php echo htmlspecialchars($i18n->t('quiz.join.pin_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" id="quiz-pin" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                       placeholder="123456" required class="quiz-input quiz-input--big">
            </div>
            <div class="quiz-field">
                <label for="quiz-code"><?php echo htmlspecialchars($i18n->t('quiz.join.code_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" id="quiz-code" name="code" maxlength="5" placeholder="A2B3C"
                       required class="quiz-input quiz-input--big quiz-input--code">
            </div>

            <div class="quiz-rules">
                <h2><?php echo htmlspecialchars($i18n->t('quiz.rules.title'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <ul>
                    <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item1'), ENT_QUOTES, 'UTF-8'); ?></li>
                    <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item2'), ENT_QUOTES, 'UTF-8'); ?></li>
                    <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item5'), ENT_QUOTES, 'UTF-8'); ?></li>
                    <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item3'), ENT_QUOTES, 'UTF-8'); ?></li>
                    <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item4'), ENT_QUOTES, 'UTF-8'); ?></li>
                </ul>
            </div>

            <label class="quiz-consent">
                <input type="checkbox" name="consent" value="1" required>
                <span><?php echo htmlspecialchars($i18n->t('quiz.join.consent'), ENT_QUOTES, 'UTF-8'); ?></span>
            </label>

            <button type="submit" class="btn primary quiz-submit">
                <?php echo htmlspecialchars($i18n->t('quiz.join.submit'), ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </form>
    </div>
</section>
