# Étude — Signaux d’assistance IA pendant un quiz

Date de l’étude : 20 juillet 2026

## Résumé de décision

Une application web ne peut pas détecter de façon fiable qu’un élève utilise Apple Intelligence, ChatGPT, une extension de navigateur ou un autre assistant IA déclenché depuis une sélection de texte.

Le produit ne doit donc jamais afficher « IA détectée ». Il peut seulement collecter des **signaux d’intégrité observables**, les corréler dans le temps et les présenter à l’enseignant pour arbitrage.

Avec le moteur actuel fondé sur un Google Form intégré dans une iframe cross-origin, la page parente ne peut pas observer la sélection, le presse-papiers ou la saisie à l’intérieur du formulaire. Une télémétrie de saisie plus riche suppose un questionnaire intégré à l’application et servi depuis la même origine, sans pour autant permettre d’attribuer avec certitude un texte à une IA.

## Scénario étudié : Apple Intelligence Writing Tools

Apple documente que les outils d’écriture peuvent fonctionner dans des sites web et applications tierces. L’utilisateur peut sélectionner du texte, ouvrir les outils d’écriture, résumer le texte ou demander une réécriture, y compris avec l’extension ChatGPT.

Une page web ne reçoit toutefois aucun événement JavaScript standard indiquant que les outils d’écriture ont été ouverts ou utilisés. Les API Apple capables de configurer les Writing Tools concernent une application native UIKit, AppKit ou WKWebView ; elles ne sont pas accessibles au JavaScript d’un site ordinaire.

## État actuel du projet

`public/assets/js/quiz-monitor.js` observe déjà :

- `visibilitychange`, `blur`, `focus` et `pagehide` ;
- la sortie du plein écran ;
- un heartbeat périodique, dont le retard ou l’absence peut être déduit côté serveur ou dans l’administration ;
- certains raccourcis clavier de copie, collage, impression et outils de développement ;
- les événements DOM `copy`, `cut` et `paste` reçus par la page parente.

Limites constatées :

- aucun suivi de `selectionchange` ou `contextmenu` ;
- les événements `copy`, `cut` et `paste` issus des menus sont journalisés mais ne sont pas bloqués ;
- les textes d’interface peuvent laisser croire que toutes les méthodes de copie sont bloquées ;
- les raccourcis et événements internes au Google Form ne remontent pas à la page parente ;
- aucun test automatique ne couvre actuellement le moniteur JavaScript ;
- une sélection suivie d’un menu Apple Intelligence peut ne produire ni copie, ni perte de focus, ni changement de visibilité.

## Matrice d’observabilité

| Action | Page ou questionnaire même origine | Google Form cross-origin | Valeur probante |
|---|---|---|---|
| Copier, couper ou coller dans la page | Événement DOM généralement observable | Invisible depuis la page parente | Signal direct d’une action de presse-papiers reçue par la page, pas une preuve d’IA |
| Sélectionner du texte | `selectionchange` observable | Invisible depuis la page parente | Contexte très faible ; usage normal fréquent |
| Ouvrir le menu contextuel | `contextmenu` parfois observable | Invisible depuis la page parente | Signal faible et contournable |
| Remplacer rapidement un texte | `beforeinput`/`input` et variation de longueur parfois observables | Invisible depuis la page parente | Heuristique ; peut être autocorrection, dictée ou accessibilité |
| Utiliser Apple Writing Tools sans modifier le document | Aucun événement IA standard | Invisible | Non détectable |
| Ouvrir une extension IA silencieuse | Généralement invisible | Invisible | Non détectable de façon fiable |
| Changer d’onglet ou d’application | `visibilitychange`/`blur` selon le cas | Observable au niveau global | Signal de sortie, pas une preuve d’IA |
| Utiliser un second appareil | Invisible | Invisible | Non détectable |

## Fonctionnalité proposée : signaux d’assistance externe

### Objectif

Ajouter au rapport enseignant une chronologie factuelle de signaux pouvant accompagner l’usage d’une ressource externe, sans identifier automatiquement une IA et sans annuler automatiquement une tentative.

### Signaux envisagés

Pour les éléments contrôlés par l’application et servis depuis la même origine :

- `clipboard_copy`, `clipboard_cut`, `clipboard_paste` ;
- `context_menu_opened` lorsqu’une sélection non vide existe ;
- `selection_context` agrégé, jamais compté seul comme incident ;
- `large_text_replacement` lorsqu’un champ natif subit un remplacement important et rapide ;
- `page_hidden`, `window_blur`, `fullscreen_exit` et `heartbeat_gap` déjà disponibles ;
- `telemetry_unavailable`, état dérivé côté serveur après un délai configurable lorsque le client ne remonte plus les signaux attendus.

Le signal `large_text_replacement` ne signifie pas « IA ». Il peut également correspondre à une correction orthographique, une dictée, une méthode de saisie, un outil d’accessibilité ou un collage autorisé.

### Données à ne jamais collecter

- texte sélectionné ;
- contenu copié, coupé ou collé ;
- contenu du presse-papiers système ;
- réponses complètes de l’élève dans la télémétrie ;
- liste supposée des extensions installées.

Les événements ne doivent contenir que le type, l’horodatage, la durée éventuelle, une cible générique, une longueur bornée et les informations techniques nécessaires à la déduplication.

### Règles de décision

- Une sélection, un clic droit, un blur ou un remplacement ne constitue jamais seul un incident invalidant.
- L’état serveur `telemetry_unavailable` n’est jamais invalidant à lui seul : il peut aussi correspondre à une panne réseau, une suspension du navigateur ou un problème technique.
- Les séquences `keydown` puis `copy`, ou `blur` puis `hidden`, doivent être dédupliquées.
- Le rapport distingue les faits observés, les heuristiques et une télémétrie indisponible.
- Aucun libellé ne doit affirmer qu’une IA a été détectée.
- Les seuils sont configurables par session et annoncés à l’élève avant l’épreuve.
- Les besoins d’accessibilité peuvent désactiver certaines règles pour un élève sans masquer cette exception dans l’audit.
- Toute invalidation reste une décision humaine et peut être excusée avec traçabilité.

## Niveaux de mise en œuvre

### Niveau 1 — Google Forms actuel

- conserver visibilité, focus, plein écran et heartbeat ;
- corriger les libellés pour distinguer raccourcis bloqués et événements seulement journalisés ;
- journaliser et dédupliquer les actions de presse-papiers reçues par la page parente ;
- ajouter éventuellement le menu contextuel de la page parente comme signal faible ;
- afficher explicitement dans l’administration que les actions internes au Google Form et les outils IA du système sont invisibles.

Ce niveau ne constitue pas une détection d’assistance IA.

### Niveau 2 — Questionnaire intégré à l’application et servi depuis la même origine

- observer sélection et événements de saisie dans les champs contrôlés ;
- corréler sélection, menu contextuel, absence de frappe et remplacement important ;
- tester `beforeinput`, `inputType` et les différences entre navigateurs ;
- conserver uniquement des métadonnées, jamais le texte ;
- présenter un score ou une chronologie de risque, pas une attribution à une IA.

Même à ce niveau, une lecture ou un résumé affiché dans une surcouche système, une extension silencieuse et un second appareil restent indétectables.

### Niveau 3 — Parc informatique administré

Pour une épreuve nécessitant des restrictions plus fortes, la prévention doit être extérieure à la page web :

- restriction Apple MDM des Writing Tools et extensions d’intelligence ;
- navigateur administré avec liste blanche d’extensions ;
- mode kiosque ou navigateur d’examen dédié ;
- filtrage réseau limité aux domaines nécessaires ;
- contrôle humain en salle.

Le filtrage réseau limite uniquement l’accès aux services distants depuis le parc et le réseau administrés. Il ne bloque ni un modèle exécuté localement sur l’appareil, ni un second appareil utilisant un autre réseau.

Une application native utilisant WKWebView peut configurer le comportement des Writing Tools. Il s’agit d’une mesure de prévention, pas d’une preuve d’usage après coup.

## Critères d’acceptation futurs

1. Les événements de presse-papiers sont dédupliqués et ne contiennent aucun texte.
2. La sélection et le menu contextuel ne déclenchent aucune invalidation automatique.
3. Le rapport utilise des libellés factuels et ne contient jamais « IA détectée ».
4. La télémétrie reçue est validée, bornée, limitée en fréquence et rattachée côté serveur à la bonne tentative.
5. Les scénarios Google Forms et questionnaire intégré à l’application possèdent des résultats de tests distincts.
6. Les tests couvrent Chrome, Edge, Firefox et Safari, Windows et macOS, puis Safari iOS/iPadOS et Chrome Android. Une recette manuelle est exécutée sur du matériel Apple compatible avec Writing Tools activé et identifie précisément la version du système.
7. Les raccourcis clavier, menus souris, appui long mobile, dictée et outils d’accessibilité sont testés.
8. Une extension IA silencieuse, Apple Intelligence et un second appareil sont documentés comme limites non détectables.
9. Les exceptions d’accessibilité sont configurables et visibles dans l’audit.
10. Le système continue de fonctionner lorsque la télémétrie est absente ou falsifiée, sans attribuer cette absence à une IA.

## Recommandations complémentaires

La meilleure réduction du risque ne repose pas uniquement sur la surveillance :

- questions et valeurs individualisées ;
- ordre aléatoire et banque de variantes ;
- demande de justification ou d’étapes intermédiaires ;
- durée cohérente avec le travail attendu ;
- court contrôle oral en cas de doute ;
- vérification humaine des incidents corrélés.

## Sources principales

- Apple Support — Writing Tools avec Apple Intelligence : https://support.apple.com/fr-fr/121582
- Apple WWDC24 — Writing Tools et intégration native : https://developer.apple.com/wwdc24/10168
- Apple Device Management — restrictions d’intelligence : https://developer.apple.com/documentation/devicemanagement/intelligencesettings
- WebKit — fonctionnalités Writing Tools de Safari 18.1 : https://webkit.org/blog/16188/webkit-features-in-safari-18-1/
- MDN — politique de même origine : https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Same-origin_policy
- MDN — événements du presse-papiers : https://developer.mozilla.org/en-US/docs/Web/API/ClipboardEvent
- MDN — événement `selectionchange` : https://developer.mozilla.org/en-US/docs/Web/API/Document/selectionchange_event
- MDN — événement `contextmenu` : https://developer.mozilla.org/en-US/docs/Web/API/Element/contextmenu_event
- W3C — Input Events Level 2 : https://www.w3.org/TR/input-events-2/
- Chrome Developers — mondes isolés des content scripts : https://developer.chrome.com/docs/extensions/develop/concepts/content-scripts
