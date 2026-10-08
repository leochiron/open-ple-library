# Lot 5b — Renouvellement continu du suivi

Spécification d'exécution du 8 octobre 2026, **développement 5b autorisé** par le chef et contrat approuvé par le lead. Fondée sur 5a publié au commit `287c0a9`, branche `agent/esgi-quiz-tracking-continuous`. Publication après gel/revue/QA indépendante.

Les routes `/quiz/api/tracking/pulse/challenge` et `/quiz/api/tracking/pulse` sont retenues. La soumission porte `schema_version: 1` et exactement les deux booléens `listener_roundtrip` et `fullscreen_active`, outre les champs de liaison et CSRF hérités. Les incarnations de preuve déjà présentes en 5a doivent être réutilisées. Un nonce de préflight et un nonce de pulse ne sont jamais interchangeables.

## Résultat et périmètre

5b ajoute un mode explicite `continuous` aux modes `off` et `preflight`. Les sessions existantes et nouvelles conservent leur mode ; aucune conversion automatique en continuous. La route administrative dédiée5a, ses droits, révision attendue, motif, audit et invalidation atomique restent l'unique mutateur du mode. Le formulaire général des paramètres ne choisit pas le mode.

L'admission saine commence par le préflight complet5a. En continuous, un pulse ponctuel réalise une **sonde EventTarget neuve via les méthodes courantes et le type keydown** et observe l'état fullscreen requis. Un événement custom non bloqué par le script fourni ne constitue pas cette sonde. Les gestes Entrée, sortie d'onglet/retour et transition fullscreen ne sont pas redemandés toutes les20s. La cadence20s est validée ; elle laisse une marge avant la preuve60s, sans garantir cette marge en cas de réseau suspendu.

Un pulse sain ne renouvelle qu'une preuve saine **encore valide au moment serveur de la transition**, liée au bon cookie/document/tentative/génération/incarnation. À `now >= proof_until`, seul un préflight complet neuf peut établir une nouvelle preuve saine. Un pulse reçu tardivement, un simple challenge, GETstate, heartbeat ou événement de journal ne renouvelle rien.

La permission finale demeure distincte de la preuve. Un tracking override peut autoriser une permission bornée malgré preuve absente/failed/expired ; il ne crée ni ne prolonge une preuve saine. Manuel absolu, browser non exempté et diagnostics privés restent applicables.

## Héritage5a à préserver

- Cookie résolu serveur ; document `room_epoch` attendu, hors UID/metadata/sessionStorage3a ; deux cookies indépendants sur le même code.
- HTML initial renforcé sans URL Forms, même avec preuve ancienne ou dérogation ; ouverture HTML remplace le document et termine ses anciennes preuves/challenges.
- Epoch stable au changement de mode pour le document courant ; `settings_revision` publique monotone contre les réponses hors ordre. Un document déjà remplacé ne se resynchronise jamais.
- Lobby→launch, relaunch et reset conservent tentative/document courant et epoch, changent G et exigent preuve fraîche ; state apprend G neuf sans409 permanent/reload. Ancien challenge/résultat/finish G est refusé. Reset copie avant terminaison/rebinding et applique son UPDATE historique des compteurs/status/finished/événements, sans supprimer/recréer la tentative.
- Stop invalide proof/challenges/permission mais le document courant peut revalider à état compatible. Close ne ressuscite pas une preuve après openLobby.401 seulement après suppression réelle du sujet/tentative.
- Préparation exclusivement API diagnostic, journal/reclassification3a inchangés. Anciennes files/retours/no-start ordinaires restent comptables ; leur ACK n'autorise pas l'ancien document ni URL/epoch neuve.
- Source diagnostique encore sortie à timeout/admission reste désarmée jusqu'au vrai retour ; la sonde ne synthétise ni départ ni retour. Ancien épisode ordinaire se ferme uniquement au vrai retour.
- Préflight indépendant de finished ; aucune reprise, pause individuelle, remise à zéro d'horloge ou collecte de réponses par pulse.
- Tout refus public générique : « Accès indisponible. Contactez le formateur. » et corps protocole générique de5a. Pas de cause, scope, checks échoués, acteur, provenance ni preuve saine/échouée dans DOM/config/JSON/console/board.

## Incarnations et nonce pulse

L'incarnation documentaire et l'incarnation de preuve sont distinctes. Proposition minimale :

1. Préflight réussi crée une incarnation saine I choisie serveur.
2. Pulse sain renouvelle la borne de I ; il ne recrée pas le document ni la tentative et ne refait pas les gestes initiaux. Le nonce consommé n'est jamais réutilisé.
3. Failure et expiry terminent I et les pending pulse liés à I. Remplacement du document, changement de génération/politique applicable ou cycle terminent tous les kinds de pending.
4. Préflight ultérieur crée J distinct de I. Aucun nonce attaché à I ne peut renouveler J, même si cookie/epoch/tentative sont identiques et la réparation survient à la même seconde.

Un nonce pulse aléatoire32octets est stocké seulement par hash. Le serveur fixe son purpose privé à émission : renewing si I est saine et valide, sinon diagnostic-only. Il lie définitivement le nonce à cookie/document/context, tentative/G, état/incarnation à émission, settings_revision et exigences couvertes. Pour renouveler, I saine exacte est requise. Aucune incarnation, purpose, cause ou identité autorisant n'est choisie dans le POST élève. TTL nonce maximum120s ; usage unique ; un pending au plus selon le mécanisme5a partagé. Un nonce diagnostic émis avant préflight J est refusé pour J, sans réaffectation ni conversion.

La limite effective n'est jamais120s de droit au renouvellement : il faut aussi `now < proof_until`. Un nonce encore avant sa propre borne120 ne peut sauver une preuve expirée à60. Ne pas confondre TTL du nonce, TTL de la preuve et borne de permission publique.

Créer un nouveau nonce termine le pending précédent, sans changer proof_issued/proof_until ni permission. Décision validée : boucle single-flight en pause pendant toute préparation complète, hors session running et lorsque la tentative est finished. Le pending client couvre challenge, collecte ET soumission jusqu'au règlement de la requête : le seul preparing() de 5a devient false trop tôt après passage au stage submitted et ne suffit pas. La boucle est aussi en pause pendant completionPending ; tous résultats emploient l'ordre d'émission commun. Polling/guard/préflight manuel restent disponibles, notamment finished.

Finished et état running sont relus sous verrou à l'émission et à la consommation d'un pulse. Un nonce émis avant finish puis livré après ne renouvelle aucune preuve. L'arrêt de la boucle JS ne remplace pas cette garde serveur ; le préflight complet reste permis pour préparer une reprise explicite.

## Protocole retenu

| Route | Proposition |
|---|---|
| POST `/quiz/api/tracking/pulse/challenge` | CSRF, contexte cookie et document courants, tentative/G/mode/révision/exigences frais. Réponse protocole bornée `challenge`/`challenge_until`, aucune preuve ou cause privée. |
| POST `/quiz/api/tracking/pulse` | CSRF et mêmes liaisons, nonce et version de schéma fermé. Deux booléens stricts proposés : `listener_roundtrip`, `fullscreen_active`. Réponse permission finale commune5a. |
| GET state, heartbeat, journal | Contrats5a conservés ; aucune création ou prolongation de preuve par ces seules opérations. |
| POST tracking/mode | Autorise continuous seulement après validation5b ; route dédiée et audit5a, refus no-op/révision périmée ; pas d'activation silencieuse. |

Réutiliser le plafond JSON4096octets et les réponses400/403/409/413/503 génériques5a. Null/entier/chaîne, champs manquants ou supplémentaires et valeurs de contexte choisies client sont refusés selon schéma strict ; un booléen false valide reste soumettable.

Le booléen listener_roundtrip provient d'un EventTarget neuf, add/dispatch/remove courants sur keydown, jamais d'un listener ou d'une méthode native sauvegardés. Ce dispatch contrôlé n'est pas un geste trusted ; le pulse ne valide pas une Entrée native antérieure et n'enregistre aucune valeur de touche. Fullscreen_active est l'état actuel ; false ne bloque que si fullscreen est requis. Aucune absence ordinaire d'événements utilisateur ne constitue un échec.

## Transaction de consommation et renouvellement

Même PDO et verrou session que5a/4a/4b, pas de transaction imbriquée ni réseau/sleep sous verrou :

1. Premier write neutre, relecture cookie/document/tentative/G/mode/règles, incarnation I et horloge serveur après acquisition du verrou.
2. Expirer d'abord la preuve à la borne60 et terminer ses pending si nécessaire. Ne pas faire consommation/renouvellement avant cette décision.
3. Valider pending pulse, secret hash, usage, borne120, révision et état/incarnation liés à émission. Pour purpose renewing, incarnation saine exacte nonexpirée obligatoire ; un nonce diagnostic-only ne renouvelle jamais. Lecture fraîche manuel/overrides/causes non exemptées ; leurs scopes ne modifient jamais la preuve brute.
4. Consommer le nonce et ajouter exactement une ligne de résultat append-only, deux booléens normalisés/date serveur/provenance client_reported bornés, dans la même transaction. Ne pas recopier POST/poll/payload. Un refus de protocole conserve le contrat privé5a séparé sans devenir un deuxième résultat consommé.
5. Si purpose renewing, I healthy nonexpirée et checks requis bons : renouveler sa borne à now+60 au maximum, conserver la couverture des gestes initiaux et une date de réception pulse séparée. Si check requis failed : terminer la santé de I et ses pending pulse, conserver constat/causes et aucune ancienne borne healthy. Purpose diagnostic-only conserve le constat mais n'établit ni ne rétablit aucune santé/TTL, même avec deux booléens true.
6. Construire permission finale depuis causes fraîches et scopes4b, commit atomique. Échec SQL annule consommation, constat, proof, permission et modifications liées ; panne privée séparée/fallback après rollback selon5a.
7. Réponse perdue : GETstate retrouve seulement la permission déjà validée et sa borne originale ; replay ne prolonge rien. Une nouvelle émission nonce ne vaut pas renouvellement.

Après liaison cookie/document/tentative/génération valide, une expiration constatée sous verrou est durable même si le nonce est ensuite refusé pour une raison de protocole attendue : commit ciblé de la transition expired, des pending pulse terminés et du constat serveur, sans consommation, résultat ni renouvellement. Ce chemin est explicite ; aucun commit depuis un catch général ou une transaction non possédée. Toute panne SQL ou exception inattendue annule intégralement cette transaction et emploie ensuite le journal privé de panne séparé. Une liaison invalide n'autorise aucune mutation de santé.

L'expiration ou l'échec de I termine les pending pulse liés à I. Un challenge de préflight complet indépendant déjà émis pour établir J reste valide jusqu'à sa propre borne : préparation à t50, expiration de I à t60 et soumission complète à t95. Document, génération ou politique invalide tous les kinds ; une nouvelle émission supersède tous les pending antérieurs.

Un résultat failed reçu avant l'expiration retire l'ancienne preuve saine, même sous tracking override. Après réparation du listener, un pulse true ne remonte pas failed/expired en healthy : préflight entier neuf pour J. Ajouter obligationFS non couverte impose préflight neuf ; retirerFS après failure ne fabrique pas de santé ou TTL. Changement de titre/quota/seuil/durée/URL/reload laisse le TTL de la preuve établie mais invalide les pending via settings_revision, conformément5a.

Concurrent et erreur : un seul nonce consommé une fois ; double request ne crée pas deux renewals/diagnostics de résultat. Un nonce I délivré avant failure/expiry/recheck est refusé pour J. Challenge preflight et pulse ne sont pas interchangeables ; aucun résultat pulse ne satisfait les gestes d'un préflight.

## Dérogations et diagnostics sans preuve saine

Les probes continuent sous override, leurs failures et provenance restent privées. Aucun libellé d'exception ni type de cause n'est envoyé côté élève. La boucle ne peut attendre un keydown/plein écran fonctionnel pour envoyer false, consulter state ou bénéficier d'une levée technique.

Décision chef+lead : après expiration/failure ou absence de preuve, la même surface publique uniforme émet un nonce purpose privé diagnostic-only, lié au document courant et à l'état/incarnation du moment. Il consomme une observation de pulse sans aucun pouvoir de renouvellement ; le serveur choisit ce purpose, jamais le client. La réponse ne révèle ni purpose, incarnation ni override. Aucun vieux nonce ne change de purpose ; tout nonce diagnostic émis avant préflight J est refusé pour J. Sous exemption, permission finale peut être vraie sans santé fabriquée, tandis que les constats continuent.

State peut projeter une permission60s sous tracking override sans changer proof_until/état/incarnation. Révocation bloque immédiatement dans garde serveur si cause restante ; le client découvre le refus par réponse/polling/borne locale, sans notification push prétendue. Browser-only n'exempte pas tracking ; futurebrowser6 reste nécessaire si tracking seul est exempté. Manualblock interdit URL/finish/resume/ACK positif même avec deux scopes.

## Stockage et history, ajout minimal

Réutiliser contextes/challenges/diagnostics5a, avec colonnes additives uniquement si le schéma livré n'a pas encore :

- kind/purpose interne preflight/pulse, liaison proof_incarnation du nonce, pending/consumed/terminated et révision/exigences ;
- borne/dernière réception de pulse et référence d'incarnation saine, séparées de la couverture et date du préflight complet ;
- diagnostic borné typé de pulse, booléens normalisés, date serveur et provenance client_reported ; transitions expiry/termination server_observed.

Pas de FK destructif vers élève/tentative/acteur, cookie ou IDPHP brut, empreinte de liaison/epoch/nonce secret dans diagnostics, archives, audit ou CSV. Pas de payload arbitraire, touche, réponseForms, UAraw ou horloge client réputée exacte. Réutiliser les codes stricts5a lorsque applicables ; nouveau code d'incarnation mismatch ou opération pulse seulement si nécessaire et allowlist explicite.

Snapshots reset copient les contextes ciblés et la policy mode continuous avant terminaison/rebinding, avec dernier pulse, checks, provenance et borne privée pertinentes, sans secrets. Le modèle 5a est conservé : les résultats append-only de tous pulses restent dans le journal privé global immuable et son CSV complet ; on ne duplique pas tous diagnostics dans chaque snapshot. Les archives5a absentes pour métadonnées pulse restent inconnues, jamais complétées depuis le vivant. Pagination25/max50 et exports itératifs complets ; borne3b dépassée refuse tout reset plutôt que perdre ou tronquer des constats.

Volume validé : chaque résultat consommé, sain ou diagnostique, reçoit sa ligne append-only bornée (deux booléens/date/provenance). Toutes20s cela représente trois constats/minute/contexte ; pages et exports complets, bornes reset inchangées avec refus total au dépassement. Ni purge silencieuse, ni agrégation remplaçant les constats.

## Client, guard et journal

Boucle single-flight : pas deux challenge/submission volontaires simultanés pour le même document. Une pause, erreur réseau ou onglet endormi n'entraîne aucun renouvellement rétroactif. À preuve/permisssion expirée, masque générique et recontrôle complet ; iframe/saisie inchangées. Nouvelle préflight ou génération arrête les callbacks anciens avant reprise.

Guard local5a conservé : server_now + durée réseau conservative + horloge monotone, sans arrondi gagnant à60. Le délai restant retire également 1000 ms pour la quantification de server_now entier et 500 ms pour la cadence du guard. Cette marge peut suspendre le client jusqu'à 1,5 s plus tôt ; le serveur garde sa borne stricte de 60 s et un client endormi se masque au prochain traitement JS. settings_revision/epoch/G/séquence reçus empêchent un résultat de renouvellement ancien de masquer un refus connu plus frais. Aucun proof_until ni healthy privé sérialisé dans ce guard ; seuls access_allowed/access_until et protocole public minimal.

Journal normal indépendant, même sous exemption, suspension ou sonde : aucun événement synthétique de pulse converti en incident, aucune fermeture artificielle d'absence. Les vrais départs/retours ordinaires et leurs UID/durées restent3a. Le pulse ne prouve pas rétroactivement la réception de tous événements natifs ; les observations et gestes booléens restent déclaratifs et falsifiables.

Finished : renouvellement automatique arrêté. Polling/guard et possibilité de préflight complet avant resume restent actifs ; aucune tentative rouverte par pulse ou preuve seule. À resume confirmé, la boucle reprend uniquement si session running, mode continuous et aucune préparation en cours. Détachement définitif : arrêt de l'intervalle et des nouvelles préparations, callbacks en vol invalidés, panneau préparatoire masqué ; un poll d'erreur d'identité ne provoque pas de boucle de challenges rejetés.

## Recette et publication

Voir `work/qa-tracking-continuous-matrix.md` : TTL et bornes exactes, deux cookies/deux documents, preuve I→J, défauts/overrides, nonce/usage/races/rollback, privacy, cycle stable du document, guard/réponses hors ordre et gestes manuels réels.

Publication : sauvegarde cohérente avant migration additive, tests de développement, gel/revue/QA indépendante, puis publication avec mode inchangé. Recette fictive et manuelle du vrai changement d'onglet/Alt+Tab/fullscreen/script avant généralisation ; aucun exemplaire de Firefox/Safari ou du navigateur personnalisé déclaré testé sans disponibilité réelle. Pas de retour métier transparent vers 5a en mode continuous : geler les mutations et corriger en avant, sans restauration destructive de la base.

## Schéma livré et signatures

`createTrackingPulseChallenge(int sessionId, int attemptId, string generation, string roomEpoch): array` et `submitTrackingPulse(int sessionId, int attemptId, string generation, string roomEpoch, string challenge, array checks): array` utilisent la même PDO et des transactions possédées par le service. Horloge serveur unique prise après verrou ; fixture uniquement par surcharge protégée de `now()`, sans réglage client ou endpoint de test.

Ajouts de colonnes idempotents :

- `quiz_tracking_challenges` : `kind`/`purpose` TEXT défaut preflight, `proof_incarnation`/`proof_status_at_issue` TEXT nullables, `fullscreen_required` INTEGER défaut 0.
- `quiz_tracking_contexts` : `last_pulse_at`, `last_pulse_checks_json`, `last_pulse_outcome`, `pulse_failure_checks_json` TEXT nullables ; `last_pulse_until`, `last_pulse_fullscreen_required`, `pulse_failure_fullscreen_required` INTEGER nullables.

La dernière observation et le constat qui a invalidé I sont distincts : un diagnostic-only true ne répare pas la santé ni sa cause historique. Le préflight J remet toutes ces métadonnées ciblées à null ; les résultats append-only de I restent conservés globalement. Projection privée allowlist et snapshots incluent les checks normalisés/provenance ; jamais incarnation privée ni empreinte de liaison. Un vieux snapshot sans clé pulse reste inconnu ; un snapshot nouveau avec null signifie aucun pulse conservé pour ce contexte.

Les résultats consommés utilisent `pulse_healthy`, `pulse_failed` ou `pulse_diagnostic_only`, opération `pulse`, provenance `client_reported`. Les refus utilisent les codes stricts 5a, plus `challenge_kind_mismatch` et `proof_incarnation_mismatch`, opérations `pulse_challenge`/`pulse`, provenance `server_observed`. Une panne ne produit aucun succès partiel ; le journal PHP privé de secours conserve uniquement code/opération/date serveur/classe SQL/fichier/ligne, sans POST ni secret.

## Manifeste applicatif et vérifications

Fichiers applicatifs modifiés, aucun nouvel asset :

- `app/Config/i18n.php`
- `app/Controllers/QuizController.php`
- `app/Services/QuizDbService.php`
- `app/Services/QuizService.php`
- `app/Services/QuizTracking.php`
- `app/Views/quiz/admin/session.php`
- `app/Views/quiz/admin/tracking-details.php`
- `app/Views/quiz/room.php`
- `public/assets/js/quiz-monitor.js`
- `public/assets/js/quiz-preflight.js`

Tests de développement : `tests/QuizTrackingContinuousTest.php` et `tests/QuizTrackingContinuousJsTest.js`. Le harnais préflight existant est partagé sans changer la recette native ; son oracle de garde tient compte de la marge de quantification/cadence. La VM couvre cadence 20 s indépendante du polling, sonde fraîche après modification des méthodes, single-flight jusqu'au règlement HTTP, pause fin/reprise, réponse pulse périmée, garde fraction .9/RTT20, génération en vol, détachement durable, formulaire/saisie conservés et absence de valeurs de formulaire dans le transport. Le PHP couvre incarnations/purpose, refus kind/schema/replay, t59/t60/TTL120, préparation t50→95, gardes finished, FS, rollback consommation/fence/diagnostic, deux cookies, cycles, snapshots, CSV et confidentialité.

Ces tests simulent l'état des listeners et le temps ; ils ne constituent pas une preuve de gestes natifs ni une attestation de navigateur. La revue lead, la QA HTTP indépendante et les recettes Chromium locales sont des validations distinctes avant publication. Les gestes natifs de visibilité/fullscreen restent à vérifier manuellement avec Léo.
