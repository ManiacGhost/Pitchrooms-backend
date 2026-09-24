#!/usr/bin/env bash
# Verifies the server-authoritative room clock and the in-code scheduler.
set -u
API="http://127.0.0.1:8080/api/v1"
DB=pitchrooms_dev
mysql -u root -e "DELETE FROM $DB.rate_limits;" 2>/dev/null

get() { python -c "import sys,json;d=json.load(sys.stdin);
ks='$1'.split('.')
for k in ks:
    d = d[int(k)] if isinstance(d,list) else d.get(k)
    if d is None: break
print(d if d is not None else '')" 2>/dev/null; }

tok() { curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$1\",\"password\":\"$2\"}" | get data.session.accessToken; }

BRAND_T=$(tok brand@pitchrooms.demo demo123)
AGENCY_T=$(tok agency@pitchrooms.demo demo123)

# Use the seeded AB pitch room.
EV=$(mysql -u root -N -B -e "SELECT id FROM $DB.events WHERE vertical='ab' AND status='scheduled' ORDER BY created_at DESC LIMIT 1;")
echo "event: $EV"

# Short stages so the test runs quickly.
mysql -u root -e "UPDATE $DB.event_agenda SET presentation_seconds=5, qa_seconds=5 WHERE event_id='$EV';"

echo "== stage clock =="
curl -s -X POST "$API/events/$EV/status" -H "Authorization: Bearer $BRAND_T" \
  -H 'Content-Type: application/json' -d '{"status":"live"}' > /dev/null

S1=$(curl -s "$API/meetings/$EV/state" -H "Authorization: Bearer $BRAND_T")
echo "  stage now:      $(echo "$S1" | get data.stage)  remaining=$(echo "$S1" | get data.remainingSeconds)s"

# Both participants must read the same clock — this is the drift fix.
A=$(curl -s "$API/meetings/$EV/state" -H "Authorization: Bearer $AGENCY_T" | get data.stageEndsAt)
B=$(curl -s "$API/meetings/$EV/state" -H "Authorization: Bearer $BRAND_T" | get data.stageEndsAt)
[ "$A" = "$B" ] && echo "  PASS  both participants share one stage deadline" \
                || echo "  FAIL  deadlines differ ($A vs $B)"

sleep 7
S2=$(curl -s "$API/meetings/$EV/state" -H "Authorization: Bearer $BRAND_T" | get data.stage)
[ "$S2" = "qa" ] && echo "  PASS  presentation auto-advanced to qa" || echo "  FAIL  stage is '$S2', expected qa"

sleep 7
S3=$(curl -s "$API/meetings/$EV/state" -H "Authorization: Bearer $BRAND_T" | get data.stage)
[ "$S3" = "completed" ] && echo "  PASS  room completed after last presenter" || echo "  FAIL  stage is '$S3'"

EVSTATUS=$(mysql -u root -N -B -e "SELECT status FROM $DB.events WHERE id='$EV';")
[ "$EVSTATUS" = "evaluation" ] && echo "  PASS  event moved to evaluation" || echo "  FAIL  event status '$EVSTATUS'"

echo "== moderator control =="
NOTHOST=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/meetings/$EV/stage/advance" -H "Authorization: Bearer $AGENCY_T")
[ "$NOTHOST" = "403" ] && echo "  PASS  seller cannot drive the agenda" || echo "  FAIL  got $NOTHOST"

echo "== evaluation + decision cascade =="
AG_ID=$(mysql -u root -N -B -e "SELECT id FROM $DB.users WHERE role='agency' LIMIT 1;")
curl -s -X POST "$API/events/$EV/evaluations" -H "Authorization: Bearer $BRAND_T" -H 'Content-Type: application/json' \
  -d "{\"sellerId\":\"$AG_ID\",\"industryExpertise\":9,\"creativity\":8,\"teamConfidence\":9,\"communication\":8,\"caseStudies\":7,\"commercialFit\":9,\"notes\":\"Strong pitch.\"}" > /dev/null
AVG=$(curl -s "$API/events/$EV/leaderboard" -H "Authorization: Bearer $BRAND_T" | get data.0.average)
echo "  leaderboard average: $AVG"
[ -n "$AVG" ] && echo "  PASS  scorecard aggregates" || echo "  FAIL  no leaderboard"

SELLER_VIEW=$(curl -s -o /dev/null -w '%{http_code}' "$API/events/$EV/evaluations" -H "Authorization: Bearer $AGENCY_T")
[ "$SELLER_VIEW" = "403" ] && echo "  PASS  seller cannot read raw scorecards" || echo "  FAIL  got $SELLER_VIEW"

OPP=$(mysql -u root -N -B -e "SELECT opportunity_id FROM $DB.events WHERE id='$EV';")
curl -s -X POST "$API/events/$EV/decision" -H "Authorization: Bearer $BRAND_T" -H 'Content-Type: application/json' \
  -d "{\"outcome\":\"won\",\"winnerId\":\"$AG_ID\",\"note\":\"Best commercial fit.\"}" > /dev/null

PSTATUS=$(mysql -u root -N -B -e "SELECT status FROM $DB.proposals WHERE opportunity_id='$OPP' AND seller_id='$AG_ID';")
[ "$PSTATUS" = "accepted" ] && echo "  PASS  winner's proposal accepted" || echo "  FAIL  proposal is '$PSTATUS'"

OSTATUS=$(mysql -u root -N -B -e "SELECT status FROM $DB.opportunities WHERE id='$OPP';")
[ "$OSTATUS" = "Completed" ] && echo "  PASS  opportunity closed by cascade" || echo "  FAIL  opportunity is '$OSTATUS'"

echo "== in-code scheduler =="
KEY=$(grep '^SCHEDULER_KEY=' .env | cut -d= -f2)
mysql -u root -e "UPDATE $DB.scheduler_locks SET last_tick_at=NULL;" 2>/dev/null

# A room whose start time has passed should be opened by the scheduler alone.
EV2=$(mysql -u root -N -B -e "SELECT id FROM $DB.events WHERE vertical='ee' ORDER BY created_at DESC LIMIT 1;")
mysql -u root -e "UPDATE $DB.events SET status='scheduled', start_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id='$EV2';"; mysql -u root -e "UPDATE $DB.scheduled_tasks SET next_run_at=NULL;"
curl -s -X POST "$API/internal/scheduler/tick" -H "X-Scheduler-Key: $KEY" -H 'Content-Type: application/json' -d '{"force":true}' > /dev/null
ST=$(mysql -u root -N -B -e "SELECT status FROM $DB.events WHERE id='$EV2';")
[ "$ST" = "live" ] && echo "  PASS  autostart job opened a due room" || echo "  FAIL  event is '$ST'"

JOBS=$(mysql -u root -N -B -e "SELECT COUNT(*) FROM $DB.scheduled_jobs WHERE status='completed';")
echo "  completed jobs: $JOBS"
[ "$JOBS" -gt 0 ] && echo "  PASS  jobs ran and recorded" || echo "  FAIL  no completed jobs"

FAILED=$(mysql -u root -N -B -e "SELECT COUNT(*) FROM $DB.scheduled_jobs WHERE status='failed';")
[ "$FAILED" = "0" ] && echo "  PASS  no failed jobs" || echo "  FAIL  $FAILED job(s) failed: $(mysql -u root -N -B -e "SELECT DISTINCT handler, last_error FROM $DB.scheduled_jobs WHERE status='failed' LIMIT 3;")"

# Pass expiry is a scheduled task, not a request-time check.
# Buy a pass first so there is something to expire.
PID=$(curl -s -X POST "$API/billing/checkout" -H "Authorization: Bearer $AGENCY_T" -H 'Content-Type: application/json'   -d '{"kind":"pass","planId":"monthly_unlimited"}' | get data.paymentId)
curl -s -X POST "$API/billing/webhook" -H 'Content-Type: application/json' -H "X-Webhook-Key: $KEY"   -d "{\"paymentId\":\"$PID\"}" > /dev/null
ACTIVE=$(mysql -u root -N -B -e "SELECT COUNT(*) FROM $DB.passes WHERE status='active';")
[ "$ACTIVE" -gt 0 ] && echo "  PASS  webhook activated a pass" || echo "  FAIL  no active pass"

mysql -u root -e "UPDATE $DB.passes SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE status='active';"
mysql -u root -e "UPDATE $DB.scheduler_locks SET last_tick_at=NULL; UPDATE $DB.scheduled_tasks SET next_run_at=NULL;"
# Job unique keys are second-granular, so a tick in the same second as the
# previous one is deduped by design. Wait a second to force a fresh job.
sleep 1
curl -s -X POST "$API/internal/scheduler/tick" -H "X-Scheduler-Key: $KEY" -H 'Content-Type: application/json' -d '{"force":true}' > /dev/null
EXPIRED=$(mysql -u root -N -B -e "SELECT COUNT(*) FROM $DB.passes WHERE status='expired';")
[ "$EXPIRED" -gt 0 ] && echo "  PASS  pass expiry job ran" || echo "  FAIL  no passes expired"
