<?php

declare(strict_types=1);

/** @var array<int, array{name:string,is_dir:bool,size:int,modified:int}> $entries */
/** @var string $relativePath */
/** @var array<int, array{label:string,path:string|null}> $breadcrumbs */
/** @var string|null $parentPath */
/** @var App\Services\I18nService $i18n */
?>
<section class="toolbar">
    <div class="breadcrumbs" aria-label="breadcrumbs">
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <?php if ($index < count($breadcrumbs) - 1): ?>
                <a href="<?php echo htmlspecialchars(buildUrl(['path' => $crumb['path']]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                <span class="crumb-separator">/</span>
            <?php else: ?>
                <span class="crumb-current"><?php echo htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="toolbar-actions">
        <div class="view-toggle">
            <button id="view-list-btn" class="view-btn active" title="<?php echo htmlspecialchars($i18n->t('view.list'), ENT_QUOTES, 'UTF-8'); ?>">≡</button>
            <button id="view-grid-btn" class="view-btn" title="<?php echo htmlspecialchars($i18n->t('view.grid'), ENT_QUOTES, 'UTF-8'); ?>">⊞</button>
        </div> zz
        <?php if ($parentPath !== null): ?>
            <a class="btn ghost" href="<?php echo htmlspecialchars(buildUrl(['path' => $parentPath]), ENT_QUOTES, 'UTF-8'); ?>">← <?php echo htmlspecialchars($i18n->t('nav.back'), ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($i18n->t('folder.heading'), ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <?php if (count($entries) === 0): ?>
        <div class="empty"><?php echo htmlspecialchars($i18n->t('folder.empty'), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
        <div class="file-list" id="file-list-container" role="list">
            <div class="file-row file-row--head" role="listitem">
                <div><?php echo htmlspecialchars($i18n->t('labels.name'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div><?php echo htmlspecialchars($i18n->t('labels.modified'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div><?php echo htmlspecialchars($i18n->t('labels.size'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div><?php echo htmlspecialchars($i18n->t('labels.actions'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php foreach ($entries as $entry): ?>
                <?php
                $entryPath = $relativePath === '' ? $entry['name'] : $relativePath . '/' . $entry['name'];
                $url = buildUrl(['path' => $entryPath]);
                $ext = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                $icon = '📄';
                if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true)) {
                    $icon = '🎵';
                } elseif (in_array($ext, ['mp4', 'avi', 'mov', 'mkv', 'webm'], true)) {
                    $icon = '🎬';
                } elseif ($ext === 'pdf') {
                    $icon = '📕';
                } elseif ($entry['is_dir']) {
                    $icon = '📁';
                }
                ?>
                <div class="file-row" role="listitem" data-icon="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" data-name="<?php echo htmlspecialchars($entry['name'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div>
                        <span class="pill <?php echo $entry['is_dir'] ? 'pill-dir' : 'pill-file'; ?>"><?php echo $icon; ?></span>
                        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="file-name"><?php echo htmlspecialchars($entry['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                    <div><?php echo htmlspecialchars(date('Y-m-d H:i', $entry['modified']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div><?php echo htmlspecialchars($entry['display_size'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="actions">
                        <?php if ($entry['is_dir']): ?>
                            <a class="btn ghost" href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($i18n->t('file.open'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php else: ?>
                            <a class="btn ghost" href="<?php echo htmlspecialchars(buildUrl(['action' => 'download', 'path' => $entryPath]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($i18n->t('file.download'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
