<?php
declare(strict_types=1);
// Private owner-only projection, or immutable values supplied by an archive.
$trackingEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<h3><?php echo $trackingEsc($i18n->t('quiz.preflight.private_title')); ?></h3>
<p class="quiz-hint"><?php echo $trackingEsc($i18n->t('quiz.preflight.private_hint')); ?></p>
<?php if (!isset($trackingPolicy)): ?>
    <p><?php echo $trackingEsc($i18n->t('quiz.preflight.not_recorded')); ?></p>
<?php else: ?>
    <p><?php echo $trackingEsc($i18n->t('quiz.preflight.mode')); ?> : <strong><?php echo $trackingEsc($trackingPolicy['mode']); ?></strong>
        · <?php echo $trackingEsc($i18n->t('quiz.preflight.revision')); ?> #<?php echo (int)$trackingPolicy['settings_revision']; ?></p>
    <?php if (($trackingContexts['rows'] ?? []) === []): ?><p><?php echo $trackingEsc($i18n->t('quiz.history.empty')); ?></p><?php endif; ?>
    <?php foreach (($trackingContexts['rows'] ?? []) as $trackingRow): ?>
        <details><summary><?php echo $trackingEsc(trim($trackingRow['first_name'].' '.$trackingRow['last_name'])); ?>
            · #<?php echo (int)$trackingRow['attempt_id']; ?> · <?php echo $trackingEsc($trackingRow['proof_status']); ?>
            · <?php echo $trackingEsc(formatParisTime($trackingRow['created_at'])); ?></summary>
            <p><?php echo $trackingEsc($i18n->t('quiz.history.nonce')); ?> : <code><?php echo $trackingEsc($trackingRow['tracking_generation']); ?></code></p>
            <?php if (!empty($trackingRow['identity_truncated'])): ?><p><?php echo $trackingEsc($i18n->t('quiz.preflight.identity_reduced')); ?></p><?php endif; ?>
            <table class="quiz-table"><tbody>
                <?php foreach (['context_ref','status','phase','created_at','terminated_at','termination_kind','proof_status','proof_until','proof_issued_at','proof_fullscreen_required','admitted_at','checks_provenance','last_pulse_at','last_pulse_outcome','last_pulse_until','last_pulse_fullscreen_required','last_pulse_provenance'] as $trackingField): ?>
                    <tr><th><?php echo $trackingEsc($trackingField); ?></th><td><?php echo $trackingEsc($trackingRow[$trackingField] ?? '—'); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <p><strong><?php echo $trackingEsc($i18n->t('quiz.preflight.causes')); ?></strong> : <?php echo $trackingEsc(implode(', ', $trackingRow['causes'] ?? []) ?: '—'); ?></p>
            <?php if (is_array($trackingRow['checks'] ?? null)): ?>
                <table class="quiz-table"><tbody><?php foreach ($trackingRow['checks'] as $trackingCheck => $trackingValue): ?>
                    <tr><th><?php echo $trackingEsc($trackingCheck); ?></th><td><?php echo $trackingValue === true ? 'true' : 'false'; ?></td></tr>
                <?php endforeach; ?></tbody></table>
            <?php endif; ?>
            <?php if (array_key_exists('last_pulse_checks', $trackingRow)): ?>
                <p>last_pulse_checks : <code><?php echo $trackingEsc(json_encode($trackingRow['last_pulse_checks'])); ?></code><br>
                    pulse_failure_checks : <code><?php echo $trackingEsc(json_encode($trackingRow['pulse_failure_checks'])); ?></code></p>
            <?php else: ?><p><?php echo $trackingEsc($i18n->t('quiz.tracking.pulse_not_recorded')); ?></p><?php endif; ?>
        </details>
    <?php endforeach; unset($trackingRow, $trackingField, $trackingCheck, $trackingValue); ?>
    <?php if (($trackingContexts['next_before'] ?? null) !== null && !isset($archive)): ?>
        <a href="/quiz-admin/attempt?id=<?php echo (int)$attempt['id']; ?>&amp;tracking_before=<?php echo (int)$trackingContexts['next_before']; ?>"><?php echo $trackingEsc($i18n->t('quiz.history.older')); ?></a>
    <?php endif; ?>
<?php endif; ?>
