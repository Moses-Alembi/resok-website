# ReSoK runbooks

Short procedures for when something goes wrong with resok.org or the member portal. They are
written for whoever is on call (see [on-call.md](on-call.md)), not only for the ICT officer.

| When | Read |
|---|---|
| A deploy broke something and you need the previous version back | [rollback.md](rollback.md) |
| The site, sign-in, payments or email are not working | [incidents.md](incidents.md) |
| Who answers, and what they need access to | [on-call.md](on-call.md) |
| After a serious incident is over | [postmortem-template.md](postmortem-template.md) |
| Changing the database structure | [database-changes.md](database-changes.md) |

These files live in git with the code, so they change when the system changes. The deploy
skips `.md` files, so they are never served on the website.

Items still to fill in are marked **[TO FILL]**. The Secretariat has to decide them; they
are not technical choices.

Last reviewed: 30 Oct 2026.
