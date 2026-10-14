#!/usr/bin/env bash
#
# Exercises Find a Specialist (lib/directory.php).
#
# What matters: nobody is listed without opting in, a lapsed member drops out, nothing
# private reaches the public, contact details come one listing at a time and are rate
# limited, the photo is only served when the member chose it, and an admin can hide a
# listing with a reason the member sees.
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
source "$(dirname "$0")/lib-session.sh"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      wanted: %s\n      got:    %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}
absent() {
    if [[ "$3" != *"$2"* ]]; then printf '  %-60s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-60s FAIL\n      did not want: %s\n      got: %.200s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

curl -s "${API}directory/search" >/dev/null   # creates the table on a fresh database
$MYSQL -e "
DELETE d FROM directory_listings d JOIN member_profiles mp ON mp.id = d.member_profile_id JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 'dir-%@resok.local';
DELETE mp FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE u.email LIKE 'dir-%@resok.local';
DELETE FROM users WHERE email LIKE 'dir-%@resok.local';
DELETE FROM auth_attempts;
INSERT INTO users (email,password_hash,email_verified,role) VALUES ('dir-a@resok.local','x',1,'member'),('dir-b@resok.local','x',1,'member'),('dir-lapsed@resok.local','x',1,'member'),('dir-nomember@resok.local','x',1,'member');
INSERT INTO member_profiles (user_id,title,first_name,surname,mobile,membership_status,id_number,physical_address,profile_image,specialization,institution,county)
 SELECT id,'Dr','Zawadi','Testlisted','+254700000001','active','SECRET-ID-123','42 Private Lane','profile-images/none.jpg','Interstitial lung disease','Testville Hospital','Kisumu' FROM users WHERE email='dir-a@resok.local';
INSERT INTO member_profiles (user_id,title,first_name,surname,mobile,membership_status) SELECT id,'Dr','Yusuf','Testunlisted','+254700000002','active' FROM users WHERE email='dir-b@resok.local';
INSERT INTO member_profiles (user_id,title,first_name,surname,mobile,membership_status) SELECT id,'Dr','Xavier','Testlapsed','+254700000003','active' FROM users WHERE email='dir-lapsed@resok.local';" 2>&1 | grep -v Warning
A_ID=$($MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='dir-a@resok.local'")
L_ID=$($MYSQL -N -e "SELECT mp.id FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE u.email='dir-lapsed@resok.local'")

A=$(minted_session dir-a@resok.local)
B=$(minted_session dir-b@resok.local)
L=$(minted_session dir-lapsed@resok.local)
N=$(minted_session dir-nomember@resok.local)
ADMIN=$(minted_session dev@resok.local)
get()  { curl -s -b "$1" --max-time 15 "${API}$2"; }
post() { curl -s -b "$1" -X POST --max-time 15 "${API}$2" -H 'Content-Type: application/json' -d "${3:-{\}}"; }
LISTING='{"listed":true,"category":"pulmonologist","specialties":"Asthma, COPD, interstitial lung disease","locations":[{"facility":"Testville Hospital","town":"Kisumu","county":"Kisumu"},{"facility":"Lakeside Clinic","town":"Kisumu","county":"Kisumu"}],"publicPhone":"0712 000 111","publicEmail":"clinic@example.com","website":"https://example.com","bio":"Chest physician.","languages":"English, Kiswahili, Dholuo","acceptingReferrals":true,"showPhoto":false,"consent":true}'

echo "Opting in:"
check "a member sees profile suggestions"             'Interstitial lung disease' "$(get "$A" directory/me)"
check "an account with no membership is turned away"  'No membership'      "$(get "$N" directory/me)"
check "listing without consent is refused"            'confirm'            "$(post "$A" directory/me "${LISTING/\"consent\":true/\"consent\":false}")"
check "listing without a location is refused"         'at least one place' "$(post "$A" directory/me '{"listed":true,"category":"pulmonologist","consent":true}')"
check "a bad website is refused"                      'https://'           "$(post "$A" directory/me "${LISTING/https:\/\/example.com/javascript:alert(1)}")"
SAVED=$(post "$A" directory/me "$LISTING")
check "a complete listing is saved"                   '"listed":true'      "$SAVED"
check "the phone is normalised to +254"               '+254712000111'      "$SAVED"
post "$L" directory/me "${LISTING/Asthma/Xavier-only}" >/dev/null
$MYSQL -e "UPDATE member_profiles SET membership_status='expired' WHERE id=$L_ID"
check "a lapsed member cannot switch a listing on"    'Only active members' "$(post "$L" directory/me "$LISTING")"

echo "Searching:"
ALL=$(curl -s "${API}directory/search")
check "the opted-in member appears"                   'Zawadi'             "$ALL"
absent "a member who did not opt in does not"         'Testunlisted'       "$ALL"
absent "a lapsed member drops out"                    'Testlapsed'         "$ALL"
absent "search results carry no phone number"         '+254712000111'      "$ALL"
absent "nor the public email"                         'clinic@example.com' "$ALL"
absent "nor anything private from the profile"        'SECRET-ID'          "$ALL"
absent "nor the home address"                         'Private Lane'       "$ALL"
absent "nor the personal mobile"                      '+254700000001'      "$ALL"
check "search by condition finds them"                'Zawadi'             "$(curl -s "${API}directory/search&q=COPD")"
check "search by facility finds them"                 'Zawadi'             "$(curl -s "${API}directory/search&q=Lakeside")"
check "the county filter finds them"                  'Zawadi'             "$(curl -s "${API}directory/search&county=Kisumu")"
absent "another county does not"                      'Zawadi'             "$(curl -s "${API}directory/search&county=Mombasa")"
absent "another category does not"                    'Zawadi'             "$(curl -s "${API}directory/search&category=radiologist")"
check "Kisumu is offered as a county filter"          '"Kisumu"'           "$ALL"
check "a wildcard search is treated as text"          '"total":0'          "$(curl -s "${API}directory/search&q=%25%25%25zzz")"

echo "Contact details and photo:"
check "contacts come one listing at a time"           'clinic@example.com' "$(curl -s "${API}directory/$A_ID/contact")"
check "an unlisted member's contacts are not served"  'no longer available' "$(curl -s "${API}directory/$L_ID/contact")"
check "the photo is not served when not chosen"       'Not found'          "$(curl -s "${API}directory/photo/$A_ID")"
for i in $(seq 1 45); do curl -s -o /dev/null "${API}directory/$A_ID/contact"; done
check "a script collecting contacts is stopped"       'Too many attempts'  "$(curl -s "${API}directory/$A_ID/contact")"
$MYSQL -e "DELETE FROM auth_attempts"

echo "Admin:"
check "a member cannot reach the admin list"          'error'              "$(get "$A" directory/admin)"
check "an admin sees the listing"                     'Zawadi'             "$(get "$ADMIN" directory/admin)"
check "hiding needs a reason"                         'reason'             "$(post "$ADMIN" "directory/admin/$A_ID/hide" '{"hidden":true}')"
post "$ADMIN" "directory/admin/$A_ID/hide" '{"hidden":true,"reason":"Please correct the facility name."}' >/dev/null
absent "a hidden listing leaves the directory"        'Zawadi'             "$(curl -s "${API}directory/search")"
check "the member sees why"                           'correct the facility' "$(get "$A" directory/me)"
post "$ADMIN" "directory/admin/$A_ID/hide" '{"hidden":false}' >/dev/null
check "unhiding brings it back"                       'Zawadi'             "$(curl -s "${API}directory/search")"

echo "Opting out:"
post "$A" directory/me "${LISTING/\"listed\":true/\"listed\":false}" >/dev/null
absent "switching off removes the listing at once"    'Zawadi'             "$(curl -s "${API}directory/search")"

rm -f "$A" "$B" "$L" "$N" "$ADMIN"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
