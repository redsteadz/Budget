# Nextcloud Integration

> Budget plugs into the Nextcloud platform itself: dashboard widgets on Nextcloud's own dashboard, transactions in the unified search bar, a bills calendar you can subscribe to from any device, and receipts attached straight from your Files.

## Dashboard Widgets

Two widgets are available on the **Nextcloud dashboard** (the landing page at *Dashboard* in the app menu — not to be confused with the app's own [dashboard](dashboard.md)):

| Widget | Shows |
|--------|-------|
| **Budget: Upcoming bills** | Your next five bills due within 60 days, with amount and due date ("due in 3 days", "due today", "overdue"). |
| **Budget: Overview** | Total balance across your accounts, this month's budget progress, and an attention line when categories are over budget or in warning. |

To enable them, open the Nextcloud dashboard and choose **Customize** — tick the Budget widgets. Each widget links into the relevant app view.

Amounts use your configured default currency from [Settings](settings.md).

## Unified Search

Transactions appear in Nextcloud's **unified search** (the magnifier in the top bar, or <kbd>Ctrl</kbd>+<kbd>F</kbd> on most setups). The search matches transaction descriptions, vendors, and notes — across your own accounts and accounts [shared with you](sharing.md).

Each result shows the amount and date, and clicking one opens the Transactions view with the search term pre-filled, so the same results are on screen with full filtering available.

Searches need at least two characters. Inside the Budget app the provider is boosted to the top of the search panel.

## Bills Calendar Feed

Subscribe to your bills as a read-only calendar — due dates appear in the Nextcloud Calendar app, on your phone, in Thunderbird, or any client that supports **ICS subscriptions**.

### Subscribing

1. Go to **Bills** and click **Calendar feed**.
2. Copy the subscription URL (or the `webcal://` variant, which most calendar apps open directly).
3. In Nextcloud Calendar: **+ New calendar > New subscription from link** and paste the URL.

The feed contains each active bill's upcoming due dates for the next 12 months — including custom recurrence patterns, semi-monthly schedules, and bills with an end date or a limited number of remaining payments. Bills with a reminder configured carry a matching calendar alarm. Clients refresh the feed periodically (the feed suggests every 12 hours).

### Security

The URL contains a long random token instead of your password — calendar apps can't log into Nextcloud, so this is the standard pattern for private feeds. Treat the URL like a password:

- Anyone who has the URL can read your bill names, amounts, and due dates (nothing else).
- **Regenerate** invalidates the old URL immediately; existing subscriptions must be updated with the new one.
- Failed token guesses are rate-limited and brute-force protected.

## Receipt Attachments

Attach receipts, invoices, or warranty documents to transactions. Files live in **your own Nextcloud Files** — the app only stores a reference, so they count toward your normal quota, are included in your Files backups, and remain yours.

### Attaching

Open a transaction for editing — the **Receipts** section offers two options:

| Action | What happens |
|--------|--------------|
| **Upload** | The file is stored in `Budget/Receipts/<year>/` in your Files (the year comes from the transaction date) and attached. |
| **Choose from Files** | Pick any existing file from your Files — it stays exactly where it is. |

Allowed types: JPEG, PNG, WebP, HEIC, and PDF, up to 25 MB. Transactions with receipts show a 📎 badge in the transaction list.

### Behavior to know

- Images show a thumbnail; clicking an attachment opens the file in Files.
- **Removing an attachment never deletes the file** — it only unlinks it. The same applies when a transaction is deleted or on a [factory reset](settings.md): rows are removed, files stay in your Files.
- If you delete or move the file in Files, renames are followed automatically (the link is by file id, not path). A deleted file shows as *missing* on the transaction, with the last known name.
- Attachments are visible only to the account owner — viewers of a [shared account](sharing.md) don't see them.
- The app's [export](settings.md) does not include receipt files, and attachment links don't survive an export/import to another instance (file ids are instance-specific). Files themselves are safe in your Files space either way.
