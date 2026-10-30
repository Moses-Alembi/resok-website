# On call

ReSoK has one ICT officer. On-call here doesn't mean a rota with pagers. It means that when
the ICT officer is unavailable (travelling, ill, on leave), one named person can still get
the site back, and knows they're the one expected to.

## Who

| Role | Person | Covers |
|---|---|---|
| Primary | ICT officer: **[TO FILL]** | Normal working time, and level 1 incidents at any time |
| Backup | **[TO FILL: named by the Secretariat]** | When the primary hasn't answered within 1 hour on a level 1, or is known to be away |

The backup doesn't need to be a developer. They need to be able to follow
[rollback.md](rollback.md) and [incidents.md](incidents.md), and to know when to call
GigaHost.

## How you'll hear about a problem

- **The security email every 8 hours** (cron/security-digest.php). It is sent even when
  everything is fine, so **a missing email is itself a warning**: the server or its cron has
  stopped.
- **An uptime monitor**: **[TO FILL once set up: e.g. UptimeRobot checking the health address
  every 5 minutes, alerting the primary and backup by email and SMS]**.
- **Members and staff**, by email or phone to the Secretariat.

## What the backup needs before they're ever called

Set this up in advance. An outage is a bad time to find out a password is missing.

- [ ] cPanel login (GigaHost), through the password manager's shared or emergency access
- [ ] Cloudflare: added as a member of the resok.org account
- [ ] GitHub: access to the repository (only needed for a git rollback)
- [ ] An admin account on the portal, with two-factor set up
- [ ] GigaHost support contact, and the account details they'll ask for
- [ ] A copy of these runbooks they've read once, ideally with one practice rollback

## Hand-over when the primary is away

Before leaving, the primary tells the backup:

- the dates they'll be away
- anything recently deployed or due to be deployed (preferably: nothing new in the last two
  days before leaving)
- any open incident or known weak spot

## Expectations

- Respond within the times in [incidents.md](incidents.md). "Respond" means acknowledge and
  start, not fix.
- For a level 1 the backup can't fix, the right moves are: roll back the latest deploy, turn
  on Cloudflare's **Under Attack** mode if it looks like an attack, and contact GigaHost. Then
  keep the log.
- Nobody is expected to be reachable around the clock for level 2 and 3 incidents.
