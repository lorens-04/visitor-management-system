# Phase 2 office appointment management

Phase 2 adds the Office Personnel web dashboard and the server rules required before
the visitor phone app can offer reliable appointment schedules.

## Before opening the dashboard

For an existing local database:

1. Export a backup of the `phone_tracker` database in phpMyAdmin.
2. Import `phone_tracker/phase1_workflow_migration.sql` once.
3. Import `phone_tracker/office_catalog_migration.sql` once if the database still uses
   the old college-level office list.
4. Import `phone_tracker/app_users_profile_migration.sql` once to enable staff profile
   photos on an existing database.
5. Confirm each Office Personnel account has role `offices`, an active account, and one
   of these `office_code` values: `IT`, `IS`, `CS`, `DEANS`, or `TECH_SUPPORT`.
6. Create at least one personnel account for every office used in testing. Each account
   sees only requests sent to its own `office_code`; this is an intentional access rule.
6. Open `http://localhost/visitor-management-system/phone_tracker/login.html` and sign
   in with an Office Personnel account. The existing login routing opens
   `offices.html`.

The Office workflow uses the Phase 1 tables. The small office-catalog migration only
adds the five approved departments and reassigns the original demo Office account; it
does not delete historical appointments.

## What Office Personnel can do

- See counts and recent requests for their assigned office only.
- Search and filter pending requests and appointment history.
- Inspect complete appointment details and status history.
- Approve a request when its schedule is still available.
- Decline a request only after recording a reason for the visitor.
- Suggest one to three alternative schedules with a reason and optional note.
- Review and mark appointment notifications as read.
- Edit their display name and upload, replace, or remove a profile photo. Usernames and
  department assignments remain read-only in the profile editor.
- Turn appointment intake on or off, including a visitor-facing reason and optional
  date when the office expects to reopen.
- Set weekly opening hours, slot duration, and maximum visitors per slot.
- Add temporary closures or extra availability without replacing the weekly schedule.

## Server-enforced behavior

- `create_appointment.php` rejects closed, full, incorrectly aligned, or unavailable
  time slots even if a client bypasses the UI.
- Approval checks availability again to protect against a slot filling while the
  request is waiting.
- Proposed schedules temporarily consume capacity while the visitor is deciding.
- Accepting a proposal moves the appointment to the chosen slot and approves it.
- Declining a proposal cancels the request and releases every proposed slot.
- An unanswered proposal closes after its deadline, cancels the request, releases the
  held slots, and notifies both the visitor and the office.
- Appointment actions, status changes, and availability changes are written to history
  or audit records.
- Every Office endpoint uses the signed-in account's `office_code`; changing an ID in
  the browser cannot expose another office's appointment.

## Main Phase 2 files

User interface:

- `phone_tracker/offices.html`
- `phone_tracker/offices.js`
- `phone_tracker/style.css`

Office APIs:

- `phone_tracker/office_dashboard.php`
- `phone_tracker/office_appointment.php`
- `phone_tracker/office_appointment_action.php`
- `phone_tracker/office_propose_reschedule.php`
- `phone_tracker/office_availability.php`
- `phone_tracker/office_notifications.php`
- `phone_tracker/staff_profile.php`

Shared workflow services:

- `phone_tracker/office_availability_service.php`
- `phone_tracker/appointment_maintenance.php`
- `phone_tracker/visitor_reschedule_response.php`
- `phone_tracker/create_appointment.php`
- `phone_tracker/app_users_profile_migration.sql`

## Verification completed

The implementation was tested on a disposable MariaDB database, not the user's real
XAMPP database. Tests covered valid and invalid schedule requests, capacity conflicts,
approval, decline reasons, proposing/accepting/declining schedules, automatic proposal
expiry, weekly hours, closures, extra hours, global intake pauses, office isolation,
notifications, history, and audit records. All PHP files passed syntax checks, all
dashboard JavaScript files parsed successfully, and all literal Office UI element IDs
referenced by JavaScript exist in the HTML.

## Intentionally left for Phase 5

The backend can already create visitor notifications and process a visitor's response
to proposed schedules. The downloadable visitor app still needs screens for those
features and a production push-notification provider. Those are mobile-app tasks, not
missing Office dashboard functions.
