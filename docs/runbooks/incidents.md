# Incidents

An incident is anything that stops members or staff using the site as they should: the site
down, sign-in failing, payments not recording, emails not arriving, or member data seen by
someone who shouldn't see it.

## How serious is it?

| Level | Examples | Respond within |
|---|---|---|
| **1 – Critical** | Site or portal down for everyone. Member data exposed or suspected exposed. Someone else controls an admin account. | 1 hour, any time |
| **2 – Major** | Sign-in, payments, voting or all email broken, but the site loads. | 4 hours in working hours |
| **3 – Minor** | One page or feature wrong, a workaround exists, few people affected. | Next working day |

If unsure, treat it as one level higher. It's easy to lower later.

## First 30 minutes

1. **Write down the time** and what was reported, word for word.
2. **Confirm it yourself.** Open the site in a private window. Check the health address:
   `https://www.resok.org/resok-portal/public/api/index.php?route=health`
3. **Work out where the fault is:**
   - **Every page fails**: hosting or Cloudflare. Check the Cloudflare dashboard (is the
     zone active? any alerts?) and cPanel (can you log in?). If cPanel is down too, contact
     GigaHost.
   - **Pages load but sign-in fails**: the database or the API. In cPanel → phpMyAdmin,
     check the database opens. Look for a "Too many attempts" lockout, or the Kenya-only
     staff sign-in rule refusing someone abroad.
   - **Email not arriving**: cPanel → Email → **Track Delivery** for that address. It gives
     the mail server's own reason. Check it before guessing.
   - **It started right after a deploy**: roll back ([rollback.md](rollback.md)), then
     investigate.
4. **Level 1 or 2: tell people.** Tell the Secretariat. If members are affected for more than
   an hour, put a short notice on the site's social channels.
5. **Start a log entry** (below) and keep adding to it as you go.

## Suspected data breach

Do these as well as the steps above:

- **Contain it.** Disable the affected account (ICT → Staff & access), sign out its sessions,
  and change the passwords involved.
- **Keep the evidence.** Don't delete logs or accounts. In the security log (Admin → Threat
  Assessment), note the events with their times.
- **Tell the Secretariat straight away.** Under Kenya's Data Protection Act 2019, a breach
  that risks members' rights must be reported to the Office of the Data Protection
  Commissioner within **72 hours** of becoming aware of it. Members affected must also be told
  when the risk to them is high. Deciding and reporting is the Secretariat's / Data
  Protection Officer's job: **[TO FILL: DPO name]**.

## The incident log

Keep one log for all incidents, including small ones. Patterns show up over time.

Where it's kept: **[TO FILL: e.g. a shared spreadsheet in the ReSoK Google Drive]**. Don't
keep it in this repository: entries may name members.

One row per incident:

| Field | What to write |
|---|---|
| Number | INC-2026-001, INC-2026-002, … |
| Started | Date and time it began (or first noticed) |
| Noticed by | Who reported it, and how |
| Level | 1, 2 or 3 |
| What people saw | In plain words, e.g. "members could not sign in" |
| Cause | Once known |
| What fixed it | e.g. "rolled back commit abc123" |
| Resolved | Date and time |
| Duration | Resolved minus Started |
| Postmortem? | Yes for every level 1, and for level 2 if it lasted over 4 hours |

## Contacts

| Who | For | How |
|---|---|---|
| ICT officer (primary on call) | Everything technical | **[TO FILL]** |
| Backup on call | When the primary can't be reached | **[TO FILL]** |
| GigaHost Africa support | Server, cPanel, email server | **[TO FILL: ticket address / phone]** |
| Cloudflare account holder | DNS, firewall, "Under Attack" mode | moses.alembi@resok.org |
| Domain account holder | resok.org registration (Afriregister via GigaHost) | **[TO FILL: still being handed over]** |
| Secretariat | Member communication, breach reporting | **[TO FILL]** |
