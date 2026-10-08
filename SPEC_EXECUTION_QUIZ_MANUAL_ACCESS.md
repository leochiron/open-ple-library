# Lot 4a — Accès serveur et blocage manuel individuel

Date : 8 octobre 2026. Lot 3b publié (9bfc0a2). Lot 4a implémenté, approuvé par la revue finale et accepté par la recette indépendante. Les éléments 4b sont des exigences futures, hors implémentation de ce sous-lot.

Découpage retenu après avis lead : **4a** centralisation d'accès, blocage manuel/levée, confidentialité, CSRF/binding et audit ; **4b** dérogations techniques et leur cycle. Chaque sous-lot reçoit son commit, revue, recette et publication. Les critères de scopes/dérogations ne sont requis qu'en4b ; aucune règle automatique5/6 n'est activée avant4b validé.

## Résultat

Le formateur peut bloquer un élève, y compris avant sa première connexion, puis lever ce blocage explicitement. La décision serveur est indépendante des incidents et de la fin déclarée. Le futur lot 4b ajoutera l'octroi et la révocation de dérogations techniques navigateur/suivi. Ce lot prépare les contrôles automatiques 5/6 sans les activer ni prétendre attester un navigateur.

Les erreurs et suspensions élèves affichent seulement « Accès indisponible. Contactez le formateur. ». Les motifs précis, contrôles exemptés, acteur et diagnostics sont réservés au formateur propriétaire. Aucune cause privée dans DOM, attributs, JSON initial, API élèves ou tableau projeté.

## Décisions de portée

- Identité de l'accès : session + étudiant, pas IP/UA/token fourni par l'élève. Le blocage manuel persiste jusqu'à sa levée explicite ; un nouveau lancement ne le lève pas. Blocage et levée demandent un motif court privé (texte normalisé, 1 à 1 000 caractères), afin de conserver le contexte de la décision.
- Dérogation : session + étudiant + génération courante ; motif obligatoire, acteur/date serveur, scopes `browser` et/ou `tracking` strictement validés. Aucun scope manuel ; aucune dérogation classe entière.
- Expiration à clôture ou nouveau lancement/reset ; ne pas expirer à finish/resume, qui est réversible. Stop puis launch est un nouveau lancement. Rechargement conserve l'exception courante.
- Une dérogation masque les causes des scopes choisis, sans les effacer ni fabriquer un contrôle sain. Révocation remet immédiatement les causes actives en vigueur. Aucun signal élève, heartbeat, recalcul d'incidents ou précontrôle futur ne lève un blocage manuel.
- Les lots5/6 introduiront des causes contrôlées par le serveur. Aucun endpoint élève ne permet de choisir librement les causes, scopes ou état d'accès.
- Horloge collective et comportement historique de fin du temps conservés ; aucune pause individuelle ni nouvelle règle de coupure au dépassement dans4.

## Service et stockage

Centraliser l'évaluation de l'accès et relire l'état sous verrou pour les décisions modifiantes. Méthodes administratives exigent contexte authentifié et propriétaire courant (superadministrateur selon modèle existant), appartenance réelle étudiant/session et CSRF. Métadonnées d'acteur proviennent du serveur. Stockage additif ; aucune purge des observations, tentatives ou réponses.

Audit atomique des blocages/levées, octrois/révocations/expirations. Conserver motif initial et transitions pour analyse, y compris après reset ou suppression de l'étudiant courant. Les détails historiques doivent conserver l'identité pertinente sans dépendre d'une FK vers une tentative supprimable. Réutiliser l'audit3b pour les décisions formateur ; prévoir une représentation explicite des diagnostics automatiques futurs, distincte d'une preuve de fraude.

La lecture détaillée du statut d'accès exige aussi le contexte administrateur. Les chemins étudiants disposent seulement d'une projection publique réduite. Les archives3b restent immuables ; inclure le contexte d'accès pertinent dans les futurs snapshots, sans réécrire les anciens.

## Routes et interface

- `buildStatePayload` doit filtrer `form_url` dans toutes les réponses et le JSON initial. Refus métier réversible : état200 normal avec `access_allowed:false`, aucune URL du formulaire, aucune cause privée. Conserver polling et collecte disponibles. Les erreurs d'identité/binding restent distinctes et peuvent détacher définitivement la page.
- Vérifier une décision fraîche avant finish/resume, même dans la branche d'accusé UID déjà reçu. Observation factuelle et heartbeat restent disponibles pendant suspension.
- Pages/API élèves privées `Cache-Control: no-store`. Protéger les mutations élèves, y compris transports beacon et nouveaux endpoints futurs ; préserver compatibilité en actualisant tests/fixtures. Le CSRF du journal est ajouté lors du transport, hors UID et métadonnées immuables de la file, pour garder les retries3a cohérents. Les audits/snapshots ne contiennent jamais cookies, CSRF ou jetons de challenge.
- Salle : masque générique sans supprimer l'iframe existante ni sa saisie. Levée : même iframe, aucun rechargement requis ; poll plus fréquent pendant suspension possible. Le formulaire déjà délivré garde une src forcément inspectable et Google peut rester accessible directement : garantie = absence de nouvelle délivrance et blocage des actions de plateforme, pas révocation chez Google.
- Actions individuelles depuis roster/fiche, y compris étudiant sans tentative. Motif et scopes lisibles uniquement dans supervision privée, rapport et export. État « Autorisé par le formateur — suivi limité » exclusivement formateur.
- Tableau projeté : badge générique. Projection API dédiée ou explicitement sûre ; ne pas envoyer le payload privé de supervision au board.

## Recette bloquante

1. A bloqué avant connexion : tentative/identité conservée, HTML/API sans form_url ni marqueur de motif privé. B continue. Levée prend effet sans recharger la salle.
2. Blocage pendant saisie puis levée : iframe identique, compteurs/fin/token inchangés, horloge continue. Finish/replay UID refusés tant que bloqué.
3. **Critère futur 4b** : browser seule n'exempte pas tracking et inversement ; aucune exception ne contourne manuel. Scopes inconnus/vides refusés. En 4a, motif invalide et action déjà appliquée sont refusés sans audit de réussite.
4. **Critère futur 4b** : recharge/finish/resume conservent l'exception ; close/newlaunch/reset l'expirent. En 4a, le blocage manuel reste actif dans tous ces cycles. Révocation des scopes réservée à 4b.
5. Autre propriétaire, mauvais couple étudiant/session, absence de contexte, GET modifiant, CSRF absent/faux : aucun changement ni audit de réussite. Transfert change les droits, jamais l'acteur historique.
6. Panne SQL entre modification et audit : rollback complet. En 4a, les anciennes générations restent rejetées par le binding du journal3a ; création/réactivation d'exception réservée à 4b.
7. DOM/config/JSON élèves et board sans cause/scopes/acteur privés ; détails privés, CSV et snapshots concordent.
8. Non-régression lots1/2/3a/3b et auth/isolation ; revue lead puis QA avant commit/publication.

Publication sur branche dédiée après validations, sauvegarde cohérente si migration, manifeste précis, inspection du schéma serveur et empreintes. Le lot4 n'active pas de refus automatique du navigateur ou du suivi ; ces règles arrivent dans5/6 avec recette sur session fictive.

## Implémentation 4a et transport

Migration additive `quiz_student_access` : clé `(session_id, student_id)`, état manuel, motif, acteur/date serveur, noms conservés. Aucune FK et aucune purge au reset ou à la suppression de l'élève. Les transitions ciblées `access_blocked`/`access_lifted` et leurs noms figés sont conservés dans `quiz_session_audit`, avec la mutation dans la même transaction. Les lectures détaillées suivent le propriétaire actuel du quiz. Une suppression manuelle du quiz parent laisse des données conservées mais inaccessibles par l'interface ; toute future suppression devra définir sa conservation.

Service privé formateur : `setManualAccess(sessionId, studentId, blocked, reason)` et `getStudentAccess(sessionId, studentId)`. Motif normalisé par réduction des espaces ; borne en caractères UTF-8, sans caractères de contrôle. Aucun changement d'état identique n'écrit d'audit de réussite. Les ID reçus par les actions sont numériques positifs scalaires. POST `/quiz-admin/access/block` ou `/quiz-admin/access/lift`, champs `id`, `student_id`, `reason`, `_csrf` administrateur. Actions depuis liste complète et fiche, y compris avant connexion.

Projection élève : `access_allowed` booléen seulement, règles et horloge ordinaires conservées, aucun motif/acteur/état manuel détaillé. La première salle et chaque GET état relisent le contexte courant ; finish/resume relisent sous verrou avant insertion ou replay UID. Refus réversible : HTTP200 avec l'état suspendu, sans URL Forms ni accusé UID. La tentative et la fin antérieure restent intactes. Observations et heartbeat demeurent disponibles. Le masque générique prime sur fin/plein écran/temps écoulé ; les réponses d'état périmées après un refus récent ne peuvent l'enlever. Poll de 3 secondes pendant suspension, horloge collective inchangée. Déblocage restaure la même iframe si elle existait ; un élève initialement bloqué n'a aucune iframe.

CSRF élève : token aléatoire de session transmis dans le formulaire de connexion (`_csrf`), le JSON initial et GET état (`csrf_token`). Le journal ajoute `_csrf` à une copie de transport lors de chaque fetch/Beacon, jamais à l'item persistant ni à son UID/métadonnées immuables. Heartbeat utilise `X-Quiz-CSRF` ; fin/reprise ajoutent le champ JSON. Le contrôleur retire `_csrf` avant validation des métadonnées. Jeton absent/faux : HTTP403 générique et aucune mutation. Aucun cookie, CSRF, challenge ou valeur Forms dans journal, audit ou snapshot. Les refus de connexion et erreurs de fin restent génériques. Pages/API élèves et supervision : `Cache-Control: no-store`.

Board : GET `/quiz-admin/api/board?id=…` utilise deux allowlists explicites (top level et lignes), sans code/motif/acteur/scopes ; badge « Accès indisponible ». GET `/quiz-admin/api/board/events` filtre les types publics en SQL avant LIMIT, puis applique une allowlist, pour qu'un bloc de plus de 100 diagnostics privés ne bloque pas le curseur. Les vues privées conservent les détails. Le CSV ajoute `contexte_acces_prive` après toutes les colonnes existantes. Les nouveaux snapshots format1 ont un champ optionnel `access_context` ciblé ; son absence dans une ancienne copie signifie « contexte non conservé », jamais accès autorisé par défaut ni reconstruction depuis la politique actuelle.

## Vérifications et publication

Tests ajoutés : `tests/QuizManualAccessTest.php` (préconnexion, deux élèves, décision fraîche, replay, cycles, motif UTF-8, SQL rollback, CSRF, privacy board, >100 diagnostics, anciennes copies, droits/transfert/suppression) et `tests/QuizManualAccessJsTest.js` (iframe/saisie conservées, fin/reprise, overlays, réponses périmées, suivi actif, retries/Beacon avec CSRF hors file, board générique). Les harnais et le test API journal3a sont actualisés au CSRF. Non-régressions PHP lots1/2/3a/3b et auth/isolation, VM paramètres/journal/historique validées. Recettes HTTP et Chromium indépendantes par le chef de projet après gel et revue.

Manifeste applicatif existant :

- `app/Config/i18n.php`
- `app/Controllers/QuizAdminController.php`
- `app/Controllers/QuizController.php`
- `app/Services/QuizDbService.php`
- `app/Services/QuizService.php`
- `app/Views/quiz/admin/attempt.php`
- `app/Views/quiz/admin/board.php`
- `app/Views/quiz/admin/history.php`
- `app/Views/quiz/admin/report.php`
- `app/Views/quiz/admin/session.php`
- `app/Views/quiz/join.php`
- `app/Views/quiz/room.php`
- `public/assets/js/quiz-journal.js`
- `public/assets/js/quiz-monitor.js`

Nouveau fichier applicatif : `app/Views/quiz/admin/access-controls.php`. Tests modifiés : `tests/QuizEventJournalTest.php`, `tests/QuizLiveSettingsJsTest.js`, `tests/fixtures/RenderQuizLiveSettings.php`. Documentation : cette spec, `SPEC_MODE_QUIZ.md`, `README.md`. Aucun déploiement de configuration ou de donnée privée.

Sauvegarde SQLite cohérente avant migration. L'ancien code ignore le blocage manuel et son audit : aucun retour arrière ne doit promettre le maintien du contrôle. Geler l'accès à l'épreuve et les actions d'accès/reset/paramètres/arbitrages pendant une intervention, garder la base additive et préférer un correctif en avant ; ne jamais restaurer destructivement une ancienne base.

Les pages chargées avant 4a doivent être rechargées une fois pour adopter le transport CSRF. Publier hors épreuve active ; les requêtes d'un ancien client sans jeton ne bénéficient d'aucun contournement de cette protection.

Recette indépendante finale : onze tests PHP/JS verts et harnais HTTP hors dépôt (SHA256 `32759DFE359C45F1BDBD8E93B572E92D406063D1FEEC5D7520ACF88B37A7D4D8`). Les droits, CSRF, replays refusés sans ACK, rollback d'audit, persistance des cycles, archives, exports et filtrage board avant LIMIT après 105 diagnostics privés passent. Chromium151 avec un formulaire fictif réellement cross-origin confirme la même iframe et la même saisie après blocage/levée, un seul chargement, l'horloge active, aucune collecte de réponse et le refus initial sans iframe. Les décisions API de ce test navigateur sont simulées ; le harnais HTTP vérifie le serveur réel local. Aucun Google Form ni élève réel utilisé.

