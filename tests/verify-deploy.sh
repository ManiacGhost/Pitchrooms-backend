#!/usr/bin/env bash
# ===========================================================================
# Post-deployment verification for a live PitchRooms install.
#
#   bash tests/verify-deploy.sh https://api.yourdomain.com https://yourdomain.com
#
# Read-only by default: it creates nothing and changes nothing. Pass --full to
# also exercise a write path (registers a throwaway account, posts an
# opportunity) — only do that on a test database.
#
# Needs: curl, python. Runs from any machine, including Windows Git Bash.
# ===========================================================================
set -u

API_BASE="${1:-}"
APP_BASE="${2:-}"
FULL=0
for a in "$@"; do [ "$a" = "--full" ] && FULL=1; done

if [ -z "$API_BASE" ]; then
  echo "Usage: bash tests/verify-deploy.sh <api-url> [frontend-url] [--full]"
  echo "   eg: bash tests/verify-deploy.sh https://api.pitchrooms.com https://pitchrooms.com"
  exit 2
fi

API_BASE="${API_BASE%/}"
APP_BASE="${APP_BASE%/}"
API="$API_BASE/api/v1"

PASS=0; FAIL=0; WARN=0
ok()   { echo "  [ok]   $1"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $1"; FAIL=$((FAIL+1)); }
warn() { echo "  [warn] $1"; WARN=$((WARN+1)); }
head() { echo ""; echo "$1"; }

jget() { python -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: print(''); raise SystemExit
ks='$1'.split('.')
for k in ks:
    if d is None: break
    d = d[int(k)] if isinstance(d,list) else d.get(k)
print('' if d is None else d)
" 2>/dev/null; }

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@"; }

echo "==========================================================="
echo " PitchRooms deployment check"
echo " API:      $API_BASE"
[ -n "$APP_BASE" ] && echo " Frontend: $APP_BASE"
echo " Mode:     $([ "$FULL" = 1 ] && echo 'full (writes test data)' || echo 'read-only')"
echo "==========================================================="

# --------------------------------------------------------------- reachability
head "1. Reachability and TLS"

HEALTH=$(curl -s --max-time 20 "$API/health")
if [ -z "$HEALTH" ]; then
  bad "no response from $API/health — check the subdomain's document root points at pitchrooms-api/public"
  echo ""
  echo "RESULT: $PASS ok, $FAIL failed, $WARN warnings"
  exit 1
fi

if [ "$(echo "$HEALTH" | jget ok)" = "True" ]; then
  ok "/health responds and the database is reachable"
else
  bad "/health says the database is down: $(echo "$HEALTH" | jget data.error)"
  echo "$HEALTH" | head -c 400; echo ""
fi

PHPV=$(echo "$HEALTH" | jget data.php)
case "$PHPV" in
  8.1*|8.2*|8.3*|8.4*|8.5*) ok "PHP $PHPV" ;;
  "")                       warn "could not read the PHP version" ;;
  *)                        bad "PHP $PHPV is too old — this API needs 8.1 or newer (set it in hPanel > PHP Configuration)" ;;
esac

if [ "${API_BASE#https://}" != "$API_BASE" ]; then
  ok "API is served over HTTPS"
  HTTP_REDIR=$(code -I "http://${API_BASE#https://}/api/v1/health")
  case "$HTTP_REDIR" in
    301|302|308) ok "plain HTTP redirects to HTTPS ($HTTP_REDIR)" ;;
    *)           warn "plain HTTP returned $HTTP_REDIR instead of a redirect" ;;
  esac
else
  warn "API is not on HTTPS — tokens and OTPs would travel in clear text"
fi

# ------------------------------------------------------------------ config
head "2. Configuration"

ENVIRONMENT=$(echo "$HEALTH" | jget data.env)
[ "$ENVIRONMENT" = "production" ] && ok "APP_ENV=production" || warn "APP_ENV=$ENVIRONMENT (set it to production)"

DEBUG_LEAK=$(curl -s --max-time 20 "$API/opportunities/definitely-not-a-real-id" | jget error.fields.file)
[ -z "$DEBUG_LEAK" ] && ok "APP_DEBUG is off (no file paths in errors)" \
                     || bad "APP_DEBUG is ON — errors leak server paths ($DEBUG_LEAK). Set APP_DEBUG=false"

REPORTED_DNS=$(echo "$HEALTH" | jget data.appDns)
if [ "$REPORTED_DNS" = "$API_BASE" ]; then
  ok "APP_DNS matches this host"
else
  bad "APP_DNS is '$REPORTED_DNS' but the API answers on '$API_BASE' — file and email links will point at the wrong host"
fi

REPORTED_FE=$(echo "$HEALTH" | jget data.frontend)
if [ -n "$APP_BASE" ]; then
  [ "$REPORTED_FE" = "$APP_BASE" ] && ok "FRONTEND_DNS matches the frontend URL" \
    || bad "FRONTEND_DNS is '$REPORTED_FE' but the frontend is '$APP_BASE' — CORS will block the browser"
else
  echo "  [info] FRONTEND_DNS is '$REPORTED_FE'"
fi

WARNINGS=$(curl -s --max-time 20 "$API/health" | python -c "
import sys,json
try: w=json.load(sys.stdin)['data'].get('warnings') or []
except Exception: w=[]
print('\n'.join(w))
" 2>/dev/null)
if [ -z "$WARNINGS" ]; then
  ok "no configuration warnings reported"
else
  while IFS= read -r line; do [ -n "$line" ] && warn "$line"; done <<< "$WARNINGS"
fi

# ------------------------------------------------------------------ security
head "3. Files that must not be reachable"

for path in ".env" "composer.json" "src/Core/Kernel.php" "storage/logs" "database/install.sql" "storage/keys/privatekey.pk" "bin/migrate.php"; do
  STATUS=$(code "$API_BASE/$path")
  case "$STATUS" in
    200) bad "$path is publicly readable — the document root is wrong, point it at pitchrooms-api/public" ;;
    403|404) ok "$path is not reachable ($STATUS)" ;;
    *)   warn "$path returned $STATUS" ;;
  esac
done

NOSNIFF=$(curl -s -I --max-time 20 "$API/health" | grep -ci "x-content-type-options" || true)
[ "$NOSNIFF" -gt 0 ] && ok "security headers present" || warn "X-Content-Type-Options header missing (mod_headers may be off)"

# ---------------------------------------------------------------------- CORS
head "4. CORS (frontend to API)"

if [ -n "$APP_BASE" ]; then
  CORS_HEADER=$(curl -s -I --max-time 20 -H "Origin: $APP_BASE" "$API/health" | grep -i "access-control-allow-origin" | tr -d '\r' | awk '{print $2}')
  if [ "$CORS_HEADER" = "$APP_BASE" ]; then
    ok "API allows the frontend origin"
  else
    bad "no matching Access-Control-Allow-Origin for $APP_BASE (got '${CORS_HEADER:-none}') — set FRONTEND_DNS"
  fi

  PREFLIGHT=$(code -X OPTIONS -H "Origin: $APP_BASE" -H "Access-Control-Request-Method: POST" "$API/auth/login")
  case "$PREFLIGHT" in
    200|204) ok "preflight OPTIONS returns $PREFLIGHT" ;;
    *)       bad "preflight OPTIONS returned $PREFLIGHT — logins will fail in the browser" ;;
  esac

  EVIL=$(curl -s -I --max-time 20 -H "Origin: https://not-your-site.example" "$API/health" | grep -i "access-control-allow-origin" | tr -d '\r' | awk '{print $2}')
  [ -z "$EVIL" ] && ok "unknown origins are refused" || bad "CORS echoes any origin ('$EVIL') — too permissive"
else
  warn "frontend URL not given, skipping CORS checks"
fi

# ---------------------------------------------------------------- API surface
head "5. API behaviour"

ROUTING=$(code "$API/meta/taxonomies")
[ "$ROUTING" = "200" ] && ok "routing and rewrites work (/meta/taxonomies)" \
  || bad "/meta/taxonomies returned $ROUTING — check public/.htaccess and that mod_rewrite is on"

UNAUTH=$(code "$API/auth/me")
[ "$UNAUTH" = "401" ] && ok "protected routes reject anonymous callers (401)" \
  || bad "/auth/me returned $UNAUTH, expected 401"

NOTFOUND=$(code "$API/this-route-does-not-exist")
[ "$NOTFOUND" = "404" ] && ok "unknown routes return 404 JSON" || warn "unknown route returned $NOTFOUND"

PLANS_STATUS=$(code "$API/billing/plans")
[ "$PLANS_STATUS" = "401" ] && ok "billing plans require auth" || warn "/billing/plans returned $PLANS_STATUS"

AUTH_HDR=$(curl -s --max-time 20 "$API/auth/me" -H "Authorization: Bearer clearly-not-a-token" | jget error.code)
[ "$AUTH_HDR" = "UNAUTHENTICATED" ] && ok "Authorization header reaches PHP (bad token rejected cleanly)" \
  || warn "expected UNAUTHENTICATED for a bad token, got '${AUTH_HDR:-nothing}' — some hosts strip the header"

# ------------------------------------------------------------------ scheduler
head "6. Scheduler (cron in code)"

TICK=$(code -X POST "$API/internal/scheduler/tick" -H "X-Scheduler-Key: obviously-wrong")
[ "$TICK" = "403" ] && ok "scheduler endpoint rejects a wrong key" || warn "scheduler tick returned $TICK for a wrong key"
echo "  [info] to drive it externally: POST $API/internal/scheduler/tick with header X-Scheduler-Key"

# ------------------------------------------------------------------ frontend
if [ -n "$APP_BASE" ]; then
  head "7. Frontend"

  ROOT=$(code "$APP_BASE/")
  [ "$ROOT" = "200" ] && ok "frontend root loads" || bad "frontend root returned $ROOT"

  DEEP=$(code "$APP_BASE/employer/jobs")
  [ "$DEEP" = "200" ] && ok "deep link /employer/jobs serves the app (SPA rewrite works)" \
    || bad "deep link returned $DEEP — copy dist/.htaccess to the web root"

  CFG=$(curl -s --max-time 20 "$APP_BASE/config.js")
  if echo "$CFG" | grep -q "__PITCHROOMS_CONFIG__"; then
    ok "config.js is being served"
    CFG_API=$(echo "$CFG" | python -c "
import sys,re
s=sys.stdin.read()
m=re.search(r'apiDns:\s*\"([^\"]*)\"', s)
print(m.group(1) if m else '')
" 2>/dev/null)
    if [ -n "$CFG_API" ]; then
      [ "${CFG_API%/}" = "$API_BASE" ] && ok "config.js apiDns points at this API" \
        || bad "config.js apiDns is '$CFG_API' but the API is '$API_BASE'"
    else
      warn "config.js apiDns is empty — the frontend will use whatever was compiled in at build time"
    fi
  else
    bad "config.js missing from the web root — upload it with dist/"
  fi

  INDEX_CACHE=$(curl -s -I --max-time 20 "$APP_BASE/" | grep -i "cache-control" | tr -d '\r')
  echo "  [info] index cache-control: ${INDEX_CACHE:-not set}"
fi

# ------------------------------------------------------------------ full mode
if [ "$FULL" = 1 ]; then
  head "8. Write path (test data will be created)"

  STAMP=$(date +%s)
  EMAIL="deploy-check-$STAMP@example.com"

  REG=$(curl -s --max-time 30 -X POST "$API/auth/register" -H 'Content-Type: application/json' \
    -d "{\"role\":\"brand\",\"email\":\"$EMAIL\",\"password\":\"Test12345\",\"company\":\"Deploy Check $STAMP\",\"country\":\"India\"}")
  TOKEN=$(echo "$REG" | jget data.session.accessToken)

  if [ -n "$TOKEN" ]; then
    ok "registration works (created $EMAIL)"

    ME=$(curl -s --max-time 20 "$API/auth/me" -H "Authorization: Bearer $TOKEN" | jget data.user.role)
    [ "$ME" = "brand" ] && ok "token authenticates against /auth/me" || bad "/auth/me returned role '$ME'"

    OPP=$(curl -s --max-time 20 -X POST "$API/opportunities" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
      -d '{"title":"Deployment check brief","description":"Created by verify-deploy.sh to confirm writes reach MySQL. Safe to delete.","budget":"$1,000"}')
    OPP_ID=$(echo "$OPP" | jget data.id)
    [ -n "$OPP_ID" ] && ok "database writes succeed (opportunity $OPP_ID)" \
      || bad "could not create an opportunity: $(echo "$OPP" | jget error.message)"

    LOGIN=$(curl -s --max-time 20 -X POST "$API/auth/login" -H 'Content-Type: application/json' \
      -d "{\"email\":\"$EMAIL\",\"password\":\"Test12345\"}" | jget data.user.id)
    [ -n "$LOGIN" ] && ok "login works for the new account" || bad "login failed for the new account"

    echo "  [info] clean up with:  DELETE FROM users WHERE email='$EMAIL';"
  else
    bad "registration failed: $(echo "$REG" | jget error.message)"
    echo "$REG" | head -c 300; echo ""
  fi

  # Demo accounts only exist if demo-users.sql was imported.
  DEMO=$(curl -s --max-time 20 -X POST "$API/auth/login" -H 'Content-Type: application/json' \
    -d '{"email":"agency@pitchrooms.demo","password":"demo123"}' | jget data.user.role)
  [ "$DEMO" = "agency" ] && ok "demo accounts are present and can sign in" \
    || echo "  [info] demo accounts not present (import database/demo-users.sql if you want them)"
fi

echo ""
echo "==========================================================="
echo " RESULT: $PASS ok, $FAIL failed, $WARN warnings"
echo "==========================================================="
[ "$FAIL" -eq 0 ] || exit 1
