# رفتار فعلی Crypto Deposit / USDT Verification

## نتیجهٔ کلیدی

سیستم دو سطح متفاوت دارد:

1. **شبکه‌های دارای implementation در adapter**: TRC20, BNB20, ERC20, TON, SOL.
2. **شبکه‌های feature-config فعال برای crypto wallet**: فقط `bnb20` و `trc20`; سه شبکهٔ دیگر در `config/feature_flags.php` false هستند.

بنابراین code path برای پنج شبکه وجود دارد، اما config محصول در حالت فعلی فقط دو شبکه را فعال اعلام می‌کند. همچنین User CryptoDepositController هر پنج شبکه را در allowlist فرم می‌پذیرد؛ این یک inconsistency policy است که پیش از تغییر باید تصمیم محصول برای آن اعلام شود.

## Dependency واقعی verification

`AppServiceProvider` این binding را ثبت کرده است:

```text
CryptoVerificationAdapter -> CryptoApiAdapter
```

`CryptoExplorerAdapter` وجود دارد اما binding فعال نیست و فقط `unavailable`/manual-review برمی‌گرداند؛ آن auto-verification fallback واقعی نیست.

## جریان واقعی

```text
User CryptoDepositController
→ CryptoDepositService::createDeposit
→ pending crypto_deposit row
→ Cron/CryptoDepositService::tryAutoVerify
→ CryptoVerificationAdapter (CryptoApiAdapter)
→ verified / pending / mismatch/error/manual_review
```

در `tryAutoVerify`:

```text
verified       → approve
mismatch/error → manual_review
pending/unavailable → retry تا max attempts، سپس manual_review
```

## شبکه‌ها و API key

| Network | Method | API key فعلی | رفتار بدون key | fallback فعلی |
|---|---|---|---|---|
| TRC20 | `verifyTronTransaction` | نیاز ندارد | auto verification تلاش می‌شود | TronScan سپس TronGrid، ولی schema fallback با parser اصلی یکسان نیست |
| BNB20 | `verifyBscTransaction` | `bscscan_api_key` | فعلاً error | BscScan mainnet/testnet هر دو همان key را می‌خواهند |
| ERC20 | `verifyEthereumTransaction` | `etherscan_api_key` | فعلاً error | Etherscan mainnet/sepolia هر دو همان key را می‌خواهند |
| TON | `verifyTonTransaction` | `toncenter_api_key` اختیاری | public Toncenter endpoint تلاش می‌شود | Toncenter mainnet/testnet |
| SOL | `verifySolanaTransaction` | `solana_rpc_url` اختیاری | public mainnet RPC تلاش می‌شود | یک RPC URL، نه provider chain واقعی |

بنابراین اگر product requirement این است که **BNB20 و ERC20 بدون API key auto-verify شوند**، این requirement در code فعلی پیاده نشده است؛ guard جدید فقط همان رفتار قبلی را type-safe کرده، نه اینکه policy جدیدی ایجاد کند.

## Address / config sources

CryptoApiAdapter و API WalletController از این settingها استفاده می‌کنند:

```text
site_wallet_bnb20
site_wallet_trc20
site_wallet_erc20
site_wallet_ton
site_wallet_sol
```

اما User CryptoDepositController در view path از نام‌های دیگری نیز استفاده می‌کند:

```text
site_usdt_bnb20_address
site_usdt_trc20_address
```

این mismatch باید پیش از فعال‌سازی production یکسان شود؛ در غیر این صورت UI و auto verifier ممکن است مقصدهای متفاوت ببینند.

## Sender verification

Interface پارامتر `fromWallet` دارد، اما CryptoApiAdapter در `verify()` آن را به verifierهای network منتقل نمی‌کند:

```php
verify(network, txHash, fromWallet, toWallet, amount)
→ verifyTransaction(network, txHash, toWallet, amount)
```

پس sender address فعلاً در auto-verification بررسی نمی‌شود. این ممکن است intentional باشد (هر فرستنده مجاز است) یا requirement امنیتی گمشده باشد؛ نیازمند تصمیم محصول است.

## Feature policy فعلی

```text
FEATURE_CRYPTO_ENABLED=false by default
FEATURE_CRYPTO_DEPOSIT_ENABLED=false by default
```

در config wallet:

```text
bnb20=true
trc20=true
erc20=false
ton=false
sol=false
```

اما controller allowlist هر پنج network را می‌پذیرد. این inconsistency اصلی policy است.

## تغییرهای اعمال‌شده تا اینجا

- HMAC video reward fail-closed/type-safe شد.
- TRC20 response guardهای type-safe اضافه شد.
- BNB20 API key و response/contract guardهای type-safe شروع شد.

هیچ network جدیدی فعال نشده و هیچ عملیات on-chain انجام نشده است.

## تصمیم‌های لازم از مالک محصول

1. آیا فقط TRC20 و BNB20 باید فعال باشند یا هر پنج شبکه؟
2. آیا BNB20/ERC20 بدون API key باید auto-verify شوند؟ اگر بله، provider عمومی مورد تأیید چیست؟
3. آیا sender address باید با `from_wallet` match شود یا هر sender پذیرفته است؟
4. نام canonical setting مقصد wallet چیست: `site_wallet_*` یا `site_usdt_*_address`؟
5. آیا fallback provider با schema متفاوت مجاز است یا فقط باید manual_review شود؟
