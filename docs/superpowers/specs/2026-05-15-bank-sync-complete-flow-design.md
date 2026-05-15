# Bank Sync — Complete GoCardless Flow & Missing Features

**Date:** 2026-05-15
**Issue:** otherworld-dev/budget#184
**Status:** Approved

## Problem

GoCardless bank sync is broken for all users. The frontend connect flow skips institution (bank) selection entirely — it sends only `secretId` and `secretKey` without an `institutionId`. This means no GoCardless requisition is created, no bank authorization URL is returned, and every subsequent sync fails with "Bank authorization has expired. Please re-authorize."

Additionally, several backend capabilities have no frontend counterpart: re-authorization, account refresh, and institution listing.

## Solution Overview

| Feature | Frontend Change | Backend Change |
|---------|----------------|----------------|
| Multi-step GoCardless wizard | New 3-step flow in connect modal | None (endpoints exist) |
| Country + searchable institution picker | New UI with logo grid | None (endpoint exists) |
| Bank auth completion detection | New status check UI | None (refresh endpoint exists) |
| Re-authorization for expired connections | New flow + UI button | 1 new endpoint + route |
| Refresh accounts button | New button in mappings section | None (endpoint exists) |
| Sync all connections | New button + aggregated progress | None (sync endpoint exists) |

## 1. GoCardless Connect Flow — Multi-Step Wizard

The existing single-step connect modal becomes a 3-step wizard when GoCardless is selected. SimpleFIN remains single-step (unchanged).

### Step 1: Credentials

Existing fields: provider dropdown, connection name, Secret ID, Secret Key.

- "Next" button validates credentials by calling the institutions endpoint (proves API keys work before proceeding).
- On validation failure: inline error, stay on step.
- On success: transition to Step 2, store credentials in memory for the session.

### Step 2: Select Your Bank

- **Country dropdown** at top. Populated from a static list of GoCardless-supported countries (~30 entries, hardcoded since this changes rarely). Defaults to the user's Nextcloud locale country if detectable via `OC.getLanguage()` → country code extraction, else `GB`.
- **Search input** for real-time text filtering of the bank list.
- **Bank grid** showing tiles with logo image + bank name. Fetched from `GET /api/bank-sync/providers/{provider}/institutions?country={code}` (endpoint already exists).
- Selecting a tile highlights it and enables the "Connect" button.
- Loading spinner while fetching institutions.
- Grid re-fetched when country changes.

### Step 3: Bank Authorization

After clicking "Connect":
1. `POST /api/bank-sync/connections` with `provider`, `name`, `secretId`, `secretKey`, `institutionId`, `redirectUrl` (current page URL).
2. Backend creates connection + requisition, returns `{ connection, mappings, authorizationUrl }`.
3. Modal shows authorization status screen:
   - Message: "Please authorize access at your bank"
   - Opens `authorizationUrl` in new tab via `window.open()`
   - Fallback link if popup blocked
   - "I've completed authorization" / "Check Status" button
4. Check status calls `POST /api/bank-sync/connections/{id}/refresh`.
   - If accounts returned → connection is active → show success, close modal, reload connections.
   - If auth still pending → show "Authorization not complete yet. Please finish the process in the bank tab and try again."

### SimpleFIN Flow (unchanged)

Single step: setup token → connect → done. No wizard needed.

### Implementation Details

- Wizard steps are show/hide divs within the existing `#bank-sync-modal`.
- Step state tracked via a variable (`currentStep`). Back button available on steps 2-3.
- Connection ID stored after Step 3 POST for subsequent status checks.
- All new HTML rendered via JS template strings (consistent with existing module pattern).

## 2. Re-Authorization Flow

When a GoCardless connection has `status: 'expired'`:

- Connection card shows a **"Re-authorize"** button replacing the sync button.
- Clicking opens a slimmed-down wizard starting at Step 2 (bank selection) — credentials are already stored in the connection.
- Uses new endpoint: `POST /api/bank-sync/connections/{id}/reauthorize`
  - Accepts: `{ institutionId, redirectUrl }`
  - Uses stored (encrypted) credentials to get a fresh access token
  - Creates a new GoCardless requisition
  - Updates connection credentials with new `requisitionId`
  - Sets connection status back to `active`
  - Returns: `{ authorizationUrl }`
- Frontend opens bank auth URL, shows Step 3 "Check Status" flow.

### Backend: New Endpoint

**Route:** `POST /api/bank-sync/connections/{id}/reauthorize` → `bankSync#reauthorize`

**Controller method:** `BankSyncController::reauthorize(int $id): DataResponse`
- Rate limit: 5/min
- Validates connection ownership and provider is GoCardless
- Delegates to `BankSyncService::reauthorize(userId, connectionId, institutionId, redirectUrl)`

**Service method:** `BankSyncService::reauthorize(...)`
- Decrypts stored credentials (has secretId, secretKey)
- Gets fresh access token
- Creates new requisition for the given institution
- Updates credentials JSON with new requisitionId + token
- Sets status to 'active', clears lastError
- Returns `{ authorizationUrl }`

## 3. Refresh Accounts Button

- Refresh icon button in the mappings section header (next to "Account Mappings — {name}" title).
- Calls existing `POST /api/bank-sync/connections/{id}/refresh`.
- Shows loading state on button during request.
- Re-renders mappings list with any newly discovered accounts.
- Success toast: "Accounts refreshed" or "X new account(s) found".

## 4. Sync All

- "Sync All" button at top of connections view, alongside "Add Connection".
- Only visible when there are 2+ active connections.
- Syncs connections sequentially (avoids rate limit issues).
- Progress indicator: "Syncing 1 of 3..." updated after each completes.
- Aggregated result toast: "Synced 3 connections: 45 imported, 12 skipped"
- Skips expired/error connections with note in results.
- Reloads connection list once all complete.

## 5. Static Data

### GoCardless Supported Countries

Hardcoded array (source: GoCardless documentation). Approximately:

```
AT, BE, BG, HR, CY, CZ, DK, EE, FI, FR, DE, GR, HU, IS, IE, IT, LV, LT, LU, MT, NL, NO, PL, PT, RO, SK, SI, ES, SE, GB
```

Each entry: `{ code: 'GB', name: 'United Kingdom' }`. Country names use `t()` for translation.

## Files Changed

### Frontend (edit)
- `src/modules/bank-sync/BankSyncModule.js` — All frontend changes (wizard, re-auth, refresh, sync-all)

### Frontend (edit)
- `budget/templates/index.php` — Add new modal HTML elements for wizard steps, institution grid container

### Backend (edit)
- `lib/Controller/BankSyncController.php` — Add `reauthorize()` method
- `lib/Service/BankSync/BankSyncService.php` — Add `reauthorize()` method
- `appinfo/routes.php` — Add reauthorize route

### Styles (edit)
- `src/css/bank-sync.css` or inline in module — Institution grid, wizard step indicators, search input styling

### Tests (new/edit)
- `tests/Unit/Controller/BankSyncControllerTest.php` — Add reauthorize tests
- `tests/Unit/Service/BankSync/BankSyncServiceTest.php` — Add reauthorize tests

## Non-Goals

- No automatic polling for auth completion (user clicks "Check Status" manually to avoid rate limits)
- No institution caching (fetched fresh each time — list is small and GoCardless may update)
- No OAuth callback endpoint (GoCardless uses redirect-based auth, not server-side OAuth callbacks)
- No changes to SimpleFIN flow
- No sync history/log UI
- No transaction conflict resolution UI
