# Changes needed in the Admin Panel repo (raised from VOB)

Running list. Each entry is a ready-to-paste prompt for Claude Code in the Admin Panel repo. Mark an entry **Done** once the Admin side
has been changed and `docs/API_CONTRACT.md` re-copied into this repo.

---

## 1. Admin "Unblock vendor sign-in" (immediate lockout reset) - Status: OPEN

Raised in VOB Phase 2. The Admin API locks a vendor out after 5 failed `POST /vendor/login` attempts for 15 minutes (`429 too_many_attempts`).
VOB no longer adds a lockout of its own, so this Admin lockout is the only one, and an Admin user must be able to clear it at once
(for example a vendor who mistyped the temporary password and is waiting on the phone).

**Prompt for the Admin Panel repo:**

```
In the Admin Panel, add a way for an Admin to immediately unblock a vendor who is locked out of the VOB sign-in.

Background: POST /api/v1/vendor/login returns 429 {"error":"too_many_attempts"} after 5 failed attempts within 15 minutes
(per email, and per IP if that is how it is keyed). Find where that throttle/lockout is stored (RateLimiter keys, cache, or a table)
and build the unblock on top of it.

Requirements
1. Admin-only action (policy / Admin role check, same as other User Administration actions). Reviewers and TOT owners cannot use it.
2. Where: User Administration, on a vendor user's row/detail ("Unblock sign-in"), and also on the TOT view page of the vendor's TOT
   for the TOT owner's convenience only if that is simple; otherwise User Administration only. Show the button (or a "Locked" badge)
   only while a lockout is active, and show the remaining minutes if known.
3. Clicking it (POST, CSRF protected, with a confirmation) clears every failed-attempt counter / lockout entry for that vendor email
   (all IP variants of the key) so the very next login attempt is allowed. It must not reset the vendor's password and must not log the vendor in.
4. Record it in activity_logs: who unblocked whom, when.
5. Fail-safe: if no lockout is active, say so and do nothing.
6. Tests (Pest): lock a vendor out with 5 bad logins through the API, assert the 6th returns 429, Admin unblocks, assert the next valid
   login returns 200; non-Admin gets 403/404; the action is written to activity_logs; unblocking one vendor does not affect another.
7. No change to the API contract is needed. If the lockout key format was changed, say so in docs/API_CONTRACT.md section 3/5.
```

---

## Adding new entries

Append a new numbered section with: why VOB needs it, the Admin-side prompt in a code block, and a status line.
