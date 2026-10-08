# Lot 4b — Dérogations techniques individuelles

Date : 8 octobre 2026. Contrat approuvé par le chef de projet et le lead. Lot4a publié et vérifié au commit243833e, PR#11. Décisions figées : octroi seulement lobby/running, une active par élève/génération, modification par révocation explicite puis nouvel octroi, provider de causes production vide en4b. Développement4b autorisé sur agent/esgi-quiz-technical-overrides ; aucune activation automatique5/6 dans ce lot.

## Résultat et périmètre

Le propriétaire du quiz ou super-administrateur peut accorder, puis révoquer, une dérogation à un élève pour les contrôles `browser`, `tracking`, ou les deux. Elle concerne une session, un étudiant réel et la génération de suivi courante, y compris avant la première connexion. Motif privé obligatoire à l'octroi et à la révocation. Aucun scope manuel, aucune dérogation collective.

Ce lot fournit le contrat d'évaluation et le cycle administratif ; aucune vérification automatique du navigateur ou du suivi n'est activée avant les lots 5/6. Une dérogation retire les causes des scopes choisis de la décision finale sans modifier les observations, causes ou preuves réelles et sans déclarer un contrôle sain. Un blocage manuel demeure absolu, indépendant des scopes et persistant jusqu'à sa levée explicite.

La seule information d'accès élève ou board reste la permission finale `access_allowed`. Aucun motif, scope, acteur, type de contrôle échoué, exemption ou indicateur « suivi limité » dans ces projections, y compris quand la dérogation permet l'accès. Refus générique « Accès indisponible. Contactez le formateur. ». La supervision privée peut afficher la dérogation, les causes encore actives et « Autorisé par le formateur — suivi limité » uniquement lorsque tracking est exempté et applicable. Pour browser seule, afficher dérogation navigateur et scopes privés sans déclarer le suivi défaillant.

## Validations et droits

- Administrateur authentifié, propriétaire courant du quiz (super selon modèle existant), étudiant appartenant réellement à la session ; contrôle au service et au contrôleur.
- ID positifs scalaires et génération attendue exactement égale à celle relue sous verrou ; jamais génération choisie ou remplacée silencieusement côté client.
- Scopes : tableau non vide de chaînes, valeurs strictes `browser`/`tracking`, sans inconnus ni doublons ; ordre canonique pour audit. Une sélection n'exempte jamais l'autre scope.
- Motif UTF-8 normalisé comme 4a, 1 à 1 000 caractères après réduction des espaces, sans caractères de contrôle. Octroi et révocation ont chacun leur motif propre ; celui de l'octroi reste conservé.
- POST et CSRF administrateur obligatoires. Aucun endpoint élève ne choisit causes, scopes, statut de dérogation ou acteur. Aucun GET modifiant.
- Une seule dérogation active par `(session, étudiant, génération)`. Proposition petite : octroi si aucune active ; sinon refus `override_exists`, et révocation explicite avant nouvel octroi. Pas de remplacement implicite ou changement silencieux de scopes.
- Proposition d'état : octroi/révocation seulement en `lobby`/`running`, pas en `armed`/`closed`. « Avant connexion » concerne un élève sans tentative dans une salle ouverte. Un octroi en lobby est annulé au lancement suivant : aucune promesse de le reporter sur la nouvelle génération.

## Migration additive proposée

Table indépendante `quiz_technical_overrides` :

| Champ | Contrat |
|---|---|
| `id` | INTEGER PK AUTOINCREMENT, identifiant de la décision d'octroi |
| `session_id`, `student_id` | Identité administrative, sans FK supprimable |
| `tracking_generation` | Nonce serveur au moment de l'octroi, non vide |
| `scope_browser`, `scope_tracking` | Booléens SQL, au moins l'un vrai |
| `status` | `active`, `revoked`, `expired` |
| `grant_reason`, `granted_at`, `grant_actor_id`, `grant_actor_name` | Motif et auteur/date serveur immuables |
| `first_name`, `last_name` | Identité figée à l'octroi, sans code/token/IP/UA |
| `ended_at`, `end_actor_id`, `end_actor_name` | Transition terminale serveur, nullable tant qu'active |
| `end_reason`, `end_kind` | Motif de révocation ou raison de cycle contrôlée (`closed`, `new_launch`, `reset`) |

Index unique partiel `(session_id, student_id, tracking_generation) WHERE status='active'`, index `(session_id,id)` et étudiant/session. Chaque nouvel octroi est une nouvelle ligne ; scopes, sujet et motif initial ne sont jamais écrasés par sa clôture. Aucun FK vers étudiant, tentative, session ou acteur : suppression d'un élève et reset ne purgent ni décisions ni audit. Les lectures d'histoire passent par le propriétaire du quiz vivant ; suppression manuelle du quiz parent conserve les lignes mais les rend inaccessibles dans l'UI. Toute future suppression de quiz doit définir sa politique de conservation.

Pas de colonne contenant cookie, identifiant brut de session PHP, CSRF, jeton de challenge, contenu Forms ou payload arbitraire. Les causes automatiques futures et leurs preuves nécessiteront leur diagnostic privé explicite dans 5/6 ; elles ne doivent pas être stockées à la place d'une dérogation administrative.

## Contrats service et routes proposés

`grantTechnicalOverride(sessionId, studentId, expectedGeneration, scopes, reason)` et `revokeTechnicalOverride(sessionId, studentId, overrideId, expectedGeneration, reason)` : contexte teacher obligatoire ; begin transaction, verrou de session en premier write, relecture propriétaire/étudiant/état/génération, changement de ligne puis audit atomique, commit. Aucune reprise avec la génération fraîche si l'ancienne est périmée. Échec SQL/audit : rollback intégral et aucun audit de réussite. Révocation d'une ligne terminale ou étrangère : refus sans effet.

`getStudentAccess` conserve les champs manuels de 4a et ajoute un objet privé `technical_override` ciblé ; les causes futures sont présentées dans des contextes privés distincts, jamais fusionnées en une cause globale de l'élève. Consultation des lignes historiques par `listTechnicalOverrides(sessionId, studentId?, beforeId, limit)` avec métadonnées bornées, propriétaire obligatoire et identité figée disponible après suppression de l'élève. Aucun mutateur d'archive.

POST `/quiz-admin/access/override/grant` : `id`, `student_id`, `generation`, `scopes[]`, `reason`, `_csrf` administrateur. POST `/quiz-admin/access/override/revoke` ajoute `override_id`. Formulaires privés dans roster et fiche ; génération et ID de décision ne font pas autorité sans validation serveur. Après succès, redirection vers la session. Erreurs administratives utiles et privées ; aucune modification des transports élèves de 4a.

## Cycle atomique et expiration persistante

| Action | Dérogation | Manuel/compteurs/observations |
|---|---|---|
| GET/poll, rechargement, heartbeat | Conservée dans sa génération | Manuel inchangé |
| Finish/resume, y compris replay | Conservée ; décision d'accès fraîche avant effet/ACK | Fin réversible inchangée |
| Stop | Conservée jusqu'au prochain launch/close/reset | Sémantique historique conservée |
| Launch ou relaunch | Actives expirées `new_launch`, puis nonce neuf | Manuel et journal/compteurs courants conservés |
| ResetAndRelaunch | Snapshot pré-reset avec contexte encore actif, expiration `reset`, audit/reset/nonce/révision dans la même transaction | Archives complètes, courant remis à zéro, manuel conservé |
| Close | Actives expirées `closed` durablement, même si nonce inchangé | Manuel conservé |
| OpenLobby après close | Aucune réactivation des lignes `expired` | Nouveau grant seulement explicite et valide |
| Révocation | Ligne `revoked` durablement | Réévaluation depuis les causes actuelles ; aucune effacement des causes |

L'expiration de toutes les lignes active du quiz, y compris d'une ancienne génération, fait partie de la même transaction et du même verrou que le lancement/reset/clôture et leurs audits3b. Audits ciblés `override_granted`, `override_revoked`, `override_expired`, avec ID de décision, sujet figé, génération, scopes, statut, motifs et acteur/date appropriés. Pour un cycle touchant plusieurs élèves, audit ciblé par décision pour respecter la borne16K de chaque before/after, plus l'audit normal de cycle ; aucune énorme liste de causes ou de payloads. Acteur d'expiration = administrateur ayant demandé le cycle. Aucun audit de succès isolé si le cycle échoue.

Une génération différente suffit à exclure une dérogation du calcul, mais cette comparaison seule ne remplace pas l'expiration persistante : close puis openLobby peut conserver le même nonce. Le statut terminal empêche donc sa renaissance. Une tentative de révoquer/octroyer avec l'ancien nonce ne peut écrire ni réactiver une exception.

## Évaluation et intégration future 5/6

Décision interne : `allowed = !manualBlocked && aucuneCauseActiveNonMasquée`. Chaque cause technique serveur a un scope allowlist `browser` ou `tracking`. Une dérogation est applicable seulement si elle est `active`, appartient au bon étudiant/session et porte la génération courante. Elle masque uniquement les causes des scopes accordés ; le jeu original de causes reste intact, consultable dans le diagnostic privé. Révocation relit les causes actuelles, sans réutiliser un ancien résultat autorisé.

4b doit fournir un évaluateur partagé testable (fonction métier pure ou provider interne contrôlé) avec fixtures de causes fiables ; le provider de production reste vide tant que 5/6 n'introduisent pas ces contrôles. Scopes et booléens cause active sont normalisés strictement ; un scope inconnu n'est jamais ignoré silencieusement. Aucun stockage ni endpoint de causes synthétiques de test dans l'application livrée. La projection publique reçoit uniquement le booléen final, jamais les entrées de cet évaluateur. Les refus ne donnent aucune information sur le nombre ou le scope des causes masquées/restantes.

Préparer un contexte interne résolu par le serveur : session, étudiant, tentative éventuelle, génération et référence opaque au contexte de cookie/lease ; les paramètres élèves ne choisissent pas cette référence. Une décision élève utilise seulement les causes de son propre contexte valide, puis la politique manuelle et la dérogation de son élève/génération. Si navigateur A est sain et B échoue avec le même code, B ne profite pas d'A sain et ne bloque pas A sain par agrégation. Le contexte privé ciblé comporte des causes allowlistées, état/date de constat, scope et statut sous masque ; ses codes indiquent un diagnostic, jamais une preuve de fraude. Identifiant opaque sans secret, aucun cookie/CSRF/challenge dans une projection ou copie. L'implémentation et persistance du contexte automatique restent5/6.

La projection du board n'évalue pas un pseudo-contexte composé de toutes les causes de l'élève ; elle devra garder un contrat générique sûr séparé du diagnostic par contexte. En4b sans contrôles automatiques, le badge manuel4a reste valable. La politique future du navigateur est déclarée par le formateur, avec environnement canonique (famille/majeure/capacités nécessaires), pas octets UA bruts ; variations mineures/GREASE ne révoquent pas une preuve. Les capacités de plein écran ne deviennent nécessaires que si cette politique l'exige. Ces règles sont préparatoires à6, aucune classification automatique ajoutée4b.

Les preuves futures du suivi/navigateur seront liées au contexte serveur cookie + tentative + génération, pas à la seule tentative. Deux navigateurs reprenant le même code doivent avoir des preuves distinctes. La dérogation administrative par élève/génération et le contexte de preuve par navigateur sont deux identités différentes : l'exception ne transforme pas la preuve de l'autre navigateur en preuve saine. Aucun cookie ou secret de lease dans audit, snapshot ou CSV. Le contrat exact de lease sera implémenté et testé en5/6.

Le booléen public garde les propriétés4a : état200 réversible, pas de nouvelle `form_url` si refus, observation/polling actifs, guard fraîche pour finish/resume avant replayUID, masque générique prioritaire et iframe/saisie conservées. Aucun nouvel état public « sous dérogation », aucune variation de fin du temps ou de pause individuelle. Une URL Forms déjà délivrée reste inspectable et n'est pas révoquée chez Google.

## Archives, audit et exports

Les snapshots futurs format1 enrichissent seulement `access_context` optionnel avec la dérogation applicable et son statut/motif/scopes/acteur/date privés au moment du snapshot. Les causes sélectionnées pour diagnostic dans5/6 seront allowlistées et bornées, jamais des preuves/headers bruts. Les anciennes archives3b/4a restent inchangées ; une information absente signifie « contexte technique non conservé », aucun remplissage depuis les politiques courantes.

Rapport et CSV privés conservent la distinction courant/copie avant reset, générations, état manuel et décisions terminales. L'identité et le motif originaux demeurent disponibles après suppression de l'étudiant, transfert du quiz ou nouveau lancement. Les API/DOM élèves et board continuent leurs allowlists4a sans scopes, motif, auteur ou indicateur privé. Le statut « suivi limité » est réservé au formateur.

## Recette bloquante proposée

1. Deux élèves A/B ; grant A avant tentative, deux scopes possibles, B sans effet. Grant dans génération actuelle, changement de génération rend l'ancienne requête invalide sans nouveau grant.
2. Évaluateur : browser seule n'exempte pas tracking, tracking seule n'exempte pas browser, les deux masquent les deux ; jeu brut des causes identique avant/après. Aucun scope inconnu/vide/mixte incorrect, doublon ou motif invalide accepté ; borne UTF-8 exacte1000 acceptée. Fixtures de deux contextes du même élève : B ne profite pas d'A sain et ne bloque pas A sain.
3. Manuel actif + n'importe quelle dérogation donne toujours refus ; lever le manuel laisse les autres causes restantes applicables. Aucun heartbeat, recalcul ou préflight ne lève le manuel.
4. Rechargement/finish/resume conservent le grant. Stop conserve, stop→launch expire. Launch, reset et close expirent atomiquement ; close→openLobby avec même nonce ne réactive pas. Deux cycles rapides même seconde conservent transitions distinctes.
5. Revoke rend les causes actuelles bloquantes, y compris après ajout/changement de causes depuis l'octroi ; aucune remise à zéro de cause ou compteur. Grant/revoke état déjà appliqué n'écrit pas d'audit de réussite.
6. Autre owner, owner transféré, couple étudiant/session étranger, aucun contexte, ancien nonce, ID de grant d'un autre élève, GET modifiant, CSRF manquant/faux : refus sans changement/audit. Super-administrateur autorisé selon modèle ; acteur fourni par le serveur.
7. Injecter panne INSERTaudit après octroi/révocation et pendant expiration d'un cycle (après une première décision modifiée) : rollback complet de toutes lignes, session/nonce/fin/compteurs/événements/archives/audit, aucune transaction ouverte.
8. Snapshot reset capture le grant avant expiration ; ancien snapshot immuable, absence de champ = inconnu. Suppression d'un élève conserve décisions/motifs/acteur/identité, transfert change les droits actuels mais pas l'histoire. Pagination et CSV complets cohérents, sans secrets.
9. HTML initial, GET état, ACK observations, finish/replay, DOM/config et API board sans motifs/scopes/acteur/indicateur limité, qu'accès soit autorisé ou refusé. VM suspension/revoke/grant retrouve la même iframe/saisie et ne réaffiche pas un résultat périmé.
10. Non-régression lots1/2/3a/3b/4a, auth/isolation/CSRF ; fixtures de causes internes seulement pour les tests. Aucun refus automatique navigateur/tracking en production4b.

## Publication

Après4a publié et autorisation explicite4b : branche dédiée, sauvegarde SQLite cohérente, migration additive, développement ciblé, gel, revue lead, QA HTTP/Chromium, commit/publication seulement après validations. Aucun secret ni donnée privée publié. L'ancien code ignorerait les dérogations/causes futures : toute intervention garde la base additive, gèle l'épreuve et les actions pertinentes, et privilégie un correctif en avant ; ne pas restaurer destructivement une vieille base.

Choix validés : table historique des octrois puis terminaison ciblée, un active par élève/génération ; refus de remplacement implicite ; octroi uniquement lobby/running ; intégration pure des causes avec provider vide en production4b. Manifeste précis à finaliser au gel : stockage/service/controller admin/i18n, contrôles et vues privées, tests/documents. Aucun besoin de modifier le transport élève ou les allowlists board déjà livrées4a.

## Implémentation 4b et vérifications locales

- `QuizAccessEvaluator::evaluate(bool manualBlocked, array causes, array scopes = []): bool` valide tous les scopes et booléens des causes. `normalizeScopes` refuse vide/inconnu/doublon et rend l'ordre canonique. Le provider protégé `currentTechnicalCauses` retourne `[]` en production ; les fixtures restent uniquement dans les tests. Le contexte préparatoire contient session/étudiant/tentative/génération et une référence actuellement null, sans persistance ni preuve automatique ajoutée.
- `grantTechnicalOverride(...): int`, `revokeTechnicalOverride(...): void`, `getStudentAccess.technical_override` objet actif typé ou null, `listTechnicalOverrides(sessionId, studentId = null, beforeId = 0, limit = 25)` retourne `rows`/`next_before`, limite maximale50. Les octrois terminés sont des lignes historiques indépendantes et immuables dans leurs champs d'origine.
- Le nonce initial est désormais généré dans `createSession`, nécessaire au premier lobby avant launch. Les anciennes sessions continuent leur initialisation additive3a ; close/openLobby conservent le nonce. L'expiration lit des pages50 par ID, ferme le curseur puis mute/audite sous le même verrou, pour garantir le parcours SQLite et borner la mémoire.
- En4b, l'interface privée affiche seulement « Dérogation de suivi active » ou « Dérogation navigateur active ». Le libellé d'autorisation/suivi limité attend un contexte automatique établissant son applicabilité en5. Board conserve sa projection manuelle générique, sans agréger des diagnostics de plusieurs navigateurs. Aucun JS élève, endpoint public ou transport journal n'est modifié.
- `QuizTechnicalOverrideTest.php` couvre les scopes stricts, les causes intactes et deux contextes isolés, préconnexion et nonce périmé, motifs UTF-8 aux bornes, fin/reprise/replay frais, cycles, manuel persistant, snapshot avant expiration et ancienne clé absente versus null, 53 actives multi-générations avec panne au deuxième batch, rollback SQL octroi/révocation/expiration/reset, pagination et CSV complets, confidentialité, POST/CSRF/identifiants et transfert/suppression. Les tests PHP/VM des lots1/2/3a/3b/4a et auth/isolation restent la régression ciblée.

Manifeste applicatif : `app/Config/i18n.php`, `app/Controllers/QuizAdminController.php`, `app/Services/QuizDbService.php`, `app/Services/QuizService.php`, `app/Views/quiz/admin/access-controls.php`, `app/Views/quiz/admin/history.php`, `app/Views/quiz/admin/report.php` ; ajouts `app/Services/QuizAccessEvaluator.php` et `app/Views/quiz/admin/override-details.php`. Tests et documents ne sont pas publiés comme fichiers applicatifs. Sauvegarde cohérente avant migration ; ancienne version ne connaissant pas ces décisions : geler les actions de cycle/règles/arbitrages pertinentes, garder la base additive et corriger en avant.

Revue finale approuvée et recette indépendante acceptée : huit tests PHP, quatre VM et harnais HTTP hors dépôt SHA256 `5FC81D4516CE2759C228B108F904798908344768490EE781873C071C36492B1F`. Cinquante-trois octrois HTTP au premier lobby, nonces anciens synthétiques, panne atteinte au51e audit dans launch/close/reset avec rollback de toutes les tables et transactions fermées ; reset réussi avec53 expirations et53 audits. Snapshots actif/null/inconnu, droits/CSRF, révocation, privé/export et transfert/suppression passent. Le JS élève étant inchangé, la preuve de conservation iframe/saisie du test Chromium4a reste applicable. Aucune preuve par cookie ni contrôle automatique n'est annoncé en4b ; provider de production vide jusqu'aux lots5/6.
