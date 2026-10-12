# Tutor Booking API

Laravel 13 REST API using MySQL, Sanctum bearer tokens, Faker and queued Laravel Notifications. It follows the reference project's Controllers / Requests / Resources / Models / Policies structure and `JsonResponseTrait` response format. Booking rules live in small service classes.

## Local installation (WAMP / Windows)

Requirements: PHP 8.3+, Composer, MySQL 8 with InnoDB, and PHP extensions including pdo_mysql, pdo_sqlite (fast tests), mbstring, openssl, fileinfo and curl. No frontend build is needed for this API.

1. Start WAMP's MySQL service. Ensure `php -v` points to a compatible WAMP PHP version.
2. Open a terminal in the project folder and run `composer install`.
3. Copy `.env.example` to `.env` only if `.env` does not exist. Set your MySQL credentials and choose an empty application database. Do not point tests at your application database.
4. Create the application and isolated test databases:

   ```sql
   CREATE DATABASE tutor_booking_pyra_20260929 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE tutor_booking_pyra_20260929_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

5. Use these local settings:

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=tutor_booking_pyra_20260929
   DB_USERNAME=root
   DB_PASSWORD=
   QUEUE_CONNECTION=database
   MAIL_MAILER=log
   BOOKING_CANCELLATION_CUTOFF_HOURS=24
   ```

6. Run:

   ```powershell
   php artisan key:generate
   php artisan migrate --seed
   php artisan serve --host=127.0.0.1 --port=8000
   ```

7. In a second terminal, process notifications:

   ```powershell
   php artisan queue:work --tries=3 --timeout=60
   ```

The API base URL is `http://127.0.0.1:8000/api`. If using Apache instead, set the virtual host document root to the project's `public` folder. Never serve the project root. Production deployments should use HTTPS, `APP_DEBUG=false`, a supervised queue worker and real SMTP credentials.

`migrate --seed` is intended for a fresh database. Demo seed accounts have fixed unique emails; do not rerun DatabaseSeeder over existing demo data. `migrate:fresh --seed` deletes all tables, so use it only when intentionally resetting disposable local data.

## Demo data

All demo passwords are `password`:

| Role | Email |
| --- | --- |
| Admin | admin@example.com |
| Tutor | tutor@example.com |
| Student | student@example.com |

Seeders create 1 admin, 4 tutors, 5 students, 6 subjects, availability from 09:00 to 17:00 UTC for days +2 through +8, and example bookings in every supported status. Dates are relative to seeding time. Additional accounts have Faker-generated email addresses.

## API documentation and walkthrough

For frontend developers, start with the [Frontend Integration and Testing Guide](docs/FRONTEND_GUIDE.md). It covers authentication, browser/CORS setup, request rules, response types, booking behavior, errors and a complete manual testing checklist. The [endpoint reference](docs/API_ENDPOINTS.md) provides examples for all 27 operations. An importable [OpenAPI specification](docs/openapi.json) and [JavaScript API client](docs/frontend-api-client.mjs) are included.

Import `docs/Tutor-Booking.postman_collection.json` into Postman. Collection variables hold the base URL, tokens, identifiers and example date. Login requests automatically save each role's token. The collection generates an example date five days ahead; clear `booking_date` when reusing an old collection.

Suggested walkthrough:

1. Run the three seeded-account login requests.
2. List subjects to capture `subject_id`, then update the tutor profile.
3. Create evening availability (18:00–20:00) on `booking_date`.
4. Create a student booking (18:00–19:00), which captures `booking_id`.
5. Confirm as tutor, reschedule as student, then confirm again or cancel.
6. Run the queue worker and inspect notifications.

The collection also includes rejection, completion, admin deactivation and deletion examples. These are alternative actions with state prerequisites; running all of them in order against one booking will correctly produce some 422 responses.

Send `Accept: application/json` and `Content-Type: application/json`. Protected endpoints require `Authorization: Bearer <token>`.

| Method | Path under `/api` | Access |
| --- | --- | --- |
| POST | /register, /login | Public |
| POST | /logout | Active authenticated user |
| GET | /me, /subjects, /tutors | Active authenticated user |
| GET | /tutors/{tutor}, /tutors/{tutor}/availability | Active authenticated user |
| GET, PUT | /tutor/profile | Tutor, own profile |
| GET, POST | /tutor/availability | Tutor, own availability |
| PUT, DELETE | /tutor/availability/{availability} | Owning tutor |
| GET | /bookings | Student/tutor own records; admin all |
| POST | /bookings | Student |
| GET | /bookings/{booking} | Owning student, booked tutor, admin |
| PATCH | /bookings/{booking}/cancel | Owning student or booked tutor |
| PATCH | /bookings/{booking}/reschedule | Owning student |
| PATCH | /bookings/{booking}/confirm, /reject, /complete | Booked tutor |
| GET | /admin/users, /admin/bookings | Admin |
| PATCH | /admin/users/{user}/status | Admin, target student/tutor |
| GET | /notifications | Own notification inbox |
| PATCH | /notifications/{notification}/read | Own notification |

Tutor filters: `name`, `subject_id`. Booking filters: `period=upcoming|past`, `status`, `date`. Admin additionally supports `tutor_id`, `student_id`; these never broaden a student's/tutor's own scope. User filters: `role=student|tutor`, `is_active=0|1`. Paginated lists accept `page`, `per_page` (1–100).

Example booking body:

```json
{
  "tutor_id": 5,
  "date": "2030-01-15",
  "start_time": "10:00",
  "end_time": "11:30"
}
```

Choose an actual tutor ID and future date that has availability. Availability creation uses the same time fields without `tutor_id`; rescheduling uses them without either participant ID. Times use minute precision and must start/end on the same date. Overnight availability can be represented as separate daily ranges, but a booking must fit one range.

Responses use `{data, message, status, ok}`; validation errors additionally include `errors`. Creates return 201, successful reads/updates/deletes return 200, invalid credentials return 401, insufficient permission/inactive accounts return 403, missing resources return 404, invalid input or booking conflicts return 422, and rate limits return 429. Private booking IDs owned by another user return 403. Raw exception details are not returned in API responses.

## Booking decisions

- **Timezone:** UTC for storage, business rules, API inputs and outputs. Convert to/from local time in clients. Date filters use UTC. Cairo local time must not be sent as if it were UTC.
- **Status flow:** `pending -> confirmed -> completed`, `pending -> rejected`, and `pending|confirmed -> cancelled`. A tutor confirms/rejects before the start and completes at/after the end. No general status-update endpoint exists.
- **Rescheduling:** Only the owning student may reschedule an active future booking. The new time is fully revalidated and resets status to pending for fresh tutor approval. The original hourly rate is retained; total price is recalculated for the new duration.
- **Cutoff:** Cancellation and rescheduling require at least 24 hours before the original start (configurable with `BOOKING_CANCELLATION_CUTOFF_HOURS`). This applies equally to student and tutor cancellation. Booking on short notice is allowed, but the cutoff still applies afterward.
- **Availability:** Non-overlapping future ranges. Pending/confirmed bookings reserve time. Rejected/cancelled bookings release it without deleting history. Responses subtract booked intervals and omit past time. Availability is a snapshot and booking must recheck it. Availability describes the tutor's free time; a student's own conflicting sessions are checked at booking time.
- **Pricing:** The server snapshots the tutor's hourly rate and calculates duration-based total price in integer cents with half-up rounding. This assessment does not process payments or convert currencies.
- **Profiles:** A tutor needs a rate and at least one subject before accepting a booking. Public tutor resources omit email and private account fields.
- **Accounts:** Public registration allows student/tutor only. Admin accounts are seeded. Deactivation blocks access, revokes tokens and hides inactive tutors. Existing bookings and history remain intact; deactivation does not silently cancel them. Reactivation requires login again.
- **History:** No booking deletion or blanket soft deletes. Foreign keys restrict deletion of referenced participants. `/bookings` includes all own records; `period=past` means the scheduled end has passed, while `period=upcoming` means pending/confirmed sessions that have not ended. Future cancelled/rejected records remain in the unfiltered history.

## How overlapping and double bookings are prevented

MySQL **InnoDB is explicitly configured**, even on WAMP installations whose server default is MyISAM. Transactions and row locks are essential; do not change the application tables to MyISAM.

1. Every booking create/reschedule/status change runs in a database transaction.
2. `ScheduleLock` locks both participant rows in ascending user-ID order using `SELECT ... FOR UPDATE`. Stable user rows exist even when there are no prior bookings, unlike relying only on a query that might return zero booking rows.
3. Under these locks, the service checks a containing availability range and performs current locking reads for overlapping pending/confirmed bookings belonging to either participant. Intervals overlap when `existing.start < requested.end AND existing.end > requested.start`; back-to-back bookings are valid.
4. Only then does it insert/update and commit. A competing request waits, then sees the committed reservation and fails validation. Rescheduling keeps the original booking intact if validation fails.
5. Availability changes and profile/status changes also use the tutor/user row lock. A range cannot be deleted or shortened across an active booking. Deadlock retries are bounded to five attempts.
6. Notifications dispatch **after commit**, with an immutable event snapshot. Rolled-back operations do not enqueue notifications.

All reservation mutations must continue to use these services. Direct database writes bypass domain rules. A unique index on start time alone would not prevent partial overlaps, so it is not presented as a substitute for this protocol.

## Notifications and queue

`BookingNotification` implements `ShouldQueue` and uses database plus mail channels. A new booking notifies the tutor; confirmation/rejection notifies the student; cancellation/rescheduling notifies both. The default mail driver writes messages locally to `storage/logs/laravel.log`, so no external email service is required. For actual delivery configure SMTP. Each channel is processed by the queue worker. Read database notifications through `/api/notifications` after jobs run.

Inspect failed jobs using `php artisan queue:failed`; retry after fixing the cause with `php artisan queue:retry all`. Restart long-lived workers after code/configuration changes using `php artisan queue:restart`.

## Automated tests

Fast isolated SQLite feature suite:

```powershell
php artisan test --compact
```

Real MySQL suite (including two simultaneous HTTP requests from independent PHP processes):

```powershell
php artisan test --configuration=phpunit.mysql.xml --compact
```

`phpunit.mysql.xml` forces the database to `tutor_booking_pyra_20260929_test`. It resets that disposable database. Use the same local MySQL credentials as `.env`; change the test configuration only to another disposable database with a name ending in `_test`. Never use a database containing valuable data. The concurrency tests refuse a non-test database and skip on SQLite, whose locking behavior cannot prove the MySQL guarantee.

Coverage includes all 12 assessment scenarios plus ownership, role escalation, tampered prices/status, availability invalidation, adjacent bookings, notification privacy/queueing, cutoff rules, admin deactivation, and student conflicts across different tutors. Three MySQL races cover tutor conflicts, student conflicts, and reschedule-versus-create conflicts.

Format code with `php vendor/bin/pint`. Framework and dependency versions are pinned in `composer.lock`.
