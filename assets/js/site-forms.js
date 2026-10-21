(function () {
  'use strict';

  // The site root, worked out from this script's own address (…/assets/js/site-forms.js).
  // Pages inside the portal load it too, and the site is served both at / and /resok-new/,
  // so a bare relative path like 'newsletter.php' would point somewhere different on each.
  var SELF = document.currentScript ? document.currentScript.src : '';
  var ROOT = SELF ? SELF.replace(/assets\/js\/site-forms\.js(\?.*)?$/, '') : '';

  var proofLoading = null;
  function loadProof() {
    if (window.ResokProof) return Promise.resolve(window.ResokProof);
    if (!proofLoading) {
      proofLoading = new Promise(function (resolve) {
        var s = document.createElement('script');
        s.src = ROOT + 'resok-portal/public/js/proof.js?v=20261017-proof';
        s.onload = function () { resolve(window.ResokProof || null); };
        s.onerror = function () { proofLoading = null; resolve(null); };
        document.head.appendChild(s);
      });
    }
    return proofLoading;
  }

  function findEmail(form) {
    return form.querySelector('input[type="email"], input[name="email"]');
  }

  function setStatus(form, message, isError) {
    var status = form.querySelector('[data-form-status]');
    if (!status) {
      status = document.createElement('p');
      status.setAttribute('data-form-status', '');
      status.setAttribute('role', 'status');
      status.style.marginTop = '10px';
      status.style.fontSize = '0.86rem';
      status.style.fontWeight = '700';
      form.appendChild(status);
    }
    status.textContent = message;
    status.style.color = isError ? '#bc0b22' : '#087539';
  }

  // The newsletter forms are a single row (box + arrow), so their message goes underneath
  // the form rather than inside it, where it would be squeezed in beside the button.
  function setStatusBelow(form, message, isError) {
    var status = form.nextElementSibling;
    if (!status || !status.hasAttribute('data-form-status')) {
      status = document.createElement('p');
      status.setAttribute('data-form-status', '');
      status.setAttribute('role', 'status');
      status.style.margin = '10px 0 0';
      status.style.fontSize = '0.86rem';
      status.style.fontWeight = '700';
      status.style.lineHeight = '1.5';
      form.insertAdjacentElement('afterend', status);
    }
    status.textContent = message;
    status.style.color = isError ? '#e5484d' : '#3ecf74';
  }

  // Posts the address to newsletter.php, which emails a confirmation link. Nothing opens
  // the visitor's email app any more.
  function handleNewsletterSubmit(event) {
    var form = event.target;
    if (!form.matches('.site-footer-subscribe, .home-newsletter-form')) return;

    event.preventDefault();

    var emailInput = findEmail(form);
    var email = emailInput ? emailInput.value.trim() : '';
    if (!email || (emailInput && !emailInput.checkValidity())) {
      if (emailInput) emailInput.focus();
      setStatusBelow(form, 'Please enter a valid email address.', true);
      return;
    }

    var button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    setStatusBelow(form, 'Signing you up...', false);

    var body = new FormData();
    body.append('email', email);
    body.append('source', window.location.pathname);
    fetch(ROOT + 'newsletter.php', { method: 'POST', body: body })
      .then(function (response) {
        // "Verifying your browser": load the solver only when the server asks for it.
        if (response.status !== 428) return response;
        return loadProof().then(function (proof) {
          return proof ? proof.fetch(ROOT + 'newsletter.php', { method: 'POST', body: body }) : response;
        });
      })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok) throw new Error(result.data.error || 'Could not sign you up. Please try again.');
        setStatusBelow(form, result.data.message || 'Almost done! Check your inbox to confirm your subscription.', false);
        form.reset();
      })
      .catch(function (error) {
        setStatusBelow(form, error.message || 'Could not sign you up. Please try again.', true);
      })
      .finally(function () {
        if (button) button.disabled = false;
      });
  }

  function handleContactSubmit(event) {
    var form = event.target;
    if (!form.matches('#contactForm')) return;

    event.preventDefault();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    var submitBtn = form.querySelector('#contactSubmitBtn, button[type="submit"]');
    var originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = 'Sending...';
    }
    setStatus(form, '', false);
    var statusEl = form.querySelector('[data-form-status]');
    if (statusEl) statusEl.style.display = 'none';

    var body = new FormData(form);
    fetch('contact.php', { method: 'POST', body: body })
      .then(function (response) {
        // "Verifying your browser": load the solver only when the server asks for it.
        if (response.status !== 428) return response;
        return loadProof().then(function (proof) {
          return proof ? proof.fetch('contact.php', { method: 'POST', body: body }) : response;
        });
      })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok) throw new Error(result.data.error || 'Could not send your message. Please try again.');
        setStatus(form, result.data.message || 'Thanks for reaching out! We will get back to you shortly.', false);
        form.reset();
      })
      .catch(function (error) {
        setStatus(form, error.message || 'Could not send your message. Please try again or email info@resok.org directly.', true);
      })
      .finally(function () {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnHtml;
        }
        var status = form.querySelector('[data-form-status]');
        if (status) status.style.display = 'block';
      });
  }

  document.addEventListener('submit', handleNewsletterSubmit, true);
  document.addEventListener('submit', handleContactSubmit, true);
}());
