# Configuration Google Drive - Synchronisation manuelle

Ce guide explique comment configurer la synchronisation manuelle du contenu depuis Google Drive.

## 🎯 Objectif

Synchroniser manuellement l'intégralité d'un dossier Google Drive vers le serveur via une URL protégée (`/sync`).
- Accès à `/sync` avec un mot de passe dédié
- Télécharge récursivement tous les fichiers/dossiers
- Remplace le contenu local existant
- Interface web avec barre de progression

## 📋 Prérequis

- Un compte Google (Gmail)
- Accès à la [Google Cloud Console](https://console.cloud.google.com/)
- Un dossier Google Drive avec vos contenus PLE
- PHP avec extension `curl` activée
- Composer installé

## 🔧 Configuration (étapes détaillées)

### 1. Créer un projet Google Cloud

1. Allez sur [Google Cloud Console](https://console.cloud.google.com/)
2. Créez un nouveau projet (ex: "PLE-Library")
3. Sélectionnez votre projet

### 2. Activer l'API Google Drive

1. Dans le menu de gauche : **APIs & Services** → **Library**
2. Recherchez **"Google Drive API"**
3. Cliquez sur **Enable**

### 3. Créer un Service Account

1. Menu : **APIs & Services** → **Credentials**
2. Cliquez sur **+ CREATE CREDENTIALS** → **Service Account**
3. Remplissez :
   - **Service account name** : `ple-drive-reader`
   - **Service account ID** : (auto-généré)
4. Cliquez sur **CREATE AND CONTINUE**
5. **Grant this service account access to project** :
   - Role : `Basic` → `Viewer` (ou laissez vide)
6. Cliquez sur **DONE**

### 4. Télécharger la clé JSON

1. Dans la liste des **Service Accounts**, cliquez sur celui que vous venez de créer
2. Onglet **KEYS**
3. **ADD KEY** → **Create new key**
4. Type : **JSON**
5. Cliquez sur **CREATE**
6. Un fichier JSON est téléchargé (ex: `ple-library-abc123.json`)

### 5. Placer le fichier de credentials

```bash
# Copiez le fichier JSON téléchargé dans le dossier storage/
cp ~/Downloads/ple-library-abc123.json storage/google-credentials.json

# Sécurisez les permissions (Linux/Mac)
chmod 600 storage/google-credentials.json
```

> ⚠️ **Important** : Ce fichier contient des clés secrètes. Ne le commitez JAMAIS dans Git !

### 6. Obtenir l'ID du dossier Google Drive

1. Ouvrez votre dossier Google Drive dans un navigateur
2. L'URL ressemble à : `https://drive.google.com/drive/folders/1a2B3c4D5e6F7g8H9i0J`
3. Copiez la partie après `/folders/` → `1a2B3c4D5e6F7g8H9i0J`

### 7. Partager le dossier avec le Service Account

1. Dans Google Drive, clic-droit sur votre dossier → **Share**
2. Ajoutez l'email du Service Account :
   - Format : `ple-drive-reader@ple-library.iam.gserviceaccount.com`
   - Vous le trouvez dans le fichier JSON téléchargé (clé `client_email`)
3. Permission : **Viewer** (lecture seule)
4. Cliquez sur **Send**

### 8. Configurer branding.php

Éditez `app/Config/branding.php` :

```php
// Google Drive API (manual sync via /sync URL)
'google_drive_credentials' => __DIR__ . '/../../storage/google-credentials.json',
'google_drive_folder_id' => '1a2B3c4D5e6F7g8H9i0J', // Votre ID de dossier
'google_drive_enabled' => true,                      // Activer l'intégration
'sync_password' => 'VotreMo7DePasseSecurise2026',    // Mot de passe pour /sync
'sync_delete_existing' => true,                      // Supprimer contenu local avant sync
```

⚠️ **Important** : Choisissez un mot de passe fort différent des mots de passe d'accès utilisateur.

### 9. Ajouter storage/ au .gitignore

Assurez-vous que `storage/` est dans `.gitignore` pour ne pas commiter les credentials :

```bash
echo "storage/google-credentials.json" >> .gitignore
```

## 🧪 Test rapide

Vérifiez que la configuration est OK :

```bash
php -r "
require 'vendor/autoload.php';
\$config = require 'app/Config/config.php';
\$gd = new App\Services\GoogleDriveService(\$config);
echo \$gd->isAvailable() ? '✅ Google Drive configuré' : '❌ Erreur config';
"
```

## 🚀 Utilisation

### Lancer une synchronisation

1. Ouvrez votre navigateur à : `https://votre-site.com/sync`
2. Entrez le mot de passe de synchronisation (`sync_password`)
3. Cochez la confirmation
4. Cliquez sur "Lancer la synchronisation"
5. Attendez la fin (barre de progression en temps réel)

### Automatisation (optionnel)

Pour synchroniser automatiquement via cron :

```bash
# Créer un script sync.php
cat > sync-cron.php << 'EOF'
<?php
require 'vendor/autoload.php';
$config = require 'app/Config/config.php';
$gd = new App\Services\GoogleDriveService($config);
$sync = new App\Controllers\SyncController($gd, null, $config);
// Appeler la méthode de sync directement
EOF

# Ajouter au crontab (tous les jours à 3h du matin)
crontab -e
# Ajouter : 0 3 * * * cd /path/to/PLE_Code && php sync-cron.php
```

**Avantages :**
- ✅ Contrôle total sur quand synchroniser
- ✅ Pas de quotas API dépassés (sync manuel)
- ✅ Interface visuelle avec progression
- ✅ Stockage local = pas de latence
- ✅ Fonctionne hors ligne après sync

**Limitations :**
- ❌ Synchronisation manuelle (pas automatique)
- ❌ Nécessite espace disque local
- ❌ Peut prendre du temps selon taille du dossier
- ❌ Service Account requis (configuration initiale)

## 🔍 Dépannage

### Erreur "credentials file not found"
→ Vérifiez le chemin dans `branding.php` : `google_drive_credentials`

### Erreur "insufficient permissions"
→ Le dossier n'est pas partagé avec le Service Account email (voir étape 7)

### Erreur "API not enabled"
→ Activez l'API Google Drive dans Cloud Console (voir étape 2)

### Erreur "Invalid password"
→ Vérifiez `sync_password` dans `branding.php`

### Fichiers non téléchargés
→ Vérifiez le `google_drive_folder_id` ; assurez-vous que c'est le bon dossier

### Timeout PHP
→ Pour gros dossiers, augmentez `max_execution_time` dans `php.ini` ou ajoutez dans `public/index.php` :
```php
set_time_limit(0); // Pas de limite
```

### Erreur "disk space"
→ Libérez de l'espace disque ou désactivez `sync_delete_existing` pour sync incrémentiel

## 📚 Documentation

- [Google Drive API PHP](https://developers.google.com/drive/api/quickstart/php)
- [Service Accounts](https://cloud.google.com/iam/docs/service-accounts)
