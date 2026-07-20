<?php

declare(strict_types=1);

/**
 * Printable code slips — standalone page, ready to print and cut.
 *
 * @var array $session
 * @var array $students
 * @var App\Services\I18nService $i18n
 */
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18n->getLanguage(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars($i18n->t('quiz.admin.codes_title'), ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">
</head>
<body class="quiz-codes-page">
    <p class="quiz-no-print">
        <button onclick="window.print()" class="btn primary"><?php echo htmlspecialchars($i18n->t('quiz.admin.codes_print'), ENT_QUOTES, 'UTF-8'); ?></button>
        <a href="/quiz-admin/session?id=<?php echo (int)$session['id']; ?>"><?php echo htmlspecialchars($i18n->t('nav.back'), ENT_QUOTES, 'UTF-8'); ?></a>
    </p>
    <h1><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="quiz-codes-grid">
        <?php foreach ($students as $st): ?>
            <div class="quiz-code-slip">
                <div class="quiz-code-slip__name"><?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="quiz-code-slip__code"><?php echo htmlspecialchars($st['code'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
