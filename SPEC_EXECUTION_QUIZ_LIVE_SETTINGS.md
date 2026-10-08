# Lot 1 — Paramètres du quiz appliqués aux pages ouvertes

Date : 8 octobre 2026. Statut : implémentation locale, revue et recette de production à effectuer.

## Résultat attendu

Une modification des règles est appliquée au prochain rafraîchissement de la salle élève, de la session formateur et du tableau projeté. Google Forms garde le même élément et les réponses en cours de saisie. Les champs du formulaire d'administration gardent leurs modifications non enregistrées.

## Périmètre

- Payload partagé élève/formateur : titre, durée, quota d'incidents, seuil hors page, plein écran obligatoire, règle de rechargement, état et horloge serveur. Nombres entiers et booléens explicites.
- Actualisation des règles affichées et du quota élève ; le tableau projeté utilise le quota courant pour sa couleur d'alerte.
- Écouteurs et bouton plein écran disponibles même si la règle était désactivée à l'ouverture. Activer la règle ouvre le contrôle ; la désactiver le masque et abandonne l'ancienne durée de sortie plein écran.
- Une tentative terminée reste terminée. Fin et reprise continuent d'attendre l'accusé serveur. La reprise conserve le formulaire déjà ouvert.
- Une nouvelle URL Forms est utilisée à la prochaine ouverture du questionnaire ; elle ne remplace jamais silencieusement le questionnaire déjà affiché.

Les rafraîchissements restent ceux de la version ESGI : salle élève 3 s en attente / 15 s pendant le quiz, session formateur 5 s, tableau projeté 3 s. Ils dépendent d'une connexion disponible.

## Hors périmètre

Requalification historique des incidents (lot 2), journal complet et relance sans effacement (lot 3), blocages et dérogations (lot 4), contrôles automatiques et navigateur (lots 5 et 6). Le contrôle plein écran de ce lot conserve son fonctionnement dans la page ; il ne constitue pas un blocage d'accès serveur.

Aucune migration, modification de configuration ou donnée de production.

## Validation locale

- `QuizLiveSettingsTest.php` : deux tentatives, paramètres modifiés puis désactivés, horloge serveur, payload commun et endpoint formateur réel, fin préservée et reprise.
- `QuizLiveSettingsJsTest.js` : pages réellement rendues en PHP, moniteur et scripts formateur exécutés en VM avec navigateur simulé. Activation/désactivation/réactivation du plein écran, annulation de l'ancien intervalle, quota et chronomètre, attente d'accusé de fin, reprise, iframe et saisie conservées, couleurs du tableau et champs d'édition non écrasés.
- Syntaxe PHP/JavaScript et suite existante profil, routage, authentification, isolation et protection des contenus.

La VM vérifie les interactions du code ; elle ne remplace pas la recette de plein écran dans un vrai navigateur.

## Recette de production

Sur un quiz de test avec élèves fictifs A et B, garder deux salles élèves, deux vues formateur et le tableau projeté ouverts. Modifier durée 15 → 20 min, quota 2 → 3, seuil 10 → 5 s, puis activer/désactiver/réactiver le plein écran et la règle de rechargement. Les résumés, compteurs et couleurs suivent au prochain rafraîchissement sans rechargement du Form. Une saisie de test et une modification non enregistrée du formulaire d'administration restent intactes.

Terminer A, modifier les règles, puis reprendre A : état terminé conservé jusqu'à la reprise confirmée, même iframe ensuite. B continue normalement. Changer l'URL Forms ne remplace pas le questionnaire déjà ouvert.

## Publication et retour arrière

Fichiers applicatifs : `app/Services/QuizService.php`, `app/Controllers/QuizAdminController.php`, `public/assets/js/quiz-monitor.js`, `app/Views/quiz/room.php`, `app/Views/quiz/admin/session.php`, `app/Views/quiz/admin/board.php`.

Sauvegarder les versions distantes des six fichiers, publier ce groupe sans épreuve réelle en cours puis vérifier les empreintes. Le retour arrière restaure seulement ces six fichiers. Ne pas envoyer tests, outils, base, secrets ou configuration serveur. Les anciens clients ignorent les nouveaux champs du payload ; les clients du lot gardent le comportement initial si les champs ne sont pas fournis.
