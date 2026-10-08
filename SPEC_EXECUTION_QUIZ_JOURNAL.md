# Lot 3a — Journal des observations

Date : 8 octobre 2026. Statut : implémenté et testé localement, revue et recette de publication requises.

## Comportement livré

- Chaque sortie observée produit immédiatement `away_start`, même si aucun retour ne parvient au serveur. Sa durée demeure inconnue. Chaque retour produit `hidden`, `blur` ou `fullscreen_exit`, y compris une absence de 0 à 999 ms.
- Les transitions d'une source gardent leur propre durée monotone, `event_uid`, `absence_uid` et identifiant du départ. Le retour fullscreen tardif ne prolonge jamais une durée hidden/blur déjà mesurée. Les durées sont bornées à 3 600 000 ms ; la borne est affichée comme minimum (`≥`), exportée avec précision `ms_minimum`.
- Hidden et blur qui se chevauchent partagent un épisode. Un retour réel page/fenêtre ferme cet épisode même si le plein écran reste quitté. Un fullscreen chevauchant rejoint au plus le premier épisode page ; son retour conserve l'UID attribué au départ. Cette règle s'applique que le plein écran soit obligatoire ou facultatif. Une modification du réglage ne supprime pas une durée factuelle ouverte.
- Au plus un retour éligible représente un incident par `(attempt_id, absence_uid)` : le premier ID éligible. Les autres observations sont conservées. La qualification et la requalification utilisent `duration_ms`, avec repli sur les secondes des anciens événements. Fullscreen et reload suivent leurs règles actuelles ; départs et diagnostics ne sont jamais des incidents.
- Excuser/rétablir un représentant arbitre tout son épisode, y compris départs et retours inéligibles. Les retours reçus ensuite héritent de l'excuse. L'arbitrage et le recompte sont atomiques, avec verrou d'écriture avant lecture.

## Transport et identité

- Seules les observations sont mises en file, jamais `finish`/`resume`. Fin et reprise restent des requêtes explicites avec accusé, liées à la tentative et à leur UID. Répéter un ancien finish après reprise renvoie l'état courant sans répéter l'effet.
- File séquentielle en `sessionStorage`, clé tentative/génération, 100 observations maximum, TTL 24 h, charge utile inférieure à 1 Ko par observation. Réessai toutes les 3 s et au retour réseau ; requête annulée après 12 s. Un échec réseau/503 ou un accusé d'une autre tentative garde le même UID. Beacon tente l'envoi lors du départ mais ne retire aucun élément sans accusé.
- Aucune réponse Forms, texte saisi ou contenu de touche n'est collecté. Les raccourcis produisent seulement leur catégorie existante. Débordement, expiration et défaut de persistance produisent un diagnostic borné `dropped_events`, réservé au journal formateur.
- Le corps API est limité à 4 Ko ; champs autorisés, sources, IDs et nombres sont validés. `event_uid` est unique par tentative. Une répétition identique accuse le journal original sans modifier date/données/effets/compteur. Un UID réutilisé avec un autre contenu est refusé. Les départs/retours reçus dans le désordre restent corrélés ; des relations contradictoires sont refusées.
- Salle, état, heartbeat et événements sont liés à l'`attempt_id` attendu. La génération aléatoire de 128 bits est renouvelée à chaque lancement et reset. Un ancien onglet A ne peut pas attribuer sa file à B par changement de cookie ; une ancienne file ne repeuple pas un reset, même effectué dans la même seconde. Reset et renouvellement du nonce sont atomiques ; la politique d'effacement préexistante reste celle du lot 2.
- Toute erreur API élève est générique (`access_unavailable`). Tout conflit 409 déclenche un GET d'état lié : état valide actualise la génération ; GET refusé masque la salle avec seulement « Accès indisponible. Contactez le formateur. ». Aucun compteur de pertes, motif technique, cause de refus ou diagnostic détaillé n'est rendu dans la salle élève.

## Lecture et horodatage

Fiche élève, rapport, supervision privée et CSV affichent source, corrélation et précision. Les listes de fiche/rapport/export gardent tout l'historique, au-delà de 100 lignes ; le fil en direct reste une fenêtre de 100 lignes. Le CSV ajoute les colonnes après les anciennes et nomme sa date `reception_serveur`.

`created_at` est la première réception serveur, jamais l'heure attestée de l'action. La connexion peut différer cette réception. `duration_ms` est une mesure monotone client ; sa précision ne prouve pas son authenticité. L'heure locale de la file sert uniquement au TTL et n'est pas transmise. Un départ sans retour est lisible avec sa durée inconnue ; aucune durée n'est fabriquée lors d'un rechargement ou d'un arrêt.

## Migration et limites

Migration additive : `quiz_sessions.tracking_generation` ; `quiz_events.event_uid`, `absence_uid`, `source`, `related_event_uid`, `tracking_generation`, `duration_ms`, `dropped_events`, toutes colonnes nullable. Index UID unique pour les valeurs non nulles, index tentative/épisode. Les anciennes sessions reçoivent un nonce sans changer état ni dates. Les anciens événements et clients restent supportés.

La collecte dépend d'un navigateur exécutant le code ; les blocages de suivi et contrôles navigateur arrivent dans les lots suivants. `sessionStorage` conserve la file au rechargement de l'onglet, pas après sa fermeture définitive. Une sortie sans retour ou un départ perdu reste factuel et incomplet. Les angles morts de l'iframe externe demeurent. L'archivage avant reset, l'audit indépendant et les archives de générations sont réservés au lot 3b.

## Tests locaux

- `QuizEventJournalTest.php` : véritable reconstruction d'ancien schéma et migration, 750/999/1000 ms, départ sans retour, durées par source, qualification unique et requalification, deux épisodes page malgré fullscreen sorti, excuses et retours tardifs, rollback SQL arbitrage/reset, UID et contenu, tentative/génération, relance/reset rapides, limites, diagnostics, anciens clients, journal/export >100.
- `QuizEventJournalJsTest.js` : moniteur réellement rendu et module en VM ; envoi immédiat, micro-absence, horloge monotone malgré saut de l'heure locale, sources répétées, deux épisodes avec fullscreen off/on et les deux ordres de départ, bornes, coupure/renvoi/reload, accusé, Beacon, saturation/TTL, générations et contexte cookie, message générique et overlays détachés.
- Non-régression ciblée : `QuizIncidentReclassificationTest.php`, `QuizLiveSettingsTest.php`, `QuizLiveSettingsJsTest.js`, authentification/isolation ; syntaxe et diff propre. La recette Chromium et la recette serveur restent à effectuer par le chef/testeur.

## Manifeste applicatif et publication

Fichiers existants modifiés (11) :

1. `app/Config/i18n.php`
2. `app/Controllers/QuizAdminController.php`
3. `app/Controllers/QuizController.php`
4. `app/Helpers/url.php`
5. `app/Services/QuizDbService.php`
6. `app/Services/QuizService.php`
7. `app/Views/quiz/admin/attempt.php`
8. `app/Views/quiz/admin/report.php`
9. `app/Views/quiz/admin/session.php`
10. `app/Views/quiz/room.php`
11. `public/assets/js/quiz-monitor.js`

Ajout distinct : `public/assets/js/quiz-journal.js`. Aucun test, document, secret ou configuration n'est à envoyer au serveur.

Sauvegarde SQLite cohérente obligatoire avant la migration ; sauvegarder et vérifier les versions distantes des fichiers. Publier sans quiz réel en cours, puis vérifier migration, empreintes et recette à deux élèves fictifs.

**Retour arrière métier non transparent :** le lot 2 ne sait pas dédupliquer les épisodes lors de ses recalculs/arbitrages. Après réception d'observations 3a, restaurer seulement ses fichiers peut doubler des incidents ou réactiver une excuse. En cas de retour exceptionnel vers le lot 2, geler les sauvegardes de paramètres et les arbitrages, préserver base/journal et privilégier un correctif en avant. Restaurer une ancienne base effacerait les nouvelles traces ; cela nécessite une décision explicite. Ne pas supprimer les colonnes pour une simple restauration de code.
