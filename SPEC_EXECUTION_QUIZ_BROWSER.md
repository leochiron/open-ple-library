# Lot 6 — Politique navigateur et cohérence : contrat d'exécution

Spécification d'exécution autorisée par le chef et approuvée par le lead, 8 octobre 2026. Lot 5b publié au commit `af61c14` ; développement du lot 6 autorisé sur `agent/esgi-quiz-browser-policy`. Publication après gel, revue lead et QA indépendante. Les informations de navigateur sont déclaratives ; aucune origine ou signature de logiciel n'est attestée.

## Interfaces retenues pour la revue finale

POST `/quiz-admin/browser/policy` et méthode `setBrowserPolicy(int sessionId, bool enabled, array families, int expectedSettingsRevision, string reason): void` sont retenus. Valeurs familles exactes, liste non vide sans doublons, ordre canonique chrome/edge/firefox/safari. Pas de minimum de version dépendant de l'actualité : seule une majeure produit numérique positive identifiée est requise. La mutation conserve la preuve tracking établie si seules les règles browser changent. Un changement de tracking mode applique séparément le contrat 5b ; il n'autorise jamais browser activé et tracking off simultanément.

Le service utilise l'UA HTTP de la requête courante reçue par PHP, jamais celle choisie dans le JSON client. Les méthodes challenge/préflight/pulse gardent les liaisons existantes et reçoivent une enveloppe browser pour schema2. Aucun nouveau endpoint de décision intermédiaire ni purpose public. Pour schema1 historique browseroff, la signature héritée reste utilisable ; aucune santé browser implicite.

La fraîcheur de l'UA est évaluée seulement dans state/challenge/préflight/pulse/finish/resume après la liaison stricte du document étudiant courant. Une supervision, un rapport, un export ou un polling de formateur ne compare jamais l'UA du formateur aux contextes étudiants et ne modifie pas leurs validations browser. Les observations ordinaires3a/5a retardées, avec ancien epoch ou sans epoch, restent admissibles pour la même tentative/génération ; heartbeat et ces observations ne font aucune évaluation browser. Leur ACK reste étroit, sans permission positive, URL ou epoch actuelle. Le rafraîchissement privé d'expiration tracking reste fondé sur l'horloge serveur.

La classe pure `QuizBrowserEvaluator` fournit parse/coherentTokens/normalizeEnvelope/evaluate/policyFingerprint/requiredCapabilities. Le trait `QuizBrowser` associe politique, nonces et projections privées au service existant. Les colonnes et enums exacts sont documentés ci-dessous, sans les exposer côté élève.

## Prédicats client des capacités

Chacun est protégé séparément contre un getter/appel qui lève une exception, et produit un booléen strict. Il s'agit de déclarations d'existence des API, sans preuve native ni garantie que le code reçoit leurs événements.

- `event_target` : EventTarget et Event sont des fonctions, et les méthodes courantes addEventListener, removeEventListener et dispatchEvent de EventTarget sont des fonctions.
- `visibility_api` : document.hidden est un booléen et document.visibilityState une chaîne ; aucune affirmation de visibilité réelle par ce seul prédicat.
- `focus_api` : document.hasFocus est une fonction.
- `fetch_api` : window.fetch est une fonction.
- `abort_controller` : AbortController et sa méthode abort sont des fonctions.
- `monotonic_clock` : performance.now est une fonction et son appel rend une valeur numérique finie.
- `fullscreen` : demande requestFullscreen||webkitRequestFullscreen et sortie exitFullscreen||webkitExitFullscreen choisies indépendamment, comme le quiz ; les paires mixtes sont admises. Une indication explicite de disponibilité false pour la variante de demande choisie rend le prédicat false ; son absence seule ne crée pas un refus si les méthodes sont présentes. Cette capacité est requise seulement avec require_fullscreen.

Le client émet schema2 uniformément aux deux étapes avec UA JS/brands/capabilities ; il n'envoie ni erreur de getter ni exception dans sa console. Une lecture impossible ne fabrique pas une famille officielle ou un résultat sain. Pas de troncature silencieuse de listes pouvant cacher une contradiction : les bornes de transport déclenchent le refus générique et le diagnostic privé existants.

Les causes privées sont `browser_outside_policy`, `browser_info_inconsistent`, `required_capability_unavailable`, `browser_verification_required`, `browser_environment_changed`. Les constats append-only utilisent browser_assessment_valid/browser_assessment_failed/browser_environment_changed/browser_verification_required, opérations preflight/pulse/transition. Le mot signature ne désigne jamais le fingerprint de politique ou de canon.

Contrat approuvé du chef et du lead, 8 octobre 2026. Dépend du contrôle d'accès4 et du suivi5. L'étude de confiance réelle reste un chantier extérieur au web ordinaire.

## Politique retenue

Mode explicite par quiz, initialement off sur les sessions existantes pendant la recette progressive. Lorsque demandé : famille déclarée Chrome, Edge, Firefox ou Safari avec version majeure numérique positive et capacités utiles. La liste correspond à une politique applicative, pas à des éditeurs certifiés ou à une version cryptographiquement prouvée. Tests de parse/cohérence et tests de fonctionnement réels sont distingués dans les docs ; pas d'annonce de test réel Firefox/Safari sans navigateur disponible.

Le serveur utilise l'User-Agent de la requête courante, jamais seulement celui de la première connexion. Le diagnostic client transmet UA JS borné, liste de marques faible entropie si disponible, et booléens stricts des seules capacités nécessaires, dont plein écran conditionnel. Comparer famille/majeure ; ne pas exiger les versions mineures complètes, OS/modèle/architecture ou high-entropy hints. Changement d'environnement canonique famille/majeure/capacités requises invalide la validation précédente et impose une vérification fraîche liée au cookie ; une variation d'octets UA, de mineure, de plateforme ou d'ordre GREASE seule ne l'invalide pas.

## Parse et disponibilité

- Edge (`Edg`, `EdgA`, `EdgiOS`) avant Chrome ; Chrome peut annoncer `CriOS`. Firefox inclut `Firefox` ou `FxiOS`. Safari exige `Version` et `Safari`, hors marque concurrente reconnue ; sa version moteur n'est pas sa version produit. Les marqueurs explicites hors politique, notamment Opera/OPR/OPT et ancien Edge, ne deviennent pas Chrome par présence du token moteur. [MDN User-Agent](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/User-Agent), [identifiants Edge](https://learn.microsoft.com/en-us/microsoft-edge/web-platform/user-agent-guidance), [Firefox iOS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/User-Agent/Firefox).
- `navigator.userAgentData` n'est pas universel ; son absence seule ne bloque pas Firefox/Safari ni un autre contexte légitime sans cette API. [Disponibilité MDN](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/userAgentData).

Les combinaisons Firefox+Chrome ou Edge+Firefox et deux tokens d'une même famille portant des majeures incompatibles restent incohérentes, même si HTTP et JS sont identiques. Safari avec deux Version/18 et Version/19 est incohérent ; cette vérification Version s'applique seulement au produit Safari, pour conserver CriOS/FxiOS+Safari iOS et Edge+Chrome documentés. La cohérence intrinsèque de l'UA HTTP fraîche est contrôlée aussi par state/finish, sans attendre une nouvelle enveloppe. Toutes chaînes UA/brand/version refusent les caractères Unicode de catégorie Cc, y compris C1 ; la ponctuation GREASE reste admise.
- Chrome iOS en mode bureau peut annoncer `CriOS/85` sans point, avec `Version` et `Safari` ; le marqueur CriOS prime. [Documentation Chromium iOS](https://chromium.googlesource.com/chromium/src/+/main/docs/ios/user_agent.md). Firefox sur iPad peut annoncer exactement Safari : enregistrer la famille déclarée Safari, sans prétendre reconnaître Firefox ni refuser faute de FxiOS. [MDN Firefox](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/User-Agent/Firefox).
- Les marques arrivent en ordre variable avec des entrées arbitraires GREASE. Ignorer les marques inconnues plutôt que les appeler incohérentes ; comparer seulement les marques reconnues pertinentes et leurs versions majeures. Une marque Chromium seule ne prouve pas Chrome officiel. [WICG](https://wicg.github.io/ua-client-hints/), [Chrome Client Hints](https://developer.chrome.com/docs/privacy-security/user-agent-client-hints).

Ni hints absents, ni plateforme réduite, ni ordre GREASE ne constituent seuls une fraude ou un motif de blocage. Les recommandations de compatibilité des éditeurs déconseillent de restreindre un site par sniffing ; ici les refus sont une politique explicitement demandée par Léo, avec récupération individuelle et limites déclaratives documentées.

## Décision et confidentialité

Motifs précis privés et stricts : `browser_outside_policy`, `browser_info_inconsistent`, `required_capability_unavailable` ; ajouter un état privé de vérification nécessaire si nouvelles données manquent. Conserver diagnostic utile borné, réception serveur, tentative/génération et contexte non secret. Pas de contenu Form, empreinte canvas/audio/GPU, IP comme identité, signature prétendue ni payload arbitraire.

Chaque délivrance d'URL et finish/resume relit la décision fraîche. Le navigateur B ne profite pas de l'évaluation d'A, même avec même code/tentative. La dérogation browser masque seulement les causes browser ; tracking demeure exigé sauf son propre scope. Tracking exempté permet de transmettre les données browser sans gestes indisponibles mais ne contourne jamais les capacités/policy browser requises. Manualblock reste absolu.

En mode renforcé, permission finale serveur uniquement pour le masque d'accès ; un ancien fullscreen gate local ne doit pas bloquer à nouveau une permission exemptée. Capacité fullscreen relève de browser ; réception/état requis de tracking. Les contrôles non exemptés restent actifs.

Élève/board : message unique générique et permission finale, aucune cause, scope, checkfailed, acteur ou données diagnostic dans DOM/config/JSON/erreurs. Formateur : motifs précis, informations observées et caractère déclaratif ; audit immuable des décisions/exceptions et CSV cohérent. Sous dérogation, conserver les causes au lieu de fabriquer un navigateur fiable.

## Recette

Chrome/Edge/Firefox/Safari, mobiletokens, versions tronquées, UA HTTP/JS majeures différentes, marque Edge vs Chrome, Safari moteur605/versionproduit, GREASE mélangé, CHabsent, marque inconnue, familles horspolitique, champs supplémentaires/mauvais types/taille. Nouveau cookie et changement UA sur tentative déjà validée ; preuve/client A ne doit ouvrir B. Overrides partiels, révocation, expiration close/newlaunch, manuel persistant, privacy et snapshots/export.

Falsification concordante HTTP/JS/hints/capacités : documenter qu'elle peut passer. Les outils Chrome permettent de changer UA et Client Hints, donc concordance ne prouve pas l'exécutable (conclusion tirée du modèle déclaratif). [Chrome DevTools](https://developer.chrome.com/docs/devtools/device-mode/override-user-agent). Aucun exemplaire du navigateur personnalisé de l'élève n'a été fourni ; ce cas réel reste à reproduire si disponible.

Publication après revue/QA avec drapeau testable, sans activer silencieusement tous les quiz avant recette manuelle.

## Contrat retenu pour revue après QA5a

Les décisions suivantes sont acceptées par le chef et le lead et appliquées dans le lot6. Les 58 fixtures et leurs 11 paires canoniques sont des exemples synthétiques, pas des attestations de navigateur réel. La recette HTTP indépendante et le navigateur réel sont distincts des tests métier/VM du développeur.

### Dépendance et mutations de politique

Validé : browser est off pour les nouveaux quiz et les migrations. Son activation exige tracking=preflight ou continuous ; une mutation tracking→off est refusée sous verrou tant que browser est actif. Pas de désactivation implicite de browser, ni d'activation silencieuse de tracking pour satisfaire cette dépendance. Deux routes administratives dédiées appliquent les mêmes droits propriétaire/superadmin, CSRF, révision attendue, motif privé, no-op refusé et audit atomique que5a. Le formulaire général ignore les champs de mode injectés.

Décision chef après revue lead : enabled/off est séparé d'un tableau explicite non vide parmi Chrome, Edge, Firefox et Safari. Les quatre familles sont admises par défaut, sans cutoff de version actuelle ni minimum de version ajouté. Des cases formateur et une mutation POST dédiée owner/superadmin/CSRF/motif/révision contrôlent ce tableau strict. La révision de politique réutilise settings_revision partagée, sans nouvelle révision publique ou browser_policy_revision distincte. Le changement browser termine sa validation et ses pending concernés, sans renouveler ni retirer une preuve tracking saine. Un changement de règle fullscreen conserve les effets tracking5a/5b indépendants de ceux de browser : une couverture tracking devenue insuffisante exige toujours le préflight complet.

Une activation de browser, un changement pertinent de politique ou une découverte d'environnement modifié impose une nouvelle évaluation browser. Désactiver browser retire ses seules causes et ne fabrique aucune santé tracking. Décision chef+lead : un fingerprint privé de politique contient familles admises, capacités requises et exigence fullscreen ; il exclut titre/quota/seuil/durée/URL/reload. La validation browser persistante est liée à ce fingerprint, pas à la révision partagée de paramètres ordinaires. settings_revision est la liaison de tous nonces pending : ces modifications ordinaires refusent un ancien nonce, sans retirer une validation browser compatible. Aucun changement de politique ne prolonge automatiquement un TTL tracking.

### Deux validations distinctes et permission finale

Validé : un nonce porte des pouvoirs privés séparés pour tracking et browser, définitivement fixés à émission. Sa réponse publique ne contient jamais ces pouvoirs, un purpose, un scope, une incarnation ou un motif. Préflight et pulse utilisent chacun leur surface uniforme schema2 ; ils restent deux opérations non interchangeables. Un client ne sélectionne ni cause ni pouvoir dans le POST.

Matrice interne retenue :

| Situation à l'émission | Pouvoir tracking du nonce | Pouvoir browser du nonce |
|---|---|---|
| Préflight, tracking manquant/failed/expired | full_preflight | assessment si browser actif, none sinon |
| Préflight, tracking sain encore valide et browser à revalider | diagnostic_only | assessment |
| Préflight volontaire, deux validations encore valides | full_preflight | assessment si actif |
| Pulse, incarnation tracking saine encore valide | renewing | assessment si actif, none sinon |
| Pulse, tracking manquant/failed/expired | diagnostic_only | assessment si actif, none sinon |

Le cas browser à revalider est décidé serveur, jamais par un champ browser_only choisi client. Dans ce cas le préflight peut recevoir sept gestes false : la donnée diagnostique est conservée, mais aucune ancienne preuve tracking saine n'est détériorée ni prolongée. Le pouvoir diagnostic_only est fixé à émission. L'expiration tracking I pendant cette préparation indépendante ne termine pas son challenge préflight : résultat browser positif encore lié à cookie/doc/G/politique/environnement peut établir browser, mais l'accès reste false faute de santé tracking non exemptée. Diagnostic_only ne devient jamais full_preflight ou renewing ; aucune santé/TTL tracking n'est fabriquée. Un préflight J ultérieur supersède les pending et refuse un ancien nonce I, même à même seconde. Le recontrôle volontaire entièrement valide garde la faculté5a de constater une nouvelle failure tracking. Le client dispose toujours du bouton générique de soumission incomplète/du timeout, sans recevoir la nature des contrôles requis.

Browser assessment peut établir une validation browser neuve après ses propres missing/pending/failed, car il consiste en une déclaration fraîche contrôlée et ne remplace aucun geste natif tracking. Un pulse diagnostic_only avec deux booléens true ne crée jamais une santé tracking, même si l'évaluation browser est positive. Un tracking override ne rend jamais browser valide ; un browser override ne change ni les gestes ni les TTL tracking. Sous override les constats continuent, avec état brut inchangé ou failed, sans santé fabriquée.

Décision chef+lead : aucun TTL browser nouveau et aucune deuxième boucle. Une évaluation browser reste valide pour le document/G/politique/environnement canonique HTTP inchangés ; seules leurs modifications ou une déclaration fraîche incohérente/de capacité manquante la retirent. En continuous chaque pulse20s transmet les capacités fraîches et peut invalider browser même avec un pouvoir tracking diagnostic_only. En preflight, notamment sous tracking override, les capacités demeurent celles de la dernière évaluation browser ; sa date et sa provenance restent privées et cette limite est explicitement documentée. Une évaluation browser seule emprunte le préflight uniforme, sans refaire les gestes lorsqu'une preuve tracking est encore saine.

Permission serveur : manualblock interdit toujours. Browser actif non exempté exige sa validation toujours liée au document/G/politique/HTTPcanon courant ; tracking actif non exempté exige sa preuve saine non expirée. La borne publique reste celle5a/5b, au plus now+60 et proof_until tracking si non exempté, sans borne browser inventée. Chaque scope ne masque que ses causes, sans modifier les états bruts. Avec deux scopes, seule une permission finale bornée est accordée ; aucune preuve saine n'est créée. Après révocation la garde serveur bloque dès la première opération si une cause demeure, sans prétendre un push instantané au navigateur.

### Environnement canonique et moment d'observation

Validé : state, chaque délivrance d'URL et finish/resume lisent l'UA HTTP de la requête courante, le canonisent et le comparent à la dernière validation browser de ce cookie/document. Un changement de famille ou majeure produit retire seulement browser ; tracking conserve son incarnation et sa borne, sauf effet indépendant des règles5a/5b. Ne pas utiliser l'UA HTTP capturé au join ou un UA transmis dans un champ client comme source HTTP.

Canon partagé déterministe retenu : famille/majeure produit HTTP, famille/majeure produit JS, résultat de cohérence des marques produit reconnues et booléens des seules capacités actuellement requises, profil des capacités requises et fingerprint privé de politique. Même parseur de produit HTTP/JS, tri fixe des clés et ensembles normalisés, représentation stable. Le digest de cette représentation lie le nonce ; il n'est ni envoyé côté public ni appelé signature de logiciel. Pas de mineure, OS, modèle, architecture, ordre de marques ou entrée GREASE dans cette identité. Une API hints absente et des hints présents cohérents ont le même résultat canonique. Une contradiction connue produit/majeure devient un état incohérent qui ne peut conserver une validation positive.

Le serveur ne peut observer seul un changement de capacité JS entre deux déclarations. State/finish contrôlent fraîchement les informations HTTP disponibles ; la capacité JS est comparée à la prochaine déclaration schema2 authentifiée. Aucun contrôle ne doit promettre une observation serveur immédiate d'un objet navigator ou d'une API JS modifiés silencieusement. Une variation de capacité facultative ne retire aucune validation. Si fullscreen devient requis, le profil change et browser doit être évalué à nouveau ; tracking applique séparément sa couverture des gestes.

Transport retenu : l'enveloppe browser bornée est fournie au challenge ET au résultat. Le serveur canonise l'UA HTTP fraîche et cette enveloppe à émission, fixe les pouvoirs et la liaison contexte/cookie/doc/tentative/G/settingsrevision/fingerprint/incarnation/environnement ; il recanonise à consommation et exige l'égalité. Les variations ignorées par la canonisation restent admises. Un changement pertinent entre challenge et résultat place browser pending, refuse ce vieux nonce sans le réaffecter et demande une émission neuve. Le diagnostic privé conserve les données actuelles normalisées, dont une capacité false ou une incohérence produit, pas une enveloppe ou UA brute. Aucun chemin ne remplace l'environnement lié au nonce par celui du POST courant.

Pour préserver la séparation, cette invalidation browser est une transition explicite ; elle ne détruit pas la preuve tracking existante. Un résultat refusé pour liaison browser ne consomme pas un succès tracking partiel. Le refus et l'invalidation browser sont journalisés de manière privée et atomique ; un défaut SQL annule la transition, puis conserve seulement le constat indépendant de panne autorisé5a. Nonce émis avant nouvelle incarnation browser J n'est pas réutilisable pour J, même à même seconde/environnement identique ; un résultat simultané n'écrit qu'une seule validation consommée.

### Schema2 fermé et borné retenu

Les routes5a/5b restent celles de tracking ; aucun endpoint public browser donnant un motif ou une décision intermédiaire n'est créé. Toute émission schema2 porte schema_version:2, attempt_id entier exact, tracking_generation et room_epoch chaînes validées, _csrf ou header existant, et browser. Préflight résultat ajoute challenge et checks7 ; pulse résultat ajoute challenge et checks2. Pas de champ purpose, scopes, policyrevision, incarnation, timestamp client, HTTPUA, motif ou environnement digest choisi client.

Enveloppe unique browser :

```json
{
  "user_agent": "navigator.userAgent borné",
  "brands": [{"brand": "Google Chrome", "version": "151"}],
  "capabilities": {"event_target": true, "visibility_api": true, "focus_api": true, "fetch_api": true, "abort_controller": true, "monotonic_clock": true, "fullscreen": true}
}
```

- user_agent : chaîne UTF-8 de1 à1024octets, sans NUL ni contrôles. L'UA HTTP est bornée de la même manière à son entrée serveur ; une UA tronquée ne doit jamais devenir une validation positive par hasard.
- brands : null si indisponible, ou liste de0 à12 objets exacts brand/version. brand non vide ≤64octets, version non vide ≤24octets ; UTF-8 et absence de caractères de contrôle. Pas de mobile/platform/high-entropy hints. [] et null seuls ne sont pas des incohérences.
- capabilities : sept booléens stricts event_target, visibility_api, focus_api, fetch_api, abort_controller, monotonic_clock et fullscreen. Les six premières capacités sont requises, fullscreen conditionnelle. L'existence/API relève browser ; réception effective, sonde et gestes trusted restent tracking. Les 58 fixtures reçoivent les six capacités additionnelles true dans defaults sans changer leurs attentes ; aucune recette de ces nouvelles capacités n'est déclarée exécutée.
- Tous les objets, tailles et types sont contrôlés ; champs supplémentaires, tableau associatif en place de liste, faux booléens, valeurs de contexte et données au-delà de4096octets sont refusés par le corps générique existant. Un false valide demeure soumettable sans attendre une Entrée qui fonctionne.

La limite4096 est conservée. Compatibilité retenue : nouveaux scripts émettent schema2 uniformément, même browseroff ; ancien schema1 est accepté uniquement tant que browser est off, pour préserver les documents5a/5b déjà ouverts au déploiement. Dès activation browser, schema2 est obligatoire, même sous browser override ; aucun ancien document ne reçoit l'URL sur une interprétation partielle du schéma. Les anciens challenges schema1 sont terminés au changement pertinent de politique. Le pilote browser utilise des documents rechargés avec le nouveau script, sans changer le modèle d'epoch/cookie.

Précision approuvée par le lead sur la proposition capabilities : event_target contrôle l'existence de EventTarget, Event et de ses méthodes courantes addEventListener/removeEventListener/dispatchEvent, sans prétendre qu'un listener reçoit réellement un événement. visibility_api/focus_api/fetch_api/abort_controller/monotonic_clock décrivent uniquement l'existence des API nécessaires au fonctionnement. Fullscreen accepte les variantes standard et webkit réellement utilisées par l'application ; sa réception/son état requis et sa transition trusted demeurent tracking. Le gel doit lister les prédicats précis communs au code et à la recette, sans tester une famille UA comme preuve d'existence d'une API.

### Cohérence des Client Hints et doublons

Les 58 fixtures existantes restent l'oracle de parse/cohérence. Google Chrome compare seulement la famille Chrome/CriOS et sa majeure produit ; Microsoft Edge compare Edg/EdgA/EdgiOS et sa majeure produit. Chromium est une marque moteur : aucune égalité générale Chromium/produit Edge n'est imposée. Absence des hints, ordre/GREASE/marque inconnue, mineures différentes et plateforme réduite restent acceptés. [WICG UA Client Hints](https://wicg.github.io/ua-client-hints/), [Chrome Client Hints](https://developer.chrome.com/docs/privacy-security/user-agent-client-hints), [identifiants Edge](https://learn.microsoft.com/en-us/microsoft-edge/web-platform/user-agent-guidance).

Choix retenu : seules Google Chrome et Microsoft Edge sont des marques produit reconnues dans cette première version. Pas d'inférence Firefox/Safari depuis un nom arbitraire de hints ni d'exigence de hints pour ces familles. Une marque produit reconnue contredisant la famille ou la majeure produit déclarée est incohérente. Versions mineures complètes n'ont aucun rôle ; une chaîne de version reconnue doit débuter par une majeure numérique positive, suivant la forme produit déjà utilisée dans les fixtures.

Doublons retenus : mêmes marque produit/majeure sont dédupliqués ; deux majeures différentes pour une même marque produit produisent un diagnostic incohérent. Les répétitions de marques inconnues restent ignorées après validation du transport et de ses bornes. Un brand reconnu malformé ne devient pas une marque inconnue pour éviter le contrôle. Ne pas exiger que Chrome et Chromium aient une majeure identique sans oracle primaire supplémentaire ; les fixtures ne fondent aucune attestation cryptographique sur une marque Chromium isolée.

Décision chef+lead : aucune restriction alphanumérique sur brand ; la ponctuation GREASE est légitime. Validation UTF-8/longueur/absence de contrôles seulement, puis canonisation produit reconnue. Le nombre maximum d'objets et le plafond total4096 bornent aussi les marques arbitraires ; leur contenu n'est pas promu à une preuve ni à un motif d'incohérence.

### Migration, journal privé, pagination et archives

Proposition additive : browser_enabled=0 et tableau strict des quatre familles par défaut ; révision settings_revision partagée. Contextes5a/5b enrichis d'un état browser missing/pending/valid/failed/terminated, date, incarnation serveur et métadonnées canoniques bornées, sans browser_until. Aucun backfill de validation depuis un UA legacy, depuis un cookie frère ou depuis une archive. Les nouvelles colonnes nonce portent liaison de révision/environnement/incarnation et pouvoirs privés séparés. Les anciens pending sont missing ou terminés sans pouvoir browser implicite.

Un résultat browser consommé écrit un constat append-only privé : identité serveur du contexte, tentative/G et noms figés ; réception serveur ; informations canoniques minimales et capacités requises ; codes allowlist et provenance de chaque information. HTTPUA est server_observed ; UA JS/brands/capacités sont client_reported. Ni UA brute complète, POST, nonce, liaison cookie/epoch, session PHP, preuve hash, touche ou réponses Forms ne sont recopiés. Un contrôle browser off ne crée pas de causes ni de validation browser ; l'enveloppe uniformément reçue est écartée après contrôle de transport, sans trace browser gratuite.

Codes privés proposés en plus de ceux déjà présents : browser_verification_required et browser_environment_changed ; les motifs de politique existants sont conservés. Leur enum et leur provenance doivent être fixés, puis toutes vues/API privées autorisées utilisent une projection explicite. Aucun rawmessage SQL/POST n'est exposé. Public élève/board : permission finale et corps génériques seulement ; ni browser_enabled/policyrevision/canon, état brut, checks échoués, pouvoirs du nonce, diagnostics, scopes ou acteur.

Politique et état browser ciblés sont copiés avant reset/terminaison/rebinding, comme tracking5a. Les résultats append-only restent dans le journal privé global et son CSV complet ; pas de duplication de tous résultats dans chaque snapshot. Ancienne archive sans browser_policy/browser_validation reste inconnue, jamais complétée depuis le vivant ; un snapshot explicite off est connu off. Suppression de l'élève/tentative et transfert de propriétaire ne détruisent pas les traces ni les noms/acteurs figés. Pagination25/max50, curseur stable et export itératif complet sont conservés ; aucun quota100, aucune purge ou troncature silencieuse. Dépassement de borne de snapshot refuse tout reset, sans reset partiel ni archive incomplète. Les seuils précis restent ceux de3b/5a sauf décision motivée du lead.

### Scénarios indépendants à préparer au gel6

1. Parse pur :58 fixtures/11paires ; familles mobiles et priorités CriOS/FxiOS/EdgA/EdgiOS ; Safari Version contre moteur605 ; Firefox iPad déclaré Safari ; OPR/OPT/ancien Edge exclus ; CH absent/GREASE/ordre/inconnu admis ; contradiction produit connue et doublons. Ajouter seulement les cas de transport final figé.
2. Deux cookies A/B sur la même tentative ; document remplacé ; nouvelle génération même epoch ; aucun pouvoir/assessment A ne valide B. State/URL/finish avec HTTPUA modifiée, même mineure, puis famille/majeure ; browser seul est invalidé, tracking I/TTL inchangés.
3. Challenge sous environnement X, résultat sous Y ; nouvelle validation J puis replay vieux nonce X/J ; variante mineure/GREASE admise ; JS fullscreen change entre émission/consommation et après évaluation ; obligatoire contre facultatif. Refus sans succès tracking partiel.
4. Matrice des pouvoirs : trackinghealthy + browserpending + gestes7false conserve trackinghealthy ; trackingfailed + browservalid + pulse2true laisse trackingfailed ; trackingoverride + browserfailed bloque ; browseroverride + trackingfailed bloque ; deux scopes ne créent pas de santé ; manuel absolu.
5. Absence de borne browser artificielle, nonce119/120/121, TTL tracking59/60/61, expiry tracking pendant consommation après attente du verrou ; capacités fraîches pulse20s même diagnostic_only, browser seule en preflight, GET/challenge/heartbeat/journal ne renouvellent aucun TTL tracking. Limite documentée des capacités du dernier assessment en preflight+override. Challenge avant J ne gagne aucun pouvoir après J.
6. Course2workers une consommation/une ligne résultat ; rollback consommation/validation/permission/audit/policy/cycles ; diagnostic privé de panne séparé. Browser activation révision obsolète, trackingoff refusé sous browseractif et courses des deux mutations, sans fenêtre incohérente.
7. Snapshot avant reset, ancien inconnnu contre off explicite,53 contextes second lot, borne dépassée refus entier, >100 constats exportés ; droits anonymes/ownerB/absentctx/CSRF/GET ; transfert/suppression et ancien nom/acteur figés.
8. Public DOM/config/challenge/state/result/event/finish/board et erreurs : aucun marqueur privé, nonce powers ou URL avant permission ; API formateur/CSV seuls contiennent les détails ; données aucun Form/touche/high-entropy. Schema1 legacy/browseroff et schema2 strict/browseron selon décision gelée.
9. Fenêtre réelle seulement pour la compatibilité fonctionnelle Chrome/Edge disponibles, Firefox/Safari si réellement accessibles, capacités fullscreen conditionnelles, maintien de l'iframe/saisie/horloge et ancien guard sous exemption. Les fixtures ne prouvent ni le vrai navigateur de l'élève ni une origine certifiée ; falsification concordante peut passer.

### Décisions figées

| Sujet | Décision | Limite |
|---|---|---|
| Validité browser | Décision chef+lead : persistante doc/G/politique/HTTPcanon inchangés, sans TTL ni deuxième boucle | Capacités fraîches à chaque pulse ; dernière déclaration seulement en preflight+trackingoverride |
| Sélection tracking lors d'une évaluation browser | Décision retenue : diagnostic_only si tracking sain et browser à revalider ; full_preflight pour recontrôle volontaire quand tous sont valides | I expiry laisse le préflight indépendant établir browser sans créer de santé tracking |
| Capacités JS à émission | enveloppe browser dans challenge et résultat ; changement pertinent refuse vieux nonce/invalide browser seul | State/finish ne peuvent découvrir une mutation JS silencieuse avant une déclaration neuve ; prédicats7 à figer |
| Transport | Décision retenue : schema2 fermé user_agent1024,12brands64/24,7capbool,4096body | Capacités6requises+FSconditionnelle ; schema1 uniquement browseroff ; pas de negotiation révélant scopes/purpose |
| Marques connues/doublons | Google Chrome/Microsoft Edge produit, Chromium moteur sans égalité imposée ; doublon majeur contradictoire = incohérent | Autres marques ne doivent devenir connues que sur preuve primaire et ajout de fixture |
| Migrations/archives | colonnes additives off/missing ; inconnue si absente ; détails privés paginés/CSV complet | Enum privé, bornes existantes, pas de purge/backfill ni rétention destructive nouvelle |

Publication après gel, revue lead et recette indépendante. Aucun mode browser n'est activé automatiquement lors de la migration ou de la publication.

## Interfaces retenues

- POST `/quiz-admin/browser/policy` : `id`, `enabled` formulaire0/1 exact, `families` tableau non vide de valeurs exactes chrome/edge/firefox/safari, `settings_revision`, `reason` normalisé1..1000caractères selon4a et `_csrf`. Familles normalisées dans ordre canonique et sans doublons ; valeurs inconnues/tableau associatif refusés. Même politique déjà appliquée = no-op refusé sans audit/révision. Lecture de dépendance tracking et mutation/audit/pending dans le même verrou ; course avec trackingoff ne laisse aucun état browseron/trackingoff.
- `setBrowserPolicy(int sessionId,bool enabled,array families,int expectedSettingsRevision,string reason): void` proposé. Aucun endpoint client de cause testée ; les tests purs appellent un évaluateur depuis une fixture privée. Les paramètres1/2/7 browser appartiennent à l'enveloppe transport uniformément, pas au journal UID.
- Une fonction pure parse un UA en famille/majeure produit, une autre normalise/évalue l'enveloppe et retourne uniquement un diagnostic privé typé/canon ; un troisième assemblage associe canon et policyfingerprint. Les capacités ne sont pas déduites d'un UA. Évaluateur d'accès4b reçoit la cause browser et tracking séparément ; sa décision finale demeure booléenne.
- Colonnes proposées contextes : browser_status, browser_assessed_at, browser_incarnation, browser_policy_fingerprint, browser_canonical_json et browser_diagnostic_json bornés. Aucun browser_until. Noms/table des nonces réutilisés avec deux pouvoirs privés, empreinte de politique/environnement et incarnation browser. Les champs d'authentification restent exclus de toute projection privée exportable. Ajouter par migration additive, sans reconstruire les tables historiques ou effacer pending/diags hors contrat.
- Projection formateur proposée : contexte non secret/date/état/familles+majeures HTTP et JS, cohérence connue, capacités normalisées requises/observées, codes précis allowlist/provenance et politique appliquée. Une ligne append-only par résultat browser consommé ou environnement changé, datée serveur ; les diagnostics tracking continuent indépendamment. Un protocole refusé n'est pas un deuxième succès consommé. State/finish ne recopient pas une nouvelle ligne identique à chaque poll ; transition pertinente seulement.
- Browseroff→on exige assessment neuf, même si le fingerprint de familles/capacités est identique à une ancienne périodeon. Cycles launch/stop/close/reset, document remplacé et G terminent la validation browser et tous kinds de nonce selon5a/5b. Snapshot est pris avant terminaison/rebinding. Mutation browserseule conserve I tracking sain ; shared settingsrevision invalide tous pending communs, sans prolonger I.
- À émission nonce, policyfingerprint/context/doc/G/environnement canonique et pouvoirs sont fixés serveur. À consommation, relecture fraîche et absence de réaffectation. Le préflight browseronly conserve son pouvoir trackingdiagnostic quand I expire, mais un changement d'incarnation par nouveau préflightJ reste un fence. Le pulse garde les fences stricts5b de proofstatus/incarnation et ne répare jamais tracking. Toute différence pertinente de canon place browserpending et refuse résultat sans consume ni résultat/healthy/renew tracking partiel ; garde l'ancienne borne tracking et conserve un diagnostic privé actuel normalisé. SQL rollback annule ce domaine exactement, avec seul constat de panne indépendant/fallback5a après rollback.

Les méthodes challenge/préflight/pulse conservent les arguments5a/5b et ajoutent seulement `?array $browser = null` en dernier argument. `setBrowserRequestUserAgent(?string)` reçoit la valeur serveur courante ; les vues formateur et les observations retardées ne l'utilisent pas pour évaluer un étudiant. `getBrowserPolicy(int)` et `listBrowserDiagnostics(int, int beforeId=0, int limit=25)` exigent le propriétaire/superadmin et renvoient des projections privées, avec rows/next_before pour la pagination.

## Pannes des API et journal ordinaire

Chaque capacité est lue séparément ; un getter impossible produit false sans exposer son exception. AbortController est facultatif pour le transport : la Promise et son décodage JSON sont réellement bornés12s même sans abort, et une réponse tardive ne peut plus appliquer une autorisation ni accuser un UID. Fetch absent ou impossible empêche la transmission ; aucun résultat sain n'est fabriqué et les gardes serveur/locales maintiennent le refus générique.

Une horloge monotone absente, non finie ou qui lève une exception suspend localement l'accès renforcé au prochain callback/guard500ms, y compris sous dérogation. Aucun remplacement par l'heure murale ni prolongation de permission. Le diagnostic monotonic_clock=false peut toujours être soumis si fetch fonctionne. Les getters de focus/visibilité/fullscreen inconnus ne deviennent pas des départs ou retours ordinaires. Un départ sans horloge mesurable n'ouvre pas de source timée. Un vrai retour devenu non mesurable conserve son départ brut, signale une seule perte via le diagnostic de journal existant et ne crée aucun duration_ms ; au prochain vrai retour mesurable, la source est réarmée sans mesurer rétroactivement cet épisode. La file UID et ses reprises sont conservées.

## Schéma additif et conservation

- `quiz_sessions` : browser_enabled INTEGER DEFAULT0 et browser_families_json TEXT avec les quatre familles par défaut.
- `quiz_tracking_contexts` : browser_status DEFAULT missing ; browser_assessed_at, browser_incarnation, browser_policy_fingerprint, browser_canonical_json, browser_diagnostic_json nullables.
- `quiz_tracking_challenges` : tracking_power nullable, browser_power DEFAULT none, browser_protocol DEFAULT1 ; browser_policy_fingerprint/browser_environment_fingerprint/browser_incarnation nullables. Liaisons privées, jamais exportées.
- `quiz_browser_diagnostics` indépendante sans FK destructrice : identités serveur et noms figés bornés, contexte non secret, génération, réception serveur, code/opération allowlist, details_json≤4096octets, identity_truncated. Index session_id/id. Aucun UA brut, cookie, epoch, challenge, CSRF, fingerprint ou réponse Forms dans les diagnostics/exports.

Format d'archive1 conservé : browser_policy et browser_validation optionnels additifs, absence ancienne = contexte non conservé. Snapshot ciblé à256contextes/tentative, limites3b inchangées et refus total sans troncature. Constats browser globaux conservés hors snapshot et CSV complet itératif ; liste25/max50. Sauvegarde cohérente avant migration. Retour ancien code : geler politiques/reset/arbitrages et corriger en avant, sans restaurer destructivement la base.

## Vérifications développeur et manifeste

`QuizBrowserEvaluatorTest.php` couvre58fixtures/11paires, produits incompatibles/doubles majeures/Safari Version, capacités conditionnelles, transport fermé et contrôles UnicodeC1 ; GREASE ponctué reste admis. `QuizBrowserPolicyTest.php` couvre pouvoirs indépendants, HTTP frais/UA formateur/anciennes queues, TTL/incarnations, scopes/manual, rollback, droits, archives et CSV>100. `QuizBrowserJsTest.js` couvre schema2/enveloppes fraîches/pulses, variantes fullscreen, getters/API cassés, timeout12s sans Abort, réponse tardive, horloge perdue/iframe et journal non mesurable. Les régressions5a/5b et des lots précédents restent requises. Les gestes trusted en VM sont des mocks, jamais une bascule native attestée.

Fichiers applicatifs modifiés : app/Config/i18n.php ; app/Controllers/QuizAdminController.php ; app/Controllers/QuizController.php ; app/Services/QuizDbService.php ; app/Services/QuizService.php ; app/Services/QuizTracking.php ; app/Views/quiz/admin/history.php ; app/Views/quiz/admin/session.php ; app/Views/quiz/admin/tracking-details.php ; public/assets/js/quiz-preflight.js ; public/assets/js/quiz-monitor.js ; public/assets/js/quiz-journal.js. Ajouts applicatifs : app/Services/QuizBrowser.php et app/Services/QuizBrowserEvaluator.php. Documentation : cette spec, README.md, SPEC_MODE_QUIZ.md. Tests : les trois tests navigateur et leur fixture, plus le hook optionnel de fixture VM5a. Configuration/base distante hors manifeste ; aucun fichier de données ou secret publié.
