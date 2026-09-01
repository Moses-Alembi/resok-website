# ReSoK Production Upload Checklist

Upload the public site files and the PHP portal files only.

## Upload

- Top-level site files: `*.html`, `assets/`, `favicon.jpg`, `manifest.json`, `sw.js`
- Portal public files: `resok-portal/public/`
- Database schema for setup reference: `resok-portal/server/schema.sql`
- A writable server-side upload directory outside the public web root, matching `upload_dir` in `resok-portal/public/api/config.php`

## Do Not Upload

- `resok-portal/server/.env`
- `resok-portal/node_modules/`
- Any `node_modules/`
- Local test uploads in `resok-portal/uploads/`
- `resok-portal/public/api/config.local.php` unless you are intentionally deploying it with production secrets

## Before Going Live

- Create the MySQL database with `resok-portal/server/schema.sql`.
- Set production environment variables or create `public/api/config.local.php` on the server.
- Use a 32+ character random `RESOK_JWT_SECRET`.
- Set `RESOK_SETUP_KEY` only long enough to create the first admin, then remove it.
- Serve the site over HTTPS.
- Run the defensive security scanner:
  `python tools/security/security_guard.py --fail-on medium`
- If the deployment machine has no internet access, run the local checks first:
  `python tools/security/security_guard.py --skip-deps --fail-on medium`
- Review `security-report.md` and fix any critical, high, or medium findings before launch.
