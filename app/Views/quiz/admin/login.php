<?php

declare(strict_types=1);

/** @var string $error */
/** @var App\Services\I18nService $i18n */
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">

<section class="card quiz-join-card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($i18n->t('quiz.admin.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <div class="card-body">
        <?php if ($error !== ''): ?>
            <p class="quiz-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <form method="post" action="/quiz-admin/login" class="quiz-join-form">
            <div class="quiz-field">
                <label for="admin-password"><?php echo htmlspecialchars($i18n->t('auth.password_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" id="admin-password" name="admin_password" required class="quiz-input" autocomplete="current-password">
            </div>
            <button type="submit" class="btn primary quiz-submit">
                <?php echo htmlspecialchars($i18n->t('auth.login_button'), ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </form>
    </div>
</section>
