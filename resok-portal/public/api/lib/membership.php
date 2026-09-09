<?php
declare(strict_types=1);

/**
 * Membership standing: the back half of the lifecycle.
 *
 * Registration, payment, review and approval already worked. What did not exist was
 * anything that happened afterwards. membership_status carried an 'expired' value that no
 * code path ever set, renewal_due was written once at approval and never again, and a member
 * who paid their renewal while still active passed through no code that moved the date. The
 * effect was a membership that never ended and a renewal that changed nothing.
 *
 * Three decisions shape this file.
 *
 * Standing is derived from the date, never read from the status column. A membership lapses
 * because a day passed, not because a job ran; if enforcement waited on a nightly cron then
 * a cron that failed would quietly extend everyone's benefits, and the failure would look
 * exactly like everything working. The column is still written - reports and admin filters
 * need something to query, and the lapse notice needs to be sent once - but nothing is
 * granted or refused on the strength of it.
 *
 * There is a grace period, and it is generous on purpose. A member whose payment is a week
 * late has not resigned; they are busy, or the paybill message went astray. Withdrawing a
 * benefit the day after a date is the kind of correctness that generates telephone calls to
 * the office. Within grace the member keeps everything and is told plainly that they are
 * overdue.
 *
 * Renewing extends from the date already held, not from today, whenever that date is still
 * in the future. Renewing a month early should not cost a month.
 */

/** How long a lapsed membership keeps its benefits while the office chases the payment. */
const MEMBERSHIP_GRACE_DAYS = 30;

/** How far ahead a renewal starts being described as due rather than distant. */
const MEMBERSHIP_DUE_SOON_DAYS = 30;

/** How long a membership runs from the day it is paid for. */
const MEMBERSHIP_TERM_MONTHS = 12;

/**
 * Whole days from today until a date, negative once it is past.
 *
 * The leading '!' resets the parsed time to midnight. Without it createFromFormat keeps the
 * current time of day, and a date three days past compares as two days and twenty-two hours
 * - which floors to two, and reports the wrong number to a member being asked for money.
 * The infrastructure module was bitten by exactly this.
 */
function membershipDaysUntil(?string $date): ?int
{
    if (!$date) return null;
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
    if (!$due) return null;
    $today = new DateTimeImmutable('today');
    return (int)$today->diff($due)->format('%r%a');
}

/**
 * What a membership's dates actually mean today.
 *
 * @param array<string,mixed> $member A member_profiles row, or the shape members/me returns.
 * @return array<string,mixed>
 */
function membershipStanding(array $member): array
{
    $status = (string)($member['membership_status'] ?? $member['membershipStatus'] ?? '');
    $due    = $member['renewal_due'] ?? $member['renewalDue'] ?? null;
    $days   = membershipDaysUntil(is_string($due) ? $due : null);

    $base = [
        'status'       => $status,
        'renewalDue'   => $due,
        'daysUntilDue' => $days,
        'graceDays'    => MEMBERSHIP_GRACE_DAYS,
        'graceEndsOn'  => null,
    ];

    // Anything that is not an approved membership is reported as itself. Standing is a
    // statement about a membership that exists; it cannot describe one still being decided.
    if ($status !== 'active' && $status !== 'expired') {
        return $base + [
            'standing'  => $status === '' ? 'none' : $status,
            'benefits'  => false,
            'band'      => $status === 'rejected' ? 'bad' : 'pending',
            'label'     => match ($status) {
                'payment_required' => 'Payment required',
                'under_review'     => 'Waiting for review',
                'rejected'         => 'Not approved',
                default            => 'No membership',
            },
        ];
    }

    // An approved membership with no renewal date. It happens to records approved before the
    // date was recorded, and it must not be read as lapsed - that would withdraw benefits
    // from long-standing members over missing data rather than an unpaid subscription.
    if ($days === null) {
        return $base + [
            'standing' => 'undated',
            'benefits' => true,
            'band'     => 'notice',
            'label'    => 'Active, with no renewal date recorded',
        ];
    }

    $graceEnd = (new DateTimeImmutable('today'))->modify('+' . ($days + MEMBERSHIP_GRACE_DAYS) . ' days');
    $base['graceEndsOn'] = $graceEnd->format('Y-m-d');

    if ($days > MEMBERSHIP_DUE_SOON_DAYS) {
        return $base + ['standing' => 'current', 'benefits' => true, 'band' => 'ok',
                        'label' => 'Active until ' . membershipDateLabel($due)];
    }
    if ($days >= 0) {
        return $base + ['standing' => 'due_soon', 'benefits' => true, 'band' => 'notice',
                        'label' => $days === 0 ? 'Renewal due today'
                                               : 'Renewal due in ' . $days . ' day' . ($days === 1 ? '' : 's')];
    }

    $overdue = abs($days);
    if ($overdue <= MEMBERSHIP_GRACE_DAYS) {
        return $base + [
            'standing'    => 'grace',
            'benefits'    => true,
            'band'        => 'warning',
            'daysOverdue' => $overdue,
            'label'       => 'Overdue by ' . $overdue . ' day' . ($overdue === 1 ? '' : 's')
                             . ' - benefits continue until ' . membershipDateLabel($base['graceEndsOn']),
        ];
    }
    return $base + [
        'standing'    => 'lapsed',
        'benefits'    => false,
        'band'        => 'bad',
        'daysOverdue' => $overdue,
        'label'       => 'Lapsed - renewal was due ' . membershipDateLabel($due),
    ];
}

function membershipDateLabel(?string $date): string
{
    if (!$date) return 'an unrecorded date';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
    return $parsed ? $parsed->format('j F Y') : (string)$date;
}

/**
 * Whether members-only benefits apply right now.
 *
 * This is the function every gate should call. It reads the dates rather than the status
 * column, so a member is current the instant a renewal is confirmed and lapsed the instant
 * grace runs out, with no job in between deciding either.
 *
 * @param array<string,mixed> $member
 */
function membershipHasBenefits(array $member): bool
{
    return (bool)membershipStanding($member)['benefits'];
}

/**
 * Looks up one member's standing by user id.
 *
 * @return array<string,mixed>
 */
function membershipStandingForUser(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT membership_status, renewal_due FROM member_profiles
                               WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('Membership standing unavailable: ' . $e->getMessage());
        $row = null;
    }
    return membershipStanding($row ?: ['membership_status' => '']);
}

/** How far ahead each renewal reminder goes out, most distant first. */
const MEMBERSHIP_REMINDER_BANDS = [30, 14, 1];

/**
 * Which reminder to send today, or null for none.
 *
 * Bands are thresholds, not dates. The old rule matched the renewal date exactly - due in
 * precisely 30, 14 or 1 days - so each reminder existed on one calendar day only, and a job
 * that did not run that day lost it permanently: the next morning the date no longer
 * matched, and nothing would match it again. Members first heard about a renewal on the day
 * it fell due.
 *
 * Reading the bands as thresholds fixes that. A member ten days out is in the 14-day band
 * whether or not anything ran on the fourteenth day, so a week of missed runs catches
 * everybody up at whatever stage they have since reached.
 *
 * $lastSent is the most urgent band already sent, which is what keeps each stage to one
 * message however often this runs.
 *
 * @param list<int> $bands
 */
function membershipReminderBand(int $daysUntilDue, ?int $lastSent, array $bands = MEMBERSHIP_REMINDER_BANDS): ?int
{
    if ($daysUntilDue < 0) return null;

    $band = null;
    foreach ($bands as $candidate) {
        if ($daysUntilDue <= $candidate && ($band === null || $candidate < $band)) $band = $candidate;
    }
    if ($band === null) return null;
    if ($lastSent !== null && $lastSent <= $band) return null;
    return $band;
}

/**
 * The renewal date a payment confirmed today should produce.
 *
 * Extended from the date already held whenever that date is still in the future, so renewing
 * a month early does not cost a month. Once the date is past - including deep into grace -
 * the new term runs from today, because the alternative is selling somebody a year that has
 * already partly elapsed.
 */
function membershipNextRenewalDate(?string $currentDue, ?string $from = null): string
{
    $today = $from
        ? (DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10)) ?: new DateTimeImmutable('today'))
        : new DateTimeImmutable('today');

    $start = $today;
    if ($currentDue) {
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', substr($currentDue, 0, 10));
        if ($due && $due > $today) $start = $due;
    }
    return $start->modify('+' . MEMBERSHIP_TERM_MONTHS . ' months')->format('Y-m-d');
}

/**
 * Extends a membership by one term and returns the new date.
 *
 * Sets the status back to active, because this is also the path a lapsed member takes back:
 * paying is what makes somebody a member again, and requiring a second manual approval for a
 * renewal would leave people who have paid sitting outside their own account.
 *
 * Clears last_reminder_days so the next cycle's reminders are free to fire again. Left set,
 * a member who renewed after their 14-day reminder would never receive that reminder in any
 * subsequent year.
 */
function membershipExtend(PDO $pdo, int $memberProfileId): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT renewal_due FROM member_profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$memberProfileId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $next = membershipNextRenewalDate($row['renewal_due'] ?? null);
        $pdo->prepare("UPDATE member_profiles
                       SET renewal_due = ?, membership_status = 'active'
                       WHERE id = ?")
            ->execute([$next, $memberProfileId]);

        // Bookkeeping, in its own statement and its own try. Clearing it lets next cycle's
        // reminders fire - left set, a member who renewed after their 14-day notice would
        // never receive that notice again in any later year. But it is not what the member
        // paid for, and a renewal must not fail because this column is missing on an install
        // where migration-membership-renewal.sql has not been applied.
        try {
            $pdo->prepare('UPDATE member_profiles SET last_reminder_days = NULL WHERE id = ?')
                ->execute([$memberProfileId]);
        } catch (Throwable $e) {
            error_log('Could not clear renewal reminder state: ' . $e->getMessage());
        }
        return $next;
    } catch (Throwable $e) {
        error_log('Could not extend membership ' . $memberProfileId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Marks memberships whose grace has run out.
 *
 * Only the column moves. Access was already decided by the dates the moment grace ended, so
 * this changes nothing a member can feel - it makes the state queryable, and returns the
 * rows so the caller can send one notice each.
 *
 * @return list<array<string,mixed>> the profiles that changed
 */
function membershipMarkLapsed(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare("SELECT mp.id, mp.user_id, mp.title, mp.first_name, mp.middle_name,
                                      mp.surname, mp.renewal_due, u.email
                               FROM member_profiles mp
                               JOIN users u ON u.id = mp.user_id
                               WHERE mp.membership_status = 'active'
                                 AND mp.renewal_due IS NOT NULL
                                 AND mp.renewal_due < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        $stmt->execute([MEMBERSHIP_GRACE_DAYS]);
        $rows = $stmt->fetchAll();
        if (!$rows) return [];

        $update = $pdo->prepare("UPDATE member_profiles SET membership_status = 'expired' WHERE id = ?");
        foreach ($rows as $row) $update->execute([(int)$row['id']]);
        return $rows;
    } catch (Throwable $e) {
        error_log('Could not mark lapsed memberships: ' . $e->getMessage());
        return [];
    }
}

/**
 * The renewal picture for the admin dashboard.
 *
 * Counted from the dates rather than the status column, so the numbers are right even on a
 * day the lapse job has not run - and a difference between 'lapsed' here and the count of
 * rows marked expired is itself worth being able to see.
 *
 * @return array<string,int>
 */
function membershipRenewalSummary(PDO $pdo): array
{
    $empty = ['active' => 0, 'dueSoon' => 0, 'inGrace' => 0, 'lapsed' => 0, 'undated' => 0];
    try {
        $stmt = $pdo->query("SELECT membership_status, renewal_due FROM member_profiles
                             WHERE membership_status IN ('active', 'expired')");
        if (!$stmt) return $empty;
    } catch (Throwable $e) {
        error_log('Renewal summary unavailable: ' . $e->getMessage());
        return $empty;
    }

    $out = $empty;
    foreach ($stmt->fetchAll() as $row) {
        $standing = membershipStanding($row)['standing'];
        if ($standing === 'current')       $out['active']++;
        elseif ($standing === 'due_soon')  { $out['active']++; $out['dueSoon']++; }
        elseif ($standing === 'grace')     $out['inGrace']++;
        elseif ($standing === 'lapsed')    $out['lapsed']++;
        elseif ($standing === 'undated')   { $out['active']++; $out['undated']++; }
    }
    return $out;
}
