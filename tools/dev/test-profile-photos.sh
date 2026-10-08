#!/usr/bin/env bash
#
# Profile photos: a member sees their own, nobody else's; admins see any; nobody signed out
# sees one; and the response never invites a shared cache to keep it.
#
# Until 9 Oct 2026 the owner lookup searched for the bare file name while uploads store
# 'profile-images/<file>', so no member could see the photo they had just uploaded.
#
# Run from the repository root:  ./tools/dev/test-profile-photos.sh
set -uo pipefail

API="http://localhost:8081/resok-portal/public/api/index.php?route="
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root resok_portal"
PHP="/c/xampp/php/php.exe"
pass=0; fail=0

check() {
    if [[ "$3" == *"$2"* ]]; then printf '  %-56s ok\n' "$1"; pass=$((pass+1))
    else printf '  %-56s FAIL\n      wanted: %s\n      got:    %.160s\n' "$1" "$2" "$3"; fail=$((fail+1)); fi
}

HASH=$($PHP -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);" 2>/dev/null | grep -v Warning)
$MYSQL -e "
DELETE FROM users WHERE email IN ('photo-a@resok.local','photo-b@resok.local','photo-admin@resok.local');
INSERT INTO users (email,password_hash,email_verified,role) VALUES
 ('photo-a@resok.local','$HASH',1,'member'),
 ('photo-b@resok.local','$HASH',1,'member'),
 ('photo-admin@resok.local','$HASH',1,'admin');" >/dev/null
A_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='photo-a@resok.local';")
B_ID=$($MYSQL -N -e "SELECT id FROM users WHERE email='photo-b@resok.local';")
$MYSQL -e "INSERT INTO member_profiles (user_id,first_name,surname,membership_status) VALUES
 ($A_ID,'Photo','Owner','active'), ($B_ID,'Other','Member','active');" >/dev/null

login() { local j; j=$(mktemp); curl -s -c "$j" -X POST "${API}auth/login" -H 'Content-Type: application/json' \
          -d "{\"email\":\"$1\",\"password\":\"TestPass123\"}" >/dev/null; echo "$j"; }
A=$(login photo-a@resok.local); B=$(login photo-b@resok.local); ADM=$(login photo-admin@resok.local)

IMG=$(cygpath -m "$(mktemp --suffix=.png)")   # curl here is a Windows build: it needs C:/ paths
# A 1x1 PNG, written directly so the test needs no image extension.
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' | base64 -d > "$IMG"
UP=$(curl -s -b "$A" -X POST "${API}members/me/profile-image" -F "profileImage=@$IMG;type=image/png;filename=me.png")
check "the upload is accepted"                             'profileImageUrl' "$UP"
URL=$(echo "$UP" | $PHP -r 'preg_match("#route=(profile-images/[^\"]+)#", stream_get_contents(STDIN), $m); echo stripslashes($m[1] ?? "");')
check "it comes back as an API address"                    'profile-images/' "$URL"

status() { curl -s -o /dev/null -w '%{http_code}' ${1:+-b "$1"} "${API}$URL"; }
check "the owner sees their own photo"                     '200' "$(status "$A")"
check "another member does not"                            '404' "$(status "$B")"
check "an admin does"                                      '200' "$(status "$ADM")"
check "nobody signed out does"                             '401' "$(status "")"
HEADERS=$(curl -s -D - -o /dev/null -b "$A" "${API}$URL")
check "a shared cache is told not to keep it"              'private' "$HEADERS"
check "it is served as an image"                           'image/png' "$HEADERS"

FILE=$($MYSQL -N -e "SELECT profile_image FROM member_profiles WHERE user_id=$A_ID;")
rm -f "resok-portal/uploads/$FILE" "$IMG"
$MYSQL -e "DELETE FROM member_profiles WHERE user_id IN ($A_ID,$B_ID);
           DELETE FROM users WHERE email IN ('photo-a@resok.local','photo-b@resok.local','photo-admin@resok.local');" >/dev/null

echo
echo "$pass passed, $fail failed"
[[ $fail -eq 0 ]]
