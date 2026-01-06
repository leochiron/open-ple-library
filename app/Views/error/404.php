<?php
declare(strict_types=1);

/** @var string $title */
/** @var string $message */
/** @var App\Services\I18nService $i18n */
?>
<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <a class="btn primary" href="<?php echo htmlspecialchars(buildUrl(['path' => '']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($i18n->t('nav.root'), ENT_QUOTES, 'UTF-8'); ?></a>
</section>
