# Vendor Handshake - Vendor Onboarding Platform (VOB)

Public, internet-facing Laravel 12 app. Vendors sign in from an emailed link, fill the Terms of Trade (TOT)
form (docs/TOT_Main_Form.html), upload documents to S3, verify their bank account with Cashfree
(Reverse Penny Drop) and submit. It has NO business database: all data goes through the Admin Panel's
REST API (separate repo). Read before coding: docs/API_CONTRACT.md, docs/DEVELOPMENT_PLAN.md.

## Rules
- Never run `git commit`/`git push`; leave changes uncommitted for the owner to review.
- Never generate keys, secrets or credentials; read them from env paths. The owner creates the RSA key pair and HMAC secret.
- Every Admin API call: HMAC signature headers + (after login) Bearer token; login/password change send credentials ONLY in the
  encrypted X-Auth-Data header with a random decoy JSON body. Log every outbound call and response to `api_logs`
  (redact passwords, tokens, X-Auth-Data; mask account/PAN/GST numbers); purge after 2 months with a scheduled command.
- The access token stays server-side (session), never in browser JavaScript. No raw ids, emails or passwords in URLs.
- Mandatory-field switches VOB_ENFORCE_REQUIRED / VOB_ENFORCE_DOCUMENTS may turn blocking off for development only; always on in
  production; formats always validated.
- The form schema, formats, locked (buyer-only) fields and mandatory lists come from the API (`locked_fields`, `required_fields`,
  `validation`) - do not hard-code them.
- Left margin shows the status tracker from the API `progress` (docs/vob_status_tracker_reference.html). Form has Save Draft + Submit.
- Tests: Pest with Http::fake for the Admin API, Cashfree and S3. Stop after each phase for review.
