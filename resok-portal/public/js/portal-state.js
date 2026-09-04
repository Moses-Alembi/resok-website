(function () {
  const STORAGE_KEY = "resok_portal_state";
  const TOKEN_KEY = "token";
  const CARD_ASSET_VERSION = "20260904-blue-bg";
  const CARD_BACKGROUND_PATH = `assets/img/membership-card-bg.png?v=${CARD_ASSET_VERSION}`;

  function defaultState() {
    return {
      loggedIn: false,
      token: "",
      member: {
        title: "",
        firstName: "",
        middleName: "",
        surname: "",
        email: "",
        mobile: "",
        country: "",
        county: "",
        division: "",
        category: "",
        idType: "",
        idNumber: "",
        profileImage: "",
        profileImageUrl: "",
        membershipId: "",
        status: "payment_required",
        cpdPoints: 0,
        renewalDue: "Pending payment"
      },
      payments: []
    };
  }

  function getState() {
    try {
      const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
      return Object.assign(defaultState(), saved || {});
    } catch {
      return defaultState();
    }
  }

  function saveState(state) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    return state;
  }

  function token() {
    return localStorage.getItem(TOKEN_KEY) || getState().token || "";
  }

  function pageName() {
    return (window.location.pathname.split("/").pop() || "index").replace(/\.html$/i, "");
  }

  function isLocalPreview() {
    return ["localhost", "127.0.0.1", ""].includes(window.location.hostname) || window.location.protocol === "file:";
  }

  function ensureLocalPreviewSession() {
    const state = getState();
    if (state.loggedIn) return state;
    state.loggedIn = true;
    state.member = Object.assign({}, state.member, {
      firstName: state.member.firstName || "Local",
      surname: state.member.surname || "Preview",
      email: state.member.email || "local.preview@resok.test",
      category: state.member.category || "Demo Member",
      status: state.member.status || "payment_required"
    });
    return saveState(state);
  }

  function apiUrl(path) {
    const route = String(path || "").replace(/^\/api\/?/, "").replace(/^\/+/, "");
    return `api/index.php?route=${encodeURIComponent(route).replace(/%2F/g, "/")}`;
  }

  async function api(path, options = {}) {
    const headers = Object.assign({}, options.headers || {});
    if (!(options.body instanceof FormData)) headers["Content-Type"] = "application/json";
    // Session lives in the httpOnly resok_token cookie (sent automatically via
    // credentials:"same-origin"); the Authorization header is only a fallback for any
    // caller that still has a token in localStorage from before this change.
    if (token()) headers.Authorization = `Bearer ${token()}`;

    const response = await fetch(apiUrl(path), Object.assign({ credentials: "same-origin" }, options, { headers }));
    let data = null;
    try {
      data = await response.json();
    } catch {
      data = null;
    }
    if (!response.ok) {
      // The server ends a session after 20 minutes with no authenticated request. When that
      // lands mid-page, tear the local session down and send the member to login with an
      // explanation, rather than surfacing a bare "request failed" on whatever they clicked.
      if (response.status === 401 && data?.reason === "idle") endSessionForInactivity();
      throw new Error(data?.error || data?.message || "Request failed");
    }
    return data;
  }

  function memberName(member = getState().member) {
    return [member.title, member.firstName, member.middleName, member.surname]
      .filter(Boolean)
      .join(" ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function initials(member = getState().member) {
    const source = `${member.firstName || "ReSoK"} ${member.surname || "Member"}`;
    return source
      .split(" ")
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0].toUpperCase())
      .join("");
  }

  function avatarUrl(member = getState().member, size = 128) {
    if (member?.profileImageUrl) return member.profileImageUrl;
    const name = encodeURIComponent(memberName(member) || "ReSoK Member");
    return `https://ui-avatars.com/api/?name=${name}&background=00932e&color=fff&size=${size}`;
  }

  function mergeMemberFromApi(member) {
    if (!member) return getState();
    const state = getState();
    state.member = Object.assign({}, state.member, {
      id: member.id || state.member.id,
      userId: member.userId || state.member.userId,
      title: member.title || "",
      firstName: member.firstName || "",
      middleName: member.middleName || "",
      surname: member.surname || "",
      profession: member.profession || "",
      specialization: member.specialization || "",
      institution: member.institution || "",
      country: member.country || "",
      county: member.county || "",
      division: member.division || "",
      physicalAddress: member.physicalAddress || "",
      payerType: member.payerType || "Individual",
      category: member.category || "",
      idType: member.idType || "",
      idNumber: member.idNumber || "",
      mobile: member.mobile || "",
      profileImage: member.profileImage || "",
      profileImageUrl: member.profileImageUrl || "",
      membershipId: member.membershipId || "",
      status: member.membershipStatus || member.status || "payment_required",
      cpdPoints: Number(member.cpdPoints || 0),
      renewalDue: member.renewalDue || "Pending payment",
      reviewReason: member.reviewReason || ""
    });
    return saveState(state);
  }

  function normalizePayment(payment) {
    const dateSource = payment.date || payment.createdAt || "";
    return {
      id: payment.id,
      createdAt: dateSource,
      date: dateSource ? new Date(dateSource).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" }) : "",
      reference: payment.reference || "",
      amount: Number(payment.amount || 0),
      method: payment.method || "M-Pesa Paybill",
      status: payment.status === "paid" ? "Paid" : payment.status === "failed" ? "Failed" : "Pending",
      phone: payment.phone || "",
      type: payment.type || "Membership"
    };
  }

  async function refreshFromApi() {
    // No client-readable flag proves a cookie session exists (it's httpOnly), so this
    // always attempts the call; the server 401s if there is no valid resok_token cookie.
    const [member, payments] = await Promise.all([api("/api/members/me"), api("/api/payments")]);
    const state = mergeMemberFromApi(member);
    state.payments = Array.isArray(payments) ? payments.map(normalizePayment) : [];
    return saveState(state);
  }

  function login(email, user) {
    // The session now lives in the httpOnly resok_token cookie the server just set
    // (issueAuthCookie in index.php) — it's deliberately not stored in localStorage,
    // so it can't be read by any XSS in this page. state.loggedIn is just a UI hint.
    const state = getState();
    state.loggedIn = true;
    state.token = "";
    state.member.email = email || user?.email || state.member.email;
    state.member.role = user?.role || state.member.role || "member";
    state.member.membershipId = user?.membershipId || "";
    state.member.status = user?.membershipStatus || state.member.status;
    state.member.cpdPoints = Number(user?.cpdPoints || 0);
    return saveState(state);
  }

  // Pass { timedOut: true } for an inactivity logout so the login page can say why. Called
  // straight from click handlers in a few pages, hence the defensive check rather than a
  // destructured parameter - an Event object must not read as a timeout.
  function logout(options) {
    const timedOut = Boolean(options && options.timedOut === true);
    api("/api/auth/logout", { method: "POST" }).catch(() => {});
    localStorage.removeItem(TOKEN_KEY);
    try {
      localStorage.removeItem(ACTIVITY_KEY);
    } catch {
      /* private mode - nothing to clear */
    }
    const state = getState();
    state.loggedIn = false;
    state.token = "";
    saveState(state);
    window.location.href = timedOut ? "login?timeout=1" : "login";
  }

  async function registerMember(data) {
    const result = await api("/api/auth/register", {
      method: "POST",
      body: JSON.stringify(data)
    });
    if (result.token) login(data.email, result.user, result.token);
    return result;
  }

  async function updateProfile(updates) {
    const member = await api("/api/members/me", {
      method: "PATCH",
      body: JSON.stringify(updates)
    });
    return mergeMemberFromApi(member);
  }

  async function uploadProfileImage(file) {
    const form = new FormData();
    form.append("profileImage", file);
    const member = await api("/api/members/me/profile-image", { method: "POST", body: form });
    return mergeMemberFromApi(member);
  }

  async function uploadPaymentProof({ amount, phone, type, paymentMode, mpesaCode, proof }) {
    if (!proof) throw new Error("Please upload proof of payment.");
    const form = new FormData();
    form.append("amount", amount);
    form.append("phone", phone || "");
    form.append("type", type || "Membership Registration");
    form.append("paymentMode", paymentMode || "M-PESA Paybill");
    form.append("mpesaCode", mpesaCode || "");
    form.append("proof", proof);
    const result = await api("/api/payments/proof", { method: "POST", body: form });
    if (result.member) mergeMemberFromApi(result.member);
    await refreshFromApi();
    return result;
  }

  async function paymentInstructions() {
    return api("/api/payment-instructions");
  }

  async function initiateStkPush({ amount, phone, type }) {
    return api("/api/payments/stk-push", {
      method: "POST",
      body: JSON.stringify({ amount, phone, type })
    });
  }

  async function paymentStatus(paymentId) {
    return api(`/api/payments/${paymentId}/status`);
  }

  async function pollPaymentStatus(paymentId, { intervalMs = 3000, timeoutMs = 60000 } = {}) {
    const started = Date.now();
    while (Date.now() - started < timeoutMs) {
      const result = await paymentStatus(paymentId);
      if (result.status === "paid" || result.status === "failed") return result;
      await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
    return { status: "pending", timedOut: true };
  }

  async function cpdLedger() {
    return api("/api/cpd/me");
  }

  async function listEvents() {
    return api("/api/events");
  }

  async function registerForEvent(eventId) {
    return api(`/api/events/${eventId}/register`, { method: "POST" });
  }

  /**
   * The resok_token session cookie is httpOnly, so this page can't just read a flag to
   * know if it's logged in — it has to ask the server. Callers must await this and stop
   * if it resolves false (a redirect to login is already underway in that case).
   */
  async function requireAuthForProtectedPage() {
    const page = pageName();
    const protectedPages = new Set(["dashboard", "profile", "payment", "card", "financials", "events"]);
    if (!protectedPages.has(page)) return true;
    if (isLocalPreview()) {
      ensureLocalPreviewSession();
      return true;
    }
    try {
      await api("/api/members/me");
      startIdleWatch();
      return true;
    } catch {
      window.location.href = "login";
      return false;
    }
  }

  function hydrateShell() {
    const member = getState().member;
    document.querySelectorAll(".user-name").forEach((el) => {
      el.textContent = memberName(member) || "Member";
    });
    document.querySelectorAll(".user-avatar").forEach((el) => {
      if (member.profileImageUrl) {
        el.textContent = "";
        el.style.backgroundImage = `url("${member.profileImageUrl}")`;
        el.style.backgroundSize = "cover";
        el.style.backgroundPosition = "center";
      } else {
        el.textContent = initials(member);
        el.style.backgroundImage = "";
      }
    });
  }

  function absoluteAssetUrl(path) {
    try {
      return new URL(path, window.location.href).href;
    } catch {
      return path;
    }
  }

  function blobToDataUrl(blob) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ""));
      reader.onerror = reject;
      reader.readAsDataURL(blob);
    });
  }

  async function imageToDataUrl(src) {
    if (!src) return "";
    const response = await fetch(absoluteAssetUrl(src), { credentials: "same-origin" });
    if (!response.ok) throw new Error("Could not load card image.");
    return blobToDataUrl(await response.blob());
  }

  // Only the background needs inlining for a download to be self-contained: the card
  // artwork already carries the logo, and the design has no photo. Fetching either of those
  // as well just made every download wait on two requests it never used.
  async function membershipCardImageAssets() {
    const background = await imageToDataUrl(CARD_BACKGROUND_PATH).catch(() => absoluteAssetUrl(CARD_BACKGROUND_PATH));
    return { background };
  }

  function validThruLabel(value) {
    const raw = String(value || "").trim();
    if (!raw || /pending/i.test(raw)) return "MM/YY";
    const parsed = new Date(raw);
    if (!Number.isNaN(parsed.getTime())) {
      return `${String(parsed.getMonth() + 1).padStart(2, "0")}/${String(parsed.getFullYear()).slice(-2)}`;
    }
    const match = raw.match(/(\d{4})[-/](\d{1,2})/);
    if (match) return `${String(match[2]).padStart(2, "0")}/${match[1].slice(-2)}`;
    return raw;
  }

  /**
   * Draws a member's card on the ReSoK card artwork.
   *
   * Every coordinate below was measured from the design (Mmebership Card-01), by masking
   * its sample text and reading back the pixel bounds - so generated text lands where the
   * designer put it rather than where it looked about right. Baselines are the bottom of
   * the measured ink; the design has no photo, so there is no photo slot to fill.
   *
   *   category    ink y 285-327, x 32-962, centred     -> baseline 327, centre 506
   *   membership  ink y 390-441, x 56-456, left        -> baseline 441, x 56
   *   VALID/THRU  ink y 384-441, x 638-736, two lines  -> baselines 409 and 441
   *   valid thru  ink y 396-426, x 768-910             -> baseline 426, x 768
   *   name        ink y 558-600, x 248-791, centred    -> baseline 600, centre 506
   */
  function membershipCardSvg(member = getState().member, assets = {}) {
    const esc = (value) => String(value || "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[char]));
    const name = (memberName(member) || "ReSoK Member").toUpperCase();
    const membershipId = member.membershipId || "Pending";
    const category = (member.category || "Member").toUpperCase();
    const validThru = validThruLabel(member.renewalDue || "Annual");
    const backgroundSrc = assets.background || absoluteAssetUrl(CARD_BACKGROUND_PATH);

    // The card is a fixed size but names are not. Rather than let a long name run off the
    // edge, shrink it until the estimated width fits - the ratios are per-family averages
    // for capitals, which is close enough at these sizes and needs no text measurement.
    const fit = (text, size, maxWidth, ratio, spacing = 0) => {
      const width = (chars, px) => chars * px * ratio + Math.max(0, chars - 1) * spacing;
      let px = size;
      while (px > 14 && width(text.length, px) > maxWidth) px -= 1;
      return px;
    };

    const SERIF = "Georgia, 'Times New Roman', 'Nimbus Roman', serif";
    const TECHNO = "Consolas, 'Courier New', 'DejaVu Sans Mono', monospace";

    const categorySize = fit(category, 56, 930, 0.62, 1);
    const idSize = fit(membershipId, 52, 400, 0.62, 2);
    const nameSize = fit(name, 46, 700, 0.62, 3);

    return `<svg xmlns="http://www.w3.org/2000/svg" width="1012" height="645" viewBox="0 0 1012 645">
  <image href="${esc(backgroundSrc)}" x="0" y="0" width="1012" height="645" preserveAspectRatio="xMidYMid slice"/>
  <text x="506" y="327" text-anchor="middle" font-family="${SERIF}" font-size="${categorySize}" font-weight="700" letter-spacing="1" fill="#ffffff">${esc(category)}</text>
  <text x="56" y="441" font-family="${TECHNO}" font-size="${idSize}" font-weight="700" letter-spacing="2" fill="#ffffff">${esc(membershipId)}</text>
  <text x="736" y="409" text-anchor="end" font-family="${SERIF}" font-size="26" font-weight="700" letter-spacing="1" fill="#ffffff">VALID</text>
  <text x="736" y="441" text-anchor="end" font-family="${SERIF}" font-size="26" font-weight="700" letter-spacing="1" fill="#ffffff">THRU</text>
  <text x="768" y="426" font-family="${TECHNO}" font-size="42" font-weight="700" letter-spacing="2" fill="#ffffff">${esc(validThru)}</text>
  <text x="506" y="600" text-anchor="middle" font-family="${TECHNO}" font-size="${nameSize}" font-weight="700" letter-spacing="3" fill="#ffffff">${esc(name)}</text>
</svg>`;
  }

  async function downloadMembershipCard(member = getState().member) {
    if (!member.membershipId) throw new Error("Membership code is not available yet.");
    const assets = await membershipCardImageAssets(member);
    const blob = new Blob([membershipCardSvg(member, assets)], { type: "image/svg+xml" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${member.membershipId}-membership-card.svg`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function currentDateLabel() {
    return new Date().toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
  }

  function paymentNumber(payment, index = 0) {
    return payment.reference || payment.mpesaCode || `RESOK-${String(payment.id || index + 1).padStart(4, "0")}`;
  }

  function feeStatementSvg(member = getState().member, payments = getState().payments, options = {}) {
    const esc = (value) => String(value || "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[char]));
    const amountDue = Number(options.amountDue || member.membershipFee || 5000);
    const paidTotal = payments.reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
    const balance = Math.max(amountDue - paidTotal, 0);
    const name = memberName(member) || "ReSoK Member";
    const rows = (payments.length ? payments : [{ date: "", reference: "No payment recorded", method: "", amount: 0, status: "Pending" }]).slice(0, 8).map((payment, index) => {
      const y = 366 + index * 46;
      return `<text x="72" y="${y}" font-family="Segoe UI, Arial, sans-serif" font-size="20" fill="#344054">${esc(payment.date || "-")}</text>
  <text x="250" y="${y}" font-family="Segoe UI, Arial, sans-serif" font-size="20" fill="#344054">${esc(paymentNumber(payment, index))}</text>
  <text x="516" y="${y}" font-family="Segoe UI, Arial, sans-serif" font-size="20" fill="#344054">${esc(payment.method || "-")}</text>
  <text x="838" y="${y}" text-anchor="end" font-family="Segoe UI, Arial, sans-serif" font-size="20" fill="#344054">KES ${Number(payment.amount || 0).toLocaleString()}</text>
  <line x1="60" y1="${y + 18}" x2="852" y2="${y + 18}" stroke="#e5e7eb"/>`;
    }).join("\n  ");
    return `<svg xmlns="http://www.w3.org/2000/svg" width="920" height="1040" viewBox="0 0 920 1040">
  <rect width="920" height="1040" fill="#ffffff"/>
  <rect x="0" y="0" width="920" height="126" fill="#00932e"/>
  <rect x="0" y="104" width="920" height="22" fill="#bc0b22"/>
  <text x="60" y="58" font-family="Segoe UI, Arial, sans-serif" font-size="31" font-weight="700" fill="#ffffff">Respiratory Society of Kenya</text>
  <text x="60" y="92" font-family="Segoe UI, Arial, sans-serif" font-size="17" fill="#ffffff">Member Fee Statement</text>
  <text x="860" y="56" text-anchor="end" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#ffffff">${esc(currentDateLabel())}</text>
  <text x="60" y="184" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Member</text>
  <text x="60" y="222" font-family="Segoe UI, Arial, sans-serif" font-size="30" font-weight="700" fill="#253141">${esc(name)}</text>
  <text x="60" y="258" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Membership ID: ${esc(member.membershipId || "Pending")} | Category: ${esc(member.category || "Member")}</text>
  <rect x="60" y="294" width="792" height="72" rx="8" fill="#f8fafc" stroke="#e5e7eb"/>
  <text x="96" y="326" font-family="Segoe UI, Arial, sans-serif" font-size="15" font-weight="700" fill="#667085">AMOUNT DUE</text>
  <text x="96" y="352" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="#253141">KES ${amountDue.toLocaleString()}</text>
  <text x="376" y="326" font-family="Segoe UI, Arial, sans-serif" font-size="15" font-weight="700" fill="#667085">TOTAL PAID</text>
  <text x="376" y="352" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="#00932e">KES ${paidTotal.toLocaleString()}</text>
  <text x="654" y="326" font-family="Segoe UI, Arial, sans-serif" font-size="15" font-weight="700" fill="#667085">BALANCE</text>
  <text x="654" y="352" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="#bc0b22">KES ${balance.toLocaleString()}</text>
  <text x="72" y="326" opacity="0">.</text>
  <text x="72" y="416" font-family="Segoe UI, Arial, sans-serif" font-size="16" font-weight="700" fill="#667085">DATE</text>
  <text x="250" y="416" font-family="Segoe UI, Arial, sans-serif" font-size="16" font-weight="700" fill="#667085">REFERENCE</text>
  <text x="516" y="416" font-family="Segoe UI, Arial, sans-serif" font-size="16" font-weight="700" fill="#667085">METHOD</text>
  <text x="838" y="416" text-anchor="end" font-family="Segoe UI, Arial, sans-serif" font-size="16" font-weight="700" fill="#667085">AMOUNT</text>
  <line x1="60" y1="432" x2="852" y2="432" stroke="#cbd5e1"/>
  <g transform="translate(0 88)">${rows}</g>
  <text x="60" y="968" font-family="Segoe UI, Arial, sans-serif" font-size="16" fill="#667085">Generated from the ReSoK Members' Portal.</text>
</svg>`;
  }

  function receiptSvg(payment, member = getState().member, index = 0) {
    const esc = (value) => String(value || "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[char]));
    const amount = Number(payment?.amount || 0);
    const reference = paymentNumber(payment || {}, index);
    return `<svg xmlns="http://www.w3.org/2000/svg" width="1040" height="720" viewBox="0 0 1040 720">
  <rect width="1040" height="720" fill="#ffffff"/>
  <rect x="58" y="48" width="924" height="624" fill="#ffffff" stroke="#d0d5dd" stroke-width="2"/>
  <text x="92" y="104" font-family="Segoe UI, Arial, sans-serif" font-size="28" font-weight="700" fill="#00932e">ReSoK</text>
  <text x="194" y="88" font-family="Segoe UI, Arial, sans-serif" font-size="15" font-weight="700" fill="#253141">RESPIRATORY</text>
  <text x="194" y="108" font-family="Segoe UI, Arial, sans-serif" font-size="15" font-weight="700" fill="#253141">SOCIETY OF KENYA</text>
  <text x="92" y="170" font-family="Segoe UI, Arial, sans-serif" font-size="30" font-weight="700" fill="#253141">PAYMENT RECEIPT</text>
  <text x="716" y="92" font-family="Segoe UI, Arial, sans-serif" font-size="16" fill="#667085">Receipt No.</text>
  <rect x="814" y="66" width="124" height="42" fill="#ffffff" stroke="#e89aaa" stroke-width="2"/>
  <text x="876" y="94" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="18" font-weight="700" fill="#253141">${esc(reference)}</text>
  <text x="716" y="150" font-family="Segoe UI, Arial, sans-serif" font-size="16" fill="#667085">Date</text>
  <rect x="814" y="124" width="124" height="42" fill="#ffffff" stroke="#e89aaa" stroke-width="2"/>
  <text x="876" y="152" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="15" font-weight="700" fill="#253141">${esc(payment?.date || currentDateLabel())}</text>
  <text x="92" y="244" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Received from:</text>
  <text x="248" y="244" font-family="Segoe UI, Arial, sans-serif" font-size="23" font-weight="700" fill="#253141">${esc(memberName(member) || "ReSoK Member")}</text>
  <line x1="248" y1="254" x2="920" y2="254" stroke="#d0d5dd"/>
  <text x="92" y="314" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Being payment of:</text>
  <text x="292" y="314" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="#253141">${esc(payment?.type || "Membership Registration / Renewal")}</text>
  <line x1="292" y1="324" x2="920" y2="324" stroke="#d0d5dd"/>
  <text x="92" y="384" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Amount in words:</text>
  <text x="292" y="384" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="#253141">Kenya Shillings ${amount.toLocaleString()} only</text>
  <line x1="292" y1="394" x2="920" y2="394" stroke="#d0d5dd"/>
  <text x="92" y="470" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Payment Method:</text>
  <text x="292" y="470" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="#253141">${esc(payment?.method || "M-Pesa Paybill")}</text>
  <text x="646" y="470" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Amount in Figures:</text>
  <rect x="814" y="438" width="124" height="48" fill="#ffffff" stroke="#d0d5dd" stroke-width="2"/>
  <text x="876" y="469" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="18" font-weight="700" fill="#253141">${amount.toLocaleString()}</text>
  <text x="646" y="536" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#667085">Ref No:</text>
  <rect x="814" y="506" width="124" height="48" fill="#ffffff" stroke="#d0d5dd" stroke-width="2"/>
  <text x="876" y="537" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="16" font-weight="700" fill="#253141">${esc(reference)}</text>
  <rect x="692" y="558" width="178" height="74" rx="6" fill="none" stroke="#1d4ed8" stroke-width="3" transform="rotate(-18 781 595)"/>
  <text x="780" y="590" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="19" font-weight="800" fill="#1d4ed8" transform="rotate(-18 780 590)">ReSoK PAID</text>
  <text x="92" y="626" font-family="Segoe UI, Arial, sans-serif" font-size="15" fill="#667085">This receipt was generated from the ReSoK Members' Portal.</text>
</svg>`;
  }

  function downloadSvg(svg, filename) {
    const blob = new Blob([svg], { type: "image/svg+xml" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function downloadFeeStatement(options = {}) {
    const state = getState();
    const filenameId = state.member.membershipId || "resok-member";
    downloadSvg(feeStatementSvg(state.member, state.payments, options), `${filenameId}-fee-statement.svg`);
  }

  function downloadReceipt(paymentId) {
    const state = getState();
    const index = state.payments.findIndex((payment) => String(payment.id || payment.reference) === String(paymentId));
    const payment = index >= 0 ? state.payments[index] : state.payments[0];
    if (!payment) throw new Error("No receipt is available yet.");
    downloadSvg(receiptSvg(payment, state.member, Math.max(index, 0)), `${paymentNumber(payment, Math.max(index, 0))}-receipt.svg`);
  }

  /* --------------------------------------------------------------------------
   * Inactivity timeout
   *
   * The server is what actually enforces this: auth() in the API rejects any token
   * whose last-activity stamp is over 20 minutes old, and member-gate.php applies the
   * same window to /learning. This is the browser half, which does three things the
   * server cannot:
   *
   *  - closes the session on schedule instead of leaving a signed-in page sitting open
   *    on a shared screen until someone happens to click something;
   *  - warns a minute before, so a half-filled form is not lost without notice;
   *  - keeps the server's window in step while the member is genuinely working - they
   *    can spend 25 minutes typing into a form without making a single API call, and
   *    without this their eventual save would be the request that gets rejected.
   * -------------------------------------------------------------------------- */
  const IDLE_TIMEOUT_MS = 20 * 60 * 1000;
  const IDLE_WARNING_MS = 60 * 1000;
  const IDLE_TICK_MS = 5 * 1000;
  const KEEPALIVE_MS = 5 * 60 * 1000;
  // In localStorage rather than a variable so that activity in any open portal tab counts
  // for all of them - otherwise a member working in one tab is logged out by an idle one.
  const ACTIVITY_KEY = "resok_last_activity";
  const ACTIVITY_EVENTS = ["mousedown", "keydown", "scroll", "touchstart", "click"];

  let idleTimer = null;
  let lastKeepAlive = 0;
  let warningEl = null;

  function lastActivityAt() {
    try {
      const saved = Number(localStorage.getItem(ACTIVITY_KEY));
      if (Number.isFinite(saved) && saved > 0) return saved;
    } catch {
      /* private mode - fall through to "active now" */
    }
    return Date.now();
  }

  function markActivity() {
    try {
      localStorage.setItem(ACTIVITY_KEY, String(Date.now()));
    } catch {
      /* private mode - the in-page timer below still works, just not across tabs */
    }
  }

  function hideIdleWarning() {
    if (!warningEl) return;
    warningEl.remove();
    warningEl = null;
  }

  function showIdleWarning(secondsLeft) {
    if (!warningEl) {
      warningEl = document.createElement("div");
      warningEl.setAttribute("role", "alertdialog");
      warningEl.setAttribute("aria-live", "assertive");
      // Styled inline: this has to look the same on all ten portal pages, and they do not
      // share a stylesheet beyond css/styles.css which not all of them load.
      warningEl.style.cssText =
        "position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:9999;" +
        "display:flex;align-items:center;gap:14px;max-width:min(440px,92vw);padding:14px 18px;" +
        "border-radius:10px;background:#fff;border:1px solid #e5e7eb;color:#253141;" +
        "box-shadow:0 18px 40px rgba(15,23,42,.18);font-family:'Segoe UI',Tahoma,sans-serif;font-size:14px";

      const text = document.createElement("span");
      text.style.cssText = "flex:1;line-height:1.45";

      const button = document.createElement("button");
      button.type = "button";
      button.textContent = "Stay signed in";
      button.style.cssText =
        "flex-shrink:0;border:0;border-radius:6px;padding:9px 14px;background:#00932e;" +
        "color:#fff;font-weight:800;font-size:13px;cursor:pointer";
      button.addEventListener("click", () => {
        markActivity();
        keepServerSessionAlive(true);
        hideIdleWarning();
      });

      warningEl.append(text, button);
      document.body.appendChild(warningEl);
    }
    warningEl.firstChild.textContent = `You will be signed out in ${secondsLeft}s due to inactivity.`;
  }

  function keepServerSessionAlive(force) {
    if (!force && Date.now() - lastKeepAlive < KEEPALIVE_MS) return;
    lastKeepAlive = Date.now();
    // Any authenticated GET slides the server's window forward. members/me is the cheapest
    // one and works for admins too - it just answers with an empty object when the account
    // has no member profile.
    api("/api/members/me").catch(() => {});
  }

  function stopIdleWatch() {
    if (!idleTimer) return;
    clearInterval(idleTimer);
    idleTimer = null;
    ACTIVITY_EVENTS.forEach((type) => window.removeEventListener(type, markActivity));
  }

  function endSessionForInactivity() {
    stopIdleWatch();
    hideIdleWarning();
    logout({ timedOut: true });
  }

  function startIdleWatch() {
    // Local preview hands itself a session in ensureLocalPreviewSession(), so timing one
    // out would just loop; there is no server session to protect there anyway.
    if (idleTimer || isLocalPreview()) return;
    markActivity();
    ACTIVITY_EVENTS.forEach((type) => window.addEventListener(type, markActivity, { passive: true }));
    idleTimer = setInterval(() => {
      const idleFor = Date.now() - lastActivityAt();
      if (idleFor >= IDLE_TIMEOUT_MS) {
        endSessionForInactivity();
        return;
      }
      if (idleFor >= IDLE_TIMEOUT_MS - IDLE_WARNING_MS) {
        showIdleWarning(Math.max(1, Math.ceil((IDLE_TIMEOUT_MS - idleFor) / 1000)));
        return;
      }
      hideIdleWarning();
      keepServerSessionAlive(false);
    }, IDLE_TICK_MS);
  }

  window.ResokPortal = {
    api,
    apiUrl,
    getState,
    saveState,
    registerMember,
    login,
    logout,
    refreshFromApi,
    updateProfile,
    uploadProfileImage,
    uploadPaymentProof,
    paymentInstructions,
    initiateStkPush,
    paymentStatus,
    pollPaymentStatus,
    cpdLedger,
    listEvents,
    registerForEvent,
    requireAuthForProtectedPage,
    startIdleWatch,
    stopIdleWatch,
    hydrateShell,
    memberName,
    initials,
    avatarUrl,
    membershipCardImageAssets,
    membershipCardSvg,
    downloadMembershipCard,
    feeStatementSvg,
    receiptSvg,
    downloadFeeStatement,
    downloadReceipt
  };

  // Start the watch on any portal page that already believes it has a session. Protected
  // pages also call startIdleWatch() from requireAuthForProtectedPage() once the server has
  // confirmed the session; this covers the rest (admin-review, certificates) without each
  // page having to opt in. It is a no-op if already running.
  function autoStartIdleWatch() {
    if (["login", "forgot-password"].includes(pageName())) return;
    if (!getState().loggedIn) return;
    startIdleWatch();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", autoStartIdleWatch);
  } else {
    autoStartIdleWatch();
  }
})();
