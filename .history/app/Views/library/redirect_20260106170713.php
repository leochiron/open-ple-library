<?php

declare(strict_types=1);

/** @var string $redirectUrl */
/** @var string $filename */
/** @var array<int, array{label:string,path:string|null}> $breadcrumbs */
/** @var App\Services\I18nService $i18n */
?>

<script>
    // Ouvrir l'URL dans un nouvel onglet
    window.open(<?php echo json_encode($redirectUrl); ?>, '_blank');
    
    // Retourner à la page précédente après un court délai
    setTimeout(function() {
        window.history.back();
    }, 100);
</script>

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
        <a class="btn ghost" href="javascript:history.back()">← <?php echo htmlspecialchars($i18n->t('nav.back'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    
    <div class="card-body">
        <p style="text-align: center; padding: 2rem;">
            <strong>Redirection en cours...</strong>
        </p>
        <p style="text-align: center;">
            Si la page ne s'ouvre pas automatiquement, <a href="<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">cliquez ici</a>.
        </p>
    </div>
</section>
