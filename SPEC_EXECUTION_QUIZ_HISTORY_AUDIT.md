# Lot 3b — Archives avant remise à zéro et audit formateur

Date : 8 octobre 2026. Statut : implémenté et tests ciblés validés ; revue finale et recette indépendante requises avant publication.

## Périmètre et décisions

Seul `resetAndRelaunch` archive puis remet les compteurs et fins à zéro. `launch` conserve les traces et compteurs actuels, et renouvelle le nonce de suivi. Une archive décrit donc l'état **avant remise à zéro**, pas une exécution unique : elle peut contenir des événements portant plusieurs générations après stop/launch.

Migration additive : `quiz_attempt_archives` conserve un JSON versionné par tentative avec règles ciblées, identité sans code/token, état, compteur, fin et événements bruts complets. Métadonnées : session, tentative source, nonce au moment du reset, acteur et date UTC, `format_version`. Unicité `(session_id, generation, source_attempt_id)` ; aucune clé étrangère vers élève/tentative supprimable. `quiz_session_audit` est indépendant des événements : action, session, acteur réel, date UTC et before/after ciblés uniquement, sans mot de passe, code d'accès, token, payload brut ou données de formulaire.

`session_id` de ces deux tables n'a pas non plus de clé étrangère. Aucune suppression de quiz n'est actuellement proposée ; supprimer manuellement le parent laisserait archives et audit conservés, mais inaccessibles dans l'interface qui exige le quiz courant. Une future suppression de quiz devra définir sa politique de conservation avant d'être introduite.

Reset : verrou d'écriture avant lecture fraîche des règles et tentatives ; snapshots de toutes les tentatives, y compris sans incident/terminées ; audit ; nettoyage courant ; nouveau nonce et incrément de `history_revision`, dans une seule transaction. Toute erreur d'archive, audit, encodage ou volume annule l'ensemble. Les anciens événements ne permettent aucun arbitrage sur une archive. Les nouveaux réglages ne requalifient que le courant. Aucun snapshot pour un élève sans tentative : le roster est conservé et aucun journal n'existe à perdre.

Audit atomique pour paramètres, ouverture, lancement/relance ordinaire, pause, clôture, reset, excuse/rétablissement d'épisode et excuse globale. Il ne suffit pas que l'interface masque une route : accès au service d'archives et d'audit exige un administrateur authentifié et le propriétaire de la session, ou le super-administrateur autorisé par le modèle existant. Les futurs contrôles pourront réutiliser ce journal ciblé.

Les arbitrages conservent noms et sélecteurs tentative/observation/épisode, affichés explicitement avec un lien d'historique. L'audit des paramètres inclut le lien Forms normalisé, le lien d'édition et l'ID du champ ; les valeurs de requête préremplies du lien public ne sont pas copiées, mais un SHA-256 permet de distinguer leur modification. Après transfert du quiz, l'ancien propriétaire perd l'accès et le nouveau le gagne ; identité et acteur historiques restent immuables. Les échecs de reset produisent un diagnostic dans le journal serveur privé (opération, session, exception, message borné, fichier/ligne), sans payload POST et sans audit de succès.

## Consultation et export

Page privée d'historique depuis session/fiche, listes paginées par curseur avec 25 entrées par défaut et 50 maximum. Archives en lecture seule, rapport reprenant les règles/identité/événements figés, et export CSV privé. Le CSV actuel conserve ses colonnes, avec colonnes de section/archive/acteur/date ajoutées et sections distinctes pour archives et audit. Aucun filtre d'historique ne tronque silencieusement un snapshot ou un export.

Liste : sélection des métadonnées seulement, sans charger les JSON. Export : lecture SQLite cohérente et itération par snapshot, puis préparation du CSV dans un flux temporaire avant émission HTTP (débordement disque au-delà de 2 Mio). La borne par snapshot ne borne pas le nombre total d'archives. La révision d'historique est envoyée uniquement aux vues/API administratives ; elle augmente au reset, jamais au lancement ordinaire. Session et tableau réconcilient le courant même à règles identiques, masquent le vieux ticker, ignorent les réponses tardives et ne rejouent pas les anciennes notifications.

Libellés explicites : copie avant remise à zéro, nonce de suivi au reset, générations propres aux événements. Dates d'événements = première réception serveur ; archivage et audit = dates serveur UTC (affichées à Paris). Les durées client conservent leur précision et leurs limites d'authenticité.

## Limites de volume et compatibilité

Maximum 1 000 tentatives par reset, 20 000 événements par tentative, 8 Mio de JSON par snapshot et 64 Mio pour les snapshots du reset. Dépassement : reset refusé explicitement, aucune suppression ni archive partielle ; sauvegarde/extraction et décision d'augmentation de capacité requises. Pas de rétention ni purge automatique des archives/audits dans ce lot.

Sauvegarde SQLite cohérente avant migration. Retour à un ancien code : geler reset, paramètres et arbitrages, conserver la base et les archives, préférer un correctif en avant. Ne jamais restaurer une ancienne base destructivement pour revenir au code précédent.

## Critères et validations

- Deux resets rapides produisent deux archives par tentative ; événements UID/ms/excuses, identité, règles, fins et compteurs antérieurs restent exacts ; courant à zéro et ancienne génération refusée.
- Launch ordinaire ne crée aucune archive ni remise à zéro ; snapshot avec plusieurs nonces correctement libellé.
- Échec SQL archive/audit/recompte et limite de volume : rollback complet, aucune transaction ouverte.
- Admin B et service sans contexte ne lisent ni archive ni audit d'A, ni rapport/export ; ancien event ID ne modifie aucune archive.
- Lecture après suppression d'élève courant, pagination, rapport et CSV cohérents ; archives immuables malgré modifications de règles/arbitrage courant.
- Tests métier 3b et non-régression journal3a, règles1/2, authentification/isolation ; revue lead puis QA indépendante avant publication.

Vérifications locales validées : `QuizHistoryAuditTest.php`, `QuizHistoryJsTest.js`, `QuizLiveSettingsTest.php`, `QuizIncidentReclassificationTest.php`, `QuizEventJournalTest.php`, `QuizAdminAuthServiceTest.php`, `QuizOwnershipIsolationTest.php`, `QuizLiveSettingsJsTest.js`, `QuizEventJournalJsTest.js`. Le test métier utilise une horloge figée pour prouver deux resets dans la même seconde ; le test VM couvre les deux ordres de réponses périmées entre attempts et events. La recette HTTP/navigateur indépendante et la sauvegarde cohérente avant migration sont conduites par le chef de projet.

## Manifeste de publication

Fichiers applicatifs existants :

- `app/Config/i18n.php`
- `app/Controllers/QuizAdminController.php`
- `app/Services/QuizDbService.php`
- `app/Services/QuizService.php`
- `app/Views/quiz/admin/attempt.php`
- `app/Views/quiz/admin/board.php`
- `app/Views/quiz/admin/report.php`
- `app/Views/quiz/admin/session.php`

Nouveau fichier applicatif : `app/Views/quiz/admin/history.php`.

Tests : ajouts `tests/QuizHistoryAuditTest.php`, `tests/QuizHistoryJsTest.js` ; harnais VM `tests/QuizLiveSettingsJsTest.js` étendu aux réponses différées ; libération d'exception en nettoyage dans `tests/QuizEventJournalTest.php` pour éviter de garder le handle SQLite sur Windows. Documentation : cette spécification, `SPEC_MODE_QUIZ.md`, `README.md`. Aucune configuration ni donnée privée à publier.
