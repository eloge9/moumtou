# MOUMTOU

## 1. Présentation

MOUMTOU est une plateforme web où les talents (étudiants, professionnels, enseignants) publient des **preuves concrètes de leurs réalisations** — projets logiciels, hardware, entrepreneuriaux, de recherche ou de soutenance académique — plutôt que de simples déclarations. Chaque preuve peut être vérifiée par un jury ou par l'administration, ce qui permet aux recruteurs de découvrir des talents sur la base de réalisations vérifiées.

## 2. Fonctionnalités principales

- **Multi-rôle** : tout compte reçoit d'abord le rôle TALENT ; il peut ensuite activer STUDENT, TEACHER et/ou RECRUITER en plus (jamais en remplacement), chacun avec ses propres informations complémentaires.
- **Projets** : publication (logiciel, hardware, entrepreneurial, recherche, soutenance), preuves (GitHub, site, vidéo YouTube, documents, photos), statuts de modération (en attente → publié → vérifié), QR code et partage.
- **Soutenances** : annonce (date/lieu/établissement/spécialité), invitation d'un jury (recherche par nom/établissement ou saisie manuelle), acceptation/refus, validation par au moins deux membres du jury, résultat (note, mention, appréciation) avec contrôle de visibilité par le candidat.
- **Recrutement** : profil recruteur, recherche de talents multi-filtres, favoris, demandes de contact (acceptées/refusées), aucune donnée de contact privée transmise — uniquement les canaux déjà publics du talent (WhatsApp, LinkedIn, GitHub, site).
- **Établissements** : catalogue public, fiche établissement avec ses talents/projets/soutenances, demande d'ajout d'un établissement absent du catalogue.
- **Modération & administration** : utilisateurs, projets, signalements, commentaires, évaluations suspectes, sanctions, vérification/dépublication, audit, sauvegardes.
- **Notifications** : en application et par e-mail (nouvelle demande de contact, invitation jury, projet vérifié, sanction, etc.).
- **Sécurité** : CSRF, en-têtes de sécurité (CSP, X-Frame-Options…), limitation de débit (inscriptions, commentaires, signalements, demandes de contact), suppression définitive de compte avec anonymisation des données nécessaires à l'audit.

## 3. Architecture

```text
Frontend  : Twig (rendu serveur) + Symfony AssetMapper (pas de Node.js/npm requis) + Stimulus/JS natif
Backend   : Symfony 7.4 (PHP 8.2+)
Database  : MySQL 8.0 + Doctrine ORM/Migrations
Storage   : système de fichiers local (public/uploads/{avatars,projects,institutions,recruiters})
Email     : Symfony Mailer (un seul MAILER_DSN, ex. SMTP), templates Twig dans templates/emails/
QR Code   : endroid/qr-code (génération à la volée, aucune donnée privée exposée)
Tests     : PHPUnit (fonctionnels + unitaires), 300+ tests
```

Application monolithique classique (pas d'API séparée front/back, pas de CORS à configurer) : chaque page est rendue côté serveur par Symfony/Twig.

## 4. Prérequis

- **OS** : Linux, macOS ou Windows.
- **Git**.
- **PHP 8.2 ou supérieur**, avec les extensions `ctype`, `iconv`, `pdo_mysql`, `gd` (redimensionnement des photos), `intl`.
- **Composer 2**.
- **MySQL 8.0** (ou MariaDB compatible — voir `.env.example`), installé nativement **ou** via Docker (voir §17).
- **Aucun Node.js/npm requis** (AssetMapper sert les assets directement, sans étape de build).

## 5. Cloner le projet

```bash
git clone <URL_DU_DEPOT>
cd moumtou
```

*(Remplacez `<URL_DU_DEPOT>` par l'URL réelle du dépôt Git transmis.)*

## 6. Configuration (.env)

Symfony charge `.env` (valeurs par défaut, commité, sans secret réel) puis `.env.local` (vos valeurs réelles, **jamais commité** — voir `.gitignore`). Un modèle complet et commenté est fourni dans `.env.example` :

```bash
cp .env.example .env.local
```

Puis éditez `.env.local` et renseignez au minimum `APP_SECRET` (une chaîne aléatoire — `php -r "echo bin2hex(random_bytes(16));"` en génère une) et `DATABASE_URL`.

| Variable | Rôle |
|---|---|
| `APP_ENV` | `dev` en développement, `prod` en production |
| `APP_SECRET` | Clé secrète interne Symfony (CSRF, tokens signés) — aléatoire, propre à chaque installation |
| `DATABASE_URL` | Connexion MySQL (utilisateur/mot de passe/hôte/port/nom de base) |
| `MAILER_DSN` | Transport d'envoi d'e-mail — `null://null` en dev (aucun envoi réel), un DSN SMTP réel en production |
| `GOOGLE_OAUTH_CLIENT_ID` / `_SECRET` | Connexion via Google (optionnel — laissez vide pour désactiver le bouton) |
| `FACEBOOK_OAUTH_CLIENT_ID` / `_SECRET` | Connexion via Facebook (optionnel) |
| `LINKEDIN_OAUTH_CLIENT_ID` / `_SECRET` | Connexion via LinkedIn (optionnel) |
| `BACKUP_ENABLED`, `BACKUP_RETENTION_*` | Activation et rétention des sauvegardes automatiques (aucun secret) |

Aucune de ces variables ne doit contenir de vraie valeur dans un fichier commité (`.env`, `.env.example`, `.env.test`) — uniquement dans `.env.local`.

## 7. Base de données

```bash
composer install
php bin/console doctrine:database:create      # crée la base si elle n'existe pas encore
php bin/console doctrine:migrations:migrate    # applique toutes les migrations
```

Données de référence (établissements, technologies, compétences, classification domaine/mention/spécialité) — **recommandé** pour avoir un catalogue non vide dans les formulaires dès le premier lancement :

```bash
php bin/console doctrine:fixtures:load
```

Ces fixtures ne créent **aucun compte utilisateur** (voir §11 pour l'administrateur).

## 8. Installation des dépendances

```bash
composer install
```

(Aucune commande `npm install` n'est nécessaire — AssetMapper gère les assets JS/CSS directement depuis `assets/` et `importmap.php`.)

## 9. Lancement

```bash
symfony server:start
# ou, sans le binaire Symfony CLI :
php -S 127.0.0.1:8000 -t public
```

L'application est alors accessible sur `http://127.0.0.1:8000` (backend et frontend confondus — il n'y a qu'un seul serveur à lancer).

## 10. Tests

```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

## 11. Création du premier administrateur

**Aucun compte administrateur n'est fourni par défaut** — ni dans le code, ni dans les fixtures, ni dans une migration. Vous créez le vôtre, avec vos propres identifiants, après avoir migré la base :

```bash
php bin/console app:create-admin
```

La commande demande, de façon interactive :

```text
Prénom :
Nom :
Email administrateur :
Téléphone (optionnel) :
Mot de passe :
Confirmation du mot de passe :
```

Puis confirme :

```text
✓ Compte administrateur créé avec succès.
Email : <l'adresse que vous avez choisie>
Rôle  : ADMIN
```

Le mot de passe n'est jamais affiché à l'écran ni journalisé. La commande refuse de créer un second administrateur par erreur : si un administrateur existe déjà, elle s'arrête avec un message clair (utilisez le bouton "Rendre administrateur" dans `/admin/utilisateurs` pour en ajouter un depuis l'interface, une fois connecté ; ou relancez `app:create-admin --force` si vous savez ce que vous faites).

Connectez-vous ensuite sur `/connexion` avec l'adresse et le mot de passe choisis.

## 12. E-mail / SMTP

Un seul réglage : `MAILER_DSN` dans `.env.local`. Exemple pour un serveur SMTP classique :

```env
MAILER_DSN=smtp://UTILISATEUR:MOT_DE_PASSE@smtp.exemple.com:587
```

En développement, `MAILER_DSN=null://null` n'envoie rien réellement (utile pour ne pas dépendre d'un vrai compte e-mail pendant le développement) ; les liens de confirmation/réinitialisation restent générés et visibles (message flash en environnement `dev`).

**Tester la configuration réelle** sans passer par l'interface :

```bash
php bin/console app:mailer:diagnose destinataire@exemple.com
```

Cette commande vérifie la configuration, tente une vraie connexion/authentification/envoi, et rappelle explicitement qu'un envoi *accepté* par le serveur SMTP n'est pas une garantie de livraison finale (filtrage anti-spam, réputation de l'expéditeur, SPF/DKIM/DMARC — hors du contrôle de l'application). Chaque envoi réel (succès ou échec) est journalisé dans `var/log/{env}-*.log` avec le préfixe `[EMAIL]`, sans jamais exposer le mot de passe SMTP.

## 13. Authentification sociale (OAuth)

Google, Facebook et LinkedIn sont pris en charge (cahier des charges §5.1). Chaque fournisseur est **indépendant et optionnel** : si ses identifiants (`*_CLIENT_ID` / `*_CLIENT_SECRET`) sont vides dans `.env.local`, le bouton correspondant affiche un message explicite au lieu de planter.

Pour l'activer : créez une application OAuth chez le fournisseur, déclarez comme URI de redirection `https://votre-domaine/connexion/{google|facebook|linkedin}/callback`, puis renseignez les identifiants obtenus dans `.env.local` (jamais dans un fichier commité).

## 14. Stockage des fichiers

Tous les fichiers déposés par les utilisateurs sont stockés localement sous `public/uploads/` :

```text
public/uploads/
├── avatars/       photos de profil
├── projects/      photos et documents de preuve des projets
├── institutions/  logos d'établissement
└── recruiters/    logos d'entreprise recruteur
```

Ce dossier n'est jamais versionné dans Git (voir `.gitignore`). Une sauvegarde régulière de `public/uploads/` est recommandée en production (voir `docs/backup-restore.md`).

## 15. QR Code

Chaque projet publié dispose d'un QR code généré à la volée (bibliothèque `endroid/qr-code`), pointant vers sa page publique — aucune donnée privée n'y est encodée.

## 16. Production / Build

Aucune étape de build front-end n'est nécessaire (pas de Node.js). Pour préparer un déploiement :

```bash
composer install --no-dev --optimize-autoloader
APP_ENV=prod php bin/console cache:clear
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod php bin/console asset-map:compile
```

## 17. Déploiement

L'application est un projet Symfony standard : elle se déploie sur tout hébergement supportant PHP 8.2+ et MySQL 8.0 (serveur classique avec Nginx/Apache + PHP-FPM, ou tout hébergeur compatible Symfony). Aucune infrastructure spécifique (queue, cache distribué, stockage objet) n'est requise par le code actuel.

Un `compose.yaml`/`compose.override.yaml` est fourni pour lancer localement, via Docker, un MySQL et un serveur de capture d'e-mails de test (Mailpit) sans les installer nativement :

```bash
docker compose up -d
# MySQL sur localhost:3306, Mailpit sur http://localhost:8025 (aucun e-mail réel envoyé)
```

Cela ne remplace pas le lancement de l'application elle-même (§9), qui reste un processus PHP classique.

## 18. Sécurité

- **Secrets** : uniquement dans `.env.local` (ignoré par Git) — jamais dans `.env`, `.env.example` ni le code.
- **Mots de passe** : hashés (algorithme automatique Symfony, `password_hashers: auto`), jamais stockés ni journalisés en clair.
- **Aucun compte par défaut** : le premier administrateur est créé par la personne qui installe (§11).
- **CSRF** : jeton vérifié sur tous les formulaires de mutation.
- **En-têtes de sécurité** : CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` sur chaque réponse.
- **Limitation de débit** : inscriptions, commentaires, signalements, demandes de contact (voir `config/packages/rate_limiter.yaml`).
- **CORS** : sans objet — application monolithique rendue côté serveur, pas d'API séparée sur un autre domaine.
- **Suppression de compte** : irréversible, avec anonymisation des données nécessaires à l'audit (jamais une suppression physique brute qui casserait l'intégrité référentielle).

En production, définissez `APP_ENV=prod` (désactive le profiler/debug toolbar) et générez un `APP_SECRET` propre à l'installation.

## 19. Dépannage

| Problème | Cause possible | Solution |
|---|---|---|
| MySQL ne démarre pas / connexion refusée | Service MySQL arrêté, ou mauvais hôte/port dans `DATABASE_URL` | Vérifier que MySQL tourne (`mysqladmin ping`), vérifier `DATABASE_URL` dans `.env.local` |
| Port 8000 déjà utilisé | Un autre processus écoute déjà dessus | `symfony server:start --port=8001`, ou arrêter l'autre processus |
| Erreur `.env` / variable manquante | `.env.local` absent ou incomplet | `cp .env.example .env.local` puis renseigner les valeurs |
| Erreur au moment des migrations | Base non créée, ou migrations non à jour | `php bin/console doctrine:database:create` puis `doctrine:migrations:migrate` |
| Erreur SMTP / e-mail non reçu | DSN invalide, identifiants faux, ou message filtré comme spam après acceptation | `php bin/console app:mailer:diagnose <email>` pour diagnostiquer étape par étape ; vérifier le dossier spam du destinataire |
| Page blanche / erreur 500 | Erreur applicative | Consulter `var/log/dev-*.log` (dev) ou les logs configurés (`stderr` en prod) |
| Upload de fichier refusé | Fichier trop volumineux ou format non autorisé | Vérifier les limites dans `config/services.yaml` (`app.project_photo_max_size`, etc.) et `php.ini` (`upload_max_filesize`, `post_max_size`) |
| Connexion OAuth échoue | Identifiants vides ou URI de redirection mal déclarée chez le fournisseur | Renseigner `*_CLIENT_ID`/`*_CLIENT_SECRET` dans `.env.local`, vérifier l'URI de callback déclarée |
| QR code illisible ou lien cassé | Projet dépublié/supprimé après génération du QR | Le QR pointe toujours vers l'URL publique du projet ; si le projet n'est plus public, la page renverra une erreur adaptée |
| `composer install` échoue | Version PHP insuffisante ou extension manquante | Vérifier PHP ≥ 8.2 et les extensions listées en §4 |
| Tests qui échouent en local | Base de test absente/désynchronisée | `php bin/console doctrine:database:create --env=test` puis `doctrine:migrations:migrate --env=test` |

## 20. Structure du projet

```text
moumtou/
├── assets/            JS/CSS source (AssetMapper)
├── bin/console
├── config/            Configuration Symfony (packages, routes, services)
├── docs/              Documentation complémentaire (scénarios, sauvegardes, monitoring)
├── migrations/        Migrations Doctrine
├── public/            Point d'entrée web + fichiers uploadés
├── src/
│   ├── Command/        Commandes console (création admin, sauvegardes, rappels…)
│   ├── Controller/      Contrôleurs (public + Admin/)
│   ├── DataFixtures/    Données de référence de démonstration
│   ├── Entity/          Entités Doctrine
│   ├── Enum/            Enumérations (statuts, rôles métier, types…)
│   ├── Form/            Formulaires Symfony
│   ├── Mailer/          Décorateur de journalisation des e-mails
│   ├── Repository/      Repositories Doctrine
│   ├── Security/        Mailers spécialisés, vérificateurs de lien signé
│   ├── Service/         Logique métier (uploads, QR code, notifications…)
│   └── EventListener/   Écouteurs (erreurs, sécurité, journalisation)
├── templates/          Vues Twig
├── tests/
│   ├── Functional/      Tests bout en bout (base réelle, requêtes HTTP simulées)
│   └── Unit/            Tests unitaires isolés
└── translations/
```

## 21. Rôles

| Rôle | Attribution | Portée |
|---|---|---|
| `ROLE_TALENT` | Automatique dès l'inscription | Base commune à tout compte : profil, publication de projets |
| `ROLE_STUDENT` | Activé en complétant le formulaire dédié (établissement, domaine, mention, spécialité) | Ajoute la gestion des soutenances |
| `ROLE_TEACHER` | Activé en complétant le formulaire dédié (établissement, fonction propre à cet établissement) | Permet d'être invité comme membre de jury, tableau de bord enseignant |
| `ROLE_RECRUITER` | Activé en complétant le profil recruteur | Recherche de talents, favoris, demandes de contact |
| `ROLE_ADMIN` | Uniquement via `app:create-admin` (premier compte) ou depuis l'interface admin (comptes suivants) | Accès complet à `/admin` |

**Multi-rôle réel** : les rôles s'additionnent, ils ne se remplacent jamais. Un même compte peut être à la fois étudiant, enseignant (dans un autre établissement) et recruteur.

## 22. Documentation complémentaire

- [`docs/scenarios.md`](docs/scenarios.md) — scénarios fonctionnels détaillés (inscription, rôles, projets, soutenances, jury, recrutement, administration, cas d'erreur…).
- [`docs/backup-restore.md`](docs/backup-restore.md) — sauvegarde et restauration.
- [`docs/errors-logs-monitoring.md`](docs/errors-logs-monitoring.md) — journalisation, corrélation des erreurs, alertes.

## 23. Licence

Propriétaire (`"license": "proprietary"` dans `composer.json`) — aucun texte de licence supplémentaire n'est présent dans ce dépôt à ce jour.

## 24. Contact

Aucune adresse de contact officielle n'est déclarée dans ce dépôt à ce jour.
