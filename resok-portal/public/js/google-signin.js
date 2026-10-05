/**
 * "Continue with Google" button (api/lib/google-signin.php does the checking).
 *
 * Shows nothing at all until the server says Google sign-in is configured, so a page that
 * includes this works exactly as before while google_client_id is empty. Google's own
 * script draws the button; when someone picks an account it gives us a signed ID token,
 * which goes to auth/google and comes back as the same answer a password login gives.
 */
(() => {
  let scriptLoading = null;
  function loadGoogle() {
    if (window.google && window.google.accounts && window.google.accounts.id) return Promise.resolve();
    if (!scriptLoading) {
      scriptLoading = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://accounts.google.com/gsi/client';
        s.async = true;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('Google sign-in could not load.'));
        document.head.appendChild(s);
      });
    }
    return scriptLoading;
  }

  /**
   * Draws the button into container (left hidden until then). purpose "abstracts" lets the
   * server create an author account for someone new; anything else only signs in existing
   * accounts. onResult gets the login response; onError gets an Error with .status.
   * Resolves true when a button was shown.
   */
  async function mount(container, { purpose, onResult, onError, onStart }) {
    const api = window.ResokPortal.api;
    let providers = null;
    try { providers = await api('auth/providers'); } catch (e) { return false; }
    if (!providers || !providers.google) return false;
    try { await loadGoogle(); } catch (e) { return false; }
    window.google.accounts.id.initialize({
      client_id: providers.google,
      ux_mode: 'popup',
      auto_select: false,
      callback: async (response) => {
        if (onStart) onStart();
        try {
          const result = await api('auth/google', { method: 'POST', body: JSON.stringify({ credential: response.credential, purpose }) });
          onResult(result);
        } catch (error) {
          onError(error);
        }
      },
    });
    container.hidden = false;
    const target = container.querySelector('[data-google-button]') || container;
    window.google.accounts.id.renderButton(target, {
      type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'rectangular',
      width: Math.max(220, Math.min(400, target.clientWidth || 320)),
    });
    return true;
  }

  window.ResokGoogle = { mount };
})();
