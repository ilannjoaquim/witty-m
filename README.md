# Witty — assistant IA pour Mautic 7.1

Plugin Mautic ajoutant une interface de chat capable de piloter Mautic : segments, emails,
landing pages, formulaires, campagnes, points, tags. Réponses en streaming, conversations
persistées, journal d'audit des actions et quota de tokens par utilisateur.

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
| Details | activation du plugin + clé API |
| Fonctionnalités | fournisseur, modèle, itérations max, confirmation avant écriture, streaming, quota de tokens |

Enregistrer avec **Sauvegarder et fermer**. Le chat est ensuite accessible dans le menu
principal (`/s/witty`), et le journal des actions dans le menu d'administration (`/s/witty/audit`).

La clé API est stockée dans `plugin_integration_settings.api_keys`, chiffrée par Mautic
(`IntegrationsHelper::saveIntegrationConfiguration`). Rien n'est écrit dans `local.php`.

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
Entity/                    conversations, messages, journal d'audit (+ repositories)
Migrations/                création des tables sur une instance déjà installée
Service/WittyConfig.php    lecture centralisée de la config de l'intégration
Service/Conversation/      transcript côté serveur, filtré par utilisateur
Service/Audit/             écriture du journal des actions
Service/Usage/             compteur de tokens et quota quotidien
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

### Outils disponibles

| Outil | Écriture | Rôle |
|---|:---:|---|
| `list_entities` | | Liste segments, emails, pages, campagnes |
| `search_contacts` | | Recherche de contacts |
| `describe_campaign_events` | | Catalogue des événements de campagne installés |
| `campaign_stats` | | Contacts et avancement par événement |
| `manage_tags` | ● | Liste les tags, en pose ou en retire sur un contact |
| `create_segment` | ● | Segment + filtres |
| `create_email` | ● | Email template ou list |
| `create_landing_page` | ● | Landing page |
| `create_form` | ● | Formulaire + champs, avec mapping vers les champs contact |
| `create_campaign` | ● | Campagne + canvas |
| `create_point_action` | ● | Action de scoring (types lus sur l'instance) |
| `send_test_email` | ● | Exemplaire de test, aucun contact touché |
| `update_entity` | ● | Renomme, décrit, (dé)publie un objet existant |
| `delete_entity` | ● | Suppression définitive |

`update_entity` et `delete_entity` acceptent plusieurs types d'objets : leur permission ne peut
donc pas être déclarée une fois pour toutes sur l'outil, elle est vérifiée **objet par objet**
via `EntityCatalog::isAllowed()` (`hasEntityAccess`, own/other). Sans cela, un utilisateur
autorisé sur les segments pourrait supprimer des emails en passant par l'agent.

`delete_entity` exige `confirmed: true` **même si le mode confirmation global est désactivé** :
une suppression ne se rattrape pas.

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

Les identifiants de modèles par défaut (`WittyConfig::DEFAULT_MODELS`) vieillissent vite :
renseigner le modèle exact dans la configuration.

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
  Une commande de purge planifiée est le complément logique.
- **Outils encore absents** : tests A/B, gestion des rapports, import de contacts, webhooks.
