(function () {
  const STORAGE_KEY = "resok_portal_state";
  const TOKEN_KEY = "token";
  const CARD_ASSET_VERSION = "20260524-gold-bg";
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
    if (!response.ok) throw new Error(data?.error || data?.message || "Request failed");
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

  function logout() {
    api("/api/auth/logout", { method: "POST" }).catch(() => {});
    localStorage.removeItem(TOKEN_KEY);
    const state = getState();
    state.loggedIn = false;
    state.token = "";
    saveState(state);
    window.location.href = "login";
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
    const page = (window.location.pathname.split("/").pop() || "index").replace(/\.html$/i, "");
    const protectedPages = new Set(["dashboard", "profile", "payment", "card", "financials", "events"]);
    if (!protectedPages.has(page)) return true;
    if (isLocalPreview()) {
      ensureLocalPreviewSession();
      return true;
    }
    try {
      await api("/api/members/me");
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

  async function membershipCardImageAssets(member = getState().member) {
    const logo = await imageToDataUrl("assets/img/logo.png").catch(() => absoluteAssetUrl("assets/img/logo.png"));
    const background = await imageToDataUrl(CARD_BACKGROUND_PATH).catch(() => absoluteAssetUrl(CARD_BACKGROUND_PATH));
    const photo = member.profileImageUrl
      ? await imageToDataUrl(member.profileImageUrl).catch(() => member.profileImageUrl)
      : "";
    return { logo, background, photo };
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

  function membershipCardSvg(member = getState().member, assets = {}) {
    const esc = (value) => String(value || "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[char]));
    const name = memberName(member) || "ReSoK Member";
    const membershipId = member.membershipId || "Pending";
    const category = (member.category || "Member").toUpperCase();
    const renewalDue = member.renewalDue || "Annual";
    const validThru = validThruLabel(renewalDue);
    const backgroundSrc = assets.background || absoluteAssetUrl(CARD_BACKGROUND_PATH);
    const photoSrc = assets.photo || member.profileImageUrl || "";
    return `<svg xmlns="http://www.w3.org/2000/svg" width="1012" height="645" viewBox="0 0 1012 645">
  <defs>
    <clipPath id="memberPhotoClip">
      <path d="M960 133H740C663 133 602 199 602 292C602 385 663 477 740 477H960Z"/>
    </clipPath>
    <linearGradient id="fieldBlend" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0" stop-color="#e8c75c" stop-opacity=".72"/>
      <stop offset=".55" stop-color="#caa13a" stop-opacity=".56"/>
      <stop offset="1" stop-color="#7d6a2c" stop-opacity=".5"/>
    </linearGradient>
    <filter id="softShadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="2" dy="2" stdDeviation="1.2" flood-color="#111827" flood-opacity=".55"/>
    </filter>
  </defs>
  <image href="${esc(backgroundSrc)}" x="0" y="0" width="1012" height="645" preserveAspectRatio="xMidYMid slice"/>
  <rect x="18" y="250" width="560" height="152" rx="10" fill="url(#fieldBlend)" opacity=".95"/>
  <rect x="34" y="548" width="596" height="66" rx="8" fill="#7c6a2b" opacity=".42"/>
  <rect x="642" y="548" width="236" height="66" rx="8" fill="#7c6a2b" opacity=".38"/>
  <rect x="602" y="133" width="358" height="344" fill="#6c5626" opacity=".18"/>
  ${photoSrc ? `<image href="${esc(photoSrc)}" x="602" y="133" width="358" height="344" preserveAspectRatio="xMidYMid slice" clip-path="url(#memberPhotoClip)"/>` : `<path d="M960 133H740C663 133 602 199 602 292C602 385 663 477 740 477H960Z" fill="#d8c481" opacity=".78"/>
  <text x="790" y="292" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="28" font-weight="800" fill="#ffffff">UPLOAD</text>
  <text x="790" y="328" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="28" font-weight="800" fill="#ffffff">PHOTO</text>`}
  <text x="26" y="292" font-family="Krdit, Credit, 'Credit Card', 'Courier New', Consolas, monospace" font-size="38" font-weight="800" letter-spacing="3" fill="#ffffff">${esc(category)}</text>
  <text x="24" y="384" font-family="Krdit, Credit, 'Credit Card', 'Courier New', Consolas, monospace" font-size="76" font-weight="900" letter-spacing="4" fill="#ffffff">${esc(membershipId)}</text>
  <text x="40" y="594" font-family="Krdit, Credit, 'Credit Card', 'Courier New', Consolas, monospace" font-size="50" font-weight="300" letter-spacing="10" fill="#ffffff">${esc(name.toUpperCase())}</text>
  <text x="652" y="571" font-family="Segoe UI, Arial, sans-serif" font-size="17" font-weight="700" fill="#ffffff" opacity=".88">VALID</text>
  <text x="652" y="591" font-family="Segoe UI, Arial, sans-serif" font-size="17" font-weight="700" fill="#ffffff" opacity=".88">THRU</text>
  <text x="718" y="591" font-family="Krdit, Credit, 'Credit Card', 'Courier New', Consolas, monospace" font-size="48" font-weight="900" letter-spacing="6" fill="#e5e7eb" stroke="#374151" stroke-width="1.1" filter="url(#softShadow)">${esc(validThru)}</text>
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
})();
