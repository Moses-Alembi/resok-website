# ReSoK Members Portal

This portal is configured to use the PHP backend in `public/api/index.php`.

## Production Setup

1. Create the database with `server/schema.sql`.
2. Configure runtime secrets with environment variables, or create `public/api/config.local.php` from `public/api/config.sample.php`.
3. Required values:
   - `RESOK_DB_NAME`
   - `RESOK_DB_USER`
   - `RESOK_DB_PASS`
   - `RESOK_JWT_SECRET` with at least 32 random characters
   - `RESOK_PAYBILL_NUMBER`
   - `RESOK_PORTAL_BASE_URL` such as `https://www.resok.org/resok-portal/public`
   - `RESOK_MAIL_FROM` for password reset emails
4. Make sure `resok-portal/uploads` is writable by PHP.
5. Keep `public/api/config.local.php` out of version control.

## Admin Access

- Admins log in from the same `public/login.html` page.
- Admin users are redirected to `public/admin-review.html`.
- To create the first admin, temporarily set `setup_key` or `RESOK_SETUP_KEY`, then send a POST request to `public/api/index.php?route=setup/admin`.
- Include the setup key in the `X-Setup-Key` header and send JSON:
  `{"email":"admin@example.com","password":"StrongPassword123"}`
- Remove the setup key after the admin account is created.

## Active User Flow

- Register or log in.
- Maintain profile details.
- Pay using the configured M-Pesa Paybill instructions.
- Upload payment proof from `payment.html`.
- Admin reviews registered members and payment proof from `admin-review.html`.
- Download the membership card from `dashboard.html` or `card.html` after admin approval issues the membership ID.

Document upload is intentionally not part of the current production flow.
