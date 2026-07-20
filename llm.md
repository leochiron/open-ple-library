# Open PLE Library - contexte projet pour LLM

Ce depot contient une application PHP de bibliotheque pedagogique publique, en lecture seule, sans base de donnees. Le contenu est stocke dans le dossier local `content/` et l'interface web expose ce filesystem sous forme de navigation par dossiers, de previsualisation de fichiers et de telechargement. Une synchronisation manuelle depuis Google Drive peut remplacer ou remplir `content/` via la route `/sync`.

Le projet est concu pour un hebergement mutualise classique. Le document root ideal est `public/`, mais un `index.php` a la racine sert aussi de shim si l'hebergeur pointe directement vers la racine du depot.

## Stack et dependances

- Langage: PHP, avec declarations `strict_types=1`.
- Architecture: front controller unique dans `public/index.php`, services PHP maison, vues PHP.
- Frontend: HTML/PHP, CSS/SCSS, JavaScript vanilla.
- Pas de framework applicatif.
- `vendor/` est present localement et contient notamment des bibliotheques Google/Composer, mais le service Google Drive actuel utilise surtout cURL et OpenSSL directement.
- Extensions PHP importantes:
  - `fileinfo` pour la detection MIME.
  - `zip` / `ZipArchive` pour le telechargement de dossiers en ZIP.
  - `curl` pour Google Drive.
  - `openssl` pour signer le JWT du service account Google.
  - sessions PHP pour l'authentification optionnelle.

## Structure globale

- `index.php`: shim racine. Sert un favicon minimal si besoin puis require `public/index.php`.
- `.htaccess`: regles Apache de securite, re ecriture vers `index.php`, headers de securite/cache, compression avec exception pour les videos.
- `public/index.php`: bootstrap complet, autoload maison, routing, gestion auth, routes legales, route sync, streaming fichiers.
- `public/debug.php`: page de diagnostic de chemins et permissions pour `content/`.
- `public/assets/css/main.css`: CSS servi en production.
- `public/assets/css/main.scss`: source SCSS.
- `public/assets/js/main.js`: selecteur de langue et bascule liste/grille.
- `public/assets/js/cookie-consent.js`: consentement cookies / Google Analytics.
- `public/assets/images/`: logo et favicon.
- `app/Config/config.php`: charge `branding.php` si present, sinon `branding.example.php`, puis expose la config runtime.
- `app/Config/branding.example.php`: modele de personnalisation du site.
- `app/Config/branding.php`: fichier local non versionne a creer par deploiement.
- `app/Config/i18n.php`: chaines de traduction.
- `app/Controllers/LibraryController.php`: navigation dossiers/fichiers.
- `app/Controllers/SyncController.php`: synchronisation Google Drive vers `content/`.
- `app/Controllers/ErrorController.php`: vues 404/500.
- `app/Services/*`: services filesystem, securite, MIME, ZIP, Google Drive, auth, i18n.
- `app/Views/*`: layout principal, login, bibliotheque, sync, pages legales.
- `app/Helpers/view.php`: helper global `render()`.
- `app/Helpers/url.php`: helper global `buildUrl()`.
- `content/`: contenu pedagogique servi. Ignore par Git sauf `.gitkeep`.
- `storage/`: donnees runtime, logs, credentials Google Drive. Ignore par Git sauf `.gitkeep`.

## Demarrage applicatif

`public/index.php` fait tout le bootstrap:

1. Active les erreurs et loggue dans `storage/php-error.log`.
2. Charge `vendor/autoload.php` s'il existe.
3. Sert un favicon inline pour eviter certains 404.
4. Enregistre un autoloader simple pour le namespace `App\`.
5. Charge les helpers `view.php` et `url.php`.
6. Charge `app/Config/config.php` puis `app/Config/i18n.php`.
7. Desactive Google Drive si `google_drive_enabled` est vrai mais que `Google_Client` n'existe pas.
8. Cree `content/` si le dossier n'existe pas.
9. Instancie les services:
   - `SecurityService`
   - `FileSystemService`
   - `MimeService`
   - `ZipService`
   - `I18nService`
   - `AuthService`
   - `GoogleDriveService`
10. Detecte la langue.
11. Calcule la route demandee depuis `REQUEST_URI`.
12. Gere les formulaires publics legaux, le logout, le login, puis route vers `/debug`, `/sync`, les pages legales ou la bibliotheque.

## Configuration

`app/Config/config.php` ne doit normalement pas etre edite pour personnaliser un site. Il charge:

- `app/Config/branding.php` s'il existe.
- Sinon `app/Config/branding.example.php`.

Il renvoie une config runtime de forme:

```php
[
    'app_name' => $branding['site_name'],
    'content_path' => realpath(__DIR__ . '/../../content') ou fallback,
    'default_language' => ...,
    'languages' => ...,
    'language_cookie' => ...,
    'language_cookie_ttl' => ...,
    'branding' => $branding,
]
```

La plupart des options fonctionnelles sont sous `config['branding']`, notamment:

- identite: `site_name`, `site_logo`, `site_logo_text`;
- theme: `colors`, `fonts`, `google_fonts_url`, `design`;
- footer: `footer_text`, `github_url`;
- i18n: `default_language`, `available_languages`, `language_cookie`, `language_cookie_ttl`;
- telechargements: `allow_directory_download`, `allow_download_previewable`;
- SEO/contact: `meta_description`, `ga_measurement_id`, `contact_email`, `contact_email_secondary`;
- auth: `passwords`, `password_cookie`, `password_cookie_ttl`;
- Google Drive: `google_drive_credentials`, `google_drive_folder_id`, `google_drive_enabled`, `sync_password`, `sync_delete_existing`;
- SMTP optionnel: lu dans `branding['smtp']` par `public/index.php` pour le formulaire de signalement.

Important: `branding.php`, `content/*` et `storage/*` sont ignores par Git. Chaque serveur doit avoir sa propre configuration locale.

## Routes principales

- `GET /`: affiche la racine de `content/`.
- `GET /{path}`: affiche un dossier ou une page fichier.
- `GET /{path}+download`: telecharge un fichier, ou compresse un dossier en ZIP.
- `GET /{path}+open`: sert un fichier previewable inline.
- `GET /sync`: affiche la page de synchronisation Google Drive.
- `POST /sync`: execute la synchronisation Google Drive. Le flux principal utilise `sync_action=start` puis des appels repetes `sync_action=process`.
- `GET /debug`: affiche la page de diagnostic.
- `GET /retrait-contenu` et `GET /signalement`: formulaire public de retrait/signalement.
- `GET /mentions-legales`, `/cgu`, `/charte-ethique`, `/conditions-utilisation`, `/politique-confidentialite`, `/politique-cookies`: pages legales si les vues existent.

Le routeur supporte aussi le prefixe `/index.php/...` pour les hebergements ou la re ecriture d'URL est limitee.

## Navigation bibliotheque

`LibraryController::browse($relativePath)` recoit un chemin relatif valide. Il:

1. Resout le chemin via `FileSystemService::resolvePath()`.
2. Renvoie une erreur si le chemin n'existe pas.
3. Si c'est un dossier:
   - liste les entrees via `FileSystemService::listDirectory()`;
   - ignore `.` / `..`, fichiers caches, `.DS_Store`, `Thumbs.db`;
   - trie dossiers avant fichiers, puis alphabetiquement;
   - ajoute une taille lisible;
   - rend `app/Views/library/folder.php`.
4. Si c'est un fichier:
   - detecte le MIME via `MimeService`;
   - si extension PHP, cherche un pattern `$url = 'https://...'`; si trouve, rend la page de redirection;
   - sinon rend `app/Views/library/file.php`.

Les breadcrumbs sont construits a partir des segments du chemin. Le bouton retour utilise le dossier parent.

## Securite filesystem

`SecurityService` est central:

- normalise les slashs;
- trim les slashs et espaces de bord;
- interdit explicitement les segments `..`;
- ignore les segments `.`;
- verifie que les chemins absolus resolus restent dans `content/`.

`FileSystemService::resolvePath()` tente `realpath()`. Si `realpath()` echoue, il fait une resolution "fuzzy" segment par segment pour retrouver des noms de dossiers avec espaces de fin. Cela evite certains 404 quand les noms de dossiers contiennent des espaces invisibles.

Point d'attention: `ensureInsideRoot()` compare par `strpos($candidate, $this->root) === 0`. C'est simple mais moins strict qu'une comparaison avec separateur de chemin. A garder en tete si la securite de chemins est retouchee.

## Fichiers previewables et streaming

`MimeService` utilise `finfo` puis un fallback par extension pour:

- PDF: `application/pdf`;
- audio: `audio/mpeg`, `audio/mp3`, `audio/wav`;
- video: `video/mp4`;
- sinon `application/octet-stream`.

Sont previewables:

- PDF;
- MP3;
- WAV;
- MP4.

`serveInline()` dans `public/index.php` gere les requetes `Range` pour les gros medias. Il renvoie:

- `206 Partial Content` si un range valide est demande;
- `416` si le range est invalide;
- `200` avec `Accept-Ranges: bytes` sinon.

Les fichiers sont lus par chunks de 1 MB. Les buffers de sortie sont nettoyes avant streaming pour eviter de corrompre les binaires.

## Telechargements

`/{path}+download` passe par `serveDownload()`:

- fichier: envoie `Content-Type`, `Content-Length`, `Content-Disposition: attachment`;
- dossier: cree une archive temporaire `ple_<id>.zip` dans le dossier temp systeme via `ZipService`, la sert, puis la supprime.

`allow_directory_download` est verifie dans la vue pour afficher ou non le bouton dossier, mais `serveDownload()` ne re verifie pas l'option cote serveur.

## Authentification optionnelle

`AuthService` lit `branding['passwords']`.

- Si le tableau est vide, l'application est publique.
- Si le tableau contient des mots de passe, une session PHP est requise.
- La verification utilise `hash_equals()`.
- La session stocke `ple_authenticated = true`.
- Le logout passe par `?logout=1`.

Les routes legales publiques ne sont pas bloquees par l'auth. La route `/sync` a son propre mot de passe `sync_password`, distinct des mots de passe utilisateurs.

Note: `branding.example.php` contient aussi `password_cookie` et `password_cookie_ttl`, mais le service actuel utilise une session PHP, pas un cookie applicatif dedie.

## Internationalisation

`I18nService` prend:

- `languages`;
- `default_language`;
- `language_cookie`;
- `language_cookie_ttl`.

Detection:

1. `?lang=xx` si supporte, puis cookie.
2. Cookie existant si supporte.
3. Deux premieres lettres de `Accept-Language`.
4. Langue par defaut.

Le selecteur de langue dans `main.js` modifie la query string `lang`.

## Layout et assets

`app/Views/layout.php`:

- calcule dynamiquement `assetBase` entre `/assets` et `/public/assets`;
- ajoute un cache busting par `filemtime()` sur CSS et JS;
- injecte les variables CSS depuis `branding['colors']`, `branding['fonts']`, `branding['design']`;
- affiche le logo ou le texte de marque;
- affiche le selecteur de langue;
- affiche les liens footer, pages legales, signalement et gestion cookies;
- expose `window.PLE_GA_MEASUREMENT_ID`.

Le layout charge:

- `main.css`;
- `main.js`;
- `cookie-consent.js`.

## Pages legales et signalement

`public/index.php` detecte les vues legales existantes et ne route que celles qui existent. Les pages publiques sont accessibles sans authentification.

Le formulaire de retrait/signalement:

- valide nom, email, URL, motif;
- envoie vers `branding['contact_email']` et eventuellement `contact_email_secondary`;
- utilise SMTP si `branding['smtp']` est configure;
- sinon fallback sur `mail()`;
- loggue fortement dans `storage/smtp-debug.log`.

La fonction `smtpSend()` est definie directement dans `public/index.php` et fait un client SMTP minimal par socket, avec support `ssl` et `tls`.

## Google Drive Setup - configuration attendue

La synchronisation Google Drive est une fonctionnalite manuelle. Elle copie recursivement un dossier Google Drive vers le dossier local `content/`. Le flux actuel est decoupe en petits lots HTTP pour eviter les timeouts PHP/proxy/navigateur sur les gros volumes.

Objectif operationnel:

- proteger `/sync` avec un mot de passe dedie;
- telecharger recursivement tous les dossiers/fichiers standards;
- ignorer les fichiers natifs Google Docs/Sheets/Slides non exportes;
- remplacer le contenu local si `sync_delete_existing` vaut true;
- conserver ensuite un site rapide, car la lecture publique se fait depuis le disque local.

Prerequis serveur:

- PHP avec `curl`;
- PHP avec `openssl`;
- droits d'ecriture sur `content/`;
- droits de lecture sur le JSON de service account;
- espace disque suffisant;
- temps d'execution assez large pour gros dossiers.

Prerequis Google:

1. Creer un projet dans Google Cloud Console.
2. Activer "Google Drive API".
3. Creer un Service Account.
4. Creer une cle JSON pour ce Service Account.
5. Copier ce JSON vers `storage/google-credentials.json`.
6. Recuperer l'ID du dossier Drive depuis l'URL:
   `https://drive.google.com/drive/folders/<FOLDER_ID>`.
7. Partager le dossier Drive avec l'email `client_email` present dans le JSON du Service Account, en permission Viewer.
8. Configurer `app/Config/branding.php`.

Configuration type dans `branding.php`:

```php
'google_drive_credentials' => __DIR__ . '/../../storage/google-credentials.json',
'google_drive_folder_id' => 'FOLDER_ID_ICI',
'google_drive_enabled' => true,
'sync_password' => 'mot-de-passe-fort-dedie-a-la-sync',
'sync_delete_existing' => true,
```

Fichiers sensibles:

- `storage/google-credentials.json` contient une cle privee et ne doit jamais etre commite.
- `app/Config/branding.php` peut contenir `sync_password`, SMTP et autres secrets; il est aussi ignore par Git.

## Google Drive - fonctionnement code reel

### Bootstrap

`public/index.php` instancie toujours:

```php
$googleDrive = new GoogleDriveService($config);
$syncController = new SyncController($googleDrive, $i18n, $config);
```

Avant cela, le bootstrap contient une garde:

```php
if (!class_exists('Google_Client')) {
    if (!empty($config['branding']['google_drive_enabled'])) {
        error_log('Google Drive disabled: composer autoload or google/apiclient not available.');
        $config['branding']['google_drive_enabled'] = false;
    }
}
```

Point important: `GoogleDriveService.php` indique qu'il utilise cURL sans dependance `google/apiclient`, mais le bootstrap desactive quand meme Google Drive si la classe `Google_Client` n'existe pas. En pratique, si `google_drive_enabled` est true, il faut donc soit garder `vendor/autoload.php` avec Google Client disponible, soit supprimer/adapter cette garde si l'on veut assumer le fonctionnement cURL pur.

### Disponibilite

`GoogleDriveService::__construct()` lit:

- `config['branding']['google_drive_credentials']`;
- le fichier JSON correspondant;
- les champs `client_email` et `private_key`.

Si le fichier existe, se decode en JSON et contient ces deux champs, `isAvailable()` renvoie true. Sinon le service est indisponible.

`getRootFolderId()` lit `config['branding']['google_drive_folder_id']`.

### Authentification Google

`GoogleDriveService::getAccessToken()`:

1. Construit un JWT signe RS256 avec:
   - issuer: `client_email`;
   - scope: `https://www.googleapis.com/auth/drive.readonly`;
   - audience: `https://oauth2.googleapis.com/token`;
   - expiration: maintenant + 3600 secondes;
   - issued at: maintenant.
2. Signe le JWT avec `openssl_sign()` et la `private_key`.
3. POST vers `https://oauth2.googleapis.com/token` avec:
   - `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`;
   - `assertion=<jwt>`.
4. Stocke `access_token` en memoire pour reutilisation pendant la requete PHP.

Le token n'est pas persiste dans `storage/`.

### Listing Drive

`listFiles($folderId)` appelle l'endpoint Drive v3 `files` avec:

- query: `'<folderId>' in parents and trashed=false`;
- `orderBy=folder,name`;
- `pageSize=1000`;
- fields: `nextPageToken,files(id,name,mimeType,size,modifiedTime)`;
- `includeItemsFromAllDrives=true`;
- `supportsAllDrives=true`.

La pagination est geree via `nextPageToken`.

### Download Drive

`downloadFile($fileId, $destinationPath)` appelle:

`https://www.googleapis.com/drive/v3/files/<fileId>?alt=media&supportsAllDrives=true`

Quand une destination est fournie, le fichier est streame directement sur disque avec cURL `CURLOPT_FILE`, ce qui evite de charger les gros fichiers en memoire.

Si le HTTP code n'est pas 200, le fichier local partiel est supprime.

### Route GET /sync

`SyncController::showSyncPage()`:

- verifie `branding['google_drive_enabled']`;
- renvoie HTTP 503 et le texte "Google Drive synchronization is not enabled." si desactive;
- sinon rend `app/Views/sync/index.php`.

La page affiche:

- champ mot de passe;
- checkbox de confirmation;
- bouton de lancement;
- barre de progression;
- journal de progression;
- lien retour bibliotheque.

### Route POST /sync

`SyncController::executeSync()` accepte deux familles de fonctionnement:

- le nouveau fonctionnement par lots, avec `sync_action=start`, `sync_action=process`, `sync_action=status`, `sync_action=cancel`;
- l'ancien fonctionnement SSE/JSON si aucun `sync_action` n'est fourni.

Le fonctionnement par lots est le flux utilise par `app/Views/sync/index.php`.

`sync_action=start`:

- verifie `google_drive_enabled`;
- compare `sync_password`;
- verifie que le service Google Drive est disponible;
- verifie le folder ID;
- cree `storage/sync-state.json`;
- initialise les stats;
- initialise une pile de suppression si `sync_delete_existing=true`;
- initialise une pile de telechargement avec le dossier Drive racine.

`sync_action=process`:

- recharge `storage/sync-state.json`;
- traite un petit lot;
- sauvegarde l'etat;
- renvoie les stats publiques et les messages;
- indique `done=true` quand la phase est `complete`.

`sync_action=status` renvoie l'etat courant. `sync_action=cancel` supprime le fichier d'etat.

Options configurables dans `branding.php`:

```php
'sync_batch_seconds' => 18,                  // duree cible max d'un appel process
'sync_batch_files' => 3,                     // nombre de fichiers standards par lot
'sync_batch_operations' => 80,               // operations max pour dossiers/suppression
'sync_file_chunk_bytes' => 32 * 1024 * 1024, // taille des chunks pour gros fichiers
```

Sans ces options, les valeurs ci-dessus sont les defauts internes.

L'ancien chemin `executeSync()` sans `sync_action`:

- si `POST stream=true`, utilise `executeSyncWithProgress()` et renvoie des events Server-Sent Events;
- sinon renvoie du JSON legacy.

Dans les deux modes:

- verifie `google_drive_enabled`;
- compare `$_POST['sync_password']` a `branding['sync_password']`;
- augmente les limites (`memory_limit=256M`, `max_execution_time=600`, puis `set_time_limit(0)`);
- verifie `GoogleDriveService::isAvailable()`;
- lit le root folder ID;
- initialise les stats;
- supprime `content/` si `sync_delete_existing` est true;
- recree `content/`;
- telecharge recursivement le contenu Drive.

Stats renvoyees:

- `folders_created`;
- `files_downloaded`;
- `files_deleted`;
- `bytes_transferred`;
- `duration`.

### Sync avec progression

La vue `app/Views/sync/index.php` envoie maintenant:

1. un `fetch(POST)` avec `sync_action=start`;
2. puis une boucle de `fetch(POST)` avec `sync_action=process`;
3. une pause courte entre deux lots;
4. une progression approximative cote client.

Chaque requete reste courte, et l'etat de parcours est conserve dans `storage/sync-state.json`.

Ancien comportement SSE conserve dans le controleur mais plus utilise par la vue:

- `sync_password`;
- `stream=true`.

Le controleur repond en `text/event-stream`. Les events possibles:

- `progress`: message de progression;
- `complete`: fin avec stats;
- `error`: erreur.

La barre de progression cote client est approximative: elle avance progressivement jusqu'a 95%, puis passe a 100% sur `complete`. Le backend ne calcule pas un pourcentage reel car il ne pre compte pas tout le Drive.

### Gros fichiers

Pour les fichiers dont la taille Drive depasse `sync_file_chunk_bytes`, le controleur ne lance plus un telechargement monolithique. Il cree `current_file` dans `storage/sync-state.json` avec:

- `id`;
- `path`;
- `name`;
- `size`;
- `offset`.

`GoogleDriveService::downloadFileRange()` utilise l'en-tete HTTP `Range: bytes=start-end` et ecrit le chunk au bon offset dans le fichier local. Le lot suivant reprend au prochain offset. Cela limite le risque qu'un seul fichier tres volumineux fasse depasser le timeout d'une requete.

### Suppression locale

`deleteDirectory($contentPath, &$stats)` supprime recursivement fichiers et dossiers, puis supprime le dossier racine lui-meme. Ensuite la sync recree `content/`.

Attention: avec `sync_delete_existing=true`, toute modification locale dans `content/` est perdue a chaque sync.

### Recursion de telechargement

`downloadFolder()` et `downloadFolderWithProgress()`:

- listent les enfants du dossier Drive courant;
- si `mimeType` est `application/vnd.google-apps.folder`, creent le dossier local puis descendent recursivement;
- si `mimeType` commence par `application/vnd.google-apps.`, ignorent le fichier natif Google;
- sinon telechargent le fichier vers le chemin local correspondant.

Les noms Drive sont utilises comme noms de fichiers/dossiers locaux. Il n'y a pas de sanitation specifique pour caracteres interdits Windows/Linux ou collisions de noms, hors contraintes du filesystem cible.

## Problemes courants Google Drive

- "Google Drive synchronization is not enabled.": `google_drive_enabled` est false, absent, ou desactive par le bootstrap car `Google_Client` est absent.
- "Invalid password": `sync_password` POST ne correspond pas a `branding['sync_password']`.
- "Google Drive non disponible": credentials absents, chemin faux, JSON invalide, `client_email` ou `private_key` manquant, cURL/OpenSSL indisponible, ou token OAuth refuse.
- "Folder ID not configured": `google_drive_folder_id` vide ou absent.
- Aucun fichier trouve: mauvais folder ID, dossier non partage avec le service account, dossier vide, ou contenu dans un Shared Drive avec permissions insuffisantes.
- Fichiers Google Docs absents: comportement normal, ils sont ignores car le code ne fait pas d'export Google Docs vers PDF/DOCX.
- Timeout: augmenter `max_execution_time`, verifier proxy/hebergeur, reduire taille du dossier ou lancer via cron/cURL.
- Espace disque: verifier l'espace avant sync, surtout si `sync_delete_existing=false`.

## Points d'attention et incoherences actuelles

- Le README dit "zero dependencies", mais `vendor/` est present et Google Drive depend au moins de cURL/OpenSSL, avec une garde `Google_Client`.
- `GoogleDriveService` est documente comme cURL pur, mais `public/index.php` exige indirectement `Google_Client` quand la sync est active.
- Le code contient des textes avec encodage mojibake dans plusieurs fichiers (`Ã©`, `ðŸ...`). Il faut faire attention a l'encodage avant modifications massives.
- `public/index.php` contient beaucoup de responsabilites: routing, SMTP, formulaires, streaming, bootstrap. Toute refactorisation doit etre prudente.
- `debug.php` indique "remove after diagnosis"; il reste accessible via `/debug`.
- La page `/sync` affiche que les fichiers locaux seront supprimes, meme si `sync_delete_existing` pourrait etre false.
- Le bouton dossier `+download` est cache si `allow_directory_download=false`, mais l'endpoint serveur n'applique pas explicitement ce flag.
- L'ancien code SSE reste present dans `SyncController` pour compatibilite, mais la vue utilise maintenant l'API par lots.

## Workflow de developpement local

1. Creer `app/Config/branding.php` depuis l'exemple.
2. Ajouter du contenu dans `content/`.
3. Lancer:

```bash
php -S localhost:8000 -t public
```

4. Ouvrir `http://localhost:8000`.

Pour tester la sync, configurer `storage/google-credentials.json`, les options Google Drive de `branding.php`, puis ouvrir `http://localhost:8000/sync`.

## Verification rapide utile

Tester la disponibilite Google Drive:

```bash
php -r "require 'vendor/autoload.php'; require 'public/index.php';"
```

Cette commande n'est pas ideale car `public/index.php` execute le routeur. Pour un vrai test CLI propre, mieux vaut creer un petit script temporaire qui charge l'autoload, l'autoloader App, `app/Config/config.php`, puis instancie `App\Services\GoogleDriveService` et appelle `isAvailable()`.

## Intention produit

L'application doit rester simple: une bibliotheque en lecture seule, personnalisable, deployable sur hebergement PHP classique, avec contenu gere par FTP ou par synchronisation Drive. La logique critique est la securite des chemins, la robustesse du streaming media, et la synchronisation Drive sans exposer de secrets.
