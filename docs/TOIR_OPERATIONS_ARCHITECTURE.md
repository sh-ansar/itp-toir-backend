# TOIR operations: digital equipment, preventive planning, crews, risk (P0)

This implementation extends existing TOIR Laravel models instead of creating a second, incompatible asset or work-order registry. Chat, Portal, HRM and Inventory remain independent.

## Asset hierarchy

\`toir_assets\` is company-scoped and forms a parent-child tree:
site/object -> system -> equipment/aggregate -> assembly -> component.
\`code\` is unique per company; parents are validated within company; cycles and deletion of active descendants are rejected. \`node_type\` is a classification separate from the legacy \`category\`.

Maintenance plans and completed work history are attached to **individual asset IDs**, including the smallest component. New readings append immutable \`toir_asset_readings\` rows with user, measurement type and timestamp. \`operating_hours\` is monotonic. \`condition_score\` (0..100) is operator-entered and is not sensor-generated.

## Preventive maintenance: two distinct triggers

An active plan can have a calendar interval (\`periodicity_days\`, \`next_due_date\`) and optionally a meter interval (\`interval_hours\`, \`next_due_hours\`). The forecast API lists projected occurrences. Calendar occurrences are exact recurrence dates. Meter dates are only projected if two increasing timestamps and non-decreasing readings yield a positive observed operating-hours/day rate; otherwise the UI states the meter threshold without inventing a date.

\`POST /api/v1/operations/generate\` uses explicit \`dry_run=true\` to preview, then commits only when confirmed. Real work orders link \`maintenance_plan_id\`, \`scheduled_for\`, \`trigger_source\` and optional \`trigger_hours\` (threshold snapshot). A unique database constraint prevents duplicate orders per plan/date. When the meter threshold has already been reached, the generator issues a real meter-triggered order (or combines it with today's already scheduled work). It does **not** mark the equipment repaired; only a completed work order advances due counters.

Completing a meter-triggered work order moves its meter threshold to current reading plus interval; it does not silently move its next calendar date. Completing an actual calendar cycle advances that cycle and resolves already-closed later cycles. This is not a fully optimized "whichever first resets both counters" scheduling policy; implementing such a policy requires explicit cancellation/replanning rules for future issued orders.

No background job automatically issues orders at this stage: the planner confirms the preview. This intentionally keeps any industrial changes under operator control until the scheduling policy is approved.

## Crews and work calendars

Crews are company-scoped, contain a lead and active members. Repeated weekly shifts become dated, confirmed work intervals. Both calendar generation and manually assigned work orders check shift boundaries and overlap for the crew, individual employee and members shared by other crews. The generator searches at 15-minute increments and reserves slots even in a dry-run preview. A blocked occurrence is reported with its reason, never silently skipped.

Current P0 constraints: shifts do not cross midnight, plan generation horizon max 90 days, work calendar range max 180 days, no skill/certification, travel-time or employee absence optimization yet. New orders are waiting until an authorised employee/crew member starts and completes them.

## Explainable risk, not fabricated AI

\`GET /api/v1/operations/risk\` calculates **triage indexes**, not statistical probabilities:

- criticality (1..5), 7 points per step above 1
- poor condition: up to 33 points
- missing active maintenance plan on maintainable equipment: 7
- overdue preventive service: up to 30 per plan
- upcoming calendar service: 6 per plan
- reached meter threshold: 24 per plan
- open emergency orders: up to 30
- uncompleted orders past planned end: up to 16
- score capped to 0..100; critical>=80, high>=60, medium>=30, otherwise low
- each parent inherits up to 90% of its most severe child's score, never less than its own
- missing or stale condition readings are flagged separately as \`data_quality\`

The API returns reasons, score, score before child inheritance, inherited child ID, timestamp and a disclaimer. A low score with incomplete measurement history is **not** evidence the equipment is healthy. ML-based failure probability, anomaly detection, remaining useful life and causal modelling require a sufficiently long stream of trusted telemetry and verified failure events.

## API (authenticated, active-company scoped)

- \`GET /api/v1/operations/asset-tree\`
- \`GET /api/v1/operations/assets/{id}/readings\`; \`POST\` for dated readings
- \`GET /api/v1/operations/crews\`; \`POST\` create; \`PATCH /crews/{id}\`
- \`POST /api/v1/operations/crews/{id}/members\`; \`DELETE /crews/{id}/members/{employeeId}\`
- \`GET /api/v1/operations/shifts\`; \`POST /shifts/generate\`; \`DELETE /shifts/{id}\`
- \`GET /api/v1/operations/forecast?days=60\`
- \`POST /api/v1/operations/generate\` with \`days\`, \`dry_run\`
- \`GET /api/v1/operations/risk\`
- existing resources, plan issue, work-order transitions, and company permissions remain in place
- \`GET /api/v1/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD\` scopes work by displayed interval

## Acceptance test outline

1. Object -> aggregate -> bearing with independent serials and a risk summary, no cycles or cross-company leakage.
2. Enter hours and condition (with an auditable history; refuse decreasing hours).
3. Plan maintenance with a calendar recurrence and optionally an hours threshold.
4. Make crew, add employees, set confirmed weekly shifts, preview and issue jobs.
5. Reject collisions for the same crew or employee even if they belong to more than one crew.
6. Execute work via the current authorised workflow; verify next due date/hour, events and no duplicates on repeating generation.
7. Review risks; verify failed bearing raises parent risk and data gaps are highlighted.
8. Repeat using a second company and viewer role; verify isolation and read-only permissions.

Automated coverage: \`ToirOperationsTest\` and \`ToirAdvancedPlanningTest\`, plus legacy workflow/domain tests. Before industrial acceptance: verify production migration backup, browser and mobile layouts, cron/worker monitoring, role matrix, PostgreSQL migration, high-volume scheduling performance and offline/overdue catch-up policy.
