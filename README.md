# Zentrix — Event Management and Information System

Zentrix (presented in the interface as **Zentrix**) is a web-based campus event management system. Its main purpose is to let authorized users manage campus events while participants discover events, register, and record their attendance.

This README is a presentation-oriented guide to the main event and attendance features. It follows the current code in this repository and focuses on the files that implement those workflows.

## 1. Main purpose and important features

The system brings the event process into one place:

1. Administrators and event organizers create and maintain campus events.
2. Participants browse upcoming or ongoing events and register.
3. Participants record attendance through camera-based face verification and GPS coordinates.
4. Participants can time out at the end of an event using another face verification.
5. Administrators can review attendance records, timestamps, photos, and saved location coordinates.
6. Event organizers and administrators can send event announcements through in-app notifications and email.

### Main user roles

| Role | Main responsibility |
| --- | --- |
| Administrator | Oversees events and system activity; manages event records and views attendance. |
| Event Organizer | Creates and manages assigned events and communicates with registered participants. |
| Staff | Has access to the staff dashboard and attendance monitoring views supported by the current role checks. |
| Participant | Browses events, registers, manages their event participation, registers a face, and checks in/out. |

Role names are checked in PHP sessions. Some pages have their own role checks, so the exact access rules are described in the file guide below.

## 2. Main system workflow

### A. Event creation and management

1. An administrator or event organizer opens **Admin Portal → Events**.
2. The user enters the event title, description, venue, start and end date/time, capacity, status, optional image URL, and optional map coordinates.
3. The server validates the required date/time values and makes sure the end is later than the start.
4. The event is saved to the Supabase `events` table.
5. Event status is displayed according to the schedule: Upcoming, Ongoing, or Completed.
6. When an event is created, the current page also attempts to send an announcement to eligible participant accounts.
7. The event can later be edited, deleted, or used to send another notification.

**Important:** The event form stores capacity, but the participant registration code reviewed here does not show a capacity-limit check. Do not claim that the system automatically blocks registrations when capacity is reached unless that behavior is verified in the deployed version.

### B. Participant registration

1. A participant signs in and opens **Browse Events** or the participant dashboard.
2. The system loads event records from Supabase.
3. Events with missing/invalid end times or events that have already ended are excluded from the browse list.
4. The participant selects an event and submits its event ID.
5. The server checks that the event exists and has not ended.
6. The system creates an `event_registrations` record containing the participant ID, event ID, and registration status.
7. The participant can view their registrations under **My Events**.

The current browse-events handler writes the registration status as **Approved** immediately. The participant dashboard also contains registration handling that checks for an existing registration and updates or inserts it.

### C. Face registration

1. The participant opens **Face Registration**.
2. The browser asks for camera access and displays a live camera preview.
3. The participant gives explicit consent for biometric face-template processing.
4. The page captures an image and sends it to `save-face-registration.php`.
5. PHP checks the participant session, request method, CSRF token, consent, and image input.
6. PHP forwards the image to the protected Python face-recognition service.
7. Python detects a single face, generates a 128-value face descriptor, and stores that descriptor in the Supabase `participant_face_profiles` table.
8. The stored record includes consent information and the face model identifier.

The Python service stores a mathematical face descriptor for matching. The registration page explains that this is used for attendance identity verification.

### D. Check-in

1. The participant opens **My Events** and selects **Check in** for an event.
2. The check-in page starts the camera and requests the device's GPS coordinates.
3. The participant captures a camera image.
4. The browser sends the event ID, coordinates, and captured image to `process-check-in.php`.
5. PHP confirms that the user is a participant and that the event exists.
6. The server checks the event schedule using Philippine time (Asia/Manila). Check-in is rejected before the event starts or after it ends.
7. PHP checks whether the participant already has an attendance record for that event.
8. The image is sent to the Python service, which compares the new face descriptor with the participant's registered descriptor.
9. If verification succeeds, PHP inserts an `attendance` record with the event ID, participant ID, check-in time, status **Present**, photo data, and submitted latitude/longitude.
10. The participant receives a success or error message.

**GPS limitation:** The current check-in handler saves the submitted latitude and longitude as attendance evidence. The reviewed code does not compare those coordinates with the event's saved map coordinates, so it should not be described as a geofence or distance-based location restriction.

### E. Time-out

1. The participant returns to **My Events** and chooses **Check out** for an event with an existing check-in.
2. The time-out page requests a camera image and GPS coordinates.
3. The server verifies the participant session, event schedule, and existing attendance record.
4. It rejects a time-out if there is no check-in, if the participant already timed out, or if the event is outside its allowed time window.
5. The Python service verifies the participant's face again.
6. If successful, PHP updates the existing attendance row with the time-out timestamp, time-out photo data, and time-out coordinates.

This gives the attendance record both a check-in and a time-out time. The participant dashboard uses these timestamps to calculate recorded attendance duration.

### F. Attendance monitoring

1. An administrator or permitted staff user opens the Admin Dashboard or Attendance Logs.
2. The dashboard retrieves attendance records for active/ongoing events and displays recent activity.
3. Attendance Logs retrieves attendance rows and participant profile details.
4. The user can filter records by event, course, and section.
5. The page displays the event, participant, check-in/time-out times, saved photo evidence, and map links based on the recorded coordinates.

## 3. Important files and what each one does

The following files are the core presentation path. The numbered steps describe the main execution order inside each file.

### Event management

#### `admin/events.php` — Event management page

- **Step 1:** Starts the PHP session and sets the timezone to Asia/Manila.
- **Step 2:** Loads the database helper, authentication helper, and email helper.
- **Step 3:** Allows only the administrator and event organizer roles.
- **Step 4:** Defines helper functions for safe HTML output, Manila date formatting, event-status calculation, and conversion of local date/time input to an ISO date with a +08:00 offset.
- **Step 5:** Builds and validates the event payload, including title, description, venue, schedule, capacity, status, image URL, and optional latitude/longitude.
- **Step 6:** Handles POST actions: create, edit, delete, and send notification.
- **Step 7:** For event organizers, checks that the event belongs to their organizer account before editing or deleting it.
- **Step 8:** Retrieves events from Supabase and prepares them for the event list and calendar.
- **Step 9:** Uses JavaScript and a map picker to search for a venue and save selected map coordinates.
- **Step 10:** Displays event information and provides the create/edit/notification interfaces.

#### `admin/dashboard.php` — Admin/staff overview

- **Step 1:** Checks the session and allows administrator, event organizer, and staff roles.
- **Step 2:** Retrieves user and event information from Supabase.
- **Step 3:** Calculates dashboard totals and event-related statistics.
- **Step 4:** Retrieves attendance records associated with ongoing events.
- **Step 5:** Joins attendance data with participant profiles and event titles for recent activity.
- **Step 6:** Shows attendance activity and links to the full Attendance Logs page.
- **Step 7:** Also contains equipment-borrowing activity; this is a supporting module, not the core event/attendance workflow.

#### `admin/attendance-logs.php` — Attendance records and evidence

- **Step 1:** Starts the session, loads database/auth helpers, and sets Philippine time.
- **Step 2:** Checks the role. The current code explicitly allows **administrator** and **staff**.
- **Step 3:** Loads event names and attendance records from Supabase.
- **Step 4:** Loads participant profiles to obtain course and section information.
- **Step 5:** Applies the selected event, course, and section filters.
- **Step 6:** Formats timestamps in Manila time.
- **Step 7:** Displays check-in and time-out evidence, timestamps, and map links for recorded coordinates.

### Participant event features

#### `participant/browse-events.php` — Browse and register

- **Step 1:** Starts the session and allows only participants.
- **Step 2:** Defines a helper that determines whether an event has ended.
- **Step 3:** On registration, reads the submitted event ID and confirms that the event exists.
- **Step 4:** Rejects registration if the event has ended or has an invalid end time.
- **Step 5:** Inserts an `event_registrations` row with the participant ID, event ID, and Approved status.
- **Step 6:** Retrieves events, removes ended/invalid events, and displays the remaining event cards.

#### `participant/dashboard.php` — Participant home page

- **Step 1:** Checks that the signed-in account has the participant role.
- **Step 2:** Loads the participant's profile, registrations, attendance records, notifications, and event data.
- **Step 3:** Handles event registration and checks the event schedule on the server.
- **Step 4:** Matches attendance records to each registered event.
- **Step 5:** Calculates registered-event count, attendance rate, and recorded attendance hours from check-in/time-out timestamps.
- **Step 6:** Combines announcements and attendance updates into a recent-activity feed.
- **Step 7:** Displays the participant's event overview, progress, and links to event and face-registration pages.
- **Step 8:** Uses JavaScript calendar and event-detail dialogs for event browsing.

#### `participant/my-events.php` — Participant's registered events

- **Step 1:** Retrieves the current participant's event registrations.
- **Step 2:** Retrieves event details and attendance records.
- **Step 3:** Matches each registration to its event and attendance record.
- **Step 4:** Checks whether the participant has checked in and whether a time-out is already recorded.
- **Step 5:** Displays the correct next action: Check in, Check out, or an unavailable state.

#### `participant/check-in.php` — Camera/GPS check-in screen

- **Step 1:** Checks the participant session and requested event ID.
- **Step 2:** Checks whether a face profile is already registered.
- **Step 3:** Starts the camera and requests GPS permission.
- **Step 4:** Captures the camera image and coordinates when the participant presses the check-in button.
- **Step 5:** Sends the data to `process-check-in.php`.
- **Step 6:** Displays the server's success or failure response.

#### `participant/process-check-in.php` — Check-in validation and database write

- **Step 1:** Accepts a JSON POST request from the check-in page.
- **Step 2:** Confirms that the request belongs to a signed-in participant.
- **Step 3:** Reads the event ID, latitude, longitude, and captured image.
- **Step 4:** Loads the event and validates its start/end schedule in Asia/Manila time.
- **Step 5:** Prevents a duplicate check-in for the same participant and event.
- **Step 6:** Calls the Python face service's `/verify` endpoint.
- **Step 7:** If the face matches, inserts the attendance row with timestamp, Present status, photo data, and coordinates.
- **Step 8:** Returns a JSON success or error message to the browser.

#### `participant/time-out.php` and `participant/process-time-out.php` — Time-out screen and handler

- **Screen file:** checks the participant and event, loads the camera, captures the image and GPS coordinates, then sends them to the handler.
- **Handler Step 1:** Confirms participant access and a valid POST request.
- **Handler Step 2:** Loads the event and checks its schedule.
- **Handler Step 3:** Finds the participant's existing attendance record.
- **Handler Step 4:** Rejects missing check-in records and duplicate time-outs.
- **Handler Step 5:** Calls the Python face-verification service.
- **Handler Step 6:** Updates the attendance row with time-out time, photo data, and coordinates.

### Face recognition

#### `participant/face-registration.php` — Register a face

- **Step 1:** Confirms participant access and creates a CSRF token.
- **Step 2:** Checks whether a face profile already exists.
- **Step 3:** Opens the camera and asks the participant to give consent.
- **Step 4:** Captures an image and sends it to `save-face-registration.php`.
- **Step 5:** Shows whether registration succeeded.

#### `participant/save-face-registration.php` — Secure registration endpoint

- **Step 1:** Accepts a JSON POST request.
- **Step 2:** Checks participant access, request method, CSRF token, explicit consent, and image data.
- **Step 3:** Calls the Python face service's `/register` endpoint.
- **Step 4:** Returns the face service's result to the browser.

#### `includes/face-service.php` — PHP-to-Python bridge

- **Step 1:** Reads the face service URL and shared service token from server environment variables.
- **Step 2:** Sends a JSON request to the selected face-service endpoint.
- **Step 3:** Includes the authorization token in the request header.
- **Step 4:** Returns the Python service's HTTP status and JSON response to PHP.

#### `face-service/app.py` — Python face-recognition service

- **Step 1:** Creates the FastAPI service and defines image-size and face-match settings.
- **Step 2:** Authorizes PHP requests using the shared face-service token.
- **Step 3:** Decodes and validates the submitted image.
- **Step 4:** Uses the face-recognition library to detect exactly one face.
- **Step 5:** Generates a 128-value face descriptor.
- **Step 6:** For registration, saves the descriptor and consent details to Supabase.
- **Step 7:** For verification, loads the participant's saved descriptor and compares it with the new scan.
- **Step 8:** Returns success only when the face distance is within the configured threshold.

### Shared backend and authentication

#### `config/database.php` — Supabase connection

- **Step 1:** Reads Supabase configuration from server environment variables or the local `.env` file.
- **Step 2:** Defines `supabaseRequest()` for normal REST requests using the configured API key.
- **Step 3:** Defines `supabaseServiceRequest()` for server-only requests using the service-role key.
- **Step 4:** Sends GET/POST/PATCH/DELETE requests and returns the HTTP status and decoded JSON response.

#### `includes/auth.php` — Session and role helpers

- **Step 1:** Starts a session when one is not already active.
- **Step 2:** Provides `isLoggedIn()` to check for a user ID in the session.
- **Step 3:** Provides `requireAuth()` and `requireRole()` for page protection.
- **Step 4:** Provides `logAudit()` to write audit activity to Supabase.
- **Step 5:** Provides `redirectByRole()` to route users to the appropriate portal.

#### `login.php`, `register.php`, `auth-callback.php`, and `logout.php`

- **`register.php`:** accepts account details, validates course/section, creates a profile, and sends an email-verification link.
- **`login.php`:** finds the account by email, verifies the password hash, checks active/verified status, creates a session, and redirects according to role.
- **`auth-callback.php`:** handles the return from the Supabase Google sign-in flow.
- **`logout.php`:** ends the signed-in session.

## 4. Main data records used by the event workflow

The PHP files query these Supabase tables. This list is based on the table names and fields referenced by the current application code; it is not a replacement for the full database schema.

| Table | Purpose in the system |
| --- | --- |
| `profiles` | User identity, email, role, course, section, and account verification details. |
| `events` | Event title, description, venue, schedule, capacity, status, image, organizer, and optional coordinates. |
| `event_registrations` | Connects a participant to an event and stores registration status. |
| `attendance` | Stores participant/event attendance, check-in and time-out timestamps, face-photo data, and GPS coordinates. |
| `participant_face_profiles` | Stores the participant's face descriptor, consent details, and face model. |
| `notifications` | Stores in-app event announcements. |
| `audit_logs` | Receives audit activity through the authentication helper. |

Other modules also use equipment-related tables and QR-token records.

## 5. Other features outside the main presentation focus

The repository includes supporting features such as:

- **Notifications:** event announcements can be saved in the in-app notifications table and sent through the email helper.
- **Equipment borrowing:** admin and participant equipment pages support equipment inventory and borrowing workflows.
- **Weather widget:** dashboard weather display is a supplementary information feature and is not part of event registration or attendance processing.
- **PWA support:** `manifest.json`, `sw.js`, and `js/pwa.js` support installable-app behavior and service-worker registration.

## 6. Technologies used

- **PHP** — server-side pages, sessions, role checks, validation, and API requests.
- **HTML/CSS/JavaScript** — page structure, visual interface, camera/GPS browser access, calendar, and interactive controls.
- **Supabase (PostgreSQL + REST API)** — application data storage and retrieval.
- **Python, FastAPI, face_recognition, dlib, NumPy, Pillow** — face registration and identity verification service.
- **Brevo email API** — account verification and event email notifications through the mail helper.
- **Render** — application hosting, with the Python face service configured separately through environment variables.

## 7. Short presentation explanation

> Zentrix is a campus Event Management and Information System designed to simplify how school events are organized and how participant attendance is recorded. Administrators and event organizers can create, update, and manage events, while participants can browse available events and register through their accounts. For attendance, participants register their face first, then use camera-based face verification and GPS coordinates to check in and time out. The system saves the attendance timestamps and supporting evidence in Supabase, where administrators and permitted staff can review attendance logs and filter records by event, course, or section. It also supports event announcements through in-app notifications and email.

## 8. Suggested live demonstration order

1. Sign in as an administrator or event organizer.
2. Open **Events** and show the event list, calendar, and create/edit event form.
3. Point out the event schedule, venue, capacity, and optional map location.
4. Sign in as a participant and open **Browse Events**.
5. Register for an available event and show **My Events**.
6. If the demo account has not registered a face, show **Face Registration** and the consent step.
7. Open the event's **Check in** page and explain the camera, GPS, server schedule validation, and face verification.
8. Show **My Events** again to explain the check-out action.
9. Return to the admin portal and show **Attendance Logs**, including event/course/section filters and recorded check-in/time-out evidence.

**Demo tip:** Prepare a test participant with a registered face and an event whose schedule is currently open. Camera access requires browser permission, and browser GPS requires location permission. A successful demonstration depends on the Python face service and Supabase credentials being available.

## 9. Important implementation notes for the presentation

- Event dates are processed and displayed using **Asia/Manila (UTC+08:00)**.
- The check-in and time-out endpoints validate event schedule server-side; changing browser-side display alone cannot bypass these checks.
- Duplicate check-ins and duplicate time-outs are rejected by the PHP handlers.
- Face recognition is performed by a separate protected Python service, not by JavaScript in the browser.
- GPS coordinates are recorded with attendance, but the reviewed handler does not enforce a distance boundary around the event venue.
- The current participant registration handler sets new registrations to **Approved**. Describe it as the current registration workflow, not as an admin approval queue.
- The current code uses Supabase tables directly through REST requests. The full production schema and database policies should be checked in the Supabase project before making claims about every database constraint.
