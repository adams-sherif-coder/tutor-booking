# Tutor Booking — Complete Endpoint Reference

Companion to [FRONTEND_GUIDE.md](FRONTEND_GUIDE.md). All paths are relative to `http://127.0.0.1:8000/api`. All protected routes require a bearer token and an active user. Examples show illustrative IDs/dates; obtain IDs from the API and choose future dates.

Each response below is the **entire JSON response**, not an Axios wrapper. Optional fields are described in the guide. All body field limits are listed in the guide and [OpenAPI specification](openapi.json).

### 1. POST `/register` — Register

**Access:** Public. **Success:** `201`.

Creates an active student or tutor and immediately issues a token. Tutor accounts start without a complete profile. Admin self-registration is rejected.

Request body:

```json
{
  "name": "New Student",
  "email": "new.student@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "role": "student"
}
```

Response example:

```json
{
  "data": {
    "user": {
      "user_id": 10,
      "name": "Demo Student",
      "email": "student@example.com",
      "role": "student",
      "is_active": true
    },
    "token": "<new-bearer-token>"
  },
  "message": "Registered successfully.",
  "status": 201,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Invalid role, duplicate email, weak/mismatched password, prohibited fields.

### 2. POST `/login` — Login

**Access:** Public. **Success:** `200`.

Issues a new token per successful login. No refresh token or expires_at is returned. The current Sanctum configuration has no automatic token expiry.

Request body:

```json
{
  "email": "student@example.com",
  "password": "password"
}
```

Response example:

```json
{
  "data": {
    "user": {
      "user_id": 10,
      "name": "Demo Student",
      "email": "student@example.com",
      "role": "student",
      "is_active": true
    },
    "token": "<bearer-token>"
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `401`: Invalid credentials.
- `403`: Your account is inactive.
- `422`: Email/password validation failed.

### 3. POST `/logout` — Logout

**Access:** Any active authenticated user. **Success:** `200`.

Revokes only the token used in this request. Clear the frontend token and user state after success. A second call with that token returns 401.

**Request body:** none.

Response example:

```json
{
  "data": [],
  "message": "Logged out successfully.",
  "status": 200,
  "ok": true
}
```

### 4. GET `/me` — Current account

**Access:** Any active authenticated user. **Success:** `200`.

Use this after restoring a token to obtain authoritative role and account identity. Tutor-specific profile fields are available from /tutor/profile.

**Request body:** none.

Response example:

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

### 5. GET `/subjects` — List subjects

**Access:** Any active authenticated user. **Success:** `200`.

Returns all subjects sorted by name. Not paginated. There are no subject-create/update/delete endpoints.

**Request body:** none.

Response example:

```json
{
  "data": {
    "subjects": [
      {
        "subject_id": 1,
        "name": "Mathematics"
      }
    ]
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

### 6. GET `/tutors` — Search tutors

**Access:** Any active authenticated user. **Success:** `200`.

Only active tutors. Sorted by name, then ID. Incomplete profiles can appear; use bookable availability before enabling a booking. name and subject_id combine with AND. name uses SQL LIKE matching; there is no date-based tutor filter.

| Query | Rule |
| --- | --- |
| `name` | Substring-like name search, maximum 255 characters. |
| `subject_id` | Existing subject ID. |
| `page` | Page number; default 1. |
| `per_page` | Items per page; default 15. |

**Request body:** none.

Response example:

```json
{
  "data": {
    "tutors": [
      {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Invalid filter, pagination or nonexistent subject ID.

### 7. GET `/tutors/{tutor}` — Tutor detail

**Access:** Any active authenticated user. **Success:** `200`.

Returns the public tutor profile and all currently bookable ranges. The availability array contains free intervals without availability IDs. Missing, inactive or non-tutor IDs return 404.

**Request body:** none.

Response example:

```json
{
  "data": {
    "tutor": {
      "tutor_id": 5,
      "name": "Demo Tutor",
      "bio": "Experienced private tutor.",
      "hourly_rate": "50.00",
      "subjects": [
        {
          "subject_id": 1,
          "name": "Mathematics"
        }
      ],
      "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
    },
    "availability": [
      {
        "starts_at": "2030-01-15T10:00:00+00:00",
        "ends_at": "2030-01-15T11:00:00+00:00"
      }
    ]
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `404`: Tutor not found.

### 8. GET `/tutors/{tutor}/availability` — Bookable tutor availability

**Access:** Any active authenticated user. **Success:** `200`.

Subtracts pending/confirmed sessions, clips past time to the next whole UTC minute and omits empty ranges. Empty/incomplete profiles return []. date is the UTC availability start date. This endpoint does not filter out the current student's conflicts.

| Query | Rule |
| --- | --- |
| `date` | Optional exact UTC availability date; omit for all future ranges. |

**Request body:** none.

Response example:

```json
{
  "data": {
    "availability": [
      {
        "starts_at": "2030-01-15T10:00:00+00:00",
        "ends_at": "2030-01-15T11:00:00+00:00"
      }
    ],
    "timezone": "UTC"
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `404`: Tutor not found.
- `422`: Invalid date.

### 9. GET `/tutor/profile` — Read own tutor profile

**Access:** Tutor only. **Success:** `200`.

Identity comes from the token. No tutor ID is accepted in the URL.

**Request body:** none.

Response example:

```json
{
  "data": {
    "tutor": {
      "tutor_id": 5,
      "name": "Demo Tutor",
      "bio": "Experienced private tutor.",
      "hourly_rate": "50.00",
      "subjects": [
        {
          "subject_id": 1,
          "name": "Mathematics"
        }
      ],
      "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

### 10. PUT `/tutor/profile` — Update own tutor profile

**Access:** Tutor only. **Success:** `200`.

Send bio, hourly_rate and the full subject_ids list on every update. name is optional but cannot be empty when supplied. Subject assignment is replacement, not append. Existing booking prices retain their snapshot.

Request body:

```json
{
  "name": "Demo Tutor",
  "bio": "Experienced private tutor.",
  "hourly_rate": "50.00",
  "subject_ids": [
    1
  ]
}
```

Response example:

```json
{
  "data": {
    "tutor": {
      "tutor_id": 5,
      "name": "Demo Tutor",
      "bio": "Experienced private tutor.",
      "hourly_rate": "50.00",
      "subjects": [
        {
          "subject_id": 1,
          "name": "Mathematics"
        }
      ],
      "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
    }
  },
  "message": "Profile updated.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Invalid rate, empty/duplicate/nonexistent subjects, missing required fields, prohibited role/is_active.

### 11. GET `/tutor/availability` — List own availability records

**Access:** Tutor only. **Success:** `200`.

Raw owned availability records whose end is later than now, sorted by start. Booked portions are still included here; this is the editing view, not the student booking picker. An already-started range may be listed but cannot be edited/deleted. Only pagination affects this endpoint.

| Query | Rule |
| --- | --- |
| `page` | Page number; default 1. |
| `per_page` | Items per page; default 15. |

**Request body:** none.

Response example:

```json
{
  "data": {
    "availability": [
      {
        "availability_id": 12,
        "tutor_id": 5,
        "starts_at": "2030-01-15T10:00:00+00:00",
        "ends_at": "2030-01-15T11:00:00+00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

### 12. POST `/tutor/availability` — Create availability

**Access:** Tutor only. **Success:** `201`.

Start strictly in the future, end after start, one UTC date, and no overlap with existing owned ranges. Adjacent ranges are allowed. Do not send tutor_id.

Request body:

```json
{
  "date": "2030-01-15",
  "start_time": "10:00",
  "end_time": "11:00"
}
```

Response example:

```json
{
  "data": {
    "availability": {
      "availability_id": 12,
      "tutor_id": 5,
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00"
    }
  },
  "message": "Availability created.",
  "status": 201,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Invalid/past interval, overlapping availability or supplied tutor_id.

### 13. PUT `/tutor/availability/{availability}` — Update availability

**Access:** Owning tutor only. **Success:** `200`.

Send all three time fields. The original range must not have started. The new range must be future, non-overlapping, and still contain every active booking depending on the original range. Moving it to another date is possible only when these rules permit.

Request body:

```json
{
  "date": "2030-01-15",
  "start_time": "10:00",
  "end_time": "11:00"
}
```

Response example:

```json
{
  "data": {
    "availability": {
      "availability_id": 12,
      "tutor_id": 5,
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00"
    }
  },
  "message": "Availability updated.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Another tutor owns this range.
- `404`: Range does not exist.
- `422`: Range started, invalid new interval, overlap or existing booking would be invalidated.

### 14. DELETE `/tutor/availability/{availability}` — Delete availability

**Access:** Owning tutor only. **Success:** `200`.

No body. Original range must not have started and must not contain any pending/confirmed booking. This deletes the availability record, not booking history.

**Request body:** none.

Response example:

```json
{
  "data": [],
  "message": "Availability deleted.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Another tutor owns this range.
- `404`: Range does not exist.
- `422`: Range started or contains an active booking.

### 15. GET `/bookings` — List bookings

**Access:** Student: own; tutor: own; admin: all. **Success:** `200`.

No period means all history, including future cancelled/rejected sessions. upcoming includes pending/confirmed with end > now (including in-progress sessions). past includes all statuses with end <= now. Past sorts start descending; otherwise start ascending; ties by ID. All filters combine with AND. Admin IDs only filter admin requests; students/tutors remain scoped to themselves.

| Query | Rule |
| --- | --- |
| `period` | upcoming: active sessions ending in the future; past: all statuses with end <= now. |
| `status` | Exact booking status. |
| `date` | UTC start date. |
| `tutor_id` | Existing user ID; effective only for admin. |
| `student_id` | Existing user ID; effective only for admin. |
| `page` | Page number; default 1. |
| `per_page` | Items per page; default 15. |

**Request body:** none.

Response example:

```json
{
  "data": {
    "bookings": [
      {
        "booking_id": 21,
        "student": {
          "user_id": 10,
          "name": "Demo Student",
          "email": "student@example.com",
          "role": "student",
          "is_active": true
        },
        "tutor": {
          "tutor_id": 5,
          "name": "Demo Tutor",
          "bio": "Experienced private tutor.",
          "hourly_rate": "50.00",
          "subjects": [
            {
              "subject_id": 1,
              "name": "Mathematics"
            }
          ],
          "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
        },
        "starts_at": "2030-01-15T10:00:00+00:00",
        "ends_at": "2030-01-15T11:00:00+00:00",
        "status": "pending",
        "hourly_rate": "50.00",
        "total_price": "50.00",
        "created_at": "2030-01-10T12:00:00+00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Invalid filter or nonexistent user ID supplied as a filter.

### 16. POST `/bookings` — Create booking

**Access:** Student only. **Success:** `201`.

The chosen tutor must be active, have a rate and at least one subject. Start must be future; interval must fit entirely inside ONE availability record. Neither participant may have an overlapping pending/confirmed booking. Price and ownership are server-controlled. Always creates pending. No subject selection is attached to a booking.

Request body:

```json
{
  "tutor_id": 5,
  "date": "2030-01-15",
  "start_time": "10:00",
  "end_time": "11:00"
}
```

Response example:

```json
{
  "data": {
    "booking": {
      "booking_id": 21,
      "student": {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      },
      "tutor": {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      },
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00",
      "status": "pending",
      "hourly_rate": "50.00",
      "total_price": "50.00",
      "created_at": "2030-01-10T12:00:00+00:00"
    }
  },
  "message": "Booking created.",
  "status": 201,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Unavailable/past/overlapping time, incomplete tutor profile, invalid tutor or prohibited student_id/status/hourly_rate/total_price.

### 17. GET `/bookings/{booking}` — Booking detail

**Access:** Owning student, booked tutor or admin. **Success:** `200`.

Includes nested student and tutor objects. Participant IDs are student.user_id and tutor.tutor_id; there are no top-level student_id/tutor_id fields in a booking response.

**Request body:** none.

Response example:

```json
{
  "data": {
    "booking": {
      "booking_id": 21,
      "student": {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      },
      "tutor": {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      },
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00",
      "status": "pending",
      "hourly_rate": "50.00",
      "total_price": "50.00",
      "created_at": "2030-01-10T12:00:00+00:00"
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Existing booking belongs to someone else.
- `404`: Booking does not exist.

### 18. PATCH `/bookings/{booking}/reschedule` — Reschedule booking

**Access:** Owning student only. **Success:** `200`.

Original booking must be pending/confirmed, future and at least cutoff hours away (default 24). New time must meet all create-booking checks. Tutor cannot change. Original hourly rate is retained; total is recalculated. Successful reschedule always returns pending and notifies both users, even if the previous booking was pending. A failed request preserves the original reservation. Do not send tutor_id/student_id/status/hourly_rate/total_price.

Request body:

```json
{
  "date": "2030-01-15",
  "start_time": "10:00",
  "end_time": "11:00"
}
```

Response example:

```json
{
  "data": {
    "booking": {
      "booking_id": 21,
      "student": {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      },
      "tutor": {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      },
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00",
      "status": "pending",
      "hourly_rate": "50.00",
      "total_price": "50.00",
      "created_at": "2030-01-10T12:00:00+00:00"
    }
  },
  "message": "Booking rescheduled; awaiting tutor approval.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Not the owning student.
- `404`: Booking does not exist.
- `422`: Cutoff, past/terminal status, availability/overlap/profile failure or prohibited field.

### 19. PATCH `/bookings/{booking}/cancel` — Cancel booking

**Access:** Owning student or booked tutor. **Success:** `200`.

No body. Pending or confirmed only, before start and at least cutoff hours away (24 by default). Notifies both users. Releases the reserved time and preserves history. Repeating the transition is invalid.

**Request body:** none.

Response example:

```json
{
  "data": {
    "booking": {
      "booking_id": 21,
      "student": {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      },
      "tutor": {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      },
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00",
      "status": "cancelled",
      "hourly_rate": "50.00",
      "total_price": "50.00",
      "created_at": "2030-01-10T12:00:00+00:00"
    }
  },
  "message": "Booking cancelled.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Wrong role or not a participant authorized for this action.
- `404`: Booking does not exist.
- `422`: Invalid state/timing; cancel also checks cutoff, confirm also checks participants/profile.

### 20. PATCH `/bookings/{booking}/confirm` — Confirm booking

**Access:** Booked tutor only. **Success:** `200`.

No body. Pending only and before start; both participants must be active and tutor profile complete. Notifies the student. Repeating the transition is invalid.

**Request body:** none.

Response example:

```json
{
  "data": {
    "booking": {
      "booking_id": 21,
      "student": {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      },
      "tutor": {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      },
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00",
      "status": "confirmed",
      "hourly_rate": "50.00",
      "total_price": "50.00",
      "created_at": "2030-01-10T12:00:00+00:00"
    }
  },
  "message": "Booking confirmed.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Wrong role or not a participant authorized for this action.
- `404`: Booking does not exist.
- `422`: Invalid state/timing; cancel also checks cutoff, confirm also checks participants/profile.

### 21. PATCH `/bookings/{booking}/reject` — Reject booking

**Access:** Booked tutor only. **Success:** `200`.

No body. Pending only and before start. Releases the reserved time, retains history and notifies the student. The cancellation cutoff does not apply to rejection. Repeating the transition is invalid.

**Request body:** none.

Response example:

```json
{
  "data": {
    "booking": {
      "booking_id": 21,
      "student": {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      },
      "tutor": {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      },
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00",
      "status": "rejected",
      "hourly_rate": "50.00",
      "total_price": "50.00",
      "created_at": "2030-01-10T12:00:00+00:00"
    }
  },
  "message": "Booking rejected.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Wrong role or not a participant authorized for this action.
- `404`: Booking does not exist.
- `422`: Invalid state/timing; cancel also checks cutoff, confirm also checks participants/profile.

### 22. PATCH `/bookings/{booking}/complete` — Complete booking

**Access:** Booked tutor only. **Success:** `200`.

No body. Confirmed only, at or after the scheduled end. Does not currently send a notification. Time passing alone never automatically completes a booking. Repeating the transition is invalid.

**Request body:** none.

Response example:

```json
{
  "data": {
    "booking": {
      "booking_id": 21,
      "student": {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      },
      "tutor": {
        "tutor_id": 5,
        "name": "Demo Tutor",
        "bio": "Experienced private tutor.",
        "hourly_rate": "50.00",
        "subjects": [
          {
            "subject_id": 1,
            "name": "Mathematics"
          }
        ],
        "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
      },
      "starts_at": "2030-01-15T10:00:00+00:00",
      "ends_at": "2030-01-15T11:00:00+00:00",
      "status": "completed",
      "hourly_rate": "50.00",
      "total_price": "50.00",
      "created_at": "2030-01-10T12:00:00+00:00"
    }
  },
  "message": "Booking completed.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Wrong role or not a participant authorized for this action.
- `404`: Booking does not exist.
- `422`: Invalid state/timing; cancel also checks cutoff, confirm also checks participants/profile.

### 23. GET `/admin/users` — List managed users

**Access:** Admin only. **Success:** `200`.

Lists students and tutors; admin accounts are excluded. Sorted by ID. is_active uses query values 0 or 1.

| Query | Rule |
| --- | --- |
| `role` | Optional role filter. |
| `is_active` | Use 1 for active, 0 for inactive. |
| `page` | Page number; default 1. |
| `per_page` | Items per page; default 15. |

**Request body:** none.

Response example:

```json
{
  "data": {
    "users": [
      {
        "user_id": 10,
        "name": "Demo Student",
        "email": "student@example.com",
        "role": "student",
        "is_active": true
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Invalid role/boolean/pagination.

### 24. PATCH `/admin/users/{user}/status` — Activate or deactivate a user

**Access:** Admin only; target student or tutor. **Success:** `200`.

is_active=false revokes every token and blocks future authenticated calls. Active bookings remain unchanged. is_active=true allows login again but does not restore revoked tokens. Admin targets cannot be changed, including yourself.

Request body:

```json
{
  "is_active": false
}
```

Response example:

```json
{
  "data": {
    "user": {
      "user_id": 10,
      "name": "Demo Student",
      "email": "student@example.com",
      "role": "student",
      "is_active": false
    }
  },
  "message": "User status updated.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `403`: Not admin or target is admin.
- `404`: Target not found.
- `422`: Missing or invalid is_active.

### 25. GET `/admin/bookings` — Search all bookings

**Access:** Admin only. **Success:** `200`.

Same response, sorting and period definitions as GET /bookings, with filters for tutor and student. date matches UTC session start date.

| Query | Rule |
| --- | --- |
| `period` | upcoming: active sessions ending in the future; past: all statuses with end <= now. |
| `status` | Exact booking status. |
| `date` | UTC start date. |
| `tutor_id` | Existing user ID; effective only for admin. |
| `student_id` | Existing user ID; effective only for admin. |
| `page` | Page number; default 1. |
| `per_page` | Items per page; default 15. |

**Request body:** none.

Response example:

```json
{
  "data": {
    "bookings": [
      {
        "booking_id": 21,
        "student": {
          "user_id": 10,
          "name": "Demo Student",
          "email": "student@example.com",
          "role": "student",
          "is_active": true
        },
        "tutor": {
          "tutor_id": 5,
          "name": "Demo Tutor",
          "bio": "Experienced private tutor.",
          "hourly_rate": "50.00",
          "subjects": [
            {
              "subject_id": 1,
              "name": "Mathematics"
            }
          ],
          "bookable_availability_url": "http://127.0.0.1:8000/api/tutors/5/availability"
        },
        "starts_at": "2030-01-15T10:00:00+00:00",
        "ends_at": "2030-01-15T11:00:00+00:00",
        "status": "pending",
        "hourly_rate": "50.00",
        "total_price": "50.00",
        "created_at": "2030-01-10T12:00:00+00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `422`: Invalid filter values.

### 26. GET `/notifications` — List own notifications

**Access:** Any active authenticated user. **Success:** `200`.

Newest first. Only the current user's inbox. Queue delivery is asynchronous; success from a booking mutation does not mean the notification has appeared yet. No unread filter/count endpoint exists.

| Query | Rule |
| --- | --- |
| `page` | Page number; default 1. |
| `per_page` | Items per page; default 15. |

**Request body:** none.

Response example:

```json
{
  "data": {
    "notifications": [
      {
        "notification_id": "d80d9a26-f0ca-49e4-bf45-0e943c11d7d0",
        "data": {
          "booking_id": 21,
          "event": "created",
          "status": "pending",
          "starts_at": "2030-01-15T10:00:00+00:00",
          "ends_at": "2030-01-15T11:00:00+00:00"
        },
        "read_at": null,
        "created_at": "2030-01-10T12:00:01.000000Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  },
  "message": "OK",
  "status": 200,
  "ok": true
}
```

### 27. PATCH `/notifications/{notification}/read` — Mark own notification read

**Access:** Notification owner only. **Success:** `200`.

No body. UUID notification identifier. Repeating this action is safe. An absent or another user's UUID returns 404.

**Request body:** none.

Response example:

```json
{
  "data": [],
  "message": "Notification marked as read.",
  "status": 200,
  "ok": true
}
```

Specific failures (in addition to shared authentication/rate-limit errors):

- `404`: Notification not found in your inbox.
