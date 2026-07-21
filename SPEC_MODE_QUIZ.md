# Spécification — Mode Quiz configurable

Statut : **proposition à valider**
Version : 0.1
Date : 20 juillet 2026

## 1. Décision produit proposée

Le projet ne devient pas une seconde application autonome. Il reste un seul code source déployable selon trois profils :

- `library` : bibliothèque de cours uniquement ;
- `quiz` : quiz uniquement, avec la page d’accès élève à la racine ;
- `hybrid` : comportement actuel, bibliothèque à la racine et quiz sous `/quiz`.

Le profil `quiz` est la cible prioritaire de cette spécification. Dans ce profil, les fonctions de bibliothèque, de synchronisation et de diagnostic ne sont pas seulement cachées dans l’interface : leurs routes sont désactivées côté serveur.

Google Forms reste le moteur de questionnaire de la première version. Le projet gère l’identité, la session d’examen, le temps, la supervision et les rapports. Une abstraction devra permettre d’ajouter un moteur de formulaire natif ultérieurement sans décider aujourd’hui de reconstruire Google Forms.

## 2. Objectifs

### Objectif principal

Permettre d’installer le même projet sur un serveur consacré aux quiz en changeant uniquement sa configuration.

### Résultat attendu en profil `quiz`

1. Un élève ouvre le nom de domaine et arrive directement sur la saisie du PIN de salle et de son code personnel.
2. Un lien « Administration » apparaît en haut à droite de cette page.
3. Le lien ouvre l’authentification enseignant puis le tableau d’administration.
4. Aucun cours, fichier, outil de synchronisation ou écran de diagnostic n’est accessible sur ce serveur.
5. Les anciennes URL `/quiz` et `/quiz-admin` restent utilisables afin de ne pas casser les liens existants.

### Non-objectifs de la première version

- remplacer Google Forms par un éditeur complet de questionnaires ;
- centraliser les cours ou remplacer MyGES ;
- garantir l’absence de triche ;
- surveiller un autre appareil utilisé par l’élève ;
- lire directement le contenu d’une iframe Google Forms ;
- transformer automatiquement un signal technique en sanction définitive.

## 3. Utilisateurs

### Élève

- rejoint une salle avec un PIN temporaire et un code personnel ;
- voit son identité avant le démarrage ;
- accepte les règles et l’information sur les événements enregistrés ;
- attend l’ouverture, passe le questionnaire et signale qu’il a terminé ;
- voit les alertes et le nombre d’incidents enregistrés.

### Enseignant ou administrateur

- crée et configure une session ;
- importe la liste des élèves ;
- distribue ou envoie les codes ;
- ouvre, lance, suspend, relance et clôture la session ;
- suit les connexions et les événements ;
- arbitre les incidents ;
- exporte les données et les rapports.

## 4. Configuration proposée

Configuration minimale dans `branding.php` :

```php
'app_mode' => 'quiz', // library | quiz | hybrid

'quiz' => [
    'enabled' => true,
    'show_admin_link' => true,
    'admin_path' => '/quiz-admin',
    'public_base_url' => 'https://quiz.exemple.fr',
    'bootstrap_admin_email' => 'admin@exemple.fr',
    'data_retention_days' => 365,
],
```

### Règles de configuration

- `app_mode` possède une valeur sûre par défaut : `hybrid`, pour préserver les installations existantes.
- `quiz.enabled=false` rend toutes les routes quiz indisponibles, même si un mot de passe est présent.
- `bootstrap_admin_email` sert uniquement à initialiser le premier super-administrateur sur une nouvelle installation. Son mot de passe initial est fourni par un secret de déploiement ou une commande locale, jamais conservé en clair dans le dépôt.
- `public_base_url` remplace la construction d’URL à partir de l’en-tête HTTP `Host`, notamment pour les courriels.
- Une configuration invalide doit produire un message explicite dans les journaux et une page 503 neutre, sans révéler de secret.
- Dans un second temps, les anciennes clés `quiz_admin_password`, `quiz_hmac_secret`, `quiz_mail_from` et `quiz_smtp` pourront être regroupées sous `quiz`, avec compatibilité transitoire.

## 5. Matrice de routage

| Route | `library` | `quiz` | `hybrid` |
|---|---:|---:|---:|
| `/` | Bibliothèque | Accès élève | Bibliothèque |
| `/quiz` | 404 | Accès élève | Accès élève |
| `/quiz/*` | 404 | Actif | Actif |
| `/quiz-admin/*` | 404 | Actif | Actif |
| `/sync` | Selon configuration | 404 | Selon configuration |
| `/debug` | Désactivé en production | 404 | Désactivé en production |
| `/{chemin de cours}` | Actif | 404 | Actif |
| ressources brutes `.md` / `.skill` | Selon bibliothèque | 404 | Selon bibliothèque |
| pages légales et confidentialité | Actif | Actif | Actif |
| assets CSS, JS et images | Actif | Actif | Actif |

La désactivation doit être effectuée avant l’instanciation des services inutiles. En mode quiz, le serveur ne doit pas créer `content/`, initialiser Google Drive ou exposer les fichiers pédagogiques.

## 6. Parcours élève

### 6.1 Accueil

En profil `quiz`, `/` rend la page d’accès élève sans redirection visible obligatoire. Le formulaire continue à envoyer vers `/quiz/join` pour conserver des URL d’API stables.

Éléments attendus :

- identité visuelle du site ;
- champ PIN à 6 chiffres ;
- champ code personnel à 5 caractères ;
- règles de l’épreuve ;
- information précise sur les événements collectés ;
- case de confirmation ;
- bouton « Rejoindre » ;
- lien « Administration » en haut à droite.

Le lien admin ne constitue pas une protection de sécurité. Il peut être affiché sans risque dès lors que l’authentification admin est correctement protégée.

### 6.2 Rejoindre une session

- Le PIN doit correspondre à une session en état `lobby` ou `running`.
- Le code doit appartenir à la liste de cette session.
- La combinaison PIN + code doit être limitée en fréquence afin d’empêcher l’énumération.
- Après validation, l’identifiant de session PHP doit être renouvelé.
- Un retour après rechargement ou coupure reprend la tentative existante et journalise la reconnexion.
- Une seconde connexion simultanée avec le même code doit être signalée à l’enseignant ; la politique de blocage ou d’autorisation reste à valider.

### 6.3 Salle d’attente et passage

- Le questionnaire n’est communiqué au navigateur qu’au lancement.
- Le chronomètre est calculé depuis l’heure serveur.
- Le plein écran peut être obligatoire par session.
- Les absences de page, sorties du plein écran et rechargements sont journalisés selon les règles de la session.
- La fin du temps doit déclencher une règle serveur. Le comportement actuel, qui affiche seulement une alerte sans fermer le formulaire, doit être remplacé ou explicitement assumé.
- « J’ai terminé » demande confirmation, journalise la fin et arrête la supervision.

### 6.4 Fin et reconnexion

- Une tentative terminée ne doit plus afficher le formulaire, même après rechargement.
- L’enseignant peut réouvrir la tentative dans le cadre d’une relance contrôlée.
- La clôture de session retire le PIN et affiche un état final à tous les élèves.

## 7. Parcours administrateur

### 7.1 Authentification

- authentification dédiée au module quiz avec comptes nominatifs ;
- mot de passe hashé, limitation des essais et délai progressif ;
- renouvellement de session à la connexion ;
- expiration après inactivité ;
- déconnexion uniquement en POST ;
- cookies `Secure`, `HttpOnly` et `SameSite=Lax` ou plus strict ;
- journalisation des connexions réussies et refusées sans stocker le mot de passe.

Il n’existe pas d’inscription publique ni de demande automatique de compte. Les comptes sont créés manuellement par un super-administrateur depuis une interface protégée, ou par une commande locale de maintenance pour le tout premier compte.

### 7.2 Préparation d’une session

L’enseignant fournit :

- titre ;
- URL publiée du Google Form ;
- identifiant du champ de corrélation ;
- durée ;
- nombre maximal d’incidents ;
- durée minimale d’absence ;
- obligation de plein écran ;
- règle applicable au rechargement ;
- liste des élèves avec prénom, nom et courriel facultatif.

Des préréglages « souple », « normal » et « strict » peuvent rester disponibles, mais chacun doit afficher clairement les règles appliquées.

### 7.3 Exploitation en direct

- génération d’un PIN temporaire ;
- écran projetable ;
- état connecté/déconnecté ;
- état commencé/terminé ;
- compteur d’incidents ;
- fil d’événements actualisé ;
- accès au détail d’un élève ;
- suspension, reprise et clôture ;
- arbitrage d’un événement ou de l’ensemble des incidents d’une tentative.

Une relance ne doit pas supprimer définitivement les événements. Elle crée une nouvelle exécution ou archive l’exécution précédente afin de préserver l’audit.

### 7.4 Après l’épreuve

- export CSV de la supervision ;
- rapport individuel imprimable ;
- rapprochement avec les réponses Google Forms ;
- suppression ou anonymisation selon la durée de conservation ;
- suppression complète d’une session avec confirmation forte, à ajouter.

## 8. Comptes administrateurs et propriété des quiz

> État d’implémentation — juillet 2026 : les comptes nominatifs, les rôles
> `super_admin`/`quiz_admin`, la création manuelle, la désactivation, la
> réinitialisation des mots de passe, la propriété des sessions et les transferts
> sont disponibles. Les anciens quiz sont attribués au premier super-admin lors
> de la migration. Le journal d’audit détaillé reste à réaliser.

### 8.1 Objectif

Permettre à plusieurs enseignants d’utiliser la même installation tout en séparant leurs espaces de travail : chaque administrateur voit et gère ses propres quiz, classes, élèves et rapports.

### 8.2 Rôles initiaux

- `super_admin` : crée, désactive et réinitialise les comptes ; voit tous les espaces ; peut transférer un quiz ou une classe ; accède aux réglages globaux et à l’audit ;
- `quiz_admin` : voit et gère uniquement les ressources qui lui appartiennent.

Il n’y a ni inscription libre, ni invitation automatique, ni création de compte par un utilisateur ordinaire dans la première version.

### 8.3 Création manuelle des comptes

Le super-administrateur dispose d’un écran « Utilisateurs » permettant de :

- créer un compte avec nom, courriel et rôle ;
- générer un mot de passe temporaire ou définir un mot de passe initial ;
- imposer son changement à la première connexion ;
- désactiver ou réactiver un compte ;
- réinitialiser son accès ;
- consulter sa dernière connexion ;
- transférer ses ressources avant suppression ou désactivation définitive.

Par sécurité, un compte ayant encore des quiz ou des classes ne peut pas être supprimé sans transfert ou archivage explicite de ses ressources.

### 8.4 Propriété et visibilité

Les objets suivants possèdent un `owner_admin_id` :

- quiz et sessions ;
- classes et groupes ;
- listes d’élèves enregistrées ;
- modèles et paquets d’examen MCP ;
- exports et rapports persistants.

Règles :

- un `quiz_admin` ne reçoit que les objets dont il est propriétaire ;
- toutes les lectures, modifications, suppressions, API temps réel et exports appliquent ce filtre côté serveur ;
- modifier un identifiant dans une URL ne permet jamais d’ouvrir le quiz d’un autre administrateur ;
- un super-administrateur peut filtrer par propriétaire et prendre en charge un espace à des fins de support ;
- tout transfert de propriété est journalisé ;
- les élèves restent rattachés à une classe ou à une session, sans devenir des comptes administrateurs.

Le simple filtrage de la page listant les quiz n’est pas suffisant : le contrôle de propriété doit être centralisé dans les services métier et réappliqué à chaque action.

### 8.5 Modèle de données indicatif

```text
admin_users
├── id
├── email (unique)
├── display_name
├── password_hash
├── role (super_admin | quiz_admin)
├── status (active | disabled)
├── must_change_password
├── last_login_at
└── created_at / updated_at

quiz_sessions.owner_admin_id → admin_users.id
quiz_classes.owner_admin_id  → admin_users.id
```

Une migration attribue les sessions existantes au premier super-administrateur afin de ne perdre aucun quiz lors de l’activation du système de comptes.

### 8.6 Évolutions ultérieures, hors première version

- partage volontaire d’un quiz avec un autre enseignant ;
- coédition ;
- équipes ou départements ;
- connexion institutionnelle SSO ;
- rôles en lecture seule ;
- délégation temporaire.

## 9. Intégration Google Forms

### 9.1 Fonctionnement conservé

Chaque tentative reçoit un jeton aléatoire signé. Ce jeton est prérempli dans un champ du Google Form et sert à rapprocher la réponse du bon élève sans envoyer directement son nom dans l’URL du formulaire.

### 9.2 Lacunes à corriger

- Le projet ne récupère actuellement aucune réponse Google Forms.
- La fonction de vérification de signature du jeton existe mais n’est reliée à aucun flux d’import.
- Un champ prérempli Google Forms peut être visible ou modifiable par l’élève ; il faut tester le scénario réel et rejeter tout jeton invalide ou attribué à une autre session.
- L’application ne sait pas si le formulaire a réellement été envoyé. Le bouton « J’ai terminé » est déclaratif.
- L’iframe est sur un autre domaine : l’application ne peut pas observer les touches, le presse-papiers ou la soumission à l’intérieur du formulaire.

### 9.3 Architecture cible

Introduire un contrat interne de fournisseur de questionnaire :

```text
QuestionnaireProvider
├── GoogleFormsProvider (version 1)
└── NativeQuizProvider (évolution éventuelle)
```

Le contrat doit fournir au minimum :

- validation de la configuration du questionnaire ;
- URL de passage liée à une tentative ;
- méthode de récupération ou d’import des réponses ;
- vérification de la corrélation tentative/réponse ;
- état de soumission lorsque le fournisseur le permet.

Pour Google Forms, deux options devront être comparées :

1. export CSV manuel et import dans l’administration ;
2. Apps Script contrôlé qui transmet chaque soumission au serveur avec une signature partagée.

L’option 1 est plus simple pour le premier pilote. L’option 2 améliore l’automatisation mais ajoute un composant à déployer, sécuriser et maintenir.

## 10. Identité des élèves

### Version initiale

- import CSV ou copier-coller ;
- prénom, nom, courriel ;
- code personnel aléatoire par session ;
- envoi facultatif par courriel ;
- tentative unique par élève et par session.

### Améliorations

- import avec colonnes explicitement mappées, afin d’éviter les erreurs sur les noms composés ;
- identifiant externe facultatif (MyGES, numéro étudiant ou autre) ;
- détection des doublons ;
- régénération individuelle d’un code compromis ;
- invalidation d’un ancien code ;
- traçabilité de la distribution ;
- politique explicite pour les doubles connexions.

Le courriel ne doit pas être utilisé comme unique preuve d’identité. Le contrôle humain en salle reste nécessaire.

## 11. Supervision et « anti-triche »

Le terme recommandé dans l’interface et la documentation est **supervision de session** ou **signaux d’intégrité**, et non « anti-triche ».

### Signaux raisonnablement observables

- page cachée ;
- perte de focus de la fenêtre ;
- sortie du plein écran ;
- rechargement ou départ de la page ;
- absence de heartbeat ;
- tentative de certains raccourcis lorsque la page parente a le focus ;
- seconde connexion avec le même code, après ajout de cette détection.

### Limites incompressibles d’une application web

- second téléphone ou second ordinateur ;
- notes papier ;
- navigateur ou système modifié ;
- JavaScript désactivé ou requêtes falsifiées ;
- actions effectuées dans l’iframe Google Forms ;
- détection fiable des outils de développement ;
- distinction automatique entre incident technique et intention de tricher.

Chaque événement est donc un indice à arbitrer. Le statut `invalid` ne doit pas annuler automatiquement une note sans décision humaine.

### Cas spécifique : outils IA déclenchés depuis une sélection

Les outils d’écriture d’Apple Intelligence, les extensions de navigateur et certains assistants IA peuvent être ouverts après sélection d’un texte, sans copie explicite et sans changement d’onglet. Une page web ne reçoit aucun événement standard indiquant qu’un outil IA a été ouvert ou utilisé.

Dans la version Google Forms, la contrainte est plus forte : le formulaire est chargé dans une iframe cross-origin. La page parente ne peut observer ni la sélection, ni le menu contextuel, ni le presse-papiers, ni les modifications de saisie à l’intérieur du formulaire.

La fonctionnalité à prévoir est donc une collecte de **signaux d’assistance externe**, et non un détecteur d’IA :

- événements de copie, coupe et collage réellement reçus par la page ;
- sélection et ouverture d’un menu contextuel comme contexte faible dans un futur questionnaire intégré à l’application et servi depuis la même origine ;
- remplacement important et rapide d’un texte comme heuristique non attributive ;
- corrélation avec perte de focus, page cachée, sortie du plein écran ou heartbeat absent ;
- état `telemetry_unavailable` dérivé côté serveur après un délai configurable, sans l’assimiler automatiquement à une fraude ni l’utiliser seul pour invalider une tentative.

Ces signaux ne doivent jamais contenir le texte sélectionné ou le contenu du presse-papiers. Ils sont dédupliqués, limités en fréquence, présentés avec leur niveau de fiabilité et soumis à arbitrage humain. Les libellés visibles restent factuels et n’emploient jamais « IA détectée ».

Pour une prévention plus forte, le cahier des charges distingue un troisième niveau hors application web : appareils administrés par MDM, navigateur avec liste blanche d’extensions, mode kiosque ou navigateur d’examen dédié. Même ce dispositif ne permet pas de détecter un second appareil.

L’étude détaillée, la matrice d’observabilité et les critères d’acceptation figurent dans `RESEARCH_AI_INTEGRITY_SIGNALS.md`.

## 12. Sécurité prioritaire

### Bloquants avant pilote réel

1. Ajouter des jetons CSRF à toutes les actions POST élève et admin.
2. Limiter les essais sur le login admin, le PIN et le code personnel.
3. Durcir les cookies et renouveler les identifiants de session après authentification.
4. Désactiver `display_errors` et `/debug` en production.
5. Valider le profil de routes côté serveur ; masquer des liens ne suffit pas.
6. Utiliser une URL publique configurée, pas `HTTP_HOST`, pour les liens envoyés.
7. Ajouter des en-têtes de sécurité adaptés, dont une CSP compatible avec Google Forms.
8. Activer et vérifier les contraintes SQLite, utiliser des transactions pour les suppressions et relances.
9. Conserver les événements lors d’une relance au lieu de les effacer.
10. Tester la falsification d’événements, de jetons de tentative et de réponses importées.
11. Tester systématiquement l’isolation entre deux comptes administrateurs et prévenir les accès directs par identifiant à une ressource d’un autre propriétaire.

### Pentest à organiser avec Samuel

- contournement du login et fixation de session ;
- force brute PIN/code ;
- CSRF sur toutes les actions d’administration ;
- manipulation des identifiants numériques dans les URL ;
- falsification des appels `/quiz/api/event` ;
- réutilisation d’un code sur plusieurs appareils ;
- modification du jeton prérempli Google Forms ;
- exposition de `storage`, `app`, `quiz.db`, clés et journaux selon les deux types de document root pris en charge ;
- injection dans les imports CSV et export CSV ;
- abus des courriels et de la configuration SMTP ;
- tests de charge avec une classe complète envoyant heartbeats et événements en parallèle.
- accès horizontal entre deux administrateurs : consultation, modification, export, flux temps réel et transfert de propriété.

## 13. Données personnelles et conformité

Données actuellement concernées : identité, courriel, code, heures de connexion, événements de navigation, hash d’IP, navigateur, état de tentative et jeton de corrélation.

Exigences :

- finalité et base légale documentées ;
- information accessible avant l’épreuve ;
- collecte minimale ;
- durée de conservation configurable ;
- procédure d’export, d’anonymisation et de suppression ;
- accès réservé aux enseignants autorisés ;
- sauvegarde protégée de la base ;
- aucun secret ni base de données dans une sauvegarde publique ;
- rapport présenté comme aide à la décision, pas comme verdict automatique.

Une validation DPO/juridique de l’établissement est nécessaire avant une généralisation.

## 14. État actuel du code

### Déjà disponible

- module quiz séparé sous `/quiz` et `/quiz-admin` ;
- cycle `armed → lobby → running → closed` ;
- PIN de salle et codes personnels ;
- import et édition d’élèves ;
- courriels de codes ;
- jetons de tentative signés ;
- Google Form intégré et prérempli ;
- minuterie serveur ;
- plein écran facultatif ;
- collecte d’événements et heartbeats ;
- statuts et arbitrage ;
- suivi enseignant actualisé toutes les cinq secondes ;
- écran projetable ;
- bouton de fin ;
- relance ;
- exports et rapports.

### Partiellement disponible

- l’administration est séparée, mais protégée par un mot de passe en clair dans la configuration ;
- l’identité est gérée, mais l’import des noms est heuristique et les doubles connexions ne sont pas traitées ;
- la supervision fonctionne, mais reste contournable et opaque à l’intérieur de l’iframe ;
- les jetons sont signés, mais aucune réponse Google n’est actuellement importée ou vérifiée ;
- le temps est affiché, mais son expiration ne ferme pas réellement l’épreuve ;
- les routes quiz sont séparées, mais aucun profil de déploiement ne désactive les autres fonctions.

### Absent ou à reprendre

- profil `app_mode` ;
- accueil quiz à la racine ;
- lien admin sur l’accueil élève ;
- contrôle serveur des fonctions désactivées ;
- CSRF et limitation des essais ;
- politique de session durcie ;
- récupération des réponses et notes Google Forms ;
- suppression/anonymisation et rétention ;
- détection des connexions concurrentes ;
- tests automatisés ;
- procédure de pentest et modèle de menace ;
- documentation de déploiement spécifique au mode quiz.
- comptes administrateurs nominatifs, rôles et propriété des quiz ;
- isolation des classes, élèves, rapports et exports par administrateur ;

### Dette technique connexe

- `public/index.php` concentre bootstrap, routage et plusieurs responsabilités ;
- les migrations SQLite sont ajoutées colonne par colonne sans version de schéma ;
- aucune suite de tests n’est présente ;
- la documentation principale décrit surtout la bibliothèque et pas le module quiz ;
- `TASKS.md` indique encore comme à faire quatre fonctions déjà présentes ;
- les deux modes de document root et les deux fichiers `.htaccess` augmentent le nombre de scénarios de sécurité à vérifier ;
- plusieurs textes historiques présentent des problèmes d’encodage à corriger avec prudence.

## 15. Architecture de mise en œuvre proposée

### Étape A — Profil d’application

- ajouter un objet de configuration normalisé ;
- créer un routeur de haut niveau fondé sur les capacités activées ;
- mapper `/` vers l’accès quiz en profil `quiz` ;
- refuser explicitement les routes désactivées ;
- ne charger que les services nécessaires ;
- ajouter le lien admin à la vue d’accès.

### Étape B — Socle de sécurité

- service de session sécurisé ;
- service CSRF ;
- limitation des essais ;
- mot de passe hashé ;
- configuration d’URL publique ;
- en-têtes de sécurité ;
- journaux de sécurité ;
- arrêt des erreurs détaillées en production.

### Étape B2 — Comptes et cloisonnement

- créer le modèle `admin_users` et le premier super-administrateur ;
- remplacer le mot de passe admin partagé par une authentification nominative ;
- rattacher les données existantes au premier propriétaire ;
- ajouter `owner_admin_id` aux ressources concernées ;
- centraliser les autorisations et les filtres de propriété ;
- fournir la création manuelle, la désactivation et le transfert des comptes ;
- ajouter les tests d’isolation entre deux administrateurs.

### Étape C — Fiabilité métier

- expiration serveur effective ;
- historique des exécutions lors des relances ;
- connexions concurrentes ;
- import d’élèves robuste ;
- suppression et rétention ;
- modèle de migrations versionné.

### Étape D — Rapprochement des réponses

- contrat `QuestionnaireProvider` ;
- import CSV Google Forms ;
- validation des jetons et de leur session ;
- écran de rapprochement réponses/tentatives ;
- export consolidé notes + supervision ;
- Apps Script seulement si le pilote justifie l’automatisation.

### Étape E — Validation

- tests unitaires du cycle de session et du calcul d’incidents ;
- tests d’intégration des routes pour chaque profil ;
- tests multi-navigateurs ;
- test de charge SQLite ;
- revue RGPD ;
- pentest avec Samuel ;
- pilote sur une épreuve non critique avant tout examen officiel.

## 16. Critères d’acceptation du premier lot

Le premier lot est accepté si :

1. `app_mode=quiz` affiche l’accès élève sur `/`.
2. Le lien admin est visible en haut à droite et mène à un login fonctionnel.
3. `/quiz` continue de fonctionner.
4. Les routes bibliothèque, sync, debug et ressources pédagogiques répondent 404 en mode quiz.
5. `app_mode=hybrid` conserve le comportement actuel.
6. `app_mode=library` rend toutes les routes quiz indisponibles.
7. Une configuration invalide ne révèle aucune donnée sensible.
8. Les tests couvrent la matrice de routes.
9. Les actions POST du flux modifié sont protégées contre le CSRF.
10. La documentation explique comment déployer chacun des trois profils.
11. Aucun `quiz_admin` ne peut consulter, modifier ou exporter les ressources d’un autre administrateur.
12. Un `super_admin` peut créer manuellement un compte, le désactiver et transférer ses ressources.

## 17. Refonte de l’interface d’administration

### 17.1 Problème constaté

L’administration actuelle concentre sur de longues pages la configuration, les actions de session, la liste des élèves, le suivi en direct et les événements. Les informations importantes et les actions risquées sont difficiles à hiérarchiser, particulièrement pendant une épreuve.

### 17.2 Principes de la refonte

- navigation persistante et prévisible ;
- séparation claire entre préparation, déroulement et résultats ;
- informations critiques visibles sans défilement ;
- une action principale évidente par écran ;
- actions dangereuses isolées et confirmées ;
- libellés compréhensibles sans connaissance technique de Google Forms ;
- état de sauvegarde et retours d’erreur visibles ;
- accessibilité clavier, contrastes suffisants et comportement responsive ;
- cohérence visuelle avec l’accueil élève sans reprendre l’interface d’examen plein écran.

### 17.3 Architecture d’écran proposée

```text
Navigation admin
├── Tableau de bord
├── Mes quiz
├── Mes classes
├── Rapports
├── Utilisateurs         (super_admin uniquement)
└── Réglages             (selon le rôle)

Fiche d’un quiz
├── 1. Préparation
├── 2. Élèves et accès
├── 3. Salle et supervision
└── 4. Résultats et rapports
```

### 17.4 Tableau de bord

Le tableau de bord affiche en priorité :

- les sessions en cours ou prévues ;
- les dernières sessions utilisées ;
- les alertes nécessitant une action ;
- un bouton principal « Créer un quiz » ;
- pour le super-administrateur, un filtre de propriétaire clairement identifié.

### 17.5 Écran de supervision

L’écran utilisé pendant l’épreuve doit être distinct des formulaires de configuration. Il affiche :

- état et temps restant ;
- PIN et nombre d’élèves attendus, connectés et terminés ;
- incidents récents ;
- filtres rapides par état ;
- actions lancer, suspendre et clôturer clairement différenciées ;
- accès au détail d’un élève sans perdre le contexte de la session.

La refonte visuelle ne doit intervenir qu’après la définition des comptes et de la nouvelle navigation, afin de ne pas dessiner deux fois les mêmes écrans.

## 18. Ordre de priorité recommandé

### P0 — Avant partage ou examen réel

- profil quiz et fermeture réelle des routes ;
- accueil à la racine et accès admin ;
- comptes nominatifs, propriété des quiz et migration des données existantes ;
- CSRF, sessions, mots de passe hashés et limitation des essais ;
- erreurs de production et route debug ;
- expiration serveur ;
- sauvegarde et procédure de restauration ;
- information des élèves et durée de conservation.

### P1 — Pour un pilote exploitable

- import Google Forms manuel avec validation des jetons ;
- historique non destructif des relances ;
- détection des doubles connexions ;
- import d’élèves robuste ;
- tests automatisés et test de charge ;
- pentest ciblé.
- refonte de la navigation et de l’écran de supervision admin ;

### P2 — Après retour du pilote

- Apps Script de remontée automatique ;
- groupes, classes et réutilisation de listes ;
- rôles pour plusieurs enseignants ;
- partage volontaire entre enseignants ;
- authentification institutionnelle ;
- serveur MCP de préparation automatisée d’un examen ;
- fournisseur de questionnaire natif si les limites de Google Forms le justifient.

## 19. Idée d’évolution — Serveur MCP de préparation d’examen

Créer un serveur MCP permettant à un enseignant, ou à un assistant IA autorisé, de préparer l’ensemble d’un examen à partir des informations fournies en langage naturel ou dans des fichiers structurés.

### Intention

L’enseignant fournit en une seule fois :

- le titre, la matière et les consignes ;
- les questions, propositions, bonnes réponses, explications et barème ;
- la durée et les règles de supervision ;
- la classe, le groupe et la liste des élèves ;
- les dates ou modalités de passage ;
- les options de diffusion des codes.

Le MCP prépare alors automatiquement :

1. le Google Form configuré en questionnaire ;
2. le champ technique de corrélation avec les tentatives ;
3. la salle et la session dans la plateforme quiz ;
4. la classe et ses élèves, avec détection des doublons ;
5. les codes personnels ;
6. un récapitulatif enseignant avec les liens utiles ;
7. éventuellement les brouillons de courriels de convocation ou d’envoi des codes.

Chaque objet créé est attribué au compte administrateur à l’origine de la demande MCP. Un super-administrateur peut choisir explicitement un autre propriétaire ; un administrateur ordinaire ne peut jamais créer ou modifier une ressource dans l’espace d’un autre utilisateur.

### Outils MCP envisagés

```text
validate_exam_package
preview_exam_plan
create_google_form
create_or_update_class
create_quiz_session
import_students
generate_student_codes
prepare_code_emails
publish_exam_package
get_exam_package_status
rollback_exam_package
```

### Fonctionnement recommandé

Le MCP doit séparer la préparation de l’exécution :

```text
Informations enseignant
        ↓
Validation et normalisation
        ↓
Aperçu complet sans modification externe
        ↓
Confirmation explicite de l’enseignant
        ↓
Création du Form + classe + session
        ↓
Contrôles de cohérence
        ↓
Récapitulatif et brouillons de diffusion
```

La création du formulaire, de la session ou des élèves peut être automatisée après confirmation. L’envoi réel des courriels, l’ouverture de la salle et le lancement de l’examen doivent rester des actions distinctes demandant une confirmation explicite.

### Validations nécessaires

- toutes les questions possèdent un type compatible ;
- les bonnes réponses et le barème sont cohérents ;
- aucun total de points inattendu n’est publié ;
- les questions ambiguës ou incomplètes sont signalées ;
- le Google Form appartient au bon compte ou dossier Drive ;
- le champ de corrélation est présent et correctement configuré ;
- la liste d’élèves est prévisualisée avant import ;
- les doublons et adresses invalides sont signalés ;
- aucune donnée personnelle n’est envoyée à un service non autorisé ;
- les opérations sont idempotentes afin qu’une relance ne crée pas plusieurs formulaires ou sessions identiques ;
- chaque objet créé est enregistré dans un journal permettant de diagnostiquer ou d’annuler une préparation incomplète.

### Architecture possible

Le MCP orchestre plusieurs adaptateurs sans contenir lui-même toute la logique métier :

```text
ExamPreparationMcpServer
├── GoogleFormsAdapter
├── GoogleDriveAdapter
├── QuizPlatformAdapter
├── StudentRosterAdapter
└── MailDraftAdapter
```

L’adaptateur de la plateforme doit utiliser une API d’administration dédiée plutôt qu’accéder directement à `quiz.db`. Cette API devra être authentifiée, limitée aux permissions nécessaires et disposer de clés révocables.

### Sécurité et permissions

- authentification forte du client MCP ;
- rattachement de chaque appel à un compte administrateur et application des mêmes autorisations que dans l’interface ;
- permissions séparées pour lire, préparer, créer, envoyer et lancer ;
- secrets Google et plateforme conservés côté serveur ;
- journal d’audit indiquant qui a demandé et confirmé chaque opération ;
- aperçu sans effet de bord disponible par défaut ;
- confirmation avant toute écriture externe importante ;
- possibilité d’annuler une préparation tant que l’examen n’a pas commencé ;
- interdiction d’envoyer automatiquement des codes ou de lancer une session à partir d’un simple texte non confirmé.

### Première version réaliste

La première version du MCP pourrait se limiter à :

1. valider un paquet d’examen structuré ;
2. créer le Google Form ;
3. créer la session quiz correspondante ;
4. importer les élèves ;
5. générer un récapitulatif et les brouillons de messages.

La gestion persistante des classes, les mises à jour complexes, l’envoi automatique et la publication complète viendraient après validation de ce premier flux.

## 20. Décisions à valider ensemble

1. Conserver les trois profils `library`, `quiz` et `hybrid`, ou seulement un interrupteur `quiz_only` ? Recommandation : trois profils explicites.
2. Garder le lien admin visible sur l’accueil élève ? Recommandation : oui, car le cacher n’ajoute pas de sécurité.
3. À l’expiration, masquer immédiatement le formulaire ou laisser une courte tolérance ? Recommandation : fermeture côté application avec une tolérance configurable et journalisée.
4. Autoriser ou bloquer une seconde connexion avec le même code ? Recommandation : signaler et demander une décision enseignant lors du pilote, avant de bloquer automatiquement.
5. Importer d’abord les réponses par CSV ou développer directement Apps Script ? Recommandation : CSV pour le premier pilote.
6. Une relance crée-t-elle une nouvelle exécution ou archive-t-elle l’ancienne dans la même tentative ? Recommandation : nouvelle exécution liée à la même tentative.
7. Quelle durée de conservation appliquer ? À décider avec l’établissement et le DPO.
8. Le terme visible doit-il être « supervision », « intégrité » ou « anti-triche » ? Recommandation : « supervision de session ».
9. Un super-administrateur voit-il tous les quiz par défaut ou seulement après activation d’un filtre global ? Recommandation : afficher d’abord ses propres quiz, avec un filtre explicite « Tous les propriétaires ».
10. À la création manuelle d’un compte, faut-il générer un mot de passe temporaire ou envoyer un lien d’activation ? Recommandation pour la première version : mot de passe temporaire à changer lors de la première connexion, sans système d’inscription publique.

## 21. Définition du pilote réussi

Le pilote est réussi si un enseignant non développeur peut, à partir d’un Google Form déjà préparé : créer une session, importer une classe, distribuer les accès, lancer l’épreuve, suivre les états, arbitrer les incidents, rapprocher les réponses et exporter un résultat, sans accès FTP ni modification manuelle du code.
