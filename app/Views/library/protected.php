<?php
declare(strict_types=1);
$accessIsProject = ($accessType ?? '') === 'Projet';
?>
<section style="max-width:620px;margin:3rem auto;padding:2rem;border:1px solid var(--primary);border-radius:16px;background:#fff;box-shadow:0 8px 30px rgba(0,0,0,.06)">
    <p style="color:var(--primary);font-weight:700;margin-top:0"><?= htmlspecialchars($accessLabel ?? 'Contrôle continu', ENT_QUOTES, 'UTF-8') ?></p>
    <h1 style="font-size:1.65rem;margin:.5rem 0 1rem"><?= $accessIsProject ? 'Projet' : 'Contrôle continu' ?> — Accès protégé</h1>
    <p><?= $accessIsProject ? 'Le sujet et les données sont réservés au lancement du projet.' : 'Le sujet et la base de code sont réservés au lancement du contrôle continu.' ?></p>
    <p>Votre enseignant vous communiquera le mot de passe au début de l’épreuve.</p>
    <?php if ($error !== ''): ?>
    <p role="alert" style="padding:1rem;border-left:4px solid #b42318;background:#fff1f0;color:#8b1c13"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($accessAction, ENT_QUOTES, 'UTF-8') ?>" style="display:grid;gap:1rem;margin:1.5rem 0">
        <input type="hidden" name="cc_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label for="cc-password" style="font-weight:600"><?= $accessIsProject ? 'Mot de passe du projet' : 'Mot de passe du contrôle continu' ?></label>
        <input id="cc-password" type="password" name="cc_password" required maxlength="256" autocomplete="off" style="width:100%;box-sizing:border-box;padding:.8rem;border:1px solid var(--primary);border-radius:8px;font-size:1rem">
        <button type="submit" style="padding:.85rem 1rem;background:var(--primary);border:0;border-radius:8px;color:#fff;font-size:1rem;font-weight:600;cursor:pointer"><?= $accessIsProject ? 'Ouvrir le projet' : 'Ouvrir le contrôle continu' ?></button>
    </form>
    <a href="<?= htmlspecialchars($returnPath ?? '/', ENT_QUOTES, 'UTF-8') ?>">← Revenir à la séance <?= htmlspecialchars($accessLabel ?? '', ENT_QUOTES, 'UTF-8') ?></a>
</section>
