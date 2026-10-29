<?php $escapeDraw = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?>
<section class="card"><header class="card-header"><h1>Ordre de passage</h1></header><div class="card-body">
<?php if ($error !== null): ?><p class="presentation-draw-error" role="alert"><?= $escapeDraw($error) ?></p><?php endif; ?>
<?php if ($result !== null): ?>
<h2><?= $escapeDraw($result['event']) ?></h2><p>Le résultat du tirage est conservé.</p>
<ol class="presentation-draw-list"><?php foreach ($result['order'] as $rank => $team): ?><li>
<?php if (isset($result['schedule'])): $start = (new DateTimeImmutable($result['schedule']['start_at']))->setTimezone(new DateTimeZone('Europe/Paris'))->modify('+' . ($rank * $result['schedule']['slot_minutes']) . ' minutes'); ?>
<strong><?= $escapeDraw($start->format('d/m H:i')) ?> – <?= $escapeDraw($start->modify('+' . $result['schedule']['slot_minutes'] . ' minutes')->format('H:i')) ?></strong> ·
<?php endif; ?><?= $escapeDraw($team['name']) ?></li><?php endforeach; ?></ol>
<a class="btn ghost" href="/presentation-draw?format=json">Télécharger le résultat</a>
<?php else: ?>
<p>Le formateur lancera le tirage en séance.</p>
<ul><?php foreach ($teams as $team): ?><li><?= $escapeDraw($team['name']) ?></li><?php endforeach; ?></ul>
<?php if ($error === null): ?><details><summary>Lancer le tirage</summary>
<form class="presentation-draw-form" method="post" action="/presentation-draw">
<input type="hidden" name="csrf" value="<?= $escapeDraw($csrf) ?>">
<label for="launch-code">Code du formateur</label><input id="launch-code" name="launch_code" type="password" maxlength="256" required autocomplete="off">
<label><input type="checkbox" name="confirm" value="1" required> Je confirme le tirage de l’ordre de passage.</label>
<button class="btn primary" type="submit">Enregistrer le tirage</button></form></details><?php endif; ?>
<?php endif; ?></div></section>
