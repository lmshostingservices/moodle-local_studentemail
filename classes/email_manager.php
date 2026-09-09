<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Business logic for managing student email accounts.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_studentemail;

defined('MOODLE_INTERNAL') || die();

/**
 * Central manager for student email provisioning.
 *
 * All public methods return:
 *   ['success' => bool, 'message' => string, ...extra]
 */
class email_manager {
    /** @var array|null Mailboxes on the server, keyed by local part; null until fetched */
    private ?array $server_mailboxes = null;

    // Email format constants.
    const FORMAT_AUTONUMBER = 'autonumber';
    const FORMAT_USERNAME   = 'username';
    const FORMAT_IDNUMBER   = 'idnumber';
    const FORMAT_FIRSTLAST  = 'firstlast';

    // Status constants.
    const STATUS_ACTIVE    = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_ARCHIVED  = 'archived';
    const STATUS_PENDING   = 'pending';
    const STATUS_ERROR     = 'error';

    /** @var cpanel_api */
    private $api;

    public function __construct() {
        $this->api = new cpanel_api();
    }

    // =========================================================================
    // Account CRUD
    // =========================================================================

    /**
     * Provision a new email account for a Moodle user.
     *
     * @param \stdClass $user   Moodle user record
     * @return array
     */
    public function create_email(\stdClass $user): array {
        global $DB;

        if (!$this->api->is_configured()) {
            return ['success' => false, 'message' => get_string('error_nocpanel', 'local_studentemail')];
        }

        // Already has an account?
        if ($DB->record_exists('local_studentemail_accounts', ['userid' => $user->id])) {
            return ['success' => false, 'message' => get_string('error_already_exists', 'local_studentemail')];
        }

        $domain = get_config('local_studentemail', 'email_domain');

        // Before minting a new address, check whether this student already has a
        // mailbox on the mail server. Creating a second one silently splits the
        // student in two: Moodle keeps mailing the address in their profile
        // while the mailbox client signs in to the new one, so they never see
        // their own mail.
        if (get_config('local_studentemail', 'prefer_existing_mailbox') !== '0') {
            $existingmailbox = $this->find_existing_mailbox($user, $domain);
            if ($existingmailbox !== '') {
                $linked = $this->link_account($user->id, $existingmailbox);
                if (!empty($linked['success'])) {
                    $linked['message'] = get_string(
                        'success_linked_existing',
                        'local_studentemail',
                        $existingmailbox
                    );
                    $linked['linked_existing'] = true;
                }
                return $linked;
            }
        }

        // Generate email address.
        $localpart = $this->generate_localpart($user);
        if (empty($localpart)) {
            return ['success' => false, 'message' => get_string('error_format', 'local_studentemail')];
        }

        $email = $localpart . '@' . $domain;

        // Generate password.
        $password = $this->generate_password();
        $quota_mb = (int)(get_config('local_studentemail', 'email_quota_mb') ?: 1024);

        // Call cPanel API.
        $result = $this->api->create_account($localpart, $password, $quota_mb);
        if (!$result['success']) {
            // Save error record so admin can see it on the dashboard.
            $this->save_account_record($user->id, $email, '', null, self::STATUS_ERROR, $quota_mb, $result['message']);
            return $result;
        }

        // Save to DB.
        $studentnumber = $this->get_format() === self::FORMAT_AUTONUMBER ? $this->peek_current_number() : null;
        $this->save_account_record($user->id, $email, $password, $studentnumber, self::STATUS_ACTIVE, $quota_mb, '');

        return [
            'success'  => true,
            'message'  => get_string('success_created', 'local_studentemail', $email),
            'email'    => $email,
            'password' => $password,
        ];
    }

    /**
     * Send a welcome email to the student's personal Moodle email address
     * containing their new college email address and password.
     *
     * Called automatically after create_email() when the
     * 'welcome_email_enabled' setting is on, and available manually via
     * the dashboard "Resend Credentials" button.
     *
     * @param \stdClass $user     The Moodle user object.
     * @param string    $email    The provisioned college email address.
     * @param string    $password The plaintext password for that address.
     * @return array ['success' => bool, 'message' => string]
     */
    public function send_welcome_email(\stdClass $user, string $email, string $password): array {
        global $CFG, $SITE;

        if (empty($user->email)) {
            return ['success' => false, 'message' => 'Student has no personal email address in Moodle to send credentials to.'];
        }

        $site_name   = $SITE->fullname ?? 'Your College';
        $login_url   = $CFG->wwwroot . '/login/index.php';
        $subject     = get_string('welcome_email_subject', 'local_studentemail', $site_name);

        $body_plain = get_string(
            'welcome_email_body',
            'local_studentemail',
            (object)[
                'firstname' => $user->firstname,
                'site'      => $site_name,
                'email'     => $email,
                'password'  => $password,
                'loginurl'  => $login_url,
            ]
        );

        $body_html = nl2br(htmlspecialchars($body_plain, ENT_QUOTES, 'UTF-8'));

        // Use Moodle's email_to_user so it respects site SMTP settings and logs.
        $from        = \core_user::get_noreply_user();
        $result      = email_to_user($user, $from, $subject, $body_plain, $body_html);

        if ($result) {
            return ['success' => true, 'message' => 'Welcome email sent to ' . $user->email];
        }
        return ['success' => false, 'message' => 'email_to_user() returned false — check Moodle outgoing mail settings.'];
    }

    /**
     * Resend welcome credentials email for an existing provisioned account.
     * Decrypts the stored password and calls send_welcome_email().
     *
     * @param int $userid Moodle user ID.
     * @return array ['success' => bool, 'message' => string]
     */
    public function resend_welcome(int $userid): array {
        global $DB;

        $account = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if (!$account) {
            return ['success' => false, 'message' => 'No provisioned email account found for this student.'];
        }

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        $password = $this->decrypt_password($account->emailpassword);
        if (empty($password)) {
            return ['success' => false, 'message' => 'Could not decrypt stored password. Try resetting the password first (Reset PW), then resend.'];
        }

        return $this->send_welcome_email($user, $account->email, $password);
    }

    /**
     * Suspend a student's email (blocks webmail login, sets quota to 1 MB).
     */
    public function suspend_email(int $userid): array {
        global $DB;

        $account = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if (!$account) {
            return ['success' => false, 'message' => get_string('error_no_email', 'local_studentemail')];
        }

        // Set quota to 1 MB so no new mail is accepted.
        $localpart = $this->email_to_localpart($account->email);
        $this->api->set_quota($localpart, 1);

        $DB->update_record(
            'local_studentemail_accounts',
            (object)[
                'id'           => $account->id,
                'status'       => self::STATUS_SUSPENDED,
                'timemodified' => time(),
            ]
        );

        return ['success' => true, 'message' => get_string('success_suspended', 'local_studentemail')];
    }

    /**
     * Restore a suspended email account.
     */
    public function restore_email(int $userid): array {
        global $DB;

        $account = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if (!$account) {
            return ['success' => false, 'message' => get_string('error_no_email', 'local_studentemail')];
        }

        $localpart = $this->email_to_localpart($account->email);
        $quota_mb  = (int)(get_config('local_studentemail', 'email_quota_mb') ?: 1024);
        $this->api->set_quota($localpart, $quota_mb);

        $DB->update_record(
            'local_studentemail_accounts',
            (object)[
                'id'           => $account->id,
                'status'       => self::STATUS_ACTIVE,
                'timemodified' => time(),
            ]
        );

        return ['success' => true, 'message' => get_string('success_restored', 'local_studentemail')];
    }

    /**
     * Archive an email account (change to random password so student can't log in,
     * but mailbox is preserved for admin access).
     */
    public function archive_email(int $userid): array {
        global $DB;

        $account = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if (!$account) {
            return ['success' => false, 'message' => get_string('error_no_email', 'local_studentemail')];
        }

        $localpart   = $this->email_to_localpart($account->email);
        $random_pass = $this->generate_password() . $this->generate_password(); // Long unguessable.
        $this->api->change_password($localpart, $random_pass);

        $DB->update_record(
            'local_studentemail_accounts',
            (object)[
                'id'           => $account->id,
                'status'       => self::STATUS_ARCHIVED,
                'timemodified' => time(),
            ]
        );

        return ['success' => true, 'message' => get_string('success_archived', 'local_studentemail')];
    }

    /**
     * Reset password for an email account and return the new password.
     */
    public function reset_password(int $userid): array {
        global $DB;

        $account = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if (!$account) {
            return ['success' => false, 'message' => get_string('error_no_email', 'local_studentemail')];
        }

        $localpart    = $this->email_to_localpart($account->email);
        $new_password = $this->generate_password();
        $result       = $this->api->change_password($localpart, $new_password);

        if (!$result['success']) {
            return $result;
        }

        $DB->update_record(
            'local_studentemail_accounts',
            (object)[
                'id'            => $account->id,
                'emailpassword' => $this->encrypt_password($new_password),
                'notes'         => '', // Clear any stale "Pwd externally managed" badge.
                'timemodified'  => time(),
            ]
        );

        return [
            'success'      => true,
            'message'      => get_string('success_password_reset', 'local_studentemail'),
            'new_password' => $new_password,
            'email'        => $account->email,
        ];
    }

    // =========================================================================
    // Bulk operations
    // =========================================================================

    /**
     * Create email accounts for all Moodle users who don't have one.
     *
     * @return array ['success'=>bool, 'created'=>int, 'errors'=>int, 'message'=>string]
     */
    public function create_missing(): array {
        global $DB;

        $users = $DB->get_records_sql(
            "SELECT u.* FROM {user} u
             LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
             WHERE u.deleted = 0 AND u.suspended = 0 AND sea.id IS NULL
             AND u.id > 1",
            []
        );

        $created = 0;
        $errors  = 0;
        foreach ($users as $user) {
            $result = $this->create_email($user);
            if ($result['success']) {
                $created++;
            } else {
                $errors++;
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'errors'  => $errors,
            'message' => "Created {$created} accounts. {$errors} errors.",
        ];
    }

    /**
     * Import existing cPanel email accounts and link them to Moodle users.
     *
     * Fetches all email accounts from cPanel for the configured domain and
     * tries to match each unlinked Moodle user by:
     *   1. Moodle username  (most common — e.g. aiop241102 → aiop241102@domain)
     *   2. Moodle idnumber
     *   3. Configured email format (autonumber/username/idnumber/firstlast)
     *   4. firstname.lastname
     *
     * Matched accounts are recorded as STATUS_ACTIVE with a note that the
     * password is managed externally (the plugin did not set it).
     *
     * @return array ['success'=>bool, 'imported'=>int, 'no_match'=>int, 'message'=>string]
     */
    public function import_existing(): array {
        global $DB;

        if (!$this->api->is_configured()) {
            return ['success' => false, 'message' => get_string('error_nocpanel', 'local_studentemail')];
        }

        // Fetch all cPanel accounts for the configured domain.
        $cpanel_result = $this->api->list_accounts();
        if (!$cpanel_result['success']) {
            return [
                'success' => false,
                'message' => 'Could not fetch cPanel accounts: ' . $cpanel_result['message'],
            ];
        }

        $accounts = $cpanel_result['accounts'];
        if (empty($accounts)) {
            return [
                'success' => true, 'imported' => 0, 'no_match' => 0,
                'message' => 'No email accounts found in cPanel for the configured domain.',
            ];
        }

        // Build lookup map: lowercase localpart => full email address.
        // cPanel UAPI field names vary by version:
        //   Older cPanel: 'login' = localpart only (e.g. "aiop241102")
        //   cPanel v136+: 'login' = full email (e.g. "aiop241102@aiopms.com.au")
        //   Some versions: 'user' = localpart, 'domain' separate
        // We normalise all cases to extract just the localpart.
        $domain     = strtolower(trim(get_config('local_studentemail', 'email_domain')));
        $cpanel_map = [];
        foreach ($accounts as $acct) {
            $acct = (array)$acct;

            $localpart = '';

            // Try 'login' first — may be localpart or full email.
            $raw = strtolower(trim($acct['login'] ?? ''));
            if (!empty($raw)) {
                // If it contains @ it's a full email — extract localpart.
                $localpart = strpos($raw, '@') !== false ? substr($raw, 0, strpos($raw, '@')) : $raw;
            }

            // Try 'user' field (some cPanel versions).
            if (empty($localpart)) {
                $raw = strtolower(trim($acct['user'] ?? ''));
                if (!empty($raw)) {
                    $localpart = strpos($raw, '@') !== false ? substr($raw, 0, strpos($raw, '@')) : $raw;
                }
            }

            // Fall back to parsing the 'email' field.
            if (empty($localpart) && !empty($acct['email'])) {
                $raw = strtolower(trim($acct['email']));
                $localpart = strpos($raw, '@') !== false ? substr($raw, 0, strpos($raw, '@')) : $raw;
            }

            if (!empty($localpart)) {
                $cpanel_map[$localpart] = $localpart . '@' . $domain;
            }
        }

        if (empty($cpanel_map)) {
            return [
                'success' => false,
                'message' => 'Could not parse cPanel account list — no localparts found. '
                           . 'Run the Diagnose Import tool to inspect the raw cPanel response.',
            ];
        }

        // Get all Moodle users who do not yet have a plugin record.
        $users = $DB->get_records_sql(
            "SELECT u.* FROM {user} u
             LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
             WHERE u.deleted = 0 AND u.id > 1 AND sea.id IS NULL",
            []
        );

        $imported   = 0;
        $no_match   = 0;
        $skipped    = 0;
        $errors     = [];
        $quota_mb   = (int)(get_config('local_studentemail', 'email_quota_mb') ?: 1024);

        // SQL pre-flight: build a set of emails already linked to OTHER users
        // so we never try to insert a duplicate and violate the email_idx UNIQUE constraint.
        $already_linked_emails = [];
        $linked_rows = $DB->get_records('local_studentemail_accounts', null, '', 'userid, email');
        foreach ($linked_rows as $row) {
            if (!empty($row->email)) {
                $already_linked_emails[strtolower($row->email)] = (int)$row->userid;
            }
        }

        foreach ($users as $user) {
            // Build candidate localparts in priority order.
            $candidates = [];

            // 1. Moodle username — if it looks like an email address (contains @),
            //    use only the localpart (before @) as the first candidate so we don't
            //    accidentally concatenate the domain chars into a nonsense string.
            if (!empty($user->username)) {
                if (strpos($user->username, '@') !== false) {
                    $localpart_only = strtolower(strtok($user->username, '@'));
                    if ($localpart_only !== '') {
                        $candidates[] = $localpart_only;
                    }
                }
                // Also add the sanitised full username as a fallback (covers
                // non-email usernames like "jsmith" or "JS.2024").
                $sanitised = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $user->username));
                if ($sanitised !== '' && !in_array($sanitised, $candidates)) {
                    $candidates[] = $sanitised;
                }
            }

            // 2. Moodle idnumber.
            if (!empty($user->idnumber)) {
                $candidates[] = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $user->idnumber));
            }

            // 3. Configured email format (non-autonumber formats only —
            //    autonumber is sequential and won't match existing accounts reliably).
            $fmt = $this->get_format();
            if ($fmt !== self::FORMAT_AUTONUMBER) {
                $formatted = '';
                if ($fmt === self::FORMAT_USERNAME && !empty($user->username)) {
                    $formatted = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $user->username));
                } else if ($fmt === self::FORMAT_IDNUMBER && !empty($user->idnumber)) {
                    $formatted = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $user->idnumber));
                } else if ($fmt === self::FORMAT_FIRSTLAST) {
                    $first = strtolower(preg_replace('/[^a-z]/i', '', $user->firstname));
                    $last  = strtolower(preg_replace('/[^a-z]/i', '', $user->lastname));
                    $formatted = $first . '.' . $last;
                }
                if ($formatted) {
                    $candidates[] = $formatted;
                }
            }

            // 4. firstname.lastname (always try as a fallback).
            $fl = strtolower(preg_replace('/[^a-z]/i', '', $user->firstname))
                . '.' . strtolower(preg_replace('/[^a-z]/i', '', $user->lastname));
            if (strlen($fl) > 1) {
                $candidates[] = $fl;
            }

            // Remove duplicates while preserving order.
            $candidates = array_unique(array_filter($candidates));

            $matched_email = null;
            foreach ($candidates as $candidate) {
                if (isset($cpanel_map[$candidate])) {
                    $matched_email = $cpanel_map[$candidate];
                    break;
                }
            }

            if ($matched_email) {
                // SQL collision guard: if this email is already linked to a DIFFERENT user,
                // skip rather than trigger the email_idx UNIQUE constraint violation.
                $email_lc = strtolower($matched_email);
                if (isset($already_linked_emails[$email_lc])
                        && $already_linked_emails[$email_lc] !== (int)$user->id) {
                    $errors[] = "Skipped user {$user->username} (id={$user->id}): "
                              . "email {$matched_email} already linked to userid "
                              . $already_linked_emails[$email_lc];
                    $skipped++;
                    continue;
                }

                // Generate a fresh password and set it on cPanel now so the plugin
                // owns the credential from this point forward.
                $new_password = $this->generate_password();
                $localpart    = $this->email_to_localpart($matched_email);
                $pw_result    = $this->api->change_password($localpart, $new_password);

                $stored_pw = '';
                $notes     = '';
                if ($pw_result['success']) {
                    $stored_pw = $new_password;
                    $notes     = '';
                } else {
                    // The cPanel reset failed — save the row but flag it so an admin can see.
                    $notes = 'Imported — auto password reset failed: ' . $pw_result['message'] . '. Use Reset PW.';
                }

                try {
                    $this->save_account_record(
                        $user->id,
                        $matched_email,
                        $stored_pw,
                        null,
                        self::STATUS_ACTIVE,
                        $quota_mb,
                        $notes
                    );
                    // Mark email as taken in our in-memory map so later iterations
                    // in this same loop also see it as already linked.
                    $already_linked_emails[$email_lc] = (int)$user->id;
                } catch (\dml_exception $e) {
                    $errors[] = "DB error for user {$user->username} (id={$user->id}) "
                              . "email {$matched_email}: " . $e->getMessage();
                    $skipped++;
                    continue;
                }

                // Send welcome email with new credentials if reset succeeded.
                if ($pw_result['success'] && get_config('local_studentemail', 'welcome_email_enabled')) {
                    $this->send_welcome_email($user, $matched_email, $new_password);
                }

                $imported++;
            } else {
                $no_match++;
            }
        }

        $msg = "Linked {$imported} existing cPanel accounts to Moodle users.";
        if ($no_match > 0) {
            $msg .= " {$no_match} had no matching cPanel account.";
        }
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (duplicate email — see Diagnose Import for details).";
        }

        return [
            'success'  => true,
            'imported' => $imported,
            'no_match' => $no_match,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'message'  => $msg,
        ];
    }

    /**
     * Manually link a specific Moodle user to an existing cPanel email address.
     *
     * Does NOT create anything in cPanel — it only records the association
     * so the plugin can manage the account going forward.
     *
     * @param int    $userid     Moodle user ID
     * @param string $email      Full email address (e.g. stu0001@students.college.edu.au)
     * @return array
     */
    public function link_account(int $userid, string $email): array {
        global $DB;

        $email = strtolower(trim($email));
        if (empty($email) || strpos($email, '@') === false) {
            return ['success' => false, 'message' => 'Invalid email address.'];
        }

        if (!$this->api->is_configured()) {
            return ['success' => false, 'message' => get_string('error_nocpanel', 'local_studentemail')];
        }

        // The email column is unique. Linking a mailbox that belongs to another
        // student would raise a database error, and would hand one student
        // another student's mail, so refuse it with a readable message.
        $owner = $DB->get_record_sql(
            'SELECT userid FROM {local_studentemail_accounts} WHERE LOWER(email) = LOWER(?)',
            [$email]
        );
        if ($owner && (int)$owner->userid !== $userid) {
            $ownername = fullname($DB->get_record('user', ['id' => $owner->userid]));
            return [
                'success' => false,
                'message' => get_string(
                    'error_mailboxclaimed',
                    'local_studentemail',
                    (object)['email' => $email, 'name' => $ownername]
                ),
            ];
        }

        $quota_mb = (int)(get_config('local_studentemail', 'email_quota_mb') ?: 1024);

        // Generate a fresh password and set it on cPanel so the plugin owns
        // the credential immediately — no "externally managed" limbo.
        $new_password = $this->generate_password();
        $localpart    = $this->email_to_localpart($email);
        $pw_result    = $this->api->change_password($localpart, $new_password);

        $stored_pw = '';
        $notes     = '';
        if ($pw_result['success']) {
            $stored_pw = $new_password;
        } else {
            $notes = 'Manually linked — auto password reset failed: ' . $pw_result['message'] . '. Use Reset PW.';
        }

        $this->save_account_record(
            $userid, $email, $stored_pw, null,
            self::STATUS_ACTIVE, $quota_mb, $notes
        );

        // Send welcome email if reset succeeded and setting is on.
        $user = $DB->get_record('user', ['id' => $userid]);
        if ($pw_result['success'] && $user && get_config('local_studentemail', 'welcome_email_enabled')) {
            $this->send_welcome_email($user, $email, $new_password);
        }

        $msg = $pw_result['success']
            ? "Linked to existing account: {$email}. New password set and credentials emailed to student."
            : "Linked to existing account: {$email}. Password reset failed — use Reset PW manually.";

        return [
            'success' => true,
            'message' => $msg,
            'email'   => $email,
        ];
    }

    /**
     * Remove the plugin's link between a Moodle user and a mailbox.
     *
     * This deletes the plugin record only. The mailbox itself is left exactly
     * as it is on the mail server, with all of its mail, so an unlink can
     * always be undone by linking again. Use it to repoint a student who was
     * linked to the wrong mailbox.
     *
     * @param  int $userid The Moodle user to unlink.
     * @return array ['success' => bool, 'message' => string, 'email' => string]
     */
    public function unlink_account(int $userid): array {
        global $DB;

        $record = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if (!$record) {
            return [
                'success' => false,
                'message' => get_string('error_notlinked', 'local_studentemail'),
            ];
        }

        $DB->delete_records('local_studentemail_accounts', ['id' => $record->id]);

        return [
            'success' => true,
            'message' => get_string('success_unlinked', 'local_studentemail', $record->email),
            'email'   => $record->email,
        ];
    }

    /**
     * Suspend accounts for all Moodle users who are suspended.
     */
    public function suspend_leavers(): array {
        global $DB;

        $accounts = $DB->get_records_sql(
            "SELECT sea.* FROM {local_studentemail_accounts} sea
             JOIN {user} u ON u.id = sea.userid
             WHERE u.suspended = 1 AND sea.status = ?",
            [self::STATUS_ACTIVE]
        );

        $suspended = 0;
        foreach ($accounts as $account) {
            $result = $this->suspend_email($account->userid);
            if ($result['success']) {
                $suspended++;
            }
        }

        return ['success' => true, 'suspended' => $suspended, 'message' => "Suspended {$suspended} accounts."];
    }

    // =========================================================================
    // Data Quality Reports
    // =========================================================================

    /**
     * Run data quality checks across Moodle users and SEM records.
     * Returns four report sections: duplicate Moodle users, multiple SEM records
     * per user, orphaned SEM records (user deleted), and suspended-but-active mismatches.
     */
    public function get_data_quality(): array {
        global $DB;

        // 1. Duplicate Moodle accounts — same firstname + lastname, different user IDs.
        $dupe_rows = $DB->get_records_sql(
            "SELECT u1.id            AS userid1,
                    u1.firstname     AS firstname,
                    u1.lastname      AS lastname,
                    u1.username      AS username1,
                    u1.email         AS moodle_email1,
                    u1.lastaccess    AS lastaccess1,
                    u2.id            AS userid2,
                    u2.username      AS username2,
                    u2.email         AS moodle_email2,
                    u2.lastaccess    AS lastaccess2,
                    sea1.email       AS sem_email1,
                    sea1.status      AS sem_status1,
                    sea2.email       AS sem_email2,
                    sea2.status      AS sem_status2
             FROM   {user} u1
             JOIN   {user} u2
                    ON  LOWER(u1.firstname) = LOWER(u2.firstname)
                    AND LOWER(u1.lastname)  = LOWER(u2.lastname)
                    AND u1.id < u2.id
             LEFT JOIN {local_studentemail_accounts} sea1 ON sea1.userid = u1.id
             LEFT JOIN {local_studentemail_accounts} sea2 ON sea2.userid = u2.id
             WHERE  u1.deleted = 0 AND u2.deleted = 0
               AND  u1.id > 1    AND u2.id > 1
             ORDER BY u1.lastname, u1.firstname"
        );

        $duplicates = [];
        foreach ($dupe_rows as $r) {
            $duplicates[] = [
                'name'         => trim($r->firstname . ' ' . $r->lastname),
                'userid1'      => (int)$r->userid1,
                'username1'    => $r->username1,
                'email1'       => $r->moodle_email1,
                'lastaccess1'  => $r->lastaccess1 ? date('d M Y', $r->lastaccess1) : 'Never',
                'sem_email1'   => $r->sem_email1 ?: '',
                'sem_status1'  => $r->sem_status1 ?: 'none',
                'userid2'      => (int)$r->userid2,
                'username2'    => $r->username2,
                'email2'       => $r->moodle_email2,
                'lastaccess2'  => $r->lastaccess2 ? date('d M Y', $r->lastaccess2) : 'Never',
                'sem_email2'   => $r->sem_email2 ?: '',
                'sem_status2'  => $r->sem_status2 ?: 'none',
            ];
        }

        // 2. Multiple SEM records per Moodle user.
        $multi_rows = $DB->get_records_sql(
            "SELECT u.id AS userid, u.firstname, u.lastname, u.username,
                    COUNT(sea.id) AS record_count,
                    GROUP_CONCAT(sea.email ORDER BY sea.id SEPARATOR ' | ') AS sem_emails,
                    GROUP_CONCAT(sea.status ORDER BY sea.id SEPARATOR ' | ') AS sem_statuses
             FROM   {user} u
             JOIN   {local_studentemail_accounts} sea ON sea.userid = u.id
             WHERE  u.deleted = 0
             GROUP  BY u.id, u.firstname, u.lastname, u.username
             HAVING COUNT(sea.id) > 1
             ORDER  BY u.lastname, u.firstname"
        );

        $multi = [];
        foreach ($multi_rows as $r) {
            $multi[] = [
                'userid'     => (int)$r->userid,
                'name'       => trim($r->firstname . ' ' . $r->lastname),
                'username'   => $r->username,
                'count'      => (int)$r->record_count,
                'sem_emails' => $r->sem_emails,
                'statuses'   => $r->sem_statuses,
            ];
        }

        // 3. Orphaned SEM records — plugin row exists but Moodle user deleted.
        $orphan_rows = $DB->get_records_sql(
            "SELECT sea.id, sea.userid, sea.email AS sem_email, sea.status
             FROM   {local_studentemail_accounts} sea
             LEFT JOIN {user} u ON u.id = sea.userid
             WHERE  u.id IS NULL OR u.deleted = 1"
        );

        $orphans = [];
        foreach ($orphan_rows as $r) {
            $orphans[] = [
                'record_id' => (int)$r->id,
                'userid'    => (int)$r->userid,
                'sem_email' => $r->sem_email,
                'status'    => $r->status,
            ];
        }

        // 4. Moodle-suspended users with an active cPanel account.
        $mismatch_rows = $DB->get_records_sql(
            "SELECT u.id AS userid, u.firstname, u.lastname, u.username, sea.email AS sem_email
             FROM   {user} u
             JOIN   {local_studentemail_accounts} sea ON sea.userid = u.id
             WHERE  u.suspended = 1 AND u.deleted = 0 AND sea.status = ?",
            [self::STATUS_ACTIVE]
        );

        $mismatches = [];
        foreach ($mismatch_rows as $r) {
            $mismatches[] = [
                'userid'    => (int)$r->userid,
                'name'      => trim($r->firstname . ' ' . $r->lastname),
                'username'  => $r->username,
                'sem_email' => $r->sem_email,
            ];
        }

        // 5. Students whose Moodle profile address is on the college domain but
        //    is not the mailbox the plugin has linked. Moodle mails one mailbox
        //    while the mailbox client signs in to the other, so the student
        //    never sees their own mail.
        $splits = [];
        $domain = strtolower(trim((string)get_config('local_studentemail', 'email_domain')));
        if ($domain !== '') {
            $splitsql = "SELECT sea.id,
                                u.id AS userid,
                                u.firstname,
                                u.lastname,
                                u.username,
                                u.email AS moodle_email,
                                sea.email AS sem_email
                           FROM {user} u
                           JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
                          WHERE u.deleted = 0
                            AND sea.email IS NOT NULL
                            AND sea.email <> ''
                            AND " . $DB->sql_like('u.email', ':domain', false) . "
                            AND LOWER(u.email) <> LOWER(sea.email)";
            $split_rows = $DB->get_records_sql($splitsql, ['domain' => '%@' . $domain]);
            foreach ($split_rows as $r) {
                $splits[] = [
                    'userid'       => (int)$r->userid,
                    'name'         => trim($r->firstname . ' ' . $r->lastname),
                    'username'     => $r->username,
                    'moodle_email' => $r->moodle_email,
                    'sem_email'    => $r->sem_email,
                ];
            }
        }

        return [
            'success'    => true,
            'duplicates' => $duplicates,
            'multi'      => $multi,
            'orphans'    => $orphans,
            'mismatches' => $mismatches,
            'splits'     => $splits,
        ];
    }

    // =========================================================================
    // Stats / Dashboard
    // =========================================================================

    /**
     * Get dashboard statistics.
     */
    public function get_stats(): array {
        global $DB;

        $total_students = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {user} WHERE deleted = 0 AND id > 1"
        );

        $counts = $DB->get_records_sql(
            "SELECT status, COUNT(*) AS cnt FROM {local_studentemail_accounts} GROUP BY status"
        );

        $active    = 0;
        $suspended = 0;
        $archived  = 0;
        $error     = 0;
        $total_provisioned = 0;

        foreach ($counts as $row) {
            $total_provisioned += (int)$row->cnt;
            if ($row->status === self::STATUS_ACTIVE)    { $active    = (int)$row->cnt; }
            if ($row->status === self::STATUS_SUSPENDED) { $suspended = (int)$row->cnt; }
            if ($row->status === self::STATUS_ARCHIVED)  { $archived  = (int)$row->cnt; }
            if ($row->status === self::STATUS_ERROR)     { $error     = (int)$row->cnt; }
        }

        $missing = max(0, $total_students - $total_provisioned);

        return [
            'total'     => $total_students,
            'active'    => $active,
            'missing'   => $missing,
            'suspended' => $suspended,
            'archived'  => $archived,
            'error'     => $error,
        ];
    }

    /**
     * Get paginated list of students with email status.
     *
     * @param string $search   Search term (name, username, email)
     * @param string $filter   Status filter: all|active|missing|suspended|archived|error
     * @param int    $page     Page number (0-based)
     * @param int    $perpage  Records per page
     * @return array ['records'=>array, 'total'=>int]
     */
    public function get_accounts(string $search = '', string $filter = 'all', int $page = 0, int $perpage = 50): array {
        global $DB;

        $params = [];
        $where  = ['u.deleted = 0', 'u.id > 1'];

        if (!empty($search)) {
            $like = $DB->sql_like_escape($search);
            $where[] = '(' .
                $DB->sql_like('u.firstname', '?', false) . ' OR ' .
                $DB->sql_like('u.lastname', '?', false) . ' OR ' .
                $DB->sql_like('u.username', '?', false) . ' OR ' .
                $DB->sql_like('sea.email', '?', false) .
            ')';
            $params = array_merge($params, ["%{$like}%", "%{$like}%", "%{$like}%", "%{$like}%"]);
        }

        // Status filter.
        if ($filter === 'missing') {
            $where[] = 'sea.id IS NULL';
        } else if ($filter !== 'all' && in_array($filter, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_ARCHIVED, self::STATUS_ERROR])) {
            $where[] = 'sea.status = ?';
            $params[] = $filter;
        }

        $sql_where = implode(' AND ', $where);

        $sql = "SELECT u.id, u.firstname, u.lastname, u.username, u.idnumber, u.email AS moodle_email,
                       u.suspended AS moodle_suspended,
                       sea.id AS account_id, sea.email AS provisioned_email, sea.status, sea.timecreated AS email_created, sea.notes
                FROM {user} u
                LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
                WHERE {$sql_where}
                ORDER BY u.lastname ASC, u.firstname ASC";

        $total   = $DB->count_records_sql("SELECT COUNT(*) FROM {user} u LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id WHERE {$sql_where}", $params);
        $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

        return [
            'records' => array_values($records),
            'total'   => $total,
        ];
    }

    // =========================================================================
    // Account detail (for webmail.php)
    // =========================================================================

    /**
     * Get a student's provisioned email and decrypted password.
     */
    public function get_account_for_user(int $userid): ?array {
        global $DB;
        $record = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if (!$record) {
            return null;
        }
        return [
            'email'    => $record->email,
            'password' => $this->decrypt_password($record->emailpassword),
            'status'   => $record->status,
        ];
    }

    // =========================================================================
    // Generate CSV report
    // =========================================================================

    public function generate_csv_report(): string {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT u.firstname, u.lastname, u.username, u.idnumber, u.email AS moodle_email,
                    sea.email AS provisioned_email, sea.status, sea.timecreated, sea.notes
             FROM {user} u
             LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
             WHERE u.deleted = 0 AND u.id > 1
             ORDER BY u.lastname ASC, u.firstname ASC"
        );

        $lines   = [];
        $lines[] = 'First Name,Last Name,Username,Student ID,Moodle Email,College Email,Status,Created,Notes';
        foreach ($records as $row) {
            $lines[] = implode(
                ',',
                [
                    '"' . str_replace('"', '""', $row->firstname) . '"',
                    '"' . str_replace('"', '""', $row->lastname) . '"',
                    '"' . $row->username . '"',
                    '"' . $row->idnumber . '"',
                    '"' . $row->moodle_email . '"',
                    '"' . ($row->provisioned_email ?? '') . '"',
                    '"' . ($row->status ?? 'none') . '"',
                    '"' . ($row->timecreated ? date('Y-m-d', $row->timecreated) : '') . '"',
                    '"' . str_replace('"', '""', ($row->notes ?? '')) . '"',
                ]
            );
        }

        return implode("\n", $lines);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Generate the local part (before @) for a user's email address.
     */
    /**
     * Every mailbox on the mail server, keyed by lowercase local part.
     *
     * Cached for the life of the request: create_missing() provisions hundreds
     * of students in one run, and asking cPanel once per student would be both
     * slow and liable to hit the API rate limit.
     *
     * @param  string $domain The configured college mail domain.
     * @return array [localpart => full email address]
     */
    private function get_server_mailboxes(string $domain): array {
        if ($this->server_mailboxes !== null) {
            return $this->server_mailboxes;
        }
        $this->server_mailboxes = [];

        $listed = $this->api->list_accounts();
        if (empty($listed['success']) || empty($listed['accounts'])) {
            return $this->server_mailboxes;
        }

        foreach ($listed['accounts'] as $account) {
            $raw = '';
            foreach (['login', 'user', 'email'] as $field) {
                if (!empty($account[$field])) {
                    $raw = (string)$account[$field];
                    break;
                }
            }
            if ($raw === '') {
                continue;
            }
            $localpart = strpos($raw, '@') !== false ? substr($raw, 0, strpos($raw, '@')) : $raw;
            $localpart = strtolower(trim($localpart));
            if ($localpart !== '') {
                $this->server_mailboxes[$localpart] = $localpart . '@' . $domain;
            }
        }

        return $this->server_mailboxes;
    }

    /**
     * Build the local parts this user's mailbox could reasonably be named,
     * in priority order.
     *
     * @param  \stdClass $user   The Moodle user.
     * @param  string    $domain The configured college mail domain.
     * @return array Lowercase local parts, most specific first.
     */
    private function build_match_candidates(\stdClass $user, string $domain): array {
        $candidates = [];
        $clean = function (string $value): string {
            return strtolower(preg_replace('/[^a-z0-9._-]/i', '', $value));
        };

        // 1. The address already in the Moodle profile, but only when it is on
        //    the college domain. This is where Moodle sends the student's mail,
        //    so it is the strongest signal of which mailbox is really theirs.
        //    A personal address (Gmail and the like) is deliberately ignored.
        if (!empty($user->email) && $domain !== '') {
            $parts = explode('@', strtolower(trim($user->email)));
            if (count($parts) === 2 && $parts[1] === strtolower(trim($domain)) && $parts[0] !== '') {
                $candidates[] = $parts[0];
            }
        }

        // 2. Moodle username — the local part when it looks like an address,
        //    then the sanitised username itself.
        if (!empty($user->username)) {
            if (strpos($user->username, '@') !== false) {
                $localpartonly = strtolower(strtok($user->username, '@'));
                if ($localpartonly !== '') {
                    $candidates[] = $localpartonly;
                }
            }
            $sanitised = $clean($user->username);
            if ($sanitised !== '') {
                $candidates[] = $sanitised;
            }
        }

        // 3. Moodle ID number.
        if (!empty($user->idnumber)) {
            $idnumber = $clean($user->idnumber);
            if ($idnumber !== '') {
                $candidates[] = $idnumber;
            }
        }

        // 4. The configured address format, unless it is the sequential
        //    autonumber format, which can never match an existing mailbox.
        $format = $this->get_format();
        if ($format !== self::FORMAT_AUTONUMBER) {
            $formatted = '';
            if ($format === self::FORMAT_USERNAME && !empty($user->username)) {
                $formatted = $clean($user->username);
            } else if ($format === self::FORMAT_IDNUMBER && !empty($user->idnumber)) {
                $formatted = $clean($user->idnumber);
            } else if ($format === self::FORMAT_FIRSTLAST) {
                $formatted = strtolower(preg_replace('/[^a-z]/i', '', $user->firstname))
                    . '.' . strtolower(preg_replace('/[^a-z]/i', '', $user->lastname));
            }
            if ($formatted !== '' && $formatted !== '.') {
                $candidates[] = $formatted;
            }
        }

        // 5. The firstname.lastname fallback.
        $firstlast = strtolower(preg_replace('/[^a-z]/i', '', $user->firstname))
            . '.' . strtolower(preg_replace('/[^a-z]/i', '', $user->lastname));
        if (strlen($firstlast) > 1) {
            $candidates[] = $firstlast;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Find a mailbox already on the mail server that belongs to this user.
     *
     * Only exact local-part matches are accepted, and a mailbox already linked
     * to a different Moodle user is never returned, so this can not attach a
     * student to somebody else's mail.
     *
     * @param  \stdClass $user   The Moodle user.
     * @param  string    $domain The configured college mail domain.
     * @return string Full email address, or '' when there is no safe match.
     */
    public function find_existing_mailbox(\stdClass $user, string $domain): string {
        global $DB;

        if ($domain === '' || !$this->api->is_configured()) {
            return '';
        }

        $onserver = $this->get_server_mailboxes($domain);
        if (empty($onserver)) {
            return '';
        }

        // A mailbox already linked to somebody else is off limits.
        $claimed = [];
        $linkedrows = $DB->get_records('local_studentemail_accounts', null, '', 'userid, email');
        foreach ($linkedrows as $row) {
            if (!empty($row->email) && (int)$row->userid !== (int)$user->id) {
                $claimed[strtolower($row->email)] = true;
            }
        }

        foreach ($this->build_match_candidates($user, $domain) as $candidate) {
            if (isset($onserver[$candidate]) && !isset($claimed[strtolower($onserver[$candidate])])) {
                return $onserver[$candidate];
            }
        }

        return '';
    }

    private function generate_localpart(\stdClass $user): string {
        $format = $this->get_format();

        switch ($format) {
            case self::FORMAT_USERNAME:
                return strtolower(preg_replace('/[^a-z0-9._-]/i', '', $user->username));

            case self::FORMAT_IDNUMBER:
                return strtolower(preg_replace('/[^a-z0-9._-]/i', '', $user->idnumber));

            case self::FORMAT_FIRSTLAST:
                $first = strtolower(preg_replace('/[^a-z]/i', '', $user->firstname));
                $last  = strtolower(preg_replace('/[^a-z]/i', '', $user->lastname));
                return $first . '.' . $last;

            case self::FORMAT_AUTONUMBER:
            default:
                $prefix = strtolower(trim(get_config('local_studentemail', 'email_prefix') ?: ''));
                $number = $this->claim_next_number();
                return $prefix . $number;
        }
    }

    private function get_format(): string {
        return get_config('local_studentemail', 'email_format') ?: self::FORMAT_AUTONUMBER;
    }

    /**
     * Atomically claim the next sequential number and increment the counter.
     *
     * Uses Moodle's lock API to prevent race conditions when two students
     * enrol simultaneously — without a lock both would read the same counter
     * value and both get assigned the same email address.
     */
    private function claim_next_number(): int {
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_studentemail');
        $lock = $lockfactory->get_lock('email_number_claim', 15); // Wait up to 15 seconds.
        if (!$lock) {
            throw new \moodle_exception('error_lock', 'local_studentemail',
                '', null, 'Could not acquire email numbering lock after 15 seconds.');
        }
        try {
            $next = (int)(get_config('local_studentemail', 'email_next_number') ?: 5001);
            set_config('email_next_number', $next + 1, 'local_studentemail');
            return $next;
        } finally {
            $lock->release();
        }
    }

    private function peek_current_number(): int {
        return (int)(get_config('local_studentemail', 'email_next_number') ?: 5001) - 1;
    }

    /**
     * Save or update an account record in the DB.
     */
    private function save_account_record(
        int $userid, string $email, string $password, ?int $studentnumber,
        string $status, int $quota_mb, string $notes
    ): void {
        global $DB;

        $now = time();
        $record = (object)[
            'userid'        => $userid,
            'email'         => $email,
            'emailpassword' => !empty($password) ? $this->encrypt_password($password) : '',
            'studentnumber' => $studentnumber,
            'status'        => $status,
            'quota_mb'      => $quota_mb,
            'notes'         => $notes,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ];

        $existing = $DB->get_record('local_studentemail_accounts', ['userid' => $userid]);
        if ($existing) {
            $record->id = $existing->id;
            $record->timecreated = $existing->timecreated;
            $DB->update_record('local_studentemail_accounts', $record);
        } else {
            $DB->insert_record('local_studentemail_accounts', $record);
        }
    }

    /**
     * Extract the local part from a full email address.
     */
    private function email_to_localpart(string $email): string {
        return explode('@', $email)[0] ?? '';
    }

    /**
     * Generate a secure random password.
     * 12 chars: uppercase + lowercase + digits + symbols.
     */
    public function generate_password(): string {
        // A cPanel password must contain at least one character from each
        // of the four classes: lowercase, uppercase, digit, special.
        // We guarantee this by picking one from each class first, then filling
        // randomly from the full set, then shuffling.
        $lower   = 'abcdefghjkmnpqrstuvwxyz';
        $upper   = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $special = '!@#$%';
        $all     = $lower . $upper . $digits . $special;

        // One guaranteed character from each required class.
        $chars = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        // Fill to 14 chars total.
        for ($i = 4; $i < 14; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle so the class-guaranteed chars aren't always at the start.
        shuffle($chars);
        return implode('', $chars);
    }

    /**
     * Encrypt a password with AES-256-CBC using a site-derived key.
     *
     * Stores as: "v2:" + base64( random_iv_16bytes + ciphertext )
     * The random IV ensures identical passwords produce different ciphertext,
     * preventing correlation across accounts.
     */
    public function encrypt_password(string $password): string {
        $key = $this->derive_key();
        $iv  = random_bytes(16); // Random IV — different every call.
        $enc = openssl_encrypt($password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return 'v2:' . base64_encode($iv . $enc);
    }

    /**
     * Decrypt an AES-256-CBC encrypted password.
     *
     * Supports both the current v2 format (random IV prefixed) and the
     * legacy v1 format (static IV) for backwards compatibility.
     */
    public function decrypt_password(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }
        $key = $this->derive_key();

        // Version 2 format: "v2:" prefix + base64(16-byte IV + ciphertext).
        if (strpos($encrypted, 'v2:') === 0) {
            $raw = base64_decode(substr($encrypted, 3));
            if (strlen($raw) > 16) {
                $iv  = substr($raw, 0, 16);
                $enc = substr($raw, 16);
                $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                if ($dec !== false) {
                    return $dec;
                }
            }
            return ''; // Corrupted v2 blob.
        }

        // Legacy v1 format: base64(openssl_encrypted) with static IV.
        // Present only on sites that installed before this fix. Safe to keep.
        $iv_legacy = substr(hash('sha256', 'studentemail_iv_v1'), 0, 16);
        return openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, $iv_legacy) ?: '';
    }

    /**
     * Derive a 32-byte AES key from the Moodle site secret.
     */
    private function derive_key(): string {
        global $CFG;
        $salt = $CFG->passwordsaltmain ?? $CFG->wwwroot;
        return substr(hash('sha256', $salt . 'local_studentemail_key_v1'), 0, 32);
    }
}
