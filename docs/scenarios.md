# Scénarios fonctionnels MOUMTOU

Ce document décrit le fonctionnement réel de MOUMTOU tel qu'implémenté (vérifié par la suite de tests automatisés — `tests/Functional/` et `tests/Unit/`, 300+ tests). Il sert de référence fonctionnelle pour un développeur externe qui reprend le projet.

## 1. Vision générale

MOUMTOU transforme des réalisations concrètes (projets logiciels, hardware, entrepreneuriaux, de recherche, soutenances académiques) en preuves de compétence vérifiables, visibles par des recruteurs. Principe central : **publié ≠ vérifié**. Un projet publié est visible publiquement dès qu'un administrateur le publie (ou, selon le cas, dès sa création) ; il devient *vérifié* seulement après validation par un jury ou un administrateur, ce qui affiche un badge distinct.

## 2. Rôles

Voir README §21. Un rôle ne remplace jamais un autre : `ROLE_TALENT` est la base commune, et `ROLE_STUDENT`/`ROLE_TEACHER`/`ROLE_RECRUITER` s'ajoutent par-dessus.

## 3. Inscription

**SCÉNARIO : Créer un compte**

Acteur : Visiteur.

Préconditions : Aucune.

Étapes :
1. Ouvrir `/inscription`.
2. Renseigner prénom, nom, téléphone, e-mail, mot de passe (+ confirmation).
3. Choisir éventuellement un type de compte (étudiant, enseignant, recruteur) — optionnel, peut être fait plus tard.
4. Valider.

Résultat attendu : Compte créé avec `ROLE_TALENT` + `ROLE_USER`, connexion automatique, redirection vers une page de bienvenue puis complétion du profil. Un e-mail de confirmation est envoyé (ne bloque pas la connexion).

Cas d'erreur :
- E-mail déjà utilisé → 422, message explicite, aucun compte créé.
- Champs obligatoires manquants → formulaire réaffiché avec les erreurs.
- Plus de 5 inscriptions/heure depuis la même origine → blocage temporaire (limitation de débit).

Permissions : Public.

Base de données : `app_user` (roles JSON additif dès la création).

## 4. Connexion

**SCÉNARIO : Se connecter**

Étapes : `/connexion`, e-mail + mot de passe, ou bouton Google/Facebook/LinkedIn si configuré.

Résultat attendu : Session authentifiée, rôles et profil conservés d'une connexion à l'autre.

Cas d'erreur : Identifiants invalides → message générique (pas d'énumération de compte) ; compte suspendu/banni → accès refusé avec message adapté.

## 5. Mot de passe oublié

**SCÉNARIO : Réinitialisation**

Étapes : `/mot-de-passe-oublie` → e-mail (pré-rempli si déjà saisi sur la page de connexion, simple confirmation) → lien signé envoyé par e-mail → `/reinitialiser-mot-de-passe?...` → nouveau mot de passe + confirmation → connexion.

Résultat attendu : Mot de passe changé, lien à usage unique (invalidé automatiquement après utilisation via une empreinte du hash courant), expire après 1 heure.

Cas d'erreur : E-mail inconnu → même message que succès (anti-énumération), aucun envoi réel ; lien expiré ou déjà utilisé → 404 explicite ; échec SMTP réel → vraie erreur serveur journalisée, jamais un faux message de succès.

## 6. Complétion du profil

Après inscription, le profil (photo, bio, compétences, technologies, réseaux sociaux, WhatsApp) est complété depuis `/mon-profil/modifier`. Un profil incomplet reste utilisable mais moins visible/crédible pour les recruteurs.

## 7. TALENT

Rôle de base. Permet : profil public, publication de projets, réception d'évaluations/commentaires/demandes de contact.

## 8. STUDENT

**SCÉNARIO : Devenir étudiant**

Étapes : Depuis le profil ou l'inscription → « Devenir étudiant » → établissement (catalogue ou demande d'ajout si absent), domaine, mention, spécialité → enregistrer.

Résultat attendu : `ROLE_STUDENT` actif **seulement une fois** le formulaire complété (pas au simple clic sur « je suis étudiant »).

## 9. TEACHER

**SCÉNARIO : Devenir enseignant**

Étapes : Établissement + fonction/rôle **propre à cet établissement** (un enseignant peut avoir une fonction différente selon l'établissement).

Résultat attendu : `ROLE_TEACHER` actif, accès à `/mon-espace-enseignant`, éligible aux invitations de jury.

## 10. RECRUITER

**SCÉNARIO : Devenir recruteur**

Étapes : Nom d'entreprise, secteur, logo (optionnel) → enregistrer.

Résultat attendu : `ROLE_RECRUITER` actif, accès à la recherche de talents et aux demandes de contact.

## 11. ADMIN

Voir §30 (Administration) et README §11 (création du premier administrateur).

## 12. Multi-rôle

Un compte peut cumuler TALENT + STUDENT + TEACHER + RECRUITER simultanément. Exemple vérifié : un talent invité comme membre de jury reçoit `ROLE_TEACHER` en plus de ses rôles existants, sans jamais perdre les précédents.

## 13. Projet

**SCÉNARIO : Créer un projet**

Acteur : Talent connecté.

Étapes : `/publier` → type de projet (personnel, professionnel, entrepreneurial, recherche, soutenance) → informations (nom, description, technologies) → preuves → enregistrer.

Résultat attendu : Projet créé en statut `EN_ATTENTE` (invisible publiquement) jusqu'à publication par un administrateur.

Statuts réels : `EN_ATTENTE → PUBLIE → VERIFIE`, avec branches `VERIFICATION_DEMANDEE` (correction demandée), `REJETE` (masqué/supprimé côté modération).

Cas d'erreur : informations obligatoires manquantes ; preuve invalide ; fichier trop volumineux.

Permissions : Seul le propriétaire peut modifier son projet ; un administrateur peut le publier/vérifier/dépublier/rejeter.

## 14. Preuves

Selon le type de projet : dépôt GitHub, site/démo, vidéo YouTube, documents, photos (glisser-déposer réel sur toutes les zones de dépôt, avec confirmation visuelle de l'upload). Un projet composé uniquement d'une photo sans autre preuve peut être refusé selon la règle métier du type de projet concerné.

## 15. Publication

La publication (rendre un projet visible publiquement) est une action **administrateur** (bouton individuel, ou bouton « Tout publier » pour publier en masse tous les projets en attente depuis `/admin/moderation`).

## 16. Vérification

La vérification (badge « Vérifié ») intervient après validation par un jury (soutenances, ≥ 2 validations distinctes) ou directement par un administrateur (tout type de projet, y compris une soutenance encore annoncée — action tracée dans le journal d'audit).

## 17. Soutenance

**SCÉNARIO : Annoncer une soutenance**

Acteur : Étudiant, propriétaire d'un projet de type Soutenance.

Étapes : `/ma-soutenance` (ou `/ma-soutenance/{id}` s'il a plusieurs soutenances) → date, heure, lieu → publier l'annonce.

Résultat attendu : Soutenance en statut `ANNONCEE`. Passe automatiquement à `REALISEE` dès que la date est dépassée (ou manuellement via le bouton « Marquer comme réalisée »).

Cas particuliers : annulation (motif obligatoire, notifie le jury) ; report (conserve l'ancienne date à titre d'historique, redemande une nouvelle date) ; plusieurs soutenances possibles pour un même talent (liste dédiée au-delà d'une seule).

## 18. Jury

**SCÉNARIO : Inviter un membre du jury**

Étapes : Depuis la page de gestion de la soutenance → rechercher un compte existant (nom/établissement) ou saisir manuellement (nom, e-mail, rôle : président/rapporteur/examinateur, établissement) → envoyer.

Résultat attendu : Invitation par e-mail avec lien signé ; le juré confirme ou refuse ; un compte MOUMTOU existant reçoit en plus `ROLE_TEACHER` automatiquement.

Une fois la soutenance réalisée, chaque juré confirmé peut valider qu'elle a bien eu lieu ; le seuil de vérification est de 2 validations distinctes (une double validation par le même juré ne compte qu'une fois).

## 19. Résultat

Après validation, le président du jury (ou un rapporteur, selon la règle en vigueur) enregistre note, mention et appréciation. Dès la validation finale, le résultat devient **visible publiquement par défaut** — le candidat garde la possibilité de le masquer.

## 20. Évaluations

Notation 1 à 5 étoiles sur un projet, un vote par compte et par projet, modifiable/supprimable par son auteur. Les évaluations statistiquement suspectes sont mises en file d'examen administrateur (disculper ou signaler comme abusive).

## 21. Commentaires

Commentaire → réponse → notification à l'auteur → signalement possible → modération (masquer/supprimer/restaurer par un administrateur, ou suppression par l'auteur lui-même).

## 22. Notifications

Émises pour : inscription/confirmation, mot de passe oublié, demande de contact (+ décision), invitation jury (+ décision), validation jury, commentaire/réponse, évaluation, projet vérifié, correction demandée, sanction, alertes de sécurité/erreurs critiques (administrateurs). Visibles dans le centre de notifications de l'application ; certaines sont doublées par e-mail selon leur nature.

## 23. Recherche

`/recherche` et `/explorer` : recherche libre (talents, projets, soutenances, technologies, établissements) et filtres avancés (technologie, statut, spécialité, établissement…).

## 24. Recrutement / contact

**SCÉNARIO : Demande de contact**

Étapes : Recruteur → profil talent → « Demander le contact » → statut `PENDING` → notification au talent → le talent accepte (`ACCEPTED`) ou refuse (`REFUSED`).

Résultat attendu : Après acceptation, **aucune messagerie interne** n'est créée — le recruteur accède aux canaux déjà publics choisis par le talent (WhatsApp, LinkedIn, GitHub, site). Un refus ne donne accès à rien de plus qu'avant.

Cas d'erreur : plus de 20 demandes de contact envoyées par le même recruteur en 24h → blocage temporaire.

## 25. WhatsApp

Canal de contact optionnel, affiché sur le profil public du talent seulement si celui-ci l'a renseigné — jamais transmis autrement (pas de messagerie propriétaire intermédiaire).

## 26. Établissements

`/etablissements` : liste, recherche, fiche établissement (talents publics, projets, soutenances rattachés), filtres. Un étudiant qui ne trouve pas son établissement peut en faire la demande d'ajout, traitée par un administrateur.

## 27. QR Code

Généré à la volée pour tout projet publié, pointe vers sa page publique, aucune donnée privée encodée. Un projet dépublié/supprimé rend simplement le lien scanné inaccessible (page d'erreur adaptée), le QR lui-même n'a pas besoin d'être régénéré.

## 28. Partage

Chaque projet et profil public dispose d'une URL directe partageable, avec métadonnées Open Graph pour un aperçu correct sur les réseaux sociaux.

## 29. Statistiques

Statistiques par projet (vues, évaluation moyenne) accessibles à son propriétaire ; statistiques globales de la plateforme accessibles depuis le tableau de bord administrateur.

## 30. Administration

Accès `/admin` (rôle `ROLE_ADMIN` uniquement) : tableau de bord, utilisateurs (rôles, suspension, suppression définitive), projets (publier/vérifier/dépublier/rejeter, y compris en masse), soutenances (vérification directe), établissements, technologies/domaines/mentions/spécialités (catalogue), commentaires, signalements, évaluations suspectes, sanctions, journal d'audit (chaque action administrateur tracée : qui, quoi, quand, pourquoi).

## 31. Modération

Un signalement (projet, profil ou commentaire) ouvre une file d'instruction administrateur : décision sur le contenu (conserver / masquer / dépublier / supprimer / demander correction) et, séparément, sanction éventuelle sur l'auteur (avertir / suspendre 7 ou 30 jours / bannir). Toute décision est journalisée, y compris un rejet du signalement.

## 32. Sanctions

Avertissement, suspension temporaire (durée fixe) ou bannissement définitif. Un compte suspendu/banni ne peut plus se connecter normalement ; l'historique des sanctions reste consultable par un administrateur.

## 33. Suppression de compte

**SCÉNARIO : Suppression définitive par un administrateur**

Étapes : `/admin/utilisateurs/{id}` → « Supprimer définitivement » → confirmation forte (avertissement explicite, action irréversible) → suppression.

Résultat attendu : Le compte et ses données personnelles sont supprimés ; les données nécessaires à l'intégrité et à l'audit (ex. trace d'une modération passée) peuvent être conservées sous forme minimisée/anonymisée plutôt que de casser les relations existantes (projets d'autres utilisateurs, historiques de jury, etc.).

Permissions : Réservé aux administrateurs.

## 34. Sécurité

Voir README §18. Points clés : CSRF systématique, en-têtes de sécurité sur chaque réponse, mots de passe hashés, aucun compte par défaut, limitation de débit sur les actions sensibles, contrôle d'accès vérifié à chaque action (propriété d'une ressource ou rôle administrateur), suppression de compte irréversible et tracée.

## 35. Scénarios d'erreur

| Action | Résultat attendu | Message utilisateur | Comportement backend |
|---|---|---|---|
| Mauvais mot de passe | Connexion refusée | Message générique (« identifiants invalides ») | Pas d'indice sur l'existence du compte |
| E-mail déjà utilisé (inscription) | Formulaire rejeté | « Un compte existe déjà avec cette adresse » | HTTP 422, aucune création |
| Token de réinitialisation expiré | Lien invalide | Page d'erreur explicite | HTTP 404 |
| Token de réinitialisation déjà utilisé | Lien invalide | Page d'erreur explicite | Rejeté (empreinte du hash de mot de passe ne correspond plus) |
| Accès à une ressource d'un autre utilisateur | Refusé | Page 403/404 selon le contexte | Vérification de propriété avant toute action |
| Utilisateur suspendu/banni tente une action | Refusé | Message adapté au statut | Accès bloqué en amont |
| Preuve/fichier invalide ou trop volumineux | Rejeté | Message précisant la contrainte dépassée | Aucun enregistrement partiel |
| Erreur SMTP réelle | Erreur serveur visible (jamais un faux succès) | Erreur générique côté utilisateur | Journalisée `[EMAIL]` avec catégorie (connexion/authentification/TLS/rejet), jamais le mot de passe |
| Erreur MySQL / base indisponible | Page d'erreur générique | Message générique + identifiant de corrélation | Journalisé en CRITICAL, alerte administrateurs (anti-spam par fenêtre de temps) |
| Session expirée | Redirection connexion | Invite à se reconnecter | Aucune action sensible exécutée sans session valide |
| Ressource inexistante | 404 | Page 404 dédiée | — |
| Permission insuffisante | 403 | Page 403 dédiée | — |

## 36. Installation

Voir README §4 à §10 pour la procédure complète (prérequis, clonage, configuration, base de données, dépendances, lancement, tests).

## 37. Création du premier ADMIN

Voir README §11 — `php bin/console app:create-admin`, identifiants choisis par l'installateur, aucun compte par défaut.
