# Phase 5 — Native visitor Android application

## Current status

The first native Kotlin/Jetpack Compose implementation is in `visitor_app`. It uses the
Phase 4 API without changing the PHP staff login or replacing the existing database.

On September 20, 2026, Android Studio/SDK 37 and the real Firebase Android client were
connected. Gradle 9.6 successfully completed `testDebugUnitTest`, `lintDebug`,
`assembleDebug`, and `assembleRelease`. Firebase resource processing passed for both
variants. The release output remains unsigned and is not an app-store artifact.
Physical-device behavior, real push delivery, password-recovery email, GPS/offline behavior,
and the complete appointment workflow still require acceptance testing.

The local Phase 4 API was rechecked through Apache on September 20, 2026: its index and
health endpoints returned HTTP 200, MySQL reported connected, and protected visitor
endpoints correctly returned HTTP 401 without a bearer token.

## Product rules preserved

- Visitor signup exists only in the visitor app, not on the staff login page.
- Signup requires the visitor tracking consent but does not require a separate email
  verification code. Email is retained for sign-in and password recovery. Visit
  decisions, proposed schedules, reminders, and cancellations are delivered through
  Notifications and Firebase push notifications.
- Bookable destinations come from the API and remain limited to IT Department,
  IS Department, CS Department, Dean's Office, and Tech Support.
- Walk-ins receive an immediate pass when the selected office is accepting visitors.
  Appointments can use only availability returned by the selected office.
- Approval exposes a QR pass; pending requests never receive one.
- QR validity remains 30 minutes before the appointment through its scheduled end.
- `window_closed` and `completed` both display as **Appointment done**; there is no
  no-show label.
- Tracking consent is accepted during account creation and copied into an auditable
  visit record. Android's required operating-system location permission is requested as
  part of signup; it is separate from legal consent and does not start collection.
- A foreground notification remains visible while tracking. Offline points stay on the
  phone until the API accepts them.
- Android 13 and newer users receive an in-app explanation and button for enabling
  appointment notifications instead of an unexplained permission prompt.
- The Android Back button returns from appointment details to the visit list.
- The launcher uses a padded adaptive icon so system icon masks do not crop the seal.
- The approved pre-oral visual direction is now adapted into a native mobile dashboard,
  branded authentication, status-aware visits, a single Notifications screen, clearer
  visitor-pass details, and a consistent profile/privacy area.
- Booking uses standard visit-purpose categories so future analytics are not fragmented
  by inconsistent capitalization or wording.
- Home uses the header bell as the single Notifications entry point instead of
  repeating notification summary and latest-update cards in the content area.
- Appointment dates are limited to the server-supported 180-day window; choosing a
  date automatically loads available times, shown as compact selectable slots with a
  clear selected-schedule confirmation.

## Main source map

| Area | Location |
|---|---|
| Gradle/project configuration | `visitor_app/*.gradle.kts`, `visitor_app/app/build.gradle.kts` |
| API request/response contract | `visitor_app/app/src/main/java/ph/edu/isatu/visitor/data` |
| Encrypted session and install ID | `data/SecureTokenStore.kt` |
| Offline GPS queue | `data/LocationDatabase.kt` |
| Foreground tracking and retry | `service/VisitorTrackingService.kt`, `service/LocationUploadWorker.kt` |
| Firebase client handling | `service/VisitorMessagingService.kt` |
| Authentication UI | `ui/AuthScreens.kt` |
| Home, visits, Notifications and Profile | `ui/MainScreens.kt` |
| Walk-in and appointment registration | `ui/BookingScreen.kt` |
| QR, rescheduling and automatic post-scan tracking UI | `ui/AppointmentDetailScreen.kt` |

## Remaining Phase 5 acceptance work

1. Test layout and accessibility on a small phone and a common modern phone.
2. Test the full app/API/Office/Security workflow on a physical Android device.
3. Verify real Firebase push delivery on that device.
4. Connect the institutional email provider and test password-reset links on-phone.
5. Confirm location collection, offline recovery, checkout stop, battery behavior and
   the Security map during a controlled campus walk.
6. Prepare a signed internal test build only after the privacy and retention decisions
   are approved.

Do not finalize Phase 6 analytics until the core physical workflow passes. Those tests
confirm that the event data used by analytics is actually produced correctly.
