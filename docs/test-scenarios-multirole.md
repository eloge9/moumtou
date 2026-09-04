# Scénarios de test manuel — Inscription, profil, multi-rôles, soutenances/jury, administration

Document complet pour tester vous-même, à la main, l'ensemble des fonctionnalités : inscription, connexion automatique, complétion de profil (champ par champ), rôles multiples, établissements, soutenances, jury (invitation, confirmation, notes, remarques, validation par le président), rappels de soutenance, filtres Explorer, administration des comptes, sécurité.

Cochez au fur et à mesure. Pour chaque erreur : notez le numéro du scénario, ce que vous avez fait, ce qui était attendu, ce qui s'est réellement passé.

---

## 1. Comptes à créer vous-même

Tous avec le même mot de passe pour simplifier : **`TestMoumtou123`**.

| # | But du compte | E-mail | Choix à l'inscription |
|---|---|---|---|
| 1 | Étudiant — candidat qui présente sa soutenance | `test.etudiant1@moumtou.dev` | Étudiant |
| 2 | Étudiant — second compte, pour tester la bascule entre comptes | `test.etudiant2@moumtou.dev` | Étudiant |
| 3 | Enseignant — sera **président** du jury | `test.president@moumtou.dev` | Enseignant |
| 4 | Enseignant — sera **rapporteur** du jury | `test.rapporteur@moumtou.dev` | Enseignant |
| 5 | Enseignant — sera **examinateur** du jury | `test.examinateur@moumtou.dev` | Enseignant |
| 6 | Recruteur | `test.recruteur@moumtou.dev` | Recruteur |
| 7 | Talent qui cumule les rôles un par un après coup | `test.multirole@moumtou.dev` | Talent |
| 8 | Compte jetable, destiné à être supprimé par l'admin | `test.asupprimer@moumtou.dev` | Talent |

**Compte administrateur** (déjà existant, ne pas recréer) :

| E-mail | Mot de passe |
|---|---|
| `admin@moumtou.com` | `AdminMoumtou123` |

**Important pour le jury** : quand le candidat (#1) invitera les membres du jury, il devra saisir **exactement** les e-mails des comptes #3/#4/#5 — c'est ce qui relie automatiquement l'invitation au bon compte enseignant (et lui accorde le rôle Enseignant s'il ne l'a pas déjà).

---

## 2. Établissements à créer

Le catalogue actuel ne contient que 4 établissements de démonstration (tous au Togo). Créez ces **2 nouveaux** via le bouton **« Mon établissement n'est pas dans la liste »** (jamais directement dans l'admin — le but est de tester le circuit self-service en entier) :

| # | Nom à saisir | Type | Pays | Ville |
|---|---|---|---|---|
| 1 | École Supérieure d'Informatique de Lomé | École | Togo | Lomé |
| 2 | Institut Universitaire de la Côte | Institut | Togo | Aného |

---

## 3. Guide de remplissage — chaque champ, formulaire par formulaire

### 3.1 Profil de base (`Modifier mon profil`) — tout compte

| Champ | Obligatoire | Exemple à saisir |
|---|---|---|
| Prénom | Oui (déjà rempli à l'inscription) | — |
| Nom | Oui (déjà rempli) | — |
| Titre professionnel | Non | `Développeur Full Stack` |
| Téléphone | Oui (déjà rempli) | — |
| Numéro WhatsApp | Non | `22890000001` |
| Autoriser les contacts WhatsApp | Non | Oui |
| Pays | Non | `Togo` |
| Ville | Non | `Lomé` |
| Biographie | Non (max 2000 caractères) | Quelques phrases sur vous |
| LinkedIn | Non (doit être un lien `linkedin.com`) | `https://linkedin.com/in/votre-profil` |
| GitHub | Non (doit être un lien `github.com`) | `https://github.com/votre-compte` |
| Site personnel | Non (URL valide) | `https://exemple.dev` |
| Portfolio | Non (URL valide) | `https://exemple.dev/portfolio` |
| Disponibilité | Non | Stage / Alternance / CDI |
| Établissement | Non ici (obligatoire seulement via « Devenir étudiant/enseignant ») | — |
| Domaine / Mention / Spécialité | Non ici | — |
| Compétences | Non (cases à cocher) | Cochez-en 2-3 |
| Technologies | Non (tapez un mot puis Entrée pour l'ajouter) | `PHP`, `Symfony`, `MySQL` |
| Photo de profil | Non (JPG/PNG/WebP, 3 Mo max) | Une image quelconque |

### 3.2 Devenir étudiant (`/devenir-etudiant`)

Tous obligatoires — le rôle ne s'active pas sans les 4 :

| Champ | Exemple |
|---|---|
| Établissement | Université de Lomé |
| Domaine | Sciences et Technologies |
| Mention | Informatique |
| Spécialité | Génie Logiciel |

### 3.3 Devenir enseignant (`/devenir-enseignant`)

| Champ | Obligatoire | Exemple |
|---|---|---|
| Établissement | Oui | Université de Lomé |
| Fonction | Non | `Maître de conférences` |

### 3.4 Devenir recruteur (`/recruteur/profil`)

| Champ | Obligatoire | Exemple |
|---|---|---|
| Entreprise / organisation | Oui | `MOUMTOU Test SARL` |
| Secteur d'activité | Non | `Technologies de l'information` |
| Pays | Non | `Togo` |
| Ville | Non | `Lomé` |
| Description | Non | Quelques phrases sur l'entreprise |
| Site web | Non | `https://exemple-entreprise.com` |
| LinkedIn | Non | `https://linkedin.com/company/exemple` |
| E-mail professionnel | Non | `contact@exemple-entreprise.com` |
| Téléphone professionnel | Non | `22890000002` |
| Logo | Non (image) | — |

### 3.5 Publier un projet de type « Soutenance » (`/publier`)

Utilisez le compte **#1 `test.etudiant1`** pour ce projet — c'est lui qui présentera la soutenance testée plus loin.

| Champ | Obligatoire | Exemple |
|---|---|---|
| Type de projet | Oui | **Soutenance** (choisissez bien celui-ci, pas Personnel) |
| Nom du projet | Oui | `Plateforme de gestion des soutenances` |
| Thème | Non | `Génie logiciel` |
| Description courte | Non (160 car. max) | Une phrase résumant le projet |
| Description détaillée | Non | Quelques paragraphes |
| Date de réalisation | Non | Une date récente |
| Technologies utilisées | Non (tags) | `Symfony`, `MySQL` |
| **Au moins une preuve est obligatoire** — remplissez-en au moins une parmi : | | |
| — Lien GitHub | | `https://github.com/exemple/projet` |
| — Vidéo YouTube | | `https://youtube.com/watch?v=xxxxxxxxxxx` |
| — Site web | | `https://exemple.dev` |
| — Démo | | `https://demo.exemple.dev` |
| — Lien du mémoire | | `https://exemple.dev/memoire.pdf` |
| — Autre preuve (titre + URL) | | |
| Photos | Non | 1-2 images |
| Document (type + titre + fichier) | Non | Un PDF si vous en avez un sous la main |
| Domaine / Mention / Spécialité / Établissement | Non (ou « Autre (à préciser) » si absent du catalogue) | Mêmes valeurs que le profil étudiant |

---

## 4. Scénarios de test

### A — Inscription et connexion automatique

- [ ] **A1.** Inscrivez le compte #1 (choix **Étudiant**). Attendu : redirection directe vers **« Bienvenue sur MOUMTOU »**, déjà connecté.
- [ ] **A2.** Le bouton de la page Bienvenue mène directement vers **« Devenir étudiant »** (pas le profil général) — puisque « Étudiant » a été choisi à l'inscription.
- [ ] **A3.** Complétez immédiatement ce formulaire avec les valeurs du §3.2. Attendu : rôle **Étudiant** actif tout de suite après (badge visible).
- [ ] **A4.** Répétez l'inscription pour les comptes #2 (Étudiant), #3/#4/#5 (Enseignant — complétez « Devenir enseignant » avec l'établissement **Université de Lomé** pour chacun), #6 (Recruteur — complétez le profil recruteur), #7 et #8 (Talent).

### B — Compléter le profil de base (tous les comptes)

- [ ] **B1.** Pour chaque compte créé, allez dans **Modifier mon profil** et remplissez un maximum de champs du §3.1 (au minimum : titre professionnel, pays, ville, bio, une compétence, une technologie).
- [ ] **B2.** Vérifiez qu'après enregistrement, la bannière « Complétez votre profil » n'apparaît plus sur votre page de profil public.

### C — Multi-rôle : cumul et bascule entre comptes

- [ ] **C1.** Avec le compte #7 (`test.multirole`), allez dans **Modifier mon profil → Mes rôles**. Ajoutez **Étudiant**, puis **Enseignant**, puis **Recruteur**, l'un après l'autre. Après chaque ajout, vérifiez que **tous** les rôles précédents sont toujours affichés (rien ne disparaît).
- [ ] **C2. Bascule entre comptes étudiants.** Déconnectez-vous du compte #1, connectez-vous avec le compte #2 (`test.etudiant2`). Vérifiez que son profil, son cursus (établissement/domaine/mention/spécialité saisis pour lui) et ses éventuels projets sont bien **distincts** de ceux du compte #1 (aucun mélange entre les deux comptes).
- [ ] **C3.** Reconnectez-vous avec le compte #1 : ses informations doivent être exactement celles saisies pour lui, inchangées par la navigation sur le compte #2.

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

- [ ] **F1.** Toujours sur **Ma soutenance**, dans « Membres du jury », invitez :
  - `test.president@moumtou.dev` → rôle **Président**
  - `test.rapporteur@moumtou.dev` → rôle **Rapporteur**
  - `test.examinateur@moumtou.dev` → rôle **Examinateur**

  Pour chacun : prénom, nom, établissement (Université de Lomé), e-mail exact du compte correspondant.
- [ ] **F2.** Attendu après chaque envoi : message « Invitation envoyée à … ». Les 3 membres apparaissent dans la liste, statut **« En attente »**.
- [ ] **F3.** Connectez-vous avec `test.president@moumtou.dev`. Allez dans **Mon espace enseignant**. L'invitation doit apparaître avec le rôle **Président**.
- [ ] **F4.** Cliquez **« Confirmer ma participation »**. Statut passe à **Confirmé**.
- [ ] **F5.** Répétez F3-F4 avec `test.rapporteur` et `test.examinateur` (tous les deux confirment aussi).
- [ ] **F6.** Retour sur le compte #1 (`test.etudiant1`) : dans « Membres du jury » sur Ma soutenance, les 3 doivent maintenant afficher le statut **Confirmé**.

### G — Réalisation et vérification de la soutenance (règle des 2 confirmations)

- [ ] **G1.** Avec le compte #1, sur **Ma soutenance**, cliquez **« Marquer la soutenance comme réalisée »**. Attendu : statut passe à **« Réalisée »**.
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
- [ ] **H7. Visibilité publique.** Toujours en `test.etudiant1`, cochez « Afficher le résultat », « Afficher ma note », « Afficher l'appréciation du jury ». Enregistrez. Allez sur la page publique du projet : note et appréciation doivent maintenant y être visibles.
- [ ] **H8.** Décochez-les à nouveau, enregistrez, revérifiez que la page publique ne montre plus rien de tout ça (contrôle du candidat sur sa propre confidentialité).

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

---

## 5. En cas d'erreur trouvée

Notez pour chaque erreur :
1. Le numéro du scénario (ex. « H4 »).
2. Ce que vous avez fait exactement, avec quel compte.
3. Ce qui était attendu (voir ci-dessus).
4. Ce qui s'est réellement passé (message d'erreur exact si possible, capture d'écran si possible).

Envoyez-moi cette liste et je corrige au fur et à mesure.
