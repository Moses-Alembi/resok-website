# Staff leave

Employees use `staff#leave` to request leave and track its status. Super administrators
use `staff#leave-manage` to decide requests, see the register and configure leave types.
The welfare-election-only menu restriction has been removed; employees can also open
their appraisals, timesheets, payslips and other existing personal staff tools.

Before the first request, the super administrator adds the organisation's actual leave
types under **Leave types**. Names and optional guidance are configurable. Nothing seeds
an annual entitlement, balance, public-holiday rule or payroll deduction. Leave dates are
recorded as requested, without calculating allowances. Deactivate a type to stop new requests;
existing requests retain the type name they were submitted under.

Employees see only their own requests and notes. They can submit valid dates from today
onward and cancel pending or approved leave that has not ended. Overlapping pending or
approved requests are refused, including concurrent submissions. Dates and ownership are
validated by the server, independently of the browser controls.

Only a verified super administrator approves or declines requests for now. A decline needs
a reason; cancelling another employee's leave also needs a reason. Approved and declined
requests cannot be decided twice. Cancellation retains the original approver and decision.
Portal notifications report submissions and decisions, and changes are audited. No emails
are sent by this module. HR delegation remains a later decision.

The module creates `staff_leave_types` and `staff_leave_requests` on first use, following
the existing staff modules' table initialization pattern. Production deployment must include
`public/api/lib/staff-leave.php`, `public/js/staff-leave.js`, the staff page, the API dispatcher
in `staff-portal.php`, and the updated staff menu in `elections.html`.

Run `php tools/dev/test-staff-admin.php` against the separate disposable MariaDB instance
on port 33317. All accounts and requests in that test are synthetic; its random database
is removed in a `finally` block.

`node tools/dev/test-staff-navigation.cjs` checks employee navigation, management guards
and the Leave UI using an in-memory DOM. It does not replace a visual browser check.
