<?php
declare(strict_types=1);
$escapeSubmission = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$submissionPlatform = ($submission['platform'] ?? 'github') === 'gitlab' ? 'GitLab' : 'GitHub';
$submissionHost = $submissionPlatform === 'GitLab' ? 'gitlab.com' : 'github.com';
$submissionPrivateAllowed = !empty($submission['course']['private_allowed']);
$submissionDocument = $submission['course']['explanations_file'] ?? 'EXPLICATIONS.md';
?>
<section class="card cc-remise" id="remise" aria-labelledby="remise-title">
    <h2 id="remise-title">📤 Remettre mon dépôt <?= $escapeSubmission($submissionPlatform) ?></h2>
    <p>Indiquez votre nom, votre prénom et le lien de votre dépôt, puis cliquez sur <strong>Enregistrer ma remise</strong>. Attendez la confirmation ci-dessous.</p>
    <p>Vos fichiers, vos branches et <strong><?= $escapeSubmission($submissionDocument) ?></strong> restent dans votre dépôt <?= $escapeSubmission($submissionPlatform) ?><?= $submissionPrivateAllowed ? ' accessible au formateur' : ' public' ?>. Le formulaire transmet uniquement votre lien au formateur.</p>
    <?php if ($submissionPrivateAllowed): ?><p>Un dépôt privé est accepté si le formateur y a accès. La réception du lien ne fige pas automatiquement la version du dépôt.</p><?php endif; ?>
    <?php if ($submission['receipt'] !== null): $receipt = $submission['receipt']; ?>
    <div role="status" style="padding:1rem;background:#edf8f3;border-left:4px solid #126a55;border-radius:8px;margin:1rem 0">
        <strong>Votre dernière remise a bien été enregistrée.</strong>
        <p><?= $escapeSubmission($receipt['prenom']) ?> <?= $escapeSubmission($receipt['nom']) ?> ·
        <?= $escapeSubmission((new DateTimeImmutable($receipt['received_at']))->format('d/m/Y à H:i:s')) ?> (heure de Paris)</p>
        <p>Référence : <strong><?= $escapeSubmission($receipt['reference']) ?></strong></p>
        <p>Dépôt remis : <a href="<?= $escapeSubmission($receipt['github_url']) ?>" target="_blank" rel="noopener"><?= $escapeSubmission($receipt['github_url']) ?></a></p>
        <p>Conservez cette référence. La réception du lien ne vérifie pas le contenu du dépôt : contrôlez qu’il est <?= $submissionPrivateAllowed ? 'accessible au formateur' : 'public' ?> et complet.</p>
    </div>
    <?php endif; ?>
    <?php if ($submission['errors'] !== []): ?>
    <div role="alert" style="padding:1rem;background:#fff1f0;border-left:4px solid #b42318;border-radius:8px;margin:1rem 0">
        <strong>Cet envoi n’a pas été enregistré.</strong>
        <ul><?php foreach ($submission['errors'] as $message): ?><li><?= $escapeSubmission($message) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <form method="post" action="<?= $escapeSubmission($submission['action']) ?>#remise" style="display:grid;gap:1rem">
        <input type="hidden" name="cc_submission" value="1">
        <input type="hidden" name="submission_csrf" value="<?= $escapeSubmission($submission['csrf']) ?>">
        <input type="hidden" name="submission_nonce" value="<?= $escapeSubmission($submission['nonce']) ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem">
            <div style="display:grid;gap:.4rem">
                <label for="remise-nom">Nom</label>
                <input id="remise-nom" name="nom" type="text" required maxlength="80" autocomplete="family-name" value="<?= $escapeSubmission($submission['values']['nom']) ?>" style="padding:.75rem;border:1px solid #aebdb7;border-radius:8px;min-width:0;font-size:1rem">
            </div>
            <div style="display:grid;gap:.4rem">
                <label for="remise-prenom">Prénom</label>
                <input id="remise-prenom" name="prenom" type="text" required maxlength="80" autocomplete="given-name" value="<?= $escapeSubmission($submission['values']['prenom']) ?>" style="padding:.75rem;border:1px solid #aebdb7;border-radius:8px;min-width:0;font-size:1rem">
            </div>
        </div>
        <label for="remise-url">URL du dépôt <?= $escapeSubmission($submissionPlatform) ?></label>
        <input id="remise-url" name="github_url" type="url" required maxlength="300" autocomplete="off" placeholder="https://<?= $escapeSubmission($submissionHost) ?>/votre-compte/votre-depot" value="<?= $escapeSubmission($submission['values']['github_url']) ?>" aria-describedby="remise-url-help" style="padding:.75rem;border:1px solid #aebdb7;border-radius:8px;min-width:0;font-size:1rem">
        <p id="remise-url-help" style="margin:0">Collez le lien du dépôt, pas celui de votre profil. Si vous corrigez une remise, envoyez le formulaire de nouveau : les envois précédents restent conservés.</p>
        <button type="submit" style="justify-self:start;padding:.85rem 1.25rem;background:#126a55;color:#fff;border:0;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer">Enregistrer ma remise</button>
    </form>
    <p style="font-size:.9rem;margin-top:1.25rem">Ces informations sont conservées dans un dossier privé du serveur et utilisées par votre enseignant pour la correction.</p>
</section>
