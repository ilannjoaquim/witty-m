# Witty — assistant IA pour Mautic 7.1

Plugin Mautic ajoutant une interface de chat capable de piloter Mautic : segments, emails,
landing pages, formulaires, campagnes, points, tags. Réponses en streaming, conversations
persistées, journal d'audit des actions et quota de tokens par utilisateur.

Il livre aussi deux thèmes d'email (**Webinar Last**, **Webinar Day 0** — sélectionnables à la
création d'un email) et une bibliothèque de templates que l'agent sait remplir bloc par bloc.

Compatible **Mautic 7.1** (Symfony 7, PHP 8.2+). Fournisseurs supportés : **Anthropic (Claude)**,
**OpenAI (GPT)**, **Google (Gemini)**.

---

## Installation

```bash
cp -r WittyBundle /chemin/vers/mautic/plugins/
cd /chemin/vers/mautic
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Le `mautic:plugins:reload` (ou le bouton **Installer/Mettre à jour les plugins** dans
**Paramètres › Plugins**) est obligatoire : c'est lui qui crée la ligne du plugin en base.
La ligne de l'intégration, elle, est créée à la première ouverture de **Paramètres › Plugins**
(`IntegrationHelper::getIntegrationObjects()` scanne et persiste les intégrations inconnues).

Puis **Paramètres › Plugins › Witty** :

| Onglet | Contenu |
|---|---|
| Details | activation du plugin + une clé API par fournisseur (au moins une) + clé/secret plugNmeet (facultatif) + clé Bright Data (facultatif) |
| Fonctionnalités | modèle par défaut par fournisseur (facultatif), itérations max, confirmation avant écriture, streaming, quota de tokens, URL du serveur plugNmeet, mode Pro Bright Data |

Enregistrer avec **Sauvegarder et fermer**. Le chat est ensuite accessible dans le menu
principal (`/s/witty`), et le journal des actions dans le menu d'administration (`/s/witty/audit`).
La section **Videoconference** (`/s/witty/video/*`) n'apparaît que si l'URL, la clé et le secret
plugNmeet sont tous les trois renseignés — voir [Videoconference (plugNmeet)](#videoconference-plugnmeet).

Les clés API sont stockées dans `plugin_integration_settings.api_keys`, chiffrées par Mautic
(`IntegrationsHelper::saveIntegrationConfiguration`). Rien n'est écrit dans `local.php`.

### Fournisseur et modèle : un réglage par tour, pas par instance

La configuration n'impose plus un fournisseur unique pour tout le plugin. **Chaque clé API
renseignée rend son fournisseur disponible** dans un sélecteur en haut du chat ; le modèle se
choisit à côté, dans une liste déroulante **peuplée en direct depuis l'API du fournisseur**
(`GET /v1/models` chez Anthropic et OpenAI, `GET /v1beta/models` chez Google) plutôt que codée en
dur — les catalogues changent trop souvent pour ça. `ModelCatalog` (`Service/Llm/ModelCatalog.php`)
appelle `LlmProviderInterface::listModels()`, met le résultat en cache une heure
(`Symfony\Component\Cache\Adapter\FilesystemAdapter`, sous `PathsHelper::getCachePath()`, une entrée
par fournisseur + clé API) et **replie systématiquement sur le seul modèle configuré par défaut**
si l'appel échoue (clé invalide, quota, réseau) — le menu déroulant du chat n'est donc jamais vide.
Le contrôleur expose ça via `GET /witty/models/{provider}` (`WittyController::modelsAction`),
interrogé en Ajax à chaque changement de fournisseur. Le choix se fait à chaque tour, sans repasser
par la fiche du plugin :

- **une seule clé renseignée** → le fournisseur est fixe, seul le modèle se change ;
- **plusieurs clés renseignées** → fournisseur et modèle se changent tous les deux, à tout moment,
  y compris en cours de conversation (l'historique est un format interne agnostique du
  fournisseur, voir plus haut) ;
- le choix est mémorisé dans `localStorage` du navigateur, et resynchronisé sur ce qu'une
  conversation existante a utilisé en dernier quand on la rouvre — sans jamais empêcher de le
  changer à nouveau ensuite.

Le champ « modèle » en configuration (onglet Fonctionnalités) n'est donc plus qu'une **valeur de
repli** par fournisseur, utilisée si le chat n'en précise pas — pas un réglage à maintenir au
quotidien.

Le formulaire refuse l'enregistrement si **aucune** clé n'est renseignée (contrainte de formulaire
sur `AuthType`, message porté par le domaine de traduction `messages` — le validateur Symfony
traduit par défaut dans le domaine `validators`, une erreur facile à manquer puisqu'elle se
traduit par une clé de traduction brute affichée telle quelle plutôt qu'une erreur PHP).

Si le chat demande un fournisseur dont la clé a été retirée depuis (`AgentRunner::run()`), l'appel
échoue explicitement plutôt que de tenter une requête HTTP avec une clé vide.

### Videoconference (plugNmeet)

Section indépendante du chat (menu principal, sous-items **Salles** / **Salles passées** /
**Enregistrements**), qui pilote une instance [plugNmeet](https://www.plugnmeet.org) depuis Mautic.
Nécessite l'URL du serveur, une clé API et un secret (onglets Details/Fonctionnalités de la fiche
du plugin) ; sans les trois, chaque page affiche un message « non configuré » au lieu du contenu —
voir `WittyConfig::isPlugNmeetConfigured()`.

- **`Service/PlugNmeet/PlugNmeetClient.php`** — portage PHP du client Node.js de référence
  (`plugnmeet-client-main/src/PlugNMeetClient.js`, fourni par l'utilisateur comme spec). Chaque
  appel signe le corps JSON en HMAC-SHA256 avec le secret (jamais transmis au navigateur, ni même
  au frontend Mautic — tout passe par `VideoconferenceController` côté serveur). L'API plugNmeet
  mélange deux formats de date selon l'endpoint (secondes Unix pour les salles actives, chaînes
  ISO 8601 pour les salles passées) : les vues gèrent les deux plutôt que d'en supposer un seul.
- **`VideoconferenceController`** — une action de page par sous-section (rend un `@Witty/Videoconference/*.html.twig`,
  même schéma que `Chat/index.html.twig` : Twig pour la coquille Mautic, JS vanilla + `fetch()`
  pour l'interactivity) et des actions AJAX dédiées (liste, création, fin de salle, liens
  auditeur/présentateur, suppression, téléchargement).
- **Salles → Asset** — `Service/PlugNmeet/RecordingToAssetConverter.php` télécharge un
  enregistrement plugNmeet en flux (jamais chargé entièrement en mémoire : ça peut peser plusieurs
  Go) et le republie comme Asset Mautic local, pour pouvoir le joindre à un email — ce que l'URL
  de téléchargement plugNmeet (à jeton, courte durée de vie) ne permet pas telle quelle. Partagé
  entre `VideoconferenceController` (bouton de l'interface) et l'outil agent
  `convert_meet_recording_to_asset`. Piège rencontré : `Asset::upload()`
  nettoie un dossier temporaire dérivé de `tempId` sans vérifier qu'il est défini ; `tempId`
  n'existe normalement que via le flux d'upload chunk-par-chunk du navigateur, absent ici puisque
  le fichier est construit directement côté serveur (`UploadedFile` en mode test) — il faut donc
  l'initialiser explicitement (`setTempId(uniqid(...))`) avant `preUpload()`/`upload()`, sans quoi
  `Filesystem::remove(null)` lève une `TypeError`.
- **Repli sur objets vides** — PHP encode un tableau vide en JSON `[]`, mais plugNmeet attend un
  objet `{}` pour `metadata.extra_data` (erreur serveur sinon : `proto: syntax error ... unexpected
  token [`) ; `PlugNmeetClient::createRoom()` le force en `stdClass` avant l'envoi.

#### Invitations personnalisées et suivi de présence

Objectif : qu'un email/landing page affiche un lien de connexion personnel par contact
(`{contactfield=meet_invitation_link}`), et qu'on sache ensuite qui a réellement assisté à la
réunion — pour conditionner points, segments ou campagnes sur « a participé » plutôt que « a
seulement été invité ».

**Le piège de conception** : un jeton `getJoinToken` plugNmeet est à usage unique et de courte
durée de vie — le générer à l'inscription pour un email envoyé des jours plus tard donnerait un
lien mort. La solution (calquée sur `dashboard/server.js` du dépôt de référence, qui fait déjà
exactement ça avec un TTL de 30 jours) : le champ contact ne stocke **jamais** le vrai jeton
plugNmeet, seulement une URL Mautic stable qui le génère à la volée à chaque clic.

- **`meet_invitation_link`** — champ contact texte provisionné automatiquement par
  `PluginSubscriber` (`PluginEvents::ON_PLUGIN_INSTALL`/`ON_PLUGIN_UPDATE`) via
  `FieldModel::saveEntity()`, sans action manuelle dans Settings > Custom Fields. Piège : un
  `LeadField` neuf a `charLengthLimit = 64` par défaut (colonne `VARCHAR(64)`), trop court pour une
  URL complète — il faut l'augmenter explicitement (`setCharLengthLimit(500)`).
- **`Service/PlugNmeet/InvitationLinkSigner.php`** — JWT-maison auto-porteur
  (`{lead_id, room_id, exp}`, HMAC-SHA256 avec `secret_key` de Mautic, 30 jours par défaut), sans
  tour en base pour le vérifier. C'est cette URL (`/meet/join/{token}`) qui va dans
  `meet_invitation_link`, jamais le jeton plugNmeet réel.
- **`Entity/WittyMeetInvitation.php`** (`witty_meet_invitations`) — trace chaque invitation
  générée (contact, salle, horodatage de clic, statut de présence). Nécessaire en plus du champ
  contact : celui-ci n'garde que le *dernier* lien généré, alors qu'un contact peut être invité à
  plusieurs webinaires au fil du temps — cette table est la source de vérité pour « qui a été
  invité à quelle salle ».
- **`Service/PlugNmeet/MeetInvitationCreator.php`** — logique partagée de « Créer un lien
  invitation meet » (revérifie que la salle est active *au moment de l'exécution*, pas seulement
  à la conception, génère le lien signé, pose le champ contact, logue l'invitation), appelée par
  les deux points de déclenchement ci-dessous.
- **`EventListener/CampaignSubscriber.php`** — l'action côté **campagne** (canvas). Suit le
  patron `eventName` + `CampaignEvents::CAMPAIGN_ON_BUILD` encore utilisé par plusieurs bundles
  cœur de Mautic (ex. `lead.changetags`), malgré la dépréciation de `CampaignExecutionEvent`
  annoncée pour 3.0.
- **`EventListener/FormSubscriber.php`** — l'action côté **formulaire** (onglet Actions d'un
  formulaire, ex. juste après une soumission — pas besoin de campagne). Mécanique différente de
  CampaignBundle : toutes les actions de formulaire partagent le même événement d'exécution
  (`FormEvents::ON_EXECUTE_SUBMIT_ACTION`), c'est `checkContext()` qui filtre sur la nôtre plutôt
  qu'un événement dédié par action.
- **`Controller/MeetJoinController.php`** — route publique (`/meet/join/{token}`, groupe
  `routes.public` de `Config/config.php`, donc hors du préfixe `/s/` que le firewall exige
  authentifié, cf. `app/config/security.php`). Vérifie la signature, que la salle est toujours
  active, mint le vrai jeton plugNmeet avec `user_id = "lead-{id}"`, et redirige. Ce préfixe
  `lead-` est ce que la réconciliation recherche ensuite dans l'artefact d'analyse.
- **`Command/ReconcileMeetAttendanceCommand.php`** (`witty:meet:reconcile-attendance`) — à
  planifier via le cron système (Mautic n'a pas d'ordonnanceur interne, comme
  `mautic:segments:update`). Pour chaque salle avec des invitations en attente, télécharge
  l'artefact `MEETING_ANALYTICS` (JSON, généré par plugNmeet après la fin de la salle — pas
  toujours immédiatement disponible, d'où une invitation sans artefact reste en attente et
  réessaie indéfiniment plutôt que d'échouer), extrait les `user_id` au format `lead-{id}` de
  `users[]`, marque les invitations correspondantes `attended` et pose un tag
  `attended-{room_id}` sur le contact — exploitable immédiatement dans un filtre de segment ou une
  condition de campagne, sans widget dédié à construire.
- **Piège Doctrine rencontré** — `EntityManager::flush()` ne détecte pas les changements sur une
  entité `WittyMeetInvitation` déjà managée (récupérée via `find()`/`findOneBy()`) sans un appel
  explicite à `persist()` juste avant, même si rien ne devrait théoriquement l'exiger pour une
  entité déjà suivie. Reproduit de façon fiable, cause exacte non isolée ; le contournement
  (`persist()` systématique avant `flush()`, comme le fait Mautic dans tous ses `saveEntity()`)
  est appliqué partout où ce schéma apparaît (`MeetJoinController`,
  `ReconcileMeetAttendanceCommand`).

#### Troisième type de lien : partageable, sans contact connu à l'avance

Les deux mécanismes ci-dessus supposent un contact identifié *avant* de générer le lien :
l'invitation signée (un Lead précis) ou `roomsLinkAction` (l'admin tape le nom au moment de générer
le lien, jeton plugNmeet réel miné immédiatement, usage unique). Aucun des deux ne convient pour «
un lien à coller dans un canal public/à partager librement, que plusieurs personnes ouvriront chacune
en choisissant leur propre nom ». D'où un troisième bouton dans la modale **Liens** de la section
Rooms, **Lien partageable**.

- **`InvitationLinkSigner::sign()`/`verify()`** — `lead_id` est devenu **nullable**. Un lien
  partageable réutilise exactement le même mécanisme que l'invitation par campagne
  (`/meet/join/{token}`, JWT-maison longue durée, jeton plugNmeet réel jamais pré-généré), avec
  `lead_id=null` dans la charge utile — c'est ce que `joinAction()` lit pour savoir s'il doit
  résoudre un Lead automatiquement ou demander un nom.
- **`VideoconferenceController::roomsShareableLinkAction`** (`POST /witty/video/rooms/shareable-link`)
  — ne prend que `room_id` (pas de `name`, contrairement à `roomsLinkAction` : c'est le visiteur qui
  le fournira). Renvoie l'URL `/meet/join/{token}` telle quelle, jamais un jeton plugNmeet direct.
- **`MeetJoinController::joinAction()`** — si `lead_id` est `null`, affiche un petit formulaire HTML
  autonome ("Votre nom" + bouton Rejoindre) au lieu de résoudre un Lead, qui poste vers…
- **`MeetJoinController::joinAnonymousAction()`** (`POST /meet/join/{token}/enter`) — revérifie le
  token de zéro (jamais de confiance dans une valeur non re-vérifiée à travers une requête), rejette
  explicitement tout token dont `lead_id` ne serait *pas* `null` (défense en profondeur : l'UI ne
  propose jamais ce cas, mais ce chemin ne doit jamais devenir une porte dérobée pour rejoindre sous
  l'identité d'un Lead sans passer par la vérification normale), mint le vrai jeton plugNmeet avec le
  nom saisi (tronqué à 80 caractères) et `user_id = "guest-{hash}"`.
- **Compromis assumé, décidé avec l'utilisateur avant l'implémentation** : contrairement au préfixe
  `lead-{id}` du flux normal, `guest-{hash}` n'a **aucun** Lead à rattacher —
  `ReconcileMeetAttendanceCommand` ne le retrouvera jamais dans l'artefact `MEETING_ANALYTICS`, donc
  **aucun suivi de présence individuel** pour ce type de lien. Un lien partageable troque le suivi
  contre la souplesse (non-unique, pas de saisie préalable côté admin) ; pour un suivi précis,
  l'invitation personnalisée reste le bon outil.
- Aucune entité `WittyMeetInvitation` n'est créée pour ce type de lien (même choix que
  `roomsLinkAction`, qui n'en crée pas non plus) : il n'y a pas de contact à rattacher à une ligne
  de suivi.

### Recherche et navigation web (Bright Data, MCP)

Une clé API Bright Data (**Details**) donne à l'agent l'accès au [serveur MCP distant de Bright
Data](https://docs.brightdata.com/ai/mcp-server/remote/quickstart) — recherche web, scraping de
pages en markdown, et jusqu'à une soixantaine d'outils avancés en mode Pro (**Fonctionnalités**).
Contrairement aux autres outils du plugin, ceux-ci ne sont **pas** codés en dur : `ToolRegistry`
interroge le serveur en direct (`tools/list`) à chaque tour et relaie tout ce qu'il annonce, sous
le nom `brightdata_<nom distant>` (ex. `brightdata_search_engine`). Une clé retirée ou un serveur
en panne désactive silencieusement la capacité (erreur journalisée, jamais remontée à
l'utilisateur) sans casser les outils Mautic locaux.

- **`Service/Mcp/McpClientInterface.php`** — contrat d'un serveur MCP distant (`isConfigured()`,
  `listTools()`, `callTool()`). Un deuxième fournisseur MCP s'ajoute en déposant une classe qui
  l'implémente, taguée automatiquement `witty.mcp_client` par autoconfiguration — même principe
  que `witty.tool` pour les outils locaux.
- **`Service/Mcp/BrightDataMcpClient.php`** — client JSON-RPC 2.0 en "Streamable HTTP" (spec MCP) :
  poignée de main `initialize` / `notifications/initialized` à la première requête, session
  réutilisée (en-tête `Mcp-Session-Id`) pour tout le tour de l'agent. Le serveur peut répondre en
  JSON simple ou en flux SSE, les deux sont gérés. Authentification par jeton en query string
  (`?token=...`), pas par en-tête — un choix propre à ce serveur, pas une convention MCP générale.
- **`Service/Tool/McpTool.php`** — adaptateur `ToolInterface` construit à la volée par
  `ToolRegistry` pour chaque outil découvert. **Exclu** du chargement générique de
  `Config/services.php` (comme les objets de valeur `Dto/`) : le laisser dans l'autowiring ferait
  échouer la compilation du conteneur sur ses arguments scalaires, puisqu'aucune valeur n'est
  connue à la compilation, seulement à l'exécution.
- **Sécurité** — ces outils ne touchent jamais Mautic (pas de permission, pas d'écriture en base) :
  ils passent uniquement par l'infrastructure Bright Data. `AuditLogger` les journalise comme
  n'importe quel autre outil.

### Recherche de prospects B2B (Prospeo, MCP)

Une clé API Prospeo (**Details**) donne à l'agent l'accès au [serveur MCP distant de
Prospeo](https://prospeo.io/api-docs/mcp) — recherche et enrichissement de profils/entreprises B2B
(`search_person`, `enrich_person`, `bulk_enrich_person`, `search_company`, `enrich_company`,
`bulk_enrich_company`, `search_suggestions`, `get_account_info`). Même mécanique que Bright Data :
`Service/Mcp/ProspeoMcpClient.php` implémente `McpClientInterface`, mêmes transport et poignée de
main JSON-RPC 2.0 "Streamable HTTP" que `BrightDataMcpClient.php` (delibérément la même structure),
outils découverts en direct et exposés sous `prospeo_<nom distant>`. Seule différence
d'authentification : en-tête `X-KEY` (celui de l'API REST classique de Prospeo, réutilisé ici pour
le serveur MCP faute de documentation précise sur ce point à l'écriture de ce client — à
vérifier/ajuster dans `ProspeoMcpClient::send()` si besoin) plutôt que le jeton en query string de
Bright Data.

**Contrairement à Bright Data, ces outils seuls ne suffisent pas** : ils ne connaissent que
Prospeo, jamais Mautic — `search_person`/`enrich_person` renvoient des profils, pas des contacts.
`Service/Tool/Tools/BulkCreateContactsTool.php` (`bulk_create_contacts`) fait le pont : l'agent
extrait lui-même, pour chaque profil retenu, les champs pertinents (email si révélé via
`enrich_person`, prénom, nom, poste, entreprise, LinkedIn...) dans la forme générique attendue
(alias de champ contact Mautic → valeur, comme `create_contact`), l'outil crée/met à jour les
contacts (`LeadModel::setFieldValues()`/`saveEntity()`) et les rattache optionnellement à un
segment existant (`segment_id`, `ListModel::addLead()` — un appel par contact, comme
`manage_contact_segments`, `addLead()` n'acceptant qu'un seul lead à la fois côté Mautic).

- **Pas d'email obligatoire, volontairement** — à la différence de `create_contact`/
  `import_leads_from_file`, un contact peut n'avoir aucun email : `search_person` seul (avant tout
  appel à `enrich_person`) ne renvoie jamais l'email ni le mobile (obfusqués, révélés uniquement via
  l'enrichissement, à son propre coût en crédits). Le dédoublonnage par email ne s'applique qu'aux
  entrées qui en fournissent un ; les autres sont toujours créées, jamais fusionnées entre elles
  faute de clé fiable.
- **Plafonné à 500 contacts par appel** (`BulkCreateContactsTool::MAX_CONTACTS`), même logique que
  `import_leads_from_file` — au-delà, plusieurs appels plutôt qu'un import synchrone déraisonnable.
- **Coût en crédits** — `search_person` facture 1 crédit par page de 25 résultats même sans
  enrichissement ; `enrich_person` facture en plus pour révéler email/mobile. `PromptBuilder` rappelle
  explicitement à l'agent de s'arrêter au nombre de contacts demandé plutôt que de paginer au-delà.
- **Sécurité** — permission `lead:leads:create` (même gate que `create_contact`), flux de
  confirmation standard (aperçu : nombre de contacts valides/invalides, segment ciblé, échantillon).
  Les outils `prospeo_*` eux-mêmes ne touchent jamais Mautic (comme `brightdata_*`) ; seul
  `bulk_create_contacts` écrit, et c'est un outil Mautic local classique, pas un relais MCP.

### Enrichissement B2B (Apollo, API REST)

Une clé API Apollo (**Details**) donne à l'agent l'accès à l'API REST classique d'Apollo
(`https://api.apollo.io/api/v1`, en-tête `x-api-key`) — **pas** son serveur MCP hébergé
(`https://mcp.apollo.io/mcp`), qui exige une authentification OAuth 2.0 "partenaire" (inscription
d'une app OAuth auprès d'Apollo, flux de consentement navigateur, jetons à rafraîchir) — un chantier
sans commune mesure avec les autres intégrations du plugin, et hors scope ici. `Service/Apollo/ApolloClient.php`
est donc un client REST classique (comme les fournisseurs LLM, `Symfony\Contracts\HttpClient\HttpClientInterface`),
pas un `McpClientInterface`.

**Portée volontairement réduite à l'enrichissement, décidée avec l'utilisateur avant l'implémentation** :
- **Pas de recherche** (`People Search`/`Organization Search`) — jugée inutile et coûteuse pour ce
  cas d'usage, jamais intégrée.
- **Aucun outil Apollo Contacts/Accounts/Deals/Lists/Sequences/Tasks/Emails** — contrairement au
  serveur MCP d'Apollo (qui expose une cinquantaine d'actions, y compris créer des séquences ou
  envoyer des emails *depuis Apollo*), seuls 4 endpoints d'enrichissement existent côté plugin. Ce
  n'est pas un filtrage a posteriori d'une liste plus large : ces outils n'existent tout simplement
  pas dans le code, aucun risque que l'agent confonde Mautic et Apollo ou modifie quoi que ce soit
  côté Apollo.
- **`reveal_personal_emails` (synchrone) est câblé sur `enrich_person`/`bulk_enrich_people`** — le
  reveal téléphone et l'enrichissement "waterfall" (sources en cascade), tous deux asynchrones par
  nature côté Apollo, ont leur propre intégration dédiée : voir [Enrichissement approfondi
  (Apollo waterfall)](#enrichissement-approfondi-apollo-waterfall-asynchrone) ci-dessous.

| Outil | Endpoint Apollo | Limite |
|---|---|---|
| `enrich_person` | `GET /people/match` | 1 profil, 1-9 crédits si trouvé |
| `bulk_enrich_people` | `POST /people/bulk_match` | 10 profils/appel |
| `enrich_company` | `GET /organizations/enrich` | 1 entreprise, 1 crédit si trouvée |
| `bulk_enrich_companies` | `POST /organizations/bulk_enrich` | 10 entreprises/appel |

**`Service/Apollo/ApolloResponseTrimmer.php`** — une réponse Apollo brute est énorme (champs CRM
internes type `salesforce_id`, dizaines de champs toujours vides par poste d'`employment_history`,
technologies détaillées, événements de levée de fonds...) : inutile et coûteux en tokens à renvoyer
tel quel au modèle. Chaque outil ne renvoie que les champs directement exploitables :

- identité/coordonnées de base (poste, entreprise, localisation, email si révélé côté personne ;
  industrie, taille, revenu, siège côté entreprise) ;
- **qualification** — `seniority`/`departments`/`subdepartments`/`functions`, et
  `employment_history` allégé à poste/entreprise/dates/poste-actuel par entrée (le reste — degré,
  matière, adresse brute... — est systématiquement vide dans les réponses observées) : c'est ce qui
  permet de répondre à "je veux les contacts avec plus de 5 ans d'expérience", déductible des dates
  plutôt que d'un champ direct ;
- **réseaux sociaux et site**, personne ET entreprise employeuse (`linkedin_url`/`twitter_url`/
  `facebook_url`, `organization_linkedin_url`/`organization_twitter_url`/`organization_facebook_url`
  côté personne, mêmes champs à plat côté `enrich_company`) — pas des champs contact standards de
  Mautic, mais rien n'empêche l'utilisateur de les mapper vers des champs personnalisés.

Comme pour Prospeo, c'est l'agent qui extrait lui-même les champs pertinents pour
`bulk_create_contacts` — aucun renommage vers les alias Mautic n'est fait ici, pour ne pas coupler
cette intégration au schéma de champs d'une instance Mautic précise.

- **Pas de flux de confirmation dédié** — comme pour les outils `prospeo_*`/`brightdata_*`,
  l'enrichissement ne touche jamais Mautic (donc pas de `isWriteOperation()`), seul
  `bulk_create_contacts` en aval déclenche la confirmation standard. Le coût réel (crédits Apollo)
  est rappelé dans `PromptBuilder` plutôt que gaté techniquement, même choix que pour `search_person`
  de Prospeo.

### Enrichissement approfondi (Apollo waterfall, asynchrone)

Complète l'enrichissement Apollo classique (ci-dessus) avec le "waterfall" — des sources
supplémentaires en cascade, plus complet mais plus cher, pour révéler l'email et/ou le téléphone
d'un profil quand `enrich_person` seul ne suffit pas
([doc Apollo](https://docs.apollo.io/docs/enrich-phone-and-email-using-data-waterfall)). Utilise la
même clé API Apollo (**Details**) que le reste de l'intégration — rien de plus à configurer.

**Choix explicite du mode, jamais deviné** — `enrich_person_waterfall` exige un argument `mode`
obligatoire (`email`, `phone` ou `both`), sans valeur par défaut : `PromptBuilder` instruit l'agent
de le déduire STRICTEMENT de la demande de l'utilisateur ("trouve son email" = `email` seul, "son
numéro" = `phone` seul, "enrichis-le complètement" = `both`), jamais `both` par défaut faute de
précision, le coût en crédits Apollo différant fortement selon le choix (un mode mal choisi facture
pour une donnée que personne n'a demandée). Techniquement, `mode` pilote deux paramètres
indépendants côté Apollo : `run_waterfall_email`/`run_waterfall_phone`.

**Asynchrone par nature côté Apollo — c'est ce qui structure toute cette intégration :**
l'appel initial (`GET /people/match` avec `run_waterfall_email`/`run_waterfall_phone`/`webhook_url`)
répond immédiatement mais SANS l'email/le téléphone, seulement un `request_id` et un statut
`accepted`/`failed`. Le résultat réel arrive plus tard (parfois plusieurs minutes) via un `POST`
d'Apollo sur `webhook_url`. Trois pièces travaillent ensemble pour couvrir cet écart :

- **`enrich_person_waterfall`** — lance la demande. Accepte soit `contact_id` (contact Mautic
  existant, ses champs email/nom/entreprise servent d'identifiants par défaut), soit des identifiants
  bruts (comme `enrich_person`). Si acceptée par Apollo (`waterfall.status=accepted`), persiste une
  ligne `WittyApolloWaterfallRequest` (`status=pending`) et renvoie le `request_id` à l'agent — jamais
  la valeur elle-même, qui n'existe pas encore à ce stade.
- **`Controller/ApolloWaterfallWebhookController.php`** (route publique, hors du firewall comme
  `witty_meet_join`/`witty_meet_slots_availability`) — reçoit le `POST` d'Apollo, retrouve la ligne
  correspondante via `request_id` (seule clé de corrélation commune aux deux appels), et la marque
  `completed` (résultat trimé par `Service/Apollo/ApolloWaterfallPayloadParser.php`, classe à part
  pour rester testable sans booter le framework) ou `failed`. Idempotent par construction : un webhook
  livré deux fois (retry Apollo) réécrit simplement les mêmes champs.
- **`check_waterfall_enrichment`** — permet à l'agent de récupérer le résultat sur un tour ultérieur
  (le `request_id` n'a aucune chance de survivre en mémoire du modèle d'un tour à l'autre sans être
  répété par l'utilisateur). Trois entrées possibles : `request_id` pour une demande précise,
  `contact_id` pour l'historique d'un contact, ou aucun des deux pour les demandes récentes de
  l'utilisateur courant (`WittyApolloWaterfallRequestRepository::findRecentForUser()`, scopé comme
  `WittyAttachment` — le détail d'une demande n'a pas à être visible par un autre compte).

**Sécurité du webhook** — l'URL porte un jeton (`/witty/apollo/waterfall/webhook/{token}`,
`WittyConfig::getApolloWebhookToken()`, comparé en temps constant via `hash_equals()`) pour empêcher
un tiers qui devinerait le chemin de POSTer un faux résultat qu'un agent relaierait ensuite comme
fiable. Dérivé par hash de la clé API Apollo elle-même plutôt que d'un secret stocké à part : rien de
supplémentaire à générer ni à retenir, et il change automatiquement si la clé change — pas un secret
cryptographique protégeant un accès direct (seul le contrôleur le vérifie, en lecture seule pour
l'extérieur), une troncature de SHA-256 suffit face à ce risque.

**Aucune écriture automatique sur le contact** — volontairement, ni `enrich_person_waterfall` ni le
contrôleur webhook ne modifient jamais un contact Mautic : le webhook peut arriver hors de toute
conversation active (rien à faire confirmer à personne à ce moment-là). Une fois le résultat récupéré
via `check_waterfall_enrichment`, c'est l'agent qui appelle `update_contact` pour l'enregistrer — donc
avec le flux de confirmation standard, comme n'importe quelle autre écriture.

### Recherche de contacts gratuite (QuickEnrich, API REST)

Une clé API QuickEnrich (**Details**) donne à l'agent l'accès à `quickenrich_search_contacts`
(`POST /employees/contact-finder`) — une recherche par filtres dans la base externe de QuickEnrich,
**gratuite** (`credits_used` toujours `0`), contrairement à Prospeo/Apollo dont la recherche a été
jugée trop coûteuse pour être intégrée. `Service/Quickenrich/QuickenrichClient.php` suit le même
principe que `ApolloClient.php` (client REST classique, `Symfony\Contracts\HttpClient\HttpClientInterface`),
avec sa propre méthode d'authentification : jeton `Authorization: Bearer` — un troisième schéma
différent de Prospeo (`X-KEY`) et Apollo (`x-api-key`), aucune convention commune entre fournisseurs
à réutiliser d'un client à l'autre.

**Nom du tool délibérément préfixé `quickenrich_`** — contrairement aux outils Apollo, celui-ci
recherche des contacts dans une base externe, exactement ce que fait déjà `search_contacts` (parmi
les contacts *déjà dans Mautic*) : sans préfixe, la collision de nom aurait été le pire cas de
confusion Mautic/fournisseur externe possible dans tout le plugin. `PromptBuilder` le rappelle
explicitement en plus du nom.

- **Endpoint de découverte uniquement** — jamais d'email/téléphone en clair dans la réponse,
  seulement `has_email`/`has_phone` (la donnée existe en base ou non). Révéler la valeur d'un
  contact déjà identifié se fait ensuite via `quickenrich_find_employee_email`
  (`GET /employees/search`) ou `quickenrich_find_employee_phone` (`GET /employees/phone-search`) —
  soit `linkedin_url` seul, soit le trio `company_url`+`first_name`+`last_name` (si les 4 sont
  fournis, QuickEnrich essaie `linkedin_url` en premier puis retombe sur le trio). `found: false`
  si rien n'est trouvé (`data` vide côté API), sinon `employee` avec les champs renvoyés tels quels
  (peu d'intérêt à les trimmer : la réponse QuickEnrich est déjà plate et courte, contrairement à
  Apollo). Le téléphone facture **1 crédit uniquement s'il est trouvé** (rien n'est déduit en cas
  d'échec) ; l'email n'a pas cette précision dans la doc, non supposée ici. `PromptBuilder` rappelle
  de n'appeler ces deux outils que sur un contact réellement retenu, jamais en boucle sur toute une
  liste par défaut, pour ne pas cramer des crédits inutilement.
- **`quickenrich_list_filter_values`** — cinq dimensions (`country_code`, `industry_linkedin`,
  `number_of_employees`, `revenue`, `services`) exigent une chaîne exacte issue des endpoints de
  référence publics de QuickEnrich (`GET /lookups/*`, aucune clé requise côté QuickEnrich, mais même
  verrou `isQuickenrichConfigured()` que le reste par cohérence) — une valeur hors liste y renvoie
  une erreur. Cet outil relaie ces cinq endpoints, paramétré par `dimension`. Les six autres
  dimensions (`title`, `locality`, `company_name`, `company_url`, `city`, `bio_li`) sont du texte
  libre. La doc montre les endpoints de référence renvoyant un tableau JSON brut
  (`["US", "GB", ...]`), pas la même enveloppe `{data: [...]}` que la recherche elle-même : l'outil
  gère les deux formes sans lever d'hypothèse fausse sur l'une ou l'autre.
- **Au moins un filtre obligatoire** — un `include`/`exclude` non vide sur une dimension, ou
  `has_email`/`has_phone` à `true` ; validé côté plugin avant même d'appeler QuickEnrich (message
  clair immédiat plutôt qu'un aller-retour réseau pour rien). Les dimensions à `include`/`exclude`
  tous deux vides ne sont jamais envoyées, pour ne pas compter par erreur comme un filtre actif côté
  API.
- **Limite de débit** — 120 requêtes/minute par clé API pour Contact Finder (recherche), 1000/minute
  pour Search/phone/email (révélation), communiquées par l'utilisateur. Contrairement au coût en
  crédits Apollo (seulement rappelé dans la description de l'outil, jamais imposé), ces deux limites
  sont désormais **auto-appliquées** côté job de fond — cf. sous-sections dédiées ci-dessous
  (`QuickenrichBulkSearchJobHandler`/`QuickenrichBulkEnrichPeopleJobHandler`), pas seulement rappelées.

#### Révélation en masse (`start_quickenrich_bulk_enrich_people`)

Limite signalée par l'agent lui-même en session : `quickenrich_find_employee_email`/
`quickenrich_find_employee_phone` n'existaient qu'en appel unitaire, sans équivalent en masse
(contrairement à la recherche, déjà couverte par `start_quickenrich_bulk_search`) — inutilisable pour
révéler l'email/téléphone de plusieurs milliers de contacts déjà importés dans Mautic.

- **`Service/Job/Handlers/QuickenrichBulkEnrichPeopleJobHandler.php`** (`quickenrich_bulk_enrich_people`)
  — parcourt tous les membres d'un **segment Mautic** (même requête `lead_lists_leads` par `lead_id`
  croissant, même exclusion `manually_removed=1`, que `ApolloBulkEnrichPeopleJobHandler`), 60 par lot.
  `reveal` (`email`/`phone`/les deux) choisit quel(s) endpoint(s) appeler par contact — contrairement à
  Apollo `bulk_match`, QuickEnrich n'a pas d'appel groupé : ce sont un ou deux vrais appels HTTP
  **par contact**, jamais un seul appel pour tout le lot.
- **`allowsMultiplePassesPerTick()=true` — seule exception parmi les quatre handlers à fournisseur
  externe, question posée en session** : la première version restait à `false` (un seul lot de 40 par
  minute, comme Apollo/QuickEnrich-recherche/MCP), plafonnant à 40-80 appels/minute alors que
  QuickEnrich autorise 1000/minute par clé API (chiffre précis communiqué par l'utilisateur) — moins de
  10 % du débit réellement disponible utilisé. Justifié **uniquement ici** : ce chiffre exact permet un
  **throttle déterministe** (`MIN_CALL_INTERVAL_SECONDS = 0.065`, ~65 ms mesurés et attendus entre deux
  appels consécutifs, jamais supposé couvert par la seule latence réseau) qui garantit de ne jamais le
  dépasser, quel que soit le nombre de passages enchaînés dans un même cron — contrairement à
  Apollo/QuickEnrich-recherche/MCP dont la limite réelle n'est pas connue avec cette précision ici, où
  un multi-passage resterait une supposition risquée. **Vérifié contre la vraie base locale** (pas
  seulement raisonné) : deux appels réels chronométrés à ~65,1 ms d'écart. Débit résultant : plusieurs
  centaines de contacts par minute au lieu de 40, auto-régulé plutôt que d'exposer un chiffre fixe qui
  se périmerait si QuickEnrich changeait sa limite.
- **Identifiant obligatoire : un lien LinkedIn déjà présent sur le contact** (champ Mautic `linkedin`)
  — le seul exploitable en masse sans requêter une `Company` par contact pour le repli
  `company_url`+`first_name`+`last_name` des outils unitaires, volontairement pas repris ici. Un
  contact sans LinkedIn est écarté proprement (`status=skipped`), jamais une erreur bloquante pour le
  reste du lot. Workflow typique en amont : `start_quickenrich_bulk_search` puis
  `start_contacts_import_from_job` (avec `linkedin` dans le mapping) pour peupler ce champ avant de
  lancer l'enrichissement.
- **`linkedin` n'est PAS un champ Doctrine mappé sur `Lead`** (seuls `title`/`firstname`/`lastname`/
  `company`/`position`/`email`/`phone`/`mobile`/`address1`/`address2`/`city`/`state`/`zipcode`/
  `timezone`/`country` le sont, cf. `Lead::loadMetadata()`) — impossible de le lire par une simple
  requête DQL comme pour ces champs-là, alors que c'est pourtant une vraie colonne de la table `leads`
  (vérifié dans cette session lors du diagnostic du bug de mapping LinkedIn, cf. plus haut). Lu en SQL
  natif, en lot (`WHERE id IN (...)`), plutôt que d'hydrater chaque `Lead` en entier
  (`LeadModel::getEntity()`, tous les groupes de champs) juste pour cette seule valeur — nom de table
  obtenu via les métadonnées Doctrine (`EntityManager::getClassMetadata(Lead::class)->getTableName()`)
  plutôt qu'une constante de préfixe supposée définie, jamais désynchronisé du mapping réel.
  **Vérifié contre une vraie base MySQL locale** (pas seulement des doublures) — requête exécutée pour
  de vrai contre un segment réel avec un membre porteur d'un lien LinkedIn et un membre sans, les deux
  correctement distingués (révélé pour l'un, écarté pour l'autre), données de test nettoyées ensuite.
- **`start_quickenrich_bulk_enrich_people`** — mêmes garde-fous que les autres `start_*bulk*`
  (segment introuvable/vide rejeté avant toute création de job). `QuickenrichBulkEnrichPeopleJobHandler::TYPE`
  ajouté à `ImportContactsFromJobHandler::CONTACT_ID_MATCHED_SOURCE_TYPES` : `start_contacts_import_from_job`
  détecte donc automatiquement, comme pour Apollo, qu'un job source issu de cet enrichissement doit
  être appliqué par **id de contact**, jamais par dédoublonnage email — rien à préciser à l'agent.
- **QuickEnrich est strict sur la forme de `linkedin_url`, deux garde-fous ajoutés après un premier
  passage en session** :
  - **Accents translitérés en ASCII avant tout appel** (`normalizeLinkedinUrl()`, `iconv('UTF-8',
    'ASCII//TRANSLIT//IGNORE', ...)`) — un caractère accentué fait échouer la requête en HTTP 422 côté
    QuickEnrich. Vérifié contre la vraie base locale : un lien `françois-tèst` correctement transformé
    en `francois-test` avant l'appel, jamais rejeté.
  - **Une valeur qui n'est manifestement pas une URL** (pas de préfixe `http://`/`https://`) est
    écartée **avant même d'appeler QuickEnrich** (`status=skipped`), jamais envoyée pour rien.
  - **Un HTTP 422 malgré tout reste possible** (donnée spécifiquement invalide pour CE contact, ex.
    profil LinkedIn supprimé depuis) — bug réel repéré en session : la première version faisait
    échouer **tout le job** pour une seule requête en erreur, alors qu'un 422 ne dit rien des autres
    contacts du lot. `QuickenrichException::getCode()` porte désormais le code HTTP réel (propagé
    depuis `QuickenrichClient::request()`, absent avant ce correctif) : seuls les codes
    spécifiques-à-la-requête (400/422, `QuickenrichBulkEnrichPeopleJobHandler::PER_ITEM_ERROR_CODES`)
    tracent cet élément en échec et continuent le lot ; une vraie panne fournisseur (401/429/5xx/timeout,
    code 0 ou hors de cette liste) fait toujours échouer le job entier — reprenable ensuite via
    `resume_bulk_job`, jamais une raison de perdre la distinction entre les deux cas.

### Données publiques (data.gouv.fr, MCP)

Un interrupteur **Fonctionnalités** (pas de clé API) donne à l'agent l'accès au [serveur MCP officiel
de data.gouv.fr](https://guides.data.gouv.fr/intelligence-artificielle/le-serveur-mcp-de-data.gouv.fr)
— recherche et consultation des jeux de données publiques françaises : `search_datasets`,
`get_dataset_info`, `list_dataset_resources`, `get_resource_info`, `query_resource_data`,
`download_and_parse_resource`, `search_dataservices`, `get_dataservice_info`,
`get_dataservice_openapi_spec`, `get_metrics`. Même mécanique que Bright Data/Prospeo :
`Service/Mcp/DatagouvMcpClient.php` implémente `McpClientInterface`, mêmes transport et poignée de
main JSON-RPC 2.0 "Streamable HTTP" (deliberement la même structure que
`BrightDataMcpClient.php`/`ProspeoMcpClient.php`), outils découverts en direct et exposés sous
`datagouv_<nom distant>`.

**Seule intégration MCP du plugin sans authentification** — le serveur est public et documenté
"no API key required (read-only tools)" : `getConfigured()` n'existe donc pas côté data.gouv.fr,
`Service/Mcp/DatagouvMcpClient::send()` n'envoie aucun en-tête d'auth (ni `X-KEY` comme Prospeo, ni
`Authorization` comme QuickEnrich, ni jeton en query string comme Bright Data). Ce qui gate
l'activation est un simple interrupteur (`WittyConfig::isDatagouvEnabled()`, réglage
`feature_settings['datagouv_enabled']`, onglet **Fonctionnalités** — pas **Details**, puisqu'il n'y
a aucun secret à saisir) : sans lui, l'agent n'a accès à rien de nouveau malgré l'absence de clé,
pour garder le choix de l'exposer ou non.

- **Lecture seule, zéro risque de confusion Mautic** — la doc officielle ne documente que des outils
  de consultation (recherche, requêtage tabulaire, téléchargement), rien qui écrit. Contrairement à
  Prospeo/Apollo/QuickEnrich, il n'y a aucun pont vers `bulk_create_contacts` ni aucun autre outil
  Mautic : un jeu de données ou une API trouvée ici reste un objet data.gouv.fr, jamais un objet
  Mautic, donc rien à rattacher à un segment ou une campagne.
- **Marqué expérimental par data.gouv.fr lui-même** — la doc officielle prévient que les réponses en
  langage naturel "may be incomplete, incorrect or include hallucinations" et recommande les API
  structurées classiques de data.gouv.fr pour un usage sérieux. `PromptBuilder` relaie cette réserve :
  l'agent doit recouper les chiffres avant de les présenter comme fiables, surtout s'ils doivent finir
  dans un email ou un contenu envoyé à des contacts.
- **Sécurité** — ces outils ne touchent jamais Mautic (pas de permission, pas d'écriture en base) :
  `AuditLogger` les journalise comme n'importe quel autre outil, même si le risque qu'ils modifient
  quoi que ce soit est nul par construction (aucun outil d'écriture côté source).

### Traitement en masse (jobs de fond)

`AgentRunner::run()` tourne entièrement dans une seule requête HTTP (streaming SSE ou non), bornée
par `max_iterations` (8 par défaut, 20 max) : un enrichissement Apollo sur un segment de 10 000 ou
50 000 contacts représenterait des milliers d'appels API, largement au-delà de ce qu'un seul tour de
chat peut absorber — pas un problème de timeout à augmenter (chaque appel HTTP a déjà le sien,
30-120 s selon le client), un problème de **volume structurel**. Plutôt que de patcher chaque outil
concerné séparément, ce mécanisme est générique et partagé par toutes les intégrations bulk-capables
(Apollo, QuickEnrich, Prospeo, data.gouv.fr) — même esprit que `ToolInterface`/`McpClientInterface`,
une classe taguée = une capacité de plus, rien à câbler ailleurs.

- **`Entity/WittyBackgroundJob.php`** — un job (`type`, `status` queued/running/completed/failed/
  cancelled, `params` figés à la création, `resumeCursor` que chaque handler avance à son rythme,
  compteurs `processedItems`/`succeededItems`/`failedItems`). Nommé `resumeCursor` et pas simplement
  `cursor` : `CURSOR` est un mot réservé SQL, un nom de colonne littéral l'aurait exigé quoté dans
  chaque requête générée par Doctrine.
- **`Entity/WittyBackgroundJobItem.php`** — un élément traité (`external_ref`, `data` en attente de
  revue). Table séparée du job plutôt qu'un unique JSON géant sur `WittyBackgroundJob` : à l'échelle
  de 50 000 éléments, impossible à paginer sinon (`list_bulk_job_items` doit pouvoir en montrer 50 à
  la fois, pas les 50 000 d'un coup — budget de contexte du modèle).
- **`Service/Job/JobHandlerInterface.php`** — `getType()` + `processChunk(WittyBackgroundJob $job)`,
  taggué `witty.job_handler` (auto-enregistré comme `witty.tool`/`witty.mcp_client`).
  `processChunk()` traite un lot borné (quelques dizaines d'éléments, une poignée d'appels API),
  avance `resumeCursor`, et bascule `status=completed` quand il n'y a plus rien à faire. Trois
  implémentations aujourd'hui :
  - **`ApolloBulkEnrichPeopleJobHandler`** (`apollo_bulk_enrich_people`) — 10 contacts par lot
    (plafond `POST /people/bulk_match`), parcourt `lead_lists_leads` par `lead_id` croissant plutôt
    que par offset/limite classique (reste correct même si la composition du segment change en cours
    de route). Corrélation Apollo↔contact **positionnelle** (la réponse ne renvoie aucune clé de
    corrélation) : si le nombre de résultats ne correspond pas au nombre envoyé, le job échoue
    explicitement plutôt que de risquer une mauvaise attribution.
  - **`QuickenrichBulkSearchJobHandler`** (`quickenrich_bulk_search_contacts`) — pagine
    `POST /employees/contact-finder` (100/page) jusqu'à `target_count` ou épuisement (page
    incomplète = fin réelle des résultats, termine même sous la cible).
    **`allowsMultiplePassesPerTick()=true`, question posée en session** : la première version restait
    à un seul appel Contact Finder par minute (1/120e du débit autorisé — encore plus sous-exploité
    que ne l'était l'enrichissement avant son propre fix, cf. plus bas). Même exception justifiée que
    `QuickenrichBulkEnrichPeopleJobHandler` (débit connu avec précision, 120/minute par clé API
    communiqué par l'utilisateur, auto-appliqué en interne), mais mécanisme différent : ce handler ne
    fait qu'**un seul** appel par `processChunk()` (jamais plusieurs dans la même boucle), donc le
    throttle (`MIN_CALL_INTERVAL_SECONDS = 0.55`, ~550 ms) ne peut pas se contenter d'une variable
    locale — il doit survivre **d'un passage à l'autre**. `resumeCursor['last_call_at']` porte ce
    timestamp à cette seule fin (ce n'est pas un état de reprise de pagination à proprement parler,
    juste le véhicule déjà existant le plus simple). **Vérifié contre un scénario réel de 3 passages
    enchaînés** (pas seulement raisonné) : écarts mesurés à 550,1 ms pile entre chaque appel. Débit
    résultant : plusieurs milliers de résultats par minute possibles au lieu de 100.
  - **`McpBulkSearchJobHandler`** (`mcp_bulk_search`) — **un seul handler générique pour Prospeo ET
    data.gouv.fr** (n'importe quel futur serveur MCP inclus), plutôt qu'un par fournisseur.
    Contrairement à Apollo/QuickEnrich (clients REST que ce plugin écrit et contrôle, schéma de
    pagination connu avec certitude), un outil MCP découvert en direct a un schéma défini par SON
    fournisseur : plutôt que deviner le nom du paramètre de pagination (`page` ? `offset` ? autre
    chose), c'est `start_bulk_mcp_search` qui le fait fournir explicitement par l'agent (qui, lui,
    voit le schéma réel via la liste d'outils) — `page_argument`, `page_start`, `page_step`,
    `items_field` (où trouver le tableau de résultats dans la réponse, forme différente d'un outil à
    l'autre).
  - **`ImportContactsFromJobHandler`** (`import_contacts_from_job`) — convertit les résultats
    **déjà stockés** d'un job de recherche terminé en contacts Mautic, sans jamais les faire
    retransiter par le modèle. Voir la sous-section dédiée ci-dessous, la décision produit qui l'a
    motivée mérite d'être comprise avant de l'utiliser.
  - **`ApolloBulkEnrichCompaniesJobHandler`** (`apollo_bulk_enrich_companies`) et
    **`ImportCompaniesFromJobHandler`** (`import_companies_from_job`) — même principe côté
    entreprises, avec une différence structurelle assumée (toujours une mise à jour par id, jamais de
    création) : voir la sous-section "Entreprises" ci-dessous.
- **`Command/ProcessBackgroundJobsCommand.php`** (`witty:jobs:process`) — à planifier via le cron
  système comme `witty:meet:reconcile-attendance` (Mautic n'a pas d'ordonnanceur interne), idéalement
  chaque minute. Borné en **temps** (50 s) plutôt qu'en nombre de jobs : même avec beaucoup de jobs en
  attente, une exécution reste courte et prévisible, jamais de quoi chevaucher le passage suivant.
  Aucun verrou explicite (comme les autres `Command/` de ce plugin) : un chevauchement re-traiterait
  au pire un lot déjà fait, jamais de corruption.
- **Multi-passage pour les imports (`JobHandlerInterface::allowsMultiplePassesPerTick()`)** — question
  posée en session : 50 imports/minute (un lot par passage de cron, taille fixe), c'est trop peu,
  pourquoi ? Réponse trouvée en comparant avec `mautic:import` (import CSV natif) :
  `LeadBundle\Model\ImportModel::process()` n'a **aucune** coupure de temps, il tourne jusqu'à la fin
  du fichier en un seul appel CLI — ce qui marche parce que cette commande est dédiée à UN import à
  la fois, jamais partagée avec d'autres types de tâches comme l'est `witty:jobs:process`. Augmenter
  bêtement `BATCH_SIZE` (50 → 1000) aurait risqué de dépasser les 50 s du budget commun sans aucun
  filet, puisque le budget n'est vérifié **qu'entre deux jobs**, jamais interrompu en cours de lot.
  Solution retenue, plus proche de l'esprit de Mautic mais bornée : un handler dont
  `allowsMultiplePassesPerTick()` renvoie `true` peut être rappelé plusieurs fois sur le **même** job
  au sein d'un seul passage de cron (au lieu d'un seul lot), tant qu'il reste du budget de temps —
  `ProcessBackgroundJobsCommand::execute()` boucle sur `tick()` jusqu'à ce que le job devienne
  terminal, que `MAX_PASSES_PER_JOB` (500, filet de sécurité) soit atteint, ou que le budget expire.
  Réservé aux deux handlers d'import (`ImportContactsFromJobHandler`/`ImportCompaniesFromJobHandler`,
  aucun appel API externe, uniquement des écritures Mautic) : les quatre autres handlers (Apollo/
  QuickEnrich/MCP) renvoient `false` et restent à un lot par minute, pour ne jamais risquer la limite
  de débit d'un fournisseur externe en les appelant en rafale dans un même passage.
- **Bug de production réel causé par le multi-passage, corrigé en session** — `EntityManagerClosed`
  remonté par `witty:jobs:process` en environnement réel, plantant toute la commande. Cause : le
  `persist()`/`flush()` final de `tick()` n'était protégé par **aucun** try/catch (ni celui autour de
  `processChunk()`, déjà terminé à ce stade côté handler, ni un autre dans `execute()`, qui appelait
  `tick()` sans le protéger non plus) — une erreur Doctrine qui ferme l'EntityManager (deadlock MySQL
  entre deux passages de cron qui se chevauchent, connexion perdue...) remontait donc telle quelle et
  plantait toute la commande avec une trace brute, perdant le suivi de tous les jobs restants avec.
  Le multi-passage a **concrètement aggravé le risque** : un passage peut désormais rester en vie
  jusqu'à 50 s au lieu de quasi instantané, rendant un chevauchement de deux passages de cron (sans
  "prevent overlapping" côté ordonnanceur) bien plus probable qu'avant. Corrigé par
  `ProcessBackgroundJobsCommand::persistAndFlush()` : vérifie `EntityManagerInterface::isOpen()`
  explicitement (Doctrine peut fermer l'EntityManager sans que l'exception d'origine soit celle qui
  remonte jusqu'ici, ex. fermée par une opération antérieure dans `processChunk()` déjà avalée par son
  propre try/catch) **et** entoure le `persist()`/`flush()` d'un try/catch — dans les deux cas,
  `tick()` renvoie `false`, `execute()` arrête net toute la boucle (interne ET externe) et renvoie
  `Command::FAILURE` proprement plutôt que de laisser une exception non rattrapée se propager.
  N'élimine pas le risque de départ (deux passages qui se chevauchent restent possibles) : `flock -n
  /tmp/witty-jobs-process.lock php bin/console witty:jobs:process` dans la ligne de cron (ou l'option
  équivalente de l'ordonnanceur) reste la vraie protection, déjà recommandée plus haut. **Vérifié
  contre un scénario réel** (pas seulement raisonné) : `isOpen()=false` et `flush()` qui lève une
  exception simulent chacun le bug de production, les deux confirmés gérés sans plantage
  (`Command::FAILURE`, boucle arrêtée après un seul tick).
- **L'exception exacte doit être visible sans creuser un second fichier de log** — question posée en
  session après coup : arrêter proprement (`Command::FAILURE`) ne servait à rien pour diagnostiquer si
  le seul message affiché restait générique ("EntityManager fermé..."), l'exception Doctrine d'origine
  n'existant que dans `var/logs/mautic_prod-*.log` (le logger Mautic), jamais dans la sortie du cron
  elle-même (ce que Coolify affiche, l'endroit où l'utilisateur regarde en premier). Corrigé : le
  `catch` autour de `processChunk()` et les deux branches de `persistAndFlush()` écrivent désormais
  `sprintf('%s: %s', get_class($e), $e->getMessage())` directement via `$output->writeln()`, en plus
  du log Mautic existant (pas à la place). **Vérifié contre un scénario réel** : une exception
  `RuntimeException` avec un message de deadlock MySQL simulé apparaît telle quelle
  (`Job #0 (t) : ECHEC — RuntimeException: SQLSTATE[40001]: Deadlock found...`) dans la sortie de
  `CommandTester`, plus besoin d'aller chercher ailleurs pour la toute première lecture.
- **`start_apollo_bulk_enrich_people`/`start_quickenrich_bulk_search`/`start_bulk_mcp_search`** — un
  outil de déclenchement par intégration (schéma typé, propre à chacune) plutôt qu'un unique outil
  générique à paramètres libres : plus fiable pour un modèle de tool-calling qu'un `params: object`
  fourre-tout. Aucun ne renvoie de résultat directement, seulement un `job_id`.
- **`check_bulk_job`/`list_bulk_job_items`** — génériques, partagés par les trois intégrations
  (même principe que `check_waterfall_enrichment`). Scope par utilisateur créateur (comme
  `WittyAttachment`) : un `job_id` d'un autre compte est traité comme introuvable.
- **Reprise sur erreur (`resume_bulk_job`)** — question posée en session : une erreur 500/timeout
  fournisseur en cours de pagination (constaté avec QuickEnrich, ex. plantage à 10 000/23 603)
  arrête-t-elle tout, faut-il tout relancer depuis zéro ? Réponse trouvée en relisant les quatre
  handlers : non, **`resumeCursor` n'avance déjà qu'après un appel fournisseur réussi** (choix fait
  dès la conception initiale du système, cf. leurs docblocks individuelles) — un job `failed` pointe
  donc toujours sur la dernière position confirmée, jamais sur une position perdue. Le seul obstacle
  réel était que `ProcessBackgroundJobsCommand::findRunnable()` n'interroge que
  `queued`/`running` : un job `failed` n'était donc plus jamais repris par le cron, quel que soit
  l'état de son curseur. `Service/Tool/Tools/ResumeBulkJobTool.php` (`resume_bulk_job`) ne fait donc
  qu'une chose, volontairement minimale — repasser le job en `queued` sans toucher à
  `resumeCursor`/aux items déjà enregistrés — le prochain passage de cron reprend alors de lui-même,
  via le même code que n'importe quel tick normal (**zéro changement nécessaire dans les quatre
  handlers**). Plafonné à `ResumeBulkJobTool::MAX_RESUME_ATTEMPTS` (5) via une nouvelle colonne
  `resume_count` (`Migrations/Version_3_1_0.php`) : au-delà, l'outil refuse et renvoie le dernier
  message d'erreur plutôt que de laisser l'agent boucler indéfiniment contre un fournisseur en panne
  prolongée. `check_bulk_job` expose `resume_count` (omis si jamais relancé) pour que l'agent voie
  l'historique. `PromptBuilder` établit l'ordre de préférence : `resume_bulk_job` d'abord (reprend LE
  job, rien à réimporter séparément) ; seulement si le plafond est atteint ou le problème manifestement
  durable, repli sur l'import partiel déjà documenté ci-dessus (`partial: true`) puis une nouvelle
  recherche pour le reliquat réel.
- **Annulation (`cancel_bulk_job`)** — question posée en session : si l'utilisateur se rétracte sur un
  job encore en cours, l'agent n'avait **aucun** moyen de l'arrêter — un vrai trou, malgré l'existence
  de `WittyBackgroundJob::STATUS_CANCELLED` depuis la conception initiale du système (jamais
  atteignable faute d'outil). `Service/Tool/Tools/CancelBulkJobTool.php` (`cancel_bulk_job`), même
  esprit minimal que `resume_bulk_job` : repasse un job `queued`/`running` en `cancelled`, ne touche à
  rien d'autre (`resumeCursor`, items déjà enregistrés intacts). Suffisant pour l'arrêter :
  `findRunnable()` n'interroge que `queued`/`running`, un job `cancelled` n'est donc plus jamais repris
  par le cron. Trois conséquences travaillées ensemble plutôt que l'outil isolé :
  - `resume_bulk_job` accepte désormais **aussi** un job `cancelled` (`RESUMABLE_STATUSES`, pas
    seulement `failed`) : si l'utilisateur se rétracte une seconde fois ("en fait non, continue"),
    rien n'est perdu, exactement le même mécanisme que la reprise sur erreur.
  - `start_contacts_import_from_job`/`start_companies_import_from_job` acceptent désormais aussi un
    job source `cancelled` (`IMPORTABLE_SOURCE_STATUSES`), marqué `partial: true` comme un job
    `failed` — les résultats déjà obtenus avant l'annulation restent exploitables.
  - `PromptBuilder` instruit explicitement l'agent de ne jamais laisser un job non désiré tourner en
    espérant qu'il s'arrête tout seul (il continuerait à consommer des crédits/appels fournisseur au
    prochain passage de cron) : `cancel_bulk_job` est le réflexe correct.
- **Importer maintenant PUIS reprendre plus tard : question posée en session, et un vrai risque de
  doublon existait pour y répondre "oui" sans réserve.** Scénario : les 10 000 premiers résultats
  sont importés (`start_contacts_import_from_job`, sans email à dédupliquer — décision explicite de
  ne pas enrichir les emails à ce stade), puis `resume_bulk_job` fait grossir le job source de
  13 600 résultats de plus. Si un second `start_contacts_import_from_job` relisait tout depuis le
  début (comportement d'origine : offset 0 à chaque nouvel appel), les 10 000 premiers — sans email,
  donc jamais dédupliqués par `ContactImporter::importOne()` — seraient **recréés en double**. Une
  mise à jour par id (`updateById()`, cas enrichissement) n'aurait pas ce problème (idempotente), mais
  aurait quand même retraité inutilement des milliers d'éléments déjà appliqués à chaque nouvel appel.
  Corrigé par un marquage au niveau de l'élément plutôt qu'un simple curseur numérique (qui ne sait
  pas distinguer "déjà lu par CET import" de "déjà transmis à Mautic par un import antérieur") :
  - **`WittyBackgroundJobItem::$consumedAt`** (nullable, `Migrations/Version_3_2_0.php`) — posé
    UNIQUEMENT au moment où l'élément est réellement transmis à `ContactImporter`/`CompanyImporter`
    (pas pour un élément écarté par un filtre ou sans champ mappable : un futur import avec un
    mapping/des filtres différents doit encore pouvoir le reconsidérer — la portée du marquage est
    volontairement étroite, à l'endroit exact où le risque de doublon existe, pas plus large).
  - **`WittyBackgroundJobItemRepository::findForJob()`/`countForJob()`** — nouveau paramètre
    `$onlyUnconsumed` (défaut `false`, donc **jamais** activé pour `list_bulk_job_items`, qui doit
    montrer tout l'historique). `ImportContactsFromJobHandler`/`ImportCompaniesFromJobHandler`
    l'utilisent systématiquement à `true` et abandonnent leur `resumeCursor` par offset : "pas encore
    consommé" **est** le curseur de reprise, un offset numérique n'aurait plus de sens dès qu'un
    job source peut grossir entre deux imports.
  - **`Start*ImportFromJobTool`** — le calcul d'`available` (et donc le refus "aucun résultat
    exploitable") ne compte plus que les éléments non consommés : un second appel sur un job déjà
    entièrement importé est proprement refusé plutôt que de créer un job qui ne ferait rien.
  - Résultat : importer une partie, reprendre le job source, puis réimporter est un flux
    **explicitement supporté et sûr**, y compris répété plusieurs fois — chaque import ne récupère
    jamais que le surplus obtenu depuis le précédent.
- **Aucune écriture automatique sur un contact Mautic** — un résultat de job est une donnée en
  ATTENTE de revue (`list_bulk_job_items`), jamais appliquée d'elle-même : c'est l'agent qui, sur
  demande, appelle `update_contact`/`bulk_create_contacts` pour l'enregistrer, avec le flux de
  confirmation standard. Même raisonnement que le waterfall Apollo, pour la même raison — un webhook
  ou un passage de cron n'a jamais personne pour valider quoi que ce soit au moment où il s'exécute.
- **Vérifié contre une vraie base MySQL locale** (pas seulement des doublures) — la requête DQL
  d'`ApolloBulkEnrichPeopleJobHandler` (parcours de `lead_lists_leads`, exclusion des membres
  `manually_removed=1`, corrélation positionnelle, reprise depuis un curseur) a été exécutée pour de
  vrai contre un segment et des contacts réels dans cette session, données de test nettoyées ensuite
  sans toucher aux données existantes.

#### Convertir/appliquer un job en contacts et entreprises, à volume

`bulk_create_contacts` (existant, synchrone) exige que l'agent recopie chaque contact en argument
d'appel — viable pour quelques centaines d'entrées, pas pour les milliers de résultats qu'un job de
recherche peut accumuler : le modèle devrait littéralement générer des dizaines de milliers d'objets
JSON en sortie, hors de portée de n'importe quel budget de tokens bien avant même le plafond de 500.
En faire un job de fond classique n'aurait rien réglé : le goulot n'est pas le **temps** d'exécution
(écrire un contact en base est rapide), c'est la **taille de ce que l'agent doit taper**.

**Décision produit qui a précédé le code** : la première idée envisagée était de laisser l'agent
écrire lui-même un script PHP sur mesure (voir la structure des données, l'adapter, filtrer les
lignes pourries) et le faire tourner en tâche de fond. Refusé sciemment — pas une histoire de
complexité technique, une histoire de **surface de confiance** : contrairement à tous les autres
outils de ce plugin (des opérations fixes, écrites et testées à l'avance), du PHP généré à la volée
tournerait sans supervision, avec un accès complet à l'application, sans qu'aucune confirmation
structurée ne puisse réellement en prévisualiser l'effet — la "confirmation" reviendrait à relire du
code à chaque fois, ou à ne rien relire du tout.

**Solution retenue : un mapping et des filtres DÉCLARATIFS**, interprétés par du code déjà écrit et
vérifié, jamais par du code que l'agent fournit :

- **`Service/Job/JobItemFilter.php`** — opérateurs fixes (`has_field`, `field_not_empty`,
  `field_empty`, `field_equals`, `field_not_equals`, `field_matches`), combinés en ET, jamais du code
  libre. Une valeur `path` supporte la notation pointée pour descendre dans un résultat imbriqué (ex.
  `useremail.email` pour un résultat Apollo waterfall). Un opérateur inconnu ou une regex invalide
  **échoue fermé** (la ligne est écartée) plutôt que de planter le job ou de tout laisser passer par
  défaut.
- **`Service/Job/Handlers/ImportContactsFromJobHandler.php`** (`import_contacts_from_job`) — lit par
  lots de 50 les éléments `status=succeeded` d'un **job source déjà terminé**, applique les filtres
  puis le mapping (`alias_champ_contact -> chemin`), et délègue la création/mise à jour à
  `Service/Contact/ContactImporter.php` (même logique de dédoublonnage par email que
  `BulkCreateContactsTool::importContacts()`, extraite dans un service à part plutôt que dans
  `BulkCreateContactsTool` lui-même — pour ne jamais retoucher un outil déjà stable/testé).
- **`start_contacts_import_from_job`** — outil de déclenchement. Contrairement aux trois autres
  `start_*bulk*` (qui ne déclenchent qu'une recherche externe, jamais une écriture Mautic), celui-ci
  crée réellement des contacts : `isWriteOperation()=true`, même flux de confirmation que
  `bulk_create_contacts` — un oubli repéré et corrigé en cours de session (la première version
  laissait passer la création de job sans confirmation, incohérent avec le reste du plugin). Valide
  tout **avant** de créer le job (job source introuvable/pas à l'utilisateur/pas terminé/sans
  résultat exploitable, mapping vide, opérateur de filtre inconnu) : jamais un job voué à échouer dès
  le premier passage de cron.
- **`list_bulk_job_items` reste l'outil de découverte** — l'agent l'appelle d'abord sur le job source
  (`limit=1`) pour voir la forme exacte des données avant d'écrire un mapping, jamais deviné.
- **Un job source `failed` reste exploitable** — bug d'usage réel rencontré en session : une
  recherche QuickEnrich visant 23 603 résultats a planté à 10 000 (erreur fournisseur en cours de
  pagination), passant le job en `status=failed`. Les deux outils exigeaient jusque-là
  `status=completed` strictement, rejetant un job qui contenait pourtant 10 000 résultats
  parfaitement exploitables (`status=succeeded` au niveau des items, indépendant du statut final du
  job parent) — obligeant à tout relancer depuis la page 1 pour rien. Corrigé :
  `StartContactsImportFromJobTool`/`StartCompaniesImportFromJobTool` acceptent désormais `completed`
  **et** `failed` (seul un job encore `queued`/`running` reste refusé, lui encore en train d'écrire).
  La réponse et l'aperçu de confirmation portent alors `partial: true` — l'agent est instruit
  (`PromptBuilder`) de le signaler explicitement à l'utilisateur plutôt que de laisser croire à un
  import complet.

**Deux modes de rapprochement, choisis AUTOMATIQUEMENT selon le type du job source** (jamais laissé
à l'appréciation de l'agent, pour éviter l'erreur) — `ImportContactsFromJobHandler::CONTACT_ID_MATCHED_SOURCE_TYPES` :

- job de **recherche** (Prospeo/QuickEnrich/MCP) — les résultats sont des profils externes, pas
  encore des contacts Mautic → dédoublonnage par email, création si aucun match
  (`ContactImporter::importOne()`, comportement d'origine, inchangé).
- job d'**enrichissement** sur un segment existant (`apollo_bulk_enrich_people`) — `external_ref` du
  job source porte déjà l'id exact du contact Mautic concerné (c'est
  `ApolloBulkEnrichPeopleJobHandler` lui-même qui l'y a mis) → mise à jour **par id**, jamais de
  recherche par email ni de création (`ContactImporter::updateById()`) : un enrichissement met à jour
  un contact qui existe déjà, par définition. Un id devenu introuvable (contact supprimé entre-temps)
  échoue explicitement pour cet élément plutôt que de créer un doublon par erreur.

#### Entreprises : même principe, mais toujours une mise à jour

Mautic n'a pas de notion de "segment d'entreprises" ni de champ d'identité fiable équivalent à
l'email d'un contact (`create_company` lui-même ne fait aucun dédoublonnage) — la source et la
destination du pipeline entreprises sont donc structurellement différentes de celles des contacts :

- **`start_apollo_bulk_enrich_companies`** (`Service/Job/Handlers/ApolloBulkEnrichCompaniesJobHandler.php`,
  type `apollo_bulk_enrich_companies`) — reçoit `company_ids` directement (liste explicite, récupérée
  via `search_companies`/`list_entities` au préalable), pas une requête à construire comme
  `ApolloBulkEnrichPeopleJobHandler::nextLeadIds()`. Les identifiants envoyés à Apollo (`name`,
  `website`) sont dérivés directement des champs déjà connus de chaque `Company` Mautic
  (`Company::getName()`/`getWebsite()`) — jamais redemandés à l'agent, qui a déjà fourni l'id. Même
  corrélation positionnelle stricte et même échec explicite sur désaccord de comptage
  qu'`ApolloBulkEnrichPeopleJobHandler`.
- **`start_companies_import_from_job`** (`Service/Job/Handlers/ImportCompaniesFromJobHandler.php` +
  `Service/Company/CompanyImporter.php`, type `import_companies_from_job`) — **toujours** une mise à
  jour par id (`external_ref` = id d'entreprise Mautic, connu avec certitude puisque fourni au
  lancement de l'enrichissement), **jamais** de création : il n'existe pas de scénario "recherche
  externe → nouvelle entreprise" ici, seulement "entreprise déjà connue → enrichissement". Mêmes
  `mapping`/`filters` déclaratifs, même `isWriteOperation()=true` avec confirmation standard, que la
  version contacts.

#### Garde-fou sur les alias de champ (`FieldWriteGuard`, `list_fields`)

**Bug constaté en production** : après un import de contacts QuickEnrich via `bulk_create_contacts`,
l'agent a affirmé avoir renseigné le lien LinkedIn — en réalité absent de la fiche. Cause trouvée par
lecture directe du code, confirmée contre une vraie base MySQL locale : `LeadModel::setFieldValues()`
(coeur Mautic) ignore silencieusement toute clé qui ne correspond à aucun alias de champ publié,
**sans la moindre erreur**. Or la description même de `bulk_create_contacts` citait `linkedin_url`
comme exemple d'alias Mautic — c'est le nom du paramètre d'entrée des outils d'enrichissement
(Apollo/QuickEnrich/Prospeo), pas un alias Mautic (qui est `linkedin`) : l'agent suivait fidèlement un
exemple erroné dans le prompt de l'outil lui-même. Corrigé dans la description, mais un alias mal
deviné peut se reproduire n'importe quand, pour n'importe quel champ — un correctif de prompt seul
n'aurait garanti sa non-répétition qu'en théorie.

**`Service/Field/FieldWriteGuard.php`**, appelé juste avant chaque `setFieldValues()` du plugin
(`create_contact`, `update_contact`, `bulk_create_contacts`, `create_company`, `update_company`,
`import_leads_from_file`, et les mappings de `start_contacts_import_from_job`/
`start_companies_import_from_job`, validés au lancement du job **et** ré-appliqués à l'écriture de
chaque lot en tâche de fond) :

- **Alias inconnu → erreur explicite**, plus jamais une perte silencieuse. Message renvoyé à l'agent :
  liste des alias en cause + rappel d'appeler `list_fields`.
- **Code pays ISO → nom complet anglais, automatiquement.** Deuxième bug trouvé en creusant le même
  rapport utilisateur (le champ `country` du contact était vide lui aussi) : `country` est un
  `<select>` Mautic dont les choix sont les noms complets anglais de
  `CoreBundle/Assets/json/countries.json` (`"France"`, `"United States"`...), mais QuickEnrich
  raisonne en codes ISO (`country_code`, ex. `"FR"`). La valeur s'enregistre sans erreur (colonne
  `varchar` libre, aucune validation de choix hors formulaire web — vérifié dans
  `RequestTrait::cleanFields()`, aucun cas `country` dans son switch) mais n'apparaît plus dans la
  fiche, le select ne pouvant présélectionner une valeur absente de ses choix. `FieldWriteGuard`
  convertit un code à 2 lettres reconnu (`Symfony\Component\Intl\Countries::getName($code, 'en')`,
  déjà vendorisé par Mautic — vérifié : `FR → France`, `US → United States`, `GB → United Kingdom`,
  correspond exactement à la liste Mautic) avant écriture, pour `country` **et** `companycountry`.
- **`prepareMany()`** — même chose pour un lot (`bulk_create_contacts`, `import_leads_from_file`) :
  les définitions de champ sont récupérées une seule fois pour tout le lot, pas une fois par ligne.

**`list_fields`** (`Service/Tool/Tools/ListFieldsTool.php`) — outil de découverte complémentaire,
demandé explicitement par l'utilisateur plutôt que de se reposer sur le seul rejet réactif : liste les
alias réels d'un contact ou d'une entreprise (`object: contact|company`), avec label/type, et les
valeurs acceptées pour un champ `select`/`multiselect` (ex. `companyindustry`, ~40 secteurs fixes —
un autre champ où une valeur hors liste serait tout aussi silencieusement perdue à l'affichage).
`PromptBuilder` instruit l'agent de l'appeler avant d'écrire un alias incertain, plutôt que de deviner
par analogie avec le nom du champ chez le fournisseur de données.

### Pièces jointes (docs, tableurs, images, polices)

Un bouton trombone dans le chat permet de joindre un fichier (image, CSV/XLS/XLSX, texte, PDF/
Office, police `.woff`/`.woff2`/`.ttf`/`.otf`) à un message — ex. importer une liste de leads,
fournir une image ou une police de marque à réutiliser dans un email/une landing page. L'upload se
fait à la sélection du fichier, pas à l'envoi du message : `POST /witty/upload`
(`WittyController::uploadAction()`) renvoie immédiatement un id, que le front inclut dans
`attachment_ids` au moment d'envoyer le message.

- **`Entity/WittyAttachment.php`** — `conversation` et `message` sont **nullables** : l'upload
  précède forcément la création du message (voire de la conversation, pour un tout nouveau fil).
  Le rattachement se fait par référence d'objet (`AttachmentManager::attachToConversation()`,
  appelé depuis `AgentRunner::run()`), jamais par id scalaire — Doctrine résout l'ordre d'INSERT au
  flush unique de fin de tour, pas besoin de flush intermédiaire.
- **`Service/Attachment/AttachmentManager.php`** — trois destinations selon le type : image/document/
  **police** (`WittyAttachment::KIND_FONT`, `.woff`/`.woff2`/`.ttf`/`.otf`) deviennent un `Asset`
  Mautic **local** (`Asset::setFile()` + `preUpload()` + `upload()`, publié immédiatement —
  contrairement aux objets créés par l'agent, un fichier vient explicitement de l'utilisateur, le
  laisser en brouillon casserait le cas d'usage "image/police utilisable tout de suite dans un
  email") ; tableur/texte restent de simples fichiers de travail sous `media/witty/uploads/`, jamais
  publiés. Une police a besoin de la même URL stable et publique qu'une image (`@font-face` ne peut
  pas référencer un fichier de travail), d'où le même chemin Asset plutôt qu'un simple fichier.
  Extension et taille validées contre une allowlist propre au plugin, plafonnée par la politique
  globale du site (`allowed_extensions`/`max_size`) — **deux verrous, pas un seul** : ajouter une
  extension à `AttachmentManager::ALLOWED_EXTENSIONS` ne suffit pas si la politique globale de
  l'instance (Paramètres > Configuration > onglet Assets > "Extensions de fichiers autorisées",
  `AssetBundle\Form\Type\ConfigType`) ne les liste pas aussi — son défaut Mautic ne contient aucune
  extension de police, à ajouter manuellement une fois par instance.
  **Deux appels non documentés côté Mautic mais indispensables avant `preUpload()`/`upload()`**,
  trouvés en lisant `AssetController`/`PublicController` (core) plutôt que dans le code lui-même :
  `Asset::$uploadDir` et `Asset::$tempId` ne sont **pas des colonnes mappées** (simples propriétés
  PHP, jamais persistées), donc toujours à zéro sur une entité fraîche, contrairement à ce qu'un
  appel à `setFile()`/`preUpload()` laisserait penser. Sans `setUploadDir($coreParametersHelper->get('upload_dir'))` :
  `Asset::getUploadDir()` retombe sur le défaut figé `'media/files'`, un chemin **relatif** résolu
  au CWD du process PHP — le fichier atterrit ailleurs que là où `PublicController::localDownloadResponse()`
  ira le chercher (il refait le même appel, avec la vraie valeur, avant de lire), l'upload
  réussit sans erreur mais l'asset devient introuvable (404) dès qu'on l'utilise. Sans
  `setTempId(uniqid('tmp_', true))` : `Asset::upload()` plante carrément en sortie
  (`Filesystem::remove(getAbsoluteTempDir())`, qui renvoie `null` tant que `tempId` est absent —
  `Filesystem::remove()` n'accepte plus `null` dans les versions récentes de Symfony) — chaque
  import d'image/document échouait donc purement et simplement, avant même la question de savoir où
  le fichier atterrit. Aucun répertoire temporaire n'est réellement créé par notre flux (pas de
  widget d'upload par morceaux) : la valeur de `tempId` importe peu, seule sa présence compte.
- **`read_attachment`** / **`import_leads_from_file`** (`Service/Tool/Tools/`) — l'agent inspecte
  une pièce jointe par son id avant d'agir : texte (contenu tronqué), tableur (en-têtes + aperçu via
  `Service/Attachment/SpreadsheetReader.php`, `phpoffice/phpspreadsheet`, déjà une dépendance
  Mautic), image/document (URL d'asset seulement, pas de lecture textuelle). L'import de leads est
  synchrone et plafonné à 500 lignes plutôt que branché sur le moteur d'import asynchrone natif de
  Mautic (`LeadBundle\Model\ImportModel`, pensé pour un assistant pas-à-pas + cron) — suffisant pour
  une liste de quelques centaines de contacts, largement le cas d'usage visé depuis le chat.
  **Police** — au-delà de l'URL d'asset, `AttachmentManager::previewFont()` renvoie un exemple de
  règle `@font-face` prêt à adapter (`format()` dérivé de l'extension, nom de famille dérivé du nom
  de fichier) : contrairement à une image, une police ne s'utilise pas juste en collant une URL dans
  un `src`. La note rappelle explicitement que ce n'est **pas une Google Font** (donc pas hébergée
  par Google, aucune garantie de cache navigateur partagé) et prévient que le support de
  `@font-face` en email est très inégal (Outlook desktop et la plupart des webmails l'ignorent
  silencieusement — la police de repli s'applique alors, à toujours prévoir dans le CSS) ; fiable en
  revanche sur une landing page, rendue par un vrai navigateur comme n'importe quel site.
- **Mention côté modèle, pas côté affichage** — `ConversationManager::toMessages()` ajoute une note
  `[Pièce jointe : nom (type, id=N)]` au texte envoyé au modèle quand le message a des pièces
  jointes (`WittyMessage::$attachments`, relation `OneToMany` en mémoire, fonctionne même avant
  flush). Le contenu persisté (`WittyMessage::content`) et la bulle affichée
  (`toDisplayTranscript()`) restent exactement ce que l'utilisateur a tapé — aucune duplication
  visuelle de la note technique.
- **Nettoyage** — un fichier joint puis jamais envoyé (onglet fermé avant Envoyer) reste orphelin
  (`conversation` null) ; `php bin/console witty:attachments:prune-orphans` le supprime après 24h
  de grâce (fichier ou Asset, et ligne en base), **sauf s'il est `pinned`** (voir bibliothèque
  Fichiers ci-dessous). À planifier via le cron système, comme `witty:meet:reconcile-attendance`
  (Mautic n'a pas d'ordonnanceur interne).
- **Bibliothèque "Fichiers" (`/witty/files`, `Controller/FileController.php`)** — tout ce qu'un
  utilisateur a déjà envoyé à l'agent, réutilisable dans n'importe quelle conversation future sans
  le rejoindre à nouveau : upload direct depuis cette page (`WittyAttachment::$pinned = true`, donc
  jamais nettoyé automatiquement, contrairement à un upload depuis le trombone du chat) et
  suppression manuelle. L'agent le retrouve par son nom via **`list_attachments`**
  (`WittyAttachmentRepository::findAllForUser()`, filtre `LIKE` sur le nom de fichier) — sans ça,
  il ne connaissait que les pièces jointes du message en cours. Aucune nouvelle notion côté
  `AttachmentManager` : `upload()`/`resolve()`/`assetUrl()` sont déjà scopés par utilisateur, pas
  par conversation, la bibliothèque ne fait que les exposer sans filtre de conversation.
- **Hors scope volontaire** — pas de vision multimodale (le modèle ne "voit" jamais les pixels d'une
  image, seulement son id et son URL d'asset) : aucun des deux cas d'usage demandés (import de
  leads, image dans un email) n'en avait besoin, et ça aurait touché `Service/Llm/*` (DTO + les 4
  providers). Pas d'extraction de texte des PDF/Word non plus (nécessiterait une nouvelle
  dépendance, `smalot/pdfparser`/`phpoffice/phpword`) : un PDF/Word joint devient un Asset
  téléchargeable, mais son contenu textuel n'est pas lu par l'agent.

---

## Développement local

Une instance Mautic jetable s'installe dans `mautic-dev/` (ignorée par git) :

```bash
# une fois, avec les droits root
sudo apt-get install -y php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring \
     php8.4-curl php8.4-zip php8.4-intl php8.4-gd php8.4-bcmath mariadb-server
sudo systemctl enable --now mariadb
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS mautic_dev CHARACTER SET utf8mb4;
                 CREATE USER IF NOT EXISTS 'mautic'@'localhost' IDENTIFIED BY 'mautic';
                 GRANT ALL ON mautic_dev.* TO 'mautic'@'localhost';"

./dev/setup-mautic.sh    # telecharge Mautic, l'installe, deploie le plugin
./dev/serve.sh           # http://127.0.0.1:8000  (admin / WittyLocal-2026-Ardoise)
./dev/sync-plugin.sh     # apres chaque modification du plugin
./dev/build-templates.sh # apres chaque modification d'un .mjml (recompile le HTML)
```

Trois pièges rencontrés, déjà traités par les scripts :

- **Mot de passe admin** — Mautic 7 refuse la *connexion* si zxcvbn note le mot de passe
  sous 3, avec « mautic » dans son dictionnaire. Un `Mautic123!` s'installe sans erreur puis
  rend l'interface inaccessible.
- **Déploiement par copie, pas par lien** — l'instance vit dans le dossier du plugin ; un
  symlink créerait `plugins/WittyBundle → … → plugins/WittyBundle`.
- **Serveur PHP intégré** — il faut un routeur (`dev/router.php`) pour remplacer les règles
  du `.htaccess`, et `PHP_CLI_SERVER_WORKERS` > 1, sinon les requêtes ajax de l'UI se bloquent
  mutuellement.

Pour rejouer les appels en ligne de commande : les POST vers `/s/…` exigent l'en-tête
`X-CSRF-Token` (valeur `mauticAjaxCsrf` présente dans le HTML), en plus du `_token` du
formulaire — `CoreBundle\EventListener\RequestSubscriber` rejette tout POST ajax sans lui.

---

## Architecture

```
Config/config.php          routes et menu
Config/services.php        autowiring, auto-tag des outils, alias mautic.integration.witty
Integration/               intégration Mautic : c'est elle qui rend la fiche du plugin éditable
Form/Type/                 formulaires des onglets Details (clé API) et Fonctionnalités
Themes/                    thèmes Mautic livrés, recopiés dans themes/ à l'installation
Templates/Email/           seed des templates d'email (lu par la migration + les tests, plus par le runtime)
Templates/Page/            seed des templates de landing page (idem)
Entity/                    conversations, messages, skills, templates, journal d'audit (+ repositories)
Migrations/                création des tables sur une instance déjà installée
Service/WittyConfig.php    lecture centralisée de la config de l'intégration
Service/Conversation/      transcript côté serveur, filtré par utilisateur
Service/Audit/             écriture du journal des actions
Service/Usage/             compteur de tokens et quota quotidien
Service/Template/          CRUD des templates (base) et substitution
Service/Theme/             déploiement des thèmes dans themes/
Service/Llm/               3 clients HTTP + normalisation du tool calling
Service/Agent/             boucle de l'agent + prompt système
Service/Tool/              registre + outils (une classe = une capacité)
Controller/                page de chat + endpoint POST
```

### Fiche du plugin

Un bundle Mautic sans classe d'intégration n'est pas configurable : `PluginController::indexAction`
le classe en `isBundle`, la vignette pointe alors vers `mautic_plugin_info` avec `data-footer="false"`,
d'où une modale qui n'affiche que la description, sans champ ni bouton. Quatre pièces sont nécessaires :

1. `Integration/WittyIntegration.php` — le nom du fichier fait foi : `IntegrationHelper` scanne
   `Integration/*Integration.php` et en déduit le nom `Witty`, puis crée la ligne en base.
2. l'alias `mautic.integration.witty` dans `Config/services.php` — clé de service imposée, sinon
   la classe n'est jamais instanciée.
3. `Integration/Support/ConfigSupport.php` — `ConfigFormInterface` fait basculer la modale vers
   `IntegrationsBundle\Controller\ConfigController` (onglets + Sauvegarder / Sauvegarder et fermer),
   `ConfigFormAuthInterface` et `ConfigFormFeatureSettingsInterface` y branchent les deux formulaires.
   Le fichier ne doit pas se terminer par `Integration.php`, sinon il serait vu comme une 2ᵉ intégration.
4. les tags `mautic.basic_integration` / `mautic.config_integration`, posés automatiquement par
   l'`autoconfigure()` de `Config/services.php`.

### Personnalisation visuelle (logo, favicon)

Onglet Fonctionnalités > Affichage de la fiche du plugin : `Form/Type/FeatureSettingsType.php`
(upload) → `Service/Branding/BrandingAssetManager.php` (écriture sur disque) →
`EventListener/BrandingSubscriber.php` (remplacement visuel, en CSS, des pages admin).

**Favicon.** `AssetsHelper::getOverridableUrl()` (core) cherche `media/images/favicon.ico` avant
de retomber sur celui du plugin — c'est ce que `head.html.twig` (core) appelle, donc ça suffit pour
les pages admin (`/s/...`). **Insuffisant pour tout ce qui est public** (landing page, aperçu web
d'un email, page de désabonnement...) : ces gabarits n'incluent jamais `head.html.twig` et ne
posent donc aucun `<link rel="icon">` explicite — le navigateur retombe sur la requête implicite
`/favicon.ico` à la racine du site, servie par le fichier statique du document root de Mautic
(là où vit `index.php`), jamais touché par l'upload. `BrandingAssetManager::storeFavicon()` écrit
donc le fichier aux deux endroits.

**Logo.** Pas de mécanisme d'override natif comme pour le favicon : `navbar.html.twig` (core)
inline le SVG du logo directement en HTML via `source()`, il n'y a pas de fichier sur disque à
remplacer. `BrandingSubscriber` injecte donc un `<style>` en fin de `<head>` de chaque page admin,
qui masque ce SVG et affiche l'image importée en fond à la place. Point d'attention : Mautic rend
**deux** blocs logo dans la navbar, tous deux avec la classe `.mautic-brand` — `.brand-logo--desktop`
(masqué en mobile) et `.brand-logo--mobile` (affiché seulement en mobile, pensé pour un logo
carré). Cibler `.mautic-brand` seul revient à appliquer la même image, potentiellement large, aux
deux emplacements — écrasée sans discernement au premier changement de layout, et facilement
repris par une règle Mautic plus spécifique (`.navbar-nav > .mautic-brand`, spécificité plus
élevée). `BrandingSubscriber` cible donc chaque bloc séparément (`.brand-logo--desktop.mautic-brand`,
`.brand-logo--mobile.mautic-brand`, deux classes = plus spécifique) et passe `!important` sur
chaque propriété, pas seulement `display`. Le bloc mobile reçoit le **favicon**, pas le logo : il
est nativement carré, contrairement au logo (format libre), donc mieux adapté à cet emplacement
carré. Les deux réglages sont indépendants — un favicon seul personnalise déjà le bloc mobile,
même sans logo importé.

### Couche LLM

Les trois fournisseurs ont des formats de *tool calling* incompatibles :

| | Déclaration | Appel | Résultat |
|---|---|---|---|
| Anthropic | `tools[].input_schema` | bloc `tool_use` | bloc `tool_result` dans un message `user` |
| OpenAI | `tools[].function.parameters` | `tool_calls[]` | message `role: tool` |
| Gemini | `functionDeclarations[]` | part `functionCall` | part `functionResponse` |

Tout est normalisé vers `Dto\Message` / `Dto\ToolCall`, et chaque provider traduit dans son
propre format. Ajouter un quatrième fournisseur = une classe implémentant `LlmProviderInterface`.

Gemini refuse `additionalProperties` et certains mots-clés JSON Schema : `sanitizeSchema()`
les retire pour ce provider uniquement.

### Ajouter un outil

Déposer une classe dans `Service/Tool/Tools/` implémentant `ToolInterface` (ou étendant
`AbstractTool`). L'autoconfiguration la tague `witty.tool`, le registre la découvre, le modèle
la voit. Aucune modification de config nécessaire.

Trois points appliqués par le registre :

- **Permissions** — chaque outil déclare sa permission Mautic (`getRequiredPermission()`).
  Un outil dont l'utilisateur connecté n'a pas le droit n'est même pas exposé au modèle.
  Ce que l'utilisateur ne peut pas faire à la main, l'agent ne peut pas le faire non plus.
- **Confirmation** — les outils en écriture renvoient d'abord `status: confirmation_required`
  avec un aperçu. Le modèle doit obtenir un accord explicite puis rappeler l'outil avec
  `confirmed: true`. Désactivable en configuration.
- **Non-publication** — tout ce qui est créé l'est en brouillon.

Pour un outil qui n'agit pas sur Mautic mais relaie un serveur MCP externe (voir [Recherche et
navigation web](#recherche-et-navigation-web-bright-data-mcp)), implémenter `McpClientInterface`
plutôt que `ToolInterface` : le registre décrit ses outils lui-même, il n'y a rien à écrire par
outil distant.

### Outils disponibles

`list_entities` / `update_entity` / `delete_entity` sont génériques : ils s'appuient sur
`EntityCatalog`, qui couvre email, page, segment, campaign, form, asset, dynamic_content, stage,
point, point_trigger, point_group, report, project, message (Channels) et **category**. Ajouter un
type au catalogue le rend automatiquement listable/renommable/supprimable sans toucher ces trois
outils.

| Outil | Écriture | Rôle |
|---|:---:|---|
| `list_entities` | | Liste n'importe quel type du catalogue (voir ci-dessus) |
| `update_entity` | ● | Renomme, décrit, (dé)publie un objet existant, tous types du catalogue ; `category_id` pour les types qui en portent une |
| `read_entity_content` | | Lit le HTML actuel d'un email/page existant, tous modes (avertit si theme visuel/MJML) |
| `update_entity_content` | ● | Remplace le HTML d'un email/page existant en entier, en place — mode code source uniquement |
| `replace_entity_content_text` | ● | Remplace toutes les occurrences d'une chaîne exacte dans le HTML d'un email/page — tous modes ; plusieurs appels = refonte visuelle complète en thème visuel/MJML |
| `delete_entity` | ● | Suppression définitive, tous types du catalogue |
| `create_category` | ● | Catégorie Mautic rattachée à un type précis (bundle) : email, page, segment, campaign, form, asset, stage, point, dynamic_content, message, meet_room |
| `list_email_templates` | | Templates d'email (section Witty > Templates) + consigne de rédaction de chaque emplacement |
| `create_email_from_template` | ● | Email construit à partir d'un template de la section Templates |
| `list_page_templates` | | Templates de landing page (section Witty > Templates) + consigne de chaque emplacement |
| `create_page_from_template` | ● | Landing page construite à partir d'un template, toujours en mode code source |
| `create_template` | ● | Crée un template (email/page) dans la bibliothèque partagée — uniquement sur demande explicite, jamais spontané |
| `update_template` | ● | Modifie un template existant (type + key), chaque champ fourni remplace l'existant en entier |
| `delete_template` | ● | Supprime définitivement un template, `confirmed: true` obligatoire même hors mode confirmation |
| `read_skill` | | Charge le contenu complet d'un skill (nom+description déjà dans le prompt système en permanence) |
| `create_skill` | ● | Crée un skill (playbook/stratégie) dans la bibliothèque partagée — uniquement sur demande explicite |
| `update_skill` | ● | Modifie un skill existant (identifié par son nom exact), chaque champ fourni remplace l'existant en entier |
| `create_email` | ● | Email template ou list |
| `create_email_variant` | ● | Variante de test A/B d'un email existant (vrai mécanisme Mautic, pas un second email indépendant) |
| `update_email_settings` | ● | Modifie les réglages d'un email déjà créé hors contenu/nom/publication : expéditeur, subject, preheader, UTM, texte brut, fenêtre de publication |
| `create_landing_page` | ● | Landing page |
| `send_test_email` | ● | Exemplaire de test, aucun contact touché |
| `create_form` | ● | Formulaire + champs, avec mapping vers les champs contact |
| `read_form` | | Détail complet d'un formulaire existant (champs avec alias, actions avec id) — préalable à update_form |
| `update_form` | ● | Ajoute/modifie/supprime des champs et des actions d'un formulaire déjà créé, un par un (op=add\|update\|remove) |
| `create_segment` | ● | Segment + filtres |
| `search_contacts` | | Recherche de contacts |
| `list_fields` | | Alias, label, type et valeurs acceptées des champs contact/entreprise réellement définis dans Mautic |
| `create_contact` | ● | Contact (refuse le doublon d'email) |
| `update_contact` | ● | Champs d'un contact existant, id ou email |
| `manage_contact_segments` | ● | Ajoute/retire un contact d'un ou plusieurs segments |
| `bulk_create_contacts` | ● | Crée/met à jour jusqu'à 500 contacts d'un coup (ex. depuis une recherche `prospeo_*`/un enrichissement Apollo) et les rattache à un segment |
| `enrich_person` | | Enrichit un profil via Apollo (titre, entreprise, email si `reveal_personal_emails`) |
| `bulk_enrich_people` | | Enrichit jusqu'à 10 profils via Apollo en un appel |
| `enrich_company` | | Enrichit une entreprise via Apollo (industrie, taille, technologies) |
| `bulk_enrich_companies` | | Enrichit jusqu'à 10 entreprises via Apollo en un appel |
| `enrich_person_waterfall` | | Lance un enrichissement Apollo approfondi (email et/ou téléphone selon `mode`) — asynchrone, renvoie un `request_id` |
| `check_waterfall_enrichment` | | Récupère le résultat d'un `enrich_person_waterfall` (par `request_id`, `contact_id`, ou historique récent) |
| `quickenrich_search_contacts` | | Recherche de contacts dans la base externe QuickEnrich (gratuite), pas dans Mautic |
| `quickenrich_list_filter_values` | | Valeurs exactes acceptées par une dimension à correspondance stricte de la recherche QuickEnrich |
| `quickenrich_find_employee_email` | | Révèle l'email d'un employé déjà identifié via QuickEnrich |
| `quickenrich_find_employee_phone` | | Révèle le téléphone d'un employé déjà identifié via QuickEnrich (1 crédit si trouvé) |
| `start_apollo_bulk_enrich_people` | | Lance en arrière-plan un enrichissement Apollo sur tous les contacts d'un segment |
| `start_apollo_bulk_enrich_companies` | | Lance en arrière-plan un enrichissement Apollo sur une liste d'entreprises Mautic existantes |
| `start_quickenrich_bulk_search` | | Lance en arrière-plan une recherche QuickEnrich paginée jusqu'à `target_count` résultats |
| `start_quickenrich_bulk_enrich_people` | | Révèle email/téléphone QuickEnrich sur tout un segment Mautic (contacts avec un lien LinkedIn) |
| `start_bulk_mcp_search` | | Lance en arrière-plan la pagination d'un outil MCP (Prospeo, data.gouv.fr) |
| `check_bulk_job` | | Consulte la progression d'un job de fond |
| `cancel_bulk_job` | | Annule un job de fond `queued`/`running` (rien n'est effacé, reprenable via `resume_bulk_job`) |
| `resume_bulk_job` | | Relance un job de fond `failed` ou `cancelled` exactement où il s'est arrêté (curseur intact), plafonné à 5 tentatives |
| `list_bulk_job_items` | | Récupère une page de résultats d'un job de fond, à revoir avant application |
| `start_contacts_import_from_job` | ● | Convertit/enrichit en arrière-plan les résultats d'un job terminé en contacts Mautic (mapping/filtres déclaratifs, met à jour par id si le job source est un enrichissement) |
| `start_companies_import_from_job` | ● | Applique en arrière-plan les résultats d'un job d'enrichissement d'entreprises terminé sur les entreprises Mautic correspondantes (toujours une mise à jour, jamais une création) |
| `manage_tags` | ● | Liste les tags, en pose/retire sur un contact, ou en supprime un définitivement |
| `search_companies` | | Recherche d'entreprises |
| `create_company` | ● | Entreprise |
| `update_company` | ● | Champs d'une entreprise existante |
| `manage_company_contacts` | ● | Rattache/détache un contact d'une entreprise |
| `create_campaign` | ● | Campagne + canvas |
| `describe_campaign_events` | | Catalogue des événements de campagne installés |
| `campaign_stats` | | Contacts et avancement par événement |
| `create_asset` | ● | Asset à partir d'une URL distante (pas d'upload binaire possible depuis le chat) |
| `create_dynamic_content` | ● | Bloc de contenu conditionnel, filtres façon segment |
| `create_stage` | ● | Étape du cycle de vie |
| `create_project` | ● | Dossier transverse |
| `create_point_action` | ● | Action de scoring (types lus sur l'instance) |
| `create_point_group` | ● | Groupe de points indépendant du score global |
| `create_point_trigger` | ● | Déclencheur : actions exécutées à un seuil de points (types lus sur l'instance) |
| `create_report` | ● | Rapport tabulaire : source + colonnes (découverte via `list_sources`/`list_columns`) |
| `run_report` | | Exécute un rapport existant, données plafonnées à 50 lignes |
| `create_message` | ● | Message multi-canal (menu Channels), canaux découverts via `list_channels` |
| `list_active_meet_rooms` | | Salles plugNmeet actuellement actives |
| `create_meet_room` | ● | Salle plugNmeet, ouverte indéfiniment jusqu'à `end_meet_room` ; catégorie/projets/tags optionnels |
| `update_meet_room` | ● | Catégorie/projets/tags d'une salle existante — `update_entity` ne couvre pas les salles |
| `end_meet_room` | ● | Termine une salle active, déconnecte tout le monde |
| `get_meet_room_participants` | | Qui est connecté à une salle active |
| `generate_meet_join_link` | ● | Lien de connexion ponctuel (nom libre, pas forcément un contact) |
| `create_meet_invitation` | ● | Lien d'invitation personnel pour un contact, avec suivi de présence |
| `list_past_meet_rooms` | | Historique des salles terminées |
| `list_meet_recordings` | | Enregistrements et/ou artefacts plugNmeet |
| `delete_meet_recording` | ● | Supprime un enregistrement ou un artefact, définitif |
| `convert_meet_recording_to_asset` | ● | Republie un enregistrement comme Asset Mautic (partageable par email) |
| `read_attachment` | | Lit une piece jointe du chat (texte, apercu de tableur, ou URL d'asset pour image/document) |
| `list_attachments` | | Retrouve un fichier deja envoye par son nom (bibliotheque Fichiers), pas seulement ceux du message en cours |
| `import_leads_from_file` | ● | Import de contacts depuis un tableur joint, plafonne a 500 lignes, mapping de colonnes fourni par l'agent |

`update_entity` et `delete_entity` acceptent plusieurs types d'objets : leur permission ne peut
donc pas être déclarée une fois pour toutes sur l'outil, elle est vérifiée **objet par objet**
via `EntityCatalog::isAllowed()` (`hasEntityAccess`, own/other, ou permission plate pour project
qui n'a pas de notion de propriétaire). `read_entity_content`/`update_entity_content` réutilisent
la même méthode avec l'opération `'view'`/`'edit'` — `isAllowed()` n'a rien de spécifique à
edit/delete, n'importe quel suffixe de permission Mautic standard (`viewown`, `viewother`...)
fonctionne puisque la substitution dans `EntityCatalog::MAP` est générique.

**Modifier le contenu d'un email/page existant** — `update_entity` exclut volontairement le HTML
(voir sa docblock) : avant `read_entity_content`/`update_entity_content`/`replace_entity_content_text`,
la seule façon de changer le contenu d'un email ou d'une page déjà créé était de le supprimer et
d'en recréer un autre, avec un nouvel id, en perdant ses statistiques et ses éventuelles références
dans une campagne. `PromptBuilder` pousse explicitement l'agent vers ces outils plutôt que
delete+create dès qu'un email/page existe déjà.

**Modifier les réglages (hors contenu) d'un email existant** — même angle mort qu'au-dessus, pour la
même raison : `update_entity` reste générique par construction (`setName`/`setDescription`/
`setIsPublished`/`setCategory`, présents sur tous les types du catalogue), alors que
`setFromAddress`/`setSubject`/`setUtmTags`/`setPreheaderText`/`setPlainText`/`setPublishUp`/
`setPublishDown`/etc. n'existent que sur `Email`. `create_email` acceptait déjà certains de ces
champs à la création (from_name/from_address), mais rien ne permettait de les changer une fois
l'email créé, et aucun outil ne couvrait `subject`/le pré-header/les tags UTM/le texte brut/la
fenêtre de publication à aucun moment — d'où `update_email_settings`.

**Un seul outil pour tout ça, volontairement** — la première version distinguait un
`update_email_sender` (expéditeur) d'un futur outil séparé pour le reste (subject, UTM...), mais
plusieurs petits outils aux frontières proches (tous "modifient un réglage d'email existant") se
seraient révélés une source de confusion supplémentaire pour le modèle, pas une clarification :
fusionnés en un seul `update_email_settings` avant même la première livraison.

- **`subject` ne peut jamais être vide** — contrairement aux autres champs texte (from_name,
  from_address, reply_to_address, bcc_address, preheader_text, plain_text), qui s'effacent en `null`
  sur une valeur vide : un email sans objet n'a pas de sens, l'outil refuse plutôt que d'accepter.
- **`utm_tags` remplace tout le tableau existant** (objet vide `{}` pour tout retirer), comparé
  indépendamment de l'ordre des clés (`ksort()` des deux côtés avant comparaison) pour ne pas
  déclencher un aller-retour de confirmation inutile sur un réordonnancement sans effet réel.
- **`publish_up`/`publish_down`** attendent une chaîne (`"YYYY-MM-DD HH:MM:SS"`), convertie en
  `\DateTime` avant d'être passée à Mautic (mapping Doctrine `DATETIME_MUTABLE`, une chaîne brute
  romprait la conversion au `flush()`) ; une date invalide est rejetée avant tout appel à
  `saveEntity()`, une chaîne vide retire la borne.
- Comme pour l'expéditeur seul : une valeur vide efface un champ blanquable en `null` (jamais une
  chaîne vide littérale), une valeur identique à l'actuelle n'est jamais comptée comme une
  modification.

**Modifier un formulaire existant (champs, actions)** — même angle mort qu'au-dessus, découvert en
usage réel : `create_form` savait créer un formulaire avec ses champs et ses actions, mais rien ne
permettait de changer une action déjà en place (ex. l'adresse destinataire d'une action "Envoyer un
email brut") — la seule option était de supprimer tout le formulaire et d'en recréer un autre,
perdant son id, ses soumissions déjà reçues et ses éventuelles références dans une campagne. D'où
`read_form` + `update_form`.

- **`Service/Form/FormDefinitions.php`/`FormPropertyBuilder.php`** — les types de champ/action et la
  construction des `properties` (un `match()` par type, ex. `select`/`radiogrp`/`checkboxgrp`
  partagent la même forme `{syncList, list}`, `form.email` porte `to`/`cc`/`bcc`...) étaient
  dupliqués dans `CreateFormTool` seul ; extraits en services partagés pour qu'`update_form` s'appuie
  sur exactement la même logique plutôt qu'une copie pouvant diverger avec le temps.
  `CreateFormTool` a été refactoré pour les utiliser, sans changement de comportement.
- **`read_form` est un préalable, pas une option** — ni les champs ni les actions ne sont indexés de
  façon stable une fois le formulaire rechargé depuis la base : `Form::$fields`/`Form::$actions`
  sont mappés `indexBy('id')` côté Doctrine (vérifié dans `FormBundle/Entity/Form.php`), donc
  `$form->getFields()->get($alias)` ne fonctionne PAS après un rechargement, contrairement à ce que
  `CreateFormTool` utilise en interne à la création (avant tout id, alias comme clé mémoire
  seulement). `update_form` retrouve donc un champ/une action en **parcourant** la collection et en
  comparant `alias`/`id`, jamais par accès direct à une clé.
- **Suppression = `EntityManagerInterface::remove()` explicite, pas un simple retrait de la
  collection** — `Form::fields`/`Form::actions` sont en cascade `persist/remove/detach/merge/refresh`
  mais **sans** `orphanRemoval` (absent de la config `createOneToMany()` dans le core Mautic) :
  retirer un champ/une action de la collection (`removeElement()`) sans appeler `remove()` dessus
  explicitement laisserait la ligne orpheline en base au lieu de la supprimer. `update_form` appelle
  toujours les deux, dans cet ordre.
- **Mise à jour toujours PARTIELLE** — `fields`/`actions` acceptent un tableau d'opérations
  (`op=add|update|remove`), chacune ne touchant que les champs explicitement fournis : changer
  `email_to` sur une action `form.email` ne réinitialise jamais `subject`/`message`/`cc`/`bcc`. Pour
  ça, une mise à jour reconstruit les propriétés en fusionnant l'existant
  (`actionPropertiesAsDefinition()`, qui relit `getProperties()` et le remet dans la forme attendue
  par `FormPropertyBuilder`) avec ce que l'appelant fournit — jamais un rafistolage clé par clé, qui
  casserait facilement la cohérence d'un type d'action (ex. `email.send.user` porte `email_id` ET
  `user_ids` ensemble).
- **Changer le `type` d'une action existante redevient une validation stricte** (comme un `add`) —
  les champs obligatoires du nouveau type ne sont probablement pas ceux de l'ancien
  (`email.send.user` exige `email_id`+`user_ids`, `lead.changetags` n'exige rien) : accepter un
  changement de type sans revalider laisserait une action mal formée.
- **Vérifié contre une vraie base MySQL locale** avant d'écrire le code définitif : un formulaire
  temporaire avec un champ et une action a confirmé, dans cette session, que `removeElement()` seul
  ne supprime pas la ligne (`orphanRemoval` bien absent) et qu'un `remove()` explicite le fait —
  données de test nettoyées ensuite.

`Email::customHtml`/`Page::customHtml` sont **toujours** ce qui part réellement au
destinataire/visiteur, quel que soit `template` — vérifié dans `MailHelper::setEmail()` (core) :
le rendu thème+blocs (`Email::getContent()`) n'est un repli que si `customHtml` est vide, un cas
de compatibilité Mautic v1 devenu quasi inexistant. `read_entity_content` retourne donc toujours
ce champ, avec un `warning` (pas un refus) si `template` n'est ni `''`, `'blank'` ni
`'mautic_code_mode'` (thème visuel/MJML, celui que `create_email`/`create_landing_page`/
`create_*_from_template` évitent systématiquement) : le HTML reflète bien ce qui est envoyé, mais
un remplacement **intégral** de ce champ divergerait fortement de ce qu'un humain reverrait en
rouvrant l'éditeur visuel/MJML (qui, lui, se base sur `Email::content`/la source MJML, pas sur
`customHtml`, pour l'édition) — d'où `update_entity_content` qui refuse ce cas plutôt que de créer
ce décalage silencieusement.

`replace_entity_content_text` lève cette dernière limite pour un remplacement **chirurgical**
(chaque appel remplace TOUTES les occurrences de `search`, pas une seule) : contrairement à une
réécriture intégrale, il ne risque pas de désynchroniser l'éditeur visuel/MJML de façon
significative. Le cas d'usage qui a motivé son ajout (remplacer une URL de logo placeholder dans
plusieurs emails déjà créés) en suggérait un usage ponctuel, mais l'outil n'est pas limité à ça :
**une refonte visuelle complète** d'un email/page en thème visuel/MJML s'obtient avec plusieurs
appels d'affilée, un par valeur de design distincte (l'ancienne couleur de fond → la nouvelle,
l'ancien `font-family` → le nouveau, l'ancien `border-radius` → le nouveau...) — le HTML compilé
par MJML répète ses styles en inline sur chaque élément plutôt que dans une seule feuille `<style>`
centralisée (contrairement au mode code source), donc pas de remplacement unique possible, mais
rien n'empêche une dizaine d'appels ciblés d'aboutir au même résultat qu'un remplacement intégral.
`PromptBuilder` guide maintenant explicitement l'agent vers cette méthode : la première version de
cette consigne ne mentionnait qu'un usage "retouche ponctuelle", ce qui a fait dire à l'agent qu'une
refonte de design complète était "impossible via l'API" pour ce type d'objet — une limite de
consigne, pas de capacité réelle.

Pour un email avec une source MJML enregistrée (`Entity/GrapesJsBuilder`, table
`bundle_grapesjsbuilder`, plugin GrapesJS — cf. le mécanisme historique de
`create_email_from_template` avant qu'il ne passe au HTML pur), le même remplacement texte est
aussi appliqué à cette source, pour que le builder MJML ne rouvre pas sur une version périmée.
Best-effort et jamais bloquant : si le plugin GrapesJS est absent ou qu'aucune source n'existe pour
cet email, `mjml_synced` vaut simplement `false` — le HTML réellement envoyé est de toute façon déjà
corrigé à ce stade.

**Categories** — contrairement aux autres types, la permission d'une catégorie dépend du *bundle*
auquel elle appartient (`email:categories:create`, `page:categories:create`... plutôt qu'une
permission `category:*` unique) : c'est le seul type dont `EntityCatalog::isAllowed()` calcule la
permission dynamiquement à partir de `Category::getBundle()`, et `create_category` vérifie
`isCategoryCreateAllowed(bundle)` séparément puisqu'aucune entité n'existe encore à ce stade. Une
catégorie n'appartient qu'à **un seul** bundle (contrainte `Category::$bundle`, colonne
`NOT NULL`) : `update_entity(category_id=...)` refuse d'assigner une catégorie "email" à un
segment. Les clés `bundle` Mautic ne correspondent pas toutes au nom du type côté plugin —
`dynamic_content` → `dynamicContent`, `message` → `messages` (voir `EntityCatalog::CATEGORY_BUNDLE`)
— et certains types (`point_trigger`, `point_group`, `report`, `project`) n'ont tout simplement
pas de champ catégorie côté Mautic core.

**Salles plugNmeet (meet_room)** — `Entity/WittyRoom.php` porte bien catégorie/projets/tags, mais
n'est pas un type `EntityCatalog` (pas de Model Mautic standard derrière, gérée par
`Service/Videoconference/RoomManager.php`) : `update_entity` ne peut donc pas la cibler, d'où
`update_meet_room` en tool dédié. `create_meet_room` ne créait jusqu'ici que la salle côté
plugNmeet — jamais la `WittyRoom` correspondante — donc catégorie/projets/tags n'avaient tout
simplement aucun endroit où s'attacher, à la création comme après coup ; corrigé en alignant
`CreateMeetRoomTool` sur `VideoconferenceController::roomsCreateAction()` (même séquence
`TaxonomyOptionsProvider::resolve*()` + `RoomManager::save()`). `create_category(bundle=meet_room)`
et `EntityCatalog::isAllowed()`/`isCategoryCreateAllowed()` court-circuitent volontairement la
vérification de permission pour le bundle `witty_room` : aucune permission Mautic dédiée n'existe
pour les salles (`CreateMeetRoomTool`/`EndMeetRoomTool` n'ont pas de `getRequiredPermission()` non
plus), leur imposer `witty_room:categories:*` bloquerait tout le monde en permanence. Une salle
plugNmeet créée avant ce correctif (ou ouverte directement sur plugNmeet, hors de l'agent) n'a pas
encore de `WittyRoom` : `update_meet_room` en crée une à la volée au lieu d'échouer.

**Test A/B email (`create_email_variant`)** — le vrai mécanisme Mautic (onglet A/B Test de la
fiche email, répartition du trafic à l'envoi, détermination automatique du gagnant) repose sur
`Email::variantParent`/`variantChildren`/`variantSettings`
(`Mautic\CoreBundle\Entity\VariantEntityTrait`, partagé avec Page) : deux emails créés séparément
via `create_email` n'ont **aucun lien** entre eux, même en les nommant pareil, l'agent produisait
donc deux emails indépendants plutôt qu'un test A/B. `create_email_variant` reproduit exactement
`EmailController::abtestAction()` (bouton "Créer un test A/B" de l'interface) : un nouvel `Email`
dont `variantParent` pointe vers l'original. Deux règles que Mautic core ne valide qu'à
l'affichage (`EmailController::indexAction()`, pas au moment de la sauvegarde) sont ici appliquées
dès la création : la somme des `weight` de toutes les variantes d'un même test ne peut pas dépasser
100 (le reste revient à l'email d'origine), et toutes les variantes doivent partager le même
`winnerCriteria`. Donner l'id d'une variante existante en `parent_email_id` résout automatiquement
la vraie racine du test (même logique que `Email::getVariants()`) — Mautic core refuse même de
démarrer un nouveau test depuis une variante, ce détail est donc transparent pour l'agent plutôt
que de lui renvoyer une erreur à contourner lui-même.

`delete_entity`, `manage_tags` (action=delete), `end_meet_room` et `delete_meet_recording`
exigent `confirmed: true` **même si le mode confirmation global est désactivé** : ce sont les
seules actions réellement irréversibles (suppression, ou déconnexion immédiate de tous les
participants).

Contact et Company n'entrent pas dans `EntityCatalog` (pas de notion de publication, champs
personnalisés au lieu de name/description) : ils gardent leurs outils dédiés
(`create_contact`/`update_contact`, `create_company`/`update_company`/`search_companies`).

Ce tableau ne liste que les outils Mautic locaux, connus à la compilation. Des outils supplémentaires
apparaissent en plus, découverts en direct sur leur serveur MCP respectif, selon la configuration :
`brightdata_*` (recherche web, scraping) si une clé Bright Data est renseignée — voir [Recherche et
navigation web](#recherche-et-navigation-web-bright-data-mcp) — `prospeo_*` (recherche/enrichissement
B2B) si une clé Prospeo est renseignée — voir [Recherche de prospects B2B](#recherche-de-prospects-b2b-prospeo-mcp)
— et `datagouv_*` (données publiques françaises) si activé, sans clé — voir [Données
publiques](#données-publiques-datagouvfr-mcp).

### Thèmes d'email

Le plugin livre des thèmes Mautic, sélectionnables dans le sélecteur au moment de créer un email :

| Thème | Dossier | Usage |
|---|---|---|
| Webinar Last | `Themes/webinar-last/` | Relance / dernier avis avant un webinar. Texte d'exemple rempli, prêt à adapter. |
| Webinar Day 0 | `Themes/webinar-day0/` | Premier email d'annonce. Structure plus longue (P.A.S. + citations verbatim), 2 CTA au lieu de 3, aucune fausse urgence — la relance est le rôle de Webinar Last. Emplacements laissés `[ENTRE CROCHETS]` avec la consigne de rédaction en commentaire HTML au-dessus de chaque bloc. |

```
Themes/<cle>/config.json           nom affiché, builder, fonctionnalités
Themes/<cle>/html/email.html.twig  le MJML du thème
Themes/<cle>/html/message.html.twig
Themes/<cle>/thumbnail.png         vignette du sélecteur
```

Ajouter un thème = déposer un dossier avec ces quatre fichiers. `ThemeInstaller` et
`ThemeIntegrityTest` parcourent `Themes/*` par `glob` : rien d'autre à déclarer.

`ThemeHelper` ne scanne que le dossier `themes/` de Mautic : un thème resté dans le plugin est
invisible. Le `PluginSubscriber` le recopie donc à l'installation **et à chaque mise à jour** du
plugin (`mautic:plugins:reload`), et `php bin/console witty:themes:install` rejoue l'opération à
la main (`--keep-existing` pour ne pas écraser).

Trois pièges du format, tous couverts par `Tests/Theme/ThemeIntegrityTest.php` :

- **le fichier passe par Twig** : une double accolade y serait évaluée comme une variable et le
  texte disparaîtrait sans erreur. Les tokens Mautic (`{contactfield=…}`, `{unsubscribe_url}`)
  n'ont qu'une accolade et traversent Twig intacts — les emplacements du thème, eux, doivent
  rester en texte simple ou en `[CROCHETS]`, jamais en double accolade ;
- **`email.mjml.twig` est déprécié** dans le code de Mautic (`GrapesJsController`) : le MJML va
  directement dans `html/email.html.twig`, le contrôleur bascule en mode MJML dès qu'il voit
  `<mjml>` ;
- sans `config.json` valide, le thème n'apparaît simplement pas dans le sélecteur.

Une mise à jour du plugin **écrase** `themes/<clé>/`. Pour partir d'un de ces thèmes et le
personnaliser, le dupliquer d'abord dans *Paramètres › Thèmes* : la copie n'est plus touchée.

### Section Templates (Witty > Templates)

Les templates d'email et de landing page que l'agent utilise (`list_email_templates` /
`create_email_from_template`, `list_page_templates` / `create_page_from_template`) sont gérés
depuis une section dédiée de l'UI Mautic, pas livrés en fichiers : `Entity/WittyTemplate.php`
(table `witty_templates`), `Service/Template/TemplateManager.php` (CRUD) et
`Controller/TemplateController.php` (preview/modifier/supprimer/créer, même mécanique que la
section Skills). Un template = un `type` (`email` ou `page`), une `key` (identifiant utilisé par
l'agent, dérivée du nom si laissée vide), un `goal`/des `rules`/des `placeholders` (même structure
que l'ancien `manifest.json`, en JSON) et un champ `html`.

**L'agent peut aussi créer/modifier/supprimer un template lui-même** (`create_template` /
`update_template` / `delete_template`, `Service/Tool/Tools/`) — exactement comme les 4 templates
d'origine ont été produits : un humain fournit un email/page réel (ou un exemple) et demande à
l'agent d'en extraire un template réutilisable, plutôt que d'écrire le HTML et le JSON des
emplacements à la main. Ces trois outils touchent la bibliothèque **partagée par toute
l'instance** — contrairement à un email/page ponctuel, un template devient une brique
d'infrastructure que l'agent lui-même réutilisera ensuite pour tout email/page futur — d'où deux
garde-fous supplémentaires : `PromptBuilder` interdit explicitement à l'agent de les appeler de sa
propre initiative (uniquement sur demande explicite de l'utilisateur, jamais en marge de la
rédaction d'un email ponctuel), et `delete_template` exige `confirmed: true` **même si le mode
confirmation global est désactivé**, même règle que `delete_entity`/`manage_tags(delete)` (voir
plus bas). `update_template`/`create_template`, eux, suivent le mode confirmation global comme le
reste des outils d'écriture. Les trois identifient leur cible par `(type, key)`, jamais par id
numérique — c'est tout ce que `list_email_templates`/`list_page_templates` exposent déjà à l'agent.

**Le code est toujours du HTML final, y compris pour un email.** PHP ne sait pas compiler du
MJML : le compilateur officiel tourne en Node (`dev/build-templates.sh`) ou, côté builder Mautic,
dans le navigateur (`grapesjs-mjml`) — aucune des deux options n'est disponible pour une
sauvegarde faite depuis un formulaire serveur, et le plugin n'ajoute pas de dépendance Node pour
ça. Un template d'email s'écrit donc directement en HTML, structurellement comme un template de
landing page (aucune compilation dans les deux cas, que le HTML soit écrit à la main ou produit par
un template) : côté email, les tokens Mautic (`{contactfield=...}`, `{unsubscribe_url}`...)
fonctionnent quelle que soit l'origine du HTML, ce sont de simples chaînes substituées par
`MailHelper` à l'envoi. **Ne pas en déduire qu'ils fonctionnent pareil sur une landing page** :
`{contactfield=...}` n'y est JAMAIS substitué (vérifié dans `PageBundle\Controller\PublicController` —
aucune passe de remplacement des tokens de champ contact sur le HTML d'une page, contrairement à un
email toujours adressé à un contact connu ; seul `{pagelink=...}` est un vrai token de page,
`PageBundle\Helper\TokenHelper`, sans rapport). Pour personnaliser une landing page, seul le Dynamic
Content de Mautic (blocs conditionnels par segment) ou du JavaScript côté client lisant le cookie de
tracking fonctionnent — jamais un merge tag serveur. Conséquence assumée par ailleurs :
contrairement à l'ancien mécanisme (MJML source enregistré dans `bundle_grapesjsbuilder` à la
création de l'email), un email créé depuis un template géré ici n'est plus éditable dans le builder
MJML — seul le HTML brut.

**La substitution est faite par le plugin, pas par le modèle.** Demander à un LLM de recracher
1 200 lignes de HTML compilé sans faute n'est pas fiable : il fournit le texte de chaque bloc,
le plugin garantit la structure. Trois garde-fous, communs aux deux types de template :

- toutes les valeurs sont échappées (`htmlspecialchars`) — le modèle produit du texte, pas du
  balisage. Vérifié : `<script>alert(1)</script>` ressort en `&lt;script&gt;` ;
- `REGISTRATION_URL`, `LOGO_URL` et `HERO_IMAGE_URL` doivent être en `http(s)` — un
  `javascript:` est refusé avant d'atteindre le `href` ;
- seuls les emplacements déclarés dans `placeholders` sont substitués, et un emplacement
  obligatoire manquant fait échouer l'appel avec la liste des clés à fournir.

Les consignes de `placeholders` (le *pourquoi* de chaque bloc, l'exemple, ce qu'il ne faut pas
écrire) sont renvoyées au modèle par `list_email_templates`/`list_page_templates`. C'est ce qui
fait la différence entre un email structuré et un email générique : sans elles, le modèle remplit
les cases au jugé — donc plus le manifeste d'un template créé depuis l'UI est soigné, mieux
l'agent le remplit.

`Migrations/Version_2_8_0.php` a repris dans `witty_templates` les 4 templates jusqu'ici livrés en
fichiers (`Templates/Email/`, `Templates/Page/`, lus via
`Service/Template/BuiltInTemplateLoader.php`) : ils restent disponibles à l'agent après la mise à
jour, et deviennent immédiatement modifiables/supprimables comme n'importe quel template créé
depuis l'UI. Ces dossiers ne sont plus lus à l'exécution — uniquement par cette migration (une
seule fois) et par les tests de `Tests/Service/Template/` (fixtures réalistes, sans base de
données).

| Template | Type | Dérivé de | Emplacements |
|---|---|---|---|
| `webinar` | email | thème `webinar-last` | 26 (24 obligatoires) — logo et visuel avec valeur par défaut |
| `webinar-day0` | email | thème `webinar-day0` | 27 — annonce day-0, structure P.A.S. + 2 citations verbatim |
| `confirmation-webinar` | page | — (jamais un thème, JS fonctionnel) | 37 (24 obligatoires) |
| `webinar-landing` | page | thème `landing-webinar` | 66 (la majorité obligatoires — page d'inscription complète) |

`HOOK` (dans `webinar-day0`) est le premier emplacement à avoir utilisé le contexte `html_br` : la
consigne autorise explicitement un `<br/>` pour une accroche sur deux lignes (voir le tableau des
contextes ci-dessous). Testé pour confirmer qu'un `<img onerror=...>` glissé dans le même champ
reste neutralisé, sans casser les vraies balises `<img>` du reste de l'email (logo, visuel
d'accroche).

### Détails spécifiques à la landing page

Même principe que l'email pour la substitution (`list_page_templates` /
`create_page_from_template`, `Service/Template/PageTemplateLibrary.php`) ; ce qui suit couvre ce
qui est propre à ce type (voir tableau plus haut pour la liste des templates) :

`Templates/Page/<clé>/source.html`, conservé dans le dossier de seed pour `confirmation-webinar`
et `webinar-landing`, garde l'intégralité des commentaires pédagogiques (WHY/HOW) d'origine pour
qui doit un jour retoucher le template à la main ; ils sont volontairement absents de
`template.html` (ce qui est réellement importé dans `html` par la migration), qui devient le code
source réel d'une page visible par de vrais visiteurs. Un template créé depuis l'UI n'a bien sûr
pas cette distinction : un seul champ `html`.

**Toujours en mode code source, jamais en thème.** `create_page_from_template` enregistre
systématiquement `template=mautic_code_mode` sur la page (jamais `blank`, jamais un thème du
dossier `Themes/`). C'est le mécanisme natif de Mautic pour figer une page en édition « code
source » (`ThemeListType`) : un template avec du JavaScript fonctionnel — compte à rebours,
bascule d'état, génération de rendez-vous calendrier — ne doit jamais passer par un thème, même
en apparence statique. Ouvrir une page dans le builder GrapesJS la fait passer par son moteur de
rendu par composants, qui ne garantit pas la survie d'un `<script>` au prochain enregistrement ;
`mautic_code_mode` garantit qu'une réouverture ultérieure de la page rouvre l'éditeur de code
source, jamais le canevas visuel.

`create_landing_page` (HTML libre, plus ancien) enregistre lui `template='blank'` : une page créée
par cet outil qui contiendrait elle-même du `<script>` s'expose au même risque si elle est
rouverte dans le builder. Non corrigé ici, hors périmètre de cette bibliothèque.

**Deux contextes d'échappement, pas un seul.** Un template d'email est entièrement HTML ; celui-ci
mélange HTML visible et un objet de configuration JavaScript (`WEBINAR_CONFIG`). Échapper une
valeur destinée à une chaîne JS avec `htmlspecialchars` casserait l'affichage : le contenu d'un
`<script>` n'est jamais interprété comme du HTML par le navigateur, une apostrophe y ressortirait
littéralement en `&#039;`. `PlaceholderRenderer` (partagé avec la bibliothèque d'email) gère trois
contextes par emplacement, déclarés dans le manifeste :

| `context` | Usage | Exemple dans ce template |
|---|---|---|
| *(absent)* — HTML | échappement HTML standard | `BRAND_NAME`, `PRIVACY_URL` |
| `js` | échappement chaîne JavaScript (guillemets/antislash/retours à la ligne) | `EVENT_TITLE`, `JOIN_LINK` |
| `html_br` | échappement HTML, avec un `<br>` littéral réouvert ensuite | `CONFIRMED_HEADLINE` (titre sur deux lignes) |

`html_br` a corrigé un bug réel trouvé en testant : le manifeste autorise explicitement un `<br>`
dans les titres de chaque état (« peut tenir sur deux lignes »), mais un échappement HTML standard
le neutralise en `&lt;br&gt;`, visible tel quel sur la page. La réouverture ne vise que `<br>` —
`<img src=x onerror=...>` glissé dans le même champ reste neutralisé, vérifié par test.

`DURATION_MINUTES` (`confirmation-webinar`) et `MAUTIC_FORM_ID` (`webinar-landing`, injecté dans
le token `{form=ID}`) sont un cas à part : littéraux numériques non guillemetés, jamais des
chaînes. `CreatePageFromTemplateTool` les contraint en entier avant substitution, pour qu'une
valeur non numérique ne casse pas la syntaxe.

**Un même thème, deux formats.** `webinar-landing` est la traduction directe du thème
`landing-webinar` : même structure, mêmes consignes (message match, pas de navigation, formulaire
toujours atteignable...), mais en jetons `{{DOUBLE_ACCOLADE}}` substitués automatiquement plutôt
qu'en `[CROCHETS]` remplis à la main dans le builder. Les deux se complètent — thème pour un
humain qui construit visuellement, template pour l'agent qui rédige et publie directement.

### Persistance, audit et coût

```
witty_conversations   un fil par utilisateur, avec provider, modèle et tokens cumulés
witty_messages        le transcript au format Dto\Message, tokens par message
witty_audit_log       qui, quel outil, quels arguments, quel statut, quel objet, en combien de ms
```

Le navigateur ne transporte plus que l'identifiant du fil : l'historique est reconstruit depuis
la base à chaque tour. Un rechargement de page ne perd rien, et les lectures sont filtrées par
utilisateur — une conversation n'est jamais visible d'un autre compte, même en devinant son id.

Le journal d'audit est écrit par `ToolRegistry`, donc **toutes** les exécutions y passent, y
compris les refus de permission et les demandes de confirmation. Le nom d'utilisateur y est
dupliqué en clair : supprimer un compte ne doit pas effacer la trace de ce qu'il a fait. Une
panne d'écriture du journal ne fait pas échouer l'action de l'utilisateur, elle retombe sur le
logger applicatif.

Le quota (`daily_token_quota`, 0 = illimité) additionne entrée et sortie sur les **messages** et
non sur les conversations, pour qu'un fil entamé la veille ne remette pas le compteur à zéro.

### Streaming

`LlmProviderInterface::stream()` reçoit un callback appelé à chaque fragment de texte et renvoie
le même `LlmResult` que `chat()` : la boucle de l'agent ne distingue pas les deux modes, seul
l'émetteur change. Faire diverger les deux chemins serait le meilleur moyen de n'en tester qu'un.

Trois formats de flux, trois pièges :

| | Texte | Appels d'outils | Usage |
|---|---|---|---|
| Anthropic | `text_delta` | blocs indexés + `input_json_delta` (JSON partiel à recoller) | `message_start` puis `message_delta` |
| OpenAI | `delta.content` | `delta.tool_calls[].index` à accumuler | seulement si `stream_options.include_usage` |
| Gemini | `parts[].text` | `functionCall` complet | `usageMetadata` du dernier événement |

Le découpage se fait sur les lignes, pas sur les chunks HTTP : une trame peut arriver en deux
morceaux et deux trames dans le même morceau. `Tests/Service/Llm/StreamParsingTest.php` rejoue
exactement ces cas.

Côté serveur la session est fermée (`$session->save()`) avant d'ouvrir le flux, sinon le verrou
de session bloquerait le reste de l'interface pendant toute la réponse. L'en-tête
`X-Accel-Buffering: no` demande à nginx de ne pas bufferiser. Si l'hébergement bufferise malgré
tout, désactiver le streaming en configuration : la réponse repasse en un bloc via `/witty/send`.

### Exécution via services internes

L'agent appelle `ListModel`, `EmailModel`, `PageModel`, `CampaignModel` directement plutôt que
l'API REST de Mautic : même couche de validation, pas d'aller-retour OAuth vers sa propre
instance, et le contexte de sécurité de l'utilisateur connecté reste applicable. Pour passer par
HTTP à la place, il suffit de réécrire les `execute()` des outils — le reste ne change pas.

---

## Tests

```bash
# parseurs SSE des trois fournisseurs, sans réseau ni clé API
php vendor/bin/phpunit plugins/WittyBundle/Tests
```

Les 14 outils ont été exécutés contre une instance Mautic 7.1.3 réelle (création de segment,
email, formulaire, campagne, action de points, tags, envoi de test, modification, suppression,
statistiques). Le reste de la chaîne — modale de configuration, chiffrement de la clé, streaming
SSE, persistance, journal d'audit, quota — a été vérifié via HTTP sur cette même instance.

## Points d'attention

1. **Nom de l'intégration** — `Integration/WittyIntegration.php` → intégration `Witty` →
   service `mautic.integration.witty`. Renommer le fichier sans renommer l'alias de
   `Config/services.php` casse silencieusement la fiche du plugin.
2. **Migration et version** — toute nouvelle table demande une classe dans `Migrations/` **et**
   un incrément de `version` dans `Config/config.php` : sans changement de version, Mautic ne
   déclenche pas `Engine::up()` et les instances déjà installées n'auront pas la table. Le SQL de
   la migration doit reproduire ce que génère `SchemaTool` — `doctrine:schema:update --dump-sql`
   ne doit rien proposer sur les tables `witty_*` après coup.
3. **`iconClass` du menu** (`Config/config.php`) — bascule sur `fa fa-magic` si l'icône Remix
   ne s'affiche pas dans ton thème.
4. **Champs de clé API toujours vides à l'ouverture** — comportement Symfony normal
   (`PasswordType::buildView()` force `value = ''` hors resoumission), pas un bug de chargement :
   les clés sont bien là, seulement pas ré-affichées, par sécurité. Le vrai piège est que le
   navigateur soumet donc `''` pour tout champ non retouché ; sans
   `EventListener/ConfigKeysSubscriber.php` (écoute
   `IntegrationEvents::INTEGRATION_API_KEYS_BEFORE_SAVE`, restaure toute clé soumise vide à partir
   de sa valeur précédente), enregistrer le formulaire pour n'ajouter qu'une seule clé (ex. Bright
   Data) effacerait silencieusement toutes les autres. Contrepartie : une clé une fois enregistrée
   ne peut plus être vidée en laissant le champ blanc — il faut la remplacer par autre chose.

Les identifiants de modèles par défaut (`WittyConfig::DEFAULT_MODELS`) ne servent plus qu'au repli
(`ModelCatalog`, si l'appel `listModels()` échoue) et à présélectionner une valeur avant le premier
chargement Ajax du menu déroulant : ils vieillissent vite, mais ce n'est plus critique puisque la
liste réelle est refetchée à chaque ouverture du chat.

---

## Limites connues / suite logique

- **Pas de reprise de flux** — si l'onglet est fermé pendant une réponse en streaming, le tour en
  cours est perdu côté affichage. Les messages déjà écrits en base sont conservés, mais il n'y a
  pas de mécanisme de reconnexion (`Last-Event-ID`).
- **Quota par utilisateur uniquement** — pas de plafond global à l'instance, ni de conversion en
  coût monétaire (les tarifs diffèrent par modèle et changent souvent).
- **Un seul tour peut dépasser le quota** — le contrôle a lieu avant l'appel, le coût n'est connu
  qu'après. Le tour suivant est refusé.
- **Purge non automatisée** — aucune rétention sur `witty_conversations` ni `witty_audit_log`.
- **Catalogue de modèles mis en cache une heure** — un modèle tout juste retiré ou ajouté chez le
  fournisseur peut mettre jusqu'à une heure à apparaître/disparaître du menu déroulant
  (`ModelCatalog`, cache fichier sous `PathsHelper::getCachePath()`, une entrée par fournisseur +
  clé API).
  Une commande de purge planifiée est le complément logique.
- **Outils encore absents** : tests A/B, gestion des rapports, import de contacts, webhooks.
- **Le seed de `witty_templates` ne se rejoue pas** — `Migrations/Version_2_8_0.php` ne s'exécute
  qu'à la création de la table (`isApplicable()`) : modifier `Templates/Email/webinar/template.mjml`
  puis relancer `dev/build-templates.sh` ne change rien sur une instance déjà migrée, il faut
  éditer le template correspondant depuis l'UI (section Witty > Templates). Volontaire — une
  instance dont un utilisateur a modifié/supprimé un template livré ne doit pas se le voir
  réimposer à la prochaine mise à jour du plugin.
