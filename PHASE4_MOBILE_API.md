# Phase 4 — Mobile API and notification foundation

The visitor Android app uses the versioned JSON API under:

`/visitor-management-system/phone_tracker/api/v1`

The staff website continues using its existing PHP session endpoints. The native app
uses bearer tokens, so mobile authentication does not alter Admin, Security, or Office
login behavior.

## Installation

1. Import the existing schemas through `appointments.sql`/Phase 1.
2. Import `phone_tracker/mobile_api_migration.sql` into `phone_tracker`.
3. Use HTTPS before testing on a physical phone outside local development.
4. Keep `phone_tracker/config/mobile_api.php` and the Firebase service-account JSON out
   of Git. Both are ignored by `phone_tracker/config/.gitignore`.

The migration is additive and repeatable. It adds visitor email/profile fields, hashed
API access tokens, one-time account-action tokens, email outbox records, Android device
registrations, notification delivery records, tracking sessions, location idempotency
fields, and configurable mobile policies.

## Common API rules

- Send and receive JSON using UTF-8.
- Authenticated requests use `Authorization: Bearer <access_token>`.
- Raw access tokens are never stored in the database; only SHA-256 hashes are stored.
  Password-reset validation uses hashes as well. Until an email provider is wired,
  the short-lived raw action token is present only in its Git-excluded outbound-email
  queue payload so the future sender can deliver it; clear that payload after delivery
  or replace it with provider-side immediate sending before production.
- Access tokens expire after 30 days by default and are revoked by sign-out or password
  reset.
- Authentication and recovery requests are rate-limited by identity and IP address.
- Production responses never expose password-reset tokens. During isolated testing,
  `VISITOR_APP_ENV=development` can return a `development_reset_token` field.
- Successful responses contain `success`, `data`, and `meta`. Errors contain `success`,
  `message`, optional field `errors`, and `meta`.
- Every response includes an API version and request ID.

## Endpoint directory

| Method | Endpoint | Authentication | Purpose |
|---|---|---|---|
| GET | `/health.php` | Public | Database/API readiness |
| POST | `/auth/register.php` | Public | Register a visitor account |
| POST | `/auth/login.php` | Public | Issue a bearer token |
| POST | `/auth/logout.php` | Bearer | Revoke the current token |
| POST | `/auth/request_password_reset.php` | Public | Queue generic reset instructions |
| POST | `/auth/reset_password.php` | Public token | Set a new password and revoke all sessions |
| GET/PATCH | `/me.php` | Bearer | Read/update visitor name and contact number |
| GET | `/offices.php` | Bearer | The five official offices and their rules |
| GET | `/availability.php` | Bearer | Available slots for an office/date |
| GET/POST | `/appointments.php` | Bearer | Visit history, walk-in pass, or appointment request |
| GET | `/appointment.php?id=` | Bearer | Details, proposal, and eligible QR pass |
| POST | `/appointment_cancel.php` | Bearer | Cancel an eligible appointment |
| POST | `/reschedule_response.php` | Bearer | Accept/decline an Office proposal |
| GET | `/notifications.php` | Bearer | Paginated in-app notifications |
| POST | `/notification_read.php` | Bearer | Mark one/all notifications read |
| POST/DELETE | `/devices.php` | Bearer | Register, rotate, or remove an FCM token |
| GET/POST | `/consent.php` | Bearer | Inspect, grant, or withdraw GPS consent |
| GET/POST | `/tracking.php` | Bearer | Inspect/start/stop an appointment session |
| POST | `/locations.php` | Bearer | Idempotent single/batch GPS uploads |

The machine-readable contract is in `phone_tracker/api/v1/openapi.yaml`.

## QR privacy rule

Creating an appointment returns `qr_pass: null` until Office approval. Creating a
walk-in returns an approved QR pass immediately when the office is accepting visitors.
The app renders only the returned `qr_pass.payload` and never invents its own token.

The pass is accepted from 30 minutes before the start through the scheduled end. The
Security web workflow remains responsible for scanning and recorded exceptions.

## Location lifecycle and offline queue

1. Signup records versioned tracking consent. Each created visit receives its own
   auditable consent record without asking the visitor to accept the same notice again.
2. Security scans the approved QR and changes the appointment to `checked_in`.
3. The app calls `tracking.php` with `action: start`.
4. The app displays a persistent Android foreground-service notification while sharing.
5. The app captures no more often than the server-provided upload interval (15 seconds
   by default), assigns a stable `client_event_id`, and stores unsent points locally.
6. `locations.php` accepts up to 100 points per batch by default. Re-sending a batch is
   safe: duplicate event IDs are counted but not stored twice.
7. Completion closes the server tracking session. Points captured before completion
   may arrive during the 24-hour offline grace period; post-completion captures are
   rejected.
8. Raw GPS points use a configurable 90-day default retention. The cleanup worker is a
   dry run unless explicitly invoked with `--apply`.

These values live in `mobile_api_settings`. Confirm the 90-day retention period with
the institution's privacy officer before a real pilot.

## Firebase Cloud Messaging

Future notifications inserted by the Office, Security, or maintenance workflow are
automatically queued for every active Android installation. Register/rotate the current
Firebase token through `devices.php` whenever `FirebaseMessagingService.onNewToken`
fires.

The worker uses the FCM HTTP v1 endpoint and a short-lived OAuth 2.0 access token:

`php phone_tracker/workers/send_push_notifications.php`

Configuration:

1. Copy `phone_tracker/config/mobile_api.example.php` to `mobile_api.php`.
2. Add the Firebase project ID.
3. Place the service-account file outside the public web root when deployed, then set
   its path through `FIREBASE_SERVICE_ACCOUNT_FILE` or the local config.
4. Enable the Firebase Cloud Messaging API v1.
5. Schedule the worker (for example every minute) after a successful real-device test.

The repository contains no Firebase credential. Without configuration, the worker exits
safely and queued in-app notifications continue to work.

## Account recovery email

Password-reset messages are placed in `outbound_emails`. A deployment email provider
still needs to consume that queue. This keeps account recovery secure:
production endpoints do not return reset tokens and always use a generic response for
unknown email addresses.

## Kotlin handoff for Phase 5

Recommended Android structure:

- Jetpack Compose presentation layer with screen/view-model state.
- Repository layer for authentication, offices, appointments, notifications, and
  tracking.
- Retrofit/OkHttp network layer with a bearer-token interceptor.
- Encrypted local token storage using Android Keystore-backed storage.
- Room queue keyed by `client_event_id` for offline GPS batches.
- Coroutines and Flow for API/database state.
- A visible foreground location service active only during a checked-in visit.
- Firebase Messaging service that rotates tokens through `devices.php`.

Do not embed the database password, Firebase service-account key, or any staff account
inside the Android application.

## Tests completed

- Repeatable fresh schema migration.
- Registration with required tracking consent and immediate sign-in.
- Password recovery and revocation of every older access token.
- Bearer sign-out and role isolation from staff web sessions.
- Exactly five official offices and generated availability slots.
- Appointment creation without QR disclosure, Office approval, and QR release.
- Immediate walk-in pass creation for an office accepting visitors.
- Visitor cancellation and Office-proposed schedule acceptance.
- Android token registration, notification queue creation, read state, and removal.
- Security check-in, tracking start, idempotent GPS batches, completion stop, offline
  pre-completion upload, and rejection of a post-completion capture.
- Retention worker dry-run behavior and safe failure when Firebase is unconfigured.

Real FCM delivery and password-recovery email delivery require project/provider credentials and therefore
remain deployment tasks rather than locally fabricated tests.
