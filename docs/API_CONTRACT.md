# Admin Panel REST API v1 - contract for the Vendor Onboarding Platform (VOB)

Source of truth for the VOB build. The Admin Panel repo owns this file; copy it into the VOB repo and re-copy when it changes.
Implementation: `routes/api.php`, `app/Http/Controllers/Api/V1/`, `app/Services/TotFormService.php`, `config/tot_form.php`.

## 1. Who may call, and what failure looks like

- Base URL: the Admin Panel (private network), prefix `/api/v1`. Only the VOB server's egress IP (`VOB_ALLOWED_IPS`) may reach it.
- **Anything that fails the gate returns a plain `404`** (wrong IP, missing/invalid signature, replayed nonce, malformed credential envelope). VOB must treat a `404` on login as "request rejected", not as "no such resource".
- Every call is logged in Admin `api_logs` (passwords, tokens, the encrypted header and bank/PAN/GST numbers are redacted or masked). VOB must log its own calls to its own `api_logs` the same way.
- All responses are JSON. VOB sends `Accept: application/json` and `Content-Type: application/json`.

## 2. Request signing (every call, including login)

Shared secret: `API_HMAC_SECRET` (Admin) = `ADMIN_API_HMAC_SECRET` (VOB), owner generated (`openssl rand -base64 48`).

Headers:

| Header | Value |
|---|---|
| `X-Timestamp` | unix seconds; must be within 300 s of Admin's clock |
| `X-Nonce` | 16-128 random characters, unique per request (a nonce is accepted once) |
| `X-Signature` | lowercase hex `HMAC_SHA256(secret, payload)` |

```
payload = METHOD . "\n" . PATH . "\n" . TIMESTAMP . "\n" . NONCE . "\n" . sha256_hex(RAW_BODY)
```
`METHOD` upper case, `PATH` is the path only (e.g. `/api/v1/tots/abc`, no host, no query string), `RAW_BODY` is the exact bytes sent (empty string for GET/DELETE).

```php
$sig = hash_hmac('sha256', implode("\n", [strtoupper($method), $path, $ts, $nonce, hash('sha256', $body)]), $secret);
```

## 3. Bearer token (every call except `links/resolve` and `vendor/login`)

`Authorization: Bearer <access_token>` from login. The token identifies the vendor; every endpoint only ever returns that vendor's own TOTs (someone else's TOT id is a `404`).

- Opaque, 64 characters, stored hashed. One live token per vendor (a new login ends the old session).
- Idle lifetime 30 min (each use extends it), absolute lifetime 8 h. When it expires VOB must send the vendor back to login (VOB never stores the vendor's password).
- Missing/invalid/expired: `401 {"error":"unauthenticated"}`.
- While `must_change_password` is true every endpoint except `vendor/me`, `vendor/logout` and `vendor/password` returns `403 {"error":"password_change_required"}`.
- Throttled to 240 requests/min per vendor (`429`).

## 4. Credentials: encrypted `X-Auth-Data` header

Passwords never go in a body. Login and password change send them in `X-Auth-Data`; the JSON **body is ignored**, so VOB sends random decoy data in it (e.g. `{"username":"<random>","password":"<random>"}`).

Keys (owner generated, see DEVELOPMENT_PLAN.md 5C): Admin holds the RSA **private** key, VOB holds the **public** key (`vob_public.pem`) and its key id (`ADMIN_API_RSA_KEY_ID`, e.g. `k1`).

```
header = base64url( JSON { "kid": "k1", "ek": b64(RSA-OAEP(pubkey, aesKey)), "iv": b64(12 bytes), "ct": b64(ciphertext), "tag": b64(16 bytes) } )
aesKey = 32 random bytes per request, iv = 12 random bytes per request
ciphertext/tag = AES-256-GCM(aesKey, iv, JSON payload)
payload = { ...fields, "ts": <unix seconds>, "nonce": "<16-128 random chars>" }
```
`ts` must be within 120 s; a `nonce` is accepted once. PHP reference (also in Admin `App\Support\CredentialEnvelope::encrypt`):

```php
$payload = json_encode($fields + ['ts' => time(), 'nonce' => bin2hex(random_bytes(16))]);
$aesKey = random_bytes(32); $iv = random_bytes(12);
$ct = openssl_encrypt($payload, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
openssl_public_encrypt($aesKey, $wrapped, $publicKeyPem, OPENSSL_PKCS1_OAEP_PADDING);
$header = rtrim(strtr(base64_encode(json_encode(['kid'=>$kid,'ek'=>base64_encode($wrapped),'iv'=>base64_encode($iv),'ct'=>base64_encode($ct),'tag'=>base64_encode($tag)])), '+/', '-_'), '=');
```
(PHP's RSA-OAEP uses SHA-1 internally; both sides are PHP so they agree. Fine for wrapping a one-time key.)

## 5. Endpoints

### Before login (signature only)

**`POST /links/resolve`**  body `{ "token": "<the value after /onboard/ in the invitation link>" }`
200 `{ "valid": true, "financial_year": "2026-27", "type": "new|renew", "vendor_name": "...", "email_hint": "s***@a***.com", "first_time_login": true }`
`first_time_login` = true for a brand-new vendor (signs in with the emailed temporary password); false for an existing vendor (own password).
422 `{ "valid": false, "error": "link_invalid" }` - tampered, expired, or replaced by a newer invitation. Show "Ask your Lulu contact to resend the invitation".

**`POST /vendor/login`**  header `X-Auth-Data` with `{ "email": "...", "password": "...", "link_token": "<optional>" }`
200
```json
{ "access_token": "...64 chars...", "token_type": "Bearer", "expires_in": 1800,
  "user": { "id": "<public id>", "name": "...", "email": "...", "must_change_password": true },
  "tot": { "id": "...", "tot_number": 12, "financial_year": "2026-27", "type": "new", "vendor_name": "...", "status": "pending_vendor", "status_label": "Pending with vendor", "editable": true, "submitted_at": null } }
```
`tot` is the TOT behind `link_token` (null when no link). A valid link on a TOT in "TOT Initiated" moves it to **Pending with vendor**.
401 `invalid_credentials` (identical for wrong password / unknown / inactive / internal accounts) - 429 `too_many_attempts` after 5 failures (15 min; one counter per vendor email, the VOB IP is not part of the key; an Admin can clear it at once from User Administration > Vendors or the TOT page, which also lets the next login through) - 422 `link_invalid` (credentials were right, link wrong/for another vendor; no token issued) - 404 bad envelope.

### After login (signature + bearer)

| Method & path | Purpose |
|---|---|
| `GET /vendor/me` | `{ "user": {...} }` |
| `POST /vendor/logout` | ends the session |
| `POST /vendor/password` | header `X-Auth-Data` `{ "current_password", "new_password" }`; 422 with `errors.current_password` / `errors.new_password`; other sessions end. Rules: >= 10 chars, upper+lower case, number, symbol, different from current |
| `GET /masters` | dropdown data, **by code**, active only: `vendor_accounting_groups[{code,name}]`, `states`, `regions`, `company_codes[{code,name,city}]`, `purchase_orgs[{code,description}]`, `categories`, `deliverable_assets` |
| `GET /tots` | `{ "data": [ summary, ... ] }` the vendor's TOTs |
| `GET /tots/{id}` | full TOT (below). First open of a TOT in "TOT Initiated" -> "Pending with vendor"; a renewal is prefilled |
| `PUT /tots/{id}/draft` | save part of the form (below) |
| `POST /tots/{id}/documents` | register an uploaded PDF (below) |
| `DELETE /tots/{id}/documents/{docId}` | remove a document |
| `POST /tots/{id}/bank-verification` | report the Cashfree result: `{ "status": "verified|failed", "reference": "<required when verified>" }` |
| `POST /tots/{id}/submit` | submit for review |

`{id}` is the TOT `id` (16-char public id), never a number.

**`GET /tots/{id}`** returns:
```json
{ "tot": { ...summary... },
  "form": { "vendor_details": {...}, "company_codes": [...], "contacts": [...], "bank": {...}, "outlets": [...], "payment_terms": {...}, "trade_terms": {...},
            "categories": [...], "business_terms": {...}, "rebate_lines": [...], "growth_slabs": [...], "margins": [...], "deliverables": [...], "pocs": [...], "signatories": [...] },
  "locked_fields": ["company_codes","vendor_details.purchase_org","vendor_details.vendor_accounting_group","payment_terms.reconciliation_type"],
  "required_fields": ["vendor_details.vendor_name", "..."],
  "documents": [{ "id","type","name","size","uploaded_at" }],
  "required_documents": ["pan_certificate","cancelled_cheque","rtgs_form", "gst_certificate (when GST Registered)", "msme_certificate (when MSME Registered)"],
  "reviewer_comments": [{ "status","comments","by","at" }] }
```
- `locked_fields` are **greyed out** in VOB; the API also ignores them if sent.
- `required_fields` are the 18 vendor-mandatory fields (asterisks). `payment_terms.terms` means "at least one of the six payment-day fields". GST Regn. No is not required when `gst_type` is `GST Unregistered`.
- `reviewer_comments` are what reviewers wrote when the TOT was returned to the vendor.
- `bank.verification_status` is `pending|verified|failed`; any later change to account name, number or IFSC resets it to `pending`. **On renewals the bank account is always re-verified**, even when unchanged.

**`PUT /tots/{id}/draft`**  body `{ "form": { "<section>": ... } }` - only the sections sent are touched. Returns `{ "saved": ["vendor_details", ...], "ignored": ["company_codes", ...] }` or 422 `{ "errors": { "vendor_details.pan_no": ["..."] } }`.
Section kinds (full field list, formats and allowed values: `config/tot_form.php`):

| Kind | Payload | Behaviour |
|---|---|---|
| single (`vendor_details`, `bank`, `payment_terms`, `trade_terms`, `business_terms`) | object | merges: only fields sent change; `null` clears |
| keyed (`contacts` by `type` sales/finance/logistics, `pocs` by `dept`, `signatories` by `kind`, `growth_slabs` by `slab_no` 1-5, `rebate_lines` by `group`+`kind`, `deliverables` by `asset` code) | list of objects each carrying its key | upsert per row; other rows kept |
| list (`outlets`, `margins`) | list of objects | **replaces** the whole list |
| pivot (`categories` = category codes; `company_codes` buyer-only) | list of codes | **replaces** the whole list |

Masters are sent/returned as **codes** (`state: "KL"`, `deliverables[].asset: "end_cap"`), never ids. PAN, GSTIN, IFSC are upper-cased and phone numbers stripped of spaces by the server. PAN `ABCDE1234F`, GSTIN 15 chars, IFSC `HDFC0001234`, PIN 6 digits, phone `+?` 7-15 digits.
Saving is lenient (format only, nothing required); completeness is checked at submit.

**Documents** - VOB uploads the PDF (max 5 MB) **directly to the private S3 bucket** with its own AWS credentials at key `tots/{tot id}/{random 8-120 chars of [A-Za-z0-9._-]}.pdf`, then registers it:
`POST /tots/{id}/documents` `{ "type", "path", "original_name", "mime": "application/pdf", "size": <bytes> }` -> 201 `{ "document": { "id","type","name","size" } }`.
Types: `pan_certificate`, `gst_certificate`, `msme_certificate`, `cancelled_cheque`, `rtgs_form`, `board_resolution`. One document per type (a new one replaces the old). Admin and VOB must point at the same bucket (`TOT_DOCS_DISK=s3` on the Admin side).

**`POST /tots/{id}/submit`** - 200 `{ "message", "tot": { "status": "pending_buyer", ... } }`; the buyer reviewers are emailed. 422 `{ "error": "incomplete", "errors": { "<path>": "message" } }` lists everything still missing, including `bank.verification` (bank must be `verified`, or `bank.validation_failed_override = true` for manual approval) and `documents.<type>`.

**Editing rules** - the vendor may change a TOT only while its status is `initiated` or `pending_vendor`. Otherwise `409 { "error": "not_editable", "status": "..." }`. Reviewers sending a TOT back puts it in `pending_vendor` again.

## 5A. Save Draft, status tracker and development switches

**Save Draft.** The VOB form has a **Save Draft** button next to **Submit**. It calls `PUT /tots/{id}/draft` with the sections currently on screen (or the whole form). Nothing is mandatory for a draft - only formats are checked - so a vendor can save half-filled data and come back later. The response carries `draft_saved_at` (also returned by `GET /tots/{id}`); show it as "Draft saved 20-Oct-2026, 10:42 AM". Offer an autosave (e.g. on section change) using the same call.

**Status tracker (left margin).** `GET /tots/{id}` returns `progress`:
```json
"progress": { "current": "vendor", "steps": [
  { "key": "initiated", "label": "TOT initiated", "state": "done", "entered_at": "2026-10-20T09:00:00+00:00" },
  { "key": "vendor", "label": "Vendor details & submission", "state": "current", "entered_at": "..." },
  { "key": "buyer", "label": "Buyer review", "state": "pending", "entered_at": null }, "..." ] }
```
Steps are in travel order: `initiated, vendor, buyer, kam, legal, finance, audit, director, approved, esign`. `state` is `done` (tick), `current` (highlighted) or `pending`. A TOT that is sent back to the vendor moves the pointer back, so the stages that must approve again show as pending again. Render it as the vertical stepper in `docs/vob_status_tracker_reference.html` (modelled on `docs/Onboarding_status.jpg`). Show it whenever the vendor fills the form and whenever they come back to read reviewer comments (`reviewer_comments`). It is read-only; after submission the form is locked (`tot.editable = false`) but the tracker stays visible.

**Development switches for mandatory-field validation.** For development and testing the mandatory checks can be switched off, on both sides:

| Where | Switch | Effect when `false` |
|---|---|---|
| Admin API `.env` | `TOT_ENFORCE_REQUIRED` | `POST /tots/{id}/submit` (and the later buyer approval) no longer demand the mandatory fields |
| Admin API `.env` | `TOT_ENFORCE_DOCUMENTS` | submit no longer demands bank verification and the required documents |
| VOB `.env` (suggested names) | `VOB_ENFORCE_REQUIRED`, `VOB_ENFORCE_DOCUMENTS` | VOB front end does not block the Submit button / show required-field errors |

- Admin switches are ignored (always on) when `APP_ENV=production`; VOB must do the same.
- Formats (PAN, GSTIN, IFSC, PIN, phone, email, allowed values) are **always** validated, switches or not.
- `GET /tots/{id}` returns `validation: { required_fields: bool, documents: bool, skip_third_party: bool }` with the Admin side state (`skip_third_party` = Admin demo mode `TOT_SKIP_THIRD_PARTY`: mail goes to the log and vendor documents are read from the VOB machine instead of S3; VOB should warn when its own `VOB_SKIP_THIRD_PARTY` differs), so VOB can show a "development mode" banner and see when the two sides disagree. A submit that went through while a switch was off returns `warnings` (same shape as `errors`) listing what was missing.
- The Admin Panel shows a yellow "Development mode" banner on every page while a switch is off.

## 5B. Answers to VOB questions (entry 2 of ADMIN_PANEL_CHANGES.md, points confirmed while building the demo)

- **Bank verification**: `POST /tots/{id}/bank-verification` accepts `{"status":"verified","reference":"<any non-empty text, e.g. DEMO-4F9K2LQ7XA>"}` and `{"status":"failed"}`; `GET /tots/{id}` then returns `form.bank.verification_status`, `verification_reference` and `verified_at`, and reviewers see them on the TOT page.
- **Documents**: `DELETE /tots/{id}/documents/{docId}` and re-uploading a document of the same type both **delete the stored file** from the configured disk (S3, or the VOB folder in demo mode). `{docId}` is an opaque 16-character id.
- **Submit**: 200 `{message, tot:{status:"pending_buyer", editable:false, submitted_at}, warnings}`; 422 `{error:"incomplete", errors:{"<path>":"message"}}` with `documents.<type>` and `bank.verification` keys.
- **Reviewer outcome**: when a reviewer rejects, the TOT comes back to the vendor as `pending_vendor` with `tot.editable=true`, the reviewer's comment in `reviewer_comments` (newest first) and a new invitation link emailed; the vendor resubmits with the same `POST .../submit`. Rejections by Buyer or Legal go to the vendor, all other stages go back to the buyer.

## 5C. Behaviour VOB depends on (confirmed by tests in `tests/Feature/ApiContractChecksTest.php`)

- **`GET /masters` keys**: `vendor_accounting_groups`, `states`, `regions`, `categories`, `deliverable_assets` = `[{code,name}]`; `company_codes` = `[{code,name,city}]`; `purchase_orgs` = `[{code,description}]`. Active entries only.
- **`PUT /tots/{id}/draft` 422 keys**: `{"message":"...","errors":{"<section>.<index>.<field>":[...]}}`, where `<index>` is the row's position in the list VOB sent (`contacts.1.phone`, `pocs.1.second_email`, `outlets.0.store_3`); single sections use `<section>.<field>` (`bank.ifsc`); pivot lists use `<section>.<index>` (`categories.1`).
- **All or nothing**: if any field fails validation the whole PUT is rejected, nothing is saved and `draft_saved_at` is not updated.
- **`required_documents`** is a list of type strings already resolved for the vendor's current GST / MSME type (no "(when ...)" text). **`document_requirements`** is the structured form: `[{"type":"gst_certificate","required":true,"uploaded":false}, ...]` for every document type.
- **Sensitive values** (PAN, GSTIN, MSME no, account no) are returned in clear to the signed-in vendor who owns the TOT. Omitting a field in a draft keeps the stored value; a value containing `*` is rejected with 422 and never saved.
- **Dates** are `Y-m-d`.
- **`locked_fields`** entries are a whole section (`company_codes`) or `section.field`; an entry covers everything below it.
- **`reviewer_comments`** holds ONLY the comments reviewers wrote when they rejected the TOT back to the vendor (status `pending_vendor`), newest first, each `{status, comments, by, at}`. It is empty for a TOT that was never returned, and never contains system history ("TOT initiated", first login, edits, resends) or approvals / internal rejections between reviewers. `tot.submitted_at` stays set after a return, so the vendor front end can offer **Resubmit**.
- **Unexpected failures**: with `APP_DEBUG=false` a 500 answers `{"error":"server_error","message":"..."}` with no SQL, file path or stack trace (details go to the Admin log). 401, 404 and 422 answers are unchanged.

## 6. Statuses (`status` value -> label)

`initiated` TOT Initiated - `pending_vendor` Pending with vendor - `pending_buyer` - `pending_kam` - `pending_legal` - `pending_finance` - `pending_audit` - `pending_gm_buying` - `pending_director` - `approved` TOT Approved - `esign_initiated` E-Sign Initiated.

## 7. Login flow for VOB

1. Vendor opens `{VOB_URL}/onboard/{token}` from the email.
2. VOB calls `POST /links/resolve`; shows the sign-in page ("Enter the temporary password from your email" when `first_time_login`).
3. VOB calls `POST /vendor/login` with the credentials and the link `token` as `link_token`. Keep the `access_token` **server-side only** (session), never in browser JavaScript.
4. If `user.must_change_password`: show the change-password page -> `POST /vendor/password`.
5. Load `GET /masters` and `GET /tots/{tot.id}`; autosave with `PUT .../draft`; upload to S3 + register documents; run Cashfree and report `POST .../bank-verification`; `POST .../submit`.

## 8. TOT_Main_Form.html (current form) - what changed from the earlier Lulu_TOT_Form.html

- **Payment Mode**, **Lulu Receipt Terms > Mode** and **Deduction Mode** all use one list: `RTGS Payment`, `ePayment`, `Bank Transfer`, `Manual Check`, `RTGS/NEFT Payment H2H` (the old Cheque/RTGS/Cash/Debit/Others/Cash/UPI values are rejected).
- **Vendor Type** (entity) gains `Hindu Undivided Family`: Company, Partnership, Proprietorship, Hindu Undivided Family, Society, PSU/ Govt bodies, Others.
- **City** is a Vendor Details field (already mandatory for the vendor).
- **Single Point of Contact - Lulu** has a second email: `pocs[].second_email`.
- **E-Sign** is a new display-only section: signature anchors `L01` Central Buyer/Regional Buying Manager, `L02` Manager Central Buying, `L03` GM-Buying/Director, `V01` vendor Authorised Signatory. The **Terms of Trade signatories** are now the anchors `L03` / `V01`, not inputs, so `signatories[].kind` accepts only `bda_lulu` and `bda_vendor`. VOB renders the anchors as static text.
- The Terms of Trade clause text is unchanged.

## 9. Open points

- Lulu-side blocks (Single Point of Contact - Lulu, Lulu signatory) are editable by the vendor for now, as only four fields were specified as locked. Say if they should be locked too.
- Cashfree Reverse Penny Drop is run by VOB; Admin trusts the signed result.
