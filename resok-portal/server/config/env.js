const path = require("path");
require("dotenv").config({ path: path.join(__dirname, "../.env") });

const weakSecrets = new Set(["change_me", "change_this_to_a_long_random_secret"]);

function required(name) {
  const value = process.env[name];
  if (!value || !String(value).trim()) {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

function isProduction() {
  return process.env.NODE_ENV === "production";
}

function mpesaConfigured() {
  return Boolean(
    process.env.MPESA_CONSUMER_KEY &&
      process.env.MPESA_CONSUMER_SECRET &&
      process.env.MPESA_SHORTCODE &&
      process.env.MPESA_PASSKEY &&
      process.env.MPESA_CALLBACK_URL
  );
}

function smtpConfigured() {
  return Boolean(process.env.SMTP_HOST && process.env.MAIL_FROM);
}

function validateEnv() {
  required("DB_HOST");
  required("DB_USER");
  required("DB_NAME");
  required("JWT_SECRET");

  if (weakSecrets.has(process.env.JWT_SECRET) || String(process.env.JWT_SECRET).length < 32) {
    throw new Error("JWT_SECRET must be a strong random value of at least 32 characters.");
  }

  if (!isProduction()) return;

  required("FRONTEND_URL");
  if (process.env.REQUIRE_EMAIL_VERIFICATION !== "true") {
    throw new Error("Set REQUIRE_EMAIL_VERIFICATION=true in production.");
  }
  if (!smtpConfigured()) {
    throw new Error("SMTP_HOST and MAIL_FROM are required in production.");
  }
  if (!mpesaConfigured() && process.env.ALLOW_OFFLINE_PAYMENTS !== "true") {
    throw new Error("Configure M-Pesa credentials or explicitly set ALLOW_OFFLINE_PAYMENTS=true.");
  }
}

module.exports = {
  isProduction,
  mpesaConfigured,
  smtpConfigured,
  validateEnv,
  weakSecrets
};
