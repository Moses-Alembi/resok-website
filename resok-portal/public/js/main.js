// Login Handler
    function handleLogin() {
      window.location.href = 'login';
    }

    // Form Validation
    document.getElementById('registrationForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      
      let isValid = true;
      
      // Email validation
      const email = document.getElementById('email');
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email.value)) {
        document.getElementById('emailError').style.display = 'block';
        isValid = false;
      } else {
        document.getElementById('emailError').style.display = 'none';
      }
      
      // Mobile validation
      const mobile = document.getElementById('mobile');
      const mobileRegex = /^\+[1-9][0-9]{7,14}$/;
      if (!mobileRegex.test(mobile.value)) {
        document.getElementById('mobileError').style.display = 'block';
        isValid = false;
      } else {
        document.getElementById('mobileError').style.display = 'none';
      }
      
      // Password match validation
      const password = document.getElementById('password');
      const confirmPassword = document.getElementById('confirmPassword');
      if (password.value !== confirmPassword.value) {
        document.getElementById('passwordMatchError').style.display = 'block';
        isValid = false;
      } else {
        document.getElementById('passwordMatchError').style.display = 'none';
      }
      
      // Password strength validation
      const passwordRegex = /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,64}$/;
      if (!passwordRegex.test(password.value)) {
        alert('Password must be 8-64 characters and include at least one capital letter, one lowercase letter, and one number');
        isValid = false;
      }
      
      if (isValid) {
        const payload = {
            title: document.getElementById('title').value,
            firstName: document.getElementById('firstName').value,
            middleName: document.getElementById('middleName').value,
            surname: document.getElementById('surname').value,
            profession: document.getElementById('profession').value,
            specialization: document.getElementById('specialization').value,
            institution: document.getElementById('institution').value,
            country: document.getElementById('country').value,
            division: document.getElementById('division').value,
            county: document.getElementById('county').value,
            physicalAddress: document.getElementById('physicalAddress').value,
            payerType: document.getElementById('payerType').value,
            category: document.getElementById('category').value,
            idType: document.querySelector('input[name="idType"]:checked')?.value || 'ID',
            idNumber: document.getElementById('idNumber').value,
            email: document.getElementById('email').value,
            mobile: document.getElementById('mobile').value,
            password: document.getElementById('password').value,
            // Present only when the member arrived from an invitation link. The server
            // marks that invitation used, so it cannot be claimed twice.
            inviteToken: new URLSearchParams(window.location.search).get('invite') || undefined
          };

        try {
          if (window.ResokPortal) {
            const result = await window.ResokPortal.registerMember(payload);

            // A token means verification was not required and they are already signed in, so
            // sending them straight to payment is right. Without one there is a step they
            // must complete in their inbox - and the old code showed that for 1.2 seconds
            // before redirecting to a login page they cannot yet use.
            if (result.token) {
              const successMsg = document.getElementById('successMsg');
              successMsg.textContent = result.message || 'Registration successful. Taking you to payment...';
              successMsg.style.display = 'block';
              window.scrollTo(0, 0);
              setTimeout(() => { window.location.href = 'payment'; }, 1500);
              return;
            }
            showVerifyNotice(payload.email);
          } else {
            throw new Error('Registration service is not available.');
          }
        } catch (error) {
          alert(error.message || 'Registration failed. Please try again.');
        }
      }
    });

    // Real-time validation feedback
    document.getElementById('email').addEventListener('blur', function() {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (this.value && !emailRegex.test(this.value)) {
        document.getElementById('emailError').style.display = 'block';
      } else {
        document.getElementById('emailError').style.display = 'none';
      }
    });

    document.getElementById('mobile').addEventListener('blur', function() {
      const mobileRegex = /^\+[1-9][0-9]{7,14}$/;
      if (this.value && !mobileRegex.test(this.value)) {
        document.getElementById('mobileError').style.display = 'block';
      } else {
        document.getElementById('mobileError').style.display = 'none';
      }
    });

    document.getElementById('confirmPassword').addEventListener('blur', function() {
      const password = document.getElementById('password').value;
      if (this.value && this.value !== password) {
        document.getElementById('passwordMatchError').style.display = 'block';
      } else {
        document.getElementById('passwordMatchError').style.display = 'none';
      }
    });


/**
 * Invitation links (?invite=<token>). The address is filled in and locked because the whole
 * point of the invitation is that it belongs to that member - letting it be edited here
 * would produce an account the invitation was never for.
 */
(function () {
  const token = new URLSearchParams(window.location.search).get('invite');
  if (!token || !/^[a-f0-9]{64}$/.test(token)) return;

  document.addEventListener('DOMContentLoaded', async function () {
    const emailField = document.getElementById('email');
    if (!emailField || !window.ResokPortal) return;
    try {
      const invite = await window.ResokPortal.api('/api/invites/claim/' + token);
      emailField.value = invite.email;
      emailField.readOnly = true;
      emailField.style.background = '#f3f4f7';

      const note = document.createElement('p');
      note.textContent = 'Welcome back. Complete the form below to claim your ReSoK portal account.';
      note.style.cssText = 'margin:0 0 18px;padding:12px 14px;border-radius:6px;background:#eaf7ee;' +
                           'color:#166534;font-size:14px;border:1px solid #bbebc8';
      const form = document.getElementById('registrationForm');
      if (form) form.parentNode.insertBefore(note, form);
    } catch (error) {
      const note = document.createElement('p');
      note.textContent = error.message || 'That invitation link is no longer valid. You can still register below.';
      note.style.cssText = 'margin:0 0 18px;padding:12px 14px;border-radius:6px;background:#fff1f2;' +
                           'color:#a10d23;font-size:14px;border:1px solid #fecdd3';
      const form = document.getElementById('registrationForm');
      if (form) form.parentNode.insertBefore(note, form);
    }
  });
})();


/**
 * Replaces the form with a confirmation that stays on screen.
 *
 * The account exists at this point but cannot be used until the member clicks the link in
 * their inbox, so this is the one moment where being explicit matters more than moving them
 * along. It names the address the link went to - the commonest failure is a typo in the
 * email field, and seeing it spelled out is what catches that - and offers a resend, because
 * a verification lost to a spam folder otherwise leaves the account permanently unusable.
 */
function showVerifyNotice(email) {
  const form = document.getElementById('registrationForm');
  if (!form) return;
  form.style.display = 'none';

  const panel = document.createElement('div');
  panel.style.cssText = 'max-width:640px;margin:0 auto;padding:32px;border:1px solid #bbebc8;' +
                        'background:#f7fdf9;border-radius:10px;text-align:center';

  const icon = document.createElement('div');
  icon.textContent = '\u2709';
  icon.style.cssText = 'font-size:44px;line-height:1;margin-bottom:14px';

  const heading = document.createElement('h2');
  heading.textContent = 'Check your email';
  heading.style.cssText = 'font-size:22px;margin:0 0 12px;color:#0f172a';

  const body = document.createElement('p');
  body.style.cssText = 'font-size:15px;line-height:1.65;color:#475467;margin:0 0 8px';
  body.append(
    document.createTextNode('We have sent a verification link to '),
    Object.assign(document.createElement('strong'), { textContent: email }),
    document.createTextNode('. Click it to activate your account, then sign in to complete your membership payment.')
  );

  const hint = document.createElement('p');
  hint.textContent = 'It usually arrives within a minute. If it does not, check your spam or junk folder.';
  hint.style.cssText = 'font-size:13.5px;color:#667085;margin:0 0 22px';

  const actions = document.createElement('div');
  actions.style.cssText = 'display:flex;gap:10px;justify-content:center;flex-wrap:wrap';

  const login = document.createElement('a');
  login.href = 'login';
  login.textContent = 'Go to sign in';
  login.style.cssText = 'background:#00932e;color:#fff;font-weight:700;padding:12px 20px;' +
                        'border-radius:6px;text-decoration:none;font-size:14px';

  const resend = document.createElement('button');
  resend.type = 'button';
  resend.textContent = 'Resend the email';
  resend.style.cssText = 'background:#eef2f7;color:#344054;font-weight:700;padding:12px 20px;' +
                         'border:0;border-radius:6px;cursor:pointer;font-size:14px;font-family:inherit';
  resend.addEventListener('click', async function () {
    resend.disabled = true;
    resend.textContent = 'Sending...';
    try {
      await window.ResokPortal.resendVerification(email);
      resend.textContent = 'Sent again';
    } catch (error) {
      resend.textContent = error.message || 'Could not resend';
    }
    setTimeout(function () {
      resend.disabled = false;
      resend.textContent = 'Resend the email';
    }, 6000);
  });

  actions.append(login, resend);
  panel.append(icon, heading, body, hint, actions);
  form.parentNode.insertBefore(panel, form);
  window.scrollTo(0, 0);
}
