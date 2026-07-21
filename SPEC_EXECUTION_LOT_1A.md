# Spec d’exécution — Lot 1A : profils d’application

Statut : proposition à valider

## Objectif

Permettre au même code source de fonctionner en bibliothèque, en quiz uniquement ou en mode hybride, sans initialiser ni exposer les fonctions désactivées.

Ce lot ne modifie pas la base SQLite et n’introduit pas encore les comptes administrateurs.

## Décisions proposées

- `SPEC_MODE_QUIZ.md` devient l’unique spec produit de référence.
- Le profil par défaut est `hybrid` afin de préserver les installations existantes.
- `library` désactive strictement toutes les routes quiz.
- `quiz` sert l’accès élève sur `/` et conserve `/quiz` pour les liens existants.
- `hybrid` conserve le comportement actuel.
- `quiz.enabled=false` sert d’arrêt d’urgence.
- Si `app_mode=quiz` et `quiz.enabled=false`, `/` répond par une page 503 neutre et les sous-routes quiz répondent 404.
- Le document root recommandé est `public/` ; le fonctionnement avec la racine du dépôt reste supporté mais doit être durci et testé.

## Configuration cible

```php
'app_mode' => 'hybrid', // library | quiz | hybrid

'quiz' => [
    'enabled' => true,
    'show_admin_link' => true,
],
```

Les anciennes configurations sans `app_mode` ou sans bloc `quiz` continuent de fonctionner en `hybrid`, avec le module quiz actif comme aujourd’hui.

## Matrice fonctionnelle

| Route | `library` | `quiz` | `hybrid` |
|---|---|---|---|
| `/` | bibliothèque | accès élève | bibliothèque |
| `/quiz` et `/quiz/*` | 404 | actifs | actifs |
| `/quiz-admin/*` | 404 | actifs | actifs |
| `/sync` | selon configuration | 404 | selon configuration |
| `/debug` et `/debug.php` | interdits en production | 404 | interdits en production |
| chemin pédagogique | actif | 404 | actif |
| `.md` et `.skill` bruts | comportement bibliothèque | 404 | comportement bibliothèque |
| assets, favicon et SEO | actifs | actifs | actifs |

## Architecture attendue

### Profil normalisé

Créer un service testable chargé de :

- valider `library`, `quiz` ou `hybrid` ;
- dériver les capacités bibliothèque, quiz, synchronisation et page d’accueil ;
- préserver les valeurs par défaut historiques ;
- signaler une configuration incohérente sans révéler de secret.

### Routage de premier niveau

La requête doit être classée avant l’initialisation des services métier.

Catégories minimales :

- quiz élève ;
- administration quiz ;
- bibliothèque ;
- synchronisation ;
- diagnostic ;
- asset/SEO ;
- route indisponible.

### Initialisation conditionnelle

En mode `quiz`, le traitement d’une requête quiz ne doit pas :

- créer `content/` ;
- instancier `SecurityService` ou `FileSystemService` ;
- instancier Google Drive ou le contrôleur de synchronisation ;
- parcourir ou exposer un fichier pédagogique.

Les services communs réellement nécessaires, comme la session PHP et l’internationalisation, restent initialisés.

## Interface

En mode `quiz`, la page `/` réutilise la vue d’accès élève.

Elle ajoute en haut à droite un lien « Administration » :

- visible seulement si `quiz.show_admin_link=true` ;
- dirigé vers `/quiz-admin` ;
- accessible au clavier ;
- lisible sur mobile ;
- absent de la salle d’examen plein écran.

Le formulaire élève conserve l’action `/quiz/join` et toutes les URL internes quiz existantes restent canoniques.

## Sécurité du serveur web

- bloquer l’accès direct à `app/`, `storage/`, `.git/` et `content/` lorsque la racine du dépôt sert de document root ;
- traiter le cas du vrai fichier `public/debug.php`, qui peut contourner le routeur ;
- désactiver l’affichage détaillé des erreurs en production ;
- ne pas considérer une route fermée si seul son lien a disparu ;
- tester les variantes avec préfixe `/index.php`.

## Découpage d’implémentation

### Commit 1 — Normalisation sans changement de routage

- service de profil ;
- valeurs par défaut et validation ;
- configuration d’exemple ;
- tests unitaires autonomes ;
- documentation de compatibilité.

### Commit 2 — Routage et initialisation conditionnelle

- routeur de premier niveau ;
- réorganisation prudente de `public/index.php` ;
- racine quiz ;
- fermeture des capacités ;
- lien admin et traductions ;
- durcissement des deux modes de document root ;
- tests d’intégration de la matrice.

## Tests obligatoires

- profil absent, valide, inconnu et incohérent ;
- matrice complète `library/quiz/hybrid` ;
- `/`, `/quiz`, `/quiz-admin`, `/sync`, `/debug`, `/debug.php`, contenu et ressources brutes ;
- variantes `/index.php/...` ;
- vérification qu’aucun `content/` n’est créé en mode quiz ;
- anciennes URL quiz inchangées ;
- non-régression navigation, fichiers et synchronisation en `hybrid` ;
- exécution avec `public/` puis avec la racine du dépôt comme document root ;
- syntaxe PHP et JavaScript.

## Critères d’acceptation

1. Une installation sans nouvelle configuration garde exactement son comportement historique.
2. En `quiz`, `/` affiche l’accès élève et le lien admin configuré.
3. En `quiz`, aucune route bibliothèque, sync, debug ou ressource pédagogique n’est accessible.
4. Une requête quiz n’initialise aucun service bibliothèque ou Google Drive.
5. En `library`, toutes les routes quiz répondent 404.
6. En `hybrid`, les deux fonctions restent disponibles.
7. Les variantes de document root respectent la même matrice.
8. Une configuration invalide produit une réponse neutre et un journal exploitable sans secret.
9. Tous les tests du lot passent.

## Hors périmètre

- comptes administrateurs nominatifs ;
- propriété et transfert des sessions ;
- CSRF généralisé et limitation des essais ;
- migrations SQLite versionnées ;
- classes persistantes ;
- refonte graphique complète de l’administration ;
- API MCP.

Ces sujets suivent dans les lots 1B à 1D après validation du profil d’application.
