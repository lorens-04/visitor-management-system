# Phase 3 — Web workflow and Security integration

Phase 3 connects the existing Admin, Office, Visitor, and Security workflow without
replacing the PHP/MySQL structure. All automated integration tests described here used
a disposable MariaDB database. The real local `phone_tracker` data was not changed.

## Delivered behavior

### QR validation and controlled exceptions

- An approved pass is accepted from 30 minutes before its scheduled start until its
  scheduled end.
- The scanner uses the same responsive Security website on desktop and guard phones.
- On local HTTP, **Take QR photo** and **Choose QR screenshot** decode an image because
  mobile browsers block continuous camera streams outside HTTPS. Live scanning remains
  available on localhost and trusted HTTPS deployments.
- A pending, rejected, cancelled, unanswered, reschedule-pending, completed, or unknown
  pass cannot be overridden from the scanner.
- An early or expired pass shows its visitor and destination and offers an
  **Authorize time override** action to Security and Admin only.
- The override requires a reason and a 15-, 30-, or 60-minute authorization period.
- The authorization, reason, authorizing user, original time window, deadline, and use
  are recorded in the database and audit log.
- When an expired pass is authorized, the active visit end is extended through the
  override deadline so scheduled maintenance cannot complete it immediately.

### Tracking lifecycle

- A successful Security scan changes the appointment from `approved` to `checked_in`.
- Visitor GPS uploads are accepted only for that visitor's checked-in appointment and
  only while an active location-consent record exists.
- Security completion changes the appointment to `completed`; later GPS uploads are
  rejected.
- Existing appointment-owned route points remain available after completion.

### Visitor monitoring

- `waiting`: checked in, but no GPS point has arrived since check-in.
- `live`: latest GPS point is no more than 45 seconds old.
- `stale`: latest GPS point is 46–180 seconds old.
- `offline`: latest GPS point is more than 180 seconds old.
- Map markers show the state, update age, destination, and reported GPS accuracy.
- Security can select an active or completed visit, select a date with route data, and
  view the appointment-specific path and average GPS accuracy.

## Integration tests completed

- Admin, Office, and Security accounts logged in and loaded their own dashboard data.
- Office-to-Admin and Security-to-Office dashboard access returned HTTP 403.
- A normal in-window QR pass checked in successfully.
- A phone-compatible scan request recorded `checked_in`, `checked_in_at`, and the
  Security user in the central database; the disposable test record was removed.
- An early pass was blocked, authorized with a recorded reason, then checked in.
- An expired pass became `window_closed`, was authorized, then remained checked in
  through its new override deadline.
- A pending appointment stayed blocked and could not receive an override.
- Location states changed through live, stale, and offline thresholds.
- Route dates and route points remained readable after completion.
- GPS uploads after completion were rejected.
- Visitor access to Security monitoring and override endpoints returned HTTP 403.

## Main files

- `phone_tracker/security_scan.html`
- `phone_tracker/security_scan.js`
- `phone_tracker/scan_appointment.php`
- `phone_tracker/create_qr_override.php`
- `phone_tracker/security_live_locations.php`
- `phone_tracker/security_route_dates.php`
- `phone_tracker/security_route_points.php`
- `phone_tracker/dashboard.html`
- `phone_tracker/dashboard.js`
- `phone_tracker/save_location.php`
- `phone_tracker/complete_appointment.php`
- `phone_tracker/style.css`

## Manual review still needed

Open the project through XAMPP and visually review desktop and narrow-screen layouts,
camera permission behavior, and the campus map with a real phone sending GPS points.
Automated tests cannot judge the final look or real-device camera/GPS behavior.

## Phase 4 handoff

Phase 4 should define a versioned JSON API for the Kotlin visitor app, replace the demo
visitor login assumptions with secure mobile authentication, and establish Firebase
Cloud Messaging device-token and notification-delivery handling. The app UI itself is
Phase 5.
