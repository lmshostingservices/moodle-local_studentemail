# Changelog - local_studentemail

All notable changes to this plugin are recorded here.

Entries below v1.5.35 are reconstructed from the release notes carried in
`version.php`. Release dates were not recorded there for most of those
versions and have deliberately not been invented; only dates stated in the
source are shown.

## [v1.5.39] - 2026-09-09

### Fixed
- **The link-before-create fix in v1.5.38 was dead code.**
  `find_existing_mailbox()` read the account list from `$result['data']`, but
  `cpanel_api::list_accounts()` returns it under `accounts`, so no mailbox was
  ever matched and a second address would still have been created. Now verified
  against both cPanel response shapes — local part in `login` with a separate
  `domain` field, and the v136+ full address in `login`.
- The server mailbox list is fetched **once per request** instead of once per
  student. `create_missing()` across hundreds of students would otherwise have
  made hundreds of cPanel API calls.
- `link_account()` refuses a mailbox already linked to another student, with a
  readable message naming that student. The unique index on `email` would
  otherwise have raised a raw database error, and the manual type-an-address
  path had no guard at all.
- Cancelling the relink confirmation left the dropdown stuck on the value that
  was chosen, so selecting the same mailbox again did nothing. The choice is now
  cleared on cancel.

### Changed
- The unlink confirmation now states that the student's mailbox page in Moodle
  shows no account until they are linked again.

## [v1.5.38] - 2026-09-09

### Fixed
- **A student could be linked to the wrong mailbox with no way to repair it.**
  `Link Existing` only rendered on unlinked rows and there was no unlink action,
  so a student linked to the wrong address could only be repaired with SQL.
  Linked rows now have **Relink** and **Unlink**.
- **Root cause:** `create_email()` checked only whether the plugin already had a
  record for the Moodle user, never whether a mailbox for that person already
  existed on the mail server, so it minted a second address. It now looks for an
  existing mailbox first — exact match on the profile local part (only when the
  profile address is on the college domain), username, ID number, configured
  format, then firstname.lastname — and links it instead. A mailbox already
  linked to another student is never matched. New setting
  `prefer_existing_mailbox`, on by default.

### Added
- **Relink** opens a chooser listing every mailbox available for that student,
  marking the one that matches their Moodle profile and the one linked now, so
  the administrator picks which address to keep. Confirmation states that
  linking resets the mailbox password and that no mail is deleted.
- **Unlink** removes the plugin record only. The mailbox and every message in it
  stay on the server, so the action is always reversible.
- The dashboard student column now shows the Moodle profile address, with a
  warning when Moodle mails one mailbox while the plugin opens another.
- Fifth Data Quality check: every student split across two mailboxes.
- New AJAX action `unlink_account`; `list_cpanel_accounts` accepts an optional
  `userid` and returns labelled options.

## [v1.5.37] - 2026-09-04

### Changed
- **Security (pipeline blocker):** the raw parameter type is no longer used
  anywhere. Message bodies use
  `PARAM_CLEANHTML` — the narrowest type that preserves rich-text formatting,
  and it applies `clean_text()` itself — and the signature uses `PARAM_TEXT`.
- `mailbox.php` and `webmail.php` check `local/studentemail:viewown` after
  `require_login()`. The capability is granted to the authenticated user and
  student archetypes by default, so no student loses access.
- Coding style: one statement per line in `classes/imap_client.php` and
  `dashboard.php`; multi-line calls in `ajax.php`, `classes/email_manager.php`,
  `classes/imap_client.php`, `mailbox.php` and `mailbox_ajax.php` now start
  their arguments on the line after the opening parenthesis; comment blocks in
  `ajax.php`, `classes/cpanel_api.php`, `classes/email_manager.php` and
  `classes/imap_client.php` begin with a capital letter.

### Not changed
- The ALL-CAPS language-string warning for `IMAP (Incoming Mail)`,
  `IMAP Hostname` and `IMAP Port` is a false positive: IMAP is a protocol
  acronym, and the identical SMTP labels are not flagged. Rewording only the
  IMAP labels would make the settings page inconsistent. The fix belongs in the
  checker's acronym allowlist.

## [v1.5.36] - 2026-09-04

### Fixed
- **Nothing was actually filed in Sent or Drafts in v1.5.35.** `imap_list()`
  returns the full mailbox specification including connection flags
  (`{mail.host:993/imap/ssl}INBOX.Sent`), but `list_folders()` stripped only the
  `{host:port}` form, so folder names kept the specification prefix. Every later
  IMAP command then specified them a second time and addressed a mailbox that
  cannot exist. `list_folders()` now strips any `{...}` prefix and
  `build_mailbox_spec()` refuses to specify an already-specified name.
- Sent/Drafts resolution is now verified rather than assumed: exact match on
  conventional names, then leaf-name match, then direct `imap_status()` probing
  of conventional names when the folder list is unusable, and only then
  creation. Every candidate is confirmed addressable before it is used.

### Changed
- The sent copy is filed **before** the response is returned (every IMAP wait
  bounded by `imap_timeout()`), and the response reports `sent_filed`,
  `sent_folder` and a full `append_log`. Failing to file no longer fails the
  send, but it is now reported instead of being invisible.
- The compose footer states which folder the copy went to, or warns when it
  could not be filed. Draft saves return the same diagnostic log, printed to the
  browser console by `semDebug()`.
- A failed autosave or save-on-close now raises the draft recovery bar, instead
  of writing an error into a pane that has just been hidden.
- Added missing language string `error_lock` (previously rendered as
  `[[error_lock]]`), plus `messagesent`, `sent_notfiled`, `sent_filedto`.

## [v1.5.35] - 2026-09-02

### Fixed
- Sent messages are now filed in the mailbox's real IMAP Sent folder. The exact
  MIME source delivered over SMTP is appended after the JSON response has been
  flushed, so a slow or unavailable Sent folder can no longer make a successful
  send report a server error. The folder name is auto-detected (`Sent`,
  `INBOX.Sent`, `Sent Items`, …) and created if the account has none. The
  previous blocking append that caused that failure has been removed.
- Compose content is no longer discarded when the compose pane is closed
  (backdrop click, close button, or folder switch).

### Added
- Drafts are saved to the mailbox's real IMAP Drafts folder: autosaved every
  25 seconds, on close, and on demand with a new **Save draft** button. Each
  draft carries an `X-SEM-Draft-Id` header so re-saving replaces the previous
  revision instead of creating duplicates.
- Clicking a message in the Drafts folder reopens it in the compose editor;
  sending deletes the draft it was composed from.
- Browser-local backup of the current draft, with a recovery bar offering to
  restore a draft left behind by a crash or accidental tab close, and an
  unload warning while a draft is unsaved.
- **Cancel** is now **Discard** and asks for confirmation before deleting the
  draft locally and on the server.
- New AJAX actions `save_draft` and `delete_draft`; new `imap_client` methods
  `append_sent_copy()`, `save_draft()`, `delete_drafts_by_id()` and
  `find_special_folder()`.

### Changed
- Privacy provider replaced: the plugin previously declared `null_provider`
  while storing per-user mailbox records and a saved signature. It now declares
  the `local_studentemail_accounts` table, the `local_studentemail_signature`
  user preference, and the external cPanel mail server, and implements export
  and all required deletion modes.
- User-facing text for the draft and sent-copy features moved into the
  Language API.
- IMAP connection, read, write and close timeouts are now bounded.
- Raw-typed request bodies are documented and validated immediately with
  `clean_text()` / `clean_param()`.
- Post-send diagnostics use `debugging(..., DEBUG_DEVELOPER)` instead of
  writing to a file in the Moodle data directory.
- `db/upgrade.php` now carries the standard Moodle GPL boilerplate.
- `test_harness.php`, a developer-only script with no access control, is no
  longer shipped in the release package.
- Package root directory corrected to `studentemail/` so the plugin installs to
  `local/studentemail/` as its component requires.

## [v1.5.34]

### Fixed
- Import collision fix: `import_existing()` pre-builds a map of already-linked
  emails and skips matches that would violate the `email_idx` unique
  constraint; per-record DML errors no longer abort the whole import.
- cPanel local-part parsing fixed for cPanel v136+ responses.

### Added
- Data Quality report and `diagnose_import` SQL diagnostics.

## [v1.5.33] - 2026-07-29

### Added
- Deep IMAP diagnostic replacing the previous "Test IMAP" button: tries both
  username formats, lists every folder with message counts, and recommends a
  fix. New per-student "Test Mailbox" action and `test_imap_student` AJAX
  action.

## [v1.5.x earlier]

### Added
- Status filter dropdown on the dashboard search bar.
- Auto-generated mailbox password on import and link, with welcome credentials.
- Browser debug console output, SMTP conversation in the AJAX response, and
  `X-SEM-Step` / `X-FATAL` response headers.
- One-click "Link Existing" from a list of unlinked cPanel accounts
  (`list_cpanel_accounts`).
- "Test SMTP" dashboard action (`test_smtp`).

### Fixed
- Mailbox page called a non-existent `get_credentials()`; corrected to
  `get_account_for_user()`.

## [v1.5.0]

### Added
- Native IMAP/SMTP mail client built into Moodle (`mailbox.php`): inbox,
  compose, reply, forward, delete, folder sidebar, unread badge and search.
  New settings `mailbox_enabled`, `smtp_host`, `smtp_port`, `smtp_encryption`.

## [v1.2.2]

### Fixed
- `require_once` of `adminlib.php` before `admin_externalpage_setup()`.

## [v1.2.1]

### Fixed
- `claim_next_number()` takes a Moodle lock before reading and incrementing the
  counter, preventing duplicate addresses on simultaneous enrolments.
- `encrypt_password()` uses a random per-encryption IV stored as
  `v2:base64(iv+ciphertext)`; decryption still supports the legacy v1 format.

## [v1.0.0]

### Added
- Initial release: automatic cPanel mailbox provisioning for Moodle students
  (UAPI with JSON API v2 fallback), AES-256-CBC encrypted passwords, four
  address formats, admin dashboard, enrolment/suspension/deletion/reactivation
  rules, and the student "My Email" portal.
