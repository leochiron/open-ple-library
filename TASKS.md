# Tâches à faire — Module Quiz

| Priorité | Tâche | Description |
|----------|-------|-------------|
| 🔴 Haute | **Relancer une session** | Bouton "Relancer" sur la page admin d'une session : archive l’exécution et ses événements sans les effacer, crée une nouvelle exécution liée, remet les tentatives concernées à `started` et repasse la session à l'état `running`. |
| 🔴 Haute | **Excuser un élève entièrement** | Bouton "Excuser l'élève" dans la page détail de la tentative : remet à zéro tous les incidents de cet élève (tous les événements passent à `excused=1`) et recalcule le statut → `started`. |
| 🟡 Moyenne | **Mise à jour temps réel des statuts élèves** | Dans la page session admin, rafraîchir via AJAX (polling ou SSE) la liste des élèves : statut (`started/suspect/invalid`), connecté/déconnecté, nombre d'incidents — sans recharger la page. |
| 🟢 Basse | **Bouton "J'ai terminé" côté élève** | Dans la salle d'examen, un bouton permet à l'élève de signaler qu'il a terminé. Quitte le plein écran, affiche un message de confirmation, empêche toute action ultérieure sur la page (monitoring arrêté). |

## Supervision des assistants IA — fonctionnalités étudiées

L’étude de faisabilité est consignée dans `RESEARCH_AI_INTEGRITY_SIGNALS.md`. Elle conclut qu’Apple Intelligence et les extensions IA silencieuses ne sont pas détectables de façon fiable depuis une page web, en particulier à l’intérieur du Google Form cross-origin.

| Priorité | Tâche | Description |
|----------|-------|-------------|
| 🔴 Haute | **Corriger les promesses de blocage** | Distinguer dans l’interface les raccourcis réellement bloqués des actions seulement observées. Ne jamais afficher « IA détectée » ni promettre le blocage de toutes les méthodes de copie. |
| 🔴 Haute | **Dédupliquer et tester le presse-papiers** | Séparer `copy`, `cut` et `paste`, fusionner les rafales `keydown` + événement DOM, ne jamais enregistrer le contenu et ajouter des tests JavaScript multi-navigateurs. |
| 🔴 Haute | **Chronologie de risque factuelle** | Afficher dans le rapport les événements observés, heuristiques et périodes sans télémétrie, avec corrélation temporelle et arbitrage enseignant, avant d’ajouter de nouveaux signaux faibles. |
| 🟢 Basse | **Signal contextuel de sélection** | Pour les seuls éléments même origine, expérimenter `selectionchange` et `contextmenu` comme contexte faible, configurable et non invalidant. Préserver navigation clavier et accessibilité. |
| 🟡 Moyenne | **Télémétrie d’un questionnaire intégré** | Si un questionnaire servi par l’application depuis la même origine est développé, étudier `beforeinput`, `inputType` et les remplacements importants sans conserver le texte saisi. Cette tâche ne s’applique pas au Google Form actuel. |
| 🟢 Basse | **Profil d’examen sur parc administré** | Documenter un déploiement MDM/navigateur administré/mode kiosque pour désactiver préventivement Writing Tools et limiter les extensions. Le présenter comme prévention externe, jamais comme preuve d’usage. |
