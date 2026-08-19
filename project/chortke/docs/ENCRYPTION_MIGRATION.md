# 🔐 Encryption v1 → v2 Migration Runbook

This document describes how to safely migrate the Chortke project from the
legacy AES-256-CBC + static-IV encryption (v1) to the new AES-256-GCM + AAD
encryption (v2) without downtime or data loss.

## Why we are doing this

The previous `core/Encryption.php`:

1. Used **AES-256-CBC** with an **IV derived from the key** → identical
   plaintexts produced identical ciphertexts (deterministic). For a 10-digit
   national code this made the column trivially dictionary-attackable.
2. Had **no authentication** (no MAC / AEAD) → vulnerable to padding-oracle
   and bit-flipping attacks.
3. On any decryption failure, **silently returned the raw input** → a
   classic plaintext-injection / authentication-bypass primitive.

The new implementation:

1. Uses **AES-256-GCM** with a 12-byte random IV and a 16-byte tag.
2. Derives a **per-context sub-key** via HKDF-SHA256 so each column
   (e.g. `kyc.national_code`, `bank.card_number`) has key isolation.
3. Binds the ciphertext to its **context as additional auth data (AAD)** so
   moving a ciphertext to another column or row fails authentication.
4. **Throws** on any failure. A new explicit `tryDecrypt()` is provided for
   call-sites that need a soft fallback.
5. Keeps a **read-only legacy decoder** behind the `ENCRYPTION_ALLOW_V1_READ`
   feature flag for the migration window only.

## Touched files

| File | Change |
|---|---|
| `core/Encryption.php` | Rewritten (AEAD, AAD, HKDF, fail-loud). |
| `app/Services/KYCService.php` | Uses `tryDecrypt()` + logs + `[CORRUPTED]` sentinel. |
| `app/Services/BankCardService.php` | All `encrypt()` calls carry context; new `safeDecrypt()` helper. |
| `app/Jobs/KYC/SubmitKYCJob.php` | New `national_code_hash` column for dedup; encrypts with context. |
| `app/Services/Auth/TwoFactorService.php` | New 2FA secrets written via central v2 AEAD; legacy CBC formats still readable. |
| `database/migrations/2026_06_03_encryption_v2_hardening.sql` | Schema changes. |
| `scripts/migrate_encryption_v1_to_v2.php` | Idempotent backfill / re-encryption job. |
| `scripts/check_encryption_guard.sh` | CI regression guard. |
| `tests/Unit/Core/EncryptionTest.php` | Security regression tests. |
| `.env.example`, `.env.production.example` | New `ENCRYPTION_ALLOW_V1_READ` flag. |

## Phase plan (zero-downtime)

### Phase 1 — Deploy code

1. Apply DB migration: `php scripts/apply_migrations.php database/migrations/2026_06_03_encryption_v2_hardening.sql`
2. Deploy new code with `ENCRYPTION_ALLOW_V1_READ=true` in `.env`.
   - All new writes are v2.
   - All existing v1 rows remain readable.
3. Smoke-test KYC viewing, bank card listing, 2FA login.

### Phase 2 — Backfill

```bash
# dry-run first
php scripts/migrate_encryption_v1_to_v2.php

# when output looks correct:
php scripts/migrate_encryption_v1_to_v2.php --apply
```

The script:
- skips rows already in `v2:` format,
- backfills `kyc_verifications.national_code_hash` so the new dedup query works,
- records any failed row in `encryption_migration_failures` for manual review,
- is **safe to re-run** any number of times.

### Phase 3 — Cutover

When `SELECT COUNT(*) FROM encryption_migration_failures` returns 0 and there
are no remaining v1 rows:

1. Set `ENCRYPTION_ALLOW_V1_READ=false` (or remove the line — default is false).
2. Optionally delete the `decryptV1Legacy()` method from `core/Encryption.php`
   and the legacy CBC branches in `TwoFactorService::decryptSecret()`.
3. Run `bash scripts/check_encryption_guard.sh` in CI on every PR.

## Operational notes

- **Ciphertext is now ~2× wider.** The migration widens affected columns
  to `VARCHAR(512)`. Verify your replicas have the same schema before cutover.
- **Equality on ciphertext is impossible.** Use HMAC fingerprint columns
  (`card_hash`, `national_code_hash`, …) for dedup / lookup.
- **Context is part of authentication.** If you ever change the `context`
  string for an existing column you must re-encrypt the whole table.
- **Decryption failures are now visible.** Expect a temporary spike in
  `kyc.decrypt.failed` / `bankcard.decrypt.failed` / `2fa.decrypt_failed`
  logs during Phase 2 — every spike maps to a real corrupted/legacy row.

## Future hardening (suggested)

- Replace `secure_key()` with envelope encryption (KMS / HashiCorp Vault).
- Consider `sodium_crypto_aead_xchacha20poly1305_ietf_*` for a 24-byte
  random nonce (better safety margin in tight loops).
- Add a periodic job that re-encrypts hot rows to enable key rotation.
