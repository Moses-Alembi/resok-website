const nodemailer = require("nodemailer");

function createTransport() {
  if (!process.env.SMTP_HOST) return null;

  return nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: Number(process.env.SMTP_PORT || 587),
    secure: false,
    auth: process.env.SMTP_USER
      ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS }
      : undefined
  });
}

async function sendMail({ to, subject, text, html, attachments, cc, bcc, replyTo }) {
  const transport = createTransport();
  if (!transport) return { skipped: true };

  const info = await transport.sendMail({
    from: process.env.MAIL_FROM || "no-reply@resok.org",
    to,
    subject,
    text,
    html,
    attachments,
    cc,
    bcc,
    replyTo
  });
  return { messageId: info.messageId };
}

function verificationUrl(token) {
  const baseUrl = process.env.FRONTEND_URL || "https://www.resok.org/resok-portal/public";
  return `${baseUrl}/api/auth/verify/${token}`;
}

function portalUrl() {
  return process.env.FRONTEND_URL || "https://www.resok.org/resok-portal/public";
}

function memberName(member = {}) {
  return [member.title, member.firstName, member.middleName, member.surname]
    .filter(Boolean)
    .join(" ")
    .replace(/\s+/g, " ")
    .trim();
}

function buildWelcomeLetterHtml(member = {}) {
  const name = memberName(member) || "ReSoK Member";
  const membershipId = member.membershipId || "Pending";
  const portal = portalUrl();

  return `<!doctype html>
<html>
  <body style="margin:0;padding:0;background:#f6f8fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
    <div style="max-width:760px;margin:0 auto;padding:32px 20px;">
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:32px 28px;box-shadow:0 10px 28px rgba(15,23,42,.08);">
        <p style="margin:0 0 18px;color:#00932e;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Welcome to ReSoK</p>
        <h1 style="margin:0 0 18px;font-size:28px;line-height:1.15;color:#0f172a;">A welcome letter from the CEO</h1>
        <p style="margin:0 0 14px;font-size:16px;line-height:1.7;">Dear ${name},</p>
        <p style="margin:0 0 14px;font-size:16px;line-height:1.7;">On behalf of the Respiratory Society of Kenya, welcome to our community of clinicians, researchers, and respiratory health advocates.</p>
        <p style="margin:0 0 14px;font-size:16px;line-height:1.7;">Your membership journey begins here. We look forward to supporting your professional growth, CPD learning, and contribution to healthier lungs for all people in Kenya and beyond.</p>
        <p style="margin:0 0 14px;font-size:16px;line-height:1.7;">Membership ID: <strong>${membershipId}</strong></p>
        <p style="margin:0 0 18px;font-size:16px;line-height:1.7;">You can access your member portal anytime at <a href="${portal}" style="color:#bc0b22;text-decoration:none;font-weight:700;">${portal}</a>.</p>
        <p style="margin:0 0 10px;font-size:16px;line-height:1.7;">Warm regards,</p>
        <p style="margin:0;font-size:16px;line-height:1.7;"><strong>Chief Executive Officer</strong><br>Respiratory Society of Kenya</p>
      </div>
    </div>
  </body>
</html>`;
}

function buildWelcomeLetterText(member = {}) {
  const name = memberName(member) || "ReSoK Member";
  const membershipId = member.membershipId || "Pending";
  const portal = portalUrl();
  return [
    "Welcome to ReSoK",
    "",
    `Dear ${name},`,
    "",
    "On behalf of the Respiratory Society of Kenya, welcome to our community of clinicians, researchers, and respiratory health advocates.",
    "We look forward to supporting your professional growth, CPD learning, and contribution to healthier lungs for all people in Kenya and beyond.",
    "",
    `Membership ID: ${membershipId}`,
    `Portal: ${portal}`,
    "",
    "Warm regards,",
    "Chief Executive Officer",
    "Respiratory Society of Kenya"
  ].join("\n");
}

function buildMembershipCardSvg(member = {}) {
  const esc = (value) => String(value || "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[char]));
  const name = memberName(member) || "ReSoK Member";
  const membershipId = member.membershipId || "Pending";
  const category = (member.category || "Member").toUpperCase();
  const renewalDue = member.renewalDue || "Annual";
  const dateText = (() => {
    const parsed = new Date(renewalDue);
    if (!Number.isNaN(parsed.getTime())) {
      return `${String(parsed.getMonth() + 1).padStart(2, "0")}/${String(parsed.getFullYear()).slice(-2)}`;
    }
    const match = String(renewalDue).match(/(\d{4})[-/](\d{1,2})/);
    if (match) return `${String(match[2]).padStart(2, "0")}/${match[1].slice(-2)}`;
    return renewalDue;
  })();

  return `<svg xmlns="http://www.w3.org/2000/svg" width="1012" height="645" viewBox="0 0 1012 645">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0b5f2f"/>
      <stop offset="1" stop-color="#00932e"/>
    </linearGradient>
    <linearGradient id="accent" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#bc0b22"/>
      <stop offset="1" stop-color="#f04b62"/>
    </linearGradient>
  </defs>
  <rect width="1012" height="645" rx="28" fill="url(#bg)"/>
  <rect x="34" y="34" width="944" height="577" rx="22" fill="rgba(255,255,255,0.08)" stroke="rgba(255,255,255,0.22)"/>
  <rect x="58" y="58" width="180" height="54" rx="12" fill="url(#accent)"/>
  <text x="148" y="93" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="24" font-weight="800" fill="#ffffff">ReSoK</text>
  <text x="72" y="192" font-family="Segoe UI, Arial, sans-serif" font-size="28" font-weight="800" fill="#ffffff" opacity=".88">MEMBERSHIP CARD</text>
  <text x="72" y="258" font-family="Segoe UI, Arial, sans-serif" font-size="72" font-weight="900" fill="#ffffff">${esc(membershipId)}</text>
  <text x="72" y="338" font-family="Segoe UI, Arial, sans-serif" font-size="26" font-weight="700" fill="#ffffff">${esc(name.toUpperCase())}</text>
  <text x="72" y="384" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#ffffff" opacity=".9">${esc(category)}</text>
  <text x="72" y="510" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#ffffff" opacity=".85">VALID THRU</text>
  <text x="72" y="562" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="900" fill="#ffffff">${esc(dateText)}</text>
  <rect x="650" y="122" width="270" height="270" rx="24" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.25)"/>
  <text x="785" y="240" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="26" font-weight="800" fill="#ffffff">MEMBER</text>
  <text x="785" y="274" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="26" font-weight="800" fill="#ffffff">CARD</text>
  <text x="785" y="312" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="18" fill="#ffffff" opacity=".9">Respiratory Society of Kenya</text>
</svg>`;
}

async function sendVerificationEmail(email, token) {
  const url = verificationUrl(token);
  return sendMail({
    to: email,
    subject: "Verify your ReSoK membership account",
    text: `Welcome to ReSoK. Verify your account here: ${url}`,
    html: `<p>Welcome to ReSoK.</p><p><a href="${url}">Verify your account</a></p>`
  });
}

async function sendPasswordResetEmail(email, token) {
  const baseUrl = process.env.FRONTEND_URL || "https://www.resok.org/resok-portal/public";
  const url = `${baseUrl}/forgot-password?token=${token}`;
  return sendMail({
    to: email,
    subject: "Reset your ReSoK membership password",
    text: `Reset your ReSoK password here: ${url}`,
    html: `<p>Use the link below to reset your ReSoK password.</p><p><a href="${url}">Reset password</a></p>`
  });
}

async function sendMemberWelcomeEmail(member = {}, options = {}) {
  const name = memberName(member) || "ReSoK Member";
  const subject = "Welcome to ReSoK Membership";
  const welcomeLetter = buildWelcomeLetterHtml(member);
  const attachments = [
    {
      filename: "ReSoK-Welcome-Letter-from-CEO.html",
      content: welcomeLetter,
      contentType: "text/html; charset=utf-8"
    },
    {
      filename: "ReSoK-Membership-Card.svg",
      content: buildMembershipCardSvg(member),
      contentType: "image/svg+xml"
    }
  ];

  return sendMail({
    to: member.email || options.to,
    subject,
    text: `Welcome to ReSoK, ${name}. Your membership packet is attached.`,
    html: `<p>Welcome to ReSoK, ${name}.</p><p>Your membership welcome letter and membership card are attached.</p><p>Use your portal for member services and updates.</p>`,
    attachments
  });
}

async function sendAnnualMembershipEmail(member = {}, options = {}) {
  const name = memberName(member) || "ReSoK Member";
  const subject = "Your ReSoK membership renewal is complete";
  const welcomeLetter = buildWelcomeLetterHtml(member);
  const attachments = [
    {
      filename: "ReSoK-Welcome-Letter-from-CEO.html",
      content: welcomeLetter,
      contentType: "text/html; charset=utf-8"
    },
    {
      filename: "ReSoK-Membership-Card.svg",
      content: buildMembershipCardSvg(member),
      contentType: "image/svg+xml"
    }
  ];

  return sendMail({
    to: member.email || options.to,
    subject,
    text: `Your ReSoK annual membership payment is complete, ${name}. Your renewed membership packet is attached.`,
    html: `<p>Your ReSoK annual membership payment is complete, ${name}.</p><p>Your renewed membership packet, including your updated membership card and welcome letter, is attached.</p>`,
    attachments
  });
}

module.exports = {
  sendMail,
  sendVerificationEmail,
  sendPasswordResetEmail,
  sendMemberWelcomeEmail,
  sendAnnualMembershipEmail,
  buildMembershipCardSvg,
  buildWelcomeLetterHtml,
  buildWelcomeLetterText
};
