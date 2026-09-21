/**
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
