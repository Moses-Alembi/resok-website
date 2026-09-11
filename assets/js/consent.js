/**
 * Cookie consent for resok.org.
 *
 * Google Analytics previously loaded in the <head> of three pages, before a visitor could
 * express any choice. Under the Kenya Data Protection Act 2019 - and the GDPR, which reaches
 * the Society's international conference traffic - analytics is non-essential processing and
 * needs consent first. So the gtag snippets have been removed from the pages, and this file
 * is now the only thing that can load Analytics: it does so after acceptance, and never
 * otherwise.
 *
 * The session cookie the members' portal sets is deliberately untouched. A login cookie is
 * strictly necessary for a service the member asked for, which is exempt from consent, and
 * gating it would mean nobody could stay signed in.
 */
(function () {
  "use strict";

  // ---------------------------------------------------------------------------------------
  // The site was tagged with two Google Analytics properties, and every page fired both, so
  // visits were being split across two accounts. One is kept. If the other is the property
  // the Secretariat actually reads, change this line - it is the only place the ID appears.
  //   in use  : G-LVVKGWHV01   (was loaded from the <head>)
  //   retired : G-7TPH4SHLMS   (was loaded a second time further down the page)
  var MEASUREMENT_ID = "G-LVVKGWHV01";

  var STORAGE_KEY = "resok_cookie_consent";
  var POLICY_VERSION = 1; // raise this if the policy changes materially and consent must be re-asked

  // ---------------------------------------------------------------------------------------
  // Stored choice. Wrapped because storage throws outright in some privacy modes rather than
  // returning nothing, and a consent banner that breaks the page is worse than no banner.
  function readChoice() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      var saved = JSON.parse(raw);
      if (!saved || saved.version !== POLICY_VERSION) return null;
      return saved.analytics === true ? "accepted" : "rejected";
    } catch (error) {
      return null;
    }
  }

  function writeChoice(accepted) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
        version: POLICY_VERSION,
        analytics: accepted === true,
        at: new Date().toISOString()
      }));
    } catch (error) {
      /* A visitor who blocks storage simply gets asked again next time. */
    }
  }

  // ---------------------------------------------------------------------------------------
  var analyticsLoaded = false;

  function loadAnalytics() {
    if (analyticsLoaded || !MEASUREMENT_ID) return;
    analyticsLoaded = true;

    var tag = document.createElement("script");
    tag.async = true;
    tag.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(MEASUREMENT_ID);
    document.head.appendChild(tag);

    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag("js", new Date());
    // IP anonymisation is on because the Act treats an IP address as personal data, and the
    // Society has no use for it at full precision.
    window.gtag("config", MEASUREMENT_ID, { anonymize_ip: true });
  }

  /**
   * Withdrawing consent has to actually remove what was set, or "reject" is only a word.
   * Cookies are deleted against every parent domain and path they may have been written to,
   * because that is where Google put them.
   */
  function clearAnalyticsCookies() {
    var host = window.location.hostname.replace(/^www\./, "");
    var domains = ["", host, "." + host];
    document.cookie.split(";").forEach(function (entry) {
      var name = entry.split("=")[0].trim();
      if (!/^_ga|^_gid$|^_gat/.test(name)) return;
      domains.forEach(function (domain) {
        document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/"
          + (domain ? "; domain=" + domain : "");
      });
    });
  }

  // ---------------------------------------------------------------------------------------
  function injectStyles() {
    if (document.getElementById("resokConsentStyles")) return;
    var css = document.createElement("style");
    css.id = "resokConsentStyles";
    css.textContent = [
      '.rk-consent{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;background:#fff;',
      'border-top:4px solid #00932e;box-shadow:0 -10px 34px rgba(15,23,42,.18);',
      "font-family:'Poppins','Segoe UI',sans-serif;color:#111f35;padding:20px 0}",
      '.rk-consent[hidden]{display:none}',
      '.rk-consent-in{width:min(1180px,92vw);margin:0 auto;display:flex;gap:26px;',
      'align-items:flex-start;flex-wrap:wrap}',
      '.rk-consent-copy{flex:1 1 460px;min-width:280px}',
      ".rk-consent h2{font-family:'Mulish','Poppins',sans-serif;font-size:1.05rem;margin:0 0 6px;",
      'text-transform:uppercase;letter-spacing:.02em}',
      '.rk-consent p{margin:0;color:#667085;font-size:.88rem;line-height:1.6}',
      '.rk-consent a{color:#00932e;font-weight:600}',
      '.rk-consent-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding-top:4px}',
      '.rk-btn{font:inherit;font-weight:700;font-size:.86rem;border-radius:6px;padding:11px 22px;',
      'cursor:pointer;border:2px solid transparent;transition:opacity .15s}',
      '.rk-btn:hover{opacity:.88}',
      '.rk-btn:focus-visible{outline:3px solid #111f35;outline-offset:2px}',
      '.rk-accept{background:#00932e;color:#fff}',
      '.rk-reject{background:#fff;color:#111f35;border-color:#111f35}',
      '@media(max-width:720px){.rk-consent-actions{width:100%}.rk-btn{flex:1 1 auto}}',
      '.rk-prefs{position:fixed;left:14px;bottom:14px;z-index:2147482000;background:#fff;',
      'border:1px solid #e7ebef;border-radius:999px;padding:8px 14px;font-size:.74rem;',
      "font-family:'Poppins','Segoe UI',sans-serif;color:#667085;cursor:pointer;",
      'box-shadow:0 6px 18px rgba(15,23,42,.12)}',
      '.rk-prefs[hidden]{display:none}'
    ].join("");
    document.head.appendChild(css);
  }

  var banner = null;
  var prefsButton = null;

  function buildBanner() {
    if (banner) return banner;
    injectStyles();

    banner = document.createElement("section");
    banner.className = "rk-consent";
    banner.setAttribute("role", "dialog");
    banner.setAttribute("aria-live", "polite");
    banner.setAttribute("aria-label", "Cookie choices");
    banner.innerHTML =
      '<div class="rk-consent-in">'
      + '<div class="rk-consent-copy">'
      + '<h2>Your choice about cookies</h2>'
      + '<p>We use cookies that are necessary to run this site and keep members signed in. '
      + 'With your permission we would also use Google Analytics to understand which pages are '
      + 'useful. Analytics is optional, and the site works fully without it. '
      + 'See our <a href="cookie-policy.html">Cookie Policy</a> and '
      + '<a href="privacy-policy.html">Privacy Policy</a>.</p>'
      + '</div>'
      + '<div class="rk-consent-actions">'
      + '<button type="button" class="rk-btn rk-accept">Accept analytics</button>'
      + '<button type="button" class="rk-btn rk-reject">Reject &mdash; essential only</button>'
      + '</div></div>';

    banner.querySelector(".rk-accept").addEventListener("click", function () { decide(true); });
    banner.querySelector(".rk-reject").addEventListener("click", function () { decide(false); });

    document.body.appendChild(banner);
    return banner;
  }

  function buildPrefsButton() {
    if (prefsButton) return prefsButton;
    injectStyles();
    prefsButton = document.createElement("button");
    prefsButton.type = "button";
    prefsButton.className = "rk-prefs";
    prefsButton.textContent = "Cookie choices";
    prefsButton.addEventListener("click", function () { showBanner(); });
    document.body.appendChild(prefsButton);
    return prefsButton;
  }

  function showBanner() {
    buildBanner().hidden = false;
    buildPrefsButton().hidden = true;
  }

  function decide(accepted) {
    writeChoice(accepted);
    buildBanner().hidden = true;
    buildPrefsButton().hidden = false;
    if (accepted) {
      loadAnalytics();
    } else {
      clearAnalyticsCookies();
    }
  }

  function start() {
    var choice = readChoice();
    if (choice === "accepted") {
      loadAnalytics();
      buildPrefsButton().hidden = false;
      return;
    }
    if (choice === "rejected") {
      buildPrefsButton().hidden = false;
      return;
    }
    showBanner();
  }

  // A decision made in one tab should not leave another tab still tracking, or still asking.
  window.addEventListener("storage", function (event) {
    if (event.key !== STORAGE_KEY) return;
    var choice = readChoice();
    if (choice === "accepted") { buildBanner().hidden = true; buildPrefsButton().hidden = false; loadAnalytics(); }
    if (choice === "rejected") { buildBanner().hidden = true; buildPrefsButton().hidden = false; }
  });

  window.ReSoKConsent = {
    open: showBanner,
    status: readChoice,
    withdraw: function () { decide(false); }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
