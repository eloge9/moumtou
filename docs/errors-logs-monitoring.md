# Gestion des erreurs, journaux et monitoring — MOUMTOU

Ce document décrit le dispositif de gestion des erreurs, de journalisation et de supervision de MOUMTOU (FONCTIONNALITÉ 18). Il s'adresse aux administrateurs et aux développeurs qui doivent diagnostiquer un incident.

## 1. Vue d'ensemble

MOUMTOU est une application Symfony rendue côté serveur (pas de frontend JS séparé, pas d'API publique hormis un seul endpoint d'autocomplétion). En conséquence, la gestion des erreurs repose sur :

- Un gestionnaire global d'exceptions (`App\EventListener\ErrorPageListener`) qui intercepte **toute** exception non gérée, quelle que soit la route.
- Un identifiant de corrélation (`X-Request-Id`) généré pour chaque requête, affiché à l'utilisateur en cas d'erreur et présent dans chaque ligne de journal.
- Un journal technique persistant (`ErrorLog`, table `error_log`) pour les seules erreurs serveur (5xx) réelles.
- Un point de contrôle de santé public (`GET /health`).
- Un tableau de bord "Monitoring" réservé aux administrateurs (`/admin/monitoring`).
- Des alertes automatiques (notification + e-mail) pour les erreurs critiques, avec regroupement anti-spam.

Aucun outil externe (Sentry, ELK, Grafana, Prometheus…) n'a été introduit : le volume et les besoins réels de MOUMTOU ne le justifient pas — tout repose sur la pile déjà en place (Monolog, MySQL, Symfony).

## 2. Identifiant de corrélation (request ID)

Chaque requête HTTP reçoit un identifiant hexadécimal de 16 caractères (ex. `FC7519D7BF9BD3BB`), généré par `App\EventListener\RequestIdListener` avant tout traitement métier. Il est :

- renvoyé dans l'en-tête de réponse `X-Request-Id` sur **toute** réponse (succès comme erreur) ;
- injecté automatiquement dans chaque ligne de journal Monolog via `App\Monolog\RequestIdProcessor` (champ `extra.request_id`) ;
- affiché à l'utilisateur sous la forme « Référence : XXXXXXXXXXXXXXXX » sur les pages d'erreur et dans le champ `request_id` du format JSON d'erreur.

**Pour tracer un incident signalé par un utilisateur** : récupérer la référence qu'il a communiquée, puis la rechercher dans les journaux (`grep FC7519D7BF9BD3BB var/log/prod.log` ou équivalent selon le collecteur de logs en production) et/ou dans le tableau de bord Monitoring (les entrées y sont listées par date, la référence est visible dans le détail de chaque erreur).

## 3. Format d'erreur JSON (API)

Le seul endpoint JSON de l'application (`/recherche/suggestions`), ainsi que toute requête envoyant un en-tête `Accept: application/json` (hors `text/html`), reçoit en cas d'erreur une réponse au format uniforme :

```json
{
  "success": false,
  "error": { "code": "INTERNAL_ERROR", "message": "Un problème technique est survenu de notre côté." },
  "request_id": "FC7519D7BF9BD3BB"
}
```

Codes HTTP et `error.code` couverts : `400/BAD_REQUEST`, `401/UNAUTHORIZED`, `403/FORBIDDEN`, `404/NOT_FOUND`, `405/METHOD_NOT_ALLOWED`, `409/CONFLICT`, `422/VALIDATION_ERROR`, `429/TOO_MANY_REQUESTS`, `503/SERVICE_UNAVAILABLE`, et `INTERNAL_ERROR`/`REQUEST_ERROR` par défaut. Aucun message technique (trace, SQL, chemin serveur) n'apparaît jamais dans cette réponse.

## 4. Pages d'erreur utilisateur (HTML)

- **403/404** : gabarits dédiés déjà existants (`error403.html.twig`, `error404.html.twig`), conservés inchangés — comportement normal de l'application, jamais journalisé comme une erreur.
- **500 et 5xx sans gabarit dédié** : `templates/bundles/TwigBundle/Exception/error500.html.twig` — message clair, référence de corrélation, retour à l'accueil, bouton « Réessayer ».
- **Autres 4xx sans gabarit dédié** (400/401/405/409/422/429) : `error_generic.html.twig`, même structure, message adapté au code.
- **En développement** (`kernel.debug=true`) : les erreurs 500 continuent d'afficher la page de débogage Symfony habituelle (trace complète) pour ne pas gêner le développement local — la journalisation/persistance/alerte a lieu normalement en arrière-plan malgré tout. Seule la réponse HTML change ; en production (`kernel.debug=false`), la page propre ci-dessus est systématiquement affichée.

## 5. Journal technique (`ErrorLog`)

Chaque erreur serveur (5xx) réelle est persistée dans la table `error_log` : date, request ID, niveau (`error`/`critical`), code HTTP, méthode, route, type d'exception, **message de l'exception uniquement** (jamais la trace complète), utilisateur connecté le cas échéant.

Cette table est **distincte** de `AdminAuditLog` (FONCTIONNALITÉ 9) : `AdminAuditLog` trace *qui a fait quoi* (actions administratives volontaires), `ErrorLog` trace *ce qui a mal fonctionné* (bugs/pannes techniques). Les deux ne sont jamais fusionnées.

La trace complète (avec fichier/ligne) reste uniquement dans les journaux Monolog (`var/log/`), jamais dans `ErrorLog` ni dans aucune réponse HTTP.

### Niveaux

| Niveau | Déclencheur | Persisté | Alerté |
|---|---|---|---|
| `error` | Toute exception non gérée aboutissant à un 5xx (ex. bug applicatif) | Oui | Non |
| `critical` | Exception de la couche base de données (`Doctrine\DBAL\Exception` — connexion perdue, requête en échec…) | Oui | Oui (voir §7) |
| WARNING (Monolog uniquement, non persisté) | 4xx sans gabarit dédié (400/401/405/409/422/429) | Non | Non |

## 6. Journaux applicatifs (Monolog)

- **Développement** : `var/log/dev.log`, rotation quotidienne (`rotating_file`, 14 fichiers conservés).
- **Test** : `var/log/test.log`, uniquement les erreurs (`fingers_crossed`).
- **Production** : écriture sur `php://stderr` au format JSON (`fingers_crossed`, seuil erreur) — la rotation est alors la responsabilité du superviseur/collecteur de logs externe (convention standard « 12-factor »), volontairement laissée telle quelle : c'est le modèle recommandé par Symfony pour un déploiement traditionnel.

Aucun mot de passe, jeton, clé API ou secret n'est jamais écrit dans un journal (vérifié — voir FONCTIONNALITÉ 15 et les tests de cette fonctionnalité). Les journaux ne sont accessibles que depuis le système de fichiers serveur (`var/log/`), jamais exposés par une route web.

## 7. Alertes critiques

Une erreur `critical` déclenche une notification (in-app + e-mail, catégorie SÉCURITÉ, non désactivable) à destination de **tous** les administrateurs, via `App\Service\CriticalErrorAlerter`.

Anti-spam : une même erreur (même type d'exception + même message + même route) ne redéclenche pas d'alerte pendant **5 minutes** après sa première occurrence (regroupement par signature, `cache.error_alerts`). Exemple : 50 requêtes échouant sur la même route pour la même raison en 2 minutes ne génèrent qu'une seule alerte.

## 8. Contrôle de santé — `GET /health`

Endpoint public (aucune authentification requise — convention pour un outil de supervision externe), ne renvoie **jamais** d'information sensible :

```json
{ "status": "ok", "database": "ok", "storage": "ok", "timestamp": "2026-09-03T10:00:00+00:00" }
```

- `status` : `ok` (200) si tout fonctionne, `degraded` (503) sinon.
- `database` : `ok` si une requête `SELECT 1` réussit, `error` sinon.
- `storage` : `ok` si le dossier des fichiers uploadés est accessible en écriture, `error` sinon.

Exclu du référencement (`robots.txt : Disallow: /health`). Aucune séparation liveness/readiness n'a été ajoutée : l'infrastructure réelle de MOUMTOU (déploiement traditionnel, pas d'orchestrateur type Kubernetes) ne le justifie pas.

## 9. Tableau de bord "Monitoring" (admin)

`/admin/monitoring`, réservé aux administrateurs (`ROLE_ADMIN`, 403 pour tout autre utilisateur connecté, redirection vers la connexion pour un visiteur anonyme).

Répond à la question « MOUMTOU fonctionne-t-il correctement en ce moment ? » :

- état des services (base de données, application) ;
- nombre d'erreurs aujourd'hui / 24 h / 7 jours ;
- nombre d'erreurs critiques sur 24 h ;
- endpoint le plus problématique sur 24 h ;
- répartition par code HTTP sur 24 h ;
- liste paginée et filtrable (code HTTP, niveau) des erreurs récentes, avec accès au détail de chacune (date, niveau, référence, route, méthode, utilisateur, type d'exception, message — jamais la trace).

Le lien "Monitoring" du menu latéral affiche un badge rouge lorsqu'au moins une erreur critique est survenue dans les dernières 24 heures.

## 10. Diagnostiquer une panne de production — démarche

1. Consulter `/admin/monitoring` : les compteurs et la liste des erreurs récentes donnent une première vue.
2. Si une notification "Erreur critique détectée" a été reçue, elle contient déjà le type d'exception, la route et la référence.
3. Avec la référence, rechercher la ligne de journal correspondante sur le serveur (`grep <référence> <fichier ou flux de logs>`) pour obtenir la trace technique complète.
4. Vérifier `GET /health` pour confirmer/infirmer un problème de base de données ou de stockage.
5. Si la base de données est indisponible : les erreurs seront classées `critical` (voir §5) — vérifier la connectivité MySQL, l'espace disque, les processus `mysqld`.
6. Une fois la cause corrigée, aucune action manuelle n'est nécessaire sur `ErrorLog`/les journaux : ils restent l'historique de l'incident.

## 11. Docker

Comme documenté en FONCTIONNALITÉ 16, `compose.yaml`/`compose.override.yaml` sont des artefacts non utilisés (ils pointent vers PostgreSQL alors que MOUMTOU fonctionne avec MySQL en déploiement traditionnel) : sans objet pour la journalisation/le monitoring, aucune modification nécessaire.
