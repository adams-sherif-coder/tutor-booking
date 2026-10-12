# Tutor Booking — Frontend Integration and Testing Guide

This guide describes the **implemented backend**, including its current limits. Use it to build or test a frontend without guessing routes, field names, permissions or booking behavior.

Project: `D:/Desktop/task back pyra/tutor-booking-pyra-20260929`  
Default API: `http://127.0.0.1:8000/api`  
API time zone: **UTC**  
Authentication: **Laravel Sanctum bearer tokens**

## Documentation package

- [Complete endpoint reference](API_ENDPOINTS.md): all 27 operations, request examples, full response examples, filters, roles and failure cases.
- [OpenAPI 3.0 reference](openapi.json): import into an OpenAPI-compatible viewer or API client.
- [Postman collection](Tutor-Booking.postman_collection.json): runnable requests with saved role tokens.
- [Frontend JavaScript client](frontend-api-client.mjs): dependency-free `fetch` wrapper and date helpers; usable from a browser module or a bundler.
- [Backend setup](../README.md): installation, database setup and implementation details.

Contents: [1. Start locally](#1-start-locally), [2. Authentication](#2-authentication-and-browser-connection), [3. Responses](#3-response-contract-and-errors), [4. Data models](#4-response-data-models), [5. Input rules](#5-request-field-rules), [6. Booking rules](#6-booking-and-availability-rules), [7. Frontend behavior](#7-frontend-screens-and-behavior), [8. JavaScript examples](#8-javascript-integration), [9. Manual testing](#9-manual-end-to-end-test-plan), [10. Negative tests](#10-negative-and-boundary-test-checklist), [11. Troubleshooting](#11-troubleshooting), [12. Current limits](#12-current-api-limits).

## 1. Start locally

Start WAMP's MySQL service. The unique application database was created and seeded during implementation:

```text
tutor_booking_pyra_20260929
```

Run the backend from the **unique project folder**, not the other `booking` folder:

```powershell
cd 'D:/Desktop/task back pyra/tutor-booking-pyra-20260929'
php artisan serve --host=127.0.0.1 --port=8000
```

In a second terminal in the same folder:

```powershell
php artisan queue:work --tries=3 --timeout=60
```

If port 8000 is occupied, use another port and change the frontend API base URL and Postman `base_url`. A frontend at `http://localhost:5173` can call the API at `http://127.0.0.1:8000/api` with the current CORS settings.

Do not rerun seeders on the already-seeded database. Do not use `migrate:fresh` just to test a frontend; it deletes tables. For a fresh installation, follow the backend README.

### Demo credentials

| Role | Email | Password |
| --- | --- | --- |
| Student | `student@example.com` | `password` |
| Tutor | `tutor@example.com` | `password` |
| Admin | `admin@example.com` | `password` |

Seed data contains 1 admin, 4 tutors, 5 students, 6 subjects and bookings in all five statuses. Tutor availability is 09:00–17:00 UTC on days +2 through +8 relative to the day seeding ran. If those dates have passed, create new future availability as tutor. Names, additional account emails, subject assignments and IDs may vary; obtain IDs from responses.

The demo password has eight characters. New registrations require at least eight characters and matching confirmation.

## 2. Authentication and browser connection

### Headers

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <token-returned-by-login-or-register>
```

`Content-Type` is needed when sending a JSON body. Every endpoint except `POST /register` and `POST /login` requires authentication, including tutor discovery and the subject list.

1. Register or log in.
2. Read the token from `response.data.token` when using native `fetch` after parsing JSON.
3. Keep the current user's token and `data.user` in your auth state.
4. Send that token in the Authorization header.
5. Use `GET /me` to validate restored authentication and load the authoritative user role.
6. After logout, clear the local token, user, cached private data and open booking selections.

With Axios, the parsed API envelope is `axiosResponse.data`, so the token is `axiosResponse.data.data.token`. With the provided client, `login()` returns the unwrapped data and saves the token in that client instance.

Tokens are opaque strings; do not parse them as JWTs. No refresh-token endpoint exists. Current configuration has no automatic token expiration. Logout revokes only the current token. Admin deactivation revokes all of the target user's tokens; reactivation requires a new login. A revoked token normally produces 401, while an authenticated inactive account can produce 403. Login with correct credentials for an inactive user returns 403.

For local testing, keeping tokens in memory is sufficient. Separate client instances or browser profiles prevent accidental role mixing. If the frontend adds persistent browser storage, handle its security implications and validate the token on startup; never commit real tokens to source control.

### CORS and cookies

The inspected application configuration allows all origins, methods and headers for `/api/*`, with `supports_credentials=false`. Use bearer authentication with `credentials: 'omit'` in `fetch`, or `withCredentials: false` in Axios. No CSRF-cookie request is needed for this API flow. Do not use `mode: 'no-cors'`, which prevents reading the response.

Serve the frontend through its development server rather than opening HTML using `file://`. A Vite development proxy to the backend is also an option; use `/api` as the client base URL when the proxy preserves that path. These are frontend options, not additional backend endpoints.

The current CORS configuration exposes no extra response headers. Cross-origin JavaScript may not be able to read `Retry-After` even when it is present. Stop rapid retries after a 429 and provide a cooldown; use a same-origin proxy or coordinate a CORS header change if you need that header in the UI.

## 3. Response contract and errors

### Success

```json
{
  "data": {
    "user": {
      "user_id": 10,
      "name": "Demo Student",
      "email": "student@example.com",
      "role": "student",
      "is_active": true
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Mutation success messages differ by action. Use HTTP status/`ok` for control flow; do not make success logic depend on a message string. Logout, availability deletion and notification marking return `data: []`, not `{}` or `null`. Those actions return **200**, not 204.

### Validation or domain error

```json
{
  "data": [],
  "message": "The tutor or student already has an overlapping booking.",
  "status": 422,
  "ok": false,
  "errors": {
    "start_time": [
      "The tutor or student already has an overlapping booking."
    ]
  }
}
```

`errors` is optional and maps field names to arrays of strings. Display the first field error beside its input and any remaining errors in an accessible summary. Array errors may use keys such as `subject_ids.0`. Framework-generated message text may vary; rely on status and field keys.

Domain rules can use keys that are not input controls:

| Error key | Meaning / display location |
| --- | --- |
| `start_time` | Past time, invalid interval, unavailable time, or participant overlap; display near date/time selection |
| `end_time` | Request end-time validation; display at end-time control |
| `tutor_id` | Invalid tutor or participant/profile requirements; display a booking-form alert |
| `booking` | Cancellation/rescheduling eligibility or cutoff; display a dialog-level alert |
| `status` | Invalid transition or timing; display at the requested action |
| `availability` | Cannot alter/delete this range; display beside the range/dialog |

### HTTP handling

| Status | Meaning | Frontend handling |
| --- | --- | --- |
| 200 | Read/update/action succeeded | Use returned data; invalidate affected cached views |
| 201 | Registration, availability or booking created | Use returned ID/resource |
| 401 | Missing/invalid/revoked token, or invalid login credentials | On protected calls clear auth and request login; on login show the credential error |
| 403 | Wrong role/owner or inactive account | Explain the restriction; do not retry with another ID |
| 404 | Resource missing/hidden | Show not found and refresh the parent list |
| 405 | Route exists but method is wrong | Correct method/path; no generic booking PUT/PATCH exists |
| 422 | Input or business rule rejected | Show errors; keep user-entered fields for correction |
| 429 | Rate limit | Stop rapid polling/retries and show a cooldown |
| 500 or other 5xx | Server failure | Show a recoverable error; verify state before retrying mutations |

Auth routes share a limit of **10 requests/minute per IP**. Protected API routes use **120 requests/minute per user**. These limits apply across routes in their group, not independently to each endpoint.

Network failures/CORS failures have no reliable API status or JSON envelope. Infrastructure errors can also return non-JSON. The provided client handles unexpected response bodies. Do not automatically resend a timed-out booking request: there is no idempotency-key support. First reload the user's bookings to determine whether it succeeded.

### Pagination

Paginated responses place the list and pagination inside `data`:

```json
{
  "data": {
    "bookings": [],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 0
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Use `page` >= 1 and `per_page` from 1 to 100; defaults are 1 and 15. There are no pagination links/cursors. Paginated list keys are `tutors`, `availability`, `bookings`, `users` and `notifications`. The subject list and bookable tutor availability are not paginated. Reset page to 1 after changing filters and omit empty optional filters instead of sending empty strings or null.

## 4. Response data models

Full JSON examples for each endpoint are in [API_ENDPOINTS.md](API_ENDPOINTS.md). Types below describe response values, not request bodies.

| Model | Fields |
| --- | --- |
| User | `user_id: number`, `name: string`, `email: string`, `role: student\|tutor\|admin`, `is_active: boolean` |
| Subject | `subject_id: number`, `name: string` |
| Tutor | `tutor_id: number`, `name: string`, `bio: string\|null`, `hourly_rate: string\|null`, `subjects: Subject[]`, `bookable_availability_url: string` |
| Free interval | `starts_at: ISO timestamp`, `ends_at: ISO timestamp` |
| Availability record | `availability_id: number`, `tutor_id: number`, `starts_at`, `ends_at` |
| Booking | `booking_id: number`, `student: User`, `tutor: Tutor`, `starts_at`, `ends_at`, `status`, `hourly_rate: string`, `total_price: string`, `created_at` |
| Notification | `notification_id: UUID string`, `data: event snapshot`, `read_at: timestamp\|null`, `created_at: timestamp` |

Important distinctions:

- `user_id`, `tutor_id`, `subject_id`, `booking_id`, and `availability_id` are distinct response keys. Do not assume every object has `id`.
- A booking's participant identifiers are nested at `student.user_id` and `tutor.tutor_id`.
- Tutor `hourly_rate` describes the current profile. Booking `hourly_rate` is the rate captured for that booking; use the booking value for its cost breakdown.
- Prices are decimal **strings**, such as `"50.00"`. A preview may convert them for display calculations; the returned `total_price` is authoritative.
- There is no currency code or payment field. Agree on a display currency with the product owner rather than assuming EGP/USD from the environment.
- Public tutor objects omit email. Booking participant data is private and returned only to authorized viewers.
- `starts_at` and `ends_at` include `+00:00`. Other serialized dates may use a `Z` suffix and fractional seconds. Both represent UTC; parse them as ISO timestamps.
- There are no `can_cancel`, `can_reschedule`, `can_confirm`, cutoff, server-clock or timezone-preference fields on booking resources. Client button rules are advisory and must handle server rejection.

## 5. Request field rules

All methods, examples and access restrictions appear in the endpoint reference. JSON field names use snake_case. Send only documented fields: unknown fields are not a supported extension mechanism, and controlled fields cannot be used to override ownership/status/price.

### Registration — `POST /register`

| Field | Required | Rule |
| --- | --- | --- |
| `name` | Yes | Nonempty string, max 255 |
| `email` | Yes | Valid email, max 255, unique |
| `password` | Yes | String, 8–128 characters |
| `password_confirmation` | Yes | Must match password |
| `role` | Yes | `student` or `tutor`; never `admin` |

Omit `is_active` and `hourly_rate`; nonempty prohibited values return 422. A new tutor completes their profile using the tutor endpoint after registration.

### Login — `POST /login`

`email` must be a valid email and `password` a nonempty string. This route does not enforce registration's minimum password length, allowing seeded credentials. No `role` parameter is required. The returned user object supplies the role.

### Tutor profile — `PUT /tutor/profile`

| Field | Required | Rule |
| --- | --- | --- |
| `name` | No | If supplied, nonempty string, max 255 |
| `bio` | Yes | Nonempty string, max 5000 |
| `hourly_rate` | Yes | Numeric, 0.01–999999.99, at most two decimal places; number or numeric string |
| `subject_ids` | Yes | Array of 1–30 existing, distinct integer subject IDs |

The entire subject list replaces the current assignment. Always submit `bio`, `hourly_rate` and `subject_ids`, even when only changing the name. Omit `role` and `is_active`.

### Time bodies — availability create/update and booking reschedule

| Field | Required | Rule |
| --- | --- | --- |
| `date` | Yes | Exact `YYYY-MM-DD`, interpreted as UTC |
| `start_time` | Yes | Exact 24-hour `HH:mm`; no seconds or UTC offset |
| `end_time` | Yes | Exact `HH:mm`, later than start on the same UTC date |

The full start timestamp must be strictly in the future. Input is a date plus two times; **do not send output fields `starts_at`/`ends_at` as the request format**. Equal times, overnight intervals and start-at-now are invalid. A one-minute interval is supported; there is no fixed 30/60-minute session requirement.

Availability derives its tutor from authentication; omit `tutor_id`. Rescheduling keeps the original tutor/student; omit both IDs and all price/status fields.

Booking creation additionally requires `tutor_id`, an existing user ID that passes the active-tutor checks. Omit `student_id`, `status`, `hourly_rate` and `total_price`.

### Admin status — `PATCH /admin/users/{user}/status`

Send `{"is_active": false}` or `{"is_active": true}` using a JSON boolean. For the list filter, send `?is_active=0` or `?is_active=1`; avoid textual `"false"`/`"true"` query values.

### Query filters

| Endpoint | Effective filters |
| --- | --- |
| `/tutors` | `name` max 255, existing `subject_id`, `page`, `per_page` |
| `/tutors/{tutor}/availability` | `date` |
| `/tutor/availability` | `page`, `per_page` |
| `/bookings` | `period=upcoming\|past`, valid `status`, `date`, pagination; admin additionally uses `tutor_id`, `student_id` |
| `/admin/bookings` | Same filters, including participant IDs |
| `/admin/users` | `role=student\|tutor`, `is_active=0\|1`, pagination |
| `/notifications` | `page`, `per_page` |

Some controllers reuse a validation class that accepts additional query fields. Acceptance does not mean filtering is implemented: for example `date` does not filter the tutor search, and `status` does not filter notifications. Use only the effective filters above. Sending participant filters as a student/tutor never broadens access and can still fail validation if an ID does not exist.

Name search uses SQL LIKE matching; `%` and `_` have wildcard meaning. No configurable sorting parameter or date-range filter is implemented.

## 6. Booking and availability rules

### Timezone handling

All time inputs, comparisons, database timestamps and date filters use UTC. Label UTC controls explicitly. Alternatively show local times and convert the selected **instants** to UTC before constructing the request body. Do not append `Z` to a local time and assume that performs conversion. Cairo's offset must not be hardcoded.

HTML `datetime-local` has no timezone. The provided helper interprets it in the browser's local timezone, then converts to UTC. If the UI supports an arbitrary named timezone different from the browser, that conversion needs explicit timezone-aware handling. If start/end become different UTC dates, the API rejects that interval; ask the user to choose a supported interval.

Show dates with `new Date(isoTimestamp)` and `Intl.DateTimeFormat`. When extracting UTC request values use `toISOString()`, not local date getters. Do not derive a displayed local date by taking the first 10 characters of a UTC timestamp.

### Availability

1. Tutors create non-overlapping date/time ranges. Adjacent ranges may touch at an endpoint.
2. A session must fit completely inside **one** range. Two adjacent 09:00–10:00 and 10:00–11:00 records do not allow one 09:30–10:30 booking.
3. Pending and confirmed bookings block time immediately. No temporary client-side hold exists.
4. Student-facing availability subtracts those reservations and returns only future free portions. An incomplete tutor profile returns an empty free-time list.
5. The raw tutor availability list retains the entire configured range, including booked time. Use it for editing; use bookable availability for the student picker.
6. Only future, not-yet-started availability records may be edited/deleted. Editing cannot exclude an active booking; deletion cannot remove a range with an active booking.
7. Rejection/cancellation releases time while keeping the booking record.
8. Bookable intervals are a snapshot. Fetch again before selection and refresh after a 422 conflict; another user can reserve the time meanwhile.
9. Free time describes the tutor's calendar. The current student's conflicting sessions are checked again when booking or rescheduling.

### Overlap and concurrency

Intervals are treated as half-open: `[start, end)`. An existing 10:00–11:00 session allows a new session at 11:00, but blocks 10:30–11:30. Both tutor overlap and student overlap across different tutors are prohibited for pending/confirmed bookings.

MySQL transactions and InnoDB row locks serialize conflicting reservations. Two valid requests for the same free slot produce one successful booking and a validation failure for the other. Disable duplicate submits in the UI, but rely on server checks for correctness.

### State transitions and action buttons

```text
pending ----confirm----> confirmed ----complete after end----> completed
   |                         |
 reject                    cancel
   |                         |
rejected                  cancelled

pending ----cancel-----> cancelled
pending or confirmed ----reschedule----> pending at new time
```

| Action | Actor | Starting state | Timing / further rules | Result |
| --- | --- | --- | --- | --- |
| Create | Student | None | Future, available, no participant conflict; both active and tutor profile complete | pending |
| Confirm | Booked tutor | pending | Before start; participants active and tutor profile complete | confirmed |
| Reject | Booked tutor | pending | Before start | rejected |
| Complete | Booked tutor | confirmed | At or after end | completed |
| Cancel | Owning student or booked tutor | pending / confirmed | Future and at least 24h before start by default | cancelled |
| Reschedule | Owning student | pending / confirmed | Original session outside cutoff; new interval passes creation rules | pending |

Admin can view/filter bookings but cannot confirm, reject, cancel, complete or reschedule them. There is no admin override. Terminal bookings cannot be reopened. Past pending sessions remain pending unless a valid transition had occurred earlier; the system has no automatic expiration/completion job.

The cutoff uses the **original** start time at the server when cancellation/rescheduling is received. Exactly 24 hours is eligible with the default configuration, but network delay can cross the boundary. A new booking may start less than 24 hours away; it is then already inside the cancellation window. Rescheduling may also choose a new time inside 24 hours if the original session was eligible. The new session will subsequently be subject to its own cutoff.

Cutoff hours are configurable on the backend and not exposed by an endpoint. This guide describes the default of 24; coordinate client configuration if it changes. Button visibility must not replace handling server-side 403/422 responses.

### Price and reschedule behavior

The server snapshots the tutor rate when creating the booking. Duration-based total uses integer cents with half-up rounding. For example, 90 minutes at `"50.00"` per hour returns `"75.00"`. Booking creation does not accept a student-submitted price.

Rescheduling retains the original hourly rate, changes duration/total as needed, releases the old reservation and reserves the new interval atomically. It always resets status to pending for approval, including an otherwise identical valid reschedule. Both users are notified. A validation failure leaves the original time and status intact. The tutor cannot be changed by rescheduling.

### Notifications

| Event | Recipients |
| --- | --- |
| created | Tutor |
| confirmed / rejected | Student |
| cancelled / rescheduled | Student and tutor, including the acting user |
| completed | No notification currently |

Database and mail notifications are queued after commit. The default mail driver logs locally; no external email is sent unless SMTP is configured. API mutation success is independent of when the worker delivers the notification. Failed jobs require backend inspection.

Notification `data` is an event-time snapshot (`booking_id`, `event`, `status`, `starts_at`, `ends_at`), not necessarily the booking's current state. Open a notification by fetching current booking details. There is no WebSocket stream, unread-count endpoint or mark-all-read endpoint. If polling, use a modest interval such as 30 seconds and pause when the tab is hidden; that interval is a frontend choice.

## 7. Frontend screens and behavior

The following are suggested UI flows; the API does not prescribe a frontend framework.

| Screen | Calls / behavior |
| --- | --- |
| Login/register | Public auth endpoints; route user by returned role |
| Student tutor search | Load subjects, search tutors; debounce search and cancel stale GET requests |
| Tutor details | Show profile/subjects/rate and bookable intervals; empty state for no free time |
| Booking form | Collect UTC date/start/end and tutor ID; show estimate, submit once, display authoritative returned total/status |
| Student bookings | Tabs for upcoming, past and all history; date/status filters; actions based on ownership/state/time |
| Tutor profile | Load own profile and subjects; submit all required fields and complete subject selection |
| Tutor availability | Raw own records for edit/delete, separate free-time preview if needed |
| Tutor bookings | Own booking list; confirm/reject pending, complete ended confirmed, cancel eligible sessions |
| Admin users | List students/tutors with active filter; toggle status and explain token revocation |
| Admin bookings | Filters for tutor/student/status/date; read-only booking details |
| Notification inbox | Own notifications; unread marker from `read_at === null`; mark individual UUID as read |

On booking create/cancel/reschedule/confirm/reject, refresh affected booking details/lists and tutor bookable availability. On tutor availability/profile changes, refresh the owned view and public tutor view. Clear private caches on logout or role/account switch.

An upcoming tab only includes active sessions whose end is in the future. Future cancelled/rejected bookings are in **all history**, not upcoming or past. An in-progress confirmed session still appears upcoming because its end has not passed. Do not relabel past pending/confirmed bookings as completed in the UI.

Account deactivation does not silently cancel sessions. An inactive tutor disappears from public discovery, while authorized booking viewers retain historical participant information. Reactivation restores login eligibility, not old tokens. Existing sessions require handling according to the same booking rules.

## 8. JavaScript integration

Copy [frontend-api-client.mjs](frontend-api-client.mjs) into the frontend source or adapt it. It uses native `fetch`, returns the API's inner `data`, and throws `ApiError` for failed HTTP/API responses.

```javascript
import { createApiClient, ApiError, localDateTimesToUtcBody }
  from './frontend-api-client.mjs';

const api = createApiClient('http://127.0.0.1:8000/api');
const { user } = await api.login('student@example.com', 'password');
console.log(user.role); // student; token is held in this client instance

const { tutors, pagination } = await api.request('/tutors', {
  query: { name: 'Demo', page: 1, per_page: 15 }
});
const tutorId = tutors[0].tutor_id;
const { availability } = await api.request(`/tutors/${tutorId}/availability`);

// Example: browser-local datetime-local control values, converted to UTC.
// Replace these sample values with an actual future available selection.
const interval = localDateTimesToUtcBody('2030-01-15T12:00', '2030-01-15T13:00');
try {
  const { booking } = await api.request('/bookings', {
    method: 'POST', body: { tutor_id: tutorId, ...interval }
  });
  console.log(booking.booking_id, booking.status, booking.total_price);
} catch (error) {
  if (error instanceof ApiError && error.status === 422) {
    console.log(error.errors); // Map each field's string[] to the form.
  } else {
    throw error;
  }
}
```

If your date/time controls already represent UTC, build the body directly without local conversion:

```javascript
const body = { date: selectedUtcDate, start_time: startHHmm, end_time: endHHmm };
await api.request(`/bookings/${bookingId}/reschedule`, { method: 'PATCH', body });
await api.request(`/bookings/${bookingId}/cancel`, { method: 'PATCH' });
```

Use separate instances during multi-role testing:

```javascript
const tutorApi = createApiClient('http://127.0.0.1:8000/api');
const studentApi = createApiClient('http://127.0.0.1:8000/api');
await tutorApi.login('tutor@example.com', 'password');
await studentApi.login('student@example.com', 'password');
// Calls use the identity of their own client instance.
```

Optional filter construction must preserve false/0 and omit only empty values. The provided client does this and encodes query strings. Numeric IDs should be sent as numbers in JSON; prices may be numeric strings. Use `AbortController` for stale searches. Aborting a mutation does not guarantee the server stopped it.

Display timestamps using the user's locale:

```javascript
const displayTime = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium', timeStyle: 'short'
});
const label = displayTime.format(new Date(booking.starts_at));
```

## 9. Manual end-to-end test plan

Use separate role sessions and record returned IDs. This plan creates demo records but does not require deleting/resetting the database. Use a fresh future date with no existing evening availability for repeat runs.

Let `D` be a UTC date at least five days ahead. Use 18:00–22:00 UTC to avoid the original seeded 09:00–17:00 ranges.

| Step | Actor / request | Expected result |
| --- | --- | --- |
| 1 | Student, tutor and admin each `POST /login` with demo credentials | 200; separate token/user objects. Record student.user_id and tutor.user_id |
| 2 | Student `GET /subjects` | 200; save any subject_id |
| 3 | Tutor `PUT /tutor/profile` with bio, hourly_rate `"50.00"`, subject_ids `[savedId]` | 200; subjects/rate shown correctly |
| 4 | Tutor `POST /tutor/availability` with D, 18:00, 22:00 | 201; save availability_id. A repeat may return 422 if the range already exists |
| 5 | Student `GET /tutors/{tutorId}/availability?date=D` | 200; interval includes 18:00–22:00 |
| 6 | Student `POST /bookings` with tutorId, D, 18:00, 19:30 | 201; pending, total_price `"75.00"`; save booking_id |
| 7 | Student reloads tutor availability | Booked interval no longer appears; evening free interval starts at 19:30 |
| 8 | Tutor reads upcoming bookings and confirms this booking | 200; confirmed; student sees updated status |
| 9 | Student reschedules to D, 20:00, 21:00 | 200; pending again, total_price `"50.00"`; old interval freed, new interval blocked |
| 10 | Tutor confirms again | 200; confirmed |
| 11 | Student cancels | 200; cancelled; time released; booking remains in unfiltered history |
| 12 | Student creates another booking for D, 20:00, 21:00 | 201; released time is bookable again |
| 13 | Tutor rejects the new pending booking | 200; rejected; history retained and time freed |
| 14 | Queue worker runs; users load `/notifications` | Expected role-specific event snapshots appear asynchronously |
| 15 | User marks one own notification UUID as read | 200; read_at becomes non-null on refresh |
| 16 | Admin filters `/admin/bookings` by participant IDs, D, status | Correct subset, 200; combine filters with AND |
| 17 | Admin deactivates the demo student | 200; student's old token fails, correct-credential login returns 403 |
| 18 | Admin reactivates student; student logs in again | 200 on both actions; old token remains invalid |
| 19 | Tutor deletes the evening availability after all its sessions are rejected/cancelled | 200; history still exists |
| 20 | Each session logs out | 200; current tokens revoked |

Completion and cutoff tests require appropriate timestamps. For completion, create a short session starting a few minutes in the future, confirm before start, then wait until after end. For cutoff, create a session less than 24 hours away and attempt cancellation/rescheduling: both should return 422. The automated suite also covers these boundaries using a controlled clock.

### Using Postman

Import the existing collection. Run the three login requests, list subjects, update tutor profile, create evening availability, then create/confirm/reschedule/cancel a booking. Collection variables capture tokens and IDs. Its built-in evening example uses 18:00–20:00 and a date five days ahead; the manual test above uses a wider range. Clear/reset `booking_date` when reopening an old collection. Alternative transitions cannot all succeed on the same booking.

## 10. Negative and boundary test checklist

| Scenario | Expected |
| --- | --- |
| Protected route with no token | 401 |
| Wrong login password | 401 |
| Register with role admin | 422 |
| Duplicate registration email / mismatched confirmation | 422 |
| Student calls tutor or admin operations | 403 |
| Student B reads/cancels/reschedules Student A's booking | 403 for existing booking |
| Tutor B edits/deletes Tutor A's availability | 403 |
| Unrelated tutor confirms/rejects/completes a booking | 403 |
| Read another user's notification UUID | 404 on mark-read; inbox never lists it |
| Nonexistent booking/availability path ID | 404 |
| Booking before availability start or beyond its end | 422 |
| Booking spans two adjacent availability records | 422 |
| Past/start-at-now/equal-end/reversed/overnight booking | 422 |
| Nonempty client-submitted student_id, price or status | 422 on booking creation |
| Same tutor/time with another student | 422 after first reservation |
| Same student/time with another tutor | 422 |
| Session starts exactly when prior active session ends, within availability | Allowed |
| New booking exactly covers an unreserved availability range | Allowed |
| Cancelled/rejected time booked again | Allowed if still future/available |
| Availability range overlaps another of tutor's ranges | 422 |
| Availability is shortened/deleted across an active booking | 422 |
| Confirm twice, reject confirmed, complete pending | 422 |
| Complete confirmed before scheduled end | 422 |
| Cancel/reschedule a terminal or past booking | 422 |
| Cancel/reschedule less than cutoff hours before original start | 422 |
| Reschedule to unavailable/overlapping time | 422; original reservation unchanged |
| Reschedule by tutor/admin | 403 |
| Book inactive/incomplete tutor | 422 on create; inactive public tutor detail returns 404 |
| Admin changes another admin's status | 403 |
| per_page > 100, page < 1, malformed filter/date | 422 |
| Combine upcoming with status cancelled | Empty list, because upcoming only includes active statuses |
| Queue worker stopped | Booking still succeeds; notifications wait in queue |

Two frontend requests sent with `Promise.all` should never both reserve the same slot. However, a single PHP development server may process requests serially. The reliable concurrency verification uses separate PHP processes with MySQL:

```powershell
php artisan test --configuration=phpunit.mysql.xml --filter=BookingConcurrencyTest --compact
```

This resets the disposable `tutor_booking_pyra_20260929_test` database; it must never target the application database. The full MySQL implementation suite previously passed 38 tests / 208 assertions, including concurrency and queue processing. These are automated backend checks, not a claim that a frontend UI has already been tested.

## 11. Troubleshooting

| Symptom | Check |
| --- | --- |
| Browser reports failed fetch/CORS | Correct backend host/port; server running; use bearer header and credentials omit; inspect preflight in DevTools; do not use no-cors |
| Postman works, browser does not | CORS, mixed HTTP/HTTPS, proxy setup or wrong frontend base URL |
| 404 for every API route | Include `/api`; start server from the unique project folder; use exact route paths |
| Login works but protected requests return 401 | Exact token extraction and Authorization header; no double `Bearer`; token may have been revoked |
| API returns HTML | Wrong URL/port/server, missing Accept header or an upstream error |
| Role actions fail unexpectedly | Confirm `/me` role and booking owner; ensure student/tutor client tokens were not swapped |
| Tutor shows no free time | Dates may have expired; tutor needs hourly_rate and subjects; all time may be reserved; verify UTC conversion |
| Cancel button fails with 422 | Status or server-side cutoff changed; refetch booking and display the response error |
| Price differs from estimate | Duration or rounding differs; use booking total_price and its captured hourly_rate |
| Notifications absent | Worker running in the correct project/database; inspect jobs/failed jobs; default emails are only logged |
| Deactivated user still appears in booking history | Expected; history and reservations remain intact |
| Future cancelled booking missing from both tabs | Use unfiltered `/bookings`; past is based on session end, upcoming includes active states only |

## 12. Current API limits

The backend does not currently provide password reset/change, email verification, profile-photo upload, student profile editing, recurring availability, overnight sessions, subject CRUD, booking subject selection, cancellation reasons/notes, payments, currency conversion, reviews, chat, external calendars, refresh tokens, idempotency keys, WebSockets, unread counts, bulk notification updates, user deletion, booking deletion, arbitrary booking status editing, automatic booking expiry, or an admin booking override.

No reason/note field is required for cancellation/rejection. No file upload is expected; send JSON. No runtime metadata endpoint exposes the cutoff, currency or permitted actions. If frontend requirements need these capabilities, request backend changes explicitly rather than inventing request fields or endpoints.

## Implementation sources

This documentation was checked against `routes/api.php`, Form Requests, Resources, Policies, Controllers, BookingService, AvailabilityService, BookingTime, notifications, rate limiter and effective CORS configuration. For future changes, keep this guide, endpoint reference, OpenAPI specification and Postman collection synchronized with the implementation.
