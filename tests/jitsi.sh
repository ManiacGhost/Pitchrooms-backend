#!/usr/bin/env bash
# Verifies the live-room token path: the entry gate, the JaaS tenant prefix,
# the RS256 signature, and the room claim.
set -u
API="http://127.0.0.1:8080/api/v1"
DB=pitchrooms_dev
mysql -u root -e "DELETE FROM $DB.rate_limits;" 2>/dev/null

get() { python -c "import sys,json;d=json.load(sys.stdin)
ks='$1'.split('.')
for k in ks:
    d = d[int(k)] if isinstance(d,list) else d.get(k)
    if d is None: break
print(d if d is not None else '')" 2>/dev/null; }

tok() { curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$1\",\"password\":\"$2\"}" | get data.session.accessToken; }

PASS=0; FAIL=0
check() {
  if [ "$2" = "$3" ]; then echo "  PASS  $1"; PASS=$((PASS+1));
  else echo "  FAIL  $1 (expected '$2', got '$3')"; FAIL=$((FAIL+1)); fi
}

AGENCY_T=$(tok agency@pitchrooms.demo demo123)
AG_ID=$(mysql -u root -N -B -e "SELECT id FROM $DB.users WHERE role='agency' LIMIT 1;")
EV=$(mysql -u root -N -B -e "SELECT id FROM $DB.events WHERE vertical='ab' ORDER BY created_at DESC LIMIT 1;")

echo "== gate must be passed before a token is issued =="
NOGATE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/meetings/$EV/token" -H "Authorization: Bearer $AGENCY_T")
check "token refused before the gate" "403" "$NOGATE"

# Give the seller a pass, then satisfy the four gate checks.
KEY=$(grep '^SCHEDULER_KEY=' .env | cut -d= -f2)
PID=$(curl -s -X POST "$API/billing/checkout" -H "Authorization: Bearer $AGENCY_T" -H 'Content-Type: application/json' \
  -d '{"kind":"pass","planId":"monthly_unlimited"}' | get data.paymentId)
curl -s -X POST "$API/billing/webhook" -H 'Content-Type: application/json' -H "X-Webhook-Key: $KEY" \
  -d "{\"paymentId\":\"$PID\"}" > /dev/null

CODE=$(mysql -u root -N -B -e "SELECT meeting_code FROM $DB.events WHERE id='$EV';")
curl -s -X POST "$API/events/$EV/access/code" -H "Authorization: Bearer $AGENCY_T" \
  -H 'Content-Type: application/json' -d "{\"code\":\"$CODE\"}" > /dev/null
# OTP delivery is out of scope here — mark those two steps directly.
mysql -u root -e "UPDATE $DB.meeting_access SET email_otp_ok=1, mobile_otp_ok=1 WHERE event_id='$EV' AND user_id='$AG_ID';"
curl -s -X POST "$API/events/$EV/access/device" -H "Authorization: Bearer $AGENCY_T" \
  -H 'Content-Type: application/json' -d '{"deviceFingerprint":"DEV-TEST-0001","cameraOk":true}' > /dev/null

GRANT=$(curl -s -X POST "$API/events/$EV/access/grant" -H "Authorization: Bearer $AGENCY_T")
check "gate grants once all four checks pass" "True" "$(echo "$GRANT" | get secured)$(echo "$GRANT" | get data.secured)"

echo "== token shape =="
T=$(curl -s -X POST "$API/meetings/$EV/token" -H "Authorization: Bearer $AGENCY_T")
DOMAIN=$(echo "$T" | get data.domain)
ROOMNAME=$(echo "$T" | get data.roomName)
ROOM=$(echo "$T" | get data.room)
TENANT=$(echo "$T" | get data.tenant)
JWT=$(echo "$T" | get data.jwt)
SECURED=$(echo "$T" | get data.secured)

check "domain is the JaaS host" "8x8.vc" "$DOMAIN"
check "token is secured" "True" "$SECURED"
check "roomName is tenant-prefixed" "$TENANT/$ROOM" "$ROOMNAME"
check "jwt present" "true" "$([ -n "$JWT" ] && echo true || echo false)"

echo "== jwt contents =="
PYOUT=$(PYTHONIOENCODING=utf-8 python -c "
import base64, json, sys
jwt='''$JWT'''
def d(seg):
    seg += '=' * (-len(seg) % 4)
    return json.loads(base64.urlsafe_b64decode(seg))
h, p = d(jwt.split('.')[0]), d(jwt.split('.')[1])
print(h.get('alg'))
print(h.get('kid'))
print(p.get('room'))
print(p.get('sub'))
print(p.get('aud'))
print(str(p.get('context',{}).get('user',{}).get('moderator')))
")
ALG=$(echo "$PYOUT" | sed -n 1p)
KID=$(echo "$PYOUT" | sed -n 2p)
JROOM=$(echo "$PYOUT" | sed -n 3p)
JSUB=$(echo "$PYOUT" | sed -n 4p)
JAUD=$(echo "$PYOUT" | sed -n 5p)
JMOD=$(echo "$PYOUT" | sed -n 6p)

check "alg is RS256" "RS256" "$ALG"
check "kid is <appId>/<keyId>" "$(grep '^JITSI_API_KEY=' .env | cut -d= -f2)" "$KID"
check "room claim is the bare room" "$ROOM" "$JROOM"
check "sub is the app id" "$(grep '^JITSI_APP_ID=' .env | cut -d= -f2)" "$JSUB"
check "aud is jitsi" "jitsi" "$JAUD"
check "seller is not a moderator" "false" "$JMOD"

echo "== signature verifies against the JaaS public key =="
VERIFY=$(php -r '
$jwt = trim($argv[1]);
[$h,$p,$s] = explode(".", $jwt);
$sig = base64_decode(strtr($s, "-_", "+/") . str_repeat("=", (4 - strlen($s) % 4) % 4));
$pub = openssl_pkey_get_public(file_get_contents(__DIR__."/storage/keys/publickey.pub"));
echo openssl_verify("$h.$p", $sig, $pub, OPENSSL_ALGO_SHA256) === 1 ? "ok" : "bad";
' "$JWT")
check "RS256 signature valid" "ok" "$VERIFY"

echo "== buyer gets moderator =="
BRAND_T=$(tok brand@pitchrooms.demo demo123)
BR_ID=$(mysql -u root -N -B -e "SELECT id FROM $DB.users WHERE role='brand' LIMIT 1;")
curl -s -X POST "$API/events/$EV/access/code" -H "Authorization: Bearer $BRAND_T" \
  -H 'Content-Type: application/json' -d "{\"code\":\"$CODE\"}" > /dev/null
mysql -u root -e "UPDATE $DB.meeting_access SET email_otp_ok=1, mobile_otp_ok=1, camera_ok=1 WHERE event_id='$EV' AND user_id='$BR_ID';"
curl -s -X POST "$API/events/$EV/access/grant" -H "Authorization: Bearer $BRAND_T" > /dev/null
BMOD=$(curl -s -X POST "$API/meetings/$EV/token" -H "Authorization: Bearer $BRAND_T" | get data.moderator)
check "buyer is moderator" "True" "$BMOD"

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
