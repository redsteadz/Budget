# Liability Negative Balance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store liability account balances as negative numbers internally so the universal balance formula (credit=add, debit=subtract) works correctly for all account types.

**Architecture:** Add `MORTGAGE` and `LINE_OF_CREDIT` to AccountType enum, add `negate()` helper to MoneyCalculator, negate liability opening balances at creation/update time, remove double-negation in NetWorthService and ReportAggregator, add a database migration to negate existing liability balances, and handle backward-compatible data import.

**Tech Stack:** PHP 8.1+, Nextcloud App Framework, BCMath

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `budget/lib/Enum/AccountType.php` | Modify | Add MORTGAGE, LINE_OF_CREDIT; update isLiability/label/supportsInterest |
| `budget/lib/Service/MoneyCalculator.php` | Modify | Add `negate()` method |
| `budget/lib/Controller/AccountController.php` | Modify | Negate balance for liabilities at creation |
| `budget/lib/Service/AccountService.php` | Modify | Negate in openingBalance recalculation |
| `budget/lib/Service/NetWorthService.php` | Modify | Remove double-negation |
| `budget/lib/Service/Report/ReportAggregator.php` | Modify | Remove double-negation |
| `budget/lib/Service/MigrationService.php` | Modify | Backward-compatible import |
| `budget/lib/Migration/Version001000061Date20260516.php` | Create | Negate existing liability balances |
| `budget/templates/index.php` | Modify | Add mortgage/line_of_credit to dropdown |
| `budget/src/modules/accounts/AccountsModule.js` | Modify | Update isLiability arrays |
| `budget/tests/Unit/Enum/AccountTypeTest.php` | Modify | Add new type tests |
| `budget/tests/Unit/Service/MoneyCalculatorTest.php` | Modify | Add negate() tests |

---

### Task 1: AccountType Enum — Add Types and Update Helpers

**Files:**
- Modify: `budget/lib/Enum/AccountType.php`
- Modify: `budget/tests/Unit/Enum/AccountTypeTest.php`

- [ ] **Step 1: Update the enum**

Replace the entire contents of `budget/lib/Enum/AccountType.php` with:

```php
<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

/**
 * Account type enum for different financial account categories.
 */
enum AccountType: string {
    case CHECKING = 'checking';
    case SAVINGS = 'savings';
    case CREDIT_CARD = 'credit_card';
    case INVESTMENT = 'investment';
    case LOAN = 'loan';
    case CASH = 'cash';
    case MONEY_MARKET = 'money_market';
    case CRYPTOCURRENCY = 'cryptocurrency';
    case MORTGAGE = 'mortgage';
    case LINE_OF_CREDIT = 'line_of_credit';

    /**
     * Check if this account type is a liability (balance stored as negative).
     */
    public function isLiability(): bool {
        return match ($this) {
            self::CREDIT_CARD, self::LOAN, self::MORTGAGE, self::LINE_OF_CREDIT => true,
            default => false,
        };
    }

    /**
     * Check if this account type is an asset.
     */
    public function isAsset(): bool {
        return !$this->isLiability();
    }

    /**
     * Check if this account type earns interest.
     */
    public function canEarnInterest(): bool {
        return match ($this) {
            self::SAVINGS, self::INVESTMENT, self::MONEY_MARKET => true,
            default => false,
        };
    }

    /**
     * Check if this account type has a credit limit.
     */
    public function hasCreditLimit(): bool {
        return $this === self::CREDIT_CARD;
    }

    /**
     * Check if this account type can have an overdraft limit.
     */
    public function hasOverdraftLimit(): bool {
        return $this === self::CHECKING;
    }

    /**
     * Check if this account type supports interest tracking (charged or earned).
     */
    public function supportsInterest(): bool {
        return match ($this) {
            self::SAVINGS, self::INVESTMENT, self::MONEY_MARKET,
            self::CREDIT_CARD, self::LOAN, self::MORTGAGE, self::LINE_OF_CREDIT => true,
            default => false,
        };
    }

    /**
     * Get human-readable label.
     */
    public function label(): string {
        return match ($this) {
            self::CHECKING => 'Checking',
            self::SAVINGS => 'Savings',
            self::CREDIT_CARD => 'Credit Card',
            self::INVESTMENT => 'Investment',
            self::LOAN => 'Loan',
            self::CASH => 'Cash',
            self::MONEY_MARKET => 'Money Market',
            self::CRYPTOCURRENCY => 'Cryptocurrency',
            self::MORTGAGE => 'Mortgage',
            self::LINE_OF_CREDIT => 'Line of Credit',
        };
    }

    /**
     * Get all valid account type values as strings.
     */
    public static function values(): array {
        return array_map(fn(self $t) => $t->value, self::cases());
    }

    /**
     * Check if a string is a valid account type.
     */
    public static function isValid(string $value): bool {
        return in_array($value, self::values(), true);
    }
}
```

- [ ] **Step 2: Update AccountTypeTest**

In `budget/tests/Unit/Enum/AccountTypeTest.php`, add `mortgage` and `line_of_credit` to all relevant data providers:
- Add to `liabilityProvider`: `['mortgage', true]` and `['line_of_credit', true]`
- Add to `supportsInterestProvider`: `['mortgage', true]` and `['line_of_credit', true]`
- Update the `testValuesReturnsAllStringValues` count from 8 to 10 and add `'mortgage'` and `'line_of_credit'` assertions
- Update `testIsValidReturnsFalse` to ensure it still rejects invalid types

- [ ] **Step 3: Run tests**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml --filter AccountTypeTest 2>&1"`

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add budget/lib/Enum/AccountType.php budget/tests/Unit/Enum/AccountTypeTest.php
git commit -m "feat: Add mortgage and line_of_credit to AccountType enum"
```

---

### Task 2: MoneyCalculator — Add negate() Method

**Files:**
- Modify: `budget/lib/Service/MoneyCalculator.php`
- Modify: `budget/tests/Unit/Service/MoneyCalculatorTest.php`

- [ ] **Step 1: Add negate() method**

In `budget/lib/Service/MoneyCalculator.php`, add after the `abs()` method (after line 110):

```php
    /**
     * Negate an amount (flip sign).
     *
     * @param float|string $amount
     * @param int $scale Decimal places (default 2)
     * @return string Negated value
     */
    public static function negate(float|string $amount, int $scale = self::DEFAULT_SCALE): string {
        return bcmul(self::normalize($amount), '-1', $scale);
    }
```

- [ ] **Step 2: Add tests for negate()**

Append to `budget/tests/Unit/Service/MoneyCalculatorTest.php` before the closing `}`:

```php
    // ===== negate =====

    public function testNegatePositive(): void {
        $this->assertEquals('-100.00', MoneyCalculator::negate('100.00'));
    }

    public function testNegateNegative(): void {
        $this->assertEquals('100.00', MoneyCalculator::negate('-100.00'));
    }

    public function testNegateZero(): void {
        $this->assertEquals('0.00', MoneyCalculator::negate('0'));
    }

    public function testNegateFloat(): void {
        $this->assertEquals('-50.75', MoneyCalculator::negate(50.75));
    }
```

- [ ] **Step 3: Run tests**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml --filter MoneyCalculatorTest 2>&1"`

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add budget/lib/Service/MoneyCalculator.php budget/tests/Unit/Service/MoneyCalculatorTest.php
git commit -m "feat: Add MoneyCalculator::negate() helper"
```

---

### Task 3: Account Creation — Negate Liability Balance

**Files:**
- Modify: `budget/lib/Controller/AccountController.php`

- [ ] **Step 1: Negate balance at creation time**

In `budget/lib/Controller/AccountController.php`, find the balance parsing section (around line 163-167):

```php
            $balance = 0.0;
            if (isset($data['balance']) && $data['balance'] !== '' && $data['balance'] !== null) {
                $balance = (float) $data['balance'];
            }
```

Add this immediately after:

```php
            // Liability accounts store balance as negative (amount owed)
            // User enters positive value; we negate for internal storage
            if ($balance > 0 && AccountType::from($typeValidation['formatted'])->isLiability()) {
                $balance = -$balance;
            }
```

Add the import at the top of the file (after existing use statements):
```php
use OCA\Budget\Enum\AccountType;
```

- [ ] **Step 2: Verify PHP lint**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && php -l lib/Controller/AccountController.php"`

Expected: No syntax errors.

- [ ] **Step 3: Commit**

```bash
git add budget/lib/Controller/AccountController.php
git commit -m "feat: Negate opening balance for liability accounts at creation"
```

---

### Task 4: Account Update — Negate Opening Balance on Edit

**Files:**
- Modify: `budget/lib/Controller/AccountController.php`

- [ ] **Step 1: Negate opening balance on update for liabilities**

In `budget/lib/Controller/AccountController.php`, find where `openingBalance` is handled in the `update()` method. Look for:

```php
            if (isset($data['openingBalance']) && $data['openingBalance'] !== '') {
                $updates['openingBalance'] = (float) $data['openingBalance'];
```

After this line, add:

```php
                // Liability accounts store opening balance as negative
                if ($updates['openingBalance'] > 0 && AccountType::from($account->getType())->isLiability()) {
                    $updates['openingBalance'] = -$updates['openingBalance'];
                }
```

Note: You need access to `$account` before this point — check if the controller fetches the account earlier in the update method. If it does (e.g., `$account = $this->service->find($id, ...)`), use that. If not, fetch it.

**Important:** `AccountService::recalculateAllBalances()` and `AccountService::update()` do NOT need sign inversion. With negative opening balances stored correctly, the formula `openingBalance + transactionNet` is universally correct:
- Asset: `5000 + (-200) = 4800` (spent $200)
- Liability: `-10000 + 500 = -9500` (paid $500)

The SQL `getNetChangeAll()` returns `credit=+, debit=-` which is correct for BOTH account types when the opening balance has the right sign.

- [ ] **Step 2: Verify PHP lint**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && php -l lib/Controller/AccountController.php"`

Expected: No syntax errors.

- [ ] **Step 3: Commit**

```bash
git add budget/lib/Controller/AccountController.php
git commit -m "feat: Negate opening balance for liabilities on account update"
```

---

### Task 5: NetWorthService — Remove Double-Negation

**Files:**
- Modify: `budget/lib/Service/NetWorthService.php`

- [ ] **Step 1: Fix liability balance aggregation**

In `budget/lib/Service/NetWorthService.php`, find lines 100-106:

```php
            if ($this->isLiabilityType($type)) {
                // Liabilities: negate balance (negative = owed, positive = credit/overpayment)
                // so that credits offset debt in the total
                $totalLiabilities = MoneyCalculator::subtract(
                    $totalLiabilities,
                    $balance
                );
            } else {
```

Replace with:

```php
            if ($this->isLiabilityType($type)) {
                // Liability balances are already negative; add directly
                // (adding a negative value naturally decreases the total)
                $totalLiabilities = MoneyCalculator::add($totalLiabilities, $balance);
            } else {
```

- [ ] **Step 2: Verify PHP lint**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && php -l lib/Service/NetWorthService.php"`

Expected: No syntax errors.

- [ ] **Step 3: Commit**

```bash
git add budget/lib/Service/NetWorthService.php
git commit -m "fix: Remove liability double-negation in net worth calculation"
```

---

### Task 6: ReportAggregator — Remove Double-Negation

**Files:**
- Modify: `budget/lib/Service/Report/ReportAggregator.php`

- [ ] **Step 1: Fix liability balance aggregation**

In `budget/lib/Service/Report/ReportAggregator.php`, find lines 169-170:

```php
            if (in_array($account->getType(), $liabilityTypes, true)) {
                $totalLiabilities -= $currentBalance; // negative = owed, positive = credit; negate so credits offset debt
```

Replace with:

```php
            if (in_array($account->getType(), $liabilityTypes, true)) {
                $totalLiabilities += $currentBalance; // Liability balances are already negative
```

- [ ] **Step 2: Verify PHP lint**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && php -l lib/Service/Report/ReportAggregator.php"`

Expected: No syntax errors.

- [ ] **Step 3: Commit**

```bash
git add budget/lib/Service/Report/ReportAggregator.php
git commit -m "fix: Remove liability double-negation in report aggregator"
```

---

### Task 7: Database Migration — Negate Existing Liability Balances

**Files:**
- Create: `budget/lib/Migration/Version001000061Date20260516.php`

- [ ] **Step 1: Create the migration**

Create `budget/lib/Migration/Version001000061Date20260516.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Negate balance and opening_balance for liability accounts.
 *
 * Previously, liability accounts (credit_card, loan, mortgage, line_of_credit)
 * stored positive balances representing "amount owed". The new model stores them
 * as negative values so the universal formula (credit=add, debit=subtract) works
 * correctly without sign-flipping logic.
 */
class Version001000061Date20260516 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $liabilityTypes = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];

        foreach ($liabilityTypes as $type) {
            // Negate balance where positive
            $this->db->executeStatement(
                'UPDATE `*PREFIX*budget_accounts` SET `balance` = -`balance` WHERE `type` = ? AND `balance` > 0',
                [$type]
            );

            // Negate opening_balance where positive
            $this->db->executeStatement(
                'UPDATE `*PREFIX*budget_accounts` SET `opening_balance` = -`opening_balance` WHERE `type` = ? AND `opening_balance` > 0',
                [$type]
            );
        }

        $output->info('Negated liability account balances for new storage model');
    }
}
```

- [ ] **Step 2: Verify PHP lint**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && php -l lib/Migration/Version001000061Date20260516.php"`

Expected: No syntax errors.

- [ ] **Step 3: Run the migration**

Run: `docker exec nc bash -c "php /var/www/html/occ migrations:migrate budget"`

Expected: Migration completes without error.

- [ ] **Step 4: Commit**

```bash
git add budget/lib/Migration/Version001000061Date20260516.php
git commit -m "feat: Add migration to negate existing liability account balances"
```

---

### Task 8: MigrationService — Backward-Compatible Import

**Files:**
- Modify: `budget/lib/Service/MigrationService.php`

- [ ] **Step 1: Bump export version**

In `budget/lib/Service/MigrationService.php`, change line 25:

```php
    private const EXPORT_VERSION = '1.0.0';
```

To:

```php
    private const EXPORT_VERSION = '1.1.0';
```

- [ ] **Step 2: Add liability balance conversion on import**

In the `importAccounts()` method (around line 474), after the line:

```php
            $account->setBalance($accData['balance'] ?? 0.0);
```

Add:

```php
            // Legacy exports (pre-1.1.0) stored liability balances as positive
            if (in_array($accData['type'], ['credit_card', 'loan', 'mortgage', 'line_of_credit'], true)) {
                $bal = (float) ($accData['balance'] ?? 0);
                if ($bal > 0) {
                    $account->setBalance(-$bal);
                }
            }
```

And similarly after `setOpeningBalance` if that field is set during import. Check if it's there — looking at the import code, `opening_balance` is not separately imported (it's derived from `balance` at creation). So only the `balance` fix is needed.

Actually, looking at the import code more carefully, it sets `balance` directly without going through `AccountController::create()`. So the fix must be in the import itself.

- [ ] **Step 3: Verify PHP lint**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && php -l lib/Service/MigrationService.php"`

Expected: No syntax errors.

- [ ] **Step 4: Commit**

```bash
git add budget/lib/Service/MigrationService.php
git commit -m "feat: Handle legacy positive liability balances in data import"
```

---

### Task 9: Frontend — Add Account Types and Update Liability Checks

**Files:**
- Modify: `budget/templates/index.php`
- Modify: `budget/src/modules/accounts/AccountsModule.js`

- [ ] **Step 1: Add account types to template dropdown**

In `budget/templates/index.php`, find the account type select (around line 4848-4857). After the `cryptocurrency` option and before `</select>`, add:

```php
                        <option value="mortgage"><?php p($l->t('Mortgage')); ?></option>
                        <option value="line_of_credit"><?php p($l->t('Line of Credit')); ?></option>
```

- [ ] **Step 2: Update frontend liability checks**

In `budget/src/modules/accounts/AccountsModule.js`:

Find line 166:
```js
        const liabilityTypes = ['credit_card', 'loan'];
```
Replace with:
```js
        const liabilityTypes = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];
```

Find line 276:
```js
        const isLiability = ['credit_card', 'loan'].includes(accountType);
```
Replace with:
```js
        const isLiability = ['credit_card', 'loan', 'mortgage', 'line_of_credit'].includes(accountType);
```

Find line 2472:
```js
        if (balance < 0 && type !== 'credit_card' && type !== 'loan') {
```
Replace with:
```js
        if (balance < 0 && !['credit_card', 'loan', 'mortgage', 'line_of_credit'].includes(type)) {
```

- [ ] **Step 3: Build frontend**

Run: `cd budget && npm run build`

Expected: Build succeeds.

- [ ] **Step 4: Commit**

```bash
git add budget/templates/index.php budget/src/modules/accounts/AccountsModule.js budget/js/ budget/css/
git commit -m "feat: Add mortgage and line_of_credit types to frontend"
```

---

### Task 10: Run Full Test Suite and Fix Any Failures

**Files:** Various test files may need updates

- [ ] **Step 1: Run full test suite**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tail -20"`

Expected: May have failures in tests that assert positive balances for liability accounts, or tests that verify the old negation logic in NetWorthService/ReportAggregator.

- [ ] **Step 2: Fix any test failures**

Common fixes needed:
- Tests that mock liability account balances should use negative values
- Tests for NetWorthService that expect `subtract` behavior should expect `add`
- Tests for ReportAggregator that expect `-=` should expect `+=`
- Tests for AccountController that pass positive balances for loans should expect them stored as negative

- [ ] **Step 3: Run full suite again**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tail -5"`

Expected: All tests pass (2717+).

- [ ] **Step 4: Commit test fixes**

```bash
git add budget/tests/
git commit -m "test: Update tests for negative liability balance model"
```

---

### Task 11: Final Verification and Push

- [ ] **Step 1: PHP lint all files**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && find lib/ appinfo/ -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'"`

Expected: No output (no errors).

- [ ] **Step 2: Run full test suite**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tail -5"`

Expected: All pass.

- [ ] **Step 3: Build frontend**

Run: `cd budget && npm run build`

Expected: Build succeeds.

- [ ] **Step 4: Push**

```bash
git push origin master
```

---

## Summary of Changes

1. **AccountType enum** — Added `MORTGAGE`, `LINE_OF_CREDIT`; updated `isLiability()`, `supportsInterest()`, `label()`
2. **MoneyCalculator** — Added `negate()` helper
3. **AccountController** — Negates positive balance to negative when creating liability accounts
4. **AccountController** — Negates opening balance for liabilities on update (same as creation). AccountService recalculation needs NO changes — the formula `openingBalance + netChange` is correct when opening balance has the right sign.
5. **NetWorthService** — Removed subtraction (now adds negative values directly)
6. **ReportAggregator** — Same fix as NetWorthService
7. **Database migration** — Negates existing positive liability balances
8. **MigrationService** — Converts legacy exports with positive liability balances on import
9. **Frontend** — Added mortgage/line_of_credit to dropdowns and liability checks
