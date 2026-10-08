<?php declare(strict_types=1); ?>
<?php if (!array_key_exists('technical_override', $accessContext ?? []) && !isset($overrideHistoryEntry)): ?>
    <p><?php echo htmlspecialchars($i18n->t('quiz.override.not_recorded'), ENT_QUOTES, 'UTF-8'); ?></p>
<?php elseif ($technicalOverride === null): ?>
    <p><?php echo htmlspecialchars($i18n->t('quiz.override.none'), ENT_QUOTES, 'UTF-8'); ?></p>
<?php else: ?>
    <p><strong>#<?php echo (int)$technicalOverride['id']; ?> · <?php echo htmlspecialchars($i18n->t('quiz.override.status.' . $technicalOverride['status']), ENT_QUOTES, 'UTF-8'); ?></strong>
        · <?php echo htmlspecialchars(implode(', ', array_map(fn(string $scope): string => $i18n->t('quiz.override.scope.' . $scope), $technicalOverride['scopes'])), ENT_QUOTES, 'UTF-8'); ?></p>
    <p><?php echo htmlspecialchars($technicalOverride['grant_reason'], ENT_QUOTES, 'UTF-8'); ?>
        · <?php echo htmlspecialchars($technicalOverride['grant_actor_name'], ENT_QUOTES, 'UTF-8'); ?>
        · <?php echo htmlspecialchars(formatParisTime($technicalOverride['granted_at']), ENT_QUOTES, 'UTF-8'); ?></p>
    <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.override.generation'), ENT_QUOTES, 'UTF-8'); ?> : <code><?php echo htmlspecialchars($technicalOverride['tracking_generation'], ENT_QUOTES, 'UTF-8'); ?></code></p>
    <?php if ($technicalOverride['ended_at'] !== null): ?><p>
        <?php echo htmlspecialchars($technicalOverride['end_kind'] === 'revoked' ? $technicalOverride['end_reason'] : $i18n->t('quiz.override.end.' . $technicalOverride['end_kind']), ENT_QUOTES, 'UTF-8'); ?>
        · <?php echo htmlspecialchars((string)$technicalOverride['end_actor_name'], ENT_QUOTES, 'UTF-8'); ?>
        · <?php echo htmlspecialchars(formatParisTime($technicalOverride['ended_at']), ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php endif; ?>
