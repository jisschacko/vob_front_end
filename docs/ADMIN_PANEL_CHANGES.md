# Changes needed in the Admin Panel repo (raised from VOB)

Running list. Each entry is a ready-to-paste prompt for Claude Code in the Admin Panel repo. Mark an entry **Done** once the Admin side
has been changed and `docs/API_CONTRACT.md` re-copied into this repo.

---

## 1. Admin "Unblock vendor sign-in" (immediate lockout reset) - Status: DONE (implemented in the Admin panel, confirmed by the owner)

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

## 2. Confirm API behaviour VOB Phase 3 relies on - Status: DONE (answered in API_CONTRACT.md 5B and 5C; VOB aligned)

VOB's TOT form (Phase 3) assumes the following. Ask the Admin repo to confirm each point, fix the Admin side if it differs, and state the final
behaviour in `docs/API_CONTRACT.md`.

**Prompt for the Admin Panel repo:**

```
Please check the vendor API against these assumptions made by the VOB front end and tell me which ones are wrong (fix the code or update
docs/API_CONTRACT.md as appropriate, with Pest tests):

1. GET /masters list shapes. VOB reads each list as [{code, name}] except purchase_orgs [{code, description}] and company_codes
   [{code, name, city}]; `categories` and `deliverable_assets` as [{code, name}]. Plain string lists are also tolerated. Confirm the exact keys.
2. PUT /tots/{id}/draft 422 error keys. For keyed sections (contacts, pocs, signatories, growth_slabs, rebate_lines, deliverables) VOB sends a
   list of rows and assumes errors come back as "<section>.<index>.<field>" where <index> is the position in the list VOB sent
   (e.g. "contacts.1.phone"). For list sections (outlets, margins) the same "<section>.<index>.<field>". Confirm, or tell me the real key format.
3. Nothing is saved when any field fails validation (the whole PUT is rejected), as VOB tells the vendor "Nothing was saved".
4. DELETE /tots/{id}/documents/{docId}: does it also delete the S3 object? And when a document of the same type is uploaded again (replace),
   is the previous S3 object deleted? VOB only creates objects and never deletes them from the bucket, so Admin must own cleanup.
   Is {docId} an opaque value (VOB only ever uses it server-side and never in a browser URL)?
5. GET /tots/{id} `required_documents` entries are strings like "pan_certificate" and "gst_certificate (when GST Registered)"; VOB parses the type
   from the first word and the condition from "(when X)". Please consider returning structured entries instead,
   e.g. [{"type":"gst_certificate","required":true}] already resolved against the vendor's current GST/MSME type.
6. Masked values on renewals: account_no (and PAN / GST / MSME numbers if you mask those too) come back with "*" characters. VOB shows them as a
   placeholder and does NOT send them back unless the vendor retypes the value. Confirm that omitting the field keeps the stored value, and that
   a masked value is never persisted if it is sent back by mistake (reject or ignore any value containing "*").
7. Dates (period_from, bank_guarantee_date ...) are returned as Y-m-d; VOB shows the first 10 characters if a timestamp is returned.
8. `locked_fields` entries may be a whole section ("company_codes") or "section.field"; VOB treats an entry as covering everything below it.
```

---

## 3. .env settings: mirror the VOB switches and list every setting - Status: DONE (implemented in the Admin panel, confirmed by the owner)

VOB now has these development / demo settings. The Admin Panel needs the matching ones so both applications behave the same in a demo, and both
repos should keep one readable list of every `.env` setting (VOB's list is `docs/ENV_SETTINGS.md`).

| VOB setting (this repo) | What it does in VOB | Admin Panel counterpart |
|---|---|---|
| `VOB_ENFORCE_REQUIRED` | VOB does not block Submit on missing mandatory fields (forced on in production) | `TOT_ENFORCE_REQUIRED` (exists) |
| `VOB_ENFORCE_DOCUMENTS` | VOB does not block Submit on missing documents / unverified bank (forced on in production) | `TOT_ENFORCE_DOCUMENTS` (exists) |
| `VOB_SKIP_THIRD_PARTY` | Skips Cashfree (bank reported as verified with a `DEMO-...` reference) and S3 (documents on VOB's local disk); banner on every page; forced off in production | **`TOT_SKIP_THIRD_PARTY` - new, see below** |
| `VOB_SESSION_IDLE_MINUTES` / `VOB_SESSION_ABSOLUTE_MINUTES` | Vendor session expiry in VOB | Admin token limits (30 min idle / 8 h): keep VOB's values <= these, or make them configurable |
| `ADMIN_API_HMAC_SECRET`, `ADMIN_API_RSA_KEY_ID`, `ADMIN_API_RSA_PUBLIC_KEY_PATH` | Signing and login-header encryption | `API_HMAC_SECRET`, `API_RSA_KEY_ID`, `API_RSA_PRIVATE_KEY_PATH` (must match) |
| `VOB_DOCS_DISK` / `AWS_*` | Where VOB writes vendor PDFs | `TOT_DOCS_DISK` (+ the same bucket in `AWS_*`) |

**Prompt for the Admin Panel repo:**

```
Part A - demo switch. Add `TOT_SKIP_THIRD_PARTY` (default false; ignored and forced false when APP_ENV=production), the Admin counterpart of the
VOB front end's VOB_SKIP_THIRD_PARTY. When true:
  - email goes to the log mailer instead of a real mail server (invitation and reviewer emails are still generated and visible in storage/logs);
  - vendor documents are read from the local disk configured by TOT_DOCS_LOCAL_ROOT (see entry 4) instead of S3;
  - any other third-party call that exists now or later (eMudhra e-sign, SAP, etc.) is skipped and logged as "skipped (demo)".
Show the existing yellow "Development mode" banner with an extra line "Third-party services are skipped". Return it from GET /api/v1/tots/{id} inside
`validation`: { required_fields: bool, documents: bool, skip_third_party: bool } so VOB can warn when the two sides disagree.
Add Pest tests, including that production ignores the flag.

Part B - one list of every setting. Create docs/ENV_SETTINGS.md in the Admin repo with a table of EVERY key in .env.example, grouped by topic, with the
columns: Variable | Application ("Admin Panel", or "Both" when the same value must also be set in the VOB front end) | Purpose | Default / production note.
Include API_HMAC_SECRET, API_RSA_*, VOB_ALLOWED_IPS, ADMIN_ALLOWED_CIDRS, VOB_URL, TOT_ENFORCE_REQUIRED, TOT_ENFORCE_DOCUMENTS, TOT_DOCS_DISK, TOT_SKIP_THIRD_PARTY,
mail, queue, database and every other key. Add a Pest test that fails when a key exists in .env.example without a row in that file (VOB has the same test:
tests/Feature/EnvSettingsDocTest.php), and make sure .env.example parses with Dotenv (no inline "//" or "#" comments after values).
Link the file from the README.
```

---

## 4. Admin changes needed for the vendor submit + owner review demo - Status: DONE (implemented in the Admin panel, confirmed by the owner)

Demo on one machine: (1) initiate a TOT in the Admin, (2) the vendor signs in to VOB, (3) the vendor fills in and submits, (4) the TOT owner reviews it.
Step 1 is done. Everything below is what VOB expects from the Admin API (endpoints are described in `docs/API_CONTRACT.md`).

**Prompt for the Admin Panel repo:**

```
I am demoing the whole vendor flow on my machine against the VOB front end (http://127.0.0.1:8001, Admin on http://127.0.0.1:8000). Please make these
changes, with Pest tests, and tell me what you could not do.

1. Demo vendor password (non-production only). Add TOT_DEMO_VENDOR_PASSWORD (default empty). When it is set and APP_ENV is not production, every NEW
   vendor created by "Initiate TOT" gets this as the temporary password instead of a random one (still hashed; must_change_password stays true unless
   TOT_DEMO_FORCE_PASSWORD_CHANGE=false). Ignored in production. I will put the value in my own .env; do not choose one for me.

2. Demo invitation link. Add TOT_DEMO_SHOW_LINK (default false, ignored in production). When true the TOT owner and Admin see the vendor invitation URL
   ({VOB_URL}/onboard/{token}) with a copy button on the TOT page and in the TOT list actions, so I do not depend on email delivery.
   Make sure VOB_URL=http://127.0.0.1:8001 works and that "Resend invitation" / "Reset" links still invalidate older ones.

3. Local network allow-lists. Confirm that API requests from 127.0.0.1 are accepted when VOB_ALLOWED_IPS=127.0.0.1 (and ::1 if needed), that web requests from
   127.0.0.1 pass ADMIN_ALLOWED_CIDRS, and write the exact local .env values to the README ("Running both apps on one machine").

4. Documents in the demo. With VOB_SKIP_THIRD_PARTY=true VOB saves PDFs on its own local disk (storage/app/private/tots/{tot id}/{random}.pdf) and registers
   path "tots/{tot id}/{random}.pdf". Add a disk "vob_local" whose root is TOT_DOCS_LOCAL_ROOT (e.g. D:/projects/vob_front_end/storage/app/private) and let
   TOT_DOCS_DISK=vob_local select it, so reviewers can open the uploaded PDFs in the Admin. Also confirm what DELETE /tots/{id}/documents/{docId} and a
   re-upload of the same type do to the stored file (see entry 2, point 4).

5. Bank verification. POST /tots/{id}/bank-verification must accept {"status":"verified","reference":"DEMO-XXXXXXXXXX"} (any non-empty reference string) and
   {"status":"failed"}. Verified must make submit pass the bank check; show the reference on the TOT view for reviewers.

6. Submit. POST /tots/{id}/submit must return 200 {message, tot:{status:"pending_buyer", ...}} and set tot.editable=false and tot.submitted_at, move
   progress to the Buyer review step (vendor step done), and email/notify the Buyer reviewers (log mailer in demo). On a TOT that is incomplete return
   422 {error:"incomplete", errors:{"<path>":"message"}} exactly as in the contract, including "documents.<type>" and "bank.verification".
   When TOT_ENFORCE_REQUIRED / TOT_ENFORCE_DOCUMENTS are false the submit must pass and return `warnings` for what was missing.

7. Owner review. After the vendor submits, the TOT owner (the user who initiated it) must be able to review it. Check that:
   - the initiator can choose THEMSELVES as a Buyer Reviewer when initiating (or, if the rules forbid it, add TOT_DEMO_OWNER_IS_BUYER=true so the owner is also a
     buyer reviewer in non-production);
   - the TOT shows in the owner's listing with status "Pending with buyer" and "Pending with" = the buyer reviewer;
   - the TOT view page shows all vendor data read-only (vendor details, contacts, bank incl. verification status/reference, payment terms, trade terms,
     categories, business terms, rebate lines, growth slabs, margins, deliverables, POCs, signatories), the uploaded documents (openable), and history/comments;
   - the reviewer has only Approve and Reject, each with a comment (mandatory on Reject);
   - Approve moves the TOT to the next stage (KAM) and updates `progress`; Reject sends it back to the VENDOR (pending_vendor), sets editable=true and returns the
     comment in GET /tots/{id} `reviewer_comments` [{status, comments, by, at}] so the VOB front end shows it and offers "Resubmit";
   - after a reject, the vendor's resubmit through POST /tots/{id}/submit works again and the stage pointer goes back to Buyer review.
   If any of this is not built yet, build the minimum for the Buyer stage now (the later stages can stay as they are).

8. End-to-end test. Add one Pest feature test that plays the VOB client against the API: initiate -> resolve link -> login (X-Auth-Data) -> change password ->
   GET masters -> GET tot -> PUT draft -> POST document -> POST bank-verification (DEMO- reference) -> POST submit -> reviewer rejects with a comment ->
   GET tot shows reviewer_comments and editable=true -> POST submit again -> reviewer approves. Also document in the README the keys and values the demo needs
   (API_HMAC_SECRET, API_RSA_PRIVATE_KEY_PATH, API_RSA_KEY_ID, VOB_ALLOWED_IPS, VOB_URL, TOT_DEMO_*).
```

---

## 6. Found while running the two apps together - Status: DONE (migration run, reviewer_comments and 500 body fixed, see API_CONTRACT.md 5C)

Seen in VOB's `api_logs` during the first local run (Admin on http://127.0.0.1:8000).

**6a. Save Draft fails with a 500 from the Admin (blocks the whole demo).** `PUT /api/v1/tots/{id}/draft` returns 500:
`SQLSTATE[42S22]: Column not found: 1054 Unknown column 'second_email' in 'field list' ... insert into tot_pocs (tot_id, dept, first_poc, first_mobile, email, second_poc, second_mobile, second_email)`
in database `vendor_onboarding`. The migration that adds it (`2026_10_23_000001_update_tot_form_for_main_form`, which also widens the payment-mode columns and adds the
Hindu Undivided Family entity type) has not been run on that database. Fix on the Admin side: run `php artisan migrate`, then check `php artisan migrate:status` shows
nothing pending. Until then every draft save and autosave from VOB shows "We could not save right now".

**6b. `reviewer_comments` contains system history.** `GET /tots/{id}` for a TOT that was never submitted returns
`"reviewer_comments":[{"status":"TOT Initiated","comments":"TOT initiated","by":"VOB Admin","at":"..."}]`. The contract says this list holds what reviewers wrote when
the TOT was sent back to the vendor. VOB now treats a TOT as "returned" only when `tot.submitted_at` is set and hides the system "TOT Initiated" row, but the Admin
should return only real return comments.

**Prompt for the Admin Panel repo:**

```
1. Run all pending migrations on the vendor_onboarding database (the tot_pocs.second_email column from 2026_10_23_000001_update_tot_form_for_main_form is missing)
   and confirm PUT /api/v1/tots/{id}/draft saves a full form without a 500. Add a Pest test that sends a draft containing every section, including pocs with
   second_email, so a missing column is caught.
2. In GET /api/v1/tots/{id}, make `reviewer_comments` contain ONLY the comments written by reviewers when they returned the TOT to the vendor
   (status "pending_vendor" after a reject), newest first, each {status, comments, by, at}. Do not include system history rows such as "TOT initiated" or
   "Pending with vendor" (first login). Keep `tot.submitted_at` set after a return so the vendor front end can show "Resubmit". Update docs/API_CONTRACT.md
   and add a test: a TOT that was never submitted returns an empty list.
3. A 500 from the API must not leak the SQL, file paths or stack trace in its body when APP_DEBUG is false. Check that the API returns {"error":"server_error"}
   style JSON with no trace in non-local environments.
```

---

## 7. Demo: do not format-validate PAN, GST number and bank details - Status: DONE (API_CONTRACT.md 5A; VOB workaround removed)

In demo mode (`VOB_SKIP_THIRD_PARTY=true`) the VOB form shows PAN No, GST Regn. No, Account Name, Account No and IFS Code as **verified (green) whatever the vendor types**.
VOB itself checks nothing, but the Admin validates their formats on every draft save and rejects the whole draft (all-or-nothing) over a bad PAN or IFSC.
Until the Admin changes, VOB works around it **in demo mode only**: it remembers what the vendor typed for these five fields in the server-side session, and when the Admin
refuses only these fields it saves the rest of the form without them. The consequence is that those values are **not stored at the Admin**, so a reviewer would see them empty.

**Prompt for the Admin Panel repo:**

```
When TOT_SKIP_THIRD_PARTY=true (never in production), skip the FORMAT validation of these five fields everywhere they are validated (PUT /api/v1/tots/{id}/draft,
POST /tots/{id}/submit, and the owner edit pages in the Admin Panel) and store whatever value is sent, trimmed, without upper-casing or rejecting it:
  vendor_details.pan_no, vendor_details.gst_regn_no, bank.account_name, bank.account_no, bank.ifsc.
Keep the maximum length. Presence (required) follows TOT_ENFORCE_REQUIRED as before. Do not change anything when the flag is off.
Add Pest tests: with the flag on, a draft with pan_no "x", ifsc "nope" and gst_regn_no "123" is saved and returned unchanged by GET /tots/{id}; with the flag off the same draft is
rejected with 422; production ignores the flag. Mention it in docs/API_CONTRACT.md next to skip_third_party.
Afterwards VOB can drop its demo workaround (App\Support\DemoLenientFields) and the reviewer sees the real typed values.
```

---

## 5. Demo run-book (both apps on one machine)

**Admin Panel `.env`** (names as above; values are yours): `APP_URL=http://127.0.0.1:8000`, `VOB_URL=http://127.0.0.1:8001`, `VOB_ALLOWED_IPS=127.0.0.1`,
`API_HMAC_SECRET`, `API_RSA_PRIVATE_KEY_PATH`, `API_RSA_KEY_ID=k1`, `TOT_ENFORCE_REQUIRED=false`, `TOT_ENFORCE_DOCUMENTS=false` (optional, makes the demo forgiving),
`TOT_SKIP_THIRD_PARTY=true`, `TOT_DEMO_VENDOR_PASSWORD=<yours>`, `TOT_DEMO_SHOW_LINK=true`, `TOT_DOCS_DISK=vob_local`,
`TOT_DOCS_LOCAL_ROOT=<path to VOB>/storage/app/private`. Keep the queue worker running if mails are queued.

**VOB `.env`**: `ADMIN_API_BASE_URL=http://127.0.0.1:8000/api/v1`, the same `ADMIN_API_HMAC_SECRET` and `ADMIN_API_RSA_KEY_ID`, `ADMIN_API_RSA_PUBLIC_KEY_PATH` pointing at
`vob_public.pem` (the public half of the Admin's key pair), `VOB_SKIP_THIRD_PARTY=true`, `VOB_ENFORCE_REQUIRED` / `VOB_ENFORCE_DOCUMENTS` as on the Admin side
(a note appears on the form if they disagree), `APP_URL=http://127.0.0.1:8001`. Run `php artisan migrate`, then `php artisan serve --port=8001`.

1. **Admin (8000):** sign in as the owner / initiator, Initiate TOT for a vendor, choose yourself as a Buyer Reviewer. Copy the vendor link from the TOT page.
2. **VOB (8001):** open the link in a private window. Sign in with the vendor email and the demo password, choose a new password, then fill in the form
   (Save Draft / autosave), upload a few PDFs, press "Verify bank account" (instant, the banner says demo), press Submit. The tracker moves to Buyer review and the form turns read-only.
3. **Admin:** the owner opens the TOT (status "Pending with buyer"), reviews the data and documents, then **Reject** with a comment.
4. **VOB:** reload: the comment shows, the form is editable and the button says **Resubmit**. Fix something, Resubmit.
5. **Admin:** the owner **Approves**; the tracker in VOB shows the next stage.

The VOB `api_logs` table shows every call (signed, secrets redacted, account numbers masked), which is a good way to show the management what travels between the two applications.

## Adding new entries

Append a new numbered section above this one with: why VOB needs it, the Admin-side prompt in a code block, and a status line.
