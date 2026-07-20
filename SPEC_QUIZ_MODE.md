# Spécification — Mode « Quiz uniquement »

> **Document obsolète.** Cette proposition est conservée pour historique. La spec produit de référence est désormais `SPEC_MODE_QUIZ.md` et l’exécution du premier incrément est décrite dans `SPEC_EXECUTION_LOT_1A.md`.

Statut : proposition à valider
Version : 0.1
Date : 20 juillet 2026

## 1. Décision produit proposée

Le projet reste une seule application déployable sur un serveur PHP classique. Il ne devient pas une seconde plateforme autonome.

Chaque déploiement choisit un profil dans sa configuration :

- `library` : bibliothèque pédagogique uniquement ;
- `quiz` : quiz uniquement ;
- `hybrid` : bibliothèque et quiz sur le même serveur.

En profil `quiz`, la racine `/` affiche directement l’écran de connexion étudiant du quiz. La bibliothèque, la synchronisation Google Drive et les routes de diagnostic ne sont pas seulement masquées dans l’interface : elles sont désactivées par le routeur et répondent `404`.

Le choix recommandé pour la première version est de conserver Google Forms comme moteur de questionnaire et de correction. L’application prend en charge l’identité, la session d’examen, le temps, la supervision et les traces d’intégrité. Remplacer Google Forms est un projet ultérieur distinct.

## 2. Objectifs

### Objectifs de la version 1

- Déployer la même base de code en bibliothèque, en quiz ou en mode hybride.
- Offrir une entrée étudiant immédiate sur un serveur configuré en quiz.
- Donner à l’enseignant un accès admin visible mais discret, en haut à droite.
- Associer chaque tentative à un élève connu : prénom, nom, adresse e-mail facultative et code personnel.
- Encadrer le passage d’un Google Form dans une salle d’examen supervisée.
- Produire un journal d’événements et un rapport permettant une décision humaine.
- Rester compatible avec un hébergement mutualisé PHP et SQLite.

### Hors périmètre de la version 1

- Reproduire l’éditeur, les types de questions et le moteur de correction de Google Forms.
- Centraliser les cours ou remplacer MyGES.
- Garantir l’impossibilité de tricher.
- Surveiller un second appareil, filmer l’élève ou installer un logiciel verrouillant le poste.
- Importer automatiquement les notes de Google Forms tant qu’une intégration fiable n’est pas spécifiée.
- Gérer plusieurs établissements et plusieurs rôles complexes.

## 3. Utilisateurs

### Élève

L’élève rejoint une session avec :

1. le PIN temporaire affiché dans la salle ;
2. son code personnel reçu sur papier ou par e-mail ;
3. son consentement explicite au suivi annoncé.

Son identité est affichée avant et pendant l’épreuve afin de limiter les erreurs de compte.

### Enseignant administrateur

L’enseignant se connecte à l’espace admin, crée une session, importe la liste des élèves, distribue les codes, ouvre la salle d’attente, lance et clôt l’épreuve, puis arbitre les incidents.

Dans la version 1, un seul rôle administrateur suffit. Une gestion multi-comptes pourra être ajoutée plus tard.

## 4. Configuration proposée

Ajouter les clés suivantes dans `branding.php` :

```php
'app_mode' => 'library', // library | quiz | hybrid

'quiz' => [
    'enabled' => false,
    'admin_enabled' => true,
    'admin_password_hash' => null,
    'show_admin_link' => true,
    'admin_link_label' => 'Administration',
    'data_retention_days' => 180,
    'trusted_proxy_headers' => false,
],
```

Règles :

- `app_mode` est la source de vérité pour les fonctionnalités exposées.
- `quiz.enabled` permet une désactivation d’urgence du module, y compris en profil `quiz`.
- Le mot de passe admin est stocké sous forme de hash généré par `password_hash()`, jamais en clair.
- Une valeur de configuration invalide provoque une page de maintenance explicite et une erreur dans les journaux ; aucun repli silencieux vers la bibliothèque.
- Les anciennes clés `quiz_admin_password`, `quiz_hmac_secret`, `quiz_mail_from` et `quiz_smtp` restent acceptées pendant une période de migration documentée.

### Matrice de routage

| Route | `library` | `quiz` | `hybrid` |
|---|---:|---:|---:|
| `/` | Bibliothèque | Connexion quiz | Bibliothèque |
| `/quiz` | 404, sauf activation explicite | Connexion quiz | Connexion quiz |
| `/quiz/*` | 404, sauf activation explicite | Actif | Actif |
| `/quiz-admin/*` | 404, sauf activation explicite | Actif si configuré | Actif si configuré |
| `/sync` | Selon config Drive | 404 | Selon config Drive |
| `/debug` | Désactivé en production | 404 | Désactivé en production |
| chemins de contenu | Actifs | 404 | Actifs |

Les ressources statiques strictement nécessaires au quiz restent accessibles en profil `quiz`.

## 5. Parcours attendus

### 5.1 Accueil étudiant en mode quiz

- `/` et `/quiz` servent le même écran, sans redirection visible obligatoire.
- Le centre de la page contient les champs PIN et code personnel, les règles de l’épreuve et le consentement.
- Un lien « Administration » se trouve en haut à droite si `show_admin_link` vaut `true`.
- Le lien mène à `/quiz-admin` et ne révèle aucune donnée avant authentification.
- Le lien admin n’apparaît pas dans la salle d’examen plein écran.
- La page ne montre aucun accès à la bibliothèque, à la synchronisation ou au contenu pédagogique.

### 5.2 Préparation par l’enseignant

L’enseignant peut :

- créer une session avec titre, URL Google Form et identifiant du champ de corrélation ;
- fixer la durée et les règles de signalement ;
- importer un CSV ou coller une liste d’élèves ;
- relire et corriger prénom, nom et e-mail ;
- imprimer les codes ou les envoyer par e-mail ;
- vérifier la compatibilité du formulaire avant d’ouvrir la salle.

Le contrôle du formulaire doit vérifier au minimum : domaine Google autorisé, format `viewform`, capacité d’affichage embarqué et présence d’un identifiant `entry.*` valide. Quand une vérification automatique est impossible, l’interface fournit un test manuel guidé.

### 5.3 Déroulement d’une session

Cycle nominal :

`brouillon (armed) → salle ouverte (lobby) → en cours (running) → clôturée (closed)`

- L’ouverture génère un PIN temporaire à six chiffres.
- Un code personnel ne fonctionne que pour la session correspondante.
- Une tentative existante est reprise sur le même navigateur.
- Le formulaire n’est communiqué au navigateur qu’après le lancement.
- Le temps restant vient de l’horloge du serveur.
- À expiration, la version 1 doit appliquer une règle explicite choisie pour la session : `information`, `masquage du formulaire` ou `clôture automatique`. La valeur recommandée est `masquage du formulaire`, avec délai de grâce configurable.
- « J’ai terminé » demande confirmation, enregistre la fin, masque le formulaire et arrête le suivi local.
- Une tentative terminée ne peut plus produire d’incidents, sauf réouverture explicite par l’enseignant.

### 5.4 Supervision

Le tableau enseignant affiche en direct :

- élèves attendus et élèves connectés ;
- heure de première connexion et dernière activité ;
- état `normal`, `à vérifier`, `seuil dépassé`, `terminé` ;
- nombre d’événements et nombre d’incidents non excusés ;
- temps restant commun ;
- fil des événements récents.

L’enseignant peut excuser un événement, excuser tous les incidents d’une tentative, relancer la session et exporter un rapport CSV ou imprimable.

Un seuil dépassé ne doit jamais annuler automatiquement une copie : il crée un état nécessitant une décision humaine.

## 6. Modèle de surveillance

### Événements observables

- page ou onglet masqué ;
- perte de focus de la fenêtre ;
- sortie du plein écran lorsque celui-ci est requis ;
- rechargement, si la règle est activée ;
- départ de la page ;
- tentative de raccourci copier, coller, imprimer, afficher la source ou ouvrir les outils de développement ;
- fin déclarée par l’élève ;
- perte de heartbeat.

### Limites à afficher clairement

- Les événements sont produits par le navigateur de l’élève et peuvent être falsifiés par un utilisateur avancé.
- Les actions réalisées dans l’iframe Google Forms sont partiellement invisibles à la page parente.
- Aucun site web ne peut détecter un téléphone ou un second ordinateur.
- Bloquer un raccourci ne bloque pas toutes les autres méthodes équivalentes.
- Une perte de focus peut être légitime : notification système, souci réseau, demande du surveillant ou fonctionnalité d’accessibilité.

Le vocabulaire produit recommandé est « indicateurs d’intégrité » ou « supervision de session », pas « anti-triche infaillible ».

## 7. Données

### Données déjà modélisées

- session : titre, URL de formulaire, durée, seuils, état, PIN et dates ;
- élève : prénom, nom, e-mail, code personnel ;
- tentative : jeton public, dates, statut, heartbeat, empreinte IP tronquée et user-agent ;
- événement : type, durée d’absence, qualification en incident, décision d’excuse et date.

### Évolutions de schéma proposées

- `quiz_sessions.time_expiry_action` et `grace_seconds` ;
- `quiz_sessions.updated_at` ;
- `quiz_attempts.submitted_at` distinct de `finished_at` si la soumission Google devient vérifiable ;
- `quiz_attempts.version` ou mise à jour atomique pour éviter les pertes lors d’événements concurrents ;
- journal des actions administrateur importantes ;
- date d’expiration ou procédure de purge des données ;
- version du texte de consentement accepté.

SQLite convient au pilote et à une classe. Il faudra tester la charge réelle des heartbeats et définir un seuil de migration vers une base serveur si plusieurs sessions simultanées sont visées.

## 8. Sécurité et conformité — prérequis avant pilote réel

### Priorité critique

- Ajouter des jetons CSRF à toutes les actions POST, étudiant et administrateur.
- Régénérer l’identifiant de session après connexion admin et après identification élève.
- Configurer les cookies de session `Secure`, `HttpOnly` et `SameSite=Lax` en HTTPS.
- Remplacer le mot de passe admin en clair par un hash et prévoir sa rotation.
- Limiter les tentatives de connexion admin et les essais PIN/code par IP et par fenêtre de temps.
- Refuser côté serveur les événements hors état `running`, après `finished_at`, ou avec une chronologie incohérente.
- Rendre atomique l’incrément du compteur d’incidents ; le calcul actuel peut perdre un événement concurrent.
- Ajouter une politique CSP compatible avec l’iframe Google Forms et supprimer l’affichage détaillé des erreurs en production.
- Désactiver `/debug` et toute route non nécessaire selon le profil.
- Vérifier permissions, sauvegarde et non-accessibilité HTTP de `storage/quiz.db` et `quiz-secret.key`.

### Données personnelles

- Documenter la finalité de chaque donnée collectée.
- Informer précisément l’élève avant consentement.
- Fixer une durée de conservation et une purge exécutable.
- Restreindre les exports, qui contiennent identité, code et jeton de corrélation.
- Ne pas considérer l’empreinte IP comme anonyme par défaut ; elle reste une donnée potentiellement rattachable.
- Définir qui peut consulter et arbitrer un rapport.

### Pentest proposé avec Samuel

Le test doit couvrir au minimum :

- contournement de l’authentification admin et élève ;
- brute force des PIN et codes ;
- CSRF, fixation et vol de session ;
- modification/rejeu/fabrication d’événements ;
- accès direct au formulaire ou au jeton avant lancement ;
- falsification des durées et du statut « terminé » ;
- accès aux fichiers SQLite, secrets, sauvegardes et journaux ;
- injections dans imports CSV, exports, e-mails et champs affichés ;
- concurrence SQLite et déni de service par heartbeats/événements ;
- configuration Apache lorsque la racine du dépôt, et non `public/`, est exposée.

Le pentest intervient après le durcissement critique et avant un examen comptant dans la note.

## 9. État des lieux du code actuel

### Déjà présent et réutilisable

- Routes étudiantes `/quiz` et administration `/quiz-admin` indépendantes du mot de passe de la bibliothèque.
- Base SQLite créée automatiquement avec mode WAL et délai d’attente pour la concurrence.
- Import de liste, codes personnels non ambigus, édition et suppression d’élèves.
- Envoi des codes par `mail()` ou SMTP authentifié.
- Cycle complet de session et PIN temporaire.
- Jeton de tentative signé et prérempli dans Google Forms.
- Salle d’attente, formulaire embarqué, minuterie et plein écran facultatif.
- Détection de plusieurs événements navigateur, heartbeat et bouton de fin.
- Tableau de supervision avec actualisation, vue projetée, arbitrage, rapport et CSV.
- Réinitialisation/reprise d’une session et excuse globale d’un élève.
- Traductions et styles déjà intégrés au projet principal.

### Partiellement présent

- L’identité est gérée dans l’application, mais la liaison avec la réponse Google dépend d’un champ prérempli et n’est pas réconciliée automatiquement.
- La minuterie atteint zéro, mais n’empêche actuellement pas de poursuivre le formulaire.
- Le suivi de connexion existe, sans transformer proprement une longue déconnexion en événement qualifié.
- La validation de l’URL Google vérifie le préfixe, mais pas la capacité réelle du formulaire à être embarqué ni le champ de liaison.
- Le temps réel utilise du polling, suffisant pour un pilote, à mesurer sous charge.
- Le secret HMAC est généré automatiquement, mais la robustesse des permissions et de la sauvegarde dépend du serveur.

### Manquant ou à corriger

- Profil de déploiement et routage « quiz uniquement ».
- Lien admin en haut à droite de l’accueil quiz.
- Tests automatisés et scénario de test de bout en bout.
- CSRF, limitation de débit, sessions durcies et mot de passe admin hashé.
- Contrôles serveur liés à l’état et à la fin d’une tentative.
- Incrément atomique des incidents.
- Politique de rétention, purge et journal d’audit admin.
- Réconciliation automatique des réponses et notes Google Forms.
- Sauvegarde/restauration documentée de la base quiz.
- Documentation de déploiement dédiée au mode quiz.

### Dette générale du dépôt affectant le module

- Le contrôleur frontal concentre beaucoup de responsabilités et affiche les erreurs en production.
- Plusieurs textes du dépôt présentent des problèmes d’encodage visibles.
- La documentation historique n’est plus alignée avec les dépendances et le module quiz.
- Le dossier de travail actuel contient de nombreux changements non commités, dont tout le module quiz.

## 10. Plan de livraison proposé

### Lot 0 — Stabiliser le travail existant

- Sauvegarder et isoler l’état actuel sur une branche.
- Corriger l’encodage sans réécriture massive risquée.
- Ajouter un test minimal de création de base, de session, d’élève et de tentative.
- Documenter les prérequis PHP, notamment `pdo_sqlite`.

### Lot 1 — Profil `quiz`

- Ajouter et valider `app_mode`.
- Centraliser la décision d’activation des modules.
- Appliquer la matrice de routage.
- Servir l’écran quiz à la racine.
- Ajouter le lien admin en haut à droite.
- Adapter SEO, navigation, page de connexion et documentation.

Critère de sortie : sur un déploiement `quiz`, aucune URL de bibliothèque, Drive ou debug n’est utilisable, et le parcours étudiant/admin fonctionne depuis `/`.

### Lot 2 — Durcissement

- CSRF, cookies, rotation de session, hash de mot de passe et anti-bruteforce.
- Validation serveur des états et compteurs atomiques.
- En-têtes de sécurité, configuration production et protection du stockage.
- Purge des données et journal admin.

Critère de sortie : revue de sécurité interne terminée et tests d’abus automatisés passants.

### Lot 3 — Fiabilité pédagogique

- Définir et implémenter la règle de fin de temps.
- Assistant de validation du Google Form.
- Signalement fiable des déconnexions.
- Tests navigateurs, accessibilité, réseau dégradé et charge d’une classe.
- Sauvegarde et restauration.

Critère de sortie : répétition générale réussie avec de faux élèves et incidents contrôlés.

### Lot 4 — Pilote et pentest

- Pilote sans enjeu de note ou avec procédure de secours papier.
- Analyse des faux positifs et retours enseignants/élèves.
- Pentest avec Samuel sur un environnement dédié.
- Correction puis décision d’autoriser ou non un examen réel.

### Lot ultérieur — Intégration Google ou moteur natif

Deux voies restent possibles :

1. conserver Google Forms et automatiser création, validation, récupération des réponses et notes ;
2. construire un moteur de formulaire natif.

La voie 2 implique à elle seule : éditeur de questions, banque de questions, médias, barèmes, mélanges, sauvegarde progressive, correction, accessibilité, import/export et migration. Elle doit faire l’objet d’une spec et d’un budget séparés.

## 11. Critères d’acceptation du mode quiz

- Avec `app_mode = quiz`, ouvrir `/` affiche la connexion étudiant.
- L’accueil contient un accès admin en haut à droite si configuré.
- Les routes bibliothèque, `/sync` et `/debug` répondent `404`.
- Avec `app_mode = library`, le comportement historique est inchangé.
- Avec `app_mode = hybrid`, `/` reste la bibliothèque et `/quiz` reste disponible.
- Une configuration invalide ne rend pas accidentellement un autre module public.
- Les actions POST sensibles échouent sans jeton CSRF valide.
- Après authentification, l’identifiant de session est renouvelé.
- Après fin ou clôture, un événement fabriqué n’augmente pas les incidents.
- Deux événements simultanés ne peuvent pas écraser le compteur l’un de l’autre.
- Le dépassement du temps applique la règle configurée et est visible des deux côtés.
- La purge supprime les données arrivées à expiration et laisse une trace technique non nominative.

## 12. Décisions à valider ensemble

1. Confirmer les trois profils `library`, `quiz`, `hybrid`.
2. Confirmer que Google Forms reste le moteur de questionnaire pour la version 1.
3. Choisir l’action par défaut à la fin du temps : information, masquage ou clôture.
4. Fixer la durée de conservation des données ; proposition : 180 jours.
5. Décider si le lien admin doit toujours être visible ou pouvoir être masqué par configuration.
6. Définir le volume cible : une classe sur une session, ou plusieurs sessions simultanées.
7. Définir le niveau de preuve attendu entre tentative locale et réponse Google Forms.
8. Confirmer qu’un incident reste toujours soumis à arbitrage humain.

## 13. Recommandation finale

Valider puis construire d’abord les lots 0 à 3. Cette trajectoire exploite l’essentiel du travail déjà réalisé, rend le déploiement « quiz uniquement » propre et réversible, et évite d’ouvrir prématurément le chantier beaucoup plus vaste d’un clone de Google Forms.

Une démonstration peut ensuite être proposée à Samuel comme prototype en cours de sécurisation, avec une demande ciblée de retours sur les scénarios de contournement et le pentest, sans présenter l’outil comme prêt pour des examens officiels.
