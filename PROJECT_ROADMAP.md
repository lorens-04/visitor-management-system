# ISATU Visitor Management System roadmap

This roadmap reflects the agreed product: visitors use a downloadable phone app;
Admin, Security, and Office personnel use web dashboards on their computers.

## Phase-control rule

- Work on only one numbered phase at a time.
- A later phase may receive a small backend foundation only when the current phase
  cannot work without it; that foundation must be identified clearly.
- The project may proceed to the next phase without a separate approval when the
  current phase has been verified and the transition clearly benefits the agreed
  system. Record the transition and remaining work in this roadmap.
- Ask before changing an agreed product rule, replacing a major technology choice, or
  performing a destructive database operation.
- Keep the existing PHP/MySQL structure unless a change is required for security or
  for an agreed workflow.

## Locked workflow decisions

- Public signup is not offered on the staff login page.
- A visitor requests an appointment in the visitor app.
- The office can approve, decline with a reason, or suggest one or more available times.
- The visitor can accept or decline a proposed time. A full chat system is not required
  for the first version.
- Office personnel can publish weekly availability, unavailable dates/times, slot length,
  and visitor capacity so unavailable appointments cannot be requested.
- The only bookable destinations are IT Department, IS Department, CS Department,
  Dean's Office, and Tech Support.
- An approved appointment receives a QR visitor pass.
- The QR is scannable from 30 minutes before the scheduled start until the scheduled end.
- A pass that is not used before the end becomes `window_closed`, displayed as
  **Appointment Done**. There is no `no_show` status.
- Security or Admin can permit an out-of-window scan only by recording a reason.
- GPS tracking starts only after Security checks the visitor in and stops when the visit
  is completed. GPS records belong to a specific appointment and consent record.

## Phase 1 — Workflow and data foundation

Status: code complete; the required tables are present in the current local database.

- [x] Remove the signup option from the staff login page.
- [x] Finalize appointment statuses and transition timestamps.
- [x] Add scheduled start/end times and the QR validity rule.
- [x] Add consent, notifications, audit logs, QR overrides, reschedule proposals, and
  office-availability tables.
- [x] Link new GPS records to the checked-in appointment and visitor account.
- [x] Preserve legacy names such as `appointment_at` and `device_name` for compatibility.
- [x] Add an additive migration instead of replacing the existing database.
- [x] Verify fresh installation, legacy migration, repeated migration, and the main
  request-to-completion workflow on a disposable database.
- [x] Verify that the real local `phone_tracker` database contains the Phase 1 tables.
- [x] Export a pre-Phase-4 backup of the real `phone_tracker` database to the local,
  Git-ignored `.backups` directory.
- [ ] Change all seed passwords before a real pilot.

## Phase 2 — Office appointment management

Status: code complete; the required schema is present in the real local database;
project-owner visual review is still pending.

- [x] Build the Office dashboard queue for pending requests.
- [x] Add Approve and Decline actions; decline requires a clear reason.
- [x] Add **Suggest another schedule** with one to three alternative time slots.
- [x] Add the visitor response endpoint: accept one proposed slot or decline the
  proposal. This small backend foundation was required to complete the Office
  rescheduling workflow; the phone-app screen belongs to Phase 5.
- [x] Automatically close an unanswered reschedule proposal after its response
  deadline and release its held time slots.
- [x] Build availability settings: accepting-visitors toggle, weekly hours,
  temporary closures/extra hours, slot duration, and capacity.
- [x] Prevent requests for unavailable, misaligned, or full slots on the server.
- [x] Add office-scoped access so personnel cannot view or change another office's
  appointments.
- [x] Record appointment history, in-system notifications, and audit events for office
  actions.
- [x] Standardize the shared web typography, correct the dashboard logo crop, and let
  Office Personnel edit their display name and profile photo safely.
- [x] Fix the Office profile menu so it stays hidden until the profile button is used.
- [x] Merge the separate Appointment History page into the main searchable, filterable,
  paginated Appointments workspace; keep Requests separate as the action queue.
- [ ] Complete the project owner's visual review of the Office dashboard in the normal
  XAMPP browser address.
- [x] Display notifications and proposed-time choices in the native visitor app.
- [ ] Connect and verify production push notifications. This remains part of Phase 5.

## Phase 3 — Web workflow integration and Security completion

Status: code complete and integration-tested on a disposable database; project-owner
visual review in the normal XAMPP browser address is still pending.

- [x] Re-test Admin, Office, and Security authentication, dashboard access, and role
  isolation using the finalized appointment statuses.
- [x] Connect the redesigned QR scanner to the 30-minute-before-start through
  scheduled-end validity window.
- [x] Add a recorded-reason interface for Security or Admin to authorize an early or
  expired QR pass for 15, 30, or 60 minutes.
- [x] Ensure an expired-window override remains active until the override deadline
  instead of being immediately completed by scheduled-time maintenance.
- [x] Confirm check-in permits appointment-owned GPS uploads and completion rejects
  further uploads while preserving historical route data.
- [x] Finish Security visitor-monitoring states: waiting, live, stale, and offline;
  show last-update age and GPS accuracy.
- [x] Add appointment-specific route history for active and completed visits, including
  date selection and average recorded accuracy.
- [x] Verify invalid roles receive HTTP 403 for Security monitoring and QR overrides.
- [x] Add responsive scanner, manual-entry, camera-retry, permission, error, and empty
  states while retaining the Security dashboard shell.
- [x] Add separate QR-photo and QR-screenshot fallbacks with clearer return-to-browser
  instructions for local HTTP testing.
- [ ] Deploy the staff site through trusted HTTPS and re-test the embedded live camera
  on the guard's physical phone. Mobile browsers block live camera streams on the
  current local HTTP address.
- [ ] Re-test QR photo and screenshot decoding on the guard's phone using a pass shown
  on a second device; confirm the central database and desktop dashboard update.
- [ ] Complete the project owner's visual review of the Security dashboard, monitoring
  map, and QR scanner in the normal XAMPP browser address.

## Phase 4 — Mobile API and notification foundation

Status: backend/API code complete and integration-tested; the additive migration is
installed in the real local database. Real Firebase and email delivery require external
project/provider credentials and remain deployment configuration tasks.

- [x] Define the human-readable and OpenAPI contracts for API version 1.
- [x] Add consent-gated visitor registration with immediate sign-in, rate-limited sign-in, hashed opaque bearer
  tokens, sign-out, and password recovery that revokes existing sessions.
- [x] Keep mobile authentication separate from the PHP sessions used by staff web
  dashboards.
- [x] Add endpoints for the five offices, availability, appointment creation/history,
  cancellation, proposed-time responses, eligible QR passes, and notifications.
- [x] Ensure a QR token is not disclosed while an appointment is pending.
- [x] Define the Kotlin/Jetpack Compose UI, repository, Retrofit, Room, coroutine/Flow,
  foreground-service, and Firebase Messaging handoff for Phase 5.
- [x] Add Android installation/FCM token registration and rotation, automatic delivery
  queuing, retry records, invalid-token handling, and an HTTP v1 push worker.
- [x] Add versioned consent, appointment-owned tracking sessions, a 15-second default
  upload interval, idempotent 100-point offline batches, a 24-hour offline-upload grace
  period, and a configurable 90-day raw-GPS retention default.
- [x] Add a dry-run-first location-retention worker.
- [x] Test the API independently on a disposable database, including the full
  request/approval/QR/check-in/tracking/completion flow.
- [x] Back up the real local database, apply the repeatable Phase 4 migration, and
  verify the API health endpoint through Apache.
- [x] Connect the real Firebase Android project and configure a least-privilege server
  service account locally without committing either credential.
- [ ] Verify real notification delivery to a physical Android device.
- [ ] Connect an institutional email provider to the password-reset outbox.
- [ ] Obtain institutional approval for the default 90-day raw-location retention.

## Phase 5 — Downloadable visitor phone application

- Status: first native implementation complete; debug/release compilation, unit tests,
  Android lint, and Firebase build integration pass. Physical-device integration,
  production email, signing, and acceptance testing remain pending.
- [x] Build the Android application natively in Kotlin and Jetpack Compose.
- [x] Use coroutines/Flow for app state, an offline Room queue for unsent location updates,
  and a visible foreground tracking service while an active visit is being tracked.
- [x] Build visitor onboarding, consent-gated registration, immediate sign-in, and account recovery.
- [x] Build office selection using only server-approved available schedules.
- [x] Build walk-in passes plus appointment creation, status, history, and cancellation screens.
- [x] Show approvals, decline reasons, and proposed schedules; allow visitors to accept or
  decline an offered time.
- [x] Add Firebase Messaging client handling, server device-token rotation, and real
  local Firebase client/server configuration; physical-device delivery remains unverified.
- [x] Display a QR visitor pass only for an approved appointment.
- [x] Capture tracking consent during signup and request Android location permission only after check-in.
- [x] Start GPS sharing only after Security check-in, show a persistent tracking indicator,
  queue updates when offline, and stop after checkout/completion.
- [x] Encrypt the API bearer token with Android Keystore and keep all service/signing
  secrets out of the repository.
- [x] Verify Gradle project configuration and the build task graph with JDK 17.
- [x] Parse the Kotlin source with ktlint, validate Android XML resources, and lint the
  Phase 4 PHP API files for syntax errors.
- [x] Add an Android 13+ notification-permission explanation and correct Back-button
  behavior on appointment details.
- [x] Add a padded adaptive Android launcher icon to protect the full university seal
  from circular and rounded launcher masks.
- [x] Redesign the native visitor UI from the approved pre-oral direction, add the
  missing home dashboard and a single Notifications screen, and standardize booking-purpose categories
  without replacing the Phase 4 API contract.
- [x] Remove duplicate notification summaries from Home and keep Notifications in the
  header as the single entry point.
- [x] Improve appointment scheduling with future-date limits, automatic availability
  loading, compact time-slot choices, capacity labels, and a selected-time summary.
- [x] Install Android Studio/SDK 37 and complete debug/release compilation, Android
  lint, and unit tests.
- [ ] Perform the full workflow and GPS/offline tests on an actual Android phone.
- [x] Connect the real Firebase Android project locally.
- [ ] Connect an institutional email provider.
- [ ] Prepare signed internal-test builds before app-store submission.

## Phase 6 — Analytics and reporting

- Validate data definitions for total visitors, active tracks, duration, purpose,
  destination, and traffic periods.
- Calculate charts only from completed or clearly defined appointment states.
- Add automatic insights with transparent rules and enough data checks.
- Add date/office filters and CSV export.
- Define retention and anonymization rules before using historical GPS analytics.

## Phase 7 — Security, testing, and deployment

- Replace development credentials and move secrets out of committed source files.
- Test permissions for Visitor, Office, Security, and Admin accounts.
- Test appointment transitions, availability conflicts, QR timing, overrides, consent,
  GPS start/stop, and notification delivery.
- Add database backups, error logging, HTTPS, and production configuration.
- Run a small campus pilot, record issues, then prepare the web deployment and app-store
  release materials.

## Recommended next task

Install the debug app on a physical Android phone and complete the Phase 5 acceptance
checklist in `PHASE5_VISITOR_ANDROID_APP.md`, including notification and GPS/offline
behavior. Do not finalize Phase 6 analytics until the visitor/Office/Security workflow
has passed that end-to-end test; otherwise analytics would use unverified event data.
