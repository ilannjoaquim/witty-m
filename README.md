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

### Pièces jointes (docs, tableurs, images)

Un bouton trombone dans le chat permet de joindre un fichier (image, CSV/XLS/XLSX, texte, PDF/
Office) à un message — ex. importer une liste de leads, ou fournir une image à réutiliser dans un
email. L'upload se fait à la sélection du fichier, pas à l'envoi du message : `POST /witty/upload`
(`WittyController::uploadAction()`) renvoie immédiatement un id, que le front inclut dans
`attachment_ids` au moment d'envoyer le message.

- **`Entity/WittyAttachment.php`** — `conversation` et `message` sont **nullables** : l'upload
  précède forcément la création du message (voire de la conversation, pour un tout nouveau fil).
  Le rattachement se fait par référence d'objet (`AttachmentManager::attachToConversation()`,
  appelé depuis `AgentRunner::run()`), jamais par id scalaire — Doctrine résout l'ordre d'INSERT au
  flush unique de fin de tour, pas besoin de flush intermédiaire.
- **`Service/Attachment/AttachmentManager.php`** — deux destinations selon le type : image/document
  deviennent un `Asset` Mautic **local** (`Asset::setFile()` + `preUpload()` + `upload()`, publié
  immédiatement — contrairement aux objets créés par l'agent, un fichier vient explicitement de
  l'utilisateur, le laisser en brouillon casserait le cas d'usage "image utilisable tout de suite
  dans un email") ; tableur/texte restent de simples fichiers de travail sous `media/witty/uploads/`,
  jamais publiés. Extension et taille validées contre une allowlist propre au plugin, plafonnée par
  la politique globale du site (`allowed_extensions`/`max_size`).
- **`read_attachment`** / **`import_leads_from_file`** (`Service/Tool/Tools/`) — l'agent inspecte
  une pièce jointe par son id avant d'agir : texte (contenu tronqué), tableur (en-têtes + aperçu via
  `Service/Attachment/SpreadsheetReader.php`, `phpoffice/phpspreadsheet`, déjà une dépendance
  Mautic), image/document (URL d'asset seulement, pas de lecture textuelle). L'import de leads est
  synchrone et plafonné à 500 lignes plutôt que branché sur le moteur d'import asynchrone natif de
  Mautic (`LeadBundle\Model\ImportModel`, pensé pour un assistant pas-à-pas + cron) — suffisant pour
  une liste de quelques centaines de contacts, largement le cas d'usage visé depuis le chat.
- **Mention côté modèle, pas côté affichage** — `ConversationManager::toMessages()` ajoute une note
  `[Pièce jointe : nom (type, id=N)]` au texte envoyé au modèle quand le message a des pièces
  jointes (`WittyMessage::$attachments`, relation `OneToMany` en mémoire, fonctionne même avant
  flush). Le contenu persisté (`WittyMessage::content`) et la bulle affichée
  (`toDisplayTranscript()`) restent exactement ce que l'utilisateur a tapé — aucune duplication
  visuelle de la note technique.
- **Nettoyage** — un fichier joint puis jamais envoyé (onglet fermé avant Envoyer) reste orphelin
  (`conversation` null) ; `php bin/console witty:attachments:prune-orphans` le supprime après 24h
  de grâce (fichier ou Asset, et ligne en base). À planifier via le cron système, comme
  `witty:meet:reconcile-attendance` (Mautic n'a pas d'ordonnanceur interne).
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
Templates/Email/           templates d'email livrés (MJML source + HTML compilé + manifeste)
Templates/Page/            templates de landing page livrés (HTML + manifeste, mode code source)
Entity/                    conversations, messages, journal d'audit (+ repositories)
Migrations/                création des tables sur une instance déjà installée
Service/WittyConfig.php    lecture centralisée de la config de l'intégration
Service/Conversation/      transcript côté serveur, filtré par utilisateur
Service/Audit/             écriture du journal des actions
Service/Usage/             compteur de tokens et quota quotidien
Service/Template/          bibliothèque de templates et substitution
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
point, point_trigger, point_group, report, project et message (Channels). Ajouter un type au
catalogue le rend automatiquement listable/renommable/supprimable sans toucher ces trois outils.

| Outil | Écriture | Rôle |
|---|:---:|---|
| `list_entities` | | Liste n'importe quel type du catalogue (voir ci-dessus) |
| `update_entity` | ● | Renomme, décrit, (dé)publie un objet existant, tous types du catalogue |
| `delete_entity` | ● | Suppression définitive, tous types du catalogue |
| `list_email_templates` | | Templates d'email livrés + consigne de rédaction de chaque emplacement |
| `create_email_from_template` | ● | Email construit à partir d'un template du plugin |
| `list_page_templates` | | Templates de landing page livrés + consigne de chaque emplacement |
| `create_page_from_template` | ● | Landing page construite à partir d'un template, toujours en mode code source |
| `create_email` | ● | Email template ou list |
| `create_landing_page` | ● | Landing page |
| `send_test_email` | ● | Exemplaire de test, aucun contact touché |
| `create_form` | ● | Formulaire + champs, avec mapping vers les champs contact |
| `create_segment` | ● | Segment + filtres |
| `search_contacts` | | Recherche de contacts |
| `create_contact` | ● | Contact (refuse le doublon d'email) |
| `update_contact` | ● | Champs d'un contact existant, id ou email |
| `manage_contact_segments` | ● | Ajoute/retire un contact d'un ou plusieurs segments |
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
| `create_meet_room` | ● | Salle plugNmeet, ouverte indéfiniment jusqu'à `end_meet_room` |
| `end_meet_room` | ● | Termine une salle active, déconnecte tout le monde |
| `get_meet_room_participants` | | Qui est connecté à une salle active |
| `generate_meet_join_link` | ● | Lien de connexion ponctuel (nom libre, pas forcément un contact) |
| `create_meet_invitation` | ● | Lien d'invitation personnel pour un contact, avec suivi de présence |
| `list_past_meet_rooms` | | Historique des salles terminées |
| `list_meet_recordings` | | Enregistrements et/ou artefacts plugNmeet |
| `delete_meet_recording` | ● | Supprime un enregistrement ou un artefact, définitif |
| `convert_meet_recording_to_asset` | ● | Republie un enregistrement comme Asset Mautic (partageable par email) |
| `read_attachment` | | Lit une piece jointe du chat (texte, apercu de tableur, ou URL d'asset pour image/document) |
| `import_leads_from_file` | ● | Import de contacts depuis un tableur joint, plafonne a 500 lignes, mapping de colonnes fourni par l'agent |

`update_entity` et `delete_entity` acceptent plusieurs types d'objets : leur permission ne peut
donc pas être déclarée une fois pour toutes sur l'outil, elle est vérifiée **objet par objet**
via `EntityCatalog::isAllowed()` (`hasEntityAccess`, own/other, ou permission plate pour project
qui n'a pas de notion de propriétaire).

`delete_entity`, `manage_tags` (action=delete), `end_meet_room` et `delete_meet_recording`
exigent `confirmed: true` **même si le mode confirmation global est désactivé** : ce sont les
seules actions réellement irréversibles (suppression, ou déconnexion immédiate de tous les
participants).

Contact et Company n'entrent pas dans `EntityCatalog` (pas de notion de publication, champs
personnalisés au lieu de name/description) : ils gardent leurs outils dédiés
(`create_contact`/`update_contact`, `create_company`/`update_company`/`search_companies`).

Ce tableau ne liste que les outils Mautic locaux, connus à la compilation. Si une clé Bright Data
est renseignée, des outils supplémentaires `brightdata_*` (recherche web, scraping) apparaissent
en plus, découverts en direct — voir [Recherche et navigation web](#recherche-et-navigation-web-bright-data-mcp).

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

### Bibliothèque de templates d'email

```
Templates/Email/webinar/template.mjml   source, avec les consignes de rédaction en commentaire
Templates/Email/webinar/template.html   HTML pré-compilé, celui qui part chez le destinataire
Templates/Email/webinar/manifest.json   nom, objectif, règles, et la consigne de chaque emplacement
```

Ajouter un template = déposer un dossier avec ces trois fichiers, puis lancer
`./dev/build-templates.sh`. Rien à déclarer dans le code.

**Le HTML est versionné** parce que PHP ne sait pas compiler du MJML : le compilateur officiel
est en Node. Le `.mjml` reste livré et est enregistré dans `bundle_grapesjsbuilder` à la création
de l'email, donc **l'email reste éditable dans le builder MJML** de Mautic. Dépendance optionnelle :
si le plugin GrapesJS est absent, seul le HTML est enregistré et l'outil le signale.

**La substitution est faite par le plugin, pas par le modèle.** Demander à un LLM de recracher
1 200 lignes de HTML compilé sans faute n'est pas fiable : il fournit le texte de chaque bloc,
le plugin garantit la structure. Trois garde-fous :

- toutes les valeurs sont échappées (`htmlspecialchars`) — le modèle produit du texte, pas du
  balisage. Vérifié : `<script>alert(1)</script>` ressort en `&lt;script&gt;` ;
- `REGISTRATION_URL`, `LOGO_URL` et `HERO_IMAGE_URL` doivent être en `http(s)` — un
  `javascript:` est refusé avant d'atteindre le `href` ;
- seuls les emplacements déclarés au manifeste sont substitués, et un emplacement obligatoire
  manquant fait échouer l'appel avec la liste des clés à fournir.

Les consignes du manifeste (le *pourquoi* de chaque bloc, l'exemple, ce qu'il ne faut pas écrire)
sont renvoyées au modèle par `list_email_templates`. C'est ce qui fait la différence entre un
email structuré et un email générique : sans elles, le modèle remplit les cases au jugé.

Deux templates livrés, tous deux dérivés des Thèmes (voir plus haut) — même structure et mêmes
consignes, traduites en jetons `{{DOUBLE_ACCOLADE}}` pour la substitution automatique :

| Template | Dérivé du thème | Emplacements |
|---|---|---|
| `webinar` | `webinar-last` | 26 (24 obligatoires) — logo et visuel avec valeur par défaut |
| `webinar-day0` | `webinar-day0` | 27 — annonce day-0, structure P.A.S. + 2 citations verbatim |

`HOOK` (dans `webinar-day0`) est le premier emplacement à utiliser le contexte `html_br` : la
consigne autorise explicitement un `<br/>` pour une accroche sur deux lignes. `EmailTemplate` /
`EmailTemplateLibrary` ont été généralisés pour le supporter (même mécanisme que la bibliothèque
de pages, voir plus bas), testé pour confirmer qu'un `<img onerror=...>` glissé dans le même champ
reste neutralisé.

### Bibliothèque de templates de landing page

Même principe, pour les pages : `list_page_templates` / `create_page_from_template`,
`Service/Template/PageTemplateLibrary.php`, un dossier par template dans `Templates/Page/`.

| Template | Dérivé du thème | Emplacements |
|---|---|---|
| `confirmation-webinar` | — (jamais un thème, JS fonctionnel) | 37 (24 obligatoires) |
| `webinar-landing` | `landing-webinar` | 66 (la majorité obligatoires — page d'inscription complète) |

```
Templates/Page/<clé>/source.html     source integralement annote (WHY/HOW), reference
Templates/Page/<clé>/template.html   version livree a la substitution, commentaires de fond retires
Templates/Page/<clé>/manifest.json   nom, objectif, regles, consigne de chaque emplacement
```

Pas de compilation ici (page = HTML brut, pas de MJML) : `template.html` est directement le
contenu substitué et enregistré. `source.html` garde l'intégralité des commentaires pédagogiques
d'origine pour qui doit un jour retoucher le template à la main ; ils sont volontairement absents
de `template.html`, qui devient le code source réel d'une page visible par de vrais visiteurs.

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
