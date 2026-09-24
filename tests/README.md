# API test scripts

End-to-end checks that run against a live API with a seeded database. They use
`curl` + `mysql`, so there is nothing to install.

```bash
php bin/migrate.php --fresh && php bin/seed.php
php -S 127.0.0.1:8080 -t public &

bash tests/smoke.sh    # 38 checks: auth, RBAC, proposals, shortlisting,
                       # entry gate, pass gate, messaging gate, admin, scheduler
bash tests/room.sh     # 14 checks: live room clock, evaluation, decision
                       # cascade, and the in-code scheduler jobs
bash tests/jitsi.sh    # 14 checks: entry gate, JaaS tenant prefix, RS256
                       # signature, room claim, moderator flag
```

Both assume a clean seed. Re-running without `--fresh` produces expected
"failures" from leftover state (a pass already bought, messaging already
unlocked by a completed event) and will trip the login rate limiter.
