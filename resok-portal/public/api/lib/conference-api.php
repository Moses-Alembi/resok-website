<?php
declare(strict_types=1);

/**
 * Routes for conference registration, badges and check-in. index.php hands every route
 * beginning "conference" here, the way it does for the MDT module.
 *
 * Who may do what (schematic: roles):
 *   delegate      register, pay, see their own registration and QR code
 *   finance       review payments, settle or waive fees, export
 *   registration  the desk: find people, settle at the desk, reprint, transfer, fix details,
 *                 print badges; also scans
 *   scanner       download the roster and upload scans, nothing else
 *   admin         all of the above plus settings, fees, add-ons and staff
 */
function confHandle(PDO $pdo, array $config, string $route, string $method): void
{
    if (!confEnsureTables($pdo) || !function_exists('abstractsEnsureTables') || !abstractsEnsureTables($pdo)) {
        respond(503, ['error' => 'Conference registration is unavailable: its tables could not be created.']);
    }

    // The conference is the abstract system's current event. Staff who are super
    // administrators also see a draft, so everything can be set up before launch.
    $eventFor = function (?array $viewer) use ($pdo, $config): array {
        $preview = $viewer !== null && isSuperAdmin($viewer, $config);
        $row = absCurrentEventRow($pdo, $preview);
        if (!$row) respond(404, ['error' => 'There is no conference to register for at the moment.']);
        return $row;
    };
    $eventShape = fn(array $e): array => [
        'id' => (int)$e['id'], 'name' => $e['name'], 'refPrefix' => $e['ref_prefix'], 'startsOn' => $e['starts_on'],
        'endsOn' => $e['ends_on'], 'venue' => $e['venue'], 'status' => $e['status'],
    ];

    $programmeReady = function_exists('confProgrammeEnsureTables') && confProgrammeEnsureTables($pdo);
    // The programme is edited by the conference's administrators and by the abstract
    // system's programme chair, who decides the sessions anyway.
    $canEditProgramme = function (array $user, int $eventId) use ($pdo, $config): bool {
        if (confHasRole($pdo, $user, $config, $eventId, 'admin')) return true;
        $stmt = $pdo->prepare("SELECT 1 FROM abs_roles WHERE event_id = ? AND user_id = ? AND role = 'programme_chair' LIMIT 1");
        $stmt->execute([$eventId, (int)($user['userId'] ?? 0)]);
        return (bool)$stmt->fetch();
    };

    // ------------------------------------------------------------------ speakers
    $speakersReady = function_exists('confSpeakersEnsureTables') && confSpeakersEnsureTables($pdo);
    if (strpos($route, 'conference/speaker') === 0 || strpos($route, 'conference/admin/speakers') === 0) {
        if (!$speakersReady) respond(503, ['error' => 'Speaker profiles are not available on this server.']);
    }
    if ($route === 'conference/speakers' && $method === 'GET') {
        $event = $eventFor(authOptional($config));
        $published = $programmeReady && confProgrammePublished($pdo, (int)$event['id']);
        respond(200, ['speakers' => confSpeakers($pdo, (int)$event['id'], 'public', $published)]);
    }
    if (preg_match('#^conference/speakers/(\d+)/photo$#', $route, $m) && $method === 'GET') {
        // Published photos are public. An unpublished one only for the programme team and
        // the speaker, so a profile being prepared is not visible by guessing its number.
        $viewer = authOptional($config);
        $event = $eventFor($viewer);
        $row = confSpeakerRow($pdo, (int)$event['id'], (int)$m[1]);
        $allowed = $row && ($row['published'] || ($viewer && ($canEditProgramme($viewer, (int)$event['id'])
                            || (int)$row['user_id'] === (int)$viewer['userId'])));
        $path = $allowed && $row['photo_file'] ? confSpeakerPhotoDir($config) . '/' . basename((string)$row['photo_file']) : '';
        if (!$path || !is_file($path)) respond(404, ['error' => 'No photo.']);
        header_remove('Content-Type');
        header('Content-Type: ' . ($row['photo_mime'] === 'image/png' ? 'image/png' : 'image/jpeg'));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
        header('Cache-Control: ' . ($row['published'] ? 'public, max-age=3600' : 'private, no-store'));
        readfile($path);
        exit;
    }
    if (strpos($route, 'conference/speaker/me') === 0) {
        $user = auth($config);
        $event = $eventFor($user);
        $me = confSpeakerForUser($pdo, (int)$event['id'], $user);
        if (!$me) respond(404, ['error' => 'You are not listed as a speaker.']);
        $self = function () use ($pdo, $event, $user): void {
            $row = confSpeakerForUser($pdo, (int)$event['id'], $user);
            $sessions = confSpeakerSessions($pdo, [(int)$row['id']]);
            respond(200, ['speaker' => confSpeakerShape($row, $sessions[(int)$row['id']] ?? [], 'self'),
                          'accommodationOptions' => CONF_SPEAKER_ACCOMMODATION]);
        };
        if ($route === 'conference/speaker/me' && $method === 'GET') $self();
        if ($route === 'conference/speaker/me' && $method === 'POST') {
            if ($e = confSpeakerSaveOwn($pdo, $me, input())) respond(400, ['error' => $e]);
            $self();
        }
        if ($route === 'conference/speaker/me/photo' && $method === 'POST') {
            if ($e = confSpeakerPhoto($pdo, $config, $me, $_FILES['photo'] ?? null)) respond(400, ['error' => $e]);
            $self();
        }
        respond(404, ['error' => 'Unknown route.']);
    }
    if (strpos($route, 'conference/admin/speakers') === 0) {
        $user = auth($config);
        $event = $eventFor($user);
        $eventId = (int)$event['id'];
        if (!$canEditProgramme($user, $eventId)) respond(403, ['error' => 'Only the programme chair and conference administrators manage speakers.']);
        confRequireStaffMfa($pdo, $config, $user, confRoles($pdo, $user, $config, $eventId));
        // Travel is for the people booking rooms and pick-ups: conference administrators.
        $isAdmin = confHasRole($pdo, $user, $config, $eventId, 'admin');
        $by = (int)$user['userId'];
        $list = fn(array $extra = []) => respond(200, ['speakers' => confSpeakers($pdo, $eventId, $isAdmin ? 'admin' : 'staff'), 'canSeeTravel' => $isAdmin,
                                                       'roles' => CONF_SPEAKER_ROLES, 'accommodationOptions' => CONF_SPEAKER_ACCOMMODATION] + $extra);
        if ($route === 'conference/admin/speakers' && $method === 'GET') $list();
        if ($route === 'conference/admin/speakers/travel' && $method === 'GET') {
            if (!$isAdmin) respond(403, ['error' => 'Only conference administrators see travel details.']);
            $rows = [['Speaker', 'Institution', 'Country', 'Email', 'Arrives', 'Departs', 'Flight', 'Accommodation', 'Notes']];
            foreach (confSpeakers($pdo, $eventId, 'admin') as $s) {
                $t = $s['travel'];
                $rows[] = [trim(($s['honorific'] ? $s['honorific'] . ' ' : '') . $s['name']), $s['institution'], $s['country'], $s['email'],
                           $t['arrivalAt'], $t['departureAt'], $t['flight'], $t['accommodationLabel'], $t['notes']];
            }
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="speaker-travel-' . date('Ymd') . '.csv"');
            header('Cache-Control: private, no-store');
            echo absCsv($rows);
            exit;
        }
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $e = null;
        if ($route === 'conference/admin/speakers') {
            $data = input();
            [$id, $e] = confSaveSpeaker($pdo, $eventId, $data, $by);
            if (!$e && $isAdmin && is_array($data['travel'] ?? null)) $e = confSpeakerSaveTravel($pdo, (int)$id, $data['travel']);
        } elseif (preg_match('#^conference/admin/speakers/(\d+)/(photo|delete|invite)$#', $route, $m)) {
            $row = confSpeakerRow($pdo, $eventId, (int)$m[1]);
            if (!$row) respond(404, ['error' => 'No such speaker.']);
            if ($m[2] === 'photo') $e = confSpeakerPhoto($pdo, $config, $row, $_FILES['photo'] ?? null);
            if ($m[2] === 'delete') $e = confSpeakerDelete($pdo, $config, $eventId, (int)$row['id'], $by);
            if ($m[2] === 'invite') {
                $e = confSpeakerInvite($pdo, $config, $event, (int)$row['id']);
                if (!$e) $list(['message' => 'Invitation sent to ' . $row['email'] . '.']);
            }
        } else {
            respond(404, ['error' => 'Unknown route.']);
        }
        if ($e) respond(400, ['error' => $e]);
        $list();
    }

    // ------------------------------------------------------------------ evaluation survey
    if (strpos($route, 'conference/survey') === 0 || strpos($route, 'conference/admin/survey') === 0) {
        if (!function_exists('confSurveyEnsureTables') || !confSurveyEnsureTables($pdo)) {
            respond(503, ['error' => 'The evaluation is not available on this server.']);
        }
        $user = auth($config);
        $event = $eventFor($user);
        $eventId = (int)$event['id'];
        if ($route === 'conference/survey') {
            $reg = confRegistrationFor($pdo, $eventId, (int)$user['userId']);
            if ($method === 'GET') respond(200, ['survey' => confSurveyForDelegate($pdo, $event, $reg)]);
            if ($method === 'POST') {
                if ($e = confSurveySubmit($pdo, $event, $reg, input())) respond(400, ['error' => $e]);
                respond(200, ['survey' => confSurveyForDelegate($pdo, $event, $reg)]);
            }
            respond(405, ['error' => 'Method not allowed']);
        }
        if (!confHasRole($pdo, $user, $config, $eventId, 'admin')) respond(403, ['error' => 'Only conference administrators see the evaluation.']);
        confRequireStaffMfa($pdo, $config, $user, confRoles($pdo, $user, $config, $eventId));
        if ($route === 'conference/admin/survey' && $method === 'GET') {
            respond(200, ['state' => confSurveyState($pdo, $eventId), 'results' => confSurveyResults($pdo, $event)]);
        }
        if ($route === 'conference/admin/survey' && $method === 'POST') {
            if ($e = confSurveySaveState($pdo, $eventId, input(), (int)$user['userId'])) respond(400, ['error' => $e]);
            respond(200, ['state' => confSurveyState($pdo, $eventId), 'results' => confSurveyResults($pdo, $event)]);
        }
        if ($route === 'conference/admin/survey/export' && $method === 'GET') {
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="evaluation-' . date('Ymd') . '.csv"');
            header('Cache-Control: private, no-store');
            echo absCsv(confSurveyCsvRows($pdo, $event));
            exit;
        }
        respond(404, ['error' => 'Unknown route.']);
    }

    // ------------------------------------------------------------------ groups
    $groupsReady = function_exists('confGroupsEnsureTables') && confGroupsEnsureTables($pdo);
    if (strpos($route, 'conference/admin/groups') === 0) {
        if (!$groupsReady) respond(503, ['error' => 'Group registration is not available on this server.']);
        $user = auth($config);
        $event = $eventFor($user);
        $eventId = (int)$event['id'];
        if (!confHasRole($pdo, $user, $config, $eventId, 'finance') && !confHasRole($pdo, $user, $config, $eventId, 'admin')) {
            respond(403, ['error' => 'Only finance and conference administrators manage group invoices.']);
        }
        confRequireStaffMfa($pdo, $config, $user, confRoles($pdo, $user, $config, $eventId));
        $by = (int)$user['userId'];
        $list = fn(array $extra = []) => respond(200, ['groups' => confGroups($pdo, $eventId)] + $extra);
        if ($route === 'conference/admin/groups' && $method === 'GET') $list();
        if (preg_match('#^conference/admin/groups/(\d+)/invoice$#', $route, $m) && $method === 'GET') {
            $g = confGroupRow($pdo, $eventId, (int)$m[1]);
            if (!$g) respond(404, ['error' => 'No such group.']);
            confSendPdf(confGroupInvoicePdf($event, $g, confGroupMembers($pdo, (int)$g['id']), confSettings($pdo, $eventId)['notes']),
                        'Invoice-' . $g['invoice_ref'] . '.pdf');
        }
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $data = input();
        if ($route === 'conference/admin/groups') {
            [, $e] = confSaveGroup($pdo, $event, $data, $by);
            if ($e) respond(400, ['error' => $e]);
            $list();
        }
        if (preg_match('#^conference/admin/groups/(\d+)/(add|remove|paid)$#', $route, $m)) {
            $g = confGroupRow($pdo, $eventId, (int)$m[1]);
            if (!$g) respond(404, ['error' => 'No such group.']);
            $extra = [];
            if ($m[2] === 'add') {
                $stmt = $pdo->prepare('SELECT * FROM conf_registrations WHERE event_id = ? AND ref = ?');
                $stmt->execute([$eventId, strtoupper(trim(confStr($data['ref'] ?? '')))]);
                $reg = $stmt->fetch();
                $e = $reg ? confGroupAdd($pdo, $event, $g, $reg, $by) : 'No registration has that reference.';
            } elseif ($m[2] === 'remove') {
                $e = confGroupRemove($pdo, $event, $g, (int)($data['registrationId'] ?? 0), $by);
            } else {
                [$result, $e] = confGroupPaid($pdo, $config, $event, $g, confStr($data['reference'] ?? ''), $by);
                $extra = ['result' => $result];
            }
            if ($e) respond(400, ['error' => $e]);
            $list($extra);
        }
        respond(404, ['error' => 'Unknown route.']);
    }

    // ------------------------------------------------------------------ sponsors
    $sponsorsReady = function_exists('confSponsorsEnsureTables') && confSponsorsEnsureTables($pdo);
    if ($route === 'conference/sponsors' && $method === 'GET') {
        if (!$sponsorsReady) respond(503, ['error' => 'Sponsors are not available on this server.']);
        $event = $eventFor(authOptional($config));
        respond(200, ['sponsors' => confSponsors($pdo, (int)$event['id'])]);
    }
    if (preg_match('#^conference/sponsors/(\d+)/logo$#', $route, $m) && $method === 'GET') {
        if (!$sponsorsReady) respond(404, ['error' => 'No logo.']);
        $stmt = $pdo->prepare('SELECT logo_file, logo_mime, active FROM conf_sponsors WHERE id = ?');
        $stmt->execute([(int)$m[1]]);
        $row = $stmt->fetch();
        $path = $row && $row['logo_file'] ? confLogoDir($config) . '/' . basename((string)$row['logo_file']) : '';
        if (!$path || !is_file($path)) respond(404, ['error' => 'No logo.']);
        header_remove('Content-Type');
        header('Content-Type: ' . $row['logo_mime']);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
        header('Cache-Control: public, max-age=3600');
        readfile($path);
        exit;
    }
    // Booth staff: their sponsors, scanning a badge, and their own leads.
    if (strpos($route, 'conference/leads') === 0) {
        if (!$sponsorsReady) respond(503, ['error' => 'Lead scanning is not available on this server.']);
        $user = auth($config);
        $event = $eventFor($user);
        $eventId = (int)$event['id'];
        $mine = confSponsorsForStaff($pdo, $eventId, (int)$user['userId']);
        $sponsorFor = function (int $id) use ($mine): array {
            foreach ($mine as $s) if ($s['id'] === $id) return $s;
            respond(403, ['error' => 'You do not scan for that sponsor.']);
        };
        if ($route === 'conference/leads' && $method === 'GET') {
            if (!$mine) respond(403, ['error' => 'This account is not registered as staff for any sponsor. Ask the organisers to add you.']);
            $sid = (int)($_GET['sponsor'] ?? $mine[0]['id']);
            $sp = $sponsorFor($sid);
            respond(200, ['event' => $eventShape($event), 'sponsors' => $mine, 'sponsor' => $sp, 'leads' => confLeads($pdo, $sid)]);
        }
        if ($route === 'conference/leads/scan' && $method === 'POST') {
            $data = input();
            $sp = $sponsorFor((int)($data['sponsorId'] ?? 0));
            if (function_exists('throttleCheck')) throttleCheck($pdo, $config, 'conf_lead_scan', (string)$user['userId']);
            [$lead, $e] = confRecordLead($pdo, $eventId, $sp['id'], (int)$user['userId'], confStr($data['code'] ?? ''), confStr($data['note'] ?? ''));
            if ($e) {
                if (function_exists('throttleFailure')) throttleFailure($pdo, $config, 'conf_lead_scan', (string)$user['userId']);
                respond(400, ['error' => $e]);
            }
            respond(200, ['lead' => $lead]);
        }
        if ($route === 'conference/leads/export' && $method === 'GET') {
            $sp = $sponsorFor((int)($_GET['sponsor'] ?? 0));
            $rows = [['Name', 'Institution', 'Country', 'Profession', 'Email (if shared)', 'Note', 'Scanned']];
            foreach (confLeads($pdo, $sp['id']) as $l) $rows[] = [$l['name'], $l['institution'], $l['country'], $l['profession'], $l['email'] ?? '', $l['note'], $l['scannedAt']];
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="leads-' . date('Ymd') . '.csv"');
            header('Cache-Control: no-store');
            echo function_exists('absCsv') ? absCsv($rows) : '';
            exit;
        }
        respond(404, ['error' => 'Unknown route.']);
    }
    if (strpos($route, 'conference/admin/sponsors') === 0) {
        if (!$sponsorsReady) respond(503, ['error' => 'Sponsors are not available on this server.']);
        $user = auth($config);
        $event = $eventFor($user);
        $eventId = (int)$event['id'];
        if (!confHasRole($pdo, $user, $config, $eventId, 'admin')) respond(403, ['error' => 'Only conference administrators manage sponsors.']);
        confRequireStaffMfa($pdo, $config, $user, confRoles($pdo, $user, $config, $eventId));
        $list = fn() => respond(200, ['sponsors' => confSponsors($pdo, $eventId, true), 'tiers' => CONF_SPONSOR_TIERS]);
        if ($route === 'conference/admin/sponsors' && $method === 'GET') $list();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $e = null;
        if ($route === 'conference/admin/sponsors') {
            [, $e] = confSaveSponsor($pdo, $eventId, input());
        } elseif (preg_match('#^conference/admin/sponsors/(\d+)/logo$#', $route, $m)) {
            $e = confSponsorLogo($pdo, $config, $eventId, (int)$m[1], $_FILES['logo'] ?? null);
        } elseif (preg_match('#^conference/admin/sponsors/(\d+)/staff$#', $route, $m)) {
            $e = confSponsorStaffAdd($pdo, $eventId, (int)$m[1], (string)(input()['email'] ?? ''));
        } elseif (preg_match('#^conference/admin/sponsors/(\d+)/staff/(\d+)/remove$#', $route, $m)) {
            $e = confSponsorStaffRemove($pdo, $eventId, (int)$m[1], (int)$m[2]);
        } else {
            respond(404, ['error' => 'Unknown route.']);
        }
        if ($e) respond(400, ['error' => $e]);
        $list();
    }

    // ------------------------------------------------------------------ programme
    if ($route === 'conference/programme' && $method === 'GET') {
        if (!$programmeReady) respond(503, ['error' => 'The programme is not available on this server.']);
        $viewer = authOptional($config);
        $event = $eventFor($viewer);
        $preview = $viewer && $canEditProgramme($viewer, (int)$event['id']);
        $published = confProgrammePublished($pdo, (int)$event['id']);
        if (!$published && !$preview) {
            respond(200, ['event' => $eventShape($event), 'published' => false, 'days' => []]);
        }
        respond(200, ['event' => $eventShape($event), 'published' => $published, 'preview' => !$published,
                      'days' => confProgramme($pdo, (int)$event['id'])]);
    }
    if (strpos($route, 'conference/admin/programme') === 0) {
        if (!$programmeReady) respond(503, ['error' => 'The programme is not available on this server.']);
        $user = auth($config);
        $event = $eventFor($user);
        $eventId = (int)$event['id'];
        if (!$canEditProgramme($user, $eventId)) respond(403, ['error' => 'Only the programme chair and conference administrators can edit the programme.']);
        $data = $method === 'POST' ? input() : [];
        $state = function () use ($pdo, $eventId, $event, $eventShape): array {
            return ['event' => $eventShape($event), 'published' => confProgrammePublished($pdo, $eventId), 'rooms' => confRooms($pdo, $eventId),
                    'days' => confProgramme($pdo, $eventId, true), 'unplaced' => confUnplacedAbstracts($pdo, $eventId),
                    'kinds' => CONF_SESSION_KINDS];
        };
        $e = null;
        if ($route === 'conference/admin/programme' && $method === 'GET') respond(200, $state());
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        if ($route === 'conference/admin/programme/publish') {
            confProgrammeSetPublished($pdo, $eventId, !empty($data['published']), (int)$user['userId']);
        } elseif ($route === 'conference/admin/programme/rooms') {
            $e = confSaveRoom($pdo, $eventId, $data);
        } elseif ($route === 'conference/admin/programme/sessions') {
            [, $e] = confSaveSession($pdo, $eventId, $data);
        } elseif (preg_match('#^conference/admin/programme/sessions/(\d+)/delete$#', $route, $m)) {
            $e = confDeleteSession($pdo, $eventId, (int)$m[1]);
        } elseif (preg_match('#^conference/admin/programme/sessions/(\d+)/items$#', $route, $m)) {
            $e = confAddItem($pdo, $eventId, (int)$m[1], $data);
        } elseif (preg_match('#^conference/admin/programme/items/(\d+)/remove$#', $route, $m)) {
            $e = confRemoveItem($pdo, $eventId, (int)$m[1]);
        } else {
            respond(404, ['error' => 'Unknown programme route.']);
        }
        if ($e) respond(400, ['error' => $e]);
        respond(200, $state());
    }

    // ------------------------------------------------------------------ delegate
    if ($route === 'conference/current' && $method === 'GET') {
        $viewer = authOptional($config);
        $event = $eventFor($viewer);
        $eventId = (int)$event['id'];
        $settings = confSettings($pdo, $eventId);
        $today = confToday($event);
        $categories = array_map(function ($c) use ($today) {
            $c['today'] = confPriceOn($c, $today);
            unset($c['taken']);
            $c['full'] = false;
            return $c;
        }, confCategories($pdo, $eventId));
        // Full is worked out from the counts, which are not themselves published.
        foreach (confCategories($pdo, $eventId) as $i => $c) {
            $categories[$i]['full'] = $c['capacity'] !== null && $c['taken'] >= $c['capacity'];
        }
        $addons = array_map(function ($a) {
            $a['full'] = $a['capacity'] !== null && $a['taken'] >= $a['capacity'];
            unset($a['taken']);
            return $a;
        }, confAddons($pdo, $eventId));
        $payload = [
            'event' => $eventShape($event),
            'registration' => ['state' => confRegistrationState($event, $settings), 'opensAt' => $settings['opensAt'],
                               'closesAt' => $settings['closesAt'], 'notes' => $settings['notes']],
            'categories' => $categories,
            'addons' => $addons,
            'today' => $today->format('Y-m-d'),
            'me' => null,
        ];
        if ($viewer) {
            $userId = (int)$viewer['userId'];
            $reg = confRegistrationFor($pdo, $eventId, $userId);
            if ($reg) $reg = confRepriceIfLapsed($pdo, $event, $reg);
            $standing = function_exists('membershipStandingForUser') ? membershipStandingForUser($pdo, $userId) : ['benefits' => false];
            $profile = function_exists('absProfile') ? absProfile($pdo, $userId) : [];
            $payload['me'] = [
                'email' => $viewer['email'] ?? '',
                'isMemberInGoodStanding' => !empty($standing['benefits']),
                'profile' => $profile,
                'registration' => $reg ? confShape($pdo, $reg) : null,
                'staffRoles' => confRoles($pdo, $viewer, $config, $eventId),
            ];
        }
        respond(200, $payload);
    }

    if ($route === 'conference/register' && $method === 'POST') {
        $user = auth($config);
        $event = $eventFor($user);
        [$reg, $error] = confRegister($pdo, $config, $event, $user, input());
        if ($error) respond(400, ['error' => $error]);
        respond(201, ['registration' => confShape($pdo, $reg)]);
    }

    if ($route === 'conference/payment' && $method === 'POST') {
        $user = auth($config);
        $event = $eventFor($user);
        $reg = confRegistrationFor($pdo, (int)$event['id'], (int)$user['userId']);
        if (!$reg) respond(404, ['error' => 'You have not registered yet.']);
        $error = confSubmitPayment($pdo, $config, $event, $reg, (int)$user['userId'], $_POST ?: input(), $_FILES['proof'] ?? null);
        if ($error) respond(400, ['error' => $error]);
        respond(200, ['registration' => confShape($pdo, confRegistrationRow($pdo, (int)$reg['id']))]);
    }

    if ($route === 'conference/certificate' && $method === 'GET') {
        $user = auth($config);
        $event = $eventFor($user);
        $reg = confRegistrationFor($pdo, (int)$event['id'], (int)$user['userId']);
        $pdf = $reg ? confCertificatePdf($pdo, $event, $reg) : null;
        if (!$pdf) respond(404, ['error' => 'A certificate is available once you have been scanned in at the conference.']);
        confSendPdf($pdf, 'Certificate-of-attendance-' . $reg['ref'] . '.pdf');
    }

    if ($route === 'conference/invitation-letter' && $method === 'GET') {
        $user = auth($config);
        $event = $eventFor($user);
        $reg = confRegistrationFor($pdo, (int)$event['id'], (int)$user['userId']);
        if (!$reg) respond(404, ['error' => 'Register first; the letter is issued for a registration.']);
        // Only a paid, confirmed registration: an official invitation must not be obtainable
        // by creating an account and registering without paying.
        if ($reg['status'] !== 'confirmed') respond(403, ['error' => 'The invitation letter is available once your registration is confirmed (after payment).']);
        $passport = mb_substr(trim((string)preg_replace('/[^\p{L}\p{N} -]/u', '', (string)($_GET['passport'] ?? ''))), 0, 30);
        $nationality = mb_substr(trim((string)preg_replace('/[^\p{L} -]/u', '', (string)($_GET['nationality'] ?? ''))), 0, 40);
        confAudit($pdo, (int)$event['id'], (int)$reg['id'], (int)$user['userId'], 'invitation_letter');
        confSendPdf(confInvitationLetterPdf($event, $reg, $passport, $nationality), 'Invitation-letter-' . $reg['ref'] . '.pdf');
    }

    // A proof is shown to the person who uploaded it and to finance and desk staff.
    if (preg_match('#^conference/payments/(\d+)/proof$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        $stmt = $pdo->prepare('SELECT p.proof_file, p.proof_mime, r.user_id, r.event_id FROM conf_payments p
                               JOIN conf_registrations r ON r.id = p.registration_id WHERE p.id = ?');
        $stmt->execute([(int)$m[1]]);
        $p = $stmt->fetch();
        $owner = $p && (int)$p['user_id'] === (int)$user['userId'];
        $staff = $p && !$owner && (confHasRole($pdo, $user, $config, (int)$p['event_id'], 'finance')
            || confHasRole($pdo, $user, $config, (int)$p['event_id'], 'registration'));
        if ((!$owner && !$staff) || !$p['proof_file']) respond(404, ['error' => 'No such file.']);
        if ($staff) confRequireStaffMfa($pdo, $config, $user, confRoles($pdo, $user, $config, (int)$p['event_id']));
        $path = confProofDir($config) . '/' . basename((string)$p['proof_file']);
        if (!is_file($path)) respond(404, ['error' => 'The file is missing on the server.']);
        header_remove('Content-Type');
        header('Content-Type: ' . $p['proof_mime']);
        header('Content-Disposition: inline; filename="payment-proof"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ staff
    if (strpos($route, 'conference/admin') === 0 || strpos($route, 'conference/scan') === 0) {
        $user = auth($config);
        $event = $eventFor($user);
        $eventId = (int)$event['id'];
        $staffId = (int)$user['userId'];
        $roles = confRoles($pdo, $user, $config, $eventId);
        $need = function (string ...$any) use ($roles): void {
            foreach ($any as $r) if (in_array($r, $roles, true)) return;
            respond(403, ['error' => 'You do not have access to this part of conference registration.']);
        };
        confRequireStaffMfa($pdo, $config, $user, $roles);

        if ($route === 'conference/scan/roster' && $method === 'GET') {
            $need('scanner');
            respond(200, ['event' => $eventShape($event)] + confRoster($pdo, $event));
        }
        if ($route === 'conference/scan/sync' && $method === 'POST') {
            $need('scanner');
            $data = input();
            $results = confSyncScans($pdo, $event, $staffId, (array)($data['scans'] ?? []));
            respond(200, ['results' => $results] + confRoster($pdo, $event));
        }

        if ($route === 'conference/admin/overview' && $method === 'GET') {
            if (!$roles) respond(403, ['error' => 'You do not have access to conference registration.']);
            $payload = ['event' => $eventShape($event), 'roles' => $roles, 'settings' => confSettings($pdo, $eventId),
                        'state' => confRegistrationState($event, confSettings($pdo, $eventId)),
                        'stats' => confStats($pdo, $event), 'categories' => confCategories($pdo, $eventId, true),
                        'addons' => confAddons($pdo, $eventId, true), 'today' => confToday($event)->format('Y-m-d')];
            if (in_array('admin', $roles, true)) $payload['staff'] = confStaffList($pdo, $eventId);
            respond(200, $payload);
        }

        if ($route === 'conference/admin/cpd' && $method === 'GET') {
            $need('admin', 'finance');
            respond(200, confCpdOverview($pdo, $event));
        }
        if ($route === 'conference/admin/cpd' && $method === 'POST') {
            $need('admin');
            $data = input();
            [$result, $e] = confPushToCpd($pdo, $event, confStr($data['day'] ?? ''), (int)($data['cpdEventId'] ?? 0), $staffId);
            if ($e) respond(400, ['error' => $e]);
            respond(200, ['result' => $result] + confCpdOverview($pdo, $event));
        }

        if ($route === 'conference/admin/settings' && $method === 'POST') {
            $need('admin');
            if ($e = confSaveSettings($pdo, $eventId, input(), $staffId)) respond(400, ['error' => $e]);
            respond(200, ['settings' => confSettings($pdo, $eventId), 'state' => confRegistrationState($event, confSettings($pdo, $eventId))]);
        }
        if ($route === 'conference/admin/categories' && $method === 'POST') {
            $need('admin');
            [, $e] = confSaveCategory($pdo, $eventId, input(), $staffId);
            if ($e) respond(400, ['error' => $e]);
            respond(200, ['categories' => confCategories($pdo, $eventId, true)]);
        }
        if ($route === 'conference/admin/addons' && $method === 'POST') {
            $need('admin');
            [, $e] = confSaveAddon($pdo, $eventId, input(), $staffId);
            if ($e) respond(400, ['error' => $e]);
            respond(200, ['addons' => confAddons($pdo, $eventId, true)]);
        }
        if ($route === 'conference/admin/staff' && $method === 'POST') {
            $need('admin');
            $data = input();
            if ($e = confStaffGrant($pdo, $eventId, confStr($data['email'] ?? ''), confStr($data['role'] ?? ''), $staffId)) respond(400, ['error' => $e]);
            respond(200, ['staff' => confStaffList($pdo, $eventId)]);
        }
        if (preg_match('#^conference/admin/staff/(\d+)/revoke$#', $route, $m) && $method === 'POST') {
            $need('admin');
            confStaffRevoke($pdo, $eventId, (int)$m[1], $staffId);
            respond(200, ['staff' => confStaffList($pdo, $eventId)]);
        }

        if ($route === 'conference/admin/registrations' && $method === 'GET') {
            $need('finance', 'registration');
            respond(200, ['registrations' => confList($pdo, $event, $_GET)]);
        }
        if ($route === 'conference/admin/export' && $method === 'GET') {
            $need('finance', 'admin');
            $rows = [['Reference', 'Name on badge', 'Email', 'Institution', 'Country', 'Phone', 'Category', 'Fee', 'Amount due', 'Currency', 'Status', 'Payment waiting', 'Days attended', 'Registered']];
            foreach (confList($pdo, $event, []) as $r) {
                $rows[] = [$r['ref'], $r['name'], $r['email'], $r['institution'], $r['country'], $r['phone'], $r['category'], $r['feeLabel'],
                           $r['amountDue'], $r['currency'], $r['status'], $r['pendingPayments'] ? 'yes' : '', $r['daysIn'], $r['createdAt']];
            }
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="registrations-' . date('Ymd-Hi') . '.csv"');
            header('Cache-Control: no-store');
            echo function_exists('absCsv') ? absCsv($rows) : '';
            exit;
        }

        if (preg_match('#^conference/admin/registrations/(\d+)(?:/([a-z]+))?$#', $route, $m)) {
            $reg = confRegistrationRow($pdo, (int)$m[1]);
            if (!$reg || (int)$reg['event_id'] !== $eventId) respond(404, ['error' => 'No such registration.']);
            $action = $m[2] ?? '';
            $data = $method === 'POST' ? input() : [];
            if ($action === '' && $method === 'GET') {
                $need('finance', 'registration');
                respond(200, ['registration' => confShape($pdo, $reg, true)]);
            }
            if ($method !== 'POST' && $action !== 'certificate') respond(405, ['error' => 'Method not allowed']);
            switch ($action) {
                case 'settle':
                    $need('finance', 'registration');
                    $e = confStaffSettle($pdo, $config, $event, $reg, $staffId, confStr($data['method'] ?? ''), confStr($data['reference'] ?? ''), confStr($data['note'] ?? ''));
                    break;
                case 'certificate':
                    $need('registration', 'finance');
                    $pdf = confCertificatePdf($pdo, $event, $reg);
                    if (!$pdf) respond(404, ['error' => 'No certificate: this delegate has not been scanned in.']);
                    confSendPdf($pdf, 'Certificate-of-attendance-' . $reg['ref'] . '.pdf');
                    break;
                case 'reprint':
                    $need('registration');
                    $e = confReprint($pdo, $config, $event, $reg, $staffId);
                    break;
                case 'transfer':
                    $need('registration');
                    $e = confTransfer($pdo, $config, $event, $reg, $staffId, confStr($data['email'] ?? ''), confStr($data['badgeName'] ?? ''));
                    break;
                case 'cancel':
                    $need('admin', 'registration');
                    $e = confCancel($pdo, $event, $reg, $staffId, confStr($data['reason'] ?? ''));
                    break;
                case 'details':
                    $need('registration');
                    $e = confUpdateDetails($pdo, $event, $reg, $staffId, $data);
                    break;
                default:
                    respond(404, ['error' => 'Unknown action.']);
            }
            if ($e) respond(400, ['error' => $e]);
            respond(200, ['registration' => confShape($pdo, confRegistrationRow($pdo, (int)$reg['id']), true)]);
        }

        if (preg_match('#^conference/admin/payments/(\d+)/review$#', $route, $m) && $method === 'POST') {
            $need('finance');
            $data = input();
            if ($e = confReviewPayment($pdo, $config, $event, (int)$m[1], $staffId, confStr($data['decision'] ?? ''), confStr($data['note'] ?? ''))) {
                respond(400, ['error' => $e]);
            }
            respond(200, ['ok' => true]);
        }

        if ($route === 'conference/admin/badges' && $method === 'GET') {
            $need('registration');
            $ids = isset($_GET['ids']) && $_GET['ids'] !== '' ? explode(',', (string)$_GET['ids']) : null;
            respond(200, ['event' => $eventShape($event), 'badges' => confBadgesForPrint($pdo, $eventId, !empty($_GET['unprinted']), $ids)]);
        }
        if ($route === 'conference/admin/badges/printed' && $method === 'POST') {
            $need('registration');
            respond(200, ['marked' => confMarkPrinted($pdo, $eventId, (array)(input()['badgeIds'] ?? []), $staffId)]);
        }
    }

    respond(404, ['error' => 'Unknown conference route.']);
}

/** Streams a PDF download and stops. */
function confSendPdf(string $pdf, string $filename): void
{
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}
