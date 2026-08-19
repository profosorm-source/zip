# Event Registry Enhancement: Wildcard Pattern Support

**Date:** 2026-05-31  
**Problem:** When new financial events are dispatched without being explicitly added to `EventRegistry::getDepositTriggerEvents()`, the `WalletDepositRequestListener` is not triggered, breaking the deposit flow.  
**Solution:** Introduce wildcard pattern matching for event listeners using `fnmatch()` to automatically capture new events under common namespaces.

## Changes Made

### 1. `core/EventDispatcher.php`

**Added:**
- `$patternListeners` property to store wildcard-based listeners.
- `listenPattern(string $pattern, $listener, $priority = 0)` method to register listeners using shell-style wildcards.
- Pattern matching in `dispatch()` method: checks both exact-match listeners and pattern listeners.

**Behavior:**
- Pattern listeners are checked when an event is dispatched.
- Example: if pattern `wallet.*` is registered, events like `wallet.deposit.requested`, `wallet.transfer.complete`, etc. will match.
- Uses `fnmatch()` for compatibility with shell wildcard syntax (`*`, `?`, `[...]`).

### 2. `app/Events/Registry/EventRegistry.php`

**Added:**
- `getDepositTriggerPatterns(): array` method that returns wildcard patterns covering all deposit-triggering namespaces.

**Patterns:**
```php
[
    'wallet.*',               // any wallet-related event
    'crypto.*',               // crypto deposit/confirm events
    'gateway.*',              // payment gateways
    '*.revenue.*',            // content/banner revenue events
    'influencer_order.*',     // influencer order lifecycle
    'custom_task.*',          // custom task rewards/refunds
    'investment.*',
    'escrow.*',
    'lottery_*',              // lottery namespace variations
    'prediction_*',
    'referral.*',
]
```

**Note:** Existing `getDepositTriggerEvents()` is retained for:
- Backward compatibility.
- Strict/explicit registration for high-priority events.
- Documentation of known financial events.

### 3. `bootstrap/app.php`

**Updated event registration:**
```php
// Register exact-match listeners (as before)
$financialEvents = \App\Events\Registry\EventRegistry::getDepositTriggerEvents();
foreach ($financialEvents as $fEvent) {
    if ($fEvent !== 'wallet.deposit.requested') {
        $dispatcher->listen($fEvent, [$walletDepositRequestListener, 'handle']);
    }
}

// NEW: Register pattern listeners for automatic capture
$patterns = \App\Events\Registry\EventRegistry::getDepositTriggerPatterns();
foreach ($patterns as $pattern) {
    $dispatcher->listenPattern($pattern, [$walletDepositRequestListener, 'handle']);
}
```

## Forward Compatibility

**Before (Fragile):**
- New financial events must be manually added to `getDepositTriggerEvents()` or they won't trigger deposits.
- Risk: developer forgets to add event → wallet deposit broken silently.

**After (Resilient):**
- New events under common namespaces (e.g., `investment.bonus_matured`) are automatically captured by patterns.
- Example: A developer adds `INVESTMENT_BONUS_MATURED = 'investment.bonus_matured'` → it matches pattern `investment.*` → WalletDepositRequestListener is triggered without Registry edits.
- Exact-match registration still works for fine-grained control or overrides.

## Pattern Matching Notes

- Patterns use `fnmatch()` (shell-style wildcards):
  - `*` matches any number of characters (including dots in event names).
  - `?` matches a single character.
  - `[abc]` matches one of the listed characters.
- Examples:
  - `wallet.*` matches `wallet.deposit.requested`, `wallet.transfer.complete`, etc.
  - `*.revenue.*` matches `content.revenue.generated`, `banner.revenue.generated`, etc.
  - `investment.*` matches `investment.matured`, `investment.dividend`, etc.
  - `lottery_*` matches `lottery_round_finished`, `lottery_winner_paid`, etc.

## Testing Checklist

1. **Existing exact-match events still work:**
   - Dispatch `wallet.deposit.requested` → should trigger `WalletDepositRequestListener`.
   - Dispatch `lottery_round.finished` → should trigger `WalletDepositRequestListener`.

2. **New events under covered patterns work:**
   - Dispatch a custom event like `investment.bonus_matured` → should match pattern `investment.*` and trigger listener.
   - Dispatch `wallet.withdrawal.approved` → should match `wallet.*` and trigger listener.

3. **Events not matching any pattern are not triggered:**
   - Dispatch an event like `user.profile.updated` (not in any pattern) → should NOT trigger `WalletDepositRequestListener`.

4. **Multiple pattern matches handled correctly:**
   - If an event matches multiple patterns, listener is called once per registered listener (deduplicated in `EventDispatcher`).

## Performance Considerations

- **Pattern matching overhead:** `fnmatch()` is called for each pattern on each dispatch.
  - For ~10 patterns and ~100-200 events per request, this is negligible (< 1ms overhead).
  - If performance becomes critical, consider:
    - Pre-computing regex equivalents during bootstrap.
    - Caching pattern match results (with cache invalidation on pattern registration).
    - Limiting number of patterns or using a trie-based matcher.

## Rollback/Disable

If pattern listeners cause issues:
1. Comment out the pattern registration loop in `bootstrap/app.php`.
2. System falls back to exact-match-only listeners (previous behavior).
3. No code changes needed in EventDispatcher or EventRegistry (backward compatible).

## Future Enhancements

1. **Admin UI:** Allow dynamic registration of patterns at runtime (stored in config or database).
2. **Validation:** Add method `EventRegistry::validatePatterns()` to ensure patterns don't overlap or conflict.
3. **Metrics:** Track how many events are caught by each pattern for monitoring.
4. **Documentation:** Generate auto-docs listing which events match which patterns.
