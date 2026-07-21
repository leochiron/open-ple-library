<?php

declare(strict_types=1);

/** @var array $admin */
/** @var array $admins */
/** @var string $flash */
/** @var string $csrfToken */
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">

<section class="card">
    <header class="card-header">
        <h1>Comptes administrateurs</h1>
        <a class="btn ghost" href="/quiz-admin">Retour aux quiz</a>
    </header>
    <div class="card-body">
        <?php if ($flash !== ''): ?><p class="quiz-flash" role="status"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

        <table class="quiz-table">
            <thead><tr><th>Nom</th><th>Courriel</th><th>Rôle</th><th>Statut</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($admins as $account): ?>
                <tr>
                    <td><?php echo htmlspecialchars($account['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($account['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($account['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php if ((int)$account['id'] !== (int)$admin['id']): ?>
                            <form method="post" action="/quiz-admin/users/status" style="display:inline;">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="admin_id" value="<?php echo (int)$account['id']; ?>">
                                <input type="hidden" name="status" value="<?php echo $account['status'] === 'active' ? 'disabled' : 'active'; ?>">
                                <button class="btn ghost quiz-btn-small" type="submit"><?php echo $account['status'] === 'active' ? 'Désactiver' : 'Réactiver'; ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="quiz-admin-grid">
    <section class="card"><div class="card-body">
        <h2>Créer un compte</h2>
        <form method="post" action="/quiz-admin/users/create" class="quiz-admin-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="quiz-field">Nom<input class="quiz-input" name="display_name" required></label>
            <label class="quiz-field">Courriel<input class="quiz-input" type="email" name="email" required></label>
            <label class="quiz-field">Rôle<select class="quiz-input" name="role"><option value="quiz_admin">Administrateur de quiz</option><option value="super_admin">Super-administrateur</option></select></label>
            <label class="quiz-field">Mot de passe temporaire<input class="quiz-input" type="password" name="password" minlength="12" required></label>
            <button class="btn primary" type="submit">Créer le compte</button>
        </form>
    </div></section>

    <section class="card"><div class="card-body">
        <h2>Réinitialiser un mot de passe</h2>
        <form method="post" action="/quiz-admin/users/password" class="quiz-admin-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="quiz-field">Compte<select class="quiz-input" name="admin_id"><?php foreach ($admins as $account): ?><option value="<?php echo (int)$account['id']; ?>"><?php echo htmlspecialchars($account['display_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
            <label class="quiz-field">Mot de passe temporaire<input class="quiz-input" type="password" name="password" minlength="12" required></label>
            <button class="btn ghost" type="submit">Réinitialiser</button>
        </form>

        <h2>Transférer tous les quiz</h2>
        <form method="post" action="/quiz-admin/users/transfer" class="quiz-admin-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="quiz-field">Depuis<select class="quiz-input" name="from_admin_id"><?php foreach ($admins as $account): ?><option value="<?php echo (int)$account['id']; ?>"><?php echo htmlspecialchars($account['display_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
            <label class="quiz-field">Vers<select class="quiz-input" name="to_admin_id"><?php foreach ($admins as $account): ?><?php if ($account['status'] === 'active'): ?><option value="<?php echo (int)$account['id']; ?>"><?php echo htmlspecialchars($account['display_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endif; ?><?php endforeach; ?></select></label>
            <button class="btn ghost" type="submit">Transférer</button>
        </form>
    </div></section>
</div>
