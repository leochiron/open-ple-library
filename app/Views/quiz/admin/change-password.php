<?php

declare(strict_types=1);

/** @var array $admin */
/** @var string $error */
/** @var string $csrfToken */
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">
<section class="card quiz-join-card">
    <header class="card-header"><h1>Choisir un nouveau mot de passe</h1></header>
    <div class="card-body">
        <p>Bienvenue <?php echo htmlspecialchars($admin['display_name'], ENT_QUOTES, 'UTF-8'); ?>. Le mot de passe temporaire doit être remplacé.</p>
        <?php if ($error !== ''): ?><p class="quiz-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <form method="post" action="/quiz-admin/change-password" class="quiz-join-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="quiz-field">Mot de passe actuel<input class="quiz-input" type="password" name="current_password" autocomplete="current-password" required></label>
            <label class="quiz-field">Nouveau mot de passe<input class="quiz-input" type="password" name="new_password" minlength="12" autocomplete="new-password" required></label>
            <button class="btn primary" type="submit">Enregistrer</button>
        </form>
    </div>
</section>
