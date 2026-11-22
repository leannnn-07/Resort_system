// Date pickers: keep check-out after check-in and show the number of nights.
(function () {
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function plusDays(value, n) {
    var p = value.split('-');
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    d.setDate(d.getDate() + n);
    return iso(d);
  }

  document.querySelectorAll('[data-stay]').forEach(function (form) {
    var inEl = form.querySelector('[name="check_in"]');
    var outEl = form.querySelector('[name="check_out"]');
    var label = form.querySelector('[data-nights]');
    if (!inEl || !outEl) return;

    function sync(changed) {
      if (inEl.value) {
        var min = plusDays(inEl.value, 1);
        outEl.min = min;
        if (changed === 'in' && (!outEl.value || outEl.value < min)) outEl.value = plusDays(inEl.value, 2);
      }
      if (label) {
        if (inEl.value && outEl.value && outEl.value > inEl.value) {
          var a = new Date(inEl.value), b = new Date(outEl.value);
          var n = Math.round((b - a) / 86400000);
          label.textContent = n + (n === 1 ? ' night' : ' nights');
        } else {
          label.textContent = '';
        }
      }
    }
    inEl.addEventListener('change', function () { sync('in'); });
    outEl.addEventListener('change', function () { sync('out'); });
    sync('init');
  });
})();

// Landing page: the photo melts into glass as you scroll, and again when you head to the booking pages.
(function () {
  var body = document.body, root = document.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function flag(v) { try { sessionStorage.setItem('baje-blend', v); } catch (e) {} }
  // Mobile menu
  var topBar = document.getElementById('top');
  var toggle = topBar ? topBar.querySelector('.nav-toggle') : null;
  if (toggle) {
    toggle.addEventListener('click', function () {
      var open = !topBar.hasAttribute('data-open');
      if (open) topBar.setAttribute('data-open', ''); else topBar.removeAttribute('data-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && topBar.hasAttribute('data-open')) {
        topBar.removeAttribute('data-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });
  }
  var isLanding = body.classList.contains('landing');

  if (isLanding) {
    var stage = document.querySelector('.landing-hero');
    var ticking = false;
    function update() {
      ticking = false;
      var h = stage ? stage.offsetHeight : window.innerHeight;
      var p = Math.min(1, Math.max(0, window.scrollY / (h * 0.6)));
      body.style.setProperty('--p', p.toFixed(3));
      var top = document.getElementById('top');
      if (top) top.classList.toggle('is-solid', p > 0.35);
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
    window.addEventListener('resize', update);
    update();
    window.addEventListener('load', update);
  }

  if (isLanding) {
    function leave(go) {
      if (reduce) { go(); return; }
      flag('settle');
      root.classList.add('leaving');
      setTimeout(go, 720);
    }

    // Links to the booking, room and price pages
    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target) return;
      var u = new URL(a.href, location.href);
      if (u.origin !== location.origin || !/\/(rooms|room|booking|search|checkout|pricelist|index)\.php$/.test(u.pathname)) return;
      e.preventDefault();
      leave(function () { location.href = a.href; });
    });

    // The availability search form
    document.querySelectorAll('[data-stay]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (reduce || form.dataset.leaving || !form.checkValidity()) return;
        e.preventDefault();
        form.dataset.leaving = '1';
        leave(function () { form.submit(); });
      });
    });

    // Back button: restore the page if it comes from the browser cache mid-transition
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) {
        root.classList.remove('leaving');
        body.querySelectorAll('[data-stay]').forEach(function (f) { delete f.dataset.leaving; });
        body.style.setProperty('--p', '0');
      }
    });
  } else if (body.classList.contains('site')) {
    // Heading back to the landing page: let the photo clear again
    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) return;
      var u = new URL(a.href, location.href);
      if (u.origin === location.origin && /(\/landing\.php|\/)$/.test(u.pathname) && !/\/admin\//.test(u.pathname)) flag('reveal');
    });
  }

})();

// Pop-up dialogs: "Are you sure?" on important buttons, and the "Create an account first to book" pop-up.
// Add data-confirm="Question?" to a button (or a form, or a link) and it asks first. Optional extras:
// data-confirm-title, data-confirm-ok (button text), data-confirm-cancel (button text), data-confirm-tone="danger".
(function () {
  var root = document.documentElement;
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  var active = null;   // { el, opener, onClose }

  function open(el, opener, onClose) {
    // Opening a pop-up on top of another (the "are you sure?" over a form) remembers the one underneath.
    active = { el: el, opener: opener || document.activeElement, onClose: onClose || null, parent: active };
    el.classList.add('is-open');
    root.classList.add('modal-open');
    var first = el.querySelector('[data-modal-focus]') || el.querySelector('.modal-card .btn');
    if (first) first.focus();
  }

  function close() {
    if (!active) return;
    var a = active;
    active = a.parent || null;
    a.el.classList.remove('is-open');
    if (!active) root.classList.remove('modal-open');
    if (a.onClose) a.onClose();
    if (a.opener && a.opener.focus && document.contains(a.opener)) a.opener.focus();
  }

  document.addEventListener('keydown', function (e) {
    if (!active) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') return;
    var items = [].slice.call(active.el.querySelectorAll(FOCUSABLE)).filter(function (n) { return n.offsetParent !== null; });
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (items.indexOf(document.activeElement) === -1) { e.preventDefault(); first.focus(); }
  });

  // Click on the dark backdrop or on any [data-modal-close] closes the pop-up.
  document.addEventListener('click', function (e) {
    if (!active || !e.target.closest) return;
    var onBackdrop = e.target === active.el;
    // A form with unsaved typing doesn't close on a stray click outside it; Cancel, the x and Escape still do.
    if (onBackdrop && active.el.hasAttribute('data-form-modal') && active.el.dataset.dirty) return;
    if (onBackdrop || e.target.closest('[data-modal-close]')) close();
  });

  // Pop-up written by the server (the "create an account first" one): already visible, just make it behave.
  var served = document.querySelector('.modal.is-open[data-modal]');
  if (served) open(served, null);

  // Form pop-ups: any button or link with data-form-open="modal-id" opens that .modal[data-form-modal].
  //  - data-id="5" fills the form from the page's JSON records (Edit); without it the form opens empty (Add).
  //  - data-fill='{"field":"value"}' presets fields (for example the room type of a photo).
  //  - data-autoopen on the modal reopens it on page load (after a failed save, or when ?edit=ID is in the address).
  // Glass panels use backdrop-filter, which would trap a fixed pop-up inside them: park every form pop-up on <body>.
  [].forEach.call(document.querySelectorAll('[data-form-modal]'), function (m) { document.body.appendChild(m); });
  var recordsEl = document.getElementById('crud-records'), records = {};
  try { if (recordsEl) records = JSON.parse(recordsEl.textContent || '{}'); } catch (err) { records = {}; }

  function fillForm(modal, values, id) {
    var form = modal.querySelector('form');
    if (!form) return;
    form.reset();
    Object.keys(values || {}).forEach(function (k) {
      var el = form.elements[k];
      if (el && 'value' in el && el.type !== 'file') el.value = values[k];
    });
    var pk = form.querySelector('[data-record-id]');
    if (pk) pk.value = id ? String(id) : '';
    var title = modal.querySelector('.modal-head h2');
    if (title && modal.dataset.titleAdd) title.textContent = id ? modal.dataset.titleEdit : modal.dataset.titleAdd;
    [].forEach.call(form.querySelectorAll('[data-pw]'), function (pw) {   // a blank password on Edit keeps the current one
      pw.required = false;
      pw.placeholder = id ? pw.getAttribute('data-pw-edit') : (pw.getAttribute('data-ph-add') || 'Min. 8 characters');
    });
    delete modal.dataset.dirty;
  }

  function openForm(modal, opener, values, id) {
    fillForm(modal, values, id);
    open(modal, opener);
    var first = modal.querySelector('input:not([type=hidden]):not([type=file]), select, textarea');
    if (first) first.focus();
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-form-open]') : null;
    if (!b || e.defaultPrevented) return;
    var modal = document.getElementById(b.getAttribute('data-form-open'));
    if (!modal) return;   // no pop-up on this page: a link still works normally
    e.preventDefault();
    var id = parseInt(b.getAttribute('data-id') || '0', 10) || 0;
    var values = id ? records[id] : null;
    if (b.hasAttribute('data-fill')) {
      try { values = Object.assign({}, values || {}, JSON.parse(b.getAttribute('data-fill'))); } catch (err) { /* ignore a bad preset */ }
    }
    openForm(modal, b, values, id);
  });

  document.addEventListener('input', function (e) {
    var m = e.target.closest ? e.target.closest('[data-form-modal]') : null;
    if (m) m.dataset.dirty = '1';
  });

  [].forEach.call(document.querySelectorAll('[data-form-modal][data-autoopen]'), function (m) {
    try {
      var a = JSON.parse(m.getAttribute('data-autoopen'));
      openForm(m, null, a.values || {}, a.id || 0);
    } catch (err) { /* leave it closed */ }
  });

  // The "are you sure?" pop-up, built once on first use.
  var box = null, titleEl, textEl, okBtn, cancelBtn, onOk = null;
  function build() {
    if (box) return;
    box = document.createElement('div');
    box.className = 'modal';
    box.setAttribute('role', 'alertdialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'confirm-title');
    box.setAttribute('aria-describedby', 'confirm-text');
    box.innerHTML = '<div class="modal-card">' +
      '<h2 id="confirm-title"></h2><p id="confirm-text"></p>' +
      '<div class="modal-actions">' +
      '<button class="btn" type="button" data-modal-close data-confirm-cancel-btn></button>' +
      '<button class="btn primary" type="button" data-confirm-ok-btn></button>' +
      '</div></div>';
    document.body.appendChild(box);
    titleEl = box.querySelector('#confirm-title');
    textEl = box.querySelector('#confirm-text');
    okBtn = box.querySelector('[data-confirm-ok-btn]');
    cancelBtn = box.querySelector('[data-confirm-cancel-btn]');
    okBtn.addEventListener('click', function () {
      var go = onOk;
      onOk = null;
      close();
      if (go) go();
    });
  }

  function ask(src, go, opener) {
    build();
    titleEl.textContent = src.getAttribute('data-confirm-title') || 'Are you sure?';
    textEl.textContent = src.getAttribute('data-confirm') || 'Are you sure?';
    okBtn.textContent = src.getAttribute('data-confirm-ok') || 'Yes, continue';
    cancelBtn.textContent = src.getAttribute('data-confirm-cancel') || 'Cancel';
    okBtn.classList.toggle('danger-solid', src.getAttribute('data-confirm-tone') === 'danger');
    onOk = go;
    open(box, opener, function () { onOk = null; });
    cancelBtn.focus();   // the safe choice is focused first
  }

  // Forms: ask before the submit goes through (the browser's own field checks have already passed).
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (form.dataset.confirmed) { delete form.dataset.confirmed; return; }
    var sub = e.submitter || null;
    var src = sub && sub.hasAttribute('data-confirm') ? sub : (form.hasAttribute('data-confirm') ? form : null);
    if (!src) return;
    e.preventDefault();
    ask(src, function () {
      form.dataset.confirmed = '1';
      if (form.requestSubmit) {
        if (sub) form.requestSubmit(sub); else form.requestSubmit();
      } else {
        if (sub && sub.name) {   // older browsers: keep the button's own value
          var hidden = document.createElement('input');
          hidden.type = 'hidden'; hidden.name = sub.name; hidden.value = sub.value;
          form.appendChild(hidden);
        }
        form.submit();
      }
    }, sub || document.activeElement);
  }, true);

  // Information pop-up: a button with data-notice="Reason" explains why something is not possible.
  // Optional: data-notice-title, data-notice-extra (a second line, e.g. how to contact the resort), data-notice-ok.
  var info = null;
  function showNotice(src) {
    if (!info) {
      info = document.createElement('div');
      info.className = 'modal';
      info.setAttribute('role', 'alertdialog');
      info.setAttribute('aria-modal', 'true');
      info.setAttribute('aria-labelledby', 'notice-title');
      info.setAttribute('aria-describedby', 'notice-text');
      info.innerHTML = '<div class="modal-card">' +
        '<h2 id="notice-title"></h2><p id="notice-text"></p><p id="notice-extra" hidden></p>' +
        '<div class="modal-actions"><button class="btn primary" type="button" data-modal-close data-modal-focus></button></div></div>';
      document.body.appendChild(info);
    }
    info.querySelector('#notice-title').textContent = src.getAttribute('data-notice-title') || 'Not possible';
    info.querySelector('#notice-text').textContent = src.getAttribute('data-notice') || '';
    var extra = info.querySelector('#notice-extra'), more = src.getAttribute('data-notice-extra') || '';
    extra.textContent = more;
    extra.hidden = !more;
    info.querySelector('[data-modal-close]').textContent = src.getAttribute('data-notice-ok') || 'OK';
    open(info, src);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-notice]') : null;
    if (!b || e.defaultPrevented) return;
    e.preventDefault();
    showNotice(b);
  });

  // Links: ask before following.
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-confirm]') : null;
    if (!a || e.defaultPrevented) return;
    e.preventDefault();
    ask(a, function () { location.href = a.href; }, a);
  });
})();

// Review photos open in a full-size viewer (arrow keys and the buttons move between the photos of one review).
(function () {
  var box = null, img, items = [], idx = 0, opener = null;

  function build() {
    if (box) return;
    box = document.createElement('div');
    box.className = 'lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Photo');
    box.innerHTML = '<button type="button" class="lb-close" aria-label="Close">&times;</button>' +
      '<button type="button" class="lb-prev" aria-label="Previous photo">&#8249;</button>' +
      '<img alt="">' +
      '<button type="button" class="lb-next" aria-label="Next photo">&#8250;</button>';
    document.body.appendChild(box);
    img = box.querySelector('img');
    box.querySelector('.lb-close').addEventListener('click', close);
    box.querySelector('.lb-prev').addEventListener('click', function () { show(idx - 1); });
    box.querySelector('.lb-next').addEventListener('click', function () { show(idx + 1); });
    box.addEventListener('click', function (e) { if (e.target === box) close(); });
  }

  function show(i) {
    idx = (i + items.length) % items.length;
    var inner = items[idx].querySelector('img');
    img.src = items[idx].getAttribute('href');
    img.alt = inner ? inner.alt : 'Photo';
  }

  function open(list, i, from) {
    build();
    items = list;
    opener = from;
    box.classList.toggle('is-single', items.length < 2);
    show(i);
    box.classList.add('is-open');
    document.documentElement.classList.add('lb-open');
    box.querySelector('.lb-close').focus();
  }

  function close() {
    if (!box || !box.classList.contains('is-open')) return;
    box.classList.remove('is-open');
    document.documentElement.classList.remove('lb-open');
    img.removeAttribute('src');
    if (opener && opener.focus) opener.focus();
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-lightbox]') : null;
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    var list = [].slice.call(document.querySelectorAll('a[data-lightbox="' + a.getAttribute('data-lightbox') + '"]'));
    open(list, list.indexOf(a), a);
  });

  document.addEventListener('keydown', function (e) {
    if (!box || !box.classList.contains('is-open')) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowLeft' && items.length > 1) show(idx - 1);
    else if (e.key === 'ArrowRight' && items.length > 1) show(idx + 1);
  });
})();

// Review form with photos and videos: check size and number before anything is uploaded, and show progress on send.
(function () {
  var form = document.querySelector('form[data-upload-form]');
  if (!form) return;
  var msg = form.querySelector('[data-upload-msg]');
  var btn = form.querySelector('button[type="submit"]');
  var label = btn ? btn.textContent : '';

  function say(text) { if (msg) { msg.textContent = text; msg.hidden = !text; } }
  function num(attr) { return parseInt(form.getAttribute(attr), 10) || 0; }
  function mb(bytes) { return Math.floor(bytes / 1048576) + ' MB'; }

  function check(input) {
    var kind = input.getAttribute('data-kind');
    var maxCount = num('data-max-' + kind + 's');
    var maxBytes = num('data-' + kind + '-bytes');
    var keep = num('data-have-' + kind + 's') - form.querySelectorAll('input[name="remove_media[]"][data-kind="' + kind + '"]:checked').length;
    var files = input.files || [];
    if (files.length + keep > maxCount) {
      say('You can have up to ' + maxCount + ' ' + kind + 's on a review' + (keep > 0 ? ' (you already have ' + keep + ')' : '') + '. Choose fewer.');
      input.value = '';
      return;
    }
    for (var i = 0; i < files.length; i++) {
      if (maxBytes && files[i].size > maxBytes) {
        say(files[i].name + ' is over the ' + mb(maxBytes) + ' limit for a ' + kind + '. Choose a smaller one.');
        input.value = '';
        return;
      }
    }
    say('');
  }

  [].forEach.call(form.querySelectorAll('input[type="file"][data-kind]'), function (input) {
    input.addEventListener('change', function () { check(input); });
  });
  [].forEach.call(form.querySelectorAll('input[name="remove_media[]"]'), function (box) {
    box.addEventListener('change', function () { say(''); });
  });

  form.addEventListener('submit', function () {
    var sending = [].some.call(form.querySelectorAll('input[type="file"]'), function (i) { return i.files && i.files.length; });
    if (sending && btn) { btn.disabled = true; btn.textContent = 'Uploading, please wait…'; }
  });
  window.addEventListener('pageshow', function () { if (btn) { btn.disabled = false; btn.textContent = label; } });
})();

// Resort switcher (staff area): a search box on top of the list of resorts. Without JavaScript the list is still plain links.
(function () {
  [].forEach.call(document.querySelectorAll('[data-resort-switch]'), function (box) {
    var menu = box.querySelector('details');
    var search = box.querySelector('[data-rs-search]');
    var empty = box.querySelector('[data-rs-empty]');
    var items = [].slice.call(box.querySelectorAll('.rs-list li'));
    if (!menu || !search) return;
    search.hidden = false;

    function visibleLinks() {
      return items.filter(function (li) { return !li.hidden; }).map(function (li) { return li.querySelector('a'); });
    }
    function filter() {
      var q = search.value.trim().toLowerCase();
      var shown = 0;
      items.forEach(function (li) {
        var hit = !q || (li.getAttribute('data-search') || '').indexOf(q) !== -1;
        li.hidden = !hit;
        if (hit) shown++;
      });
      if (empty) empty.hidden = shown !== 0;
    }
    function close(refocus) {
      menu.open = false;
      if (refocus) menu.querySelector('summary').focus();
    }

    search.addEventListener('input', filter);
    menu.addEventListener('toggle', function () {
      if (menu.open) { search.value = ''; filter(); search.focus(); }
    });
    search.addEventListener('keydown', function (e) {
      var links = visibleLinks();
      if (e.key === 'ArrowDown' && links.length) { e.preventDefault(); links[0].focus(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (links.length) links[0].click(); }
    });
    box.addEventListener('keydown', function (e) {
      var links = visibleLinks();
      var i = links.indexOf(document.activeElement);
      if (e.key === 'Escape' && menu.open) { e.preventDefault(); close(true); }
      else if (i !== -1 && e.key === 'ArrowDown') { e.preventDefault(); (links[i + 1] || links[i]).focus(); }
      else if (i !== -1 && e.key === 'ArrowUp') { e.preventDefault(); (i > 0 ? links[i - 1] : search).focus(); }
    });
    document.addEventListener('click', function (e) {
      if (menu.open && !box.contains(e.target)) close(false);
    });
  });
})();

/* Tables: when every cell in a column is a number, right-align its heading too, so heading and figures share one edge. */
(function () {
  document.querySelectorAll('table:not(.cal)').forEach(function (t) {
    var head = t.tHead;
    if (!head || !head.rows.length) return;
    var ths = Array.prototype.slice.call(head.rows[head.rows.length - 1].cells);
    var rows = [];
    Array.prototype.forEach.call(t.tBodies, function (b) {
      Array.prototype.forEach.call(b.rows, function (r) { if (r.cells.length === ths.length) rows.push(r); });
    });
    if (!rows.length) return;
    ths.forEach(function (th, i) {
      if (th.classList.contains('num') || th.classList.contains('right')) return;
      var all = rows.every(function (r) { var c = r.cells[i]; return c.classList.contains('num') || c.classList.contains('right'); });
      if (all) th.classList.add('num');
    });
  });
})();

/* Header: choose the layout that fits. Full links when there is room, the menu button when there is not,
   whatever the resort's name length or the window width. */
(function () {
  var top = document.getElementById('top');
  var bar = top && top.querySelector('.bar');
  if (!bar) return;
  var queued = false;
  function fit() {
    queued = false;
    top.removeAttribute('data-compact');                       // measure the full layout
    var tooWide = window.innerWidth <= 1040 || bar.scrollWidth > bar.clientWidth + 1;
    if (tooWide) top.setAttribute('data-compact', '');
    else {
      top.removeAttribute('data-open');
      var t = top.querySelector('.nav-toggle');
      if (t) t.setAttribute('aria-expanded', 'false');
    }
  }
  function queue() { if (!queued) { queued = true; requestAnimationFrame(fit); } }
  fit();
  window.addEventListener('resize', queue);
  window.addEventListener('load', queue);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(queue);
})();

/* Resort cancellation terms: grey out the days box while online cancellation is switched off. */
(function () {
  document.querySelectorAll('[data-cancel-switch]').forEach(function (sw) {
    var box = sw.closest('form') && sw.closest('form').querySelector('.cancel-days');
    if (!box) return;
    function sync() { box.classList.toggle('is-off', !sw.checked); }
    sw.addEventListener('change', sync);
    sync();
  });
})();

// Room page: photo gallery (large photo, thumbnails, arrows, swipe, left/right keys)
(function () {
  var g = document.querySelector('[data-gallery]');
  if (!g) return;
  var main = g.querySelector('[data-main]'), thumbs = [].slice.call(g.querySelectorAll('[data-thumb]'));
  var count = g.querySelector('[data-count]'), cur = 0;
  if (!main || thumbs.length < 2) return;
  function show(i) {
    cur = (i + thumbs.length) % thumbs.length;
    main.src = thumbs[cur].getAttribute('data-src');
    main.alt = main.alt.replace(/photo \d+ of/, 'photo ' + (cur + 1) + ' of');
    thumbs.forEach(function (t, k) {
      t.classList.toggle('is-on', k === cur);
      if (k === cur) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
    });
    if (count) count.textContent = (cur + 1) + ' / ' + thumbs.length;
    var t = thumbs[cur], strip = t.parentNode;
    if (strip.scrollWidth > strip.clientWidth) strip.scrollTo({ left: t.offsetLeft - strip.clientWidth / 2 + t.clientWidth / 2, behavior: 'smooth' });
  }
  thumbs.forEach(function (t, k) { t.addEventListener('click', function () { show(k); }); });
  var p = g.querySelector('[data-prev]'), n = g.querySelector('[data-next]');
  if (p) p.addEventListener('click', function () { show(cur - 1); });
  if (n) n.addEventListener('click', function () { show(cur + 1); });
  document.addEventListener('keydown', function (e) {
    if (/^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName || '') || document.documentElement.classList.contains('modal-open')) return;
    if (e.key === 'ArrowLeft') show(cur - 1);
    if (e.key === 'ArrowRight') show(cur + 1);
  });
  var x0 = null, box = g.querySelector('.rd-main');
  box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  box.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 50) show(cur + (dx < 0 ? 1 : -1));
    x0 = null;
  }, { passive: true });
  thumbs.forEach(function (t) { var im = new Image(); im.src = t.getAttribute('data-src'); });   // preload so switching is instant
})();

// Room page booking card: keep the number of rooms right as the group changes, and for logged-in guests
// refresh the availability when the dates or group change.
(function () {
  var form = document.querySelector('[data-room-form]');
  if (!form) return;
  var cap = Math.max(1, parseInt(form.getAttribute('data-cap'), 10) || 1);
  var rooms = form.querySelector('[data-rooms]');
  var adults = form.querySelector('[name="adults"]'), children = form.querySelector('[name="children"]');
  function need() {
    var g = Math.max(1, parseInt(adults.value, 10) || 1) + Math.max(0, parseInt(children.value, 10) || 0);
    return Math.max(1, Math.ceil(g / cap));
  }
  function sync() { if (rooms) rooms.value = need(); }
  var timer = null;
  function refresh() {
    if (form.getAttribute('data-live') !== '1' || !form.checkValidity()) return;
    var q = new URLSearchParams();
    ['r', 'id', 'cottage', 'check_in', 'check_out', 'adults', 'children'].forEach(function (k) {
      var el = form.querySelector('[name="' + k + '"]');
      if (el && el.value !== '') q.set(k, el.value);
    });
    location.replace(form.getAttribute('data-self') + (form.getAttribute('data-self').indexOf('?') > -1 ? '&' : '?') + q.toString() + '#book');
  }
  form.addEventListener('input', sync);
  form.addEventListener('change', function () {
    sync();
    clearTimeout(timer);
    timer = setTimeout(refresh, 400);
  });
  sync();
})();

// Room page: click the big photo, or "Show all photos", to see every photo full size with next/previous.
(function () {
  var g = document.querySelector('[data-gallery]');
  if (!g) return;
  var links = [].slice.call(g.parentNode.querySelectorAll('a[data-room-photo]'));
  var main = g.querySelector('[data-main]');
  if (!links.length || !main) return;
  function current() {
    var on = g.querySelector('.rd-thumb.is-on');
    var i = on ? parseInt(on.getAttribute('data-thumb'), 10) : 0;
    return links[i] || links[0];
  }
  main.addEventListener('click', function () { current().click(); });
  var all = g.querySelector('[data-showall]');
  if (all) all.addEventListener('click', function () { current().click(); });
})();

// Resort settings: dim the downpayment percentage input when online payments are switched off.
(function () {
  document.querySelectorAll('[data-pay-switch]').forEach(function (box) {
    var field = box.closest('fieldset');
    var dpWrap = field ? field.querySelector('.cancel-days') : null;
    if (!dpWrap) return;
    function sync() { dpWrap.classList.toggle('is-off', !box.checked); }
    box.addEventListener('change', sync);
    sync();
  });
})();

// One-click copy for resort GCash / Maya numbers.
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!btn) return;
    var text = btn.getAttribute('data-copy') || '';
    if (!text) return;
    function done() {
      var orig = btn.dataset.orig || btn.textContent;
      btn.dataset.orig = orig;
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = orig; }, 1600);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () {});
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (err) {}
      document.body.removeChild(ta);
    }
  });
})();

// Staff / Admin / Super Admin live notification bell.
(function () {
  var menu = document.querySelector('[data-notif-menu]');
  if (!menu) return;
  var badge = menu.querySelector('[data-notif-badge]');
  var list = menu.querySelector('[data-notif-list]');
  var clearBtn = menu.querySelector('[data-notif-clear-btn]');
  var readAllForm = menu.querySelector('[data-notif-readall]');
  var bellBtn = menu.querySelector('.notif-btn');
  var csrf = menu.getAttribute('data-csrf') || '';
  var lastUnread = parseInt(menu.getAttribute('data-unread') || '0', 10) || 0;
  var firstId = 0;
  var firstLink = list ? list.querySelector('[data-notif-id]') : null;
  if (firstLink) firstId = parseInt(firstLink.getAttribute('data-notif-id') || '0', 10) || 0;

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function kindLabel(k) {
    return k === 'payment' ? 'Payment' : (k === 'cancelled' ? 'Cancelled' : 'Booking');
  }
  function render(data) {
    if (!data || typeof data.unread !== 'number') return;
    var u = data.unread;
    if (badge) {
      badge.textContent = u > 99 ? '99+' : String(u);
      badge.hidden = u <= 0;
    }
    if (clearBtn) clearBtn.hidden = u <= 0;
    if (list && Array.isArray(data.items)) {
      if (!data.items.length) {
        list.innerHTML = '<li class="notif-empty">No notifications yet.</li>';
      } else {
        list.innerHTML = data.items.map(function (n) {
          return '<li class="notif-item' + (n.is_read ? '' : ' is-unread') + '">'
            + '<a href="' + esc(n.href) + '" data-notif-id="' + n.id + '">'
            + '<span class="notif-top"><span class="notif-kind is-' + esc(n.kind) + '">' + esc(kindLabel(n.kind)) + '</span>'
            + '<small class="notif-time">' + esc(n.time) + '</small></span>'
            + '<strong>' + esc(n.title) + '</strong>'
            + '<span class="notif-body">' + esc(n.body) + '</span>'
            + (n.resort_name ? '<small class="notif-resort">' + esc(n.resort_name) + '</small>' : '')
            + '</a></li>';
        }).join('');
      }
    }
  }
  function showToast(item) {
    if (!item) return;
    var old = document.querySelector('.notif-toast');
    if (old) old.remove();
    var a = document.createElement('a');
    a.className = 'notif-toast';
    a.href = item.href;
    a.innerHTML = '<strong>' + esc(item.title) + '</strong><small>' + esc(item.body) + (item.resort_name ? ' · ' + esc(item.resort_name) : '') + '</small>';
    document.body.appendChild(a);
    setTimeout(function () { if (a.parentNode) a.remove(); }, 7000);
  }

  // Close dropdown on outside click or Escape.
  document.addEventListener('click', function (e) {
    if (menu.open && !menu.contains(e.target)) menu.open = false;
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.open) menu.open = false;
  });

  // Mark single notification read when clicked.
  menu.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('a[data-notif-id]') : null;
    if (!link) return;
    var id = link.getAttribute('data-notif-id');
    if (!id || !csrf) return;
    var body = new URLSearchParams({ csrf: csrf, action: 'read', notif_id: id });
    if (navigator.sendBeacon) {
      navigator.sendBeacon('notifications.php', body);
    } else if (window.fetch) {
      fetch('notifications.php', { method: 'POST', body: body, keepalive: true, headers: { 'X-Requested-With': 'fetch' } }).catch(function () {});
    }
  });

  // Mark all read without leaving the page.
  if (readAllForm && window.fetch) {
    readAllForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = new URLSearchParams({ csrf: csrf, action: 'read_all' });
      fetch('notifications.php', { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) { lastUnread = d.unread || 0; render(d); })
        .catch(function () { readAllForm.submit(); });
    });
  }

  // Poll every 15 seconds while the tab is visible so new bookings / payments appear immediately.
  if (window.fetch) {
    setInterval(function () {
      if (document.hidden) return;
      fetch('notifications.php', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !Array.isArray(d.items)) return;
          var newest = d.items[0] || null;
          var newestId = newest ? newest.id : 0;
          if (newestId > firstId && d.unread > lastUnread) {
            if (bellBtn) {
              bellBtn.classList.remove('is-ring');
              void bellBtn.offsetWidth;
              bellBtn.classList.add('is-ring');
            }
            showToast(newest);
          }
          firstId = Math.max(firstId, newestId);
          lastUnread = d.unread;
          render(d);
        })
        .catch(function () {});
    }, 15000);
  }
})();

// Cottage (Day use) vs Overnight Room selector inside the search bar.
(function () {
  document.querySelectorAll('[data-mode-select]').forEach(function (sel) {
    var form = sel.closest('form');
    if (!form) return;
    var outWrap = form.querySelector('[data-out-wrap]');
    var outInput = outWrap ? outWrap.querySelector('input[name="check_out"]') : null;
    var inLabel = form.querySelector('[data-in-label]');
    var btn = form.querySelector('[data-search-btn]');

    function apply() {
      var isCot = sel.value === 'cottage';
      form.classList.toggle('is-cottage', isCot);
      if (outWrap) outWrap.hidden = isCot;
      if (outInput) {
        outInput.disabled = isCot;
        outInput.required = !isCot;
      }
      if (inLabel) inLabel.textContent = isCot ? 'Visit date' : 'Check-in';
      if (btn) btn.textContent = isCot ? 'Find cottages' : 'Check rooms';
    }

    sel.addEventListener('change', apply);
    apply();
  });
})();

// Fullscreen payment screenshot viewer with a clear Back button (works on all admin & guest pages).
(function () {
  var overlay = null, imgEl = null, titleEl = null, backBtn = null, lastTrigger = null;

  function ensure() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'proof-viewer';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Payment screenshot');
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="pv-card">' +
        '<div class="pv-bar">' +
          '<button type="button" class="btn sm pv-back" data-pv-close>&larr; Back</button>' +
          '<span class="pv-title">Payment screenshot</span>' +
          '<button type="button" class="modal-x pv-x" data-pv-close aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="pv-stage">' +
          '<img class="pv-img" src="" alt="Payment screenshot">' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    imgEl = overlay.querySelector('.pv-img');
    titleEl = overlay.querySelector('.pv-title');
    backBtn = overlay.querySelector('.pv-back');

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay || e.target.closest('[data-pv-close]')) {
        close();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay && !overlay.hidden) {
        close();
      }
    });
  }

  function open(src, label, trigger) {
    ensure();
    lastTrigger = trigger || null;
    imgEl.src = src;
    titleEl.textContent = label || 'Payment screenshot';
    overlay.hidden = false;
    document.documentElement.classList.add('modal-open');
    if (backBtn) backBtn.focus();
  }

  function close() {
    if (!overlay || overlay.hidden) return;
    overlay.hidden = true;
    imgEl.src = '';
    document.documentElement.classList.remove('modal-open');
    if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('a.proof-link') : null;
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (!href) return;
    e.preventDefault();
    var title = link.getAttribute('data-proof-title') || 'Payment screenshot';
    open(href, title, link);
  });
})();

// Homepage full-bleed hero slideshow with animated progress bars (Disney Cruise–style).
(function () {
  var root = document.querySelector('[data-hero-slider]');
  if (!root) return;
  var slides = [].slice.call(root.querySelectorAll('.hs-slide'));
  var bars = [].slice.call(root.querySelectorAll('.hs-bar'));
  if (slides.length < 2) return;

  var dur = Math.max(2500, parseInt(root.getAttribute('data-interval') || '5500', 10) || 5500);
  root.style.setProperty('--hs-dur', dur + 'ms');

  var credit = root.querySelector('[data-hs-credit]');
  var nameEl = root.querySelector('[data-hs-name]');
  var locEl = root.querySelector('[data-hs-loc]');
  var prevBtn = root.querySelector('[data-hs-prev]');
  var nextBtn = root.querySelector('[data-hs-next]');
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var idx = 0;
  var timer = null;
  var remaining = dur;
  var startedAt = Date.now();
  var paused = false;

  function show(nextIdx) {
    idx = (nextIdx + slides.length) % slides.length;
    slides.forEach(function (s, i) {
      var on = i === idx;
      s.classList.toggle('is-active', on);
      s.setAttribute('aria-hidden', on ? 'false' : 'true');
    });
    bars.forEach(function (b, i) {
      b.classList.remove('is-active', 'is-done');
      b.setAttribute('aria-selected', i === idx ? 'true' : 'false');
      if (i < idx) {
        b.classList.add('is-done');
      } else if (i === idx) {
        // Restart CSS fill animation cleanly
        void b.offsetWidth;
        b.classList.add('is-active');
      }
    });
    var active = slides[idx];
    if (credit && active) {
      credit.setAttribute('href', active.dataset.href || 'index.php#resorts');
      if (nameEl) nameEl.textContent = active.dataset.name || '';
      if (locEl) locEl.textContent = active.dataset.loc || '';
    }
    remaining = dur;
    schedule();
  }

  function schedule() {
    clearTimeout(timer);
    if (reduced || paused) return;
    startedAt = Date.now();
    timer = setTimeout(function () { show(idx + 1); }, remaining);
  }

  function pause() {
    if (paused) return;
    paused = true;
    root.classList.add('is-paused');
    clearTimeout(timer);
    remaining = Math.max(400, remaining - (Date.now() - startedAt));
  }

  function resume() {
    if (!paused) return;
    paused = false;
    root.classList.remove('is-paused');
    schedule();
  }

  bars.forEach(function (b) {
    b.addEventListener('click', function () {
      show(parseInt(b.getAttribute('data-hs-go') || '0', 10) || 0);
    });
  });
  if (prevBtn) prevBtn.addEventListener('click', function () { show(idx - 1); });
  if (nextBtn) nextBtn.addEventListener('click', function () { show(idx + 1); });

  root.addEventListener('mouseenter', pause);
  root.addEventListener('mouseleave', resume);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) pause(); else resume();
  });

  schedule();
})();

// Show / hide password toggle across all auth forms.
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('[data-pw-toggle]') : null;
  if (!btn) return;
  var wrap = btn.closest('.pw-wrap');
  var input = wrap ? wrap.querySelector('input') : null;
  if (!input) return;
  var showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.textContent = showing ? 'Show' : 'Hide';
  btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
  btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
});


// Editorial magazine homepage (index.php · body.home-lp): sticky header transition & directory tabs.
(function () {
  if (!document.body.classList.contains('home-lp')) return;

  // 1. Transparent navbar -> solid warm limestone on scroll
  var topBar = document.getElementById('top');
  if (topBar) {
    var syncTop = function () { topBar.classList.toggle('is-solid', window.scrollY > 36); };
    window.addEventListener('scroll', syncTop, { passive: true });
    syncTop();
  }

  // 2. Directory showcase tabs (Featured resorts | Day-use cottages)
  var tabBar = document.querySelector('[data-mg-tabs]');
  if (tabBar) {
    var tabs   = Array.prototype.slice.call(tabBar.querySelectorAll('[data-mg-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-mg-panel]'));
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var target = tab.getAttribute('data-mg-tab');
        tabs.forEach(function (t) {
          var on = t === tab;
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(function (p) {
          var match = p.getAttribute('data-mg-panel') === target;
          p.hidden = !match;
          p.classList.toggle('is-active', match);
        });
      });
    });
  }
})();


// Live name search bar & 2-item capped list ("View all N") for Book / Rooms / Landing / Search pages.
(function () {
  // 1. "View all N" / "Show less" toggles on capped lists
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-cap-toggle]') : null;
    if (!btn) return;
    var moreWrap = btn.closest('[data-cap-more]');
    var list = moreWrap ? moreWrap.previousElementSibling : null;
    if (!list || !list.hasAttribute('data-cap-list')) return;
    var expanded = list.getAttribute('data-expanded') === '1';
    list.setAttribute('data-expanded', expanded ? '0' : '1');
    list.querySelectorAll('[data-cap-extra]').forEach(function (el) {
      el.hidden = expanded;
    });
    var total = btn.getAttribute('data-total') || '';
    btn.textContent = expanded ? ('View all ' + total) : 'Show less';
  });

  // 2. Live name search filter (searches across all items including capped extras)
  var input = document.querySelector('[data-stay-filter]');
  var scope = document.querySelector('[data-stay-filter-scope]');
  if (!input || !scope) return;
  var clearBtn = document.querySelector('[data-stay-filter-clear]');
  var emptyMsg = scope.querySelector('[data-stay-filter-empty]');
  var sections = Array.prototype.slice.call(scope.querySelectorAll('[data-filter-section]'));
  var lists    = Array.prototype.slice.call(scope.querySelectorAll('[data-cap-list]'));
  var rows     = Array.prototype.slice.call(scope.querySelectorAll('.type-row'));

  function run() {
    var q = input.value.trim().toLowerCase();
    if (clearBtn) clearBtn.hidden = q === '';
    var totalShown = 0;

    if (q !== '') {
      // Searching: reveal matching rows even if capped, hide "View all N" buttons
      lists.forEach(function (l) {
        var more = l.nextElementSibling;
        if (more && more.hasAttribute('data-cap-more')) more.hidden = true;
        l.querySelectorAll('.cap-item').forEach(function (ci) { ci.hidden = false; });
      });
      rows.forEach(function (row) {
        var hay = (row.textContent || '').toLowerCase();
        var match = hay.indexOf(q) !== -1;
        var wrap = row.closest('.cap-item') || row;
        wrap.hidden = !match;
        row.hidden = !match;
        if (match) totalShown++;
      });
    } else {
      // Cleared: restore 2-item cap state
      rows.forEach(function (row) { row.hidden = false; });
      lists.forEach(function (l) {
        var expanded = l.getAttribute('data-expanded') === '1';
        var more = l.nextElementSibling;
        if (more && more.hasAttribute('data-cap-more')) more.hidden = false;
        l.querySelectorAll('.cap-item').forEach(function (ci) {
          ci.hidden = ci.hasAttribute('data-cap-extra') && !expanded;
        });
      });
      totalShown = rows.length;
    }

    sections.forEach(function (sec) {
      var secRows = sec.querySelectorAll('.type-row');
      var any = false;
      secRows.forEach(function (r) {
        var w = r.closest('.cap-item') || r;
        if (!r.hidden && !w.hidden) any = true;
      });
      sec.hidden = !any;
    });
    if (emptyMsg) emptyMsg.hidden = totalShown > 0 || rows.length === 0;
  }

  input.addEventListener('input', run);
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      input.value = '';
      run();
      input.focus();
    });
  }
  run();
})();

// Interactive hover tooltip for Super Admin Analytics charts ([data-chart-tip])
(function () {
  var tip = document.getElementById('an-tooltip');
  if (!tip) return;
  var activeEl = null;

  function place(e) {
    var pad = 12;
    var x = e.clientX + 14;
    var y = e.clientY - tip.offsetHeight - 12;
    if (x + tip.offsetWidth + pad > window.innerWidth) {
      x = Math.max(pad, e.clientX - tip.offsetWidth - 14);
    }
    if (y < pad) {
      y = e.clientY + 16;
    }
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
  }

  document.addEventListener('mouseover', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-chart-tip]') : null;
    if (!t) return;
    activeEl = t;
    tip.textContent = t.getAttribute('data-chart-tip') || '';
    tip.hidden = false;
    place(e);
  });

  document.addEventListener('mousemove', function (e) {
    if (!activeEl || tip.hidden) return;
    place(e);
  });

  document.addEventListener('mouseout', function (e) {
    if (!activeEl) return;
    var rel = e.relatedTarget;
    if (rel && activeEl.contains && activeEl.contains(rel)) return;
    var next = rel && rel.closest ? rel.closest('[data-chart-tip]') : null;
    if (!next) {
      activeEl = null;
      tip.hidden = true;
    }
  });
})();
