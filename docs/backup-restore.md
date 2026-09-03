# Sauvegarde, restauration et continuité de service — MOUMTOU

Ce document décrit la stratégie de sauvegarde de MOUMTOU (FONCTIONNALITÉ 16). Il s'adresse aux administrateurs système ayant accès à la ligne de commande du serveur.

## 1. Ce qui est sauvegardé

| Élément | Sauvegardé ? | Où |
|---|---|---|
| Base de données MySQL | Oui | `mysqldump` compressé |
| Photos de projets | Oui | `public/uploads/projects` |
| Avatars | Oui | `public/uploads/avatars` |
| Logos d'établissements | Oui | `public/uploads/institutions` |
| Logos recruteurs | Oui | `public/uploads/recruiters` |
| Preuves externes (GitHub, YouTube, site, mémoire en lien) | **Non** | MOUMTOU ne stocke que l'URL, jamais le fichier — rien à sauvegarder |
| `.env`, `.env.local`, secrets, mots de passe, clés API | **Jamais** | Volontairement exclus de toute sauvegarde |

## 2. Backup manuel

```bash
# Base de données seule
php bin/console app:backup:database manual --force

# Médias seuls
php bin/console app:backup:media manual --force

# Sauvegarde complète (base + médias + manifeste)
php bin/console app:backup:full manual --force
```

`--force` outrepasse `BACKUP_ENABLED=false` si nécessaire ; `--dry-run` (backup base/médias uniquement) affiche ce qui serait fait sans rien exécuter.

Chaque commande affiche un résultat structuré :

```text
BACKUP SUCCESS
Database: OK
Integrity: OK
Size: 12.1 Ko
Checksum: 55548a0f...
File: var/backups/moumtou-database-manual-prod-20260903-020000.sql.gz
```

ou en cas d'échec :

```text
BACKUP FAILED
Reason: mysqldump est introuvable sur ce serveur (PATH).
```

## 3. Où sont stockées les sauvegardes

Par défaut : `var/backups/` (hors de `public/`, donc **jamais servi par le serveur web** — aucune URL ne peut y donner accès).

Nom des fichiers :

```text
moumtou-database-<palier>-<env>-<AAAAMMJJ-HHMMSS>.sql.gz(.sha256)
moumtou-media-<palier>-<env>-<AAAAMMJJ-HHMMSS>.tar.gz(.sha256)
moumtou-manifest-<palier>-<env>-<AAAAMMJJ-HHMMSS>.json
history.jsonl   (journal de toutes les opérations, lu par l'admin — voir §7)
```

`<palier>` vaut `daily`, `weekly`, `monthly` ou `manual`.

**⚠️ Stockage distant.** `var/backups/` reste sur le même disque que l'application : en cas de panne matérielle complète du serveur, ces fichiers sont perdus avec le reste. Ce dépôt de code ne peut pas décider unilatéralement d'un fournisseur de stockage distant (aucune infrastructure de ce type n'existe actuellement dans le projet). **Recommandation de production, à mettre en place par l'opérateur d'infrastructure** : copier régulièrement `var/backups/` vers un emplacement séparé (rsync vers un second serveur, disque externe, ou stockage objet déjà utilisé ailleurs dans l'organisation) — par exemple une tâche cron `rsync -a var/backups/ user@serveur-secondaire:/backups/moumtou/` après chaque sauvegarde.

## 4. Intégrité

Chaque sauvegarde produit :
1. un fichier `.sql.gz` ou `.tar.gz` ;
2. un fichier `.sha256` contenant son empreinte (`sha256sum <fichier>` doit correspondre) ;
3. une entrée dans `var/backups/history.jsonl` (taille, durée, empreinte, statut).

La restauration (§5) vérifie automatiquement cette empreinte avant de restaurer quoi que ce soit : un fichier corrompu ou altéré est rejeté (`Empreinte SHA-256 invalide`).

## 5. Restauration

**Ces commandes ne sont jamais accessibles depuis le web — CLI uniquement, avec `--force` obligatoire.** Ne jamais exécuter contre la production pour "tester" — utilisez un environnement de test/staging (voir §7).

### A. Base de données seule

```bash
php bin/console app:restore:database var/backups/moumtou-database-daily-prod-20260903-020000.sql.gz --force
```

### B. Médias seuls

```bash
php bin/console app:restore:media var/backups/moumtou-media-daily-prod-20260903-020000.tar.gz --force
```

Par défaut, restaure vers `public/uploads` réel. Le contenu actuel est d'abord déplacé vers `var/uploads-backup-<horodatage>/` (sauf `--no-safety-copy`) pour pouvoir annuler en cas d'erreur.

### C. Restauration complète

```text
1. Préparer l'environnement (dépendances, .env.local avec les vrais secrets, base MySQL vide/accessible)
2. php bin/console app:restore:database <dump>.sql.gz --force
3. php bin/console app:restore:media <archive>.tar.gz --force
4. php bin/console doctrine:schema:validate   (vérifier la cohérence)
5. php bin/console cache:clear
6. Tester : connexion, un projet public, une image de profil
7. Remettre le service en ligne
```

## 6. Rétention

Configurable via `.env` (jamais un secret) :

```env
BACKUP_ENABLED=true
BACKUP_RETENTION_DAILY=7
BACKUP_RETENTION_WEEKLY=4
BACKUP_RETENTION_MONTHLY=6
```

Après chaque sauvegarde réussie d'un palier `daily`/`weekly`/`monthly`, seules les `N` sauvegardes les plus récentes de **ce même palier** sont conservées, les plus anciennes sont supprimées automatiquement (fichier + `.sha256`). Les sauvegardes `manual` ne sont jamais purgées automatiquement.

## 7. Automatisation

Aucun système de tâches planifiées n'existe déjà dans MOUMTOU (pas de Symfony Scheduler, pas de worker Docker dédié — le `compose.yaml` du dépôt est un reste de scaffolding Symfony/PostgreSQL jamais utilisé, l'application tourne réellement sur MySQL hors Docker). La planification passe donc par le cron du système d'exploitation, comme pour `app:defense:send-reminders` déjà en place :

```cron
# Quotidien à 2h
0 2 * * *  cd /chemin/vers/moumtou && php bin/console app:backup:full daily >> var/log/backup.log 2>&1

# Hebdomadaire le dimanche à 3h
0 3 * * 0  cd /chemin/vers/moumtou && php bin/console app:backup:full weekly >> var/log/backup.log 2>&1

# Mensuel le 1er du mois à 4h
0 4 1 * *  cd /chemin/vers/moumtou && php bin/console app:backup:full monthly >> var/log/backup.log 2>&1
```

Sous Windows (Planificateur de tâches), créer une tâche exécutant `php.exe bin\console app:backup:full daily` avec les mêmes horaires.

## 8. Alertes

En cas d'échec, tous les comptes `ROLE_ADMIN` reçoivent une notification (in-app + e-mail, catégorie sécurité — toujours activée, non désactivable) via le système de notifications existant de MOUMTOU — aucun nouveau canal d'alerte n'a été créé.

## 9. Tableau de bord admin

`Administration → Sauvegardes` (`/admin/sauvegardes`, réservé `ROLE_ADMIN`) affiche : dernière sauvegarde par type, statut, taille, durée, dernier test de restauration, et l'historique complet. Lecture seule — aucun bouton ne déclenche d'opération depuis le web (§5).

## 10. Scénario : suppression accidentelle d'un projet

```text
1. Identifier l'heure approximative de l'incident (journal d'administration existant).
2. Identifier la dernière sauvegarde de base de données antérieure à cet incident
   (var/backups/, ou tableau de bord "Sauvegardes").
3. Restaurer ce dump dans une base MySQL TEMPORAIRE distincte
   (ex. `mysql -u root -p moumtou_recuperation < dump.sql`, jamais la prod).
4. Retrouver la ligne du projet supprimé dans cette base temporaire.
5. Réinjecter uniquement les lignes nécessaires dans la production
   (INSERT ciblé, jamais un remplacement complet — cahier §18).
6. Supprimer la base temporaire une fois la récupération terminée.
```

## 11. Scénario : panne serveur complète

```text
1. Provisionner un nouvel environnement (PHP 8.2+, MySQL 8, extensions requises).
2. Déployer le code de l'application (dépôt Git).
3. composer install --no-dev --optimize-autoloader
4. Recréer .env.local avec les vrais secrets (DATABASE_URL, APP_SECRET, clés OAuth/mail —
   à conserver séparément dans un coffre-fort à secrets, jamais dans une sauvegarde).
5. php bin/console app:restore:database <dernier dump> --force
6. php bin/console app:restore:media <dernière archive> --force
7. php bin/console doctrine:schema:validate
8. php bin/console cache:clear
9. Configurer le serveur web (vhost, HTTPS, .htaccess de public/uploads).
10. Tester : connexion, un projet public, une soutenance, une photo.
11. Remettre le service en ligne (DNS/répartiteur de charge).
```

## 12. Sécurité des sauvegardes

- Emplacement (`var/backups/`) hors de `public/` : aucune URL ne peut y donner accès (vérifié — voir tests).
- Accès aux commandes `app:backup:*`/`app:restore:*` : ligne de commande serveur uniquement, jamais une route HTTP.
- Le mot de passe MySQL n'apparaît jamais dans une commande ni dans les journaux (transmis via la variable d'environnement `MYSQL_PWD` du sous-processus, jamais en argument visible).
- Aucun secret (`.env*`, mots de passe, jetons, clés) n'est inclus dans une sauvegarde.
- Tableau de bord de consultation réservé `ROLE_ADMIN`.

## 13. Limite connue

Le stockage distant (§3) n'est pas automatisé par ce dépôt : c'est une tâche d'infrastructure à mettre en place par l'opérateur (rsync/stockage objet déjà utilisé ailleurs dans l'organisation), documentée mais non exécutable depuis le code applicatif seul.
