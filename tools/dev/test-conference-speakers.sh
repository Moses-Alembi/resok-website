#!/usr/bin/env bash
#
# Exercises speaker profiles: the programme team adds and publishes speakers; a speaker signs
# in with the listed email and completes their own profile; the public sees published
# profiles without email or travel; travel is for conference administrators only; photos
# lose their EXIF (GPS) block. Uses spk-*@resok.local accounts and "TESTSPK " speakers.
set -uo pipefail
cd "$(dirname "$0")/../.."

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
UPLOADS="resok-portal/uploads/conference-speakers"
source tools/dev/lib-session.sh
pass=0; fail=0
check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-62s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-62s FAIL\n      should not contain: %s\n' "$1" "$2"; fail=$((fail+1))
    else printf '  %-62s ok\n' "$1"; pass=$((pass+1)); fi
}
q() { $MYSQL -N -e "$1"; }
get()  { curl -s -b "$1" "${API}$2"; }
post() { curl -s -b "$1" -H 'Content-Type: application/json' -X POST -d "$3" "${API}$2"; }
json() { $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) && array_key_exists($k, $d) ? $d[$k] : null; } echo is_scalar($d) ? $d : json_encode($d);' "$1"; }

curl -s "${API}conference/speakers" >/dev/null
EV=$(q "SELECT id FROM abs_events WHERE status='published' ORDER BY id DESC LIMIT 1")
SAVED_PUB=$(q "SELECT COALESCE((SELECT published FROM conf_programme_state WHERE event_id=$EV),0)")
TMP="tools/dev/.spk-tmp"; mkdir -p "$TMP"
cleanup() {
    for f in $(q "SELECT photo_file FROM conf_speakers WHERE name LIKE 'TESTSPK %' AND photo_file IS NOT NULL"); do rm -f "$UPLOADS/$f"; done
    $MYSQL -e "
      DELETE ss FROM conf_speaker_sessions ss JOIN conf_speakers s ON s.id = ss.speaker_id WHERE s.name LIKE 'TESTSPK %';
      DELETE FROM conf_speakers WHERE name LIKE 'TESTSPK %';
      DELETE FROM conf_sessions WHERE title LIKE 'TESTSPK %';
      DELETE FROM abs_emails WHERE to_email LIKE 'spk-%@resok.local';
      DELETE FROM abs_roles WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'spk-%@resok.local');
      DELETE FROM users WHERE email LIKE 'spk-%@resok.local';" 2>/dev/null
}
cleanup
HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
$MYSQL -e "INSERT INTO users (email, password_hash, email_verified, role) VALUES
  ('spk-prof@resok.local','$HASH',1,'member'),('spk-other@resok.local','$HASH',1,'member'),('spk-chair@resok.local','$HASH',1,'member');
  INSERT INTO abs_roles (event_id, user_id, role) SELECT $EV, id, 'programme_chair' FROM users WHERE email='spk-chair@resok.local';"
ADMIN=$(minted_session dev@resok.local)
PROF=$(minted_session spk-prof@resok.local); OTHER=$(minted_session spk-other@resok.local); CHAIR=$(minted_session spk-chair@resok.local)
q "INSERT INTO conf_sessions (event_id, day, starts_at, ends_at, kind, title) VALUES ($EV, '2027-08-10', '09:00', '10:00', 'plenary', 'TESTSPK Keynote session')"
SESS=$(q "SELECT id FROM conf_sessions WHERE title='TESTSPK Keynote session'")

echo "The programme team:"
r=$(post "$OTHER" conference/admin/speakers '{"name":"TESTSPK Nobody"}')
check "a delegate cannot add speakers"                          'Only the programme chair' "$r"
r=$(post "$ADMIN" conference/admin/speakers "{\"name\":\"TESTSPK Jane Mwangi\",\"honorific\":\"Prof\",\"email\":\"SPK-prof@resok.local\",\"institution\":\"University of Nairobi\",\"featured\":true,\"sessions\":[{\"sessionId\":$SESS,\"role\":\"keynote\"}]}")
check "an administrator adds a speaker with a session"          '"roleLabel":"Keynote"' "$r"
SPK=$(q "SELECT id FROM conf_speakers WHERE name='TESTSPK Jane Mwangi'")
r=$(post "$ADMIN" conference/admin/speakers '{"name":"TESTSPK Bad","sessions":[{"sessionId":999999,"role":"speaker"}]}')
check "a session from elsewhere is refused"                     'not in this conference' "$r"
r=$(post "$ADMIN" conference/admin/speakers '{"name":"TESTSPK Bad","email":"not-an-email"}')
check "a bad email is refused"                                  'not valid' "$r"
r=$(post "$CHAIR" conference/admin/speakers "{\"id\":$SPK,\"name\":\"TESTSPK Jane Mwangi\",\"honorific\":\"Prof\",\"email\":\"spk-prof@resok.local\",\"institution\":\"University of Nairobi\",\"featured\":true}")
check "the programme chair edits speakers too"                  'TESTSPK Jane Mwangi' "$r"
absent "but the chair does not see travel"                     '"travel"' "$r"
check "editing without sessions keeps them"                     '"roleLabel":"Keynote"' "$r"
r=$(curl -s -b "$CHAIR" "${API}conference/admin/speakers/travel")
check "nor download the travel sheet"                           'Only conference administrators' "$r"

echo "Not yet published:"
r=$(curl -s "${API}conference/speakers")
absent "an unpublished speaker is not public"                  'TESTSPK Jane' "$r"

echo "The speaker:"
r=$(get "$OTHER" conference/speaker/me)
check "someone not listed has no speaker profile"               'not listed as a speaker' "$r"
r=$(get "$PROF" conference/speaker/me)
check "the speaker finds the profile set up under their email"  'TESTSPK Jane Mwangi' "$r"
check "and it is now linked to their account"                   "$(q "SELECT id FROM users WHERE email='spk-prof@resok.local'")" "$(q "SELECT user_id FROM conf_speakers WHERE id=$SPK")"
r=$(post "$PROF" conference/speaker/me '{"name":"TESTSPK Jane Mwangi","position":"Professor of Medicine","bio":"Works on TB.<script>x</script>","published":true,"email":"evil@x.test","travel":{"arrivalAt":"2027-08-09T14:30","departureAt":"2027-08-12 10:00","flight":"KQ 101","accommodation":"needed","notes":"Vegetarian"}}')
check "the speaker saves their bio and travel"                  '"flight":"KQ 101"' "$r"
check "a speaker cannot publish themselves"                     "0" "$(q "SELECT published FROM conf_speakers WHERE id=$SPK")"
check "or change the listed email"                              "spk-prof@resok.local" "$(q "SELECT email FROM conf_speakers WHERE id=$SPK")"
r=$(post "$PROF" conference/speaker/me '{"name":"TESTSPK Jane Mwangi","travel":{"arrivalAt":"2027-08-12 10:00","departureAt":"2027-08-09 10:00","accommodation":"needed"}}')
check "departure before arrival is refused"                     'after arrival' "$r"
r=$(post "$PROF" conference/speaker/me '{"name":"TESTSPK Jane Mwangi","travel":{"accommodation":"palace"}}')
check "an unknown accommodation option is refused"              'accommodation option' "$r"

echo "Photos:"
# A real JPEG with an EXIF block carrying a GPS marker spliced in after the start marker.
$PHP -r '$j = file_get_contents("resok-portal/public/assets/img/certificate-resok-logo.jpg"); $exif = "Exif\0\0GPS-LAT-1.2921-SECRETHOME"; echo substr($j, 0, 2) . "\xFF\xE1" . pack("n", strlen($exif) + 2) . $exif . substr($j, 2);' > "$TMP/gps.jpg"
grep -q "SECRETHOME" "$TMP/gps.jpg" && check "the test photo carries a GPS marker" "x" "x"
r=$(curl -s -b "$PROF" -F "photo=@$TMP/gps.jpg;type=image/jpeg" "${API}conference/speaker/me/photo")
check "the speaker uploads a photo"                             '"photoUrl":"api' "$r"
FILE=$(q "SELECT photo_file FROM conf_speakers WHERE id=$SPK")
[ -f "$UPLOADS/$FILE" ] && check "the photo is stored outside the web root" "x" "x" || check "the photo is stored outside the web root" "stored" "missing"
grep -q "SECRETHOME" "$UPLOADS/$FILE" && check "its GPS block is removed" "removed" "still there" || check "its GPS block is removed" "x" "x"
printf '<?php echo 1; ?>' > "$TMP/fake.jpg"
r=$(curl -s -b "$PROF" -F "photo=@$TMP/fake.jpg;type=image/jpeg" "${API}conference/speaker/me/photo")
check "a file that is not an image is refused"                  'JPG or PNG' "$r"
code=$(curl -s -o /dev/null -w '%{http_code}' "${API}conference/speakers/$SPK/photo")
check "an unpublished speaker's photo is not public"            "404" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$PROF" "${API}conference/speakers/$SPK/photo")
check "but the speaker sees their own"                          "200" "$code"

echo "Published:"
post "$ADMIN" conference/admin/speakers "{\"id\":$SPK,\"name\":\"TESTSPK Jane Mwangi\",\"honorific\":\"Prof\",\"email\":\"spk-prof@resok.local\",\"position\":\"Professor of Medicine\",\"published\":true,\"featured\":true}" >/dev/null
q "INSERT INTO conf_programme_state (event_id, published) VALUES ($EV, 0) ON DUPLICATE KEY UPDATE published = 0"
r=$(curl -s "${API}conference/speakers")
check "a published speaker is public"                           'TESTSPK Jane Mwangi' "$r"
absent "without their email"                                   'spk-prof' "$r"
absent "or travel"                                             'KQ 101' "$r"
absent "or sessions while the programme is unpublished"        'TESTSPK Keynote session' "$r"
check "the bio is passed as text for the page to escape"        'Works on TB.<script>' "$r"
q "UPDATE conf_programme_state SET published = 1 WHERE event_id = $EV"
r=$(curl -s "${API}conference/speakers")
check "sessions appear once the programme is published"         'TESTSPK Keynote session' "$r"
code=$(curl -s -o /dev/null -w '%{http_code}' "${API}conference/speakers/$SPK/photo")
check "the published photo is public"                           "200" "$code"
h=$(curl -s -D - -o /dev/null "${API}conference/speakers/$SPK/photo")
check "and served as an image in a sandbox"                     'sandbox' "$h"

echo "Administrators:"
r=$(get "$ADMIN" conference/admin/speakers)
check "administrators see travel"                               '"accommodation":"needed"' "$r"
r=$(curl -s -b "$ADMIN" "${API}conference/admin/speakers/travel")
check "and download the travel sheet"                           'KQ 101' "$r"
r=$(post "$ADMIN" "conference/admin/speakers/$SPK/invite" '{}')
check "an invitation is emailed to the speaker"                 'Invitation sent' "$r"
check "once a day at most"                                      "1" "$(post "$ADMIN" "conference/admin/speakers/$SPK/invite" '{}' >/dev/null; q "SELECT COUNT(*) FROM abs_emails WHERE template='conf_speaker_invite' AND to_email='spk-prof@resok.local'")"
check "the email greets them by title"                          "Dear Prof Mwangi" "$(q "SELECT text_body FROM abs_emails WHERE template='conf_speaker_invite' AND to_email='spk-prof@resok.local'")"
post "$ADMIN" conference/admin/speakers "{\"id\":$SPK,\"name\":\"TESTSPK Jane Mwangi\",\"email\":\"spk-other@resok.local\"}" >/dev/null
r=$(get "$PROF" conference/speaker/me)
check "changing the email moves the profile off the old account" 'not listed as a speaker' "$r"
r=$(get "$OTHER" conference/speaker/me)
check "to whoever owns the new email"                           'TESTSPK Jane Mwangi' "$r"
r=$(post "$CHAIR" "conference/admin/speakers/$SPK/delete" '{}')
absent "a speaker is deleted"                                  'TESTSPK Jane' "$r"
[ -f "$UPLOADS/$FILE" ] && check "with their photo" "deleted" "left behind" || check "with their photo" "x" "x"

cleanup
q "UPDATE conf_programme_state SET published = $SAVED_PUB WHERE event_id = $EV"
rm -rf "$TMP"; rm -f "$ADMIN" "$PROF" "$OTHER" "$CHAIR"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
