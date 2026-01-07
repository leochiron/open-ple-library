# Synchronisation Google Drive - Guide rapide

## 🚀 Mise en route rapide

### 1. Configuration initiale (une seule fois)

Suivez le guide complet : [GOOGLE_DRIVE_SETUP.md](GOOGLE_DRIVE_SETUP.md)

**Résumé :**
1. Créer un projet Google Cloud
2. Activer l'API Google Drive
3. Créer un Service Account
4. Télécharger le JSON de credentials → `storage/google-credentials.json`
5. Partager votre dossier Drive avec l'email du Service Account
6. Configurer `app/Config/branding.php`

### 2. Configuration dans branding.php

```php
'google_drive_credentials' => __DIR__ . '/../../storage/google-credentials.json',
'google_drive_folder_id' => 'VOTRE_FOLDER_ID',  // De l'URL Google Drive
'google_drive_enabled' => true,
'sync_password' => 'UnMotDePasseSecurise2026',
'sync_delete_existing' => true,
```

### 3. Lancer une synchronisation

**Via interface web :**
1. Allez sur : `https://votre-site.com/sync`
2. Entrez le mot de passe de sync
3. Cochez la confirmation
4. Cliquez sur "Lancer la synchronisation"

**Via ligne de commande :**
```bash
curl -X POST https://votre-site.com/sync \
  -d "sync_password=VotreMotDePasse"
```

## 📝 Notes importantes

- **Mot de passe de sync** : Différent des mots de passe utilisateurs (dans `passwords`)
- **Suppression** : `sync_delete_existing = true` supprime tout `content/` avant sync
- **Durée** : Dépend de la taille du dossier (barre de progression en direct)
- **Credentials** : Jamais commiter `branding.php` ou `storage/google-credentials.json`

## 🔒 Sécurité

- Le fichier `storage/google-credentials.json` est ignoré par Git
- Le mot de passe de sync doit être fort et unique
- L'URL `/sync` est protégée par mot de passe
- Le Service Account a accès en lecture seule (Viewer)

## ⚡ Automatisation

Pour synchroniser automatiquement toutes les nuits :

```bash
# Créer sync-cron.sh
#!/bin/bash
cd /path/to/PLE_Code
curl -X POST http://localhost/sync -d "sync_password=VotreMotDePasse"

# Rendre exécutable
chmod +x sync-cron.sh

# Ajouter au crontab (3h du matin)
crontab -e
# Ajouter : 0 3 * * * /path/to/PLE_Code/sync-cron.sh >> /var/log/ple-sync.log 2>&1
```

## 🐛 Problèmes courants

| Erreur | Solution |
|--------|----------|
| "Google Drive not enabled" | Mettre `google_drive_enabled = true` dans branding.php |
| "Invalid password" | Vérifier `sync_password` dans branding.php |
| "credentials file not found" | Vérifier le chemin `google_drive_credentials` |
| "insufficient permissions" | Partager le dossier Drive avec le Service Account |
| Timeout PHP | Augmenter `max_execution_time` ou ajouter `set_time_limit(0)` |

## 📚 Documentation complète

- [Configuration détaillée](GOOGLE_DRIVE_SETUP.md)
- [Google Drive API](https://developers.google.com/drive/api)
- [Service Accounts](https://cloud.google.com/iam/docs/service-accounts)

## 💡 Conseils

- Testez d'abord avec un petit dossier
- Vérifiez l'espace disque disponible avant sync
- Gardez une sauvegarde du dossier `content/` avant le premier sync
- Surveillez les logs dans `storage/php-error.log`
