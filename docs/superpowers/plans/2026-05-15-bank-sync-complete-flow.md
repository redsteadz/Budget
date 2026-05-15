# Bank Sync Complete Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix GoCardless bank sync by implementing the missing institution selection wizard, re-authorization flow, refresh accounts button, and sync-all functionality.

**Architecture:** The backend already has all needed endpoints except one (reauthorize). The work is primarily frontend — converting the single-step connect modal into a multi-step wizard for GoCardless, and adding missing UI controls. One new backend endpoint + route for re-authorization.

**Tech Stack:** PHP 8.1+ (Nextcloud App Framework), JavaScript ES6+ modules, Webpack build

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `budget/appinfo/routes.php` | Modify (line ~343) | Add reauthorize route |
| `budget/lib/Controller/BankSyncController.php` | Modify | Add `reauthorize()` method |
| `budget/lib/Service/BankSync/BankSyncService.php` | Modify | Add `reauthorize()` method |
| `budget/src/modules/bank-sync/BankSyncModule.js` | Rewrite | Multi-step wizard, re-auth, refresh, sync-all |
| `budget/templates/index.php` | Modify (~line 4272-4297) | Add sync-all button, refresh button container; replace modal HTML with wizard steps |
| `budget/tests/Unit/Service/BankSync/BankSyncServiceTest.php` | Modify | Add reauthorize tests |
| `budget/tests/Unit/Controller/BankSyncControllerTest.php` | Modify | Add reauthorize tests |

---

### Task 1: Backend — Add Reauthorize Endpoint

**Files:**
- Modify: `budget/appinfo/routes.php:343`
- Modify: `budget/lib/Service/BankSync/BankSyncService.php:366`
- Modify: `budget/lib/Controller/BankSyncController.php:254`

- [ ] **Step 1: Add the route**

In `budget/appinfo/routes.php`, add after the `refreshAccounts` route (line 343):

```php
['name' => 'bankSync#reauthorize', 'url' => '/api/bank-sync/connections/{id}/reauthorize', 'verb' => 'POST'],
```

- [ ] **Step 2: Add `reauthorize()` to BankSyncService**

In `budget/lib/Service/BankSync/BankSyncService.php`, add before the `requireEnabled()` method (before line 361):

```php
/**
 * Re-authorize an expired GoCardless connection with a new requisition.
 *
 * @return array{authorizationUrl: string}
 */
public function reauthorize(string $userId, int $connectionId, string $institutionId, string $redirectUrl): array {
    $this->requireEnabled();

    $connection = $this->connectionMapper->find($connectionId, $userId);

    if ($connection->getProvider() !== 'gocardless') {
        throw new \Exception('Re-authorization is only supported for GoCardless connections');
    }

    $provider = $this->providerFactory->getProvider('gocardless');
    $creds = json_decode($connection->getCredentials(), true);

    if (!$creds || !isset($creds['secretId'], $creds['secretKey'])) {
        throw new \Exception('Stored credentials are incomplete');
    }

    // Get a fresh access token using stored API keys
    $tokenData = $provider->getToken($creds['secretId'], $creds['secretKey']);

    // Re-initialize with institution to create a new requisition
    $result = $provider->initializeConnection([
        'secretId' => $creds['secretId'],
        'secretKey' => $creds['secretKey'],
        'institutionId' => $institutionId,
        'redirectUrl' => $redirectUrl,
    ]);

    // Update connection with new credentials (new requisitionId, fresh token)
    $connection->setCredentials($result['credentials']);
    $connection->setStatus('active');
    $connection->setLastError(null);
    $connection->setUpdatedAt(date('Y-m-d H:i:s'));
    $this->connectionMapper->update($connection);

    $this->auditService->log($userId, 'bank_reauthorized', 'bank_connection', $connectionId, [
        'provider' => 'gocardless',
    ]);

    return [
        'authorizationUrl' => $result['authorizationUrl'] ?? null,
    ];
}
```

- [ ] **Step 3: Add `reauthorize()` to BankSyncController**

In `budget/lib/Controller/BankSyncController.php`, add before the `getGoCardlessToken` method (before line 251):

```php
/**
 * Re-authorize an expired GoCardless connection.
 * @NoAdminRequired
 */
#[UserRateLimit(limit: 5, period: 60)]
public function reauthorize(int $id): DataResponse {
    if ($r = $this->requireBankSync()) return $r;

    try {
        $params = $this->request->getParams();
        $institutionId = $params['institutionId'] ?? null;
        $redirectUrl = $params['redirectUrl'] ?? null;

        if (!$institutionId) {
            return new DataResponse(['error' => $this->l->t('Institution ID is required')], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->syncService->reauthorize($this->userId, $id, $institutionId, $redirectUrl ?? '');
        return new DataResponse($result);
    } catch (\Exception $e) {
        return $this->handleError($e, $this->l->t('Failed to re-authorize'), Http::STATUS_BAD_REQUEST, ['connectionId' => $id]);
    }
}
```

- [ ] **Step 4: Verify PHP lint passes**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && php -l appinfo/routes.php && php -l lib/Controller/BankSyncController.php && php -l lib/Service/BankSync/BankSyncService.php"`

Expected: `No syntax error detected` for all three files.

- [ ] **Step 5: Commit**

```bash
git add budget/appinfo/routes.php budget/lib/Controller/BankSyncController.php budget/lib/Service/BankSync/BankSyncService.php
git commit -m "feat: Add reauthorize endpoint for expired GoCardless connections"
```

---

### Task 2: Backend Tests — Reauthorize

**Files:**
- Modify: `budget/tests/Unit/Service/BankSync/BankSyncServiceTest.php`
- Modify: `budget/tests/Unit/Controller/BankSyncControllerTest.php`

- [ ] **Step 1: Add service test for successful reauthorize**

Append to `budget/tests/Unit/Service/BankSync/BankSyncServiceTest.php` before the closing `}`:

```php
// ===== reauthorize =====

public function testReauthorizeCreatesNewRequisitionAndUpdatesConnection(): void {
    $this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

    $connection = new BankConnection();
    $connection->setId(1);
    $connection->setUserId(self::USER_ID);
    $connection->setProvider('gocardless');
    $connection->setCredentials(json_encode([
        'secretId' => 'sid',
        'secretKey' => 'skey',
        'accessToken' => 'old-token',
        'requisitionId' => 'old-req',
    ]));
    $connection->setStatus('expired');

    $this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);
    $this->providerFactory->method('getProvider')->with('gocardless')->willReturn($this->provider);

    $this->provider->method('getToken')->willReturn('new-token');
    $this->provider->method('initializeConnection')->willReturn([
        'credentials' => json_encode(['secretId' => 'sid', 'secretKey' => 'skey', 'requisitionId' => 'new-req']),
        'accounts' => [],
        'authorizationUrl' => 'https://bank.example.com/auth',
    ]);

    $this->connectionMapper->expects($this->once())->method('update')
        ->willReturnCallback(function (BankConnection $c) {
            $this->assertEquals('active', $c->getStatus());
            $this->assertNull($c->getLastError());
            $creds = json_decode($c->getCredentials(), true);
            $this->assertEquals('new-req', $creds['requisitionId']);
            return $c;
        });

    $result = $this->service->reauthorize(self::USER_ID, 1, 'BANK_ID', 'https://app/callback');

    $this->assertEquals('https://bank.example.com/auth', $result['authorizationUrl']);
}

public function testReauthorizeRejectsNonGoCardlessProvider(): void {
    $this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

    $connection = new BankConnection();
    $connection->setId(1);
    $connection->setUserId(self::USER_ID);
    $connection->setProvider('simplefin');
    $connection->setCredentials('{}');

    $this->connectionMapper->method('find')->willReturn($connection);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('only supported for GoCardless');

    $this->service->reauthorize(self::USER_ID, 1, 'BANK_ID', '');
}

public function testReauthorizeThrowsWhenDisabled(): void {
    $this->adminSettings->method('isBankSyncEnabled')->willReturn(false);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('disabled');

    $this->service->reauthorize(self::USER_ID, 1, 'BANK_ID', '');
}
```

- [ ] **Step 2: Add controller test for reauthorize**

Append to `budget/tests/Unit/Controller/BankSyncControllerTest.php` before the closing `}`:

```php
// ── reauthorize ────────────────────────────────────────────────

public function testReauthorizeSuccess(): void {
    $this->enableBankSync();
    $this->request->method('getParams')->willReturn([
        'institutionId' => 'BANK_ID',
        'redirectUrl' => 'https://app/callback',
    ]);

    $this->syncService->method('reauthorize')->willReturn([
        'authorizationUrl' => 'https://bank.example.com/auth',
    ]);

    $response = $this->controller->reauthorize(1);
    $this->assertSame(Http::STATUS_OK, $response->getStatus());
    $this->assertEquals('https://bank.example.com/auth', $response->getData()['authorizationUrl']);
}

public function testReauthorizeMissingInstitutionId(): void {
    $this->enableBankSync();
    $this->request->method('getParams')->willReturn([]);

    $response = $this->controller->reauthorize(1);
    $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
}

public function testReauthorizeDisabledReturns403(): void {
    $this->disableBankSync();

    $response = $this->controller->reauthorize(1);
    $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
}
```

- [ ] **Step 3: Run tests**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml --filter 'BankSync' 2>&1"`

Expected: All bank sync tests pass (including the new ones).

- [ ] **Step 4: Commit**

```bash
git add budget/tests/Unit/Service/BankSync/BankSyncServiceTest.php budget/tests/Unit/Controller/BankSyncControllerTest.php
git commit -m "test: Add reauthorize endpoint tests"
```

---

### Task 3: Frontend — Replace Connect Modal with Multi-Step Wizard

This is the largest task. It replaces the existing modal HTML and rewrites the connect flow in BankSyncModule.js.

**Files:**
- Modify: `budget/templates/index.php:6060-6120` (replace modal content)
- Modify: `budget/src/modules/bank-sync/BankSyncModule.js` (rewrite connect flow)

- [ ] **Step 1: Replace the modal HTML in index.php**

Replace the entire bank sync modal in `budget/templates/index.php` (the `<div id="bank-sync-modal">` block, lines 6060-6120) with:

```php
<!-- Bank Sync Connection Modal -->
<div id="bank-sync-modal" class="modal" style="display: none;" role="dialog" aria-label="<?php p($l->t('Add Bank Connection')); ?>">
    <div class="modal-content" style="max-width: 600px;">
        <!-- Step 1: Provider & Credentials -->
        <div id="bank-sync-step-1" class="bank-sync-step">
            <h3><?php p($l->t('Add Bank Connection')); ?></h3>

            <div class="form-group">
                <label for="bank-sync-provider"><?php p($l->t('Provider')); ?></label>
                <select id="bank-sync-provider">
                    <option value=""><?php p($l->t('Select a provider...')); ?></option>
                    <option value="gocardless"><?php p($l->t('GoCardless (UK/Europe)')); ?></option>
                    <option value="simplefin"><?php p($l->t('SimpleFIN Bridge (US)')); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label for="bank-sync-name"><?php p($l->t('Connection Name')); ?></label>
                <input type="text" id="bank-sync-name" placeholder="<?php p($l->t('e.g. My Bank')); ?>" maxlength="255">
            </div>

            <!-- SimpleFIN fields -->
            <div id="simplefin-fields" style="display: none;">
                <div class="form-group">
                    <label for="bank-sync-setup-token"><?php p($l->t('Setup Token')); ?></label>
                    <input type="text" id="bank-sync-setup-token" placeholder="<?php p($l->t('Paste your SimpleFIN setup token')); ?>">
                    <small class="form-text"><?php p($l->t('Get a token from beta-bridge.simplefin.org')); ?></small>
                </div>
            </div>

            <!-- GoCardless fields -->
            <div id="gocardless-fields" style="display: none;">
                <div class="form-group">
                    <label for="bank-sync-secret-id"><?php p($l->t('Secret ID')); ?></label>
                    <input type="text" id="bank-sync-secret-id" placeholder="<?php p($l->t('Your GoCardless Secret ID')); ?>">
                </div>
                <div class="form-group">
                    <label for="bank-sync-secret-key"><?php p($l->t('Secret Key')); ?></label>
                    <input type="password" id="bank-sync-secret-key" placeholder="<?php p($l->t('Your GoCardless Secret Key')); ?>">
                </div>
                <small class="form-text"><?php p($l->t('Get API keys from bankaccountdata.gocardless.com')); ?></small>
            </div>

            <div id="bank-sync-step1-error" class="bank-sync-error" style="display: none;"></div>

            <div class="modal-buttons">
                <button type="button" class="primary" id="bank-sync-step1-next"><?php p($l->t('Next')); ?></button>
                <button type="button" class="secondary cancel-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </div>

        <!-- Step 2: Select Institution (GoCardless only) -->
        <div id="bank-sync-step-2" class="bank-sync-step" style="display: none;">
            <h3><?php p($l->t('Select Your Bank')); ?></h3>

            <div class="form-group">
                <label for="bank-sync-country"><?php p($l->t('Country')); ?></label>
                <select id="bank-sync-country"></select>
            </div>

            <div class="form-group">
                <input type="text" id="bank-sync-institution-search" placeholder="<?php p($l->t('Search banks...')); ?>">
            </div>

            <div id="bank-sync-institutions-loading" class="bank-sync-loading" style="display: none;">
                <span class="icon-loading-small"></span> <?php p($l->t('Loading banks...')); ?>
            </div>

            <div id="bank-sync-institutions-grid" class="bank-sync-institutions-grid"></div>

            <div id="bank-sync-step2-error" class="bank-sync-error" style="display: none;"></div>

            <div class="modal-buttons">
                <button type="button" class="primary" id="bank-sync-step2-connect" disabled><?php p($l->t('Connect')); ?></button>
                <button type="button" class="secondary" id="bank-sync-step2-back"><?php p($l->t('Back')); ?></button>
            </div>
        </div>

        <!-- Step 3: Bank Authorization -->
        <div id="bank-sync-step-3" class="bank-sync-step" style="display: none;">
            <h3><?php p($l->t('Authorize with Your Bank')); ?></h3>

            <div class="bank-sync-auth-status">
                <p><?php p($l->t('A new window has been opened for you to authorize access at your bank.')); ?></p>
                <p><?php p($l->t('Once you have completed the authorization, click the button below.')); ?></p>
                <p id="bank-sync-auth-link-fallback" style="display: none;">
                    <?php p($l->t('If the window did not open, ')); ?>
                    <a id="bank-sync-auth-link" href="#" target="_blank" rel="noopener"><?php p($l->t('click here to authorize')); ?></a>.
                </p>
            </div>

            <div id="bank-sync-step3-error" class="bank-sync-error" style="display: none;"></div>
            <div id="bank-sync-step3-success" class="bank-sync-success" style="display: none;"></div>

            <div class="modal-buttons">
                <button type="button" class="primary" id="bank-sync-check-auth"><?php p($l->t('I\'ve Completed Authorization')); ?></button>
                <button type="button" class="secondary cancel-btn"><?php p($l->t('Close')); ?></button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Add Sync All button to the bank sync view header in index.php**

In `budget/templates/index.php`, find the div around line 4280 that contains the "Add Connection" button header and replace it:

Find:
```php
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3><?php p($l->t('Bank Connections')); ?></h3>
                        <button class="btn btn-primary" id="add-bank-connection-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Connection')); ?>
                        </button>
                    </div>
```

Replace with:
```php
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3><?php p($l->t('Bank Connections')); ?></h3>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-secondary" id="sync-all-connections-btn" style="display: none;" title="<?php p($l->t('Sync all active connections')); ?>">
                                <span class="icon-play" aria-hidden="true"></span>
                                <?php p($l->t('Sync All')); ?>
                            </button>
                            <button class="btn btn-primary" id="add-bank-connection-btn">
                                <span class="icon-add" aria-hidden="true"></span>
                                <?php p($l->t('Add Connection')); ?>
                            </button>
                        </div>
                    </div>
```

- [ ] **Step 3: Add refresh button container to the mappings section header in index.php**

In `budget/templates/index.php`, find the mappings section header (around line 4293-4294):

Find:
```php
                <div class="settings-section" id="bank-mappings-section" style="display: none;">
                    <h3 id="bank-mappings-title"><?php p($l->t('Account Mappings')); ?></h3>
```

Replace with:
```php
                <div class="settings-section" id="bank-mappings-section" style="display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 id="bank-mappings-title"><?php p($l->t('Account Mappings')); ?></h3>
                        <button class="btn btn-sm" id="refresh-accounts-btn" title="<?php p($l->t('Refresh account list from bank')); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z"/></svg>
                        </button>
                    </div>
```

- [ ] **Step 4: Commit template changes**

```bash
git add budget/templates/index.php
git commit -m "feat: Add multi-step wizard HTML, sync-all button, and refresh accounts button"
```

---

### Task 4: Frontend — Rewrite BankSyncModule.js

This task replaces the entire `BankSyncModule.js` with the complete new implementation including the wizard flow, re-auth, refresh, and sync-all.

**Files:**
- Rewrite: `budget/src/modules/bank-sync/BankSyncModule.js`

- [ ] **Step 1: Write the complete new BankSyncModule.js**

Replace the entire contents of `budget/src/modules/bank-sync/BankSyncModule.js` with the code below. This is a full rewrite — every method documented inline.

```javascript
import { translate as t } from '@nextcloud/l10n';
import { showSuccess, showError } from '../../utils/notifications.js';

/**
 * Bank Sync Module — manages bank connections, account mappings, and sync operations.
 * Only visible when the admin has enabled bank sync.
 *
 * GoCardless flow: 3-step wizard (credentials → bank selection → authorization).
 * SimpleFIN flow: single-step (credentials → connect → done).
 */
export default class BankSyncModule {
    constructor(app) {
        this.app = app;
        this.connections = [];
        this.selectedConnectionId = null;

        // Wizard state
        this._wizardStep = 1;
        this._wizardCredentials = null; // { secretId, secretKey } for GoCardless
        this._wizardConnectionId = null; // Set after connect POST in step 3
        this._wizardAuthUrl = null;
        this._selectedInstitutionId = null;
        this._institutions = [];
        this._reauthorizeConnectionId = null; // Non-null when re-authorizing

        // GoCardless supported countries
        this._countries = [
            { code: 'AT', name: 'Austria' }, { code: 'BE', name: 'Belgium' },
            { code: 'BG', name: 'Bulgaria' }, { code: 'HR', name: 'Croatia' },
            { code: 'CY', name: 'Cyprus' }, { code: 'CZ', name: 'Czech Republic' },
            { code: 'DK', name: 'Denmark' }, { code: 'EE', name: 'Estonia' },
            { code: 'FI', name: 'Finland' }, { code: 'FR', name: 'France' },
            { code: 'DE', name: 'Germany' }, { code: 'GR', name: 'Greece' },
            { code: 'HU', name: 'Hungary' }, { code: 'IS', name: 'Iceland' },
            { code: 'IE', name: 'Ireland' }, { code: 'IT', name: 'Italy' },
            { code: 'LV', name: 'Latvia' }, { code: 'LT', name: 'Lithuania' },
            { code: 'LU', name: 'Luxembourg' }, { code: 'MT', name: 'Malta' },
            { code: 'NL', name: 'Netherlands' }, { code: 'NO', name: 'Norway' },
            { code: 'PL', name: 'Poland' }, { code: 'PT', name: 'Portugal' },
            { code: 'RO', name: 'Romania' }, { code: 'SK', name: 'Slovakia' },
            { code: 'SI', name: 'Slovenia' }, { code: 'ES', name: 'Spain' },
            { code: 'SE', name: 'Sweden' }, { code: 'GB', name: 'United Kingdom' },
        ];
    }

    // ── Initialization ──────────────────────────────────────────

    async init() {
        await this.checkStatus();
        this.setupEventListeners();
    }

    async checkStatus() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/status'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            const data = await response.json();

            const navItem = document.getElementById('bank-sync-nav');
            if (navItem) {
                navItem.style.display = data.enabled ? '' : 'none';
            }

            return data;
        } catch (error) {
            console.error('Failed to check bank sync status:', error);
            return { enabled: false };
        }
    }

    setupEventListeners() {
        if (this._listenersSetup) return;
        this._listenersSetup = true;

        document.addEventListener('click', (e) => {
            if (e.target.closest('#add-bank-connection-btn')) {
                e.preventDefault();
                this.showConnectModal();
            } else if (e.target.closest('#bank-sync-step1-next')) {
                e.preventDefault();
                this.handleStep1Next();
            } else if (e.target.closest('#bank-sync-step2-connect')) {
                e.preventDefault();
                this.handleStep2Connect();
            } else if (e.target.closest('#bank-sync-step2-back')) {
                e.preventDefault();
                this.showWizardStep(1);
            } else if (e.target.closest('#bank-sync-check-auth')) {
                e.preventDefault();
                this.handleCheckAuth();
            } else if (e.target.closest('#sync-all-connections-btn')) {
                e.preventDefault();
                this.syncAll();
            } else if (e.target.closest('#refresh-accounts-btn')) {
                e.preventDefault();
                this.refreshAccounts();
            }
        });

        document.addEventListener('change', (e) => {
            if (e.target.id === 'bank-sync-provider') {
                const provider = e.target.value;
                document.getElementById('simplefin-fields').style.display = provider === 'simplefin' ? 'block' : 'none';
                document.getElementById('gocardless-fields').style.display = provider === 'gocardless' ? 'block' : 'none';
            } else if (e.target.id === 'bank-sync-country') {
                this.loadInstitutions(e.target.value);
            }
        });

        document.addEventListener('input', (e) => {
            if (e.target.id === 'bank-sync-institution-search') {
                this.filterInstitutions(e.target.value);
            }
        });
    }

    // ── View Loading ────────────────────────────────────────────

    async loadBankSyncView() {
        const status = await this.checkStatus();

        const disabledNotice = document.getElementById('bank-sync-disabled-notice');
        const content = document.getElementById('bank-sync-content');

        if (!status.enabled) {
            if (disabledNotice) disabledNotice.style.display = 'block';
            if (content) content.style.display = 'none';
            return;
        }

        if (disabledNotice) disabledNotice.style.display = 'none';
        if (content) content.style.display = 'block';

        this.setupEventListeners();
        await this.loadConnections();
    }

    // ── Connections ─────────────────────────────────────────────

    async loadConnections() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/connections'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to fetch connections');

            this.connections = await response.json();
            this.renderConnections();
        } catch (error) {
            console.error('Failed to load bank connections:', error);
        }
    }

    renderConnections() {
        const container = document.getElementById('bank-connections-list');
        if (!container) return;

        // Show/hide Sync All button
        const syncAllBtn = document.getElementById('sync-all-connections-btn');
        const activeCount = this.connections.filter(c => c.connection.status === 'active').length;
        if (syncAllBtn) {
            syncAllBtn.style.display = activeCount >= 2 ? '' : 'none';
        }

        if (!this.connections.length) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No bank connections yet. Click "Add Connection" to get started.')}</div>`;
            return;
        }

        container.innerHTML = this.connections.map(({ connection, mappings }) => {
            const statusClass = connection.status === 'active' ? 'positive' : (connection.status === 'error' ? 'negative' : '');
            const statusLabel = {
                active: t('budget', 'Active'),
                error: t('budget', 'Error'),
                expired: t('budget', 'Expired'),
            }[connection.status] || connection.status;

            const providerLabel = connection.provider === 'gocardless' ? 'GoCardless' : 'SimpleFIN';
            const lastSync = connection.lastSyncAt
                ? t('budget', 'Last sync: {date}', { date: new Date(connection.lastSyncAt).toLocaleString() })
                : t('budget', 'Never synced');
            const mappedCount = mappings.filter(m => m.budgetAccountId && m.enabled).length;

            const isExpired = connection.status === 'expired';
            const isGoCardless = connection.provider === 'gocardless';

            // Show re-authorize button for expired GoCardless, sync button otherwise
            const actionBtn = (isExpired && isGoCardless)
                ? `<button class="btn btn-sm btn-warning bank-reauth-btn" data-connection-id="${connection.id}" title="${t('budget', 'Re-authorize')}">${t('budget', 'Re-authorize')}</button>`
                : `<button class="btn btn-sm bank-sync-btn" data-connection-id="${connection.id}" title="${t('budget', 'Sync now')}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z"/></svg>
                </button>`;

            return `
                <div class="bank-connection-card" data-connection-id="${connection.id}">
                    <div class="bank-connection-header">
                        <div class="bank-connection-info">
                            <strong>${this.escapeHtml(connection.name)}</strong>
                            <span class="bank-connection-provider">${providerLabel}</span>
                            <span class="bank-connection-status ${statusClass}">${statusLabel}</span>
                        </div>
                        <div class="bank-connection-actions">
                            ${actionBtn}
                            <button class="btn btn-sm bank-mappings-btn" data-connection-id="${connection.id}" title="${t('budget', 'Account mappings')}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z"/></svg>
                            </button>
                            <button class="btn btn-sm btn-danger bank-disconnect-btn" data-connection-id="${connection.id}" title="${t('budget', 'Disconnect')}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="bank-connection-meta">
                        <span>${lastSync}</span>
                        <span>${t('budget', '{count} account(s) mapped', { count: mappedCount })}</span>
                        ${connection.lastError ? `<span class="bank-connection-error">${this.escapeHtml(connection.lastError)}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        // Event listeners
        container.querySelectorAll('.bank-sync-btn').forEach(btn => {
            btn.addEventListener('click', () => this.syncConnection(parseInt(btn.dataset.connectionId)));
        });
        container.querySelectorAll('.bank-reauth-btn').forEach(btn => {
            btn.addEventListener('click', () => this.startReauthorize(parseInt(btn.dataset.connectionId)));
        });
        container.querySelectorAll('.bank-mappings-btn').forEach(btn => {
            btn.addEventListener('click', () => this.showMappings(parseInt(btn.dataset.connectionId)));
        });
        container.querySelectorAll('.bank-disconnect-btn').forEach(btn => {
            btn.addEventListener('click', () => this.disconnect(parseInt(btn.dataset.connectionId)));
        });
    }

    // ── Wizard: Modal & Step Navigation ─────────────────────────

    showConnectModal() {
        this._reauthorizeConnectionId = null;
        this._resetWizardState();

        const modal = document.getElementById('bank-sync-modal');
        if (!modal) return;

        // Reset form fields
        document.getElementById('bank-sync-provider').value = '';
        document.getElementById('bank-sync-name').value = '';
        document.getElementById('bank-sync-setup-token').value = '';
        document.getElementById('bank-sync-secret-id').value = '';
        document.getElementById('bank-sync-secret-key').value = '';
        document.getElementById('simplefin-fields').style.display = 'none';
        document.getElementById('gocardless-fields').style.display = 'none';

        // Show step 1, enable provider/name fields
        document.getElementById('bank-sync-provider').disabled = false;
        document.getElementById('bank-sync-name').disabled = false;

        this.showWizardStep(1);
        modal.style.display = 'flex';
    }

    showWizardStep(step) {
        this._wizardStep = step;
        for (let i = 1; i <= 3; i++) {
            const el = document.getElementById(`bank-sync-step-${i}`);
            if (el) el.style.display = i === step ? 'block' : 'none';
        }
        // Clear errors on step change
        for (let i = 1; i <= 3; i++) {
            const err = document.getElementById(`bank-sync-step${i}-error`);
            if (err) { err.style.display = 'none'; err.textContent = ''; }
        }
        const success = document.getElementById('bank-sync-step3-success');
        if (success) { success.style.display = 'none'; success.textContent = ''; }
    }

    _resetWizardState() {
        this._wizardStep = 1;
        this._wizardCredentials = null;
        this._wizardConnectionId = null;
        this._wizardAuthUrl = null;
        this._selectedInstitutionId = null;
        this._institutions = [];
    }

    _showStepError(step, message) {
        const el = document.getElementById(`bank-sync-step${step}-error`);
        if (el) {
            el.textContent = message;
            el.style.display = 'block';
        }
    }

    // ── Wizard Step 1: Credentials ──────────────────────────────

    async handleStep1Next() {
        const provider = document.getElementById('bank-sync-provider').value;
        const name = document.getElementById('bank-sync-name').value.trim();

        if (!provider) {
            this._showStepError(1, t('budget', 'Please select a provider'));
            return;
        }
        if (!name) {
            this._showStepError(1, t('budget', 'Please enter a connection name'));
            return;
        }

        if (provider === 'simplefin') {
            // SimpleFIN: single-step, just connect directly
            await this._connectSimpleFIN(name);
            return;
        }

        // GoCardless: validate credentials by fetching institutions
        const secretId = document.getElementById('bank-sync-secret-id').value.trim();
        const secretKey = document.getElementById('bank-sync-secret-key').value.trim();

        if (!secretId || !secretKey) {
            this._showStepError(1, t('budget', 'Please enter your API credentials'));
            return;
        }

        // Store credentials for later steps
        this._wizardCredentials = { secretId, secretKey, name, provider };

        // Validate by loading institutions — if this fails, credentials are bad
        const btn = document.getElementById('bank-sync-step1-next');
        btn.disabled = true;
        btn.textContent = t('budget', 'Validating...');

        try {
            this._populateCountryDropdown();
            const country = document.getElementById('bank-sync-country').value;
            await this.loadInstitutions(country);
            this.showWizardStep(2);
        } catch (error) {
            this._showStepError(1, t('budget', 'Invalid credentials: {error}', { error: error.message }));
        } finally {
            btn.disabled = false;
            btn.textContent = t('budget', 'Next');
        }
    }

    async _connectSimpleFIN(name) {
        const setupToken = document.getElementById('bank-sync-setup-token').value.trim();
        if (!setupToken) {
            this._showStepError(1, t('budget', 'Please enter a setup token'));
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/connections'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ provider: 'simplefin', name, setupToken })
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            document.getElementById('bank-sync-modal').style.display = 'none';
            showSuccess(t('budget', 'Bank connected successfully'));
            await this.loadConnections();
        } catch (error) {
            this._showStepError(1, t('budget', 'Failed to connect: {error}', { error: error.message }));
        }
    }

    // ── Wizard Step 2: Institution Selection ────────────────────

    _populateCountryDropdown() {
        const select = document.getElementById('bank-sync-country');
        if (!select || select.options.length > 1) return; // Already populated

        select.innerHTML = '';

        // Try to detect default country from Nextcloud locale
        const lang = OC.getLanguage ? OC.getLanguage() : 'en-gb';
        const localeParts = lang.split(/[-_]/);
        const detectedCountry = (localeParts[1] || 'gb').toUpperCase();

        this._countries.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            if (c.code === detectedCountry) opt.selected = true;
            select.appendChild(opt);
        });

        // Default to GB if detected country not in list
        if (!select.value) {
            select.value = 'GB';
        }
    }

    async loadInstitutions(country) {
        const grid = document.getElementById('bank-sync-institutions-grid');
        const loading = document.getElementById('bank-sync-institutions-loading');
        const searchInput = document.getElementById('bank-sync-institution-search');

        if (loading) loading.style.display = 'block';
        if (grid) grid.innerHTML = '';
        if (searchInput) searchInput.value = '';
        this._selectedInstitutionId = null;
        this._updateConnectButton();

        const creds = this._wizardCredentials || {};

        try {
            const url = OC.generateUrl(`/apps/budget/api/bank-sync/providers/gocardless/institutions?country=${encodeURIComponent(country)}&secretId=${encodeURIComponent(creds.secretId || '')}&secretKey=${encodeURIComponent(creds.secretKey || '')}`);
            const response = await fetch(url, {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            this._institutions = await response.json();
            this.renderInstitutions(this._institutions);
        } catch (error) {
            this._showStepError(2, t('budget', 'Failed to load banks: {error}', { error: error.message }));
            throw error;
        } finally {
            if (loading) loading.style.display = 'none';
        }
    }

    renderInstitutions(institutions) {
        const grid = document.getElementById('bank-sync-institutions-grid');
        if (!grid) return;

        if (!institutions.length) {
            grid.innerHTML = `<div class="empty-state-small">${t('budget', 'No banks found for this country.')}</div>`;
            return;
        }

        grid.innerHTML = institutions.map(inst => `
            <div class="bank-institution-tile" data-institution-id="${this.escapeHtml(inst.id)}" tabindex="0" role="button">
                ${inst.logo ? `<img src="${this.escapeHtml(inst.logo)}" alt="" class="bank-institution-logo" loading="lazy">` : '<div class="bank-institution-logo-placeholder"></div>'}
                <span class="bank-institution-name">${this.escapeHtml(inst.name)}</span>
            </div>
        `).join('');

        grid.querySelectorAll('.bank-institution-tile').forEach(tile => {
            tile.addEventListener('click', () => this._selectInstitution(tile));
            tile.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this._selectInstitution(tile);
                }
            });
        });
    }

    _selectInstitution(tile) {
        // Deselect previous
        const grid = document.getElementById('bank-sync-institutions-grid');
        grid.querySelectorAll('.bank-institution-tile.selected').forEach(t => t.classList.remove('selected'));

        tile.classList.add('selected');
        this._selectedInstitutionId = tile.dataset.institutionId;
        this._updateConnectButton();
    }

    _updateConnectButton() {
        const btn = document.getElementById('bank-sync-step2-connect');
        if (btn) btn.disabled = !this._selectedInstitutionId;
    }

    filterInstitutions(query) {
        const lower = query.toLowerCase();
        const filtered = lower
            ? this._institutions.filter(inst => inst.name.toLowerCase().includes(lower))
            : this._institutions;
        this.renderInstitutions(filtered);
    }

    // ── Wizard Step 2 → 3: Connect & Open Auth ──────────────────

    async handleStep2Connect() {
        if (!this._selectedInstitutionId) return;

        const btn = document.getElementById('bank-sync-step2-connect');
        btn.disabled = true;
        btn.textContent = t('budget', 'Connecting...');

        const redirectUrl = window.location.href.split('#')[0];

        try {
            let result;

            if (this._reauthorizeConnectionId) {
                // Re-authorization flow
                const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${this._reauthorizeConnectionId}/reauthorize`), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({
                        institutionId: this._selectedInstitutionId,
                        redirectUrl,
                    })
                });

                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    throw new Error(error.error || `HTTP ${response.status}`);
                }

                result = await response.json();
                this._wizardConnectionId = this._reauthorizeConnectionId;
            } else {
                // New connection flow
                const creds = this._wizardCredentials;
                const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/connections'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({
                        provider: 'gocardless',
                        name: creds.name,
                        secretId: creds.secretId,
                        secretKey: creds.secretKey,
                        institutionId: this._selectedInstitutionId,
                        redirectUrl,
                    })
                });

                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    throw new Error(error.error || `HTTP ${response.status}`);
                }

                result = await response.json();
                this._wizardConnectionId = result.connection?.id;
            }

            // Open authorization URL
            this._wizardAuthUrl = result.authorizationUrl;
            if (this._wizardAuthUrl) {
                const authWindow = window.open(this._wizardAuthUrl, '_blank');
                if (!authWindow) {
                    // Popup blocked — show fallback link
                    const fallback = document.getElementById('bank-sync-auth-link-fallback');
                    const link = document.getElementById('bank-sync-auth-link');
                    if (fallback) fallback.style.display = 'block';
                    if (link) link.href = this._wizardAuthUrl;
                }
            }

            this.showWizardStep(3);
        } catch (error) {
            this._showStepError(2, t('budget', 'Failed to connect: {error}', { error: error.message }));
        } finally {
            btn.disabled = false;
            btn.textContent = t('budget', 'Connect');
        }
    }

    // ── Wizard Step 3: Check Authorization ──────────────────────

    async handleCheckAuth() {
        if (!this._wizardConnectionId) return;

        const btn = document.getElementById('bank-sync-check-auth');
        btn.disabled = true;
        btn.textContent = t('budget', 'Checking...');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${this._wizardConnectionId}/refresh`), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            const mappings = await response.json();

            if (Array.isArray(mappings) && mappings.length > 0) {
                // Authorization complete — accounts found
                const successEl = document.getElementById('bank-sync-step3-success');
                if (successEl) {
                    successEl.textContent = t('budget', 'Authorization successful! {count} account(s) found. You can now close this dialog and set up your account mappings.', { count: mappings.length });
                    successEl.style.display = 'block';
                }
                btn.textContent = t('budget', 'Done');
                btn.onclick = () => {
                    document.getElementById('bank-sync-modal').style.display = 'none';
                    this.loadConnections();
                    btn.onclick = null;
                };
                btn.disabled = false;
            } else {
                this._showStepError(3, t('budget', 'Authorization not yet complete. Please finish the authorization in the bank window and try again.'));
            }
        } catch (error) {
            this._showStepError(3, t('budget', 'Authorization check failed: {error}', { error: error.message }));
        } finally {
            if (btn.textContent !== t('budget', 'Done')) {
                btn.disabled = false;
                btn.textContent = t('budget', 'I\'ve Completed Authorization');
            }
        }
    }

    // ── Re-Authorization ────────────────────────────────────────

    startReauthorize(connectionId) {
        const conn = this.connections.find(c => c.connection.id === connectionId);
        if (!conn) return;

        this._reauthorizeConnectionId = connectionId;
        this._resetWizardState();

        // We need credentials for the institution lookup — get them from the
        // reauthorize endpoint which uses stored credentials internally.
        // But for the institutions list, we need secretId/secretKey sent as params.
        // The stored credentials are encrypted server-side and not exposed.
        // So we ask the user to re-enter their API keys for institution lookup.

        const modal = document.getElementById('bank-sync-modal');
        if (!modal) return;

        // Show step 1 but with provider pre-selected and name pre-filled
        document.getElementById('bank-sync-provider').value = 'gocardless';
        document.getElementById('bank-sync-provider').disabled = true;
        document.getElementById('bank-sync-name').value = conn.connection.name;
        document.getElementById('bank-sync-name').disabled = true;
        document.getElementById('gocardless-fields').style.display = 'block';
        document.getElementById('simplefin-fields').style.display = 'none';
        document.getElementById('bank-sync-secret-id').value = '';
        document.getElementById('bank-sync-secret-key').value = '';

        this.showWizardStep(1);
        modal.style.display = 'flex';
    }

    // ── Sync ────────────────────────────────────────────────────

    async syncConnection(connectionId) {
        try {
            showSuccess(t('budget', 'Syncing...'));

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}/sync`), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.message) {
                showError(result.message);
                this.showMappings(connectionId);
            } else {
                showSuccess(t('budget', 'Sync complete: {imported} imported, {skipped} skipped', {
                    imported: result.imported,
                    skipped: result.skipped
                }));
            }

            await this.loadConnections();
        } catch (error) {
            console.error('Failed to sync:', error);
            showError(t('budget', 'Sync failed: {error}', { error: error.message }));
            await this.loadConnections();
        }
    }

    // ── Sync All ────────────────────────────────────────────────

    async syncAll() {
        const activeConnections = this.connections.filter(c => c.connection.status === 'active');
        if (!activeConnections.length) return;

        const btn = document.getElementById('sync-all-connections-btn');
        if (btn) btn.disabled = true;

        let totalImported = 0;
        let totalSkipped = 0;
        let synced = 0;
        let errors = 0;

        for (let i = 0; i < activeConnections.length; i++) {
            const conn = activeConnections[i].connection;
            showSuccess(t('budget', 'Syncing {current} of {total}...', { current: i + 1, total: activeConnections.length }));

            try {
                const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${conn.id}/sync`), {
                    method: 'POST',
                    headers: { 'requesttoken': OC.requestToken }
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const result = await response.json();
                totalImported += result.imported || 0;
                totalSkipped += result.skipped || 0;
                synced++;
            } catch (error) {
                console.error(`Failed to sync connection ${conn.id}:`, error);
                errors++;
            }
        }

        if (errors > 0) {
            showError(t('budget', 'Synced {synced} of {total} connections: {imported} imported, {skipped} skipped, {errors} failed', {
                synced, total: activeConnections.length, imported: totalImported, skipped: totalSkipped, errors
            }));
        } else {
            showSuccess(t('budget', 'Synced {synced} connections: {imported} imported, {skipped} skipped', {
                synced, imported: totalImported, skipped: totalSkipped
            }));
        }

        if (btn) btn.disabled = false;
        await this.loadConnections();
    }

    // ── Disconnect ──────────────────────────────────────────────

    async disconnect(connectionId) {
        if (!confirm(t('budget', 'Are you sure you want to disconnect this bank? This will remove the connection and all account mappings.'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to disconnect');

            showSuccess(t('budget', 'Bank disconnected'));
            document.getElementById('bank-mappings-section').style.display = 'none';
            await this.loadConnections();
        } catch (error) {
            showError(t('budget', 'Failed to disconnect bank'));
        }
    }

    // ── Account Mappings ────────────────────────────────────────

    async showMappings(connectionId) {
        this.selectedConnectionId = connectionId;
        const section = document.getElementById('bank-mappings-section');
        if (!section) return;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}/mappings`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to fetch mappings');

            const mappings = await response.json();
            section.style.display = 'block';

            const conn = this.connections.find(c => c.connection.id === connectionId);
            const title = document.getElementById('bank-mappings-title');
            if (title && conn) {
                title.textContent = t('budget', 'Account Mappings — {name}', { name: conn.connection.name });
            }

            this.renderMappings(mappings, connectionId);
        } catch (error) {
            showError(t('budget', 'Failed to load account mappings'));
        }
    }

    renderMappings(mappings, connectionId) {
        const container = document.getElementById('bank-mappings-list');
        if (!container) return;

        const accounts = this.app.accounts || [];
        const accountOptions = accounts.map(a =>
            `<option value="${a.id}">${this.escapeHtml(a.name)} (${a.currency})</option>`
        ).join('');

        container.innerHTML = mappings.map(mapping => {
            const enabled = mapping.enabled ? 'checked' : '';
            const balance = mapping.lastBalance ? `${mapping.lastCurrency || ''} ${mapping.lastBalance}` : '';

            return `
                <div class="bank-mapping-row" data-mapping-id="${mapping.id}">
                    <div class="bank-mapping-info">
                        <label class="bank-mapping-enable">
                            <input type="checkbox" class="mapping-enabled-checkbox"
                                   data-mapping-id="${mapping.id}" data-connection-id="${connectionId}" ${enabled}>
                        </label>
                        <div>
                            <strong>${this.escapeHtml(mapping.externalAccountName || mapping.externalAccountId)}</strong>
                            ${balance ? `<small>${balance}</small>` : ''}
                            ${mapping.consentExpires ? `<small class="consent-warning">${t('budget', 'Consent expires: {date}', { date: new Date(mapping.consentExpires).toLocaleDateString() })}</small>` : ''}
                        </div>
                    </div>
                    <div class="bank-mapping-target">
                        <select class="mapping-account-select" data-mapping-id="${mapping.id}" data-connection-id="${connectionId}">
                            <option value="">${t('budget', '— Not mapped —')}</option>
                            ${accountOptions}
                        </select>
                    </div>
                </div>
            `;
        }).join('');

        // Set selected values
        mappings.forEach(mapping => {
            if (mapping.budgetAccountId) {
                const select = container.querySelector(`.mapping-account-select[data-mapping-id="${mapping.id}"]`);
                if (select) select.value = mapping.budgetAccountId;
            }
        });

        // Event listeners
        container.querySelectorAll('.mapping-enabled-checkbox').forEach(cb => {
            cb.addEventListener('change', () => this.updateMapping(
                parseInt(cb.dataset.connectionId),
                parseInt(cb.dataset.mappingId),
                null,
                cb.checked
            ));
        });

        container.querySelectorAll('.mapping-account-select').forEach(sel => {
            sel.addEventListener('change', () => this.updateMapping(
                parseInt(sel.dataset.connectionId),
                parseInt(sel.dataset.mappingId),
                sel.value ? parseInt(sel.value) : null,
                null
            ));
        });
    }

    async updateMapping(connectionId, mappingId, budgetAccountId, enabled) {
        try {
            const body = {};
            if (budgetAccountId !== null) body.budgetAccountId = budgetAccountId;
            if (enabled !== null) body.enabled = enabled;

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}/mappings/${mappingId}`), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify(body)
            });

            if (!response.ok) throw new Error('Failed to update mapping');
            showSuccess(t('budget', 'Mapping updated'));
        } catch (error) {
            showError(t('budget', 'Failed to update mapping'));
        }
    }

    // ── Refresh Accounts ────────────────────────────────────────

    async refreshAccounts() {
        if (!this.selectedConnectionId) return;

        const btn = document.getElementById('refresh-accounts-btn');
        if (btn) btn.disabled = true;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${this.selectedConnectionId}/refresh`), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            const mappings = await response.json();
            this.renderMappings(mappings, this.selectedConnectionId);
            showSuccess(t('budget', 'Accounts refreshed'));
        } catch (error) {
            showError(t('budget', 'Failed to refresh accounts: {error}', { error: error.message }));
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // ── Utilities ───────────────────────────────────────────────

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
```

- [ ] **Step 2: Build and verify no JS errors**

Run: `cd budget && npm run dev`

Expected: Build succeeds without errors.

- [ ] **Step 3: Commit**

```bash
git add budget/src/modules/bank-sync/BankSyncModule.js
git commit -m "feat: Rewrite BankSyncModule with multi-step wizard, re-auth, refresh, and sync-all"
```

---

### Task 5: CSS — Institution Grid and Wizard Styles

**Files:**
- Modify: `budget/src/css/style.css` (or wherever bank-sync styles live)

- [ ] **Step 1: Find the existing bank sync CSS location**

Run: `grep -rn 'bank-institution\|bank-sync-step\|institutions-grid' budget/src/css/` to confirm no existing styles.
Then find where bank sync CSS lives: `grep -n 'bank-connection-card\|bank-mapping-row' budget/src/css/style.css`

- [ ] **Step 2: Add institution grid and wizard styles**

Append the following CSS after the existing bank sync styles in `budget/src/css/style.css`:

```css
/* Bank Sync Wizard Steps */
.bank-sync-step { }
.bank-sync-error {
    color: var(--color-error, #e9322d);
    background: var(--color-error-hover, #fdd);
    padding: 8px 12px;
    border-radius: 4px;
    margin-top: 8px;
    font-size: 13px;
}
.bank-sync-success {
    color: var(--color-success, #46ba61);
    background: var(--color-success-hover, #dfd);
    padding: 8px 12px;
    border-radius: 4px;
    margin-top: 8px;
    font-size: 13px;
}
.bank-sync-loading {
    padding: 16px;
    text-align: center;
    color: var(--color-text-maxcontrast);
}
.bank-sync-auth-status {
    padding: 16px 0;
}
.bank-sync-auth-status p {
    margin-bottom: 8px;
}

/* Institution Grid */
.bank-sync-institutions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px;
    max-height: 340px;
    overflow-y: auto;
    padding: 4px;
    margin-bottom: 12px;
}
.bank-institution-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px 8px;
    border: 2px solid var(--color-border, #ededed);
    border-radius: 8px;
    cursor: pointer;
    transition: border-color 0.15s, box-shadow 0.15s;
    text-align: center;
    min-height: 80px;
    justify-content: center;
}
.bank-institution-tile:hover {
    border-color: var(--color-primary-element, #0082c9);
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.bank-institution-tile.selected {
    border-color: var(--color-primary-element, #0082c9);
    background: var(--color-primary-element-light, #e6f3fb);
    box-shadow: 0 0 0 1px var(--color-primary-element, #0082c9);
}
.bank-institution-tile:focus-visible {
    outline: 2px solid var(--color-primary-element, #0082c9);
    outline-offset: 2px;
}
.bank-institution-logo {
    width: 40px;
    height: 40px;
    object-fit: contain;
    margin-bottom: 6px;
    border-radius: 4px;
}
.bank-institution-logo-placeholder {
    width: 40px;
    height: 40px;
    margin-bottom: 6px;
    background: var(--color-background-darker, #f0f0f0);
    border-radius: 4px;
}
.bank-institution-name {
    font-size: 12px;
    line-height: 1.3;
    word-break: break-word;
}

/* Re-authorize button */
.btn-warning {
    background: var(--color-warning, #e9a200) !important;
    color: #fff !important;
    border-color: var(--color-warning, #e9a200) !important;
}
.btn-warning:hover {
    opacity: 0.9;
}

#bank-sync-institution-search {
    width: 100%;
}
```

- [ ] **Step 3: Build**

Run: `cd budget && npm run dev`

Expected: Build succeeds.

- [ ] **Step 4: Commit**

```bash
git add budget/src/css/style.css
git commit -m "feat: Add CSS for institution grid, wizard steps, and re-authorize button"
```

---

### Task 6: Run Full Test Suite

**Files:** None (verification only)

- [ ] **Step 1: Run all PHP tests**

Run: `docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tail -5"`

Expected: `OK` with 2700+ tests passing, 0 errors, 0 failures.

- [ ] **Step 2: Run JS lint**

Run: `cd budget && npm run lint`

Expected: No errors (warnings acceptable).

- [ ] **Step 3: Final production build**

Run: `cd budget && npm run build`

Expected: Build succeeds.

- [ ] **Step 4: Commit built assets and push**

```bash
git add budget/js/ budget/css/
git commit -m "build: Compile frontend assets for bank sync features"
git push origin master
```

---

## Summary of Changes

1. **Backend:** 1 new route, 1 new controller method, 1 new service method for re-authorization
2. **Frontend:** Complete rewrite of BankSyncModule.js (420 → ~580 lines) with:
   - 3-step GoCardless wizard (credentials → bank selection → authorization)
   - Searchable institution grid with logos
   - Country selection with locale detection
   - Re-authorization flow for expired connections
   - Refresh accounts button in mappings view
   - Sync-all button for multiple connections
3. **HTML:** Replaced single-step modal with 3-step wizard, added sync-all and refresh buttons
4. **CSS:** Institution grid, wizard step styling, error/success states, warning button
5. **Tests:** 6 new tests covering the reauthorize endpoint
