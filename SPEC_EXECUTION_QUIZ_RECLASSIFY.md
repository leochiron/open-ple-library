# Lot 2 — Requalification de l'historique des incidents

Date : 8 octobre 2026. Statut : implémentation locale, revue et recette de production à effectuer.

## Résultat attendu

Enregistrer les paramètres requalifie les événements conservés de ce quiz avec les nouvelles règles, puis recalcule tous les compteurs et statuts. Le fil formateur remplace ses anciennes lignes au prochain rafraîchissement. Les événements bruts, arbitrages et fins déclarées restent intacts.

## Règles de qualification

- `hidden` et `blur` deviennent incidents si leur durée enregistrée est supérieure ou égale au seuil.
- `fullscreen_exit` suit le même seuil uniquement lorsque le plein écran est obligatoire.
- `reload` devient incident uniquement lorsque cette règle est activée.
- Les autres événements ne sont pas requalifiés par ce lot.
- Un incident excusé reste visible mais ne compte pas dans le quota. L'excuse reste conservée même si une règle retire puis rétablit la qualification d'incident.
- Statut : zéro incident non excusé → `started` ; un nombre inférieur au quota → `suspect` ; quota atteint → `invalid`. La fin déclarée est un état distinct conservé dans `finished_at`.

La même fonction qualifie les nouveaux événements et l'historique. Le format actuel garde les durées en secondes entières ; aucune précision supplémentaire n'est inventée pour les anciens événements.

## Cohérence du stockage

La sauvegarde des paramètres, la requalification et les compteurs de toutes les tentatives partagent une transaction SQLite. Une erreur annule l'ensemble. Seuls `is_incident`, `incident_count` et `status` sont dérivés ; types, durées, dates, identifiants, excuses et fins ne sont pas remplacés.

Un nouvel événement prend le verrou d'écriture avant de relire la session et la tentative, puis utilise les règles réellement courantes et recompte les incidents depuis la base. Une requête qui avait lu un ancien compteur ou seuil ne peut pas réintroduire ces valeurs. Un `finish` ou `resume` refusé ne laisse aucun événement enregistré.

Aucune migration. La publication seule ne requalifie pas les données : la requalification se produit à l'enregistrement des paramètres.

## Affichage en direct

Le payload d'état et le fil portent une version calculée depuis seuil, quota, règle de plein écran et règle de rechargement. Une durée ou un titre modifié seul ne provoque pas de reconstruction du fil.

La page de session transmet sa version dans la requête d'événements. Si elle a changé, le serveur renvoie les **100 événements les plus récents**, avec leur qualification actuelle, et demande le remplacement des anciennes lignes. Le curseur repart du dernier événement réellement affiché ; l'historique complet reste conservé et consultable par élève ou export. Les requêtes du fil ne se superposent pas et les champs d'édition non enregistrés restent intacts.

Le tableau projeté conserve son curseur habituel : requalifier un ancien événement ne crée pas de nouvelle notification ou de son. Les compteurs et couleurs sont actualisés par la réponse des tentatives. Détail individuel, rapport et export utilisent la qualification actuelle lors de leur ouverture ou génération ; ce lot n'ajoute pas de rafraîchissement automatique à ces écrans statiques.

## Validation locale

- `QuizIncidentReclassificationTest.php` : deux élèves, absences 7 s et 12 s, seuil 10 → 5 → 15 ; activation/désactivation plein écran et rechargement ; quota ; arbitrage conservé après plusieurs changements ; fin intacte ; événement envoyé avec anciens snapshots ; payload réel du fil ; fenêtre récente avec plus de 100 événements ; retour arrière complet après erreur SQL injectée ; refus de fin/reprise hors quiz lancé.
- `QuizLiveSettingsJsTest.js` : conservation de tous les scénarios du lot 1, remplacement de lignes anciennes sans doublons, alerte supprimée ou excusée, curseur et version suivants, état vide, absence de notifications de requalification sur le tableau, édition non enregistrée intacte.
- Tests ciblés d'authentification et d'isolation, syntaxe et diff propre.

## Recette de production

Avec élèves fictifs A et B dans une session de test, seuil initial 10 s : A sort 7 s, B 12 s. Passer à 5 puis 15 s. Vérifier compteurs et couleurs, anciennes lignes du fil, détail, rapport et export. Tester quota 2 → 3, règles rechargement et plein écran, puis excuser un événement et terminer A avant de changer à nouveau les règles. L'excuse et la fin restent conservées, B reste indépendant, les événements gardent dates/durées/identifiants. Aucun ancien événement ne résonne sur le tableau projeté.

## Publication et retour arrière

Manifeste applicatif : `app/Services/QuizService.php`, `app/Controllers/QuizAdminController.php`, `app/Views/quiz/admin/session.php`, `app/Config/i18n.php`.

Sauvegarde SQLite cohérente requise avant les essais de recalcul, sans copie FTP d'une base en cours d'écriture. Publier ensemble les quatre fichiers hors épreuve réelle, après sauvegarde de leurs versions distantes. Le retour arrière de code restaure ces quatre fichiers sans écraser la base : les événements bruts et arbitrages sont conservés et la qualification dérivée reste celle des dernières règles enregistrées. Aucune restauration automatique de données n'est autorisée pour annuler l'upload.

Hors périmètre : nouvelles transitions et déduplication (lot 3), historique des relances (lot 3), blocages et dérogations (lot 4), détection du suivi/navigateur (lots 5 et 6), précision milliseconde future.
