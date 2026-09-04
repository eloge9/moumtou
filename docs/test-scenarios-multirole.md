# Scénarios de test manuel — Inscription, profil, multi-rôles, soutenances/jury, administration

Document complet pour tester vous-même, à la main, l'ensemble des fonctionnalités : inscription, connexion automatique, complétion de profil (champ par champ), rôles multiples, établissements, soutenances, jury (invitation, confirmation, notes, remarques, validation par le président), rappels de soutenance, filtres Explorer, administration des comptes, sécurité.

Cochez au fur et à mesure. Pour chaque erreur : notez le numéro du scénario, ce que vous avez fait, ce qui était attendu, ce qui s'est réellement passé.

---

## 1. Comptes de test — état actuel

Tous les comptes ci-dessous existent déjà (créés via le formulaire d'inscription pour #1/#2/#3, complétés directement en base pour #4 à #8 après que la limite d'inscription a été atteinte).

| #   | But du compte                                                  | E-mail                         | Mot de passe                   |
| --- | -------------------------------------------------------------- | ------------------------------ | ------------------------------ |
| 1   | Étudiant — candidat qui présente sa soutenance                 | `test.etudiant1@moumtou.dev`   | `test.etudiant1@moumtou.dev`   |
| 2   | Étudiant — second compte, pour tester la bascule entre comptes | `test.etudiant2@moumtou.dev`   | `test.etudiant2@moumtou.dev`   |
| 3   | Enseignant — **président** du jury, rattaché à IPNET           | `test.president@moumtou.dev`   | `test.president@moumtou.dev`   |
| 4   | Enseignant — **rapporteur** du jury, rattaché à IPNET          | `test.rapporteur@moumtou.dev`  | `test.rapporteur@moumtou.dev`  |
| 5   | Enseignant — **examinateur** du jury, rattaché à IPNET         | `test.examinateur@moumtou.dev` | `test.examinateur@moumtou.dev` |
| 6   | Recruteur (« MOUMTOU Test SARL »)                              | `test.recruteur@moumtou.dev`   | `test.recruteur@moumtou.dev`   |
| 7   | Talent qui cumule les rôles un par un après coup               | `test.multirole@moumtou.dev`   | `test.multirole@moumtou.dev`   |
| 8   | Compte jetable, destiné à être supprimé par l'admin            | `test.asupprimer@moumtou.dev`  | `test.asupprimer@moumtou.dev`  |

**Pour les comptes #4 à #8 : le mot de passe est exactement l'adresse e-mail.** Pour #1/#2/#3 : `TestMoumtou123` (déjà en votre possession).

**Compte administrateur** (déjà existant sur cette base de dev locale, ne pas recréer) :

| E-mail              | Mot de passe      |
| ------------------- | ----------------- |
| `admin@moumtou.com` | `AdminMoumtou123` |

> Note (livraison client) : ce compte venait des fixtures de démonstration. Pour toute **nouvelle** installation, les fixtures ne créent plus d'administrateur — voir `php bin/console app:create-admin` dans le README. Ce compte de dev local n'est pas affecté et reste utilisable tel quel pour vos tests manuels en cours.

**Établissement des enseignants** : les comptes #3/#4/#5 sont déjà rattachés à **IPNET INSTITUTE OF TECHNOLOGY** (établissement réel ajouté au catalogue entretemps), chacun avec sa propre fonction (Président du jury / Maître de conférences, Rapporteur pédagogique, Examinateur) — inutile de le refaire, passez directement aux scénarios du §4.

**Important pour le jury** : quand le candidat (#1) invitera les membres du jury (scénario F), saisissez **exactement** les e-mails des comptes #3/#4/#5 — c'est ce qui relie l'invitation au bon compte enseignant.

---

## 2. Établissements à créer

Le catalogue actuel ne contient que 4 établissements de démonstration (tous au Togo). Créez ces **2 nouveaux** via le bouton **« Mon établissement n'est pas dans la liste »** (jamais directement dans l'admin — le but est de tester le circuit self-service en entier) :

| #   | Nom à saisir                            | Type     | Pays | Ville |
| --- | --------------------------------------- | -------- | ---- | ----- |
| 1   | École Supérieure d'Informatique de Lomé | École    | Togo | Lomé  |
| 2   | Institut Universitaire de la Côte       | Institut | Togo | Aného |

---

## 3. Guide de remplissage — chaque champ, formulaire par formulaire

### 3.1 Profil de base (`Modifier mon profil`) — tout compte

| Champ                           | Obligatoire                                                         | Exemple à saisir                       |
| ------------------------------- | ------------------------------------------------------------------- | -------------------------------------- |
| Prénom                          | Oui (déjà rempli à l'inscription)                                   | —                                      |
| Nom                             | Oui (déjà rempli)                                                   | —                                      |
| Titre professionnel             | Non                                                                 | `Développeur Full Stack`               |
| Téléphone                       | Oui (déjà rempli)                                                   | —                                      |
| Numéro WhatsApp                 | Non                                                                 | `22890000001`                          |
| Autoriser les contacts WhatsApp | Non                                                                 | Oui                                    |
| Pays                            | Non                                                                 | `Togo`                                 |
| Ville                           | Non                                                                 | `Lomé`                                 |
| Biographie                      | Non (max 2000 caractères)                                           | Quelques phrases sur vous              |
| LinkedIn                        | Non (doit être un lien `linkedin.com`)                              | `https://linkedin.com/in/votre-profil` |
| GitHub                          | Non (doit être un lien `github.com`)                                | `https://github.com/votre-compte`      |
| Site personnel                  | Non (URL valide)                                                    | `https://exemple.dev`                  |
| Portfolio                       | Non (URL valide)                                                    | `https://exemple.dev/portfolio`        |
| Disponibilité                   | Non                                                                 | Stage / Alternance / CDI               |
| Établissement                   | Non ici (obligatoire seulement via « Devenir étudiant/enseignant ») | —                                      |
| Domaine / Mention / Spécialité  | Non ici                                                             | —                                      |
| Compétences                     | Non (cases à cocher)                                                | Cochez-en 2-3                          |
| Technologies                    | Non (tapez un mot puis Entrée pour l'ajouter)                       | `PHP`, `Symfony`, `MySQL`              |
| Photo de profil                 | Non (JPG/PNG/WebP, 3 Mo max)                                        | Une image quelconque                   |

### 3.2 Devenir étudiant (`/devenir-etudiant`)

Tous obligatoires — le rôle ne s'active pas sans les 4 :

| Champ         | Exemple                  |
| ------------- | ------------------------ |
| Établissement | Université de Lomé       |
| Domaine       | Sciences et Technologies |
| Mention       | Informatique             |
| Spécialité    | Génie Logiciel           |

### 3.3 Devenir enseignant (`/devenir-enseignant`)

| Champ                        | Obligatoire | Exemple                       |
| ---------------------------- | ----------- | ----------------------------- |
| Établissement                | Oui         | IPNET INSTITUTE OF TECHNOLOGY |
| Fonction à cet établissement | Non         | `Maître de conférences`       |

La fonction est propre à **chaque établissement** : un même enseignant peut être « Maître de conférences » à IPNET et « Vacataire » ailleurs. Pour ajouter un 2ᵉ (ou 3ᵉ) établissement avec sa propre fonction, utilisez le formulaire « Ajouter un établissement » dans **Mon espace enseignant** (§3.3 bis ci-dessous), pas le formulaire d'inscription initial.

### 3.3 bis Ajouter un établissement supplémentaire (`Mon espace enseignant`)

| Champ                              | Obligatoire | Exemple               |
| ---------------------------------- | ----------- | --------------------- |
| Établissement                      | Oui         | Institut des Sciences |
| Votre fonction à cet établissement | Non         | `Vacataire`           |

### 3.4 Devenir recruteur (`/recruteur/profil`)

| Champ                     | Obligatoire | Exemple                                |
| ------------------------- | ----------- | -------------------------------------- |
| Entreprise / organisation | Oui         | `MOUMTOU Test SARL`                    |
| Secteur d'activité        | Non         | `Technologies de l'information`        |
| Pays                      | Non         | `Togo`                                 |
| Ville                     | Non         | `Lomé`                                 |
| Description               | Non         | Quelques phrases sur l'entreprise      |
| Site web                  | Non         | `https://exemple-entreprise.com`       |
| LinkedIn                  | Non         | `https://linkedin.com/company/exemple` |
| E-mail professionnel      | Non         | `contact@exemple-entreprise.com`       |
| Téléphone professionnel   | Non         | `22890000002`                          |
| Logo                      | Non (image) | —                                      |

### 3.5 Publier un projet de type « Soutenance » (`/publier`)

Utilisez le compte **#1 `test.etudiant1`** pour ce projet — c'est lui qui présentera la soutenance testée plus loin.

| Champ                                                                        | Obligatoire                                            | Exemple                                                  |
| ---------------------------------------------------------------------------- | ------------------------------------------------------ | -------------------------------------------------------- |
| Type de projet                                                               | Oui                                                    | **Soutenance** (choisissez bien celui-ci, pas Personnel) |
| Nom du projet                                                                | Oui                                                    | `Plateforme de gestion des soutenances`                  |
| Thème                                                                        | Non                                                    | `Génie logiciel`                                         |
| Description courte                                                           | Non (160 car. max)                                     | Une phrase résumant le projet                            |
| Description détaillée                                                        | Non                                                    | Quelques paragraphes                                     |
| Date de réalisation                                                          | Non                                                    | Une date récente                                         |
| Technologies utilisées                                                       | Non (tags)                                             | `Symfony`, `MySQL`                                       |
| **Au moins une preuve est obligatoire** — remplissez-en au moins une parmi : |                                                        |                                                          |
| — Lien GitHub                                                                |                                                        | `https://github.com/exemple/projet`                      |
| — Vidéo YouTube                                                              |                                                        | `https://youtube.com/watch?v=xxxxxxxxxxx`                |
| — Site web                                                                   |                                                        | `https://exemple.dev`                                    |
| — Démo                                                                       |                                                        | `https://demo.exemple.dev`                               |
| — Lien du mémoire                                                            |                                                        | `https://exemple.dev/memoire.pdf`                        |
| — Autre preuve (titre + URL)                                                 |                                                        |                                                          |
| Photos                                                                       | Non                                                    | 1-2 images                                               |
| Document (type + titre + fichier)                                            | Non                                                    | Un PDF si vous en avez un sous la main                   |
| Domaine / Mention / Spécialité / Établissement                               | Non (ou « Autre (à préciser) » si absent du catalogue) | Mêmes valeurs que le profil étudiant                     |

---

## 4. Scénarios de test

### A — Inscription et connexion automatique

- [ ] **A1.** Inscrivez le compte #1 (choix **Étudiant**). Attendu : redirection directe vers **« Bienvenue sur MOUMTOU »**, déjà connecté.
- [ ] **A2.** Le bouton de la page Bienvenue mène directement vers **« Devenir étudiant »** (pas le profil général) — puisque « Étudiant » a été choisi à l'inscription.
- [ ] **A3.** Complétez immédiatement ce formulaire avec les valeurs du §3.2. Attendu : rôle **Étudiant** actif tout de suite après (badge visible).
- [ ] **A4.** _(Déjà fait pour tous les comptes #2 à #8 — vérifiez simplement que chacun se connecte correctement avec le mot de passe indiqué au §1, puis passez directement au §B.)_

### B — Compléter le profil de base (tous les comptes)

- [ ] **B1.** Pour chaque compte créé, allez dans **Modifier mon profil** et remplissez un maximum de champs du §3.1 (au minimum : titre professionnel, pays, ville, bio, une compétence, une technologie).
- [ ] **B2.** Vérifiez qu'après enregistrement, la bannière « Complétez votre profil » n'apparaît plus sur votre page de profil public.

### C — Multi-rôle : cumul et bascule entre comptes

- [ ] **C1.** Avec le compte #7 (`test.multirole`), allez dans **Modifier mon profil → Mes rôles**. Ajoutez **Étudiant**, puis **Enseignant**, puis **Recruteur**, l'un après l'autre. Après chaque ajout, vérifiez que **tous** les rôles précédents sont toujours affichés (rien ne disparaît).
- [ ] **C2. Bascule entre comptes étudiants.** Déconnectez-vous du compte #1, connectez-vous avec le compte #2 (`test.etudiant2`). Vérifiez que son profil, son cursus (établissement/domaine/mention/spécialité saisis pour lui) et ses éventuels projets sont bien **distincts** de ceux du compte #1 (aucun mélange entre les deux comptes).
- [ ] **C3.** Reconnectez-vous avec le compte #1 : ses informations doivent être exactement celles saisies pour lui, inchangées par la navigation sur le compte #2.
- [ ] **C4. Fonction différente par établissement.** _(Déjà en place : le compte #3 `test.president` est rattaché à IPNET — « Président du jury / Maître de conférences » — et à Institut Y sans fonction précisée.)_ Allez dans **Mon espace enseignant** avec ce compte et vérifiez que les deux établissements apparaissent bien dans la liste, chacun avec **sa propre fonction affichée en dessous du nom** (l'un renseigné, l'autre vide) — pas la même pour les deux. Vous pouvez aussi tester d'en ajouter un 3ᵉ vous-même pour valider le formulaire.

### D — Établissement absent du catalogue

- [ ] **D1.** Avec le compte #2, sur un formulaire avec sélection d'établissement, cliquez **« Mon établissement n'est pas dans la liste »**. Envoyez une demande pour **« École Supérieure d'Informatique de Lomé »** (§2).
- [ ] **D2.** Envoyez une seconde demande pour **« Institut Universitaire de la Côte »**.
- [ ] **D3.** En admin (**Administration → Établissements → Demandes d'établissements**), acceptez la première, refusez la seconde.
- [ ] **D4.** Vérifiez que la première apparaît maintenant dans tous les sélecteurs d'établissement, et que la seconde n'apparaît jamais.

### E — Soutenance : publication et annonce

- [ ] **E1.** Avec le compte #1, publiez le projet de soutenance décrit au §3.5.
- [ ] **E2.** Allez dans **Ma soutenance**. Attendu : le projet de soutenance apparaît, avec le formulaire **« Annoncer ma soutenance »**.
- [ ] **E3.** Renseignez une date **proche** (demain ou après-demain — important pour tester le rappel au scénario J), une heure, un lieu (ex. `Amphi B`). Validez.
- [ ] **E4.** Attendu : la soutenance passe au statut **« Annoncée »**, avec les boutons **Reporter** / **Annuler** disponibles.

### F — Inviter le jury (3 enseignants, rôles différents)

- [ ] **F1a. Recherche.** Toujours sur **Ma soutenance**, dans « Membres du jury », utilisez d'abord le bloc **« Rechercher un enseignant déjà inscrit sur MOUMTOU »** : tapez `test.president` (ou filtrez par établissement IPNET). Attendu : `test.president@moumtou.dev` apparaît dans les résultats avec son établissement affiché. Cliquez dessus : prénom/nom/e-mail (et l'établissement si une correspondance existe) se remplissent automatiquement dans le formulaire en dessous — il ne reste qu'à choisir le rôle **Président** et valider.
- [ ] **F1b.** Répétez pour `test.rapporteur` (rôle **Rapporteur**) et `test.examinateur` (rôle **Examinateur**) via la recherche.
- [ ] **F1c. Repli manuel.** Pour vérifier que la saisie manuelle reste disponible, testez aussi une invitation avec une adresse e-mail qui n'existe pas encore sur MOUMTOU (ex. `prof.externe@example.com`) directement dans le formulaire du bas, sans passer par la recherche. Attendu : ça fonctionne comme avant (invitation envoyée par e-mail à une personne sans compte).

- [ ] **F2.** Attendu après chaque envoi : message « Invitation envoyée à … ». Les 3 membres apparaissent dans la liste, statut **« En attente »**.
- [ ] **F3.** Connectez-vous avec `test.president@moumtou.dev`. Allez dans **Mon espace enseignant**. L'invitation doit apparaître avec le rôle **Président**.
- [ ] **F4.** Cliquez **« Confirmer ma participation »**. Statut passe à **Confirmé**.
- [ ] **F5.** Répétez F3-F4 avec `test.rapporteur` et `test.examinateur` (tous les deux confirment aussi).
- [ ] **F6.** Retour sur le compte #1 (`test.etudiant1`) : dans « Membres du jury » sur Ma soutenance, les 3 doivent maintenant afficher le statut **Confirmé**.

### G — Réalisation et vérification de la soutenance (règle des 2 confirmations)

- [ ] **G0. Champs grisés avant la date.** Juste après avoir annoncé la soutenance (§E), avec une date future, allez sur **Ma soutenance** : la section « Après la soutenance » (photos, vidéo, résultat) doit apparaître **grisée et non modifiable**, avec un message expliquant qu'elle sera disponible une fois la date passée. Le bouton **« Marquer la soutenance comme réalisée dès maintenant »** reste lui cliquable.
- [ ] **G1.** Avec le compte #1, sur **Ma soutenance**, cliquez **« Marquer la soutenance comme réalisée dès maintenant »**. Attendu : statut passe à **« Réalisée »**, et la section « Après la soutenance » redevient immédiatement modifiable (elle n'était grisée que le temps que la soutenance ne soit pas encore réalisée).
- [ ] **G2.** Connectez-vous avec `test.president@moumtou.dev`. Dans **Mon espace enseignant**, sous l'invitation, un bouton **« Je confirme que cette soutenance a bien eu lieu »** doit être visible. Cliquez dessus.
- [ ] **G3.** Attendu : message « En attente d'une 2ᵉ confirmation (1/2) ».
- [ ] **G4.** Connectez-vous avec `test.rapporteur@moumtou.dev`, faites la même confirmation.
- [ ] **G5.** Attendu : la soutenance passe au statut **« Vérifiée »** (2/2 confirmations atteintes). Vérifiez sur la page publique du projet (`test.etudiant1`) qu'un badge **« Soutenance vérifiée »** apparaît.

### H — Notes et remarques de chaque membre du jury, validation par le président

- [ ] **H1.** Toujours connecté en `test.rapporteur`, sur son invitation, ouvrez **« Résultat de la soutenance »**. Remplissez : note (ex. `15`), décision (**Admis(e)**), statut (**Réussie**), appréciation (« Bonne présentation, maîtrise correcte du sujet. »). Cliquez **« Enregistrer le résultat »**.
- [ ] **H2.** Connectez-vous en `test.examinateur`. Ouvrez le même bloc « Résultat de la soutenance » : la note/décision/statut du rapporteur doivent être visibles (partagés, pas propres à chaque juré). Modifiez l'appréciation avec votre propre remarque (« Bonne maîtrise technique, quelques lacunes sur la soutenance orale. »), et/ou ajustez la note si besoin. Enregistrez.
- [ ] **H3.** Connectez-vous en `test.president`. Ouvrez le même bloc : vérifiez que le président **peut aussi** saisir/ajuster note et appréciation comme les autres jurés.
- [ ] **H4. Validation finale.** Toujours en tant que président, un bouton supplémentaire doit apparaître : **« Valider le résultat final (président du jury) »** — ce bouton n'existe **que** pour le président, ni pour le rapporteur ni pour l'examinateur (revérifiez sur leurs comptes : le bouton n'y est pas). Cliquez dessus.
- [ ] **H5.** Attendu : le résultat passe en **« Validé »**. Reconnectez-vous en `test.rapporteur` ou `test.examinateur` : les champs (note, décision, statut, appréciation) doivent maintenant être **grisés/désactivés** — impossible de les modifier après validation.
- [ ] **H6.** Reconnectez-vous en `test.etudiant1`. Sur **Ma soutenance**, la carte « Résultat de ma soutenance » doit afficher le statut (Réussie), la note, l'appréciation, et « Validé ».
- [ ] **H7. Visibilité publique — visible par défaut dès la validation.** Sans rien cocher, allez directement sur la page publique du projet : note et appréciation doivent **déjà** être visibles (la validation du président au §H4 les a rendues publiques automatiquement). Vérifiez aussi sur **Ma soutenance** que les 3 cases (« Afficher le résultat », « ma note », « l'appréciation ») sont déjà cochées.
- [ ] **H8. Le candidat garde la main.** Décochez les 3 cases, enregistrez, revérifiez que la page publique ne montre plus rien de tout ça — utile par exemple en cas d'échec, pour ne pas exposer un résultat non souhaité.

### I — Report et annulation de soutenance (à tester séparément, sur un 2ᵉ projet si besoin)

- [ ] **I1.** Publiez un second projet de soutenance avec le compte #2 (`test.etudiant2`), annoncez-le.
- [ ] **I2.** Cliquez **« Reporter »**, indiquez un motif. Attendu : statut **« Reportée »**, l'ancienne date et le motif s'affichent.
- [ ] **I3.** Un formulaire « Nouvelle date de la soutenance » doit apparaître : renseignez-la. Attendu : retour au statut **« Annoncée »**.
- [ ] **I4.** Sur un 3ᵉ projet de soutenance (ou en réutilisant celui-ci), testez **« Annuler »** avec un motif. Attendu : statut **« Annulée »**, plus aucune action possible dessus (pas d'invitation de jury supplémentaire, pas de report).

### J — Rappel de soutenance

Cette fonctionnalité est un **envoi automatique par e-mail**, déclenché normalement par une tâche planifiée quotidienne (pas par un clic dans l'interface). Pour la tester manuellement, il faut exécuter une commande sur le serveur :

```bash
php bin/console app:defense:send-reminders
```

- [ ] **J1.** Assurez-vous d'avoir une soutenance **« Annoncée »** dont la date est dans les prochaines 48 heures (c'est pour ça qu'on a mis une date proche au scénario E3).
- [ ] **J2.** Exécutez la commande ci-dessus. Attendu : elle affiche « Rappel envoyé — [nom du projet] » et se termine par « 1 rappel(s) envoyé(s). ».
- [ ] **J3.** Réexécutez la même commande immédiatement. Attendu : « Aucune soutenance à rappeler pour le moment. » (elle ne renvoie jamais deux fois le même rappel).
- [ ] **J4.** Si un serveur de messagerie de test est configuré (Mailtrap, MailHog, etc.), vérifiez que le candidat et les membres confirmés du jury ont bien reçu un e-mail de rappel.

### K — Explorer et ses filtres

- [ ] **K1.** Allez sur **Explorer**. Les projets publiés (soutenance et autres) doivent apparaître.
- [ ] **K2.** Cochez un filtre de statut (« Vérifié par le jury » par exemple). Rechargement automatique, total mis à jour.
- [ ] **K3.** Cochez un filtre de technologie, un domaine, un établissement. Vérifiez que le total change à chaque fois de façon cohérente.
- [ ] **K4.** Cliquez **« Réinitialiser »** : tous les filtres se vident.

### L — Administration : gestion des comptes

- [ ] **L1.** En admin, **Utilisateurs** : vérifiez que tous les comptes créés apparaissent avec les bons rôles en tags (y compris les enseignants, bien liés via l'invitation de jury).
- [ ] **L2.** Filtrez par rôle **Enseignant** : les 3 comptes présidents/rapporteur/examinateur doivent apparaître (et eux seuls, avec les éventuels comptes multi-rôle).
- [ ] **L3.** Ouvrez la fiche du compte #8 (`test.asupprimer`). Testez **Avertir**, puis **Suspendre 7 jours**, puis **Réactiver**.
- [ ] **L4.** Sur cette même fiche, tentez de **Supprimer définitivement** sans taper « SUPPRIMER » : refusé.
- [ ] **L5.** Tapez exactement **SUPPRIMER**, motif optionnel, validez. Attendu : succès, retour à la liste, statut « Supprimé ».
- [ ] **L6.** Vérifiez que ce compte ne peut plus se connecter.
- [ ] **L7.** Sur votre propre fiche admin, vérifiez l'absence de bouton de sanction/suppression (juste des messages explicatifs).

### M — Sécurité

- [ ] **M1.** Avec un compte non-admin, tentez `/admin` dans la barre d'adresse : 403, pas d'erreur technique visible.
- [ ] **M2.** Vérifiez qu'aucun formulaire du site ne propose de choisir le rôle Administrateur.
- [ ] **M3.** Avec le compte `test.rapporteur` (non président), vérifiez encore une fois qu'aucun bouton de validation finale du résultat n'est accessible, même en modifiant l'URL à la main si vous êtes à l'aise avec les outils navigateur (doit renvoyer une erreur d'accès refusé).

### N — Panel admin : navigation

- [ ] **N1.** En admin, vérifiez la présence des boutons **« Interface utilisateur »** et **« Déconnexion »** en haut de l'espace Administration.

### O — Plusieurs soutenances pour un même compte

- [ ] **O1.** Avec le compte #1 (`test.etudiant1`), publiez un **second** projet de type Soutenance (nom différent, ex. « Deuxième projet de soutenance »). Ce compte a maintenant 2 projets Soutenance.
- [ ] **O2.** Allez sur **Ma soutenance**. Attendu : au lieu de la gestion directe, une **liste** apparaît avec les 2 projets, chacun avec son statut (« Annoncée »/« Vérifiée »/« À annoncer »…).
- [ ] **O3.** Cliquez sur le nouveau projet (pas encore annoncé) : sa propre page de gestion s'ouvre, avec le formulaire « Annoncer ma soutenance » — indépendante de la première (déjà vérifiée au §H).
- [ ] **O4.** Annoncez cette 2ᵉ soutenance avec une date différente. Retournez sur **Ma soutenance** : la liste doit refléter les 2 statuts distincts, sans que l'un affecte l'autre.
- [ ] **O5.** Avec un compte n'ayant qu'**une seule** soutenance (ex. `test.etudiant2`), vérifiez que **Ma soutenance** affiche directement sa gestion — pas de liste intermédiaire quand il n'y en a qu'une (aucun clic supplémentaire nécessaire).

### P — Vérification directe d'une soutenance par l'admin

- [ ] **P1.** En admin, ouvrez la fiche du 2ᵉ projet de soutenance (§O4) via **Administration → Projets**. Dans le bloc « Soutenance », un bouton **« Vérifier directement (admin) »** doit être visible (la soutenance est encore « Annoncée », sans confirmation du jury).
- [ ] **P2.** Cliquez dessus (confirmez la boîte de dialogue). Attendu : la soutenance passe directement à **« Vérifiée »**, sans attendre les 2 confirmations du jury — le projet associé passe aussi à **« Vérifié »**.
- [ ] **P3.** Revérifiez que le bouton a disparu (une soutenance déjà vérifiée ne peut plus l'être une seconde fois), et que ceci apparaît dans **Journal d'administration**.

---

## 5. En cas d'erreur trouvée

Notez pour chaque erreur :

1. Le numéro du scénario (ex. « H4 »).
2. Ce que vous avez fait exactement, avec quel compte.
3. Ce qui était attendu (voir ci-dessus).
4. Ce qui s'est réellement passé (message d'erreur exact si possible, capture d'écran si possible).

Envoyez-moi cette liste et je corrige au fur et à mesure.
