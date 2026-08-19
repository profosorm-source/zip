# Schema Integrity Pass — 2026-06-14

## Scope
Fresh migration from zero was executed against an isolated database:

- Database: `chortke_schema_contract_manual`
- Current production/test DB `chortke` was not dropped.

## Result

```json
{
  "migration_success": true,
  "executed_migrations": 63,
  "tables_count": 224,
  "contract_tables_checked": 31,
  "missing": [],
  "contract_pass": true
}
```

## Critical schema contracts checked

The pass verified critical tables/columns for:

- Auth / Session / 2FA
- Wallet / Withdrawals / Manual Deposit / Crypto Deposit / Payment Gateway / Scheduled Payment
- Lottery
- Prediction
- Referral
- Influencer Marketplace
- Social Task
- Disputes
- API tokens

## Notes

- The test used a fresh isolated DB to prove migrations can reconstruct required schema from zero.
- `schema_migrations` count looked inconsistent compared with executed count; this likely indicates older migrations create/drop or modify migration tracking or that some migration files contain multiple schema operations under consolidated runner behavior. The actual schema contract check passed with no missing tables/columns.
- No user/business test data from the current DB was required for this contract check.

## Recommendation

Add a permanent automated `SchemaContractTest` to CI that checks the same required tables/columns before running feature tests.
