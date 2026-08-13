<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX handler for the Student Email Manager admin dashboard.
 *
 * All requests require the local/studentemail:manage capability and a
 * valid Moodle session key (sesskey).
 *
 * Actions:
 *   create_email         — provision one student's email
 *   suspend_email        — suspend one student's email
 *   restore_email        — restore one student's email
 *   archive_email        — archive one student's email
 *   reset_password       — generate new password for one student
 *   create_missing       — bulk create all missing accounts
 *   suspend_leavers      — bulk suspend accounts for suspended users
 *   get_accounts         — paginated/filtered student list (JSON)
 *   get_stats            — dashboard stats
 *   test_connection      — verify cPanel connectivity
 *   download_report      — not handled here; see dashboard.php download link
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/studentemail/classes/cpanel_api.php');
require_once($CFG->dirroot . '/local/studentemail/classes/email_manager.php');

use local_studentemail\email_manager;

require_login();
require_sesskey();

$systemcontext = context_system::instance();
require_capability('local/studentemail:manage', $systemcontext);

// Close session early so admin actions don't block the browser.
\core\session\manager::write_close();

header('Content-Type: application/json');

$action = required_param('action', PARAM_ALPHANUMEXT);

$manager = new email_manager();

try {
    switch ($action) {

        // -----------------------------------------------------------
        case 'create_email':
            $userid = required_param('userid', PARAM_INT);
            $user   = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $result = $manager->create_email($user);
            echo json_encode($result);
            break;

        // -----------------------------------------------------------
        case 'suspend_email':
            $userid = required_param('userid', PARAM_INT);
            echo json_encode($manager->suspend_email($userid));
            break;

        // -----------------------------------------------------------
        case 'restore_email':
            $userid = required_param('userid', PARAM_INT);
            echo json_encode($manager->restore_email($userid));
            break;

        // -----------------------------------------------------------
        case 'archive_email':
            $userid = required_param('userid', PARAM_INT);
            echo json_encode($manager->archive_email($userid));
            break;

        // -----------------------------------------------------------
        case 'reset_password':
            $userid = required_param('userid', PARAM_INT);
            echo json_encode($manager->reset_password($userid));
            break;

        // -----------------------------------------------------------
        case 'resend_welcome':
            $userid = required_param('userid', PARAM_INT);
            echo json_encode($manager->resend_welcome($userid));
            break;

        // -----------------------------------------------------------
        case 'import_existing':
            echo json_encode($manager->import_existing());
            break;

        // -----------------------------------------------------------
        case 'link_account':
            $userid = required_param('userid', PARAM_INT);
            $email  = required_param('email', PARAM_EMAIL);
            echo json_encode($manager->link_account($userid, $email));
            break;

        // -----------------------------------------------------------
        case 'create_missing':
            echo json_encode($manager->create_missing());
            break;

        // -----------------------------------------------------------
        case 'suspend_leavers':
            echo json_encode($manager->suspend_leavers());
            break;

        // -----------------------------------------------------------
        case 'get_accounts':
            $search  = optional_param('search', '', PARAM_TEXT);
            $filter  = optional_param('filter', 'all', PARAM_ALPHANUMEXT);
            $page    = optional_param('page', 0, PARAM_INT);
            $perpage = optional_param('perpage', 50, PARAM_INT);

            $data = $manager->get_accounts($search, $filter, $page, $perpage);

            // Add full name + formatted date for JS rendering.
            foreach ($data['records'] as &$row) {
                $row->fullname     = trim($row->firstname . ' ' . $row->lastname);
                $row->email_date   = !empty($row->email_created) ? date('d M Y', $row->email_created) : '';
                $row->status_label = $row->status ?: 'none';
            }
            unset($row);

            echo json_encode(['success' => true, 'data' => $data]);
            break;

        // -----------------------------------------------------------
        case 'get_stats':
            echo json_encode(['success' => true, 'stats' => $manager->get_stats()]);
            break;

        // -----------------------------------------------------------
        case 'get_data_quality':
            echo json_encode($manager->get_data_quality());
            break;

        // -----------------------------------------------------------
        // Diagnostic: shows raw cPanel API response + candidate matching
        // for the first 5 unlinked Moodle users. Admin-only debug tool.
        case 'diagnose_import':
            $api_diag = new \local_studentemail\cpanel_api();
            $diag     = $api_diag->diagnose_list_accounts();

            // Also grab first 5 unlinked Moodle users + their candidates.
            $unlinked = $DB->get_records_sql(
                "SELECT u.id, u.username, u.firstname, u.lastname, u.email AS moodle_email
                 FROM   {user} u
                 LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
                 WHERE  u.deleted = 0 AND u.id > 1 AND sea.id IS NULL
                 ORDER BY u.id
                 LIMIT 5"
            );

            $user_samples = [];
            foreach ($unlinked as $u) {
                $candidates = [];
                if (!empty($u->username)) {
                    if (strpos($u->username, '@') !== false) {
                        $candidates[] = strtolower(substr($u->username, 0, strpos($u->username, '@')));
                    }
                    $san = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $u->username));
                    if ($san !== '' && !in_array($san, $candidates)) {
                        $candidates[] = $san;
                    }
                }
                if (!empty($u->idnumber ?? '')) {
                    $candidates[] = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $u->idnumber));
                }
                $first = strtolower(preg_replace('/[^a-z]/i', '', $u->firstname));
                $last  = strtolower(preg_replace('/[^a-z]/i', '', $u->lastname));
                if (strlen($first . $last) > 1) {
                    $candidates[] = $first . '.' . $last;
                }
                $user_samples[] = [
                    'userid'       => $u->id,
                    'username'     => $u->username,
                    'moodle_email' => $u->moodle_email,
                    'candidates'   => array_values(array_unique(array_filter($candidates))),
                ];
            }

            $diag['unlinked_samples'] = $user_samples;
            $diag['unlinked_total']   = (int)$DB->count_records_sql(
                "SELECT COUNT(*) FROM {user} u
                 LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
                 WHERE u.deleted = 0 AND u.id > 1 AND sea.id IS NULL"
            );

            // SQL: emails already claimed by multiple userids (violates email_idx UNIQUE).
            $email_dups = $DB->get_records_sql(
                "SELECT email, COUNT(*) AS cnt, GROUP_CONCAT(userid ORDER BY userid SEPARATOR ', ') AS userids
                 FROM   {local_studentemail_accounts}
                 WHERE  email IS NOT NULL AND email != ''
                 GROUP BY email
                 HAVING COUNT(*) > 1"
            );
            $diag['sql_email_duplicates'] = array_values($email_dups);

            // SQL: Moodle users with identical firstname+lastname (same firstname.lastname candidate → collision risk).
            $name_dups = $DB->get_records_sql(
                "SELECT u.firstname, u.lastname, COUNT(*) AS cnt,
                        GROUP_CONCAT(u.id ORDER BY u.id SEPARATOR ', ') AS userids,
                        GROUP_CONCAT(u.username ORDER BY u.id SEPARATOR ', ') AS usernames
                 FROM   {user} u
                 LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
                 WHERE  u.deleted = 0 AND u.id > 1 AND sea.id IS NULL
                 GROUP BY u.firstname, u.lastname
                 HAVING COUNT(*) > 1
                 ORDER BY cnt DESC
                 LIMIT 20"
            );
            $diag['sql_name_duplicates'] = array_values($name_dups);

            // SQL: emails in local_studentemail_accounts that DO NOT exist in cPanel
            // (orphaned records — cPanel mailbox was deleted but plugin record remains).
            $diag['sql_total_linked'] = (int)$DB->count_records('local_studentemail_accounts');

            // SQL: status breakdown of currently linked accounts.
            $status_rows = $DB->get_records_sql(
                "SELECT status, COUNT(*) AS cnt FROM {local_studentemail_accounts} GROUP BY status"
            );
            $diag['sql_status_breakdown'] = array_values($status_rows);

            echo json_encode($diag);
            break;

        // -----------------------------------------------------------
        // Dry-run import: shows ALL remaining unlinked users, their candidates,
        // whether they matched a cPanel account, and why they didn't if not.
        // No DB writes — admin-only diagnostic tool.
        case 'dry_run_import':
            $dry_api      = new \local_studentemail\cpanel_api();
            $dry_result   = $dry_api->list_accounts();
            if (!$dry_result['success']) {
                echo json_encode(['success' => false, 'message' => $dry_result['message']]);
                break;
            }
            $dry_domain = strtolower(trim(get_config('local_studentemail', 'email_domain')));
            $dry_map    = [];
            foreach (($dry_result['accounts'] ?? []) as $acct) {
                $acct = (array)$acct;
                $raw  = strtolower(trim($acct['login'] ?? ''));
                $lp   = strpos($raw, '@') !== false ? substr($raw, 0, strpos($raw, '@')) : $raw;
                if (empty($lp)) {
                    $raw = strtolower(trim($acct['user'] ?? ''));
                    $lp  = strpos($raw, '@') !== false ? substr($raw, 0, strpos($raw, '@')) : $raw;
                }
                if (empty($lp) && !empty($acct['email'])) {
                    $raw = strtolower(trim($acct['email']));
                    $lp  = strpos($raw, '@') !== false ? substr($raw, 0, strpos($raw, '@')) : $raw;
                }
                if (!empty($lp)) {
                    $dry_map[$lp] = $lp . '@' . $dry_domain;
                }
            }

            $fmt = get_config('local_studentemail', 'email_format') ?: 'autonumber';

            $dry_unlinked = $DB->get_records_sql(
                "SELECT u.id, u.username, u.firstname, u.lastname, u.email AS moodle_email, u.idnumber
                 FROM   {user} u
                 LEFT JOIN {local_studentemail_accounts} sea ON sea.userid = u.id
                 WHERE  u.deleted = 0 AND u.id > 1 AND sea.id IS NULL
                 ORDER BY u.lastname, u.firstname, u.id"
            );

            $dry_rows    = [];
            $matched_ct  = 0;
            $no_match_ct = 0;
            foreach ($dry_unlinked as $u) {
                $candidates = [];
                if (!empty($u->username)) {
                    if (strpos($u->username, '@') !== false) {
                        $candidates[] = strtolower(substr($u->username, 0, strpos($u->username, '@')));
                    }
                    $san = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $u->username));
                    if ($san !== '' && !in_array($san, $candidates)) {
                        $candidates[] = $san;
                    }
                }
                if (!empty($u->idnumber)) {
                    $candidates[] = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $u->idnumber));
                }
                if ($fmt !== 'autonumber') {
                    if ($fmt === 'username' && !empty($u->username)) {
                        $candidates[] = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $u->username));
                    } elseif ($fmt === 'idnumber' && !empty($u->idnumber)) {
                        $candidates[] = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $u->idnumber));
                    } elseif ($fmt === 'firstlast') {
                        $first = strtolower(preg_replace('/[^a-z]/i', '', $u->firstname));
                        $last  = strtolower(preg_replace('/[^a-z]/i', '', $u->lastname));
                        $candidates[] = $first . '.' . $last;
                    }
                }
                $first = strtolower(preg_replace('/[^a-z]/i', '', $u->firstname));
                $last  = strtolower(preg_replace('/[^a-z]/i', '', $u->lastname));
                if (strlen($first . $last) > 1) {
                    $candidates[] = $first . '.' . $last;
                }
                $candidates = array_values(array_unique(array_filter($candidates)));

                $matched_email = null;
                $matched_by    = null;
                foreach ($candidates as $c) {
                    if (isset($dry_map[$c])) {
                        $matched_email = $dry_map[$c];
                        $matched_by    = $c;
                        break;
                    }
                }

                if ($matched_email) {
                    $matched_ct++;
                } else {
                    $no_match_ct++;
                }

                $dry_rows[] = [
                    'userid'        => (int)$u->id,
                    'username'      => $u->username,
                    'fullname'      => trim($u->firstname . ' ' . $u->lastname),
                    'moodle_email'  => $u->moodle_email,
                    'candidates'    => $candidates,
                    'matched_email' => $matched_email,
                    'matched_by'    => $matched_by,
                ];
            }

            echo json_encode([
                'success'       => true,
                'cpanel_count'  => count($dry_map),
                'unlinked_total'=> count($dry_rows),
                'would_match'   => $matched_ct,
                'no_match'      => $no_match_ct,
                'rows'          => $dry_rows,
            ]);
            break;

        // -----------------------------------------------------------
        case 'list_cpanel_accounts':
            $api = new \local_studentemail\cpanel_api();
            $result = $api->list_accounts();
            if (!$result['success']) {
                echo json_encode($result);
                break;
            }
            // Build a flat list of emails from cPanel.
            $cpanel_emails = [];
            foreach (($result['accounts'] ?? []) as $acct) {
                $email = '';
                if (!empty($acct['email'])) {
                    $email = $acct['email'];
                } elseif (!empty($acct['login']) && !empty($acct['domain'])) {
                    $email = $acct['login'] . '@' . $acct['domain'];
                } elseif (!empty($acct['user']) && !empty($acct['domain'])) {
                    $email = $acct['user'] . '@' . $acct['domain'];
                }
                if (!empty($email) && strpos($email, '@') !== false) {
                    $cpanel_emails[] = strtolower(trim($email));
                }
            }
            $cpanel_emails = array_unique($cpanel_emails);
            sort($cpanel_emails);
            // Remove already-linked emails so only unlinked ones appear.
            if (!empty($cpanel_emails)) {
                [$in_sql, $in_params] = $DB->get_in_or_equal($cpanel_emails, SQL_PARAMS_NAMED);
                $linked = $DB->get_fieldset_select(
                    'local_studentemail_accounts',
                    'email',
                    "LOWER(email) $in_sql",
                    $in_params
                );
                $linked = array_map('strtolower', $linked);
                $cpanel_emails = array_values(array_filter($cpanel_emails, function ($e) use ($linked) {
                    return !in_array($e, $linked, true);
                }));
            }
            echo json_encode(['success' => true, 'accounts' => $cpanel_emails]);
            break;

        // -----------------------------------------------------------
        case 'test_connection':
            $api    = new \local_studentemail\cpanel_api();
            $result = $api->test_connection();
            echo json_encode($result);
            break;

        // -----------------------------------------------------------
        case 'test_smtp':
            require_once($CFG->dirroot . '/lib/phpmailer/moodle_phpmailer.php');
            require_once($CFG->dirroot . '/local/studentemail/classes/email_manager.php');

            $smtp_host = trim(get_config('local_studentemail', 'smtp_host') ?? '');
            $smtp_port = (int)(get_config('local_studentemail', 'smtp_port') ?? 587);
            $smtp_enc  = get_config('local_studentemail', 'smtp_encryption') ?: 'tls';

            if (empty($smtp_host)) {
                echo json_encode(['success' => false, 'message' => 'SMTP host is not configured. Go to Settings and enter your SMTP server hostname.']);
                break;
            }

            // Grab one active account to use as auth credentials.
            $account_row = $DB->get_record_select(
                'local_studentemail_accounts',
                "status = 'active'",
                [],
                'id, email, emailpassword',
                IGNORE_MULTIPLE
            );

            if (!$account_row) {
                echo json_encode(['success' => false, 'message' => 'No active student email accounts found. Create or link at least one account before testing SMTP.']);
                break;
            }

            $mgr_test = new email_manager();
            $smtp_user = $account_row->email;
            $smtp_pass = $mgr_test->decrypt_password($account_row->emailpassword);

            $mail = new \moodle_phpmailer();
            $mail->isSMTP();
            $mail->Host      = $smtp_host;
            $mail->Port      = $smtp_port;
            $mail->SMTPAuth  = true;
            $mail->Username  = $smtp_user;
            $mail->Password  = $smtp_pass;
            $mail->Timeout   = 10;

            switch ($smtp_enc) {
                case 'ssl':
                    $mail->SMTPSecure  = 'ssl';
                    break;
                case 'tls':
                    $mail->SMTPSecure  = 'tls';
                    break;
                default:
                    $mail->SMTPSecure  = '';
                    $mail->SMTPAutoTLS = false;
            }

            if (!empty(get_config('auth_studentemail', 'imap_novalidate'))) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $ok = @$mail->smtpConnect();
            if ($ok) {
                $mail->smtpClose();
                echo json_encode([
                    'success' => true,
                    'message' => "SMTP connection successful! Connected to {$smtp_host}:{$smtp_port} using " . strtoupper($smtp_enc ?: 'plain') . " encryption and authenticated as {$smtp_user}.",
                ]);
            } else {
                $err = $mail->ErrorInfo ?: 'Connection refused or authentication failed.';
                echo json_encode([
                    'success' => false,
                    'message' => "SMTP connection failed: {$err}",
                ]);
            }
            break;

        // -----------------------------------------------------------
        case 'fix_missing_passwords':
            // Reset cPanel passwords for all active accounts that have no stored password.
            // Safe to run multiple times — only touches accounts where emailpassword is empty/null.
            $missing = $DB->get_records_select(
                'local_studentemail_accounts',
                "status = 'active' AND (emailpassword IS NULL OR emailpassword = '')",
                [],
                '',
                'id, userid, email, emailpassword'
            );

            if (empty($missing)) {
                echo json_encode(['success' => true, 'message' => 'All active accounts already have a stored password. Nothing to fix.', 'fixed' => 0, 'errors' => 0]);
                break;
            }

            $fixed  = 0;
            $errors = 0;
            $error_details = [];

            foreach ($missing as $row) {
                $result = $manager->reset_password((int)$row->userid);
                if ($result['success']) {
                    $fixed++;
                } else {
                    $errors++;
                    $error_details[] = $row->email . ': ' . ($result['message'] ?? 'unknown error');
                }
            }

            $msg = "Fixed {$fixed} account(s).";
            if ($errors > 0) {
                $msg .= " {$errors} failed: " . implode('; ', array_slice($error_details, 0, 5));
            }
            echo json_encode(['success' => true, 'message' => $msg, 'fixed' => $fixed, 'errors' => $errors]);
            break;

        // -----------------------------------------------------------
        case 'bulk_reset_all_passwords':
            // Reset cPanel passwords for ALL active accounts — not just missing ones.
            // Useful after bulk-linking via "Link Existing" where passwords are "managed externally".
            $all_active = $DB->get_records_select(
                'local_studentemail_accounts',
                "status = 'active'",
                [],
                '',
                'id, userid, email'
            );

            if (empty($all_active)) {
                echo json_encode(['success' => true, 'message' => 'No active accounts found.', 'fixed' => 0, 'errors' => 0]);
                break;
            }

            $fixed  = 0;
            $errors = 0;
            $error_details = [];

            foreach ($all_active as $row) {
                $result = $manager->reset_password((int)$row->userid);
                if ($result['success']) {
                    $fixed++;
                } else {
                    $errors++;
                    $error_details[] = $row->email . ': ' . ($result['message'] ?? 'unknown error');
                }
            }

            $msg = "Reset passwords for {$fixed} account(s).";
            if ($errors > 0) {
                $msg .= " {$errors} failed: " . implode('; ', array_slice($error_details, 0, 5));
            }
            echo json_encode(['success' => true, 'message' => $msg, 'fixed' => $fixed, 'errors' => $errors]);
            break;

        // -----------------------------------------------------------
        case 'test_imap':
        case 'test_imap_student':
            require_once($CFG->dirroot . '/local/studentemail/classes/email_manager.php');
            require_once($CFG->dirroot . '/local/studentemail/classes/imap_client.php');

            // Resolve IMAP settings (auth_studentemail preferred, fallback to local_studentemail).
            $imap_host = trim(get_config('auth_studentemail', 'imap_host') ?? '');
            if (empty($imap_host)) $imap_host = trim(get_config('local_studentemail', 'imap_host') ?? '');
            if (strpos($imap_host, ',') !== false) $imap_host = trim(explode(',', $imap_host)[0]);

            if (empty($imap_host)) {
                echo json_encode(['success' => false, 'message' => 'IMAP host is not configured. Go to Settings.']);
                break;
            }

            $imap_port  = (int)(get_config('auth_studentemail', 'imap_port')       ?: get_config('local_studentemail', 'imap_port')       ?: 993);
            $imap_type  = get_config('auth_studentemail', 'imap_type')              ?: (get_config('local_studentemail', 'imap_type')       ?: 'imap');
            $imap_enc   = get_config('auth_studentemail', 'imap_encryption')        ?: (get_config('local_studentemail', 'imap_encryption') ?: 'ssl');
            $novalidate = !empty(get_config('auth_studentemail', 'imap_novalidate')) || !empty(get_config('local_studentemail', 'imap_novalidate'));
            $strip_now  = !empty(get_config('auth_studentemail', 'strip_domain'))    || !empty(get_config('local_studentemail', 'imap_strip_domain'));

            $imap_flags    = '/' . $imap_type;
            if ($imap_enc === 'ssl')      $imap_flags .= '/ssl';
            elseif ($imap_enc === 'tls')  $imap_flags .= '/tls';
            if ($novalidate)              $imap_flags .= '/novalidate-cert';

            $server_prefix = '{' . $imap_host . ':' . $imap_port . $imap_flags . '}';

            // Resolve which account to test.
            $mgr_imap = new email_manager();

            if ($action === 'test_imap_student') {
                $test_userid  = required_param('userid', PARAM_INT);
                $imap_account = $DB->get_record('local_studentemail_accounts',
                    ['userid' => $test_userid], 'id, userid, email, emailpassword, status');
                if (!$imap_account) {
                    echo json_encode(['success' => false, 'message' => 'No email account record found for this student.']);
                    break;
                }
            } else {
                $imap_account = $DB->get_record_select('local_studentemail_accounts',
                    "status = 'active' AND emailpassword IS NOT NULL AND emailpassword != ''",
                    [], 'id, userid, email, emailpassword, status', IGNORE_MULTIPLE);
                if (!$imap_account) {
                    echo json_encode(['success' => false, 'message' => 'No active accounts with stored passwords. Reset PW on at least one student first.']);
                    break;
                }
            }

            $imap_email = $imap_account->email;
            $imap_pass  = $mgr_imap->decrypt_password($imap_account->emailpassword);

            if (empty($imap_pass)) {
                echo json_encode(['success' => false, 'message' => 'Cannot decrypt stored password for ' . s($imap_email) . '. Click Reset PW on this student first.']);
                break;
            }

            // Helper: open IMAP with a given username, list all folders + counts.
            $probe = function (string $username) use ($server_prefix, $imap_pass): array {
                $spec   = $server_prefix . 'INBOX';
                imap_errors(); // clear prior error stack
                $stream = @imap_open($spec, $username, $imap_pass, 0, 2, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

                if (!$stream) {
                    $err = '';
                    $errs = imap_errors();
                    if (is_array($errs)) $err = implode(' | ', $errs);
                    if (empty($err)) $err = imap_last_error() ?: 'Connection refused or authentication failed';
                    return ['connected' => false, 'error' => $err, 'username' => $username,
                            'spec' => $spec, 'inbox_count' => 0, 'folders' => []];
                }

                $inbox_count = imap_num_msg($stream);
                $raw_folders = @imap_list($stream, $server_prefix, '*') ?: [];
                $folders = [];
                foreach ($raw_folders as $fraw) {
                    $fname = str_replace($server_prefix, '', $fraw);
                    if (function_exists('mb_convert_encoding')) {
                        $fname = @mb_convert_encoding($fname, 'UTF-8', 'UTF7-IMAP') ?: $fname;
                    }
                    $reopened = @imap_reopen($stream, $fraw);
                    $fcount   = $reopened ? imap_num_msg($stream) : -1;
                    $folders[] = ['folder' => $fname, 'count' => $fcount];
                }
                @imap_reopen($stream, $server_prefix . 'INBOX');
                @imap_close($stream);

                return ['connected' => true, 'error' => '', 'username' => $username,
                        'spec' => $spec, 'inbox_count' => $inbox_count, 'folders' => $folders];
            };

            // Probe with current setting, then with the opposite.
            $user_full  = $imap_email;
            $user_local = preg_replace('/@.+$/', '', $imap_email);

            $username_a = $strip_now ? $user_local : $user_full;
            $username_b = $strip_now ? $user_full  : $user_local;
            $label_a    = $strip_now ? 'strip_domain=ON (localpart only — CURRENT)' : 'strip_domain=OFF (full email — CURRENT)';
            $label_b    = $strip_now ? 'strip_domain=OFF (full email — OPPOSITE)'   : 'strip_domain=ON (localpart only — OPPOSITE)';

            $result_a = $probe($username_a);
            $result_b = $probe($username_b);

            // Build recommendation.
            $fix = '';
            $overall_success = false;

            $inbox_a  = $result_a['connected'] ? $result_a['inbox_count'] : -1;
            $inbox_b  = $result_b['connected'] ? $result_b['inbox_count'] : -1;
            $total_a  = $result_a['connected'] ? array_sum(array_column($result_a['folders'], 'count')) : -1;
            $total_b  = $result_b['connected'] ? array_sum(array_column($result_b['folders'], 'count')) : -1;

            if (!$result_a['connected'] && !$result_b['connected']) {
                $fix = 'BOTH username formats fail to authenticate. The problem is not the username format — check: (1) IMAP host is exactly right, (2) port ' . $imap_port . ' + ' . strtoupper($imap_enc) . ' is correct, (3) click Reset PW on this student then test again.';
            } elseif ($inbox_a > 0) {
                $fix = 'Current setting is correct — INBOX has ' . $inbox_a . ' message(s) with the current username format. If Moodle still shows empty, click Reset PW on this student to refresh the stored password.';
                $overall_success = true;
            } elseif ($inbox_a === 0 && $inbox_b > 0) {
                $fix = 'FIX: Toggle "Strip domain from IMAP username" ' . ($strip_now ? 'OFF' : 'ON') . ' in Settings. Current format connects but INBOX = 0. Opposite format connects AND has ' . $inbox_b . ' message(s) in INBOX.';
            } elseif (!$result_a['connected'] && $inbox_b > 0) {
                $fix = 'FIX: Toggle "Strip domain from IMAP username" ' . ($strip_now ? 'OFF' : 'ON') . ' in Settings. Current format cannot connect at all. Opposite format connects and has ' . $inbox_b . ' message(s).';
            } elseif ($result_a['connected'] && $inbox_a === 0 && $total_a > 0) {
                $fix = 'Connected OK but INBOX is empty — messages exist in OTHER folders (total: ' . $total_a . '). cPanel webmail may display a different folder as the inbox. See folder list below.';
            } elseif ($result_a['connected'] && $total_a === 0 && $result_b['connected'] && $total_b === 0) {
                $fix = 'Both formats connect but both show 0 messages in ALL folders. The IMAP connection is reaching the wrong mail store entirely (e.g. a different server or virtual host). Confirm your IMAP host (' . $imap_host . ') is the same server as cPanel webmail.';
            } else {
                $fix = 'Connection succeeded but no messages found. See folder list for details.';
            }

            echo json_encode([
                'success'    => $overall_success,
                'email'      => $imap_email,
                'imap_host'  => $imap_host,
                'imap_port'  => $imap_port,
                'imap_flags' => $imap_flags,
                'strip_now'  => $strip_now,
                'label_a'    => $label_a,
                'label_b'    => $label_b,
                'result_a'   => $result_a,
                'result_b'   => $result_b,
                'fix'        => $fix,
                'message'    => $fix,
            ]);
            break;

        // -----------------------------------------------------------
        default:
            throw new moodle_exception('Invalid action: ' . $action);
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
