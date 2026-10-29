<?php
declare(strict_types=1);
use App\Services\AssessmentReviewService as Review;
$escape = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$link = static fn(string $id = ''): string => '/quiz-admin/remises?' . http_build_query(['course' => $course] + ($id !== '' ? ['id' => $id] : []));
$exportLink = '/quiz-admin/remises/export?' . http_build_query(['course' => $course, 'q' => $search, 'status' => $statusFilter, 'timing' => $timingFilter]);
?>
<link rel="stylesheet" href="<?= $escape(assetBase()) ?>/css/quiz.css">
<style>.remise-table-wrap{overflow-x:auto}.remise-note{color:var(--text-muted,#64748b);font-size:.95rem}.remise-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin:18px 0}.remise-filters label{display:flex;flex-direction:column;gap:6px}.remise-filters input,.remise-filters select,.remise-edit select,.remise-edit textarea,.remise-deadline input{padding:9px;border:1px solid #94a3b8;border-radius:6px;font:inherit;max-width:100%}.remise-edit textarea{width:100%;min-height:130px}.remise-check{padding:14px;border:1px solid #94a3b8;border-radius:8px;margin:16px 0}.remise-actions{display:flex;gap:12px;flex-wrap:wrap}.remise-edit label{display:block;margin:14px 0 6px}.remise-alert{padding:12px;border:1px solid #b45309;border-radius:6px;margin:12px 0}code.remise-sha{word-break:break-all}.remise-deadline{margin:16px 0}@media(max-width:600px){.remise-filters label{width:100%}}</style>
<section class="card">
<header class="card-header"><h1>Remises des CC et projets</h1><a class="btn ghost" href="/quiz-admin">Retour à l’administration</a></header>
<div class="card-body">
<nav class="remise-actions" aria-label="Évaluation à consulter"><?php foreach ($courses as $assessmentId => $assessmentConfig): ?><a class="btn ghost" href="/quiz-admin/remises?course=<?= $escape($assessmentId) ?>"<?= $assessmentId === $course ? ' aria-current="page"' : '' ?>><?= $escape($assessmentConfig['title']) ?></a><?php endforeach ?></nav>
<?php if ($flash !== ''): ?><p class="remise-alert" role="status"><?= $escape($flash) ?></p><?php endif ?>
<?php foreach ($warnings as $warning): ?><p class="remise-alert" role="alert"><?= $escape($warning) ?></p><?php endforeach ?>
<p><strong><?= $escape($courses[$course]['title']) ?></strong> · <?= (int)$total ?> remise(s) enregistrée(s).</p>
<p>Échéance : <strong><?= $escape(Review::paris($deadline)) ?></strong> — heure de Paris.</p>
<details class="remise-deadline"><summary>Modifier l’échéance</summary>
<form method="post" action="/quiz-admin/remises/deadline">
<input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="course" value="<?= $escape($course) ?>">
<label for="deadline">Date et heure de Paris</label> <input id="deadline" name="deadline" type="datetime-local" required value="<?= $escape((new DateTimeImmutable($deadline))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i')) ?>">
<button class="btn" type="submit">Enregistrer l’échéance</button>
</form></details>
<p class="remise-note">La date indique la réception du lien, pas une copie du dépôt à cette date. Les noms sont déclarés par les étudiants. Une liste des inscrits serait nécessaire pour repérer les absents.</p>
<?php if ($detail === null): ?>
<form method="get" action="/quiz-admin/remises" class="remise-filters">
<input type="hidden" name="course" value="<?= $escape($course) ?>">
<label>Recherche par nom ou référence<input name="q" value="<?= $escape($search) ?>" maxlength="150"></label>
<label>État de vérification<select name="status"><option value="">Tous</option><?php foreach (Review::STATUSES as $key=>$label): ?><option value="<?= $key ?>" <?= $statusFilter===$key?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach ?></select></label>
<label>Délai<select name="timing"><option value="">Tous</option><option value="ontime" <?= $timingFilter==='ontime'?'selected':'' ?>>À l’heure</option><option value="late" <?= $timingFilter==='late'?'selected':'' ?>>En retard</option></select></label>
<button class="btn" type="submit">Filtrer</button><a class="btn ghost" href="<?= $escape($link()) ?>">Réinitialiser</a><a class="btn ghost" href="<?= $escape($exportLink) ?>">Exporter les résultats CSV</a>
</form>
<?php if ($rows === []): ?><p><?= $total===0?'Aucune remise enregistrée pour ce CC.':'Aucune remise ne correspond aux filtres.' ?></p>
<?php else: ?><div class="remise-table-wrap"><table class="quiz-table"><thead><tr><th>Étudiant déclaré</th><th>Réception (Paris)</th><th>Délai</th><th>Référence</th><th>Dépôt</th><th>État</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
<td><?= $escape($r['prenom'].' '.$r['nom']) ?></td><td><?= $escape($r['received_label']) ?></td><td><?= $r['late']?'En retard':'À l’heure' ?></td><td><?= $escape($r['reference']) ?></td>
<td><a href="<?= $escape($r['github_url']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir GitHub</a></td><td><?= $escape(Review::STATUSES[$r['review']['status']]) ?></td><td><a class="btn ghost" href="<?= $escape($link($r['id'])) ?>">Vérifier</a></td>
</tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?php else: $review=$detail['review']; $check=$review['check']; ?>
<a class="btn ghost" href="<?= $escape($link()) ?>">Toutes les remises</a>
<h2><?= $escape($detail['prenom'].' '.$detail['nom']) ?></h2>
<p>Reçu le <strong><?= $escape($detail['received_label']) ?></strong> · <?= $detail['late']?'En retard':'À l’heure' ?> · Référence <?= $escape($detail['reference']) ?></p>
<p><a href="<?= $escape($detail['github_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($detail['github_url']) ?></a></p>
<h3>Contrôle du dépôt</h3>
<p class="remise-note">Le contrôle utilise uniquement l’accès public GitHub, à votre demande. Il observe le dépôt maintenant ; il ne certifie ni sa qualité, ni son état au moment de la remise.</p>
<?php if (!empty($courses[$course]['private_allowed'])): ?><p class="remise-note">Un dépôt privé accessible au formateur est autorisé pour ce sujet. Si l’API publique ne peut pas le lire, vérifiez-le avec votre accès GitHub habituel. L’observation automatique porte sur main ; consultez la branche indiquée dans le README si elle diffère.</p><?php endif ?>
<form method="post" action="/quiz-admin/remises/check"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="course" value="<?= $escape($course) ?>"><input type="hidden" name="id" value="<?= $escape($detail['id']) ?>"><button class="btn" type="submit">Contrôler le dépôt</button></form>
<?php if (is_array($check)): ?><div class="remise-check"><p><strong><?= $escape($check['message']) ?></strong></p><p>Contrôle du <?= $escape(Review::paris($check['checked_at'])) ?> par <?= $escape($check['checked_by']) ?>.</p>
<?php if (isset($check['public'])): ?><p>Accès public : <?= $check['public']?'oui':'non confirmé' ?>.</p><?php endif ?>
<?php if (!empty($check['main_sha'])): ?><p>Commit main observé : <code class="remise-sha"><?= $escape($check['main_sha']) ?></code></p><p><?= $escape($courses[$course]['document'] ?? 'EXPLICATIONS.md') ?> à ce commit : <?= $check['explanations']?'présent et non vide':'manquant ou vide' ?>.</p><?php endif ?>
<?php if (isset($check['branches'])): ?><p>Branches présentes : <?= $escape(implode(', ',$check['branches'])) ?>.</p><p class="remise-note">Vérifiez la branche et la version remises au regard des consignes de l’évaluation.<?= !empty($check['branches_limited'])?' Liste limitée aux 100 premières branches.':'' ?></p><?php endif ?>
</div><?php else: ?><p>Aucun contrôle GitHub enregistré.</p><?php endif ?>
<?php if (count($review['check_history']??[])>1): ?><details><summary>Historique des contrôles (20 derniers maximum)</summary><ul><?php foreach (array_reverse($review['check_history']) as $past): ?><li><?= $escape(Review::paris($past['checked_at']).' — '.$past['message'].' — '.($past['main_sha']??'sans commit observé')) ?></li><?php endforeach ?></ul></details><?php endif ?>
<h3>Vérification du formateur</h3>
<form method="post" action="/quiz-admin/remises/save" class="remise-edit">
<input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="course" value="<?= $escape($course) ?>"><input type="hidden" name="id" value="<?= $escape($detail['id']) ?>"><input type="hidden" name="version" value="<?= (int)($form['version']??$review['version']) ?>">
<label for="review-status">État de vérification</label><select id="review-status" name="status"><?php foreach (Review::STATUSES as $key=>$label): ?><option value="<?= $key ?>" <?= ($form['status']??$review['status'])===$key?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach ?></select>
<label for="review-comment">Commentaire privé du formateur</label><textarea id="review-comment" name="comment" maxlength="5000"><?= $escape($form['comment']??$review['comment']) ?></textarea>
<p class="remise-note">La validation est votre décision. Aucun état « Vérifié » n’est attribué automatiquement.</p><button class="btn" type="submit">Enregistrer le suivi</button>
</form>
<?php if (isset($review['updated_at'])): ?><p class="remise-note">Dernière modification : <?= $escape(Review::paris($review['updated_at'])) ?> par <?= $escape($review['updated_by']??'') ?>.</p><?php endif ?>
<h3>Autres envois au même nom déclaré</h3><p class="remise-note">Ce rapprochement par nom ne certifie pas l’identité ; les homonymes peuvent apparaître ensemble. Chaque envoi conserve sa date et son suivi.</p>
<?php if ($history===[]): ?><p>Aucun autre envoi à ce nom.</p><?php else: ?><ul><?php foreach ($history as $r): ?><li><a href="<?= $escape($link($r['id'])) ?>"><?= $escape($r['received_label'].' — '.$r['reference']) ?></a> · <?= $escape($r['github_url']) ?></li><?php endforeach ?></ul><?php endif ?>
<?php endif ?>
</div></section>
