# Vendor Handshake – Development Plan (Admin Panel + Vendor Onboarding Platform)

Basis: analysis of `docs/TOT_Main_Form.html`, `Lulu_Initiate_TOT_Form.html`, `Lulu_TOT_List.html` and the requirement brief.

---

## 0. Decisions (all confirmed by owner)

Confirmed: #2 Legal rejects to the Vendor (intentional); #3 is a typo (Audit reviewers); the other defaults below are accepted.

| # | Item | Proposed default |
|---|------|------------------|
| 1 | Status list contains **"Pending with GM Buying"** but the workflow never uses it | Keep status in master; not used until E-sign stage (confirm) |
| 2 | Rejection routing differs per stage: Buyer→Vendor, KAM→Buyer, Legal→**Vendor**, Finance/Audit/Director→Buyer. Legal→Vendor looks inconsistent with the others | Implement as a configurable `workflow_stages` table (reject_to column) so it can change without code |
| 3 | Audit approval text says "assigned in *Finance* Reviewer Email ID" | Treated as typo → Audit reviewers |
| 4 | Director dropdown lists role "Director & COO"; role list also has "Director & CEO" | Configurable role→dropdown map table (`reviewer_group_roles`) |
| 5 | "16-char random value usable as IV for AES" – reusing one IV per user with one key is cryptographically weak (CBC predictability, catastrophic with GCM) | Use the 16-char value as the **opaque public identifier** (`public_id`). For encryption use Laravel `Crypt` (AES-256-CBC+HMAC / GCM) with a **fresh random IV per message**. Do not reuse the public_id as IV |
| 6 | Login credentials "encrypted in header, dummy body" | Hybrid: AES-256-GCM payload (random IV) in a header, key wrapped by RSA-OAEP public key held by VOB; admin holds private key. Timestamp + nonce to stop replay. TLS is still mandatory |
| 7 | Reviewer actions | **Updated:** only two actions on the TOT Review page: **Approve** and **Reject**, each with a comment box; comments appear in TOT history. Reject goes to the stage per #2 (Buyer→Vendor, KAM→Buyer, Legal→Vendor, Finance/Audit/Director→Buyer). Separate "Send back to vendor" is removed. Confirmed: comment optional on Approve, **mandatory on Reject** |
| 8 | Renew flow | Same initiate form + optional "Existing TOT document" upload; new TOT row linked via `previous_tot_id` |
| 9 | Admin panel hosting | Corporate network only; reached over VPN/Direct Connect/peering from the AWS VPC hosting VOB |
| 10 | E-sign (eMudhra) order: Vendor → Central Buyer → Manager Central Buying → GM Buying | Phase 5, after scaffolding |

---

## 1. Architecture & security model

```
Internet → VOB (public Laravel, AWS EC2/ALB, S3)
                │  HTTPS, VPN / VPC peering, fixed egress IP (NAT/Elastic IP)
                ▼
          Admin Panel (private Laravel, corp network)  ── MySQL (single source of truth)
```

**Layered 404 strategy (Admin panel)**
1. Network: security group / firewall allows only (a) corporate CIDRs, (b) VOB egress IP. No public DNS or ALB.
2. App middleware `RestrictNetwork` (first in global stack): reads real client IP (trusted proxies configured explicitly, never trust raw `X-Forwarded-For`). Web routes → corporate CIDR list; `/api/*` → VOB IP allow-list only. Anything else → `abort(404)` (identical body to a genuine 404, no login page rendered, no redirect).
3. API signature middleware: HMAC-SHA256 over method+path+timestamp+nonce+body-hash with shared secret; ±5 min window; nonce cached (Redis) to block replay. Failure → 404 too (don't reveal existence).
4. Allow-lists live in `.env`/config, cached; unit-tested with spoofed headers.

**Auth for API**
- `POST /api/v1/vendor/login`: credentials only in encrypted header `X-Auth-Data`; body carries decoy fields (random generated dummy `username/password`, ignored server-side). Returns `access_token` (Sanctum token, short TTL, ability `vendor`) + refresh strategy.
- All other endpoints: `Authorization: Bearer <access_token>` + signature + IP checks. **Confirmed (option 1):** HMAC signing stays for every call (it authenticates VOB as the caller, protects the login call that has no token yet, and prevents tampering/replay); the Bearer token identifies *which vendor* is logged in. The token resolves to the vendor user, and every endpoint authorises against that user only (a vendor can touch only their own TOT). `api_logs.user_public_id` records the vendor on each call.
- Rate limit + lockout on login (per email + per IP), generic error messages.

**Identifiers & data protection**
- Every user/TOT/vendor/document row has `public_id` CHAR(16), generated with `random_bytes` (base62/hex), unique index. Internal auto-increment ids are never exposed in URLs, APIs or HTML.
- Links in emails contain only an encrypted, signed, expiring payload (`Crypt::encryptString` + Laravel signed URL). No raw IDs/emails/passwords in links.
- Initial password email: random 8 chars (CSPRNG; ensure upper/lower/digit/symbol), hashed with bcrypt/argon2, `must_change_password=1`, expires after N days.
- Sensitive columns (PAN, GST, bank account no., IFSC, signatory contacts) → Eloquent `encrypted` casts; blind-index columns (HMAC) if search is needed.
- Uploads: S3 private bucket, SSE-KMS, pre-signed short-lived URLs, MIME + extension + size validation, AV scan hook, random object keys.
- Security headers, CSRF, session fixation protection, secure/HttpOnly/SameSite cookies, `$fillable` strictness, policies on every model, audit trail.

**API logging**
- Table `api_logs`: id, direction (in/out), method, url, route, ip, user_public_id, request_headers (sensitive redacted), request_body (redacted/masked), response_status, response_body (masked), duration_ms, created_at. Index on created_at.
- Implemented via global API middleware (terminable) on Admin; HTTP-client macro/middleware on VOB (outbound log, own `api_logs` table too, plus Cashfree/eMudhra calls).
- Never log passwords/tokens/Auth header/full account numbers (mask). Purge: scheduled command `logs:purge-api --months=2` daily in `routes/console.php`, chunked deletes, run via cron `* * * * * php artisan schedule:run`.

---

## 2. Stack & conventions (both apps)

Laravel 11/12, PHP 8.3, MySQL 8, Redis (cache/queue/nonce), Blade + Bootstrap/Tailwind reusing the provided HTML look, Alpine/Tom Select (or Choices.js) for multi-select with Select-All. Pest/PHPUnit, Pint, Larastan. Queue (Redis/SQS) for all emails. Service/Action classes, Form Requests, Policies, Enums for statuses/roles, API Resources for responses. Versioned API `/api/v1`.

---

## 3. Admin Panel – data model

**Core / auth**
- `users`: id, public_id(16), first_name, last_name, email(unique, nullable on soft-delete handling), employee_id(unique), contact_number, department, functional_role, is_tot_initiator(bool), is_active, must_change_password, password, user_type(internal|vendor), deleted_at, timestamps.
  *Soft delete freeing email/mobile*: on delete, rename `email`→`deleted_{id}_{ts}_{email}` (or null them and move originals to `deleted_original_*` columns); unique indexes then don't block reuse.
- `user_roles` (id, name, slug, is_assignable[false for Vendor]) – seeded with the 24 roles.
- `role_user` pivot (multi-role).
- `reviewer_group_roles`: group (buyer, kam, legal, finance, audit, director) → role_id (drives dropdowns).
- `api_logs`, `activity_logs` (who/what/when/subject, for ownership change, resend, reset, edit).

**System data**: `vendor_accounting_groups`, `states`, `regions` (each: id, name, code, is_active, soft deletes), plus:
- `purchase_orgs`: id, `code` (string, unique, e.g. 9000), `description` (e.g. "Lulu Kerala"), is_active, soft deletes. Seeded with the 10 existing orgs (8800 Mantle Solutions, 9000 Lulu Kerala, 9001 Lulu Andhra Pradesh, 9002 Lulu Karnataka, 9003 Lulu Uttar Pradesh, 9004 Lulu Tamil Nadu, 9005 Lulu Telangana, 9006 Lulu Ahmedabad, 9007 Lulu Haryana, 9800 Lulu Hotels).
- `company_codes`: id, `code` (string, unique, e.g. 9000), `name` (company name), `city`, is_active, soft deletes. Seeded with the ~115 existing codes (9000 Lulu Regional Office/Kochi … 9840 Reo Kerala/Kochi). Seed from CSV files kept in `database/seeders/data/` (`purchase_orgs.csv`, `company_codes.csv`) so the lists are reviewable and re-runnable (upsert on `code`).
- Notes: codes are stored as strings (never integers); `9019` and `9093` share the name "Lulu ISD- Head office" (code, not name, is the unique key); `9800` exists in both tables (different tables, no conflict).
- Delete is blocked (or soft-delete only) when referenced by a TOT.

**TOT (derived from `TOT_Main_Form.html`)**
- `tots`: id, public_id, tot_number, vendor_user_id, owner_user_id, previous_tot_id, type(new|renew), financial_year, region_id, vendor_name, vendor_email, vendor_type(Normal Rep/Brand Rep/Distributor/Import), status, current_stage, existing_tot_doc_path, submitted_at, approved_at, timestamps.
- `tot_reviewers`: tot_id, group, user_id (one row per selected user) + `tot_esign_signatories` (role, user_id, order).
- `tot_status_history`: tot_id, status, updated_on, updated_by_user_id, comments, pending_with_email(s), action (approve/reject/send_back/system).
- `tot_comments`: tot_id, user_id, comment, visible_to_vendor, stage.
- `tot_assignments`: tot_id, stage, user_id, is_current, acted_at (what drives each reviewer's "my pending" list and emails).
- `vendor_profiles` (section 1–2): registration_type, agreement_no, scope_type, state_id, company_code_id, purchase_organization, vendor_code, brand, parent_code, address, pin, pan, gst_type, gst_no, msme_type, msme_no.
- `vendor_contacts`: type(sales|finance|logistics), name, phone, email.
- `vendor_bank_details`: accounting_group_id, vendor_type, account_name, bank_name_address, account_no(enc), ifsc, verification_status, verified_at, cashfree_ref.
- `vendor_documents`: tot_id, type(PAN, GST, MSME, cancelled_cheque, RTGS, board_resolution, existing_tot…), s3_path, original_name, size, mime.
- `vendor_store_terms` (Store 1–7 by state): state_id, store_code.
- `vendor_payment_terms`: accounting_info_type, reconciliation_type, days_from_statement, days_grn, y/z/b/sor days, payment_mode, apply_15_day_rule.
- `vendor_trade_terms` (transportation, loading/unloading, pilferage, ullage, packing, insurance in transit/store, vendor returns transport, returnable goods, return type) – term, party/mode, value.
- `vendor_agreement_periods` (from/to) and `vendor_fees`: fee_type(vendor_listing, brand_listing, sku_listing), value, basis (on/off invoice), frequency.
- `vendor_margins`: kind(regular|eoss|front_end|back_end), value, basis, material_category/brand, channel (Lulu Daily/Hyper).
- `vendor_rebates` + `vendor_rebate_slabs`: deduction mode, base target, slab target, rebate.
- `vendor_promoters`: count, sharing value, payroll, uniform cost, payment deduction.
- `deliverable_assets` and `vendor_deliverables` exactly as specified (use `tot_id` alongside/instead of `vendor_id` so each year's TOT has its own rows – recommended).
- `vendor_pocs` (1st/2nd POC dept, name, mobile, email), `vendor_signatories` (Lulu & vendor: name, designation, contact, date, stamp path), `vendor_notices` (attention, address, email).
- `esign_requests`, `esign_signers` (phase 5).
- Open point: exact repeating-row structures will be finalised by a line-by-line pass of `TOT_Main_Form.html` in Phase 1 (this list is from the label scan).

---

## 4. Admin Panel – modules & behaviour

1. **Auth**: login (username=email), forced password change on first login, forgot/reset, throttle, session timeout. Network gate returns 404 before any page renders.
2. **User Administration (Admin only)**: list/search/filter by role; Invite (all fields mandatory, async unique checks for email & employee id, multi-role select, Vendor role disabled, TOT Initiator flag); edit; activate/inactivate; soft delete freeing email/mobile; invitation email with "Accept & Login" → admin panel URL (works only from corporate network).
3. **System Data (Admin only)**: CRUD ×5 (Vendor Accounting Group, State, Region, Company Code, Purchase Organization) with listing, add/edit, delete (guard when in use).
4. **TOT Listing** (match `Lulu_TOT_List.html`): filters (vendor, pending-with email, SAP code, type, vendor type, FY, status), pagination, comments + history modals. Scope: owner sees own; Admin all; reviewers see TOTs currently assigned to them (plus ones they acted on, read-only). A newly initiated TOT appears **immediately** (status "TOT Initiated", written in the same transaction as creation) so the owner and Admin can track progress through every later status; no cache on this query.
5. **Initiate TOT** (button dropdown New/Renew): form per `Lulu_Initiate_TOT_Form.html`; FY dropdown computed (Apr–Mar: previous, current, next); Region from DB; role-filtered multi-selects with Select-All (single select for Director); Renew adds optional upload. On submit (transaction): create TOT (`TOT Initiated`), create/reuse vendor user (existing vs new → different email template; new gets 8-char password), store reviewers, queue invitation email to vendor **CC owner**, log history.
6. **Owner actions**: edit, resend invitation, reset & re-initiate incomplete TOT (clears vendor data, new credentials), all in `activity_logs`. **Admin**: transfer ownership (unapproved only; target must be TOT Initiator), logged and shown.
7. **Review workflow** (state machine class + `workflow_stages`): Vendor submit → Pending with buyer → KAM → Legal → Finance → Audit → Director → TOT Approved. Approve by ANY one assignee advances and notifies next group; only **Approve** and **Reject** buttons, each opening a comment modal (comment stored in `tot_comments` and shown in history), Reject routed per stage config; each transition writes `tot_status_history` (status, updated on, updated by, comments, pending-with emails) and queues emails. Concurrency: row lock so two reviewers can't both act.
8. **TOT view page**: read-only vendor data, documents (pre-signed), comment thread, action bar by current assignment.
9. **Phase 5**: PDF generation of TOT form (DomPDF/Snappy), eMudhra Signer Core integration, signer order, status `E-Sign Initiated`, webhook/callback via VOB→Admin API.

**Admin API (`/api/v1`)** – all behind network+signature+bearer, all logged:
`POST vendor/login`, `POST vendor/password` (change), `GET masters` (accounting groups, states, regions, company codes), `GET tot/{publicId}` (draft/data + status), `PUT tot/{publicId}/draft`, `POST tot/{publicId}/documents` (S3 metadata), `POST tot/{publicId}/submit`, `POST tot/{publicId}/bank-verification` (store result), `POST logout`. Vendor can only touch TOTs they own (policy by token user).

---

## 5. Vendor Onboarding Platform (VOB)

- Stateless-ish public app: **no business DB**, only: `cashfree_verifications` (temporary: tot ref, reference_id, status, masked account, response, expires_at, purged by scheduler), `api_logs`, sessions/cache/jobs.
- Flow: email link (signed+encrypted) → login page (existing vendor: own password; new vendor: password from email) → first-login forced password change → TOT form (multi-section wizard with autosave to Admin API) → uploads direct to S3 (pre-signed) → Cashfree Reverse Penny Drop bank verification → submit → read-only status page; shows reviewer comments when sent back and allows resubmission. Marks "Pending with vendor" on first login (via API).
- `AdminApiClient` service: encrypts credentials header, builds decoy body, signs requests, Bearer token handling/refresh, retries/timeouts, logs every call/response to `api_logs`, same purge job.
- Master data (accounting groups, states, regions, company codes, purchase orgs) fetched via API with short cache. Field rules (§5A): vendor-mandatory validation, greyed-out buyer-only fields (Company Code, Purchase Organization, Vendor Accounting Group Type, Reconciliation of Accounts Type), and renewal prefill from the previous TOT.
- Hardening: WAF/CloudFront, rate limiting, reCAPTCHA on login, no stack traces, CSP, S3 bucket private, session in Redis, token kept server-side only (never in browser JS).
- Cashfree: create verification (RPD), show QR/UPI link, poll/webhook for result, store only reference + status; failure shows "Bank details validation failed" per form.

---

## 5A. Field rules (mandatory / prefill / locked)

**Vendor-mandatory (validated in VOB on submit and again in the Admin API):** Vendor Name, Address, City, State, PIN, GST Regn. No, PAN No, GST Type, Sales Contact No, Sales Email, Finance Contact No, Finance Email, Account Name, Bank Name and Address, Account No, IFS Code, Payment Terms, Payment Mode.

**Buyer-mandatory (validated on Approve at "Pending with buyer"; Reject does not require them):** Company Code, Purchase Organization, Vendor Name, Address, City, State, PIN, GST Regn. No, PAN No, GST Type, Sales Contact No, Sales Email, Finance Contact No, Finance Email, Vendor Accounting Group Type, Account Name, Bank Name and Address, Account No, IFS Code, Reconciliation of Accounts Type, Payment Terms, Payment Mode.
- Buyer-only fields (vendor cannot edit): **Company Code, Purchase Organization, Vendor Accounting Group Type, Reconciliation of Accounts Type**. In VOB these render greyed out/read-only, and the Admin API **ignores** them if sent by a vendor token (server-side enforcement, not just UI).
- All other buyer-mandatory fields are vendor-filled; the buyer can review and correct them.
- Later reviewers (KAM onward) see the data read-only.
- Rules live in one `TotFieldRules` class (field → editable_by, required_for_vendor_submit, required_for_buyer_approve) used by Form Requests in both the API and the UI; VOB gets the vendor subset from the API so it cannot drift.
- Data additions: `vendor_profiles.city` (not in the original form scan); `vendor_profiles.company_code_id`, `purchase_org_id`; `vendor_bank_details.accounting_group_id`; `vendor_payment_terms.reconciliation_type`. "Payment Terms" = the condition for receiving payment; "Payment Mode" = the method by which money is transferred to the vendor (confirmed). Both map to the payment block of the form; exact inputs get matched during the line-by-line pass in Phase 1.

**Renewal prefill (VOB):** for `type = renew`, the Admin API returns these values from the vendor's previous approved TOT (`previous_tot_id`) when the form is first opened; vendor may edit them: Registration Type, Vendor Agreement No, Company Code, Purchase Organization, Vendor Name, Vendor Code, Brand, Parent Code, Address, State, PIN, GST Regn. No, PAN No, MSME Reg No, GST Type, MSME Type, Account Name, Bank Name and Address, Account No, IFS Code.
- Company Code and Purchase Organization are prefilled but stay greyed out for the vendor.
- Bank details: prefilled, but Cashfree Reverse Penny Drop verification is **always re-run on every renewal, even if the details are unchanged** (accounts can be closed at any time). Submit is blocked until verification succeeds. Account number is returned masked unless the vendor chooses to edit it.
- Fields not prefilled (fees, margins, terms, deliverables, signatories, documents) start empty.

## 5B. Local development database

- Admin Panel `.env` (local only): `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=vendor_onboarding`, `DB_USERNAME=root`, `DB_PASSWORD=` (empty). Database already created on localhost.
- `.env` is git-ignored; only `.env.example` (no real credentials) is committed. Staging/production must use a dedicated least-privilege MySQL user with a strong password from AWS Secrets Manager or equivalent; root with an empty password is for the local machine only.
- `vendor_onboarding` is the **Admin Panel** database (confirmed; it holds all business data). The VOB app keeps only its small local tables (`cashfree_verifications`, `api_logs`, jobs/sessions). Proposed separate DB name for it: `vendor_onboarding_portal` (confirm, or use SQLite/Redis-only locally).

## 5C. Keys and secrets (owner-generated; Claude must never generate or commit them)

Rule: no key, secret or certificate is generated, created or stored by Claude/the code scaffolding. The owner generates them and places them as below. Code only reads them from env/paths and fails with a clear "key not configured" error (and returns 404 externally) if missing.

**1. RSA key pair (login-header encryption, RSA-OAEP key wrapping)**
```
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out admin_private.pem
openssl pkey -in admin_private.pem -pubout -out vob_public.pem
```
(Run in Git Bash / any machine with OpenSSL, outside the repos.)

| File | Goes to | Config |
|------|---------|--------|
| `admin_private.pem` (PRIVATE) | **Admin Panel only** → `storage/app/keys/admin_private.pem` (git-ignored, outside `public/`, file mode 600) | `.env`: `API_RSA_PRIVATE_KEY_PATH=storage/app/keys/admin_private.pem`, `API_RSA_KEY_ID=k1` |
| `vob_public.pem` (public) | **VOB only** → `storage/app/keys/vob_public.pem` (git-ignored) | `.env`: `ADMIN_API_RSA_PUBLIC_KEY_PATH=storage/app/keys/vob_public.pem`, `ADMIN_API_RSA_KEY_ID=k1` |

The private key never leaves the Admin Panel; delete the local copy of `admin_private.pem` after placing it. A key id header (`X-Key-Id`) allows rotation without downtime (Admin keeps old + new private keys during the switch).

**2. HMAC signing secret (shared by both apps)**
```
openssl rand -base64 48
```
Put the same value in Admin `.env` as `API_HMAC_SECRET=` and in VOB `.env` as `ADMIN_API_HMAC_SECRET=`.

**3. Other values the owner supplies in `.env` (never committed):**
- Admin: `ADMIN_ALLOWED_CIDRS` (corporate ranges), `VOB_ALLOWED_IPS` (VOB egress/Elastic IP), `APP_KEY` (via `php artisan key:generate`, run by the owner), mail settings.
- VOB: `ADMIN_API_BASE_URL`, AWS S3 key/secret/bucket/region, Cashfree client id/secret, eMudhra credentials (later), mail settings.
- Production: store these in AWS Secrets Manager / SSM Parameter Store, not on disk in plain text; `.env.example` contains only empty placeholder names.

## 5D. Working rules

- **No git commits by Claude.** All changes are left uncommitted in the working tree; the owner reviews them and commits with their own message. Claude does not run `git commit`, `git push`, `git add` for commit purposes, or branch/merge operations unless explicitly asked.
- Stop after each phase for review.

---

## 6. Phased delivery

| Phase | Scope | Exit criteria |
|------|-------|---------------|
| 0 | Resolve §0 questions; finalise TOT field inventory | Signed-off ERD |
| 1 | Admin scaffolding: network gate, auth, migrations/seeders, roles, api_logs + purge, activity log, base layout from docs HTML | 404 tests pass for non-allowed IPs |
| 2 | User admin + System Data CRUD (5 masters incl. Purchase Org; seed purchase orgs and company codes) | Invite email, soft-delete reuse tested |
| 3 | Initiate TOT (New/Renew), listing, owner/admin actions, emails | Role-scoped listing tests |
| 4 | Admin API + VOB app (login, password, form, S3, Cashfree, submit) | End-to-end vendor submit |
| 5 | Review workflow + notifications + history | Full approval/reject matrix tests |
| 6 | PDF + eMudhra e-sign (both apps) | Signed PDF stored, statuses updated |
| 7 | Security review, pen-test, load test, deployment (AWS, VPN, cron, backups) | Go-live checklist |

(Phase 4 and 5 can swap; the workflow engine is needed for submit→"Pending with buyer".)

Testing: feature tests for every transition, policy tests, IP/signature middleware tests, encrypted-header login test, purge command test, Cashfree/eMudhra faked HTTP.

---

## 7. Prompt – ADMIN PANEL application (this repo)

```
Act as a senior Laravel developer. Build the "Vendor Handshake – Admin Panel" (private, corporate-network-only Laravel 12 / PHP 8.3 / MySQL 8 app) in this repo. Do it in the phases below and stop after each phase for review. Follow docs/DEVELOPMENT_PLAN.md and use docs/*.html as the UI reference (Lulu_TOT_List.html, Lulu_Initiate_TOT_Form.html, TOT_Main_Form.html).

SECURITY (non-negotiable)
- Middleware RestrictNetwork runs first: web routes only from ADMIN_ALLOWED_CIDRS; /api/* only from VOB_ALLOWED_IPS; otherwise abort(404) with the standard 404 view (no login page, no redirect). Configure trusted proxies explicitly.
- API: HMAC-SHA256 signature header (method, path, timestamp, nonce, body hash), ±5 min, nonce replay protection in Redis; failures → 404.
- Vendor login API: credentials only inside an encrypted header X-Auth-Data (AES-256-GCM, random IV, key wrapped with RSA-OAEP; keys in env/secrets). Request body contains decoy dummy fields that are ignored. Returns Sanctum access_token; all other APIs need Bearer token.
- Every user/TOT/document has public_id CHAR(16) from random_bytes, unique; never expose auto-increment ids. Email links hold only encrypted signed expiring payloads. Use per-message random IVs for encryption (do NOT reuse public_id as IV).
- Encrypted casts for PAN/GST/bank fields; passwords hashed; 8-char random initial passwords with forced change at first login.
- api_logs table logging every API request/response (redact passwords/tokens, mask account numbers); command logs:purge-api deleting rows older than 2 months, scheduled daily; document the cron entry.

PHASE 1: scaffolding – packages, env config, RestrictNetwork + signature + ApiLogger middleware, auth (login, forced password change, reset), layout with left menu (TOT Listing; User Administration & System Data only for Admin), user_roles seeded with the 24 roles (Vendor non-assignable), activity_logs, tests proving 404 from disallowed IPs.
PHASE 2: User Administration (list, invite with all-mandatory fields and async unique checks for email/employee id, multi-role, TOT Initiator flag, edit, activate/inactivate, soft delete that frees email/mobile, invitation email with "Accept & Login" button) and System Data CRUD (Vendor Accounting Group, State, Region, Company Code, Purchase Organization). Seed purchase_orgs and company_codes from CSVs in database/seeders/data (code, description/name, city; codes as strings).
PHASE 3: Migrations for the full TOT data model (see plan §3), Initiate TOT (New/Renew with optional existing-TOT upload, dynamic FY previous/current/next, Region from DB, role-based multi-select reviewer dropdowns with Select All, single Director), vendor user creation (new vs existing email templates, CC to owner), TOT listing scoped by owner/Admin/assigned reviewer with filters, comments & history modals, owner actions (edit, resend, reset & re-initiate), Admin ownership transfer – all logged.
PHASE 4: REST API v1 (vendor login, change password, masters, get/save draft, document metadata, bank verification result, submit) with API Resources, policies, rate limits; submit sets status "Pending with buyer".
PHASE 5: Review workflow state machine (configurable stages and reject routing), any-one-approves semantics, mandatory comments, only Approve and Reject buttons at every stage (each with a comment modal; comments shown in history), buyer-stage Approve validated against the 22 buyer-mandatory fields and vendor tokens blocked from editing Company Code, Purchase Organization, Vendor Accounting Group Type and Reconciliation of Accounts Type (see plan §5A), status history (status, updated on, updated by, comments, pending-with email), queued notifications, row locking.
PHASE 6 (later): PDF of TOT form + eMudhra Signer Core e-sign (order: Vendor, Central Buyer, Manager Central Buying, GM Buying).

Deliver with migrations, seeders, factories, Pest tests, and a README of env vars. Ask before making assumptions listed as open in the plan.
```

---

## 8. Prompt – VENDOR ONBOARDING PLATFORM (new repo; continuation)

```
Act as a senior Laravel developer. Build "Vendor Handshake – Vendor Onboarding Platform (VOB)", an internet-facing Laravel 12 app, as the continuation of the Admin Panel project (read its docs/DEVELOPMENT_PLAN.md, the API contract it exposes, and docs/TOT_Main_Form.html for the form).

RULES
- VOB has NO business database. All TOT/vendor data is read/written through the Admin Panel REST API (/api/v1). Local tables only: cashfree_verifications (temporary, purged), api_logs (every outbound/inbound call + response, redacted, purged after 2 months by a scheduled command), plus sessions/cache/jobs (Redis).
- AdminApiClient service: sends credentials only as an encrypted header X-Auth-Data (AES-256-GCM random IV, key RSA-OAEP wrapped) with a decoy dummy JSON body; afterwards uses Bearer access_token; adds HMAC signature/timestamp/nonce headers; stored server-side only; handles expiry/refresh, timeouts, retries; logs everything.
- Links from emails are encrypted/signed/expiring; no raw IDs or emails in URLs; routes use encrypted tokens.
- Follow plan §5A: the 18 vendor-mandatory fields; grey out and never submit Company Code, Purchase Organization, Vendor Accounting Group Type, Reconciliation of Accounts Type; for renewals prefill the 20 listed fields from the API.
- Also required: a **Save Draft** button + last-saved time + autosave (PUT .../draft); the vertical **status tracker** in the left margin from the API `progress` (ticks for passed stages, current highlighted, remaining stages pending in order; see docs/vob_status_tracker_reference.html); and development switches `VOB_ENFORCE_REQUIRED` / `VOB_ENFORCE_DOCUMENTS` (false = the front end does not block Submit on mandatory fields/documents; always on when APP_ENV=production; formats always validated).
- Features: login (existing vendor password vs new vendor password from email), forced password change, multi-section TOT wizard replicating TOT_Main_Form.html with server-side validation and autosave, master dropdowns (Vendor Accounting Group Type, State, Region, Company Code) via API with caching, document upload to AWS S3 (private bucket, pre-signed, MIME/size/AV checks), Cashfree Reverse Penny Drop bank account verification (create → poll/webhook → store ref+status only, show "Bank details validation failed" on failure), review/submit, read-only status page, display reviewer comments when sent back and allow resubmission. First successful login triggers status "Pending with vendor" through the API.
- Hardening: rate limiting + captcha on login, CSP/security headers, no debug output, WAF-friendly, token never exposed to the browser.

PHASES: 1 scaffolding + AdminApiClient + logging/purge; 2 auth & password flow; 3 TOT wizard + S3 uploads; 4 Cashfree integration; 5 submit/resubmit/status; 6 eMudhra vendor e-sign page (later); 7 security review and deployment. Stop after each phase for review. Include Pest tests with Http::fake for Admin API, Cashfree and S3.
```

---

## Implementation notes (kept current as phases complete)

### Phase 3 (TOT initiation) - design decisions that refine sections 3 and 4
- A `vendors` table was added: one row per vendor organisation (its id is the "Internal Vendor ID" in the list, `user_id` is the vendor login). `tots.vendor_id` replaces `tots.vendor_user_id`; each financial year gets a new TOT for the same vendor.
- The TOT timeline is a single table, `tot_status_history`, with an `event` column (status_change, edited, invitation_resent, reset, ownership_changed, comment). The "Comments" and "History" modals read it; the separate `tot_comments` table is not used.
- The existing-TOT PDF (renewals) is stored as a `tot_documents` row of type `existing_tot` on the private disk `TOT_DOCS_DISK`.
- Reviewer role lists live in `App\Enums\ReviewerGroup::roleSlugs()` (a code change), not in a `reviewer_group_roles` table.
- `vendor_deliverables` is keyed by `tot_id` (not vendor_id) so each year keeps its own rows.
- Vendor links: `TotLink::token()` is encrypted with the Admin app key and is opaque to VOB. VOB sends it to the Admin API (Phase 4) which calls `TotLink::resolve()`. Resend/reset rotate `tots.invite_nonce`, killing older links. VOB URL: `VOB_URL` in `.env`, path `/onboard/{token}`.
- Invitation emails (new vendor with temporary password, existing vendor without) are sent immediately, not queued, and copy the TOT owner.
- Only the TOT owner can edit, resend or reset (and only before approval); only Admin can transfer ownership. One TOT per vendor per financial year.

### Phase 4 (Admin REST API v1)
- Contract for the VOB build: docs/API_CONTRACT.md (signature, encrypted X-Auth-Data header, bearer tokens, endpoints, form schema).
- Bearer tokens are custom (table `api_tokens`, SHA-256 hash only, 30 min sliding idle / 8 h absolute, one live token per vendor) instead of Sanctum - no extra dependency.
- The TOT form is described once in `config/tot_form.php` (fields, formats, who may edit, mandatory lists); `TotFormService` reads/validates/saves it. Masters travel as codes, not ids.
- Vendor documents: VOB uploads to the private S3 bucket and registers the metadata; Admin reads from the same bucket (`TOT_DOCS_DISK=s3`; needs `composer require league/flysystem-aws-s3-v3` in both apps).
- Submit sets "Pending with buyer" and emails the Buyer Reviewers (queued mail; run `php artisan queue:work`).

### Form change: docs/TOT_Main_Form.html is now the master TOT form (replaces Lulu_TOT_Form.html)
Differences found and applied (migration `2026_10_23_000001_update_tot_form_for_main_form`, `config/tot_form.php`, API_CONTRACT.md section 8):
- Payment Mode, Lulu Receipt Mode and Deduction Mode share a new list (RTGS Payment, ePayment, Bank Transfer, Manual Check, RTGS/NEFT Payment H2H); the three columns were widened to 40 characters.
- Vendor Type (entity) adds Hindu Undivided Family.
- Lulu SPOC table adds a 2nd-POC email (`tot_pocs.second_email`).
- New E-Sign section and Terms of Trade signatories are now signature anchors (L01, L02, L03, V01) for the eMudhra step, so `tot_signatories` keeps only `bda_lulu` / `bda_vendor` inputs; anchors are listed in `config/tot_form.php` (`esign_anchors`).
- City was already in the model. Clause wording, categories, deliverable assets and all other fields are unchanged.

### Save Draft, status tracker, development switches (added after Phase 4)
- Save Draft = `PUT /tots/{id}/draft` (lenient, stamps `tots.draft_saved_at`); VOB shows a Save Draft button, last-saved time and autosave.
- Vendor status tracker: API `progress` (10 ordered stages with done/current/pending), reference markup `docs/vob_status_tracker_reference.html`.
- `TOT_ENFORCE_REQUIRED` / `TOT_ENFORCE_DOCUMENTS` switch the mandatory checks off for development (never in production); VOB mirrors them with `VOB_ENFORCE_REQUIRED` / `VOB_ENFORCE_DOCUMENTS`. Formats are always validated.
- VOB prompt additions (section 8): include the Save Draft button, the left-margin tracker from `progress`, and the two VOB switches (ignored in production).
