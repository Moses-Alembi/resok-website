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

  document.addEventListener('submit', handleNewsletterSubmit, true);
}());
