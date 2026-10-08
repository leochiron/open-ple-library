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
