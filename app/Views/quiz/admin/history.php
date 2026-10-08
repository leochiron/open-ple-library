<?php
declare(strict_types=1);
$sid = (int)$session['id'];
$esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?php echo $esc(assetBase()); ?>/css/quiz.css">
<section class="card">
    <header class="card-header"><h1><?php echo $esc($i18n->t('quiz.history.title')); ?> — <?php echo $esc($session['title']); ?></h1></header>
    <div class="card-body">
        <p class="quiz-hint"><?php echo $esc($i18n->t('quiz.history.read_only')); ?></p>
        <p class="quiz-hint"><?php echo $esc($i18n->t('quiz.history.current_distinct')); ?></p>
        <div class="quiz-admin-actions">
            <a class="btn ghost" href="/quiz-admin/session?id=<?php echo $sid; ?>"><?php echo $esc($i18n->t('quiz.admin.back_session')); ?></a>
            <a class="btn ghost" href="/quiz-admin/export?id=<?php echo $sid; ?>"><?php echo $esc($i18n->t('quiz.history.export_all')); ?></a>
            <?php if ($attemptFilter > 0): ?><a class="btn ghost" href="/quiz-admin/history?id=<?php echo $sid; ?>"><?php echo $esc($i18n->t('quiz.history.all_attempts')); ?></a><?php endif; ?>
        </div>
        <h2><?php echo $esc($i18n->t('quiz.history.snapshot')); ?></h2>
        <?php if ($archives['rows'] === []): ?><p><?php echo $esc($i18n->t('quiz.history.empty')); ?></p>
        <?php else: ?>
            <table class="quiz-table">
                <thead><tr>
                    <th><?php echo $esc($i18n->t('quiz.admin.col_student')); ?></th>
                    <th><?php echo $esc($i18n->t('quiz.history.archived_at')); ?></th>
                    <th><?php echo $esc($i18n->t('quiz.history.actor')); ?></th>
                    <th><?php echo $esc($i18n->t('quiz.admin.col_incidents')); ?></th>
                    <th><?php echo $esc($i18n->t('quiz.admin.col_status')); ?></th>
                    <th><?php echo $esc($i18n->t('quiz.history.nonce')); ?></th>
                    <th><?php echo $esc($i18n->t('labels.actions')); ?></th>
                </tr></thead>
                <tbody><?php foreach ($archives['rows'] as $row): ?><tr>
                    <td><?php echo $esc(trim($row['first_name'] . ' ' . $row['last_name'])); ?> · #<?php echo (int)$row['id']; ?></td>
                    <td><?php echo $esc(formatParisTime($row['archived_at'])); ?></td>
                    <td><?php echo $esc($row['actor_name']); ?></td>
                    <td><?php echo (int)$row['incident_count']; ?></td>
                    <td><?php echo $esc($row['status']); ?><?php echo $row['finished_at'] !== null ? ' · ' . $esc($i18n->t('quiz.admin.finished_badge')) : ''; ?><br><?php echo (int)$row['event_count']; ?> <?php echo $esc($i18n->t('quiz.history.events')); ?></td>
                    <td><code title="<?php echo $esc($row['generation']); ?>"><?php echo $esc(substr($row['generation'], 0, 8) ?: '—'); ?></code></td>
                    <td><a href="/quiz-admin/archive?id=<?php echo (int)$row['id']; ?>" target="_blank"><?php echo $esc($i18n->t('quiz.history.open_report')); ?></a><br>
                        <a href="/quiz-admin/archive/export?id=<?php echo (int)$row['id']; ?>"><?php echo $esc($i18n->t('quiz.admin.action_export')); ?></a></td>
                </tr><?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
        <?php if ($archives['next_before'] !== null): ?><a class="btn ghost" href="/quiz-admin/history?id=<?php echo $sid; ?>&amp;before=<?php echo (int)$archives['next_before']; ?>&amp;attempt_id=<?php echo $attemptFilter; ?>"><?php echo $esc($i18n->t('quiz.history.older')); ?></a><?php endif; ?>

        <h2><?php echo $esc($i18n->t('quiz.history.audit')); ?></h2>
        <p class="quiz-hint"><?php echo $esc($i18n->t('quiz.history.audit_hint')); ?></p>
        <?php if ($audit['rows'] === []): ?><p><?php echo $esc($i18n->t('quiz.history.empty')); ?></p>
        <?php else: ?>
            <table class="quiz-table"><thead><tr>
                <th><?php echo $esc($i18n->t('quiz.admin.col_date')); ?></th>
                <th><?php echo $esc($i18n->t('quiz.history.actor')); ?></th>
                <th><?php echo $esc($i18n->t('quiz.history.action')); ?></th>
                <th><?php echo $esc($i18n->t('quiz.history.changes')); ?></th>
            </tr></thead><tbody>
            <?php foreach ($audit['rows'] as $entry): ?><tr>
                <td><?php echo $esc(formatParisTime($entry['created_at'])); ?></td>
                <td><?php echo $esc($entry['actor_name']); ?> (#<?php echo (int)$entry['actor_admin_id']; ?>)</td>
                <td><?php echo $esc($i18n->t('quiz.history.action.' . $entry['action'])); ?>
                    <?php $subject = $entry['after'] + $entry['before']; if (isset($subject['attempt_id'])): ?>
                        <br><a href="/quiz-admin/history?id=<?php echo $sid; ?>&amp;attempt_id=<?php echo (int)$subject['attempt_id']; ?>">
                            <?php echo $esc(trim(($subject['first_name'] ?? '') . ' ' . ($subject['last_name'] ?? ''))); ?>
                            · <?php echo $esc($i18n->t('quiz.history.attempt')); ?> #<?php echo (int)$subject['attempt_id']; ?></a>
                        <?php if (isset($subject['event_id'])): ?><br><?php echo $esc($i18n->t('quiz.history.observation')); ?> #<?php echo (int)$subject['event_id']; ?><?php endif; ?>
                        <?php if (!empty($subject['absence_uid'])): ?><br><?php echo $esc($i18n->t('quiz.history.episode')); ?> <code><?php echo $esc($subject['absence_uid']); ?></code><?php endif; ?>
                    <?php endif; ?>
                    <?php if (isset($subject['student_id']) && !isset($subject['attempt_id'])): ?><br>
                        <?php echo $esc(trim(($subject['first_name'] ?? '') . ' ' . ($subject['last_name'] ?? ''))); ?> · #<?php echo (int)$subject['student_id']; ?>
                    <?php endif; ?>
                </td>
                <td><details><summary><?php echo $esc($i18n->t('quiz.history.changes')); ?></summary>
                    <?php foreach (array_unique(array_merge(array_keys($entry['before']), array_keys($entry['after']))) as $field): ?>
                        <?php if (($entry['before'][$field] ?? null) === ($entry['after'][$field] ?? null)): continue; endif; ?>
                        <p><strong><?php echo $esc($field); ?></strong> :
                            <code><?php echo $esc(json_encode($entry['before'][$field] ?? null, JSON_UNESCAPED_UNICODE)); ?></code> →
                            <code><?php echo $esc(json_encode($entry['after'][$field] ?? null, JSON_UNESCAPED_UNICODE)); ?></code></p>
                    <?php endforeach; ?>
                </details></td>
            </tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <?php if ($audit['next_before'] !== null): ?><a class="btn ghost" href="/quiz-admin/history?id=<?php echo $sid; ?>&amp;audit_before=<?php echo (int)$audit['next_before']; ?>&amp;attempt_id=<?php echo $attemptFilter; ?>"><?php echo $esc($i18n->t('quiz.history.older')); ?></a><?php endif; ?>
    </div>
</section>
