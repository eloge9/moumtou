/* ==========================================================================
   MOUMTOU — comportements d'interface
   JavaScript natif, sans dépendance. Chaque module s'active uniquement si les
   éléments correspondants sont présents dans la page.
   ========================================================================== */

(function () {
  'use strict';

  /* -------------------------------------------------- Menu de navigation -- */
  function initNavigation() {
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');
    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', nav.classList.contains('is-open'));
      });
    }
    // Marque le lien correspondant à la page courante
    var fichier = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.m-nav__link, .m-admin__lien').forEach(function (lien) {
      var href = lien.getAttribute('href');
      if (href && href === fichier) lien.classList.add('is-active');
    });
  }

  /* ------------------------------------------- Filtres, chips et compteur -- */
  // Retrouve le <form> associé à un contrôle de filtre, qu'il soit à
  // l'intérieur (aside) ou à l'extérieur (ex. tri, via l'attribut form="…").
  function formulaireFiltres(el) {
    var proche = el.closest('[data-filtres-auto]');
    if (proche) return proche;
    var input = el.matches('input[form]') ? el : el.querySelector('input[form]');
    return input ? document.getElementById(input.getAttribute('form')) : null;
  }

  function initFiltres() {
    document.querySelectorAll('[data-chip]').forEach(function (chip) {
      var input = chip.querySelector('input');
      chip.addEventListener('click', function (e) {
        if (e.target === input) return; // le natif gère déjà l'input lui-même
        if (chip.hasAttribute('data-chip-exclusif')) {
          var groupe = chip.closest('[data-chip-groupe]');
          if (groupe) groupe.querySelectorAll('[data-chip]').forEach(function (c) {
            c.classList.remove('is-active');
            var i = c.querySelector('input'); if (i) i.checked = false;
          });
          chip.classList.add('is-active');
          if (input) input.checked = true;
        } else {
          chip.classList.toggle('is-active');
          if (input) input.checked = chip.classList.contains('is-active');
        }
        majResume();
        var formulaire = formulaireFiltres(chip);
        if (formulaire) formulaire.requestSubmit();
      });
      // Clic direct sur l'input (accessibilité clavier) : même comportement.
      if (input) {
        input.addEventListener('change', function () {
          chip.classList.toggle('is-active', input.checked);
          majResume();
          var formulaire = formulaireFiltres(chip);
          if (formulaire) formulaire.requestSubmit();
        });
      }
    });

    // Retrait d'un filtre actif (croix)
    document.querySelectorAll('[data-filtre-retirer]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        btn.closest('[data-filtre]').remove();
        majResume();
      });
    });

    document.querySelectorAll('[data-filtre-case]').forEach(function (input) {
      input.addEventListener('change', function () {
        majResume();
        var formulaire = formulaireFiltres(input);
        if (formulaire) formulaire.requestSubmit();
      });
    });

    document.querySelectorAll('[data-filtres-auto] select, [data-filtres-auto] input[type="text"], [data-filtres-auto] input[type="range"]').forEach(function (champ) {
      champ.addEventListener('change', function () {
        var formulaire = formulaireFiltres(champ);
        if (formulaire) formulaire.requestSubmit();
      });
    });

    var reset = document.querySelector('[data-filtres-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        document.querySelectorAll('[data-filtre-case]').forEach(function (i) { i.checked = false; });
        document.querySelectorAll('[data-chip]').forEach(function (c) { c.classList.remove('is-active'); });
        majResume();
      });
    }

    majResume();
  }

  function majResume() {
    var cible = document.querySelector('[data-filtres-resume]');
    if (!cible) return;
    var actifs = document.querySelectorAll('[data-filtre-case]:checked').length +
                 document.querySelectorAll('[data-chip].is-active').length;
    cible.textContent = actifs === 0 ? 'Aucun filtre actif'
      : actifs + (actifs > 1 ? ' filtres actifs' : ' filtre actif');
  }

  /* ------------------------------------------ Publication en trois étapes -- */
  function initEtapes() {
    var formulaire = document.querySelector('[data-etapes]');
    if (!formulaire) return;

    var panneaux = formulaire.querySelectorAll('[data-etape-panneau]');
    var indicateurs = formulaire.querySelectorAll('[data-etape]');
    var traits = formulaire.querySelectorAll('[data-etape-trait]');
    var courante = 0;

    function afficher(index) {
      courante = Math.max(0, Math.min(index, panneaux.length - 1));
      panneaux.forEach(function (p, i) { p.classList.toggle('is-active', i === courante); });
      indicateurs.forEach(function (ind, i) {
        ind.classList.toggle('is-active', i === courante);
        ind.classList.toggle('is-done', i < courante);
      });
      traits.forEach(function (t, i) { t.classList.toggle('is-done', i < courante); });
      var compteur = document.querySelector('[data-etape-compteur]');
      if (compteur) compteur.textContent = 'Étape ' + (courante + 1) + ' sur ' + panneaux.length;

      var derniere = courante === panneaux.length - 1;
      formulaire.querySelectorAll('[data-etape-suivant]').forEach(function (b) { b.hidden = derniere; });
      formulaire.querySelectorAll('[data-etape-envoyer]').forEach(function (b) { b.hidden = !derniere; });
      formulaire.querySelectorAll('[data-etape-precedent]').forEach(function (b) { b.hidden = courante === 0; });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Choix du type de projet (chaque carte peut envelopper un vrai input radio)
    formulaire.querySelectorAll('[data-choix]').forEach(function (choix) {
      if (choix.hasAttribute('data-choix-interdit')) return;
      var radio = choix.querySelector('input[type="radio"]');
      if (radio && radio.checked) choix.classList.add('is-active');
      choix.addEventListener('click', function () {
        formulaire.querySelectorAll('[data-choix]').forEach(function (c) { c.classList.remove('is-active'); });
        choix.classList.add('is-active');
        if (radio) radio.checked = true;
      });
    });

    formulaire.querySelectorAll('[data-etape-suivant]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!valider(courante)) return;
        afficher(courante + 1);
      });
    });
    formulaire.querySelectorAll('[data-etape-precedent]').forEach(function (btn) {
      btn.addEventListener('click', function () { afficher(courante - 1); });
    });

    function valider(index) {
      var message = formulaire.querySelector('[data-etape-erreur]');
      var erreur = '';

      if (index === 0 && !formulaire.querySelector('[data-choix].is-active')) {
        erreur = 'Sélectionnez le type de projet.';
      }
      if (index === 1) {
        var nom = formulaire.querySelector('[data-nom-projet]');
        if (nom && !nom.value.trim()) erreur = 'Le nom du projet est obligatoire.';
      }
      if (index === 2) {
        var preuves = formulaire.querySelectorAll('[data-preuve]');
        var remplie = Array.prototype.some.call(preuves, function (p) { return p.value.trim() !== ''; });
        if (!remplie) erreur = 'Au moins une preuve de réalisation est obligatoire.';
      }

      if (message) {
        message.textContent = erreur;
        message.hidden = !erreur;
      }
      return !erreur;
    }

    var envoi = formulaire.querySelector('[data-etape-envoyer]');
    if (envoi) {
      envoi.addEventListener('click', function () {
        if (!valider(2)) return;
        // requestSubmit() (et non submit()) pour déclencher l'évènement 'submit'
        // nécessaire à la protection CSRF (voir csrf_protection_controller.js).
        formulaire.requestSubmit();
      });
    }

    afficher(0);
  }

  /* --------------------------------------------- Technologies (tags) ------ */
  function initTags() {
    document.querySelectorAll('[data-tags]').forEach(function (zone) {
      var champ = zone.querySelector('[data-tags-input]');
      var liste = zone.querySelector('[data-tags-liste]');
      var cache = zone.querySelector('[data-tags-hidden]');
      if (!champ || !liste) return;

      function majCache() {
        if (!cache) return;
        var valeurs = Array.prototype.map.call(liste.querySelectorAll('.m-chip'), function (tag) {
          return tag.firstChild ? tag.firstChild.textContent.trim() : tag.textContent.trim();
        });
        cache.value = valeurs.join(',');
      }

      function retirer(tag) {
        tag.remove();
        majCache();
      }

      champ.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ',') return;
        e.preventDefault();
        var valeur = champ.value.trim().replace(/,$/, '');
        if (!valeur) return;
        var tag = document.createElement('span');
        tag.className = 'm-chip is-active';
        tag.innerHTML = valeur + ' <span class="m-chip__x">&times;</span>';
        tag.addEventListener('click', function () { retirer(tag); });
        liste.appendChild(tag);
        champ.value = '';
        majCache();
      });

      liste.querySelectorAll('.m-chip').forEach(function (tag) {
        tag.addEventListener('click', function () { retirer(tag); });
      });

      majCache();
    });
  }

  /* ------------------------------------------------ Notation par étoiles -- */
  function initNotes() {
    document.querySelectorAll('[data-note]').forEach(function (bloc) {
      var etoiles = bloc.querySelectorAll('[data-note-etoile]');
      var sortie = bloc.parentElement.querySelector('[data-note-valeur]');
      var valeur = parseInt(bloc.getAttribute('data-note-initiale') || '0', 10);

      function peindre(n) {
        etoiles.forEach(function (e, i) { e.classList.toggle('is-on', i < n); });
      }
      peindre(valeur);

      etoiles.forEach(function (etoile, i) {
        etoile.addEventListener('mouseenter', function () { peindre(i + 1); });
        etoile.addEventListener('click', function () {
          valeur = i + 1;
          peindre(valeur);
          if (sortie) sortie.textContent = 'Votre évaluation : ' + valeur + ' / 5';

          // Si l'étoile enveloppe un vrai input radio (page projet), on
          // envoie réellement la note au serveur.
          var conteneur = etoile.closest('label');
          var radio = conteneur ? conteneur.querySelector('input[type="radio"]') : null;
          if (radio) {
            radio.checked = true;
            var formulaire = etoile.closest('form');
            if (formulaire) formulaire.requestSubmit();
          }
        });
      });
      bloc.addEventListener('mouseleave', function () { peindre(valeur); });
    });
  }

  /* ------------------------------------------------- Onglets de profil ---- */
  function initOnglets() {
    document.querySelectorAll('[data-onglets]').forEach(function (groupe) {
      var boutons = groupe.querySelectorAll('[data-onglet]');
      boutons.forEach(function (bouton) {
        bouton.addEventListener('click', function () {
          var cible = bouton.getAttribute('data-onglet');
          boutons.forEach(function (b) { b.classList.toggle('is-active', b === bouton); });
          document.querySelectorAll('[data-onglet-panneau]').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-onglet-panneau') === cible);
          });
        });
      });
    });
  }

  /* --------------------------------------- Menus déroulants et modales --- */
  function initMenus() {
    document.querySelectorAll('[data-menu]').forEach(function (menu) {
      var bouton = menu.querySelector('[data-menu-bouton]');
      if (!bouton) return;
      bouton.addEventListener('click', function (e) {
        e.stopPropagation();
        var ouvert = menu.classList.contains('is-open');
        fermerMenus();
        menu.classList.toggle('is-open', !ouvert);
      });
    });
    document.addEventListener('click', fermerMenus);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { fermerMenus(); fermerModales(); }
    });
  }

  function fermerMenus() {
    document.querySelectorAll('[data-menu].is-open').forEach(function (m) { m.classList.remove('is-open'); });
  }

  function initModales() {
    document.querySelectorAll('[data-modale-ouvrir]').forEach(function (btn) {
      btn.addEventListener('click', function () { ouvrirModale(btn.getAttribute('data-modale-ouvrir')); });
    });
    document.querySelectorAll('[data-modale]').forEach(function (modale) {
      modale.addEventListener('click', function (e) {
        if (e.target === modale || e.target.hasAttribute('data-modale-fermer')) fermerModales();
      });
    });
  }

  function ouvrirModale(id) {
    var modale = document.getElementById(id);
    if (modale) modale.classList.add('is-open');
  }
  function fermerModales() {
    document.querySelectorAll('[data-modale].is-open').forEach(function (m) { m.classList.remove('is-open'); });
  }

  /* ------------------------------------- Champ « Autre (à préciser) » ----- */
  function initAutrePrecision() {
    document.querySelectorAll('[data-autre]').forEach(function (zone) {
      var liste = zone.querySelector('select');
      var champ = zone.querySelector('[data-autre-champ]');
      if (!liste || !champ) return;

      function maj() { champ.hidden = liste.value !== 'autre'; }
      liste.addEventListener('change', maj);
      maj();
    });
  }

  /* ------------------------------------- Autocomplétion (cahier §23) -- */
  function initRecherche() {
    document.querySelectorAll('[data-recherche-globale]').forEach(function (formulaire) {
      var champ = formulaire.querySelector('[data-recherche-champ]');
      var boite = formulaire.querySelector('[data-recherche-suggestions]');
      if (!champ || !boite) return;

      var minuteur = null;
      var requeteEnCours = null;

      function fermer() { boite.hidden = true; boite.innerHTML = ''; }

      function afficher(suggestions) {
        boite.innerHTML = '';
        if (!suggestions.length) { fermer(); return; }
        // Construction via DOM (pas innerHTML) : les libellés viennent de
        // données utilisateur (noms de talents, de projets…), il ne faut
        // jamais les injecter comme HTML brut.
        suggestions.forEach(function (s) {
          var lien = document.createElement('a');
          lien.href = s.url;
          var libelle = document.createTextNode(s.label);
          var type = document.createElement('span');
          type.className = 'm-meta';
          type.textContent = s.type;
          lien.appendChild(libelle);
          lien.appendChild(type);
          boite.appendChild(lien);
        });
        boite.hidden = false;
      }

      champ.addEventListener('input', function () {
        clearTimeout(minuteur);
        var q = champ.value.trim();
        if (q.length < 2) { fermer(); return; }
        // Debounce : une seule requête au plus tous les 300ms.
        minuteur = setTimeout(function () {
          if (requeteEnCours) requeteEnCours.abort();
          var controleur = new AbortController();
          requeteEnCours = controleur;
          fetch('/recherche/suggestions?q=' + encodeURIComponent(q), { signal: controleur.signal })
            .then(function (reponse) { return reponse.ok ? reponse.json() : { suggestions: [] }; })
            .then(function (donnees) { afficher(donnees.suggestions || []); })
            .catch(function () {});
        }, 300);
      });

      document.addEventListener('click', function (e) {
        if (!formulaire.contains(e.target)) fermer();
      });
      champ.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') fermer();
      });
    });
  }

  /* ------------------------------------------------------------ Amorçage -- */
  document.addEventListener('DOMContentLoaded', function () {
    initNavigation();
    initFiltres();
    initEtapes();
    initTags();
    initNotes();
    initOnglets();
    initMenus();
    initModales();
    initAutrePrecision();
    initRecherche();
  });

  window.MOUMTOU = { ouvrirModale: ouvrirModale, fermerModales: fermerModales };
})();
