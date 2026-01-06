<?php
declare(strict_types=1);

/** @var string $title */
/** @var string $message */
?>
<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
</section>
