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

Puis **Paramètres › Configuration › Witty** : choisir le fournisseur, coller la clé API,
éventuellement forcer un modèle. Le chat est ensuite accessible dans le menu principal (`/s/witty`).

La clé API est chiffrée via l'`EncryptionHelper` de Mautic avant d'être écrite dans
`app/config/local.php` (préfixe `witty_enc::`). Une clé déjà saisie en clair reste lisible ;
elle sera chiffrée à la prochaine sauvegarde du formulaire.

---

## Architecture

```
Config/config.php          routes, menu, paramètres exposés à la config Mautic
Config/services.php        autowiring + auto-tag des outils (witty.tool)
EventListener/             ajoute l'onglet "Witty" + chiffre la clé API au pre-save
Form/Type/ConfigType.php   formulaire de la section de configuration
Service/WittyConfig.php    lecture centralisée de la config (+ déchiffrement)
Service/Llm/               3 clients HTTP + normalisation du tool calling
Service/Agent/             boucle de l'agent + prompt système
Service/Tool/              registre + outils (une classe = une capacité)
Controller/                page de chat + endpoint POST
```

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
3. **`formTheme`** — le `ConfigSubscriber` n'en déclare pas. Si le rendu de l'onglet est cassé,
   ajouter un `formTheme` pointant vers un template de widget dédié.

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
