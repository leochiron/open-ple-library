# Configuration du Branding PLE

Ce guide explique comment personnaliser l'apparence visuelle de votre site PLE Library en configurant le fichier `branding.php`.

## Installation Rapide

1. **Copier le fichier d'exemple :**
   ```bash
   cp app/Config/branding.example.php app/Config/branding.php
   ```

2. **Éditer le fichier `branding.php`** avec vos propres valeurs

3. **Le fichier est automatiquement chargé** au démarrage de l'application

> **Important :** Le fichier `branding.php` est exclu de Git (`.gitignore`), vous devez donc créer ce fichier sur chaque serveur où vous déployez l'application.

## Configuration Détaillée

### Profil de déploiement

Le même code peut exposer uniquement la bibliothèque, uniquement les quiz,
ou les deux fonctions :

```php
'app_mode' => 'hybrid', // library | quiz | hybrid
'quiz' => [
    'enabled' => true,
    'show_admin_link' => true,
],
```

- `library` conserve la bibliothèque et ferme les routes quiz ;
- `quiz` place l'accès élève à la racine et ferme la bibliothèque ;
- `hybrid` conserve la bibliothèque à la racine et le quiz sous `/quiz` ;
- `quiz.enabled=false` coupe immédiatement toutes les routes quiz.

Une ancienne configuration qui ne contient pas ces clés reste automatiquement
en mode `hybrid`, avec le module quiz actif : aucune migration de configuration
n'est nécessaire.

### 1. Identité du Site

```php
'site_name' => 'Mon Portail PLE',
'site_logo' => '/assets/images/logo.png',
'site_logo_text' => 'Mon Portail PLE',
'header_brand_mode' => 'auto', // auto | logo | text | logo_text
```

- **`site_name`** : Nom affiché dans le titre de la page et le footer
- **`site_logo`** : Chemin vers votre logo (relatif au dossier `/public`)
- **`site_logo_text`** : Texte affiché dans l'en-tête (modes `text` et `logo_text`)
- **`header_brand_mode`** : Mode d'affichage du branding de l'en-tête

Modes disponibles pour `header_brand_mode` :
- `auto` : comportement historique (texte si `site_logo_text` est défini, sinon logo)
- `logo` : logo seul (avec fallback texte si le logo est introuvable)
- `text` : texte seul
- `logo_text` : logo + texte sur une ligne (puis empilé sur mobile)

**💡 Conseil :** Placez votre logo dans `/public/assets/images/` et assurez-vous qu'il a un fond transparent pour une meilleure intégration.

### 2. Palette de Couleurs

```php
'colors' => [
    'primary' => '#F5B400',      // Couleur principale
    'secondary' => '#E43C78',    // Couleur secondaire
    'tertiary' => '#5CB88A',     // Couleur tertiaire
    'structure' => '#3B1C4A',    // Navigation et structure
    'text' => '#1A1A1A',         // Texte principal
    'background' => '#FFFFFF',   // Fond de page
],
```

**Guide des couleurs :**

- **`primary`** : Utilisée pour les boutons, liens, éléments importants (exemple : jaune-orange)
- **`secondary`** : Accents secondaires, hover states (exemple : rose)
- **`tertiary`** : Éléments tertiaires, badges (exemple : vert)
- **`structure`** : En-têtes, navigation, bordures principales (exemple : violet foncé)
- **`text`** : Couleur du texte principal - privilégier un gris foncé pour la lisibilité
- **`background`** : Couleur de fond de page (généralement blanc ou gris très clair)

**💡 Conseils :**
- Utilisez des couleurs contrastées pour garantir l'accessibilité (ratio de contraste WCAG AA minimum : 4.5:1)
- Testez vos couleurs avec un outil comme [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)
- Restez cohérent avec l'identité visuelle de votre organisation

### 3. Typographie

```php
'fonts' => [
    'body' => "'Inter', system-ui, -apple-system, sans-serif",
    'heading' => "'Poppins', 'Inter', sans-serif",
    'ui' => "'Nunito', 'Inter', sans-serif",
],
```

**Guide des polices :**

- **`body`** : Police du corps de texte (paragraphes, contenu principal)
- **`heading`** : Police des titres (h1, h2, h3, etc.)
- **`ui`** : Police des éléments d'interface (boutons, labels, navigation)

**💡 Conseils :**
- Utilisez toujours des polices de repli (fallback fonts) comme `system-ui, sans-serif`
- Pour Google Fonts, générez l'URL d'import sur [fonts.google.com](https://fonts.google.com/)
- Limitez-vous à 2-3 familles de polices maximum pour de meilleures performances

#### Utilisation de Google Fonts

1. Visitez [Google Fonts](https://fonts.google.com/)
2. Sélectionnez vos polices et les styles (weights) nécessaires
3. Copiez l'URL générée dans `google_fonts_url`

```php
'google_fonts_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&display=swap',
```

**Si vous n'utilisez pas Google Fonts :**
```php
'google_fonts_url' => '',  // Laisser vide
'fonts' => [
    'body' => 'system-ui, -apple-system, sans-serif',
    'heading' => 'Georgia, serif',
    'ui' => '-apple-system, BlinkMacSystemFont, sans-serif',
],
```

### 4. Système de Design

```php
'design' => [
    'shadow' => '0 8px 20px rgba(0, 0, 0, 0.06)',
    'radius' => '12px',
    'spacing' => '12px',
],
```

**Paramètres du design :**

- **`shadow`** : Ombre portée pour les cartes et éléments flottants (format CSS `box-shadow`)
- **`radius`** : Rayon des coins arrondis (px, rem, em)
- **`spacing`** : Unité d'espacement de base utilisée dans toute l'interface

**💡 Conseils :**
- Pour un design moderne : `radius` entre 8px et 16px
- Pour un design classique : `radius` entre 2px et 6px
- L'ombre doit être subtile : opacité entre 0.04 et 0.1

### 5. Footer

```php
'footer_text' => 'Mon Organisation PLE · Licence MIT',
```

Personnalisez le texte du pied de page avec :
- Nom de votre organisation
- Informations de copyright
- Type de licence

## Exemples de Configurations

### Configuration Minimaliste

```php
return [
    'site_name' => 'PLE Minimaliste',
    'site_logo' => '/assets/images/logo.png',
    'header_brand_mode' => 'logo',
    'colors' => [
        'primary' => '#000000',
        'secondary' => '#666666',
        'tertiary' => '#999999',
        'structure' => '#000000',
        'text' => '#1A1A1A',
        'background' => '#FFFFFF',
    ],
    'fonts' => [
        'body' => 'system-ui, sans-serif',
        'heading' => 'system-ui, sans-serif',
        'ui' => 'system-ui, sans-serif',
    ],
    'google_fonts_url' => '',
    'design' => [
        'shadow' => 'none',
        'radius' => '0px',
        'spacing' => '16px',
    ],
    'footer_text' => 'PLE Minimaliste',
];
```

### Configuration Colorée

```php
return [
    'site_name' => 'PLE Vibrant',
    'site_logo' => '/assets/images/logo-vibrant.png',
    'site_logo_text' => 'PLE Vibrant',
    'header_brand_mode' => 'logo_text',
    'colors' => [
        'primary' => '#FF6B35',      // Orange vif
        'secondary' => '#00A8CC',    // Cyan
        'tertiary' => '#FFC300',     // Jaune doré
        'structure' => '#2D3142',    // Bleu-gris foncé
        'text' => '#2D3142',
        'background' => '#F8F9FA',
    ],
    'fonts' => [
        'body' => "'Roboto', sans-serif",
        'heading' => "'Montserrat', sans-serif",
        'ui' => "'Open Sans', sans-serif",
    ],
    'google_fonts_url' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700&family=Roboto:wght@400;500&family=Open+Sans:wght@400;600&display=swap',
    'design' => [
        'shadow' => '0 4px 16px rgba(0, 0, 0, 0.1)',
        'radius' => '16px',
        'spacing' => '16px',
    ],
    'footer_text' => '🎨 PLE Vibrant · Design 2026',
];
```

## Déploiement Multi-Serveurs

Pour déployer sur plusieurs serveurs avec des configurations différentes :

1. **Serveur Principal :**
   ```bash
   cp branding.example.php branding.php
   # Éditer branding.php avec la config du serveur principal
   ```

2. **Serveur de Développement :**
   ```bash
   cp branding.example.php branding.php
   # Utiliser des couleurs de dev différentes pour distinguer les environnements
   ```

3. **Serveur de Production :**
   ```bash
   cp branding.example.php branding.php
   # Utiliser la charte graphique officielle
   ```

## Dépannage

### Le site affiche toujours les couleurs par défaut

- Vérifiez que `branding.php` existe dans `app/Config/`
- Vérifiez les permissions du fichier (lecture requise)
- Videz le cache du navigateur (Ctrl+Shift+R ou Cmd+Shift+R)

### Les polices Google ne se chargent pas

- Vérifiez l'URL dans `google_fonts_url`
- Assurez-vous que les noms de polices dans `fonts` correspondent exactement
- Testez l'URL directement dans votre navigateur

### Les couleurs ne s'appliquent pas correctement

- Vérifiez le format hexadécimal : `#RRGGBB`
- Assurez-vous qu'il n'y a pas de guillemets supplémentaires
- Les variables CSS sont sensibles à la casse

## Support

Pour toute question ou problème, consultez la documentation du projet ou ouvrez une issue sur le dépôt Git.

---

**Dernière mise à jour :** Janvier 2026
