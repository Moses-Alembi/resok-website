# Manual CPD imports

The reusable importer is `resok-portal/public/api/cron/import-event-cpd.php`.
Its code can be deployed from GitHub. Attendee records and unredeemed CPD tokens
belong in a separate JSON payload, uploaded manually to `cpd-imports/` alongside
the web root. The payload stays outside the website and Git repository.

## Run on the live site

1. Deploy the importer code through the normal cPanel Git deployment.
2. Create `/home/resokorg/cpd-imports/` alongside `public_html`, then upload the
   prepared JSON payload there. The importer rejects a data folder under the
   website's document root.
3. Use the server's configured `cron_secret` and `data_encryption_key`.
   If `cron_secret` is unset, configure a random secret of at least 32 characters
   in the server's `resok-portal/public/api/config.local.php`.
4. Open `https://www.resok.org/resok-portal/public/api/cron/import-event-cpd.php`.
   Enter the dataset name, cron secret, and the actual meeting start time in
   Kenya time. Run the preview, check its event details and counts, then import.
5. Check the final counts. The attendees can collect their assigned tokens from
   the event page or their linked member dashboard.

The preview does not change database records. Rerunning the same payload reuses
the matching event and existing allocations. An import does not send emails;
attendees request their tokens through the existing collection flow.

Once the import is verified, the manually uploaded payload can be removed.
Existing allocations remain in the database. Sensitive SQL exports, payloads,
token lists, and production configuration must stay out of Git commits.
