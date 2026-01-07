<?php

declare(strict_types=1);

/** @var string $relativePath */
/** @var string $filename */
/** @var string $mime */
/** @var bool $isPreviewable */
/** @var string $size */
/** @var int $modified */
/** @var array<int, array{label:string,path:string|null}> $breadcrumbs */
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
        <a class="btn ghost" href="<?php echo htmlspecialchars(buildUrl(['path' => dirname($relativePath) === '.' ? '' : dirname($relativePath)]), ENT_QUOTES, 'UTF-8'); ?>">← <?php echo htmlspecialchars($i18n->t('nav.back'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a class="btn primary" href="<?php echo htmlspecialchars(buildUrl(['action' => 'download', 'path' => $relativePath]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($i18n->t('file.download'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="muted"><?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(date('Y-m-d H:i', $modified), ENT_QUOTES, 'UTF-8'); ?></p>
    </header>

    <?php if ($isPreviewable): ?>
        <div class="preview" id="preview-container">
            <?php if (strpos($mime, 'pdf') !== false): ?>
                <div class="preview-toolbar">
                    <button id="fullscreen-btn" class="btn ghost" title="Plein écran">⛶</button>
                </div>
                <iframe src="<?php echo htmlspecialchars(buildCleanUrl(['action' => 'open', 'path' => $relativePath]), ENT_QUOTES, 'UTF-8'); ?>" title="PDF preview" loading="lazy" id="pdf-iframe"></iframe>
            <?php elseif (strpos($mime, 'audio') === 0): ?>
                <audio controls preload="metadata" id="audio-player">
                    <source src="<?php echo htmlspecialchars(buildCleanUrl(['action' => 'open', 'path' => $relativePath]), ENT_QUOTES, 'UTF-8'); ?>" type="<?php echo htmlspecialchars($mime, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($i18n->t('file.preview_unavailable'), ENT_QUOTES, 'UTF-8'); ?>
                </audio>
                <script>
                    (function() {
                        var audio = document.getElementById('audio-player');
                        if (audio) {
                            audio.addEventListener('error', function(e) {
                                console.error('Audio error:', e);
                                console.error('Audio error code:', audio.error ? audio.error.code : 'unknown');
                                console.error('Audio src:', audio.currentSrc);
                            });
                        }
                    })();
                </script>
            <?php elseif (strpos($mime, 'video') === 0): ?>
                <video controls preload="metadata" id="video-player">
                    <source src="<?php echo htmlspecialchars(buildCleanUrl(['action' => 'open', 'path' => $relativePath]), ENT_QUOTES, 'UTF-8'); ?>" type="<?php echo htmlspecialchars($mime, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($i18n->t('file.preview_unavailable'), ENT_QUOTES, 'UTF-8'); ?>
                </video>
                <script>
                    (function() {
                        var video = document.getElementById('video-player');
                        if (video) {
                            video.addEventListener('error', function(e) {
                                console.error('Video error:', e);
                                console.error('Video error code:', video.error ? video.error.code : 'unknown');
                                console.error('Video error message:', video.error ? video.error.message : 'unknown');
                                console.error('Video src:', video.currentSrc);
                            });
                            video.addEventListener('loadedmetadata', function() {
                                console.log('Video metadata loaded successfully');
                            });
                        }
                    })();
                </script>
            <?php endif; ?>
        </div>
        <script>
            (function() {
                var fullscreenBtn = document.getElementById('fullscreen-btn');
                var previewContainer = document.getElementById('preview-container');
                
                if (fullscreenBtn && previewContainer) {
                    fullscreenBtn.addEventListener('click', function() {
                        previewContainer.classList.toggle('fullscreen');
                        fullscreenBtn.textContent = previewContainer.classList.contains('fullscreen') ? '✕' : '⛶';
                    });
                    
                    // Close fullscreen on Escape key
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && previewContainer.classList.contains('fullscreen')) {
                            previewContainer.classList.remove('fullscreen');
                            fullscreenBtn.textContent = '⛶';
                        }
                    });
                }
            })();
        </script>
    <?php else: ?>
        <div class="empty">
            <?php echo htmlspecialchars($i18n->t('file.preview_unavailable'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
</section>
