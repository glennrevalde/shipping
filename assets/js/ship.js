/* G-SMaRT Shipping System - motion and small helpers of the shipping pages (no framework). */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // cards and tiles rise into view, one after the other
  function reveal() {
    var els = Array.prototype.slice.call(document.querySelectorAll('.reveal:not(.in)'));
    if (reduce || !('IntersectionObserver' in window)) {
      els.forEach(function (el) { el.classList.add('in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          var el = e.target;
          var i = Number(el.getAttribute('data-i') || 0);
          setTimeout(function () { el.classList.add('in'); }, Math.min(i, 10) * 55);
          io.unobserve(el);
        }
      });
    }, { rootMargin: '0px 0px -30px 0px' });
    els.forEach(function (el, i) { el.setAttribute('data-i', String(i)); io.observe(el); });
  }

  // figures count up to their value
  function countUp() {
    document.querySelectorAll('[data-count]').forEach(function (el) {
      var to = parseFloat(el.getAttribute('data-count'));
      if (!isFinite(to)) return;
      var text = el.textContent;
      var decimals = (text.split('.')[1] || '').replace(/[^0-9]/g, '').length;
      var prefix = text.match(/^[^0-9\-]*/)[0];
      var suffix = text.match(/[^0-9]*$/)[0];
      if (reduce) return;
      var start = null;
      var dur = 900;
      function fmt(v) {
        return prefix + v.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
      }
      function step(ts) {
        if (!start) start = ts;
        var p = Math.min(1, (ts - start) / dur);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = fmt(to * eased);
        if (p < 1) requestAnimationFrame(step); else el.textContent = text;
      }
      requestAnimationFrame(step);
    });
  }

  // meters fill to their width
  function meters() {
    document.querySelectorAll('.meter > span[data-w]').forEach(function (s) {
      var w = s.getAttribute('data-w') + '%';
      if (reduce) { s.style.width = w; return; }
      requestAnimationFrame(function () { setTimeout(function () { s.style.width = w; }, 120); });
    });
  }

  // QR codes: <div data-qr="text"></div>
  function qr() {
    if (typeof window.qrcode !== 'function') return;
    document.querySelectorAll('[data-qr]').forEach(function (el) {
      if (el.getAttribute('data-done')) return;
      try {
        var q = window.qrcode(0, 'M');
        q.addData(el.getAttribute('data-qr'));
        q.make();
        el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
        el.setAttribute('data-done', '1');
      } catch (e) {
        el.textContent = el.getAttribute('data-qr');
      }
    });
  }

  // confirm buttons: <button data-confirm="Are you sure?">
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  // a select that submits its form when changed: <select data-autosubmit>
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('[data-autosubmit]') && e.target.form) {
      e.target.form.submit();
    }
  });

  window.Ship = { reveal: reveal, qr: qr, meters: meters };
  function boot() { reveal(); countUp(); meters(); qr(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
