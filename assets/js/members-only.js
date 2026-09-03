/**
 * Click-time prompt for members-only pages.
 *
 * The pages listed below are gated on the server by member-gate.php - that is what actually
 * enforces access, and it stands whether or not this script runs. This only improves what a
 * click feels like: instead of a visitor being thrown onto a login form with no explanation,
 * they get a short prompt offering both doors, log in or join.
 *
 * A signed-in member should never see the prompt, but the session cookie is httpOnly so the
 * page cannot read it - it has to ask the API. That question is only asked when someone
 * actually clicks a gated link, not on every page view, and if it cannot be answered (the
 * member is offline, the API is down) the click is allowed through to the server, which
 * decides for itself. Failing open here costs nothing: the gate is still in front.
 */
(function () {
  "use strict";

  const GATED_PAGES = {
    "learning": "Learning & CME",
    "media-learning": "the Media & Learning Channel",
    "assemblies": "Assemblies & Working Groups",
    "research": "Research & Publications",
    "workshops-and-training": "Courses and Training"
  };

  const SESSION_URL = "/resok-portal/public/api/index.php?route=members/me";
  const LOGIN_URL = "/resok-portal/public/login";
  const JOIN_URL = "/resok-portal/public/";

  let sessionCheck = null; // cached promise - one lookup per page, however many links are clicked
  let modal = null;
  let lastFocused = null;

  /** The gated page a link points at, or null. Handles /research, research.html, and #anchors. */
  function gatedTarget(link) {
    let path;
    try {
      const url = new URL(link.getAttribute("href"), window.location.href);
      if (url.origin !== window.location.origin) return null;
      path = url.pathname;
    } catch {
      return null;
    }
    const slug = path.replace(/\/$/, "").split("/").pop().replace(/\.(html|php)$/i, "");
    return Object.prototype.hasOwnProperty.call(GATED_PAGES, slug) ? slug : null;
  }

  function hasSession() {
    if (!sessionCheck) {
      sessionCheck = fetch(SESSION_URL, { credentials: "same-origin" })
        .then((response) => response.ok)
        .catch(() => true); // unreachable API: let the server answer instead of guessing
    }
    return sessionCheck;
  }

  function injectStyles() {
    if (document.getElementById("membersOnlyStyles")) return;
    const style = document.createElement("style");
    style.id = "membersOnlyStyles";
    style.textContent = `
      .mo-backdrop{position:fixed;inset:0;z-index:2000;display:grid;place-items:center;padding:24px;
        background:rgba(10,22,38,.55);backdrop-filter:blur(2px)}
      .mo-card{width:min(430px,100%);background:#fff;border-radius:12px;padding:32px 28px;text-align:center;
        box-shadow:0 24px 60px rgba(15,23,42,.28);font-family:'Poppins','Segoe UI',sans-serif;color:#111f35}
      .mo-lock{width:58px;height:58px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;
        background:rgba(188,11,34,.08);font-size:24px}
      .mo-card h2{font-family:'Mulish','Poppins',sans-serif;text-transform:uppercase;font-size:1.3rem;margin:0 0 10px}
      .mo-card p{color:#667085;font-size:.92rem;line-height:1.6;margin:0 0 24px}
      .mo-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
      .mo-btn{border:0;border-radius:6px;padding:12px 18px;font-weight:700;font-size:.88rem;cursor:pointer;
        text-decoration:none;display:inline-block;font-family:inherit}
      .mo-btn-login{background:#00932e;color:#fff}
      .mo-btn-join{background:#bc0b22;color:#fff}
      .mo-dismiss{display:block;margin:16px auto 0;background:none;border:0;color:#667085;font-size:.82rem;
        cursor:pointer;text-decoration:underline;font-family:inherit}
      @media(max-width:420px){.mo-actions{flex-direction:column}.mo-btn{width:100%}}
    `;
    document.head.appendChild(style);
  }

  function closeModal() {
    if (!modal) return;
    modal.remove();
    modal = null;
    document.removeEventListener("keydown", onKeydown);
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  function onKeydown(event) {
    if (event.key === "Escape") closeModal();
  }

  function openModal(slug, href) {
    injectStyles();
    closeModal();
    lastFocused = document.activeElement;

    modal = document.createElement("div");
    modal.className = "mo-backdrop";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-label", "Members only");

    const card = document.createElement("div");
    card.className = "mo-card";
    card.innerHTML =
      '<div class="mo-lock" aria-hidden="true">&#128274;</div>' +
      "<h2>Members only</h2>" +
      "<p></p>" +
      '<div class="mo-actions"></div>';
    card.querySelector("p").textContent =
      `${GATED_PAGES[slug]} is available to ReSoK members. Log in to continue, or join the society to get access.`;

    const login = document.createElement("a");
    login.className = "mo-btn mo-btn-login";
    login.textContent = "Log in";
    // Land back on the page they wanted once they are through login.
    login.href = `${LOGIN_URL}?next=${encodeURIComponent("/" + slug)}`;

    const join = document.createElement("a");
    join.className = "mo-btn mo-btn-join";
    join.textContent = "Become a member";
    join.href = JOIN_URL;

    const dismiss = document.createElement("button");
    dismiss.type = "button";
    dismiss.className = "mo-dismiss";
    dismiss.textContent = "Not now";
    dismiss.addEventListener("click", closeModal);

    card.querySelector(".mo-actions").append(login, join);
    card.appendChild(dismiss);
    modal.appendChild(card);
    modal.addEventListener("click", (event) => {
      if (event.target === modal) closeModal();
    });

    document.body.appendChild(modal);
    document.addEventListener("keydown", onKeydown);
    login.focus();
  }

  document.addEventListener("click", (event) => {
    // Leave modified clicks alone - ctrl/cmd/middle-click means "open in a new tab", and
    // hijacking that would be worse than the prompt is good.
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const link = event.target.closest("a[href]");
    if (!link || link.target === "_blank") return;

    const slug = gatedTarget(link);
    if (!slug) return;

    const href = link.href;
    event.preventDefault();
    hasSession().then((signedIn) => {
      if (signedIn) {
        window.location.href = href;
        return;
      }
      openModal(slug, href);
    });
  });
})();
