# Teammate setup and testing

Use the Git repository for normal sharing. Do not send an unfiltered copy of the
project folder: the working folder can contain a Firebase service-account private key,
machine-specific Android settings, database backups, and build output.

## Files that must never be committed or placed in a shared ZIP

- `phone_tracker/config/firebase-service-account.json`
- `phone_tracker/config/mobile_api.php`
- `visitor_app/local.properties`
- Android signing keys (`*.jks`, `*.keystore`, and `keystore.properties`)
- `.backups/`, `.gradle-user/`, `debug.log`, and generated `build/` directories

The repository ignore rules cover these paths. Before pushing, always run
`git status --short` and confirm none of them appears.

`visitor_app/app/google-services.json` is also intentionally ignored. It does not
contain the server private key, but the project owner should distribute it to trusted
Android developers through a private team channel or let an authorized teammate
download it from the Firebase project. Never substitute the server service-account
JSON for this Android client file.

## One-time setup for each teammate

1. Install Git, XAMPP, and Android Studio with Android SDK 37.
2. Clone the repository into `C:\xampp\htdocs\visitor-management-system`.
3. Start Apache and MySQL in XAMPP.
4. Create a MySQL database named `phone_tracker`.
5. For a new database, import the SQL files in this order:
   - `phone_tracker/phone_tracker.sql`
   - `phone_tracker/login_users.sql`
   - `phone_tracker/appointments.sql`
   - `phone_tracker/office_catalog_migration.sql`
   - `phone_tracker/app_users_profile_migration.sql`
   - `phone_tracker/mobile_api_migration.sql`
6. Open `http://localhost/visitor-management-system/` and test the staff website.
7. In Android Studio, open the `visitor_app` folder, not the repository root.
8. Allow Android Studio to create `visitor_app/local.properties` and complete Gradle
   sync.
9. Put the approved Android Firebase client file at
   `visitor_app/app/google-services.json`.

Only the computer that runs the PHP push worker needs the server service-account key
and `phone_tracker/config/mobile_api.php`. Ordinary Android testers do not need either
server secret.

## Create one Office Personnel account per destination

Office access is intentionally isolated by destination. An IT account cannot see IS,
CS, Dean's Office, or Tech Support requests. Sign in as Admin, open **User Management**,
and create at least one active **Office Personnel** account for each office being
tested:

- IT Department (`IT`)
- IS Department (`IS`)
- CS Department (`CS`)
- Dean's Office (`DEANS`)
- Tech Support (`TECH_SUPPORT`)

When testing an appointment, sign into the Office dashboard with the account assigned
to the same destination selected in the visitor app. Do not solve missing requests by
showing every office's records to one personnel account; that would bypass the intended
access boundary.

## Phone connection to a teammate's local XAMPP server

The phone and computer must be on the same trusted Wi-Fi network. On the computer,
run `ipconfig` and copy its active Wi-Fi IPv4 address. Add this line to
`visitor_app/local.properties`, replacing the example address:

```properties
ISATU_API_BASE_URL=http://192.168.1.25/visitor-management-system/phone_tracker/api/v1/
```

Allow Apache through Windows Firewall on private networks. The emulator can keep the
default `10.0.2.2` address. Local HTTP is enabled only in debug builds; production must
use HTTPS.

## Use the Security scanner from a phone

The Security dashboard is a responsive web page, so guards do not need a separate
Android app. Connect the guard's phone to the same trusted Wi-Fi as the XAMPP
computer, then open this address in the phone browser (replace the example IP):

```text
http://192.168.1.25/visitor-management-system/phone_tracker/login.html
```

Sign in with an active Security account and open **Scan QR**. On a local `http://`
address, mobile browsers block the embedded live camera. Use **Take QR photo** and,
after pressing the shutter, tap the camera app's checkmark or **Use photo** to return
the image to the website. Do not tap the QR link suggested by the camera app. You can
also use **Choose QR screenshot** for a saved test image. The website decodes the
image on the phone and sends the pass to the same
`scan_appointment.php` endpoint used by the desktop scanner. The resulting check-in
is written to the central MySQL database and appears on the Security computer.

Continuous live scanning is available on `localhost` and when the deployed site uses
trusted HTTPS. For phone testing, use a QR shown on a second device and allow Apache
through Windows Firewall on private networks. Do not expose the local XAMPP server
directly to the public internet.

## Build and install the test app

The simplest method is Android Studio's **Run app** button with a USB-debugging phone
or emulator selected. The command-line verification used by the project owner is:

```powershell
.\gradlew.bat testDebugUnitTest lintDebug assembleDebug
```

The debug APK is generated at
`visitor_app/app/build/outputs/apk/debug/app-debug.apk`. Do not distribute the unsigned
release APK as a production application.

## Required acceptance workflow

1. Register a visitor, accept tracking consent, and confirm immediate sign-in.
2. Create a walk-in pass for an office accepting visitors and scan it in Security.
3. Separately request an available appointment with one of the five approved offices.
4. Approve it from the matching Office dashboard.
5. Confirm the phone receives a notification and displays the appointment QR pass.
6. Scan the appointment pass from Security during its valid window.
7. Grant Android's one-time precise-location permission during signup. Keep the visitor
   pass open while Security scans it and confirm sharing starts automatically after check-in.
8. Confirm Security sees the live marker and route updates.
9. Disconnect the phone briefly, reconnect it, and confirm queued points upload.
10. Complete the visit and confirm tracking stops and later uploads are rejected.

Record the phone model, Android version, tester, date, and any failed step. Do not use
real visitor personal data during development testing.
