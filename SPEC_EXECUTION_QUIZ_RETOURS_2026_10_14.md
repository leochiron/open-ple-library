# Corrections des retours Quiz — 14 octobre 2026

Autorisation : demande de Léo du 14 octobre 2026. Base récupérée : `a2de4c2`.

- Rendre « Retirer les alertes » accessible depuis la liste des élèves et le détail d'une tentative. Réutiliser l'arbitrage serveur existant : historique conservé, statut recalculé, contrôle du propriétaire et CSRF.
- Ne créer aucune alerte pour les sorties demandées pendant une préparation, y compris après une première admission. Les observations reçues pendant un défi de préparation du document courant restent non pénalisantes après requalification. Les sorties normales après préparation restent comptabilisées.
- Fiabiliser la déclaration de fin face aux réponses retardées et aux erreurs réseau. Une fin acquittée doit être répercutée dans l'état local ; les sondages périodiques ne doivent pas invalider sa confirmation. Distinguer l'envoi Google Forms et la déclaration Quiz, sans prétendre lire la confirmation d'une iframe externe.

Vérifications préalables : 17 suites PHP fonctionnelles et 8 suites JS réussies avec PHP 8.4 et SQLite ; règles Apache vérifiées statiquement après rétablissement des protections locales. Test de routes réussi sur copie propre isolée (la copie Nextcloud possède une base locale préexistante). Total : 27 suites avec les deux contrôles globaux. Apache réel non testé.

Préserver les changements locaux antérieurs : migration ESGI, en-tête et ressources pédagogiques. Sauvegarde du diff local dans le dossier voisin `sauvegarde-avant-mise-a-jour-2026-10-14`. Aucun changement de données ni déploiement de production dans ce lot.
