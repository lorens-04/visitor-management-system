# Phase 1 database and workflow foundation

This phase adds the agreed appointment lifecycle, schedule windows, consent records,
appointment-linked GPS, notifications, audit history, QR overrides, rescheduling, and
office availability without renaming the existing core tables.

## Existing local database

1. Export a backup of the `phone_tracker` database in phpMyAdmin.
2. Select the existing `phone_tracker` database.
3. Import only `phone_tracker/phase1_workflow_migration.sql`.
4. Do not import the rewritten `appointments.sql` over the existing appointments table.

The migration changes old `pending` records to `pending_approval`. Existing location
rows remain as legacy rows with a null `appointment_id` because they cannot be matched
to appointments reliably after the fact. New GPS rows are linked to an appointment.

## New empty database

Import in this order:

1. `phone_tracker/phone_tracker.sql`
2. `phone_tracker/login_users.sql`
3. `phone_tracker/appointments.sql`

The two older migration files are kept only for databases made before Phase 1. Do not
run `appointments_status_migration.sql` after the Phase 1 migration.

## Appointment statuses

- `pending_approval`
- `approved`
- `rejected`
- `cancelled`
- `unanswered`
- `reschedule_proposed`
- `checked_in`
- `completed`
- `window_closed` (shown to visitors as **Appointment Done**)

An approved QR is valid from 30 minutes before `scheduled_start_at` until
`scheduled_end_at`. Security/Admin overrides are stored with the actor, reason, and
valid-until time. The backend endpoint exists in `phone_tracker/create_qr_override.php`;
its dashboard form is intentionally left for the Security UI phase.

## Compatibility notes

- `appointment_at` remains as a legacy alias of `scheduled_start_at`.
- `device_name` remains for display, but new location ownership uses `appointment_id`.
- The temporary visitor web page now asks for a date/time and consent so it can test
  the new backend before the Android visitor application is built.
- Office availability defaults to accepting visitors with 30-minute slots and one
  visitor per slot. Phase 2 now provides the Office UI for weekly schedules,
  exceptions, slot duration, and capacity.
- Phase 2 uses the tables created by `phase1_workflow_migration.sql`. After upgrading
  the available-office list, also import `office_catalog_migration.sql` once.
