# Visitor map and campus-exit handoff

The Android visitor app now opens `TrackingMapScreen.kt` automatically after Security
changes an appointment to `checked_in`. The screen already contains the final layout,
tracking/permission states, destination panel, and map-provider placeholder.

## Backend data still required

Provide one authenticated mobile endpoint, recommended as:

`GET /phone_tracker/api/v1/campus_map.php?appointment_id={id}`

Suggested response data:

```json
{
  "destination": {
    "office_code": "IT",
    "label": "IT Department",
    "latitude": 10.0000000,
    "longitude": 122.0000000
  },
  "campus_boundary": [
    { "latitude": 10.0000000, "longitude": 122.0000000 }
  ],
  "exit_policy": {
    "minimum_accuracy_meters": 50,
    "outside_confirmation_points": 3,
    "outside_confirmation_seconds": 45
  }
}
```

Use official coordinates for the campus polygon and the five permitted destinations:
IT Department, IS Department, CS Department, Dean's Office, and Tech Support. Store
these values on the server so the Android app does not need an update when a pin moves.

## Android map integration point

Replace `CampusMapPlaceholder` inside `TrackingMapScreen.kt` with the selected Android
map composable. The recommended Google Maps setup needs `MAPS_API_KEY` in uncommitted
`visitor_app/local.properties`, package restriction for `ph.edu.isatu.visitor`, and both
debug and release certificate restrictions.

The visitor map should display only:

- the visitor's current position;
- the assigned destination marker;
- the campus boundary; and
- tracking/campus-exit status.

Do not draw a suggested route or the visitor's historical trail on this screen. Route
history remains available only to authorized Security/Admin analytics.

## Server-authoritative campus exit

Extend `phone_tracker/api/v1/locations.php` to check each accepted GPS point against the
official boundary. Do not complete a visit after a single outside reading. Confirm an
exit only after the configured number/duration of accurate readings outside a buffered
boundary; this prevents GPS drift near gates and building edges from ending visits.

After a confirmed exit, the server must atomically:

1. mark the appointment `completed` with an exit-specific audit note;
2. end the location tracking session with `ended_reason = 'campus_exit'`;
3. enqueue a visitor notification; and
4. reject subsequent location uploads.

For privacy, process outside coordinates only to confirm the boundary transition and do
not retain the visitor's movement beyond the campus.
