# Tâches à faire — Module Quiz

| Priorité | Tâche | Description |
|----------|-------|-------------|
| 🔴 Haute | **Relancer une session** | Bouton "Relancer" sur la page admin d'une session : remet tous les `attempt.status` à `started`, vide les événements (ou les archive), et repasse la session à l'état `running`. |
| 🔴 Haute | **Excuser un élève entièrement** | Bouton "Excuser l'élève" dans la page détail de la tentative : remet à zéro tous les incidents de cet élève (tous les événements passent à `excused=1`) et recalcule le statut → `started`. |
| 🟡 Moyenne | **Mise à jour temps réel des statuts élèves** | Dans la page session admin, rafraîchir via AJAX (polling ou SSE) la liste des élèves : statut (`started/suspect/invalid`), connecté/déconnecté, nombre d'incidents — sans recharger la page. |
| 🟢 Basse | **Bouton "J'ai terminé" côté élève** | Dans la salle d'examen, un bouton permet à l'élève de signaler qu'il a terminé. Quitte le plein écran, affiche un message de confirmation, empêche toute action ultérieure sur la page (monitoring arrêté). |
