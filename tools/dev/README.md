# Developer tooling

## Before every upload

```bash
./tools/dev/check.sh          # php -l over every file - fast
./tools/dev/check.sh --full   # adds inline page scripts and PHPStan
```

Green means safe to upload. It has to stay green to be worth anything, so if something
here starts failing, fix the code rather than the check.

## Why this exists

The portal API has been down twice from things that are invisible without a PHP parser:

- `lib/blog.php` carried an unparenthesised nested ternary. Brackets balanced, so a
  hand-written checker passed it. PHP 8 rejects it at compile time, which took out every
  route in the API - including routes that never touched that file.
- A route called `adminLog()`, which does not exist. The real function is
  `logAdminAction()`. Nothing catches that by reading.

`php -l` catches the first outright. PHPStan catches the second, and found a live one on
its first run: `mfaEnsureColumns()` returns a bool so two-factor can fail soft, but every
call site ignored it and four then queried columns that would not exist.

`tools/security/php_lint.py` used to stand in for this when the machine had no PHP. It has
been removed - it produced false positives, and a checker you have to second-guess is worse
than none. `git log` has it if it is ever needed.

## Local stack

| | |
|---|---|
| Site | http://localhost:8081 |
| phpMyAdmin | http://localhost/phpmyadmin |
| Database | `resok_portal`, user `root`, no password |
| Config | `resok-portal/public/api/config.local.php` - gitignored, local secrets only |

Apache serves the project directory in place rather than a copy in `htdocs`, so there is
no second copy to drift. Its vhost sets `RESOK_LOCAL`, which `.htaccess` checks to skip the
force-HTTPS and canonical-domain redirects - gated on a server-set variable, never on the
Host header, which a client controls.

Apache and MySQL run as console processes and do not survive a reboot:

```bash
/c/xampp/apache/bin/httpd.exe &
/c/xampp/mysql/bin/mysqld.exe --defaults-file=/c/xampp/mysql/bin/my.ini --standalone &
```

## Schema import order

`schema.sql`, then `schema-security.sql`, `schema-events.sql`, `schema-tokens.sql`, then
`seed-past-events.sql`. Re-running any of them is safe.
