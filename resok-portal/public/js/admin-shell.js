/**
 * BUMP THE ?v= ON EVERY PAGE THAT LOADS THIS FILE WHEN YOU CHANGE IT - the host serves
 * static assets with a 1-year immutable cache header, so without it the browser keeps
 * running the old copy against new markup. See css/admin-shell.css for the full note.
 *
 * Wiring for the shared super-admin shell (topbar + sidebar): mobile open/close, marking the
 * current page's nav link active from the URL rather than a hand-set class per file, filling
 * the user chip once ResokPortal knows who is signed in, and logout. One file so the five
 * super-admin pages behave identically instead of drifting apart one inline script at a time.
 */
(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  /**
   * What the topbar search can reach. Destinations only - it deliberately does not pretend to
   * search member or event records, which live behind their own filtered endpoints on the
   * pages themselves. Keywords carry the words somebody would actually type for a section
   * whose title does not contain them ("2fa", "tickets", "roll").
   */
  var AREAS = [
    { label: "Dashboard", href: "admin-home", icon: "fa-grid-2",
      hint: "Overview, figures and recent activity", keywords: "home overview kpi" },
    { label: "Analytics", href: "analytics", icon: "fa-chart-line",
      hint: "Membership, learning and readership figures", keywords: "stats figures reports charts" },
    { label: "Administrators", href: "admin-review", icon: "fa-user-shield",
      hint: "Promote, demote and create administrators", keywords: "roles permissions promote admins" },
    { label: "Member register", href: "admin-review", icon: "fa-users",
      hint: "Every member on file, with payment status", keywords: "members list approve reject payments" },
    { label: "Events & attendance", href: "admin-review", icon: "fa-calendar-days",
      hint: "Create events and record CPD attendance", keywords: "cpd tokens attendance events" },
    { label: "Threat Assessment", href: "security", icon: "fa-shield-halved",
      hint: "Live security checks against the running site", keywords: "security posture checks" },
    { label: "Two-factor authentication", href: "security", icon: "fa-key",
      hint: "Set up 2FA on your own account", keywords: "2fa mfa totp authenticator" },
    { label: "Database migrations", href: "security", icon: "fa-database",
      hint: "Apply pending schema files", keywords: "schema sql migrate" },
    { label: "Elections", href: "election-admin", icon: "fa-check-to-slot",
      hint: "Returning officer console", keywords: "vote ballot roll nominations tally" },
    { label: "ICT Operations", href: "ict", icon: "fa-server",
      hint: "Assets, licences, credentials and helpdesk", keywords: "it infrastructure" },
    { label: "Helpdesk", href: "ict#tickets", icon: "fa-life-ring",
      hint: "ICT support queue", keywords: "tickets support issues" },
    { label: "Assets", href: "ict#assets", icon: "fa-laptop",
      hint: "Laptops, devices and equipment", keywords: "hardware devices inventory" },
    { label: "Software & licences", href: "ict#licenses", icon: "fa-box-open",
      hint: "Licence seats and renewals", keywords: "licences subscriptions renewals" },
    { label: "Credentials", href: "ict#credentials", icon: "fa-key",
      hint: "Stored ICT credentials", keywords: "passwords logins secrets" },
    { label: "Staff & access", href: "ict#staff", icon: "fa-user-gear",
      hint: "Who holds which ICT capability", keywords: "capabilities grants ict staff" }
  ];

  function setUpSearch() {
    var input = document.getElementById("adminSearch");
    var box = document.getElementById("adminSearchResults");
    if (!input || !box) return;

    var matches = [];
    var cursor = -1;

    function close() {
      box.hidden = true;
      cursor = -1;
    }

    function render() {
      box.textContent = "";
      if (!matches.length) {
        var none = document.createElement("p");
        none.className = "admin-search-empty";
        none.textContent = "Nothing matches that.";
        box.appendChild(none);
        box.hidden = false;
        return;
      }
      matches.forEach(function (area, index) {
        var link = document.createElement("a");
        link.className = "admin-search-hit" + (index === cursor ? " is-cursor" : "");
        link.href = area.href;
        var icon = document.createElement("i");
        icon.className = "fas " + area.icon;
        var text = document.createElement("span");
        var label = document.createElement("strong");
        label.textContent = area.label;
        var hint = document.createElement("small");
        hint.textContent = area.hint;
        text.append(label, hint);
        link.append(icon, text);
        box.appendChild(link);
      });
      box.hidden = false;
    }

    input.addEventListener("input", function () {
      var q = input.value.trim().toLowerCase();
      if (!q) return close();
      matches = AREAS.filter(function (area) {
        return (area.label + " " + area.hint + " " + area.keywords).toLowerCase().indexOf(q) !== -1;
      });
      cursor = matches.length ? 0 : -1;
      render();
    });

    input.addEventListener("keydown", function (event) {
      if (event.key === "Escape") return close();
      if (!matches.length || box.hidden) return;
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        cursor += event.key === "ArrowDown" ? 1 : -1;
        if (cursor < 0) cursor = matches.length - 1;
        if (cursor >= matches.length) cursor = 0;
        render();
      } else if (event.key === "Enter" && cursor > -1) {
        event.preventDefault();
        location.href = matches[cursor].href;
      }
    });

    document.addEventListener("click", function (event) {
      if (!event.target.closest(".admin-search")) close();
    });
  }

  ready(function () {
    var sidebar = document.getElementById("adminSidebar");
    var backdrop = document.getElementById("adminSidebarBackdrop");
    var toggle = document.getElementById("adminSidebarToggle");

    function closeSidebar() {
      if (sidebar) sidebar.classList.remove("open");
      if (backdrop) backdrop.classList.remove("open");
    }

    if (toggle && sidebar) {
      toggle.addEventListener("click", function () {
        sidebar.classList.toggle("open");
        if (backdrop) backdrop.classList.toggle("open");
      });
    }
    if (backdrop) backdrop.addEventListener("click", closeSidebar);
    sidebar && sidebar.querySelectorAll(".admin-nav-link[href]").forEach(function (link) {
      link.addEventListener("click", closeSidebar);
    });

    // Active link: match by page name rather than requiring every page to hand-set it, so a
    // renamed or reordered nav item cannot leave a page with no active state.
    var page = (location.pathname.split("/").pop() || "").replace(/\.html$/, "") || "dashboard";
    document.querySelectorAll(".admin-nav-link[data-page]").forEach(function (link) {
      if (link.dataset.page === page) link.classList.add("active");
    });

    // Light/dark toggle. The theme itself is applied by a tiny blocking script in each page's
    // <head> (so there's no flash on load) - this just handles the click, and shares one
    // localStorage key with admin-home.html so the choice is the same across every page.
    var THEME_KEY = "resok-admin-theme";
    var themeToggle = document.getElementById("adminThemeToggle");
    if (themeToggle) {
      themeToggle.addEventListener("click", function () {
        var next = document.documentElement.getAttribute("data-theme") === "light" ? "dark" : "light";
        document.documentElement.setAttribute("data-theme", next);
        try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
      });
    }

    // Any .list-toggle that names the block it controls folds that block. Markup-only, so a
    // page adds a collapsible section without adding a handler for it.
    document.querySelectorAll(".list-toggle[aria-controls]").forEach(function (button) {
      var body = document.getElementById(button.getAttribute("aria-controls"));
      if (!body) return;
      button.setAttribute("aria-expanded", String(!body.hidden));
      button.addEventListener("click", function () {
        var open = body.hidden;
        body.hidden = !open;
        button.setAttribute("aria-expanded", String(open));
      });
    });

    var fullscreenToggle = document.getElementById("adminFullscreenToggle");
    if (fullscreenToggle) {
      fullscreenToggle.addEventListener("click", function () {
        if (document.fullscreenElement) document.exitFullscreen();
        else document.documentElement.requestFullscreen().catch(function () {});
      });
    }

    setUpSearch();

    var backToTop = document.getElementById("adminBackToTop");
    if (backToTop) {
      backToTop.addEventListener("click", function () {
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    }

    var logoutBtn = document.getElementById("adminLogoutLink");
    if (logoutBtn) {
      logoutBtn.addEventListener("click", function (event) {
        event.preventDefault();
        if (window.ResokPortal && typeof window.ResokPortal.logout === "function") {
          window.ResokPortal.logout();
        } else {
          location.href = "login";
        }
      });
    }

    // The user chip is cosmetic - initials plus role, best-effort from whichever endpoint the
    // page itself already calls. Pages that call /api/admin/whoami expose it via this hook so
    // the chip doesn't need its own extra network round trip.
    window.ResokAdminShell = {
      setUser: function (info) {
        info = info || {};
        var avatar = document.getElementById("adminUserAvatar");
        var name = document.getElementById("adminUserName");
        if (avatar) {
          var source = info.name || info.email || "";
          var initials = source
            .split(/[\s.@]+/)
            .filter(Boolean)
            .slice(0, 2)
            .map(function (part) { return part[0].toUpperCase(); })
            .join("") || "A";
          avatar.textContent = initials;
        }
        if (name) {
          name.textContent = info.name || info.email || "Administrator";
        }
      }
    };
  });
})();
