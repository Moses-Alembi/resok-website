# Staff administration

Open `resok-portal/public/staff#administration` to manage the existing staff tools.
The **Manage staff portal** link appears in the sidebar and on the overview for verified
super administrators. It links to staff records and accounts, departments, announcements,
timesheet approvals and reports, project codes, field reports, payroll, purchase requests,
suppliers, appraisal cycles, access rules, welfare elections, ICT, permissions and activity.

Super administrators receive every staff capability automatically, including when they
do not have an employee record. Verification still requires the server's admin role and
super-admin email configuration. Individual staff grants cannot reduce that access.
The Permissions screen shows those accounts with every capability checked and the label
**Full access automatically**, instead of offering ineffective staff-grant changes.

This change does not assign HR roles or change existing staff grants. Ordinary staff retain
their current launch restrictions. Workflow rules, such as approval stages and locked
records, still apply. Leave, documents and policies, tasks and projects, and the calendar
remain planned sections, outside the existing management tools.

Module scripts register before initial navigation so links to management tabs also work
when opened directly or refreshed. Asset versions on the staff page must be bumped when
changing its scripts or stylesheet because production assets use an immutable cache.

`php tools/dev/test-staff-admin.php` exercises the management endpoints using synthetic
accounts on the separate disposable MariaDB instance on `127.0.0.1:33317`, using the same
test credentials as `test-welfare.php`. It creates and drops its own randomly named
`resok_staff_admin_test_*` database and never loads the portal's normal configuration.
It checks automatic super-admin access, management without an employee record, effective
permission display, ordinary staff restrictions, editing records and settings, and auditing.
