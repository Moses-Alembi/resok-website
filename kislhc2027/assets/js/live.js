/*
 * Keeps the KISLHC 2027 site in step with the ReSoK portal, which runs registration, the
 * programme and the sponsor list. The pages work without it: if the portal does not answer,
 * nothing on the page changes.
 *
 *  - When registration is open, "Registration" links go straight to the portal's registration.
 *  - When the programme is published, the programme section links to it.
 *  - When sponsors are listed, a "KISLHC 2027 sponsors" block appears above the partners.
 *  - Published speaker profiles appear after the programme section.
 */
(function () {
  var PORTAL = '../resok-portal/public/';
  var API = PORTAL + 'api/index.php?route=';

  function get(route) {
    return fetch(API + route, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error(String(r.status));
      return r.json();
    });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  get('conference/current').then(function (d) {
    var state = d.registration && d.registration.state;
    if (state !== 'open') return;
    document.querySelectorAll('a[href="registration.html"]').forEach(function (a) {
      a.href = PORTAL + 'conference';
      if (/^\s*Registration\s*$/.test(a.textContent)) a.textContent = 'Register now';
    });
    var box = document.querySelector('#register .info-box');
    if (box) {
      box.innerHTML = '<p><i class="fas fa-check-circle"></i> <strong>Registration is open.</strong> Register and pay online with your ReSoK account; members receive the member rate.</p>'
        + '<p style="margin-top:12px"><a class="btn btn-primary" href="' + PORTAL + 'conference">Register now</a></p>';
    }
  }).catch(function () {});

  get('conference/programme').then(function (d) {
    if (!d.published || !d.days || !d.days.length) return;
    var box = document.querySelector('#program .info-box');
    if (box) {
      box.innerHTML = '<p><i class="fas fa-calendar-check"></i> <strong>The KISLHC 2027 programme is published.</strong></p>'
        + '<p style="margin-top:12px"><a class="btn btn-primary" href="' + PORTAL + 'conference-programme">View the programme</a></p>';
    }
  }).catch(function () {});

  // Published speaker profiles, keynotes first, after the programme section.
  get('conference/speakers').then(function (d) {
    var list = d.speakers || [];
    var program = document.getElementById('program');
    if (!list.length || !program) return;
    var html = '<section class="section" id="speakers"><div class="card"><h2 class="section-title"><i class="fas fa-microphone"></i> Speakers</h2>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:22px;margin-top:16px">'
      + list.map(function (s) {
        var name = [s.honorific, s.name].filter(Boolean).join(' ');
        var photo = s.photoUrl
          ? '<img src="' + esc(PORTAL + s.photoUrl) + '" alt="' + esc(name) + '" loading="lazy" style="width:120px;height:120px;border-radius:50%;object-fit:cover">'
          : '<div style="width:120px;height:120px;border-radius:50%;background:#e8f3ec;display:flex;align-items:center;justify-content:center;color:#087539"><i class="fas fa-user fa-2x"></i></div>';
        var sessions = (s.sessions || []).map(function (x) { return esc(x.roleLabel) + ': ' + esc(x.title); }).join('<br>');
        return '<div style="text-align:center">' + '<div style="display:flex;justify-content:center">' + photo + '</div>'
          + '<h3 style="margin:12px 0 2px;font-size:17px">' + esc(name) + '</h3>'
          + (s.featured ? '<div style="color:#087539;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.06em">Keynote</div>' : '')
          + '<div style="color:#667085;font-size:14px">' + esc([s.position, s.institution, s.country].filter(Boolean).join(', ')) + '</div>'
          + (sessions ? '<div style="font-size:13px;margin-top:6px">' + sessions + '</div>' : '')
          + (s.bio ? '<details style="margin-top:8px;text-align:left"><summary style="cursor:pointer;color:#087539;font-weight:600;text-align:center">Biography</summary><p style="margin-top:8px;font-size:14px;white-space:pre-line">' + esc(s.bio) + '</p></details>' : '')
          + '</div>';
      }).join('') + '</div></div></section>';
    program.insertAdjacentHTML('afterend', html);
  }).catch(function () {});

  get('conference/sponsors').then(function (d) {
    var list = (d.sponsors || []).filter(function (s) { return s.logoUrl || s.name; });
    var partners = document.getElementById('partners');
    if (!list.length || !partners) return;
    var byTier = {};
    var order = [];
    list.forEach(function (s) {
      if (!byTier[s.tierLabel]) { byTier[s.tierLabel] = []; order.push(s.tierLabel); }
      byTier[s.tierLabel].push(s);
    });
    var html = '<section class="section" id="sponsors-2027"><div class="card"><h2 class="section-title"><i class="fas fa-handshake"></i> KISLHC 2027 Sponsors</h2>'
      + order.map(function (tier) {
        return '<h3 style="margin:18px 0 10px">' + esc(tier) + '</h3><div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center">'
          + byTier[tier].map(function (s) {
            var inner = s.logoUrl
              ? '<img src="' + esc(PORTAL + s.logoUrl) + '" alt="' + esc(s.name) + '" style="max-height:70px;max-width:180px;object-fit:contain">'
              : '<strong>' + esc(s.name) + '</strong>';
            return s.website ? '<a href="' + esc(s.website) + '" target="_blank" rel="noopener noreferrer">' + inner + '</a>' : inner;
          }).join('') + '</div>';
      }).join('') + '</div></section>';
    partners.insertAdjacentHTML('beforebegin', html);
  }).catch(function () {});
})();
