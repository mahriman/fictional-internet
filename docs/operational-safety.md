# Operational safety

## Generation rate limit

Ordinary generation and discussion continuation share a limit of ten POST
submissions per authenticated user per minute. The key uses the authenticated
user identity, so switching between generation and continuation does not
provide a separate allowance. GET forms do not consume this allowance. POSTs
are counted conservatively before validation and attempt-token resolution;
that means a duplicate completed submission can count even though it cannot
make another provider call. A limited request receives HTTP 429, a
`Retry-After` header, and a safe message asking the user to wait before trying
again. The request does not reach a provider or claim/create an attempt.

## GenerationAttempt retention

GenerationAttempt rows support form-token idempotency and synchronous request
operations; durable generation provenance remains in immutable versions.
`php artisan generation-attempts:prune --dry-run` reports eligible row counts
without deleting. Run `php artisan generation-attempts:prune` to remove only
attempt rows. The command never deletes content or versions and is safe to
repeat.

Default retention windows are centralized in `config/generation_attempts.php`:

- Issued but unsubmitted attempts: 1 day after creation.
- Failed attempts: 7 days after their failure update.
- Completed attempts: 30 days after completion, preserving a generous window
  for duplicate-submit resolution.
- In-progress attempts: 1 day after claim. Normal provider and renderer work
  completes within bounded request timeouts; the longer interval avoids
  pruning an ordinarily active attempt. In-progress rows without `claimed_at`
  are retained conservatively.

For production, schedule the prune command daily after reviewing its dry-run
counts. Scheduler configuration and deployment instructions belong to the
release operations documentation.

## Reference selector loading

The shared ordinary-generation and continuation selector identifies each
choice by content type, stable artifact title, and immutable version number.
Its query loads version identifiers and numbers but omits structured content,
context snapshots, and generation metadata. Version-derived headlines are not
needed to identify a selected immutable reference; the stable title remains
available even if a historical headline differs.
