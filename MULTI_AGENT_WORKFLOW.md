# Organisation multi-agent du projet Quiz

Statut : actif

## Rôles

### Chef de projet

- analyse et consolide les besoins ;
- maintient la spec de référence ;
- découpe les lots et tranche les contradictions ;
- demande la validation utilisateur lorsqu’une décision modifie le produit ;
- autorise le démarrage du développement ;
- décide si un lot est publiable.

### Lead développeur

- audite l’architecture avant développement ;
- valide le découpage technique et les migrations ;
- définit les critères de revue ;
- relit le diff du développeur ;
- refuse les changements trop larges, les contrôles uniquement visuels et les migrations risquées ;
- remet le lot au testeur après revue.

### Développeur

- ne développe que le lot validé ;
- travaille par petits commits cohérents ;
- préserve la compatibilité ascendante ;
- ajoute les tests techniques prévus ;
- documente les décisions et limites rencontrées ;
- remet au lead un diff propre avec les vérifications exécutées.

### Testeur

- transforme les critères d’acceptation en scénarios vérifiables ;
- prépare les jeux de données et les tests de non-régression ;
- teste la sécurité des routes et des autorisations ;
- qualifie les anomalies comme bloquantes ou non bloquantes ;
- ne valide jamais un comportement uniquement parce que l’interface masque une action ;
- remet un verdict de recette au chef de projet.

## Règle de travail partagé

Les agents partagent le même répertoire. Un seul agent est autorisé à modifier les fichiers à la fois.

Séquence obligatoire :

```text
Chef de projet : spec et périmètre
        ↓ validation
Développeur : implémentation et tests techniques
        ↓
Lead développeur : revue du code et de l’architecture
        ↓ corrections éventuelles
Testeur : recette fonctionnelle, sécurité et non-régression
        ↓
Chef de projet : décision de publication
```

Le lead et le testeur peuvent analyser en parallèle avant le développement, mais leurs modifications de fichiers sont séquencées par le chef de projet.

## Cycle d’un lot

1. Le chef de projet publie une spec d’exécution avec périmètre, hors-périmètre et critères d’acceptation.
2. Le lead confirme que le lot est assez petit et réversible.
3. Le testeur prépare la recette avant l’implémentation.
4. Le développeur réalise le lot.
5. Le développeur exécute les validations locales et transmet le diff.
6. Le lead relit toutes les zones critiques et demande les corrections nécessaires.
7. Le testeur exécute la recette sur la version relue.
8. Le chef de projet consolide les résultats.
9. Un commit et une publication ne sont effectués qu’après validation du lot.

## Critères généraux bloquants

- secret, mot de passe en clair, base ou journal sensible ajouté à Git ;
- perte ou migration non réversible de données ;
- route interdite encore accessible directement ;
- contrôle d’autorisation présent uniquement dans une vue ;
- fuite de données entre deux administrateurs ;
- action modifiante non protégée ;
- échec des tests de syntaxe ou de la recette obligatoire ;
- régression critique du mode historique ;
- documentation et code en contradiction sur le comportement livré.

## Gestion des specs

- `SPEC_MODE_QUIZ.md` est la spec produit de référence.
- Chaque incrément possède une spec d’exécution courte et testable.
- `SPEC_QUIZ_MODE.md` est une ancienne proposition et ne doit plus piloter le développement.
- Une décision ultérieure remplace explicitement la précédente dans la spec de référence.

## Politique de publication

- branche dédiée par lot ;
- commits petits et descriptifs ;
- vérifications exécutées avant chaque commit ;
- revue du lead puis recette QA avant fusion ;
- demande de fusion en brouillon tant que la recette n’est pas terminée ;
- fusion dans `main` uniquement après décision du chef de projet.

