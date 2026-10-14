# Retours Quiz — recette du 14 octobre 2026

Base : `origin/main` à `a2de4c2`. Branche : `fix/quiz-alertes-et-fin-formulaire`.

## Comportement corrigé

- **Retirer les alertes** : bouton dans la liste des élèves et le détail d'une tentative, y compris les participants apparus en direct. Compteur et statut recalculés, événements et audit conservés. L'historique indique « excusé » à la place du pictogramme d'alerte. Les nouveaux incidents restent suivis.
- **Vérification sans incident** : aucune fausse alerte de raccourci pendant la préparation. Les sorties reçues pendant les 45 secondes d'un défi serveur lié au document courant sont marquées comme préparation, exclues du tableau projeté et non pénalisantes même après changement des règles. Un départ ordinaire ouvert avant la préparation conserve sa qualification. Les événements anciens ne sont pas excusés automatiquement faute de preuve de leur contexte.
- **Fin du formulaire** : les sondages autorisés n'invalident plus l'acquittement de fin. Les refus récents conservent leur priorité. La confirmation met à jour l'état partagé ; une nouvelle tentative utilise le même identifiant et une erreur réseau déclenche une vérification serveur. Le reçu demeure lisible après expiration du suivi, sans donner accès au questionnaire ni permettre une reprise non autorisée.

Google Forms confirme la réception des réponses ; Quiz enregistre ensuite une déclaration de fin distincte.

## Recette dans Chrome

Autorisation de Léo : vérifier avec les projets de test existants, puis pousser seulement si la recette est concluante.

Le quiz fictif existant « TEST PRODUCTION — Mode strict et reprise — 11 octobre 2026 » (session 5 ESGI) a été consulté en lecture seule. Sa configuration et son Google Form fictif ont servi à une instance locale isolée, avec une durée étendue à 120 minutes pour la recette. Aucune session pédagogique du site n'a été modifiée.

- Retrait depuis la liste : Test Auto passe de 1 incident / statut `invalid` à 0 / `started` ; événement conservé, marqué « excusé ».
- Préparation native réussie par Léo à 11:17:05, heure de Paris : les sept contrôles sont vrais (clavier, aller-retour d'onglet, retour du focus, changement et activation du plein écran). Aucune dérogation active. Les essais de préparation échoués et réussis n'ont créé aucun incident.
- Une vraie sortie de page après admission, de 29,453 secondes, est comptée : la surveillance ordinaire reste active.
- Depuis le navigateur piloté : saisie d'une identité explicitement fictive et de 18 réponses fictives dans le formulaire intégré, puis clic Envoyer. Google Forms affiche sa confirmation de réception.
- Case de confirmation puis bouton Terminer dans Quiz : déclaration enregistrée à 11:19:28, heure de Paris, avec un seul événement `finish`.
- Plus d'une minute après la fin, le suivi a expiré et le reçu demeure visible ; le formulaire reste masqué et la reprise sans permission reste désactivée.
- Retrait depuis le détail de Test Leo : incident excusé, historique conservé et déclaration de fin préservée.

Les premiers essais d'onglet dans le contexte piloté ne recevaient pas le signal de page masquée. Le test demandé en fenêtre privée et réalisé manuellement par Léo a ensuite réussi. Le code de détection d'onglet est inchangé dans ce lot ; la cause précise de cette différence d'environnement n'est pas démontrée. Ne pas présenter le pilotage automatique comme preuve d'un geste natif réussi.

## Validation automatisée et limites

**29 suites réussies : 20 PHP et 9 JavaScript**, relancées après le dernier ajustement. Syntaxe PHP et diff vérifiés. Migration additive du marqueur de préparation testée avec conservation des données. Contrôles existants de propriété, CSRF, dérogations, blocage manuel, historique, exports, générations et conservation de l'iframe réussis.

Les tests globaux utilisent une copie isolée afin de ne pas dépendre de la base Nextcloud existante. Apache est absent : règles vérifiées statiquement. La recette native porte sur Chrome ; Edge, Firefox et Safari n'ont pas été testés séparément. Aucune revue indépendante effectuée dans cette session.

Les preuves locales sont dans le dossier voisin `verification-quiz-2026-10-14` : résultats avant push et captures `fin-quiz-confirmee.jpg`, `fin-quiz-confirmee-apres-expiration.jpg`.

## Périmètre de publication

La condition de recette préalable au push est remplie. Le commit contient les corrections Quiz, les tests et la documentation correspondante. Les changements locaux antérieurs (migration ESGI, en-tête et ressources pédagogiques) sont conservés dans le dossier de travail et exclus de ce commit. Leur diff est sauvegardé dans le dossier voisin `sauvegarde-avant-mise-a-jour-2026-10-14`.

Le push de la branche ne fusionne pas dans `main` et ne déploie rien sur le serveur de production.
