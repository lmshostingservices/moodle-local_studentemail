# local_studentemail

Moodle plugin — provisions cPanel mailboxes for students and provides a built-in
IMAP/SMTP mail client at `/local/studentemail/mailbox.php`.

## Drafts and Sent (v1.5.35)

Both live in the student's **real cPanel mailbox** over IMAP, so the same
messages appear in Moodle, in cPanel/Roundcube webmail, and in any mail client.

**Sent** — after SMTP delivery succeeds, the exact MIME source that went out is
appended to the mailbox's Sent folder. The folder name is auto-detected
(`Sent`, `INBOX.Sent`, `Sent Items`, …) and created if the account has none.
The append runs *after* the JSON response has been flushed, so a slow or
unavailable Sent folder can never make a successful send look like a failure.

**Drafts** — compose content is never discarded by closing the pane. It is:

* kept in the compose pane (reopening Compose brings it back),
* backed up in browser `localStorage` between saves,
* autosaved to the IMAP Drafts folder every 25 seconds,
* saved when the pane is closed or the folder is switched,
* saved on demand with the **Save draft** button.

Each draft carries an `X-SEM-Draft-Id` header, so re-saving replaces the
previous revision instead of piling up copies. Clicking a message in Drafts
reopens it in the editor; sending deletes the draft it came from. **Discard**
removes it everywhere, with confirmation.

Note: file attachments are not stored in drafts — attach files before sending.

### Requirements

* PHP `imap` extension enabled.
* IMAP settings configured (in `auth_studentemail`, or `local_studentemail` as
  fallback) and SMTP settings under `local_studentemail`.
* The mailbox user needs permission to create folders if Sent/Drafts are absent.

### Troubleshooting

Append results are written to `$CFG->dataroot/sem_send_debug.txt`
(`sent-copy filed=yes|no` plus the IMAP error, when any).

## Licence

GNU GPL v3 or later.
