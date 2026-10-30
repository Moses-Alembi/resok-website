# Rolling back a deploy

Use this when a deploy has made something worse and the cause isn't obvious within about 15
minutes. Going back first and investigating afterwards is almost always quicker.

## How a deploy works (so you know what you're undoing)

1. Changes are pushed to `master` on GitHub.
2. In cPanel → **Git Version Control**, someone clicks **Update from Remote**, then **Deploy
   HEAD Commit**.
3. `.cpanel.yml` copies the files into `public_html` with rsync.

Three things are **not** undone by putting old code back:

- **Files the bad deploy added.** The deploy never deletes anything (on purpose, so uploads
  and secrets survive), so a new file stays until someone removes it.
- **Database changes.** If an SQL file was applied on the Threat Assessment page, the old code
  runs against the new structure. That is normally fine, because changes only add tables and
  columns. See [database-changes.md](database-changes.md).
- **`config.local.php`.** It isn't in git. Before editing it, always download a copy named with
  the date, so you can put it back.

## Option A: revert in git (normal route)

On the laptop, in the `cpd-admin` folder:

```bash
git log --oneline -10            # find the bad commit
git revert <commit-id>           # makes a new commit that undoes it
git push origin master
```

Then in cPanel → Git Version Control, click **Update from Remote**, then **Deploy HEAD Commit**.

- `git revert` doesn't rewrite history. The bad change stays on record and can be fixed and
  redeployed later.
- If several commits went out together, revert them newest first.

## Option B: replace one file by hand (no laptop available)

1. On github.com, open the file and click **History**.
2. Open the last good version and click **Raw**. Save the file.
3. In cPanel → **File Manager**, go to the same path under `public_html` and upload it over the
   current copy.

The next Deploy HEAD Commit will overwrite this with whatever is on `master`. Make sure
Option A happens afterwards, or the bad version comes back.

## After rolling back

- **Check from outside, past the cache.** Add a throwaway query string, for example
  `https://www.resok.org/resok-portal/public/login?nocache=839`. The host's nginx cache keeps
  JS and CSS for a year under each `?v=` address. If a page still behaves badly, change the
  `?v=` value on its scripts (a new value nobody has requested) and deploy again.
- **Check the health address.** It should say `{"status":"OK"}`:
  `https://www.resok.org/resok-portal/public/api/index.php?route=health`
- **Sign in as a test member and as an admin.** Most breakages show there first.
- **Record it** in the incident log ([incidents.md](incidents.md)), even if it was quick.

## When the data itself is damaged

Putting old code back doesn't repair data. If records were wrongly changed or deleted, restore
from a backup into a **separate** database first, and copy back only the affected rows. A
whole-database restore loses everything members did since the backup was taken.

Backups: **[TO FILL: where the nightly database backup is kept, and who can get it]**.
Until off-site backups exist, cPanel → **Backup** is the only copy, and it sits on the same
server.
