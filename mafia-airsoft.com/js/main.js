/* Mafia Airsoft Team – petites interactions communes à tout le site */
(function () {
    'use strict';

    // ---------- Menu mobile
    var toggle = document.getElementById('siteMenuToggle');
    var menu = document.getElementById('siteMenu');
    if (toggle && menu) {
        var setOpen = function (open) {
            menu.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
        };
        toggle.addEventListener('click', function () { setOpen(menu.hidden); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hidden) { setOpen(false); toggle.focus(); } });
        document.addEventListener('click', function (e) {
            if (!menu.hidden && !menu.contains(e.target) && !toggle.contains(e.target)) { setOpen(false); }
        });
        window.addEventListener('resize', function () { if (window.innerWidth > 900 && !menu.hidden) { setOpen(false); } });
    }

    // ---------- Confirmation avant une action ([data-confirm] sur un bouton ou un formulaire)
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-confirm]:not(form)') : null;
        if (el && !confirm(el.getAttribute('data-confirm') || 'Confirmer ?')) { e.preventDefault(); e.stopPropagation(); }
    }, true);
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm(form.getAttribute('data-confirm') || 'Confirmer ?')) { e.preventDefault(); }
        });
    });

    // ---------- Messages : bouton de fermeture ; seuls les messages de réussite s'effacent tout seuls
    document.querySelectorAll('.s-site .alert').forEach(function (alert) {
        if (alert.closest('form') || alert.hasAttribute('data-keep')) { return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 's-alert-close';
        btn.setAttribute('aria-label', 'Fermer ce message');
        btn.innerHTML = '&times;';
        btn.addEventListener('click', function () { alert.remove(); });
        alert.classList.add('has-close');
        alert.appendChild(btn);
        if (alert.classList.contains('alert-success')) {
            setTimeout(function () {
                alert.style.transition = 'opacity 0.4s';
                alert.style.opacity = '0';
                setTimeout(function () { alert.remove(); }, 450);
            }, 8000);
        }
    });

    // ---------- Compteur de caractères pour les zones de texte limitées
    document.querySelectorAll('textarea[maxlength]').forEach(function (textarea) {
        var max = parseInt(textarea.getAttribute('maxlength'), 10);
        var counter = document.createElement('div');
        counter.style.cssText = 'text-align:right;color:#8a8a8a;font-size:0.85rem;margin-top:0.25rem;';
        var update = function () {
            counter.textContent = textarea.value.length + ' / ' + max;
            counter.style.color = textarea.value.length > max * 0.9 ? '#fbbf24' : '#8a8a8a';
        };
        textarea.parentNode.appendChild(counter);
        textarea.addEventListener('input', update);
        update();
    });

    // ---------- Formulaires longs du panel : prévenir avant de quitter sans enregistrer
    var dirty = false;
    document.querySelectorAll('form.admin-form, form[data-warn-unsaved]').forEach(function (form) {
        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('submit', function () { dirty = false; });
    });
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // ---------- Galerie défilante (accueil) : boutons précédent / suivant
    document.querySelectorAll('[data-strip]').forEach(function (wrap) {
        var strip = wrap.querySelector('.s-strip');
        if (!strip) { return; }
        wrap.querySelectorAll('[data-strip-dir]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dir = parseInt(btn.getAttribute('data-strip-dir'), 10);
                strip.scrollBy({ left: dir * strip.clientWidth * 0.9, behavior: 'smooth' });
            });
        });
    });

    // ---------- Réservation : confirmation avec le total
    document.querySelectorAll('form[data-booking]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var btn = e.submitter;
            if (!btn || btn.name !== 'team') { return; }
            var team = btn.getAttribute('data-team-name') || '';
            var select = form.querySelector('select[name="quantity"]');
            var qty = select ? parseInt(select.value, 10) : 1;
            var price = parseInt(form.getAttribute('data-price') || '0', 10);
            var guests = form.getAttribute('data-guests') === '1';
            var msg = guests
                ? 'Ajouter ' + qty + ' invité' + (qty > 1 ? 's' : '') + ' dans « ' + team + ' » ?'
                : (qty > 1 ? 'Réserver ' + qty + ' places dans « ' + team + ' » ?' : 'Rejoindre « ' + team + ' » ?');
            if (price > 0) {
                msg += '\n\nTotal : ' + (price * qty / 100).toFixed(2).replace('.', ',') + ' € – paiement sécurisé par carte sur la page suivante.';
            }
            if (!confirm(msg)) { e.preventDefault(); }
        });
    });

    // ---------- Photos d'un article : agrandissement
    var lightbox = document.getElementById('lightbox');
    if (lightbox && typeof lightbox.showModal === 'function') {
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox]'));
        var img = lightbox.querySelector('img');
        var caption = lightbox.querySelector('p');
        var index = 0;
        var show = function (i) {
            index = (i + items.length) % items.length;
            img.src = items[index].getAttribute('data-lightbox');
            img.alt = items[index].getAttribute('data-caption') || '';
            caption.textContent = items[index].getAttribute('data-caption') || '';
        };
        items.forEach(function (item, i) {
            item.addEventListener('click', function () { show(i); lightbox.showModal(); });
        });
        lightbox.addEventListener('click', function (e) { if (e.target === lightbox) { lightbox.close(); } });
        lightbox.querySelectorAll('[data-lb]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dir = btn.getAttribute('data-lb');
                if (dir === 'close') { lightbox.close(); } else { show(index + parseInt(dir, 10)); }
            });
        });
        document.addEventListener('keydown', function (e) {
            if (!lightbox.open) { return; }
            if (e.key === 'ArrowRight') { show(index + 1); }
            if (e.key === 'ArrowLeft') { show(index - 1); }
        });
    }
})();
