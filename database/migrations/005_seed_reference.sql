-- ===========================================================================
-- 005 — reference data that the frontend currently hardcodes in its bundle.
-- Pricing lives here so it can change without a redeploy.
-- ===========================================================================

INSERT INTO plans (id, name, tag, badge, description, price_minor, currency, period, duration_days, unlimited, features, active, sort_order)
VALUES
('monthly_unlimited', 'Monthly All-Access Pass', 'UNLIMITED PITCHROOMS', 'Most Popular',
 'Unlimited pitchrooms and events for 30 days across all brands, investors, and hiring employers.',
 49900, 'USD', 'per month', 30, 1,
 JSON_ARRAY(
   'Unlimited Live Pitch Room presentations every month',
   'Pitch to unlimited Brands, Investors & Hiring Teams',
   'Verified Seller Pro Badge across all proposal lists',
   'Priority Pitch Deck queue in live buyer review sessions',
   'Full live scorecard breakdown & buyer feedback analytics',
   'Zero per-event fees across all 3 ecosystems (AB, SI, EE)',
   'Dedicated Pitch Concierge support & tech check'
 ), 1, 1),
('single_event', 'Single Event Pitch Pass', 'PAY AS YOU PITCH', 'Flexible Access',
 'Full access to pitch, present live, and enter one specific event or pitchroom.',
 9900, 'USD', 'single event', 365, 0,
 JSON_ARRAY(
   '1 Live Pitch Room admission for the selected event',
   'Official live pitch + Q&A presenter slot',
   'Full proposal deck submission & brand brief review',
   'Official Buyer Scorecard evaluation & feedback access',
   'Verified Event Participation Certificate & tax invoice'
 ), 1, 2)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO slot_pricing (vertical, position, title, description, fee_minor, currency, active) VALUES
('ab', 1, 'Premium Position #1', 'First presenter — maximum buyer attention and the full evaluation window.', 1500000, 'INR', 1),
('ab', 2, 'Premium Position #2', 'Second presenter — strong recall before the buyer panel settles.', 1000000, 'INR', 1),
('ab', 3, 'Premium Position #3', 'Third presenter — premium placement in the first half of the agenda.', 750000, 'INR', 1),
('ab', 4, 'Standard Position #4', 'Standard allocated position based on shortlist evaluation score.', 0, 'INR', 1),
('ab', 5, 'Standard Position #5', 'Standard allocated position based on shortlist evaluation score.', 0, 'INR', 1),
('si', 1, 'Premium Position #1', 'First to pitch the investor panel.', 1500000, 'INR', 1),
('si', 2, 'Premium Position #2', 'Second pitch slot.', 1000000, 'INR', 1),
('si', 3, 'Premium Position #3', 'Third pitch slot.', 750000, 'INR', 1),
('si', 4, 'Standard Position #4', 'Standard allocated position.', 0, 'INR', 1),
('si', 5, 'Standard Position #5', 'Standard allocated position.', 0, 'INR', 1),
('ee', 1, 'Premium Position #1', 'First candidate to present to the hiring panel.', 1500000, 'INR', 1),
('ee', 2, 'Premium Position #2', 'Second presenter slot.', 1000000, 'INR', 1),
('ee', 3, 'Premium Position #3', 'Third presenter slot.', 750000, 'INR', 1),
('ee', 4, 'Standard Position #4', 'Standard allocated position.', 0, 'INR', 1),
('ee', 5, 'Standard Position #5', 'Standard allocated position.', 0, 'INR', 1)
ON DUPLICATE KEY UPDATE title = VALUES(title), fee_minor = VALUES(fee_minor);

-- Recurring jobs. These run from the API process itself (inline after a
-- response, via the /internal/scheduler/tick endpoint, or the CLI daemon) —
-- no hosting-panel cron entry is required.
INSERT INTO scheduled_tasks (id, handler, description, interval_seconds, enabled, next_run_at) VALUES
('task-meeting-reminders', 'meeting.reminders',  'Queue 24h and 1h reminders for scheduled events', 300,  1, UTC_TIMESTAMP()),
('task-meeting-autostart', 'meeting.autostart',  'Move events into waiting room / live at start time', 60, 1, UTC_TIMESTAMP()),
('task-meeting-advance',   'meeting.advance',    'Advance the live room clock past expired stages',    30,  1, UTC_TIMESTAMP()),
('task-meeting-autoclose', 'meeting.autoclose',  'Close rooms that ran past their end time',           300, 1, UTC_TIMESTAMP()),
('task-pass-expiry',       'billing.passExpiry', 'Expire seller passes and notify holders',            3600, 1, UTC_TIMESTAMP()),
('task-opportunity-deadlines', 'opportunity.deadlines', 'Close opportunities whose deadline passed',   3600, 1, UTC_TIMESTAMP()),
('task-cleanup',           'system.cleanup',     'Prune expired OTPs, tokens, locks and stale logs',   86400, 1, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE handler = VALUES(handler), description = VALUES(description);

INSERT INTO scheduler_locks (name, locked_at, locked_by, last_tick_at, ticks)
VALUES ('global', NULL, NULL, NULL, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name);
