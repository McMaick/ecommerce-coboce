/* COBOCE E-Commerce — main.js */
'use strict';

document.addEventListener('DOMContentLoaded', () => {

  // ── Password visibility toggle ────────────────────────────
  document.querySelectorAll('.btn-pwd-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const wrap  = btn.closest('.password-wrap');
      const input = wrap.querySelector('input[type="password"], input[type="text"]');
      const icon  = btn.querySelector('i');
      const show  = input.type === 'password';
      input.type  = show ? 'text' : 'password';
      icon.classList.toggle('bi-eye',       !show);
      icon.classList.toggle('bi-eye-slash',  show);
    });
  });

  // ── Password strength meter ───────────────────────────────
  const pwdInput = document.getElementById('password');
  const meter    = document.getElementById('pwd-strength-bar');
  if (pwdInput && meter) {
    pwdInput.addEventListener('input', () => {
      const v = pwdInput.value;
      let score = 0;
      if (v.length >= 8)          score++;
      if (/[A-Z]/.test(v))        score++;
      if (/[0-9]/.test(v))        score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      meter.className = 'pwd-strength s' + score;
    });
  }

  // ── Auto-dismiss success/info alerts ─────────────────────
  document.querySelectorAll('.alert-success, .alert-info').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el)?.close(), 5000);
  });

  // ── Add to cart button animation ─────────────────────────
  document.querySelectorAll('.btn-add-cart').forEach(btn => {
    btn.addEventListener('click', function (e) {
      if (this.dataset.noAnim) return;
      const original = this.innerHTML;
      this.disabled  = true;
      this.innerHTML = '<i class="bi bi-check-lg"></i> Agregado';
      this.style.background = '#198754';
      setTimeout(() => {
        this.disabled = false;
        this.innerHTML = original;
        this.style.background = '';
      }, 1800);
    });
  });

  // ── Quantity controls ─────────────────────────────────────
  document.querySelectorAll('[data-qty-minus]').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.qtyMinus);
      if (!input) return;
      const step = parseFloat(input.step) || 1;
      const min  = parseFloat(input.min)  || step;
      const val  = parseFloat(input.value) || min;
      if (val > min) input.value = (val - step).toFixed(step < 1 ? 2 : 0);
      input.dispatchEvent(new Event('change'));
    });
  });
  document.querySelectorAll('[data-qty-plus]').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.qtyPlus);
      if (!input) return;
      const step = parseFloat(input.step) || 1;
      const max  = parseFloat(input.max)  || 9999;
      const val  = parseFloat(input.value) || 0;
      if (val < max) input.value = (val + step).toFixed(step < 1 ? 2 : 0);
      input.dispatchEvent(new Event('change'));
    });
  });

  // ── Confirm dangerous actions ─────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });

  // ── Form submit spinner ───────────────────────────────────
  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando...';
      }
    });
  });

});
