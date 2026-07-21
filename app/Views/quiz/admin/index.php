<?php

declare(strict_types=1);

/** @var array $sessions */
/** @var string $flash */
/** @var array $old */
/** @var array $admin */
/** @var bool $showAll */
/** @var string $csrfToken */
/** @var App\Services\I18nService $i18n */

// Repopulate the create form after a validation error (keys default to empty)
$old = $old ?? [];
$oldVal = static function (string $key, string $default = '') use ($old): string {
    return htmlspecialchars((string)($old[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};
$oldChecked = static function (string $key) use ($old): string {
    return isset($old[$key]) ? ' checked' : '';
};

$stateLabels = [
    'armed' => $i18n->t('quiz.state.armed'),
    'lobby' => $i18n->t('quiz.state.lobby'),
    'running' => $i18n->t('quiz.state.running'),
    'closed' => $i18n->t('quiz.state.closed'),
];
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">

<div class="quiz-admin-grid">

<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($i18n->t('quiz.admin.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <div class="quiz-admin-actions">
            <span><?php echo htmlspecialchars($admin['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($admin['role'] === 'super_admin'): ?>
                <a class="btn ghost" href="/quiz-admin/users">Utilisateurs</a>
                <a class="btn ghost" href="<?php echo $showAll ? '/quiz-admin' : '/quiz-admin?scope=all'; ?>">
                    <?php echo $showAll ? 'Mes quiz' : 'Tous les quiz'; ?>
                </a>
            <?php endif; ?>
            <a class="btn ghost" href="/quiz-admin/logout">Déconnexion</a>
        </div>
    </header>
    <div class="card-body">
        <?php if ($flash !== ''): ?>
            <p class="quiz-error" role="alert"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if (count($sessions) === 0): ?>
            <p><?php echo htmlspecialchars($i18n->t('quiz.admin.no_sessions'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
            <table class="quiz-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_title'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_state'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th>PIN</th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_students'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_attempts'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <?php if ($showAll): ?><th>Propriétaire</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="quiz-state quiz-state--<?php echo htmlspecialchars($s['state'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stateLabels[$s['state']] ?? $s['state'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo $s['access_pin'] !== null ? htmlspecialchars($s['access_pin'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td><?php echo (int)$s['student_count']; ?></td>
                            <td><?php echo (int)$s['attempt_count']; ?></td>
                            <?php if ($showAll): ?><td><?php echo htmlspecialchars($s['owner_name'], ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                            <td><a class="btn ghost" href="/quiz-admin/session?id=<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($i18n->t('quiz.admin.manage'), ENT_QUOTES, 'UTF-8'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h2><?php echo htmlspecialchars($i18n->t('quiz.admin.create_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
    </header>
    <div class="card-body">
        <details class="quiz-tuto">
            <summary>📖 <?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_title'), ENT_QUOTES, 'UTF-8'); ?></summary>
            <ol>
                <li>
                    <?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_step1'), ENT_QUOTES, 'UTF-8'); ?>
                    <a href="https://docs.google.com/forms/u/0/create" target="_blank" rel="noopener">forms.google.com ↗</a>
                </li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_step2'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_step3'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_step4'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_step5'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_step6'), ENT_QUOTES, 'UTF-8'); ?></li>
            </ol>
            <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.tuto_tip'), ENT_QUOTES, 'UTF-8'); ?></p>
        </details>

        <form method="post" action="/quiz-admin/create" class="quiz-admin-form" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="quiz-field quiz-field--full">
                <label for="qa-title"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_title'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" id="qa-title" name="title" required class="quiz-input" value="<?php echo $oldVal('title'); ?>">
            </div>
            <div class="quiz-field quiz-field--full">
                <label for="qa-url"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_url'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="url" id="qa-url" name="google_form_url" required class="quiz-input"
                       placeholder="https://docs.google.com/forms/d/e/.../viewform" value="<?php echo $oldVal('google_form_url'); ?>">
                <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_url_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="quiz-field quiz-field--full">
                <label for="qa-entry"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_entry'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" id="qa-entry" name="attempt_entry_id" required class="quiz-input"
                       placeholder="entry.123456789 — ou collez l'URL préremplie complète" value="<?php echo $oldVal('attempt_entry_id'); ?>">
                <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_entry_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="quiz-field quiz-field--full">
                <label><?php echo htmlspecialchars($i18n->t('quiz.admin.presets_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="quiz-admin-actions">
                    <button type="button" class="btn ghost" data-preset="souple"><?php echo htmlspecialchars($i18n->t('quiz.admin.preset_loose'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <button type="button" class="btn ghost" data-preset="normal"><?php echo htmlspecialchars($i18n->t('quiz.admin.preset_normal'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <button type="button" class="btn ghost" data-preset="strict"><?php echo htmlspecialchars($i18n->t('quiz.admin.preset_strict'), ENT_QUOTES, 'UTF-8'); ?></button>
                </div>
                <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.presets_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="quiz-field">
                <label for="qa-duration"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_duration'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="number" id="qa-duration" name="duration_minutes" value="<?php echo $oldVal('duration_minutes', '30'); ?>" min="1" max="240" class="quiz-input">
            </div>
            <div class="quiz-field">
                <label for="qa-max-incidents"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_max_incidents'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="number" id="qa-max-incidents" name="max_incidents" value="<?php echo $oldVal('max_incidents', '2'); ?>" min="1" max="20" class="quiz-input">
            </div>
            <div class="quiz-field">
                <label for="qa-min-away"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_min_away'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="number" id="qa-min-away" name="min_away_seconds" value="<?php echo $oldVal('min_away_seconds', '10'); ?>" min="1" max="300" class="quiz-input">
            </div>
            <div class="quiz-field">
                <label class="quiz-consent">
                    <input type="checkbox" id="qa-fullscreen" name="require_fullscreen"<?php echo $oldChecked('require_fullscreen'); ?>>
                    <span><?php echo htmlspecialchars($i18n->t('quiz.admin.field_fullscreen'), ENT_QUOTES, 'UTF-8'); ?></span>
                </label>
            </div>
            <div class="quiz-field">
                <label class="quiz-consent">
                    <input type="checkbox" id="qa-reload" name="reload_is_incident"<?php echo $oldChecked('reload_is_incident'); ?>>
                    <span><?php echo htmlspecialchars($i18n->t('quiz.admin.field_reload'), ENT_QUOTES, 'UTF-8'); ?></span>
                </label>
            </div>
            <div class="quiz-field quiz-field--full">
                <label for="qa-roster"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_roster'), ENT_QUOTES, 'UTF-8'); ?></label>
                <textarea id="qa-roster" name="roster" placeholder="Léa Dupont lea.dupont@ecole.fr&#10;Karim Benali&#10;jules.martin@ecole.fr&#10;..."><?php echo htmlspecialchars((string)($old['roster'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_roster_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="quiz-field quiz-field--full">
                <label for="qa-roster-file"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_roster_file'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="file" id="qa-roster-file" name="roster_file" accept=".csv,.txt" class="quiz-input">
                <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_roster_file_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="quiz-field quiz-field--full">
                <button type="submit" class="btn primary quiz-submit"><?php echo htmlspecialchars($i18n->t('quiz.admin.create_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </form>
    </div>
</section>

</div>

<script>
(function () {
    'use strict';
    // Surveillance presets: fill the rule fields, the teacher can still adjust them
    var presets = {
        souple: { max: 4, away: 20, fullscreen: false, reload: false },
        normal: { max: 2, away: 10, fullscreen: false, reload: false },
        strict: { max: 1, away: 5, fullscreen: true, reload: true }
    };
    document.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var p = presets[btn.dataset.preset];
            if (!p) { return; }
            document.getElementById('qa-max-incidents').value = p.max;
            document.getElementById('qa-min-away').value = p.away;
            document.getElementById('qa-fullscreen').checked = p.fullscreen;
            document.getElementById('qa-reload').checked = p.reload;
            document.querySelectorAll('[data-preset]').forEach(function (b) {
                b.classList.toggle('primary', b === btn);
                b.classList.toggle('ghost', b !== btn);
            });
        });
    });
})();
</script>
