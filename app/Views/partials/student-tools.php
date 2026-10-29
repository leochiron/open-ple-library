<?php
declare(strict_types=1);
$branding = $config['branding'];
$compactHeader = ($branding['header_layout'] ?? 'classic') === 'compact';
$contactEmail = $branding['student_contact_email'] ?? '';
$showContact = is_string($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL);
$showQuiz = ($branding['show_student_quiz_link'] ?? true) && \App\Services\ApplicationProfile::fromBranding($branding)->quizEnabled();
$displayOrigin = (string)($config['public_base_url'] ?? '');
$displayHost = parse_url($displayOrigin, PHP_URL_HOST) ?: '';
$escapeTool = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<?php if (!$compactHeader && ($showContact || $showQuiz)): ?>
<nav class="student-tools" aria-label="Accès utiles">
    <?php if ($showContact): ?><button type="button" class="btn ghost" id="student-contact-open" aria-haspopup="dialog">✉ Me contacter</button><?php endif; ?>
    <?php if ($showQuiz): ?><a class="btn ghost" href="/quiz">Quiz</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php if ($showContact): ?>
<dialog id="student-contact-dialog" class="student-contact-dialog" aria-labelledby="student-contact-title">
    <form method="dialog" class="student-contact-close"><button type="submit" class="btn ghost" aria-label="Fermer la fenêtre" autofocus>×</button></form>
    <h2 id="student-contact-title">Me contacter</h2>
    <p>Une question sur un cours ou un projet ? Vous pouvez m’écrire à cette adresse :</p>
    <a class="student-contact-email" href="mailto:<?= $escapeTool($contactEmail) ?>"><?= $escapeTool($contactEmail) ?></a>
    <p>Cliquez sur l’adresse pour ouvrir votre messagerie, ou copiez-la.</p>
    <button type="button" class="btn primary" id="student-contact-copy" data-email="<?= $escapeTool($contactEmail) ?>">Copier l’adresse</button>
    <p id="student-contact-status" role="status" aria-live="polite"></p>
</dialog>
<?php endif; ?>
<?php if (!$compactHeader && !empty($branding['display_site_address']) && $displayHost !== ''): ?>
<div class="site-address-banner"><a href="<?= $escapeTool($displayOrigin) ?>"><?= $escapeTool($displayHost) ?></a></div>
<?php endif; ?>
