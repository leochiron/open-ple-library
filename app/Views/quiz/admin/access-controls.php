<?php declare(strict_types=1); ?>
<?php if (!empty($accessContext['reason'])): ?>
    <p><?php echo htmlspecialchars($accessContext['reason'], ENT_QUOTES, 'UTF-8'); ?>
        · <?php echo htmlspecialchars((string)$accessContext['actor_name'], ENT_QUOTES, 'UTF-8'); ?>
        · <?php echo htmlspecialchars(formatParisTime($accessContext['changed_at']), ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
<form method="post" action="/quiz-admin/access/<?php echo $accessContext['manual_blocked'] ? 'lift' : 'block'; ?>" class="quiz-join-form">
    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$session['id']; ?>">
    <input type="hidden" name="student_id" value="<?php echo $accessStudentId; ?>">
    <label><?php echo htmlspecialchars($i18n->t('quiz.access.reason'), ENT_QUOTES, 'UTF-8'); ?>
        <textarea name="reason" class="quiz-input" required maxlength="1000" rows="2"></textarea></label>
    <button type="submit" class="btn ghost"><?php echo htmlspecialchars($i18n->t($accessContext['manual_blocked'] ? 'quiz.access.lift' : 'quiz.access.block'), ENT_QUOTES, 'UTF-8'); ?></button>
</form>
<h3><?php echo htmlspecialchars($i18n->t('quiz.override.title'), ENT_QUOTES, 'UTF-8'); ?></h3>
<?php $technicalOverride = $accessContext['technical_override'] ?? null; include __DIR__ . '/override-details.php'; ?>
<?php if (in_array($session['state'], ['lobby', 'running'], true)): ?>
    <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.override.hint'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($technicalOverride !== null && in_array('tracking', $technicalOverride['scopes'], true) && empty($accessContext['manual_blocked'])): ?>
        <p><strong><?php echo htmlspecialchars($i18n->t('quiz.override.tracking_active'), ENT_QUOTES, 'UTF-8'); ?></strong></p>
    <?php elseif ($technicalOverride !== null && $technicalOverride['scopes'] === ['browser']): ?>
        <p><?php echo htmlspecialchars($i18n->t('quiz.override.browser_only'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <form method="post" action="/quiz-admin/access/override/<?php echo $technicalOverride !== null ? 'revoke' : 'grant'; ?>" class="quiz-join-form">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$session['id']; ?>">
        <input type="hidden" name="student_id" value="<?php echo $accessStudentId; ?>">
        <input type="hidden" name="generation" value="<?php echo htmlspecialchars((string)($session['tracking_generation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($technicalOverride !== null): ?><input type="hidden" name="override_id" value="<?php echo (int)$technicalOverride['id']; ?>">
        <?php else: ?>
            <label><input type="checkbox" name="scopes[]" value="browser"> <?php echo htmlspecialchars($i18n->t('quiz.override.scope.browser'), ENT_QUOTES, 'UTF-8'); ?></label>
            <label><input type="checkbox" name="scopes[]" value="tracking"> <?php echo htmlspecialchars($i18n->t('quiz.override.scope.tracking'), ENT_QUOTES, 'UTF-8'); ?></label>
        <?php endif; ?>
        <label><?php echo htmlspecialchars($i18n->t('quiz.access.reason'), ENT_QUOTES, 'UTF-8'); ?><textarea name="reason" class="quiz-input" required maxlength="1000" rows="2"></textarea></label>
        <button type="submit" class="btn ghost"><?php echo htmlspecialchars($i18n->t($technicalOverride !== null ? 'quiz.override.revoke' : 'quiz.override.grant'), ENT_QUOTES, 'UTF-8'); ?></button>
    </form>
<?php endif; ?>
