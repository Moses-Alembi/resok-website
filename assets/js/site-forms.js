(function () {
  'use strict';

  var NEWSLETTER_EMAIL = 'info@resok.org';
  var NEWSLETTER_SUBJECT = 'ReSoK Newsletter Signup';

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

  function handleNewsletterSubmit(event) {
    var form = event.target;
    if (!form.matches('.site-footer-subscribe, .home-newsletter-form')) return;

    event.preventDefault();

    var emailInput = findEmail(form);
    var email = emailInput ? emailInput.value.trim() : '';
    if (!email || (emailInput && !emailInput.checkValidity())) {
      if (emailInput) emailInput.focus();
      setStatus(form, 'Please enter a valid email address.', true);
      return;
    }

    var body = 'Please add this email to the ReSoK newsletter list:%0D%0A%0D%0A' +
      encodeURIComponent(email);
    window.location.href = 'mailto:' + NEWSLETTER_EMAIL +
      '?subject=' + encodeURIComponent(NEWSLETTER_SUBJECT) +
      '&body=' + body;

    setStatus(form, 'Your email client should open so you can send the signup request.', false);
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

    fetch('contact.php', {
      method: 'POST',
      body: new FormData(form)
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
