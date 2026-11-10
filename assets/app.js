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

// Full-bleed pages (landing, room): the photo melts into glass as you scroll, and again when you head to the booking pages.
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
  var isRoom = body.classList.contains('room-page');

  if (isLanding || isRoom) {
    var stage = document.querySelector('.landing-hero, .rp-stage');
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

  // Room page: photo slideshow (automatic, with arrows, dots, swipe and keyboard)
  if (isRoom) {
    var slides = [].slice.call(document.querySelectorAll('.rp-slide'));
    var dots = [].slice.call(document.querySelectorAll('.rp-dot'));
    var playBtn = document.querySelector('[data-play]');
    var cur = 0, timer = null, playing = !reduce && slides.length > 1;

    function show(i) {
      if (!slides.length) return;
      cur = (i + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('is-on', k === cur); });
      dots.forEach(function (d, k) { d.classList.toggle('is-on', k === cur); });
    }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function start() { stop(); if (playing && slides.length > 1) timer = setInterval(function () { show(cur + 1); }, 5500); }
    function setPlaying(v) {
      playing = v;
      if (playBtn) {
        playBtn.setAttribute('aria-pressed', v ? 'true' : 'false');
        playBtn.setAttribute('aria-label', v ? 'Pause slideshow' : 'Play slideshow');
      }
      start();
    }
    function manual(i) { show(i); start(); }   // a manual change restarts the timer

    var prev = document.querySelector('[data-prev]'), next = document.querySelector('[data-next]');
    if (prev) prev.addEventListener('click', function () { manual(cur - 1); });
    if (next) next.addEventListener('click', function () { manual(cur + 1); });
    dots.forEach(function (d) { d.addEventListener('click', function () { manual(+d.getAttribute('data-dot')); }); });
    if (playBtn) playBtn.addEventListener('click', function () { setPlaying(!playing); });

    document.addEventListener('keydown', function (e) {
      if (/^(INPUT|SELECT|TEXTAREA)$/.test((e.target.tagName || ''))) return;
      if (e.key === 'ArrowLeft') manual(cur - 1);
      if (e.key === 'ArrowRight') manual(cur + 1);
    });

    // Swipe on touch screens
    var x0 = null, stageEl = document.querySelector('.rp-stage');
    if (stageEl) {
      stageEl.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
      stageEl.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 50) manual(cur + (dx < 0 ? 1 : -1));
        x0 = null;
      }, { passive: true });
    }
    // Save battery: pause while the tab is hidden
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });

    if (reduce && playBtn) playBtn.setAttribute('aria-pressed', 'false');
    if (playBtn && !playing) { playBtn.setAttribute('aria-pressed', 'false'); playBtn.setAttribute('aria-label', 'Play slideshow'); }
    start();

    // "Scroll down" hint scrolls to the booking glass
    var hint = document.querySelector('.rp-hint');
    if (hint) hint.addEventListener('click', function (e) {
      var t = document.getElementById('book');
      if (t) { e.preventDefault(); t.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' }); }
    });
  }
})();
