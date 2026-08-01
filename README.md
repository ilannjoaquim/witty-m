# Witty — assistant IA pour Mautic 7.1

Plugin Mautic ajoutant une section **Witty** dans les paramètres et une interface de chat
capable de piloter Mautic : création de segments, d'emails, de landing pages et de campagnes.

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
**Paramètres › Plugins**) est obligatoire : c'est lui qui crée la ligne du plugin et celle de
l'intégration en base. Sans elle, la fiche du plugin n'a rien à afficher.

Puis **Paramètres › Plugins › Witty** :

| Onglet | Contenu |
|---|---|
| Details | activation du plugin + clé API |
| Fonctionnalités | fournisseur, modèle, nombre max d'itérations, confirmation avant écriture |

Enregistrer avec **Sauvegarder et fermer**. Le chat est ensuite accessible dans le menu
principal (`/s/witty`).

La clé API est stockée dans `plugin_integration_settings.api_keys`, chiffrée par Mautic
(`IntegrationsHelper::saveIntegrationConfiguration`). Rien n'est écrit dans `local.php`.

---

## Architecture

```
Config/config.php          routes et menu
Config/services.php        autowiring, auto-tag des outils, alias mautic.integration.witty
Integration/               intégration Mautic : c'est elle qui rend la fiche du plugin éditable
Form/Type/                 formulaires des onglets Details (clé API) et Fonctionnalités
Service/WittyConfig.php    lecture centralisée de la config de l'intégration
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

### Exécution via services internes

L'agent appelle `ListModel`, `EmailModel`, `PageModel`, `CampaignModel` directement plutôt que
l'API REST de Mautic : même couche de validation, pas d'aller-retour OAuth vers sa propre
instance, et le contexte de sécurité de l'utilisateur connecté reste applicable. Pour passer par
HTTP à la place, il suffit de réécrire les `execute()` des outils — le reste ne change pas.

---

## Points à vérifier avant mise en production

Le code compile logiquement mais n'a pas été exécuté contre une instance ; ces trois endroits
sont ceux qui demandent une vérification en priorité :

1. **`CreateCampaignTool`** — le canvas Mautic (`nodes` / `connections`) et les `properties`
   des événements (`email.send`, `lead.changetags`, `lead.changepoints`) sont la partie la plus
   sensible aux évolutions de version. Méthode fiable : créer une campagne équivalente à la main,
   puis inspecter `$campaign->getCanvasSettings()` et les `properties` des events pour aligner
   exactement le format.
2. **`iconClass` du menu** (`Config/config.php`) — bascule sur `fa fa-magic` si l'icône Remix
   ne s'affiche pas dans ton thème.
3. **Nom de l'intégration** — `Integration/WittyIntegration.php` → intégration `Witty` →
   service `mautic.integration.witty`. Renommer le fichier sans renommer l'alias de
   `Config/services.php` casse silencieusement la fiche du plugin (voir ci-dessous).

Les identifiants de modèles par défaut (`WittyConfig::DEFAULT_MODELS`) vieillissent vite :
renseigner le modèle exact dans la configuration.

---

## Limites connues / suite logique

- **Pas de persistance** — le transcript vit côté navigateur et repart à zéro au rechargement.
  Ajouter `WittyConversation` / `WittyMessage` (Doctrine + migration) pour l'historique et l'audit.
- **Pas de streaming** — réponse en un bloc. Passer en SSE améliore nettement le ressenti sur
  les enchaînements longs, au prix d'une gestion du buffering PHP-FPM.
- **Pas de journal d'actions** — les créations sont tracées via le logger Symfony uniquement.
  Une table d'audit dédiée (qui a demandé quoi, quel outil, quel objet créé) est souhaitable.
- **Pas de garde-fou de coût** — ni compteur de tokens, ni quota par utilisateur.
- **Outils manquants évidents** : formulaires, tags, points, envoi de test, modification et
  suppression d'objets existants, statistiques de campagne.
