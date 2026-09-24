#!/usr/bin/env bash
# End-to-end smoke test against the local API.
set -u
API="http://127.0.0.1:8080/api/v1"
PASS=0; FAIL=0
mysql -u root -e "DELETE FROM pitchrooms_dev.rate_limits;" 2>/dev/null

j() { python -c "import sys,json;d=json.load(sys.stdin);print(json.dumps(d))" 2>/dev/null; }
get() { python -c "import sys,json;d=json.load(sys.stdin);
ks='$1'.split('.')
for k in ks:
    d = d[int(k)] if isinstance(d,list) else d.get(k)
    if d is None: break
print(d if d is not None else '')" 2>/dev/null; }

check() { # name expected actual
  if [ "$2" = "$3" ]; then echo "  PASS  $1"; PASS=$((PASS+1));
  else echo "  FAIL  $1 (expected '$2', got '$3')"; FAIL=$((FAIL+1)); fi
}

echo "== auth =="
LOGIN=$(curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"brand@pitchrooms.demo","password":"demo123"}')
BRAND_TOKEN=$(echo "$LOGIN" | get data.session.accessToken)
BRAND_ID=$(echo "$LOGIN" | get data.user.id)
check "brand login returns token" "true" "$([ -n "$BRAND_TOKEN" ] && echo true || echo false)"

BAD=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"brand@pitchrooms.demo","password":"wrong"}')
check "wrong password rejected" "401" "$BAD"

AGENCY=$(curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"agency@pitchrooms.demo","password":"demo123"}')
AGENCY_TOKEN=$(echo "$AGENCY" | get data.session.accessToken)
AGENCY_ID=$(echo "$AGENCY" | get data.user.id)

EMP=$(curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"employee@pitchrooms.demo","password":"demo123"}')
EMP_TOKEN=$(echo "$EMP" | get data.session.accessToken)
EMP_ID=$(echo "$EMP" | get data.user.id)

ADMIN=$(curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"admin@pitchrooms.demo","password":"admin123"}')
ADMIN_TOKEN=$(echo "$ADMIN" | get data.session.accessToken)

NOAUTH=$(curl -s -o /dev/null -w '%{http_code}' "$API/auth/me")
check "unauthenticated /auth/me is 401" "401" "$NOAUTH"

ME=$(curl -s "$API/auth/me" -H "Authorization: Bearer $BRAND_TOKEN" | get data.user.role)
check "/auth/me resolves role" "brand" "$ME"

echo "== opportunities =="
CREATE=$(curl -s -X POST "$API/opportunities" -H "Authorization: Bearer $BRAND_TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"Smoke test campaign brief","description":"A brief created by the automated smoke test to verify the full flow end to end.","budget":"$5,000","skills":["Branding","Social"],"preferredSellers":"Top 5","deadline":"2027-01-01T00:00:00Z"}')
OPP_ID=$(echo "$CREATE" | get data.id)
check "brand creates opportunity" "true" "$([ -n "$OPP_ID" ] && echo true || echo false)"

SELLER_CREATE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/opportunities" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"Seller should not post this","description":"Sellers must not be able to create opportunities at all."}')
check "seller cannot post opportunity" "403" "$SELLER_CREATE"

VALID=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/opportunities" -H "Authorization: Bearer $BRAND_TOKEN" -H 'Content-Type: application/json' -d '{"title":"x"}')
check "validation rejects bad payload" "400" "$VALID"

echo "== proposals =="
PROP=$(curl -s -X POST "$API/proposals" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"opportunityId\":\"$OPP_ID\",\"coverLetter\":\"We would love to pitch this.\",\"bid\":\"\$4,800\",\"timeline\":\"6 weeks\"}")
PROP_ID=$(echo "$PROP" | get data.id)
check "agency submits proposal" "true" "$([ -n "$PROP_ID" ] && echo true || echo false)"

DUP=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/proposals" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"opportunityId\":\"$OPP_ID\",\"coverLetter\":\"Second attempt\"}")
check "duplicate proposal blocked (409)" "409" "$DUP"

SCORE=$(curl -s "$API/proposals/$PROP_ID" -H "Authorization: Bearer $AGENCY_TOKEN" | get data.matchScore)
check "match score computed server-side" "true" "$([ -n "$SCORE" ] && [ "$SCORE" -gt 0 ] && echo true || echo false)"

NOPROP=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/proposals" -H "Authorization: Bearer $EMP_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"opportunityId\":\"$OPP_ID\"}")
check "employee without resume blocked (422)" "422" "$NOPROP"

echo "== shortlisting =="
RANK=$(curl -s "$API/opportunities/$OPP_ID/ranking" -H "Authorization: Bearer $BRAND_TOKEN" | get data.ranking.0.sellerId)
check "ranking returns the agency" "$AGENCY_ID" "$RANK"

SL=$(curl -s -X POST "$API/opportunities/$OPP_ID/shortlist/auto" -H "Authorization: Bearer $BRAND_TOKEN" | get data.shortlisted)
check "auto-shortlist runs" "1" "$SL"

OTHER=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/opportunities/$OPP_ID/shortlist/auto" -H "Authorization: Bearer $AGENCY_TOKEN")
check "non-owner cannot shortlist" "403" "$OTHER"

curl -s -X POST "$API/opportunities/$OPP_ID/shortlist/confirm" -H "Authorization: Bearer $BRAND_TOKEN" > /dev/null

echo "== events =="
EV=$(curl -s -X POST "$API/events" -H "Authorization: Bearer $BRAND_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"opportunityId\":\"$OPP_ID\",\"startAt\":\"2027-02-01T10:00:00Z\",\"title\":\"Smoke test pitch room\"}")
EV_ID=$(echo "$EV" | get data.id)
check "buyer schedules pitch room" "true" "$([ -n "$EV_ID" ] && echo true || echo false)"

PAST=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/events" -H "Authorization: Bearer $BRAND_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"opportunityId\":\"$OPP_ID\",\"startAt\":\"2020-01-01T10:00:00Z\"}")
check "past start time rejected" "400" "$PAST"

echo "== room entry gate =="
GATE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/events/$EV_ID/access/grant" -H "Authorization: Bearer $AGENCY_TOKEN")
check "gate blocks entry before checks" "403" "$GATE"

CODE=$(mysql -u root -N -B -e "SELECT meeting_code FROM pitchrooms_dev.events WHERE id='$EV_ID';" 2>/dev/null)
BADCODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/events/$EV_ID/access/code" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' -d '{"code":"ZZZZZZ"}')
check "wrong meeting code rejected" "400" "$BADCODE"

OK=$(curl -s -X POST "$API/events/$EV_ID/access/code" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' -d "{\"code\":\"$CODE\"}" | get data.steps.code)
check "correct meeting code accepted" "True" "$OK"

echo "== pass gate =="
ACCESS=$(curl -s "$API/billing/access?eventId=$EV_ID" -H "Authorization: Bearer $AGENCY_TOKEN" | get data.hasAccess)
check "seller without pass has no access" "False" "$ACCESS"
BUYER_ACCESS=$(curl -s "$API/billing/access?eventId=$EV_ID" -H "Authorization: Bearer $BRAND_TOKEN" | get data.hasAccess)
check "buyer bypasses pass gate" "True" "$BUYER_ACCESS"

PLANS=$(curl -s "$API/billing/plans" -H "Authorization: Bearer $AGENCY_TOKEN" | get data.0.priceFormatted)
check "plans load from DB" "\$499" "$PLANS"

CHECKOUT=$(curl -s -X POST "$API/billing/checkout" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d '{"kind":"pass","planId":"monthly_unlimited"}')
PAY_ID=$(echo "$CHECKOUT" | get data.paymentId)
check "checkout creates payment intent" "true" "$([ -n "$PAY_ID" ] && echo true || echo false)"

STILL=$(curl -s "$API/billing/access?eventId=$EV_ID" -H "Authorization: Bearer $AGENCY_TOKEN" | get data.hasAccess)
check "checkout alone grants nothing" "False" "$STILL"

KEY=$(grep '^SCHEDULER_KEY=' .env | cut -d= -f2)
curl -s -X POST "$API/billing/webhook" -H 'Content-Type: application/json' -H "X-Webhook-Key: $KEY" \
  -d "{\"paymentId\":\"$PAY_ID\",\"providerRef\":\"smoke-test\"}" > /dev/null
NOW=$(curl -s "$API/billing/access?eventId=$EV_ID" -H "Authorization: Bearer $AGENCY_TOKEN" | get data.hasAccess)
check "settled webhook activates pass" "True" "$NOW"

UNSIGNED=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/billing/webhook" -H 'Content-Type: application/json' -d "{\"paymentId\":\"$PAY_ID\"}")
check "unsigned webhook rejected" "403" "$UNSIGNED"

echo "== messaging gate =="
LOCKED=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/threads" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"counterpartyId\":\"$BRAND_ID\"}")
check "messaging locked before pitch" "403" "$LOCKED"

mysql -u root -e "UPDATE pitchrooms_dev.events SET status='completed', ended_at=UTC_TIMESTAMP() WHERE id='$EV_ID';" 2>/dev/null
THREAD=$(curl -s -X POST "$API/threads" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"counterpartyId\":\"$BRAND_ID\"}")
THREAD_ID=$(echo "$THREAD" | get data.id)
check "messaging unlocks after completion" "true" "$([ -n "$THREAD_ID" ] && echo true || echo false)"

MSG=$(curl -s -X POST "$API/threads/$THREAD_ID/messages" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d '{"body":"Thanks for the pitch room today.","clientMsgId":"smoke-1"}' | get data.id)
check "message sends" "true" "$([ -n "$MSG" ] && echo true || echo false)"

MSG2=$(curl -s -X POST "$API/threads/$THREAD_ID/messages" -H "Authorization: Bearer $AGENCY_TOKEN" -H 'Content-Type: application/json' \
  -d '{"body":"Thanks for the pitch room today.","clientMsgId":"smoke-1"}' | get data.id)
check "duplicate clientMsgId is idempotent" "$MSG" "$MSG2"

COUNT=$(curl -s "$API/threads/$THREAD_ID/messages" -H "Authorization: Bearer $BRAND_TOKEN" | python -c "import sys,json;print(len(json.load(sys.stdin)['data']))")
check "counterparty reads one message" "1" "$COUNT"

OUTSIDER=$(curl -s -o /dev/null -w '%{http_code}' "$API/threads/$THREAD_ID/messages" -H "Authorization: Bearer $EMP_TOKEN")
check "outsider cannot read thread" "403" "$OUTSIDER"

echo "== notifications =="
NOTIF=$(curl -s "$API/notifications" -H "Authorization: Bearer $AGENCY_TOKEN" | get meta.total)
check "agency has notifications" "true" "$([ "$NOTIF" -gt 0 ] && echo true || echo false)"

POLL=$(curl -s "$API/realtime/poll?since=2020-01-01T00:00:00Z" -H "Authorization: Bearer $BRAND_TOKEN" | get data.unreadCount)
check "realtime poll responds" "true" "$([ -n "$POLL" ] && echo true || echo false)"

echo "== admin =="
STATS=$(curl -s "$API/admin/stats" -H "Authorization: Bearer $ADMIN_TOKEN" | get data.users)
check "admin stats" "true" "$([ "$STATS" -ge 7 ] && echo true || echo false)"
NONADMIN=$(curl -s -o /dev/null -w '%{http_code}' "$API/admin/stats" -H "Authorization: Bearer $BRAND_TOKEN")
check "non-admin blocked from admin" "403" "$NONADMIN"

echo "== scheduler =="
TICK=$(curl -s -X POST "$API/internal/scheduler/tick" -H "X-Scheduler-Key: $KEY" -H 'Content-Type: application/json' -d '{"force":true}')
check "scheduler tick authorised" "True" "$(echo "$TICK" | get ok)"
BADTICK=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/internal/scheduler/tick" -H 'X-Scheduler-Key: nope')
check "scheduler rejects bad key" "403" "$BADTICK"

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
