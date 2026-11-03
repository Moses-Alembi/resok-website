# Staff administration

Open `resok-portal/public/staff#administration` to manage the existing staff tools.
The **Manage staff portal** link appears in the sidebar and on the overview for verified
super administrators. It links to leave requests, staff records and accounts, departments, announcements,
timesheet approvals and reports, project codes, field reports, payroll, purchase requests,
suppliers, appraisal cycles, access rules, welfare elections, ICT, permissions and activity.

Super administrators receive every staff capability automatically, including when they
do not have an employee record. Verification still requires the server's admin role and
super-admin email configuration. Individual staff grants cannot reduce that access.
The Permissions screen shows those accounts with every capability checked and the label
**Full access automatically**, instead of offering ineffective staff-grant changes.

No HR roles or staff grants are assigned by this release. Active employees can use their
own leave, appraisals, timesheets, purchase requests, payslips, field work, profile, directory,
announcements, equipment and support tools. The former election-only launch lock is removed.
Management links and endpoints remain restricted to the appropriate server permissions;
opening employee tools does not grant payroll, staff management or approval rights.
Leave approval and type settings are restricted to super administrators for now, as requested.
Workflow rules, such as approval stages and locked records, still apply. Documents and policies,
tasks and projects, and the calendar remain planned sections.

Job titles are shown below employee names in the staff header, Staff records and the ICT
staff-account list. Super administrators keep their administrator caption in the header.
Titles are profile data, independent of roles and staff permissions.

For a name/designation sheet, open **Staff records → Load HR list** and keep **Update job
titles only** selected. Paste the serial number, name and title columns, check the matched
accounts, omit anyone whose record should stay unchanged, then apply. A printed SN is not
an employee number. This mode changes only `job_title`: it preserves names, employee numbers,
counties, account activation, roles and election eligibility. Weak matches are left unselected.
It does not create missing accounts. Turning title-only mode off retains the full HR import
for sheets that contain actual employee numbers and intentional county updates.
Keep named roster sheets and comparison exports outside the public source repository.

Module scripts register before initial navigation so links to management tabs also work
when opened directly or refreshed. Asset versions on the staff page must be bumped when
changing its scripts or stylesheet because production assets use an immutable cache.

`php tools/dev/test-staff-admin.php` exercises the management endpoints using synthetic
accounts on the separate disposable MariaDB instance on `127.0.0.1:33317`, using the same
test credentials as `test-welfare.php`. It creates and drops its own randomly named
`resok_staff_admin_test_*` database and never loads the portal's normal configuration.
It checks automatic super-admin access, management without an employee record, effective
permission display, employee access to their own tools, management restrictions, leave
privacy and concurrent submissions, editing records and settings, and auditing.
