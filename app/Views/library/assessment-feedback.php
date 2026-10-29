<?php declare(strict_types=1); ?>
<style>
.assessment-feedback-toolbar{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin:0 0 24px;padding:18px 22px;border:1px solid var(--primary);border-radius:16px;background:#fff}
.assessment-feedback-links,.assessment-feedback-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.assessment-feedback-form label{font-weight:600}.assessment-feedback-form input[type=email]{min-width:230px;padding:11px 12px;border:1px solid var(--primary);border-radius:9px;font:inherit}.assessment-feedback-error{width:100%;margin:0;color:#a32424}
.assessment-feedback-result{padding:26px;margin-bottom:24px}.assessment-feedback-result h1,.feedback-overview h1{font-size:1.65rem}.assessment-feedback-result h2,.feedback-overview h2{font-size:1.2rem;margin-top:26px}.assessment-feedback-result .muted,.feedback-overview .muted{font-size:.9rem;color:var(--text)}.assessment-feedback-result .scores,.feedback-overview .scores{display:flex;gap:14px;flex-wrap:wrap;margin:20px 0}.assessment-feedback-result .score,.feedback-overview .score{background:var(--background,#fff);padding:14px;border-radius:10px;min-width:155px}.assessment-feedback-result .score strong,.feedback-overview .score strong{display:block;font-size:1.45rem}.assessment-feedback-result table{border-collapse:collapse;width:100%;font-size:.95rem}.assessment-feedback-result th,.assessment-feedback-result td{padding:12px;text-align:left;vertical-align:top;border-bottom:1px solid var(--primary)}.assessment-feedback-result th{background:var(--structure);color:white}.assessment-feedback-result td:nth-child(2){white-space:nowrap}.assessment-feedback-result a{overflow-wrap:anywhere}.assessment-feedback-result p,.feedback-overview p,.feedback-overview li{line-height:1.6}
@media(max-width:720px){.assessment-feedback-toolbar{padding:14px}.assessment-feedback-form{width:100%}.assessment-feedback-form label{width:100%}.assessment-feedback-form input[type=email]{min-width:0;flex:1;width:150px}.assessment-feedback-result{padding:16px}.assessment-feedback-result table{font-size:.8rem}.assessment-feedback-result td,.assessment-feedback-result th{padding:7px}}
</style>
<section class="assessment-feedback-toolbar" aria-label="Sujet et retours du contrôle continu">
    <nav class="assessment-feedback-links" aria-label="Navigation du contrôle continu">
        <a class="btn ghost" href="<?php echo htmlspecialchars($feedback['return'], ENT_QUOTES, 'UTF-8'); ?>">← Retour</a>
        <a class="btn ghost" href="<?php echo htmlspecialchars($feedback['action'], ENT_QUOTES, 'UTF-8'); ?>">Mes retours</a>
    </nav>
    <form class="assessment-feedback-form" method="post" action="<?php echo htmlspecialchars($feedback['action'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="assessment_feedback" value="1">
        <input type="hidden" name="feedback_csrf" value="<?php echo htmlspecialchars($feedback['csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <label for="feedback-email">Votre mail</label>
        <input id="feedback-email" name="feedback_email" type="email" autocomplete="email" maxlength="254" value="<?php echo htmlspecialchars($feedback['email'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Mail utilisé pour le quiz" required>
        <button class="btn primary" type="submit">Valider</button>
    </form>
    <?php if ($feedback['error'] !== ''): ?><p class="assessment-feedback-error" role="alert"><?php echo htmlspecialchars($feedback['error'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
</section>
<?php if ($feedback['result'] !== null): ?>
    <section class="card assessment-feedback-result" aria-label="Votre note et votre retour individuel">
        <?php echo $feedback['result']['html']; /* Private, teacher-generated and escaped during import. */ ?>
    </section>
<?php endif; ?>
