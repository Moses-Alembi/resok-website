# ReSoK Security Guard Report

## Summary

- high: 54
- medium: 9
- low: 1
- info: 1
- pass: 33

## Findings

### [HIGH] Command execution
- Location: `resok-portal/public/api/cron/renewal-reminders.php:36`
- Issue: Dangerous execution sink found.
- Recommendation: Avoid command/eval execution; if unavoidable, use strict allowlists and argument arrays.

### [HIGH] Command execution
- Location: `resok-portal/public/api/index.php:319`
- Issue: Dangerous execution sink found.
- Recommendation: Avoid command/eval execution; if unavoidable, use strict allowlists and argument arrays.

### [HIGH] Command execution
- Location: `resok-portal/public/api/index.php:369`
- Issue: Dangerous execution sink found.
- Recommendation: Avoid command/eval execution; if unavoidable, use strict allowlists and argument arrays.

### [HIGH] Command execution
- Location: `resok-portal/public/api/index.php:384`
- Issue: Dangerous execution sink found.
- Recommendation: Avoid command/eval execution; if unavoidable, use strict allowlists and argument arrays.

### [HIGH] Command execution
- Location: `resok-portal/public/api/index.php:400`
- Issue: Dangerous execution sink found.
- Recommendation: Avoid command/eval execution; if unavoidable, use strict allowlists and argument arrays.

### [HIGH] SQL injection
- Location: `resok-portal/public/api/index.php:845`
- Issue: Dynamic SQL construction detected.
- Recommendation: Use parameterized queries/placeholders and keep user input out of SQL strings.

### [HIGH] SQL injection
- Location: `resok-portal/public/api/index.php:846`
- Issue: Dynamic SQL construction detected.
- Recommendation: Use parameterized queries/placeholders and keep user input out of SQL strings.

### [HIGH] SQL injection
- Location: `resok-portal/public/api/index.php:892`
- Issue: Dynamic SQL construction detected.
- Recommendation: Use parameterized queries/placeholders and keep user input out of SQL strings.

### [HIGH] SQL injection
- Location: `resok-portal/public/api/lib/blog.php:254`
- Issue: Dynamic SQL construction detected.
- Recommendation: Use parameterized queries/placeholders and keep user input out of SQL strings.

### [HIGH] SQL injection
- Location: `resok-portal/server/controllers/documentController.js:116`
- Issue: Dynamic SQL construction detected.
- Recommendation: Use parameterized queries/placeholders and keep user input out of SQL strings.

### [HIGH] SQL injection
- Location: `resok-portal/server/controllers/memberController.js:66`
- Issue: Dynamic SQL construction detected.
- Recommendation: Use parameterized queries/placeholders and keep user input out of SQL strings.

### [HIGH] SQL injection
- Location: `resok-portal/server/scripts/setupDatabase.js:41`
- Issue: Dynamic SQL construction detected.
- Recommendation: Use parameterized queries/placeholders and keep user input out of SQL strings.

### [HIGH] XSS
- Location: `assets/js/members-only.js:108`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `assets/js/site-forms.js:64`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `assets/js/site-forms.js:90`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `contact.html:3899`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `contact.html:3917`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `index.html:6106`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `index.html:6124`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:298`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:376`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:380`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:439`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:442`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:452`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:459`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:469`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/admin-review.html:476`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/card.html:45`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/dashboard.html:108`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/dashboard.html:118`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/dashboard.html:122`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/dashboard.html:133`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/dashboard.html:150`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/dashboard.html:157`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/events.html:70`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/events.html:73`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/events.html:102`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/financials.html:234`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/js/cropper.js:74`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/login.html:267`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:159`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:191`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:204`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:223`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:255`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:274`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:285`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:293`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/payment.html:311`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/profile.html:132`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `resok-portal/public/profile.html:141`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `sponsors.html:3532`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [HIGH] XSS
- Location: `sponsors.html:3550`
- Issue: Potential unsafe HTML insertion.
- Recommendation: Use textContent for untrusted content or sanitize before inserting HTML.

### [MEDIUM] Debug
- Location: `resok-portal/server/config/database.js:21`
- Issue: Debug output may leak sensitive runtime data.
- Recommendation: Disable debug output in production and log sensitive events server-side only.

### [MEDIUM] Debug
- Location: `resok-portal/server/controllers/authController.js:195`
- Issue: Debug output may leak sensitive runtime data.
- Recommendation: Disable debug output in production and log sensitive events server-side only.

### [MEDIUM] Debug
- Location: `resok-portal/server/scripts/setupDatabase.js:49`
- Issue: Debug output may leak sensitive runtime data.
- Recommendation: Disable debug output in production and log sensitive events server-side only.

### [MEDIUM] Debug
- Location: `resok-portal/server/scripts/setupDatabase.js:51`
- Issue: Debug output may leak sensitive runtime data.
- Recommendation: Disable debug output in production and log sensitive events server-side only.

### [MEDIUM] Debug
- Location: `resok-portal/server/server.js:59`
- Issue: Debug output may leak sensitive runtime data.
- Recommendation: Disable debug output in production and log sensitive events server-side only.

### [MEDIUM] Debug
- Location: `resok-portal/server/server.js:60`
- Issue: Debug output may leak sensitive runtime data.
- Recommendation: Disable debug output in production and log sensitive events server-side only.

### [MEDIUM] File upload protection
- Location: `resok-portal/server/middleware/upload.js:1`
- Issue: Upload filenames use timestamp/random number, not cryptographic randomness.
- Recommendation: Use crypto.randomBytes for generated upload filenames.

### [MEDIUM] HTTPS enforcement
- Location: `resok-portal/server/server.js:1`
- Issue: Could not confirm application-level HTTPS redirect/HSTS enforcement.
- Recommendation: Enforce HTTPS at the proxy/server and send HSTS in production.

### [MEDIUM] Security headers
- Location: `resok-portal/server/server.js:1`
- Issue: Helmet CSP is currently disabled.
- Recommendation: Enable a Content-Security-Policy once inline scripts/styles are reduced or nonce-based.

### [LOW] Security headers
- Location: `resok-portal/public/api/index.php:1`
- Issue: PHP API does not send Content-Security-Policy.
- Recommendation: Add CSP for HTML responses; API JSON responses are lower risk.

### [INFO] Dependency scanning
- Location: `resok-portal/package.json:1`
- Issue: Dependency audit skipped by --skip-deps.
- Recommendation: Run npm audit before deployment when network access is available.

### [PASS] Authentication
- Location: `resok-portal/server/controllers/authController.js:1`
- Issue: Password hashing uses bcrypt with cost 12.
- Recommendation: Keep cost reviewed as server capacity changes.

### [PASS] Authentication
- Location: `resok-portal/server/controllers/authController.js:1`
- Issue: Login avoids revealing whether an email exists.
- Recommendation: Keep generic login errors.

### [PASS] Authentication
- Location: `resok-portal/server/controllers/authController.js:1`
- Issue: Email verification is enforced in login flow.
- Recommendation: Require verification in production.

### [PASS] Authentication
- Location: `resok-portal/server/controllers/authController.js:1`
- Issue: JWT expiration is configured.
- Recommendation: Use short lifetimes for high-risk roles.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: DB_HOST is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: DB_USER is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: DB_NAME is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: JWT_SECRET is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: FRONTEND_URL is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: REQUIRE_EMAIL_VERIFICATION is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: SMTP_HOST is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment variables
- Location: `resok-portal/server/config/env.js:1`
- Issue: MAIL_FROM is referenced by server env validation.
- Recommendation: Set this in production environment only.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents db_host.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents db_name.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents db_user.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents db_pass.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents jwt_secret.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents upload_dir.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents max_file_size.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents portal_base_url.
- Recommendation: Use production values outside version control.

### [PASS] Environment/config
- Location: `resok-portal/public/api/config.sample.php:1`
- Issue: PHP config documents mail_from.
- Recommendation: Use production values outside version control.

### [PASS] File upload protection
- Location: `resok-portal/public/api/index.php:1`
- Issue: PHP upload handling verifies MIME with finfo.
- Recommendation: Keep MIME validation server-side.

### [PASS] File upload protection
- Location: `resok-portal/server/middleware/upload.js:1`
- Issue: Upload size limit is configured.
- Recommendation: Keep allowlists narrow and store uploads outside the public web root.

### [PASS] File upload protection
- Location: `resok-portal/server/middleware/upload.js:1`
- Issue: Upload MIME allowlist is configured.
- Recommendation: Keep allowlists narrow and store uploads outside the public web root.

### [PASS] File upload protection
- Location: `resok-portal/server/middleware/upload.js:1`
- Issue: Upload extension allowlist is configured.
- Recommendation: Keep allowlists narrow and store uploads outside the public web root.

### [PASS] File upload protection
- Location: `resok-portal/server/middleware/upload.js:1`
- Issue: Upload subdirectory names are normalized.
- Recommendation: Keep allowlists narrow and store uploads outside the public web root.

### [PASS] HTTPS/proxy
- Location: `resok-portal/server/server.js:1`
- Issue: Trust proxy is configured for reverse proxy deployments.
- Recommendation: Set TRUST_PROXY correctly on production hosting.

### [PASS] Rate limiting
- Location: `resok-portal/server/server.js:1`
- Issue: API rate limiting is enabled.
- Recommendation: Add stricter limits around login/password reset endpoints.

### [PASS] Security headers
- Location: `resok-portal/public/api/index.php:1`
- Issue: PHP API sends X-Content-Type-Options.
- Recommendation: Keep API security headers enabled.

### [PASS] Security headers
- Location: `resok-portal/public/api/index.php:1`
- Issue: PHP API sends X-Frame-Options.
- Recommendation: Keep API security headers enabled.

### [PASS] Security headers
- Location: `resok-portal/public/api/index.php:1`
- Issue: PHP API sends Referrer-Policy.
- Recommendation: Keep API security headers enabled.

### [PASS] Security headers
- Location: `resok-portal/server/server.js:1`
- Issue: Helmet middleware is enabled.
- Recommendation: Keep CSP tuned for production.

### [PASS] Security headers
- Location: `resok-portal/server/server.js:1`
- Issue: Express x-powered-by header is disabled.
- Recommendation: Keep implementation details hidden.
