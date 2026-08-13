<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX handler for the Student Email mailbox page.
 *
 * Student-facing — no :manage capability required.
 * Checks that the logged-in user has an active provisioned email account.
 *
 * Actions:
 *   get_folder      — list messages in a folder (paginated)
 *   get_message     — fetch full message body
 *   send_message    — send an email via SMTP
 *   mark_read       — mark message read/unread
 *   delete_message  — delete a message
 *   get_unread      — get unread count
 *   get_folders     — list available folders
 *   get_attachment  — fetch attachment part as base64 (Phase 2)
 *   server_search   — IMAP SEARCH across full folder (Phase 2)
 *   move_message    — move message between folders (Phase 2)
 *   toggle_flag     — star/unstar a message (Phase 2)
 *   save_signature  — save student email signature to user preference (Phase 2)
 *   get_signature   — get student email signature from user preference (Phase 2)
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

// Diagnostic: surface hidden fatal errors in the browser/response during debugging.
@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
@error_reporting(E_ALL);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/studentemail/classes/email_manager.php');
require_once($CFG->dirroot . '/local/studentemail/classes/imap_client.php');

use local_studentemail\email_manager;
use local_studentemail\imap_client;

// ── Debug step helper ─────────────────────────────────────────────────────────
// Sets X-SEM-Step response header (visible in Chrome DevTools → Network →
// Headers for the mailbox_ajax.php request) AND writes to the file log.
// Subsequent calls overwrite the header value so the final value is always
// the last checkpoint PHP reached before dying or sending the response.
// ─────────────────────────────────────────────────────────────────────────────
function sem_step(int $n, string $label = '', string $logfile = ''): void {
    $val = $n . ($label !== '' ? ' ' . $label : '');
    if (!headers_sent()) {
        header('X-SEM-Step: ' . $val);
    }
    if ($logfile !== '') {
        file_put_contents($logfile, '[' . date('Y-m-d H:i:s') . "] STEP {$val}\n", FILE_APPEND | LOCK_EX);
    }
}

require_login();
sem_step(1, 'logged-in');

require_sesskey();
sem_step(2, 'sesskey-ok');

header('Content-Type: application/json');

// Close session so IMAP ops don't block other requests.
\core\session\manager::write_close();
sem_step(3, 'session-closed');

$action = required_param('action', PARAM_ALPHANUMEXT);
sem_step(4, 'action=' . $action);

// ── EARLY DIAGNOSTIC LOG ─────────────────────────────────────────────────────
// Written before ANY credential loading so we see the entry even if the
// password decrypt or imap_client constructor crashes.
// Path: $CFG->dataroot (Moodle data dir) — always writable by PHP, and
// visible in cPanel File Manager under your Moodle data folder.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'send_message') {
    $sem_log_early = $CFG->dataroot . '/sem_send_debug.txt';
    file_put_contents(
        $sem_log_early,
        '[' . date('Y-m-d H:i:s') . "] === NEW SEND REQUEST RECEIVED === action={$action} user={$USER->id}\n",
        FILE_APPEND | LOCK_EX
    );
}

// Signature actions don't need IMAP credentials.
if ($action === 'save_signature') {
    $sig = optional_param('signature', '', PARAM_RAW);
    // Limit to 2 KB.
    $sig = substr($sig, 0, 2048);
    set_user_preference('local_studentemail_signature', $sig);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'get_signature') {
    $sig = get_user_preferences('local_studentemail_signature', '');
    echo json_encode(['success' => true, 'signature' => $sig]);
    exit;
}

// Load the student's credentials.
sem_step(5, 'loading-credentials');
$manager = new email_manager();
$creds   = $manager->get_account_for_user($USER->id);
sem_step(6, 'credentials-loaded');

if (!$creds || $creds['status'] !== 'active') {
    echo json_encode(['success' => false, 'message' => 'No active email account found.']);
    exit;
}

$email    = $creds['email'];
$password = $creds['password'];

if (empty($email) || empty($password)) {
    // No stored password — common for accounts imported from cPanel before the plugin
    // knew the password. If the email address is known, auto-generate a new cPanel
    // password transparently so the student never sees an error.
    if (!empty($email)) {
        $auto_result = $manager->reset_password($USER->id);
        if ($auto_result['success']) {
            $password = $auto_result['new_password'];
        } else {
            echo json_encode(['success' => false, 'message' => 'Email credentials unavailable and auto-reset failed: ' . ($auto_result['message'] ?? 'unknown error') . '. Please ask your administrator to click "Fix Missing Passwords" in the Student Email Manager dashboard.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No email account found. Please ask your administrator to create or link your account in the Student Email Manager dashboard.']);
        exit;
    }
}

try {
    $client = new imap_client($email, $password);

    switch ($action) {

        // -----------------------------------------------------------------------
        case 'get_folder':
            $folder = optional_param('folder', 'INBOX', PARAM_TEXT);
            $limit  = min((int)optional_param('limit', 50, PARAM_INT), 200);
            $offset = max(0, (int)optional_param('offset', 0, PARAM_INT));
            $result = $client->get_messages($folder, $limit, $offset);
            $client->close();
            echo json_encode(['success' => true] + $result);
            break;

        // -----------------------------------------------------------------------
        case 'get_message':
            $msgno  = required_param('msgno', PARAM_INT);
            $folder = optional_param('folder', 'INBOX', PARAM_TEXT);
            $msg    = $client->get_message($msgno, $folder);
            $client->close();
            echo json_encode(['success' => true, 'message' => $msg]);
            break;

        // -----------------------------------------------------------------------
        case 'send_message':
            $to      = required_param('to', PARAM_TEXT);
            $cc      = optional_param('cc', '', PARAM_TEXT);
            $subject = required_param('subject', PARAM_TEXT);
            $body    = required_param('body', PARAM_RAW);

            // Validate TO recipients.
            $recipients = array_filter(array_map('trim', explode(',', $to)));
            foreach ($recipients as $r) {
                if (!validate_email($r)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid recipient address: ' . s($r)]);
                    exit;
                }
            }

            // Validate CC recipients (if provided).
            if (!empty($cc)) {
                $cc_list = array_filter(array_map('trim', explode(',', $cc)));
                foreach ($cc_list as $r) {
                    if (!validate_email($r)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid CC address: ' . s($r)]);
                        exit;
                    }
                }
            }

            // Collect uploaded file attachments.
            $attachments = [];
            if (!empty($_FILES['attachments'])) {
                $files = $_FILES['attachments'];
                $count = is_array($files['name']) ? count($files['name']) : 1;
                for ($fi = 0; $fi < $count; $fi++) {
                    $fname  = is_array($files['name'])     ? $files['name'][$fi]     : $files['name'];
                    $ftmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$fi] : $files['tmp_name'];
                    $ferr   = is_array($files['error'])    ? $files['error'][$fi]    : $files['error'];
                    $fmime  = is_array($files['type'])     ? $files['type'][$fi]     : $files['type'];
                    if ($ferr === UPLOAD_ERR_OK && is_uploaded_file($ftmp)) {
                        $fname = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $fname);
                        $attachments[] = [
                            'name' => $fname,
                            'tmp'  => $ftmp,
                            'mime' => $fmime ?: 'application/octet-stream',
                        ];
                    }
                }
            }

            $from_name = fullname($USER);

            // ----------------------------------------------------------------
            // FILE-BASED DEBUG LOG — written to $CFG->dataroot/sem_send_debug.txt.
            // Unlike error_log(), file_put_contents() persists to disk even if
            // the PHP process is SIGKILL'd by PHP-FPM request_terminate_timeout.
            // Admin can read it via action=get_debug_log (admin-only) or cPanel
            // File Manager at that path.
            // ----------------------------------------------------------------
            $sem_log = $CFG->dataroot . '/sem_send_debug.txt';
            $sem_ts  = date('Y-m-d H:i:s');
            file_put_contents($sem_log, "[$sem_ts] send_message case entered — to={$to} host=" . get_config('local_studentemail', 'smtp_host') . ':' . get_config('local_studentemail', 'smtp_port') . "\n", FILE_APPEND | LOCK_EX);
            sem_step(10, 'send-case-entered', $sem_log);

            // Shutdown handler: fires on max_execution_time fatal.
            // CRITICAL: must call flush() after echo or PHP-FPM drops bytes on kill.
            register_shutdown_function(function () use ($sem_log) {
                $err = error_get_last();
                $ts  = date('Y-m-d H:i:s');
                if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    file_put_contents($sem_log, "[$ts] FATAL shutdown: type={$err['type']} msg={$err['message']} file={$err['file']}:{$err['line']}\n", FILE_APPEND | LOCK_EX);
                    // Drain every output buffer layer so our JSON is not buried in
                    // Moodle HTML, then explicitly flush the FastCGI output stream.
                    while (ob_get_level() > 0) { ob_end_clean(); }
                    // Surface the fatal in response headers — visible in Chrome DevTools
                    // Network → Headers even when the body is empty or garbled.
                    if (!headers_sent()) {
                        header('Content-Type: application/json');
                        header('X-SEM-Step: FATAL');
                        header('X-FATAL: ' . substr(preg_replace('/[\r\n]/', ' ', $err['message']), 0, 200));
                    }
                    $json = json_encode([
                        'success' => false,
                        'message' => 'PHP fatal in send: ' . $err['message'],
                        'file'    => $err['file'],
                        'line'    => $err['line'],
                    ]);
                    echo $json;
                    // Force bytes out of PHP-FPM's FastCGI buffer before process dies.
                    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                    else { flush(); }
                } else {
                    file_put_contents($sem_log, "[$ts] shutdown (no fatal) last_err=" . json_encode($err) . "\n", FILE_APPEND | LOCK_EX);
                }
            });

            // Extend wall-clock limit. PHPMailer Timeout=5s throws a catchable
            // exception before this fires under normal SMTP conditions.
            set_time_limit(90);

            sem_step(11, 'calling-send_message', $sem_log);
            // Helper: convert any string to clean UTF-8 so json_encode never
            // returns false (SMTP conversations often contain Latin-1 or binary bytes).
            $sem_utf8 = function ($s) {
                if (!is_string($s)) { return (string)$s; }
                // Remove ASCII control chars in RAW BYTE mode (no /u flag) so
                // non-UTF-8 input never causes preg_replace to return null.
                $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
                if ($clean === null) { $clean = $s; }
                // Replace any invalid UTF-8 sequences with the Unicode replacement char.
                return mb_scrub($clean, 'UTF-8');
            };
            // Fallback encoder: if json_encode still returns false, return a safe
            // plain-English payload so the browser always gets valid JSON.
            $sem_json = function (array $data) use ($sem_utf8) {
                array_walk_recursive($data, function (&$v) use ($sem_utf8) {
                    if (is_string($v)) { $v = $sem_utf8($v); }
                });
                $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($out === false) {
                    $out = json_encode([
                        'success' => $data['success'] ?? false,
                        'message' => 'Response encoding error: ' . json_last_error_msg(),
                        'smtp_log' => '(could not encode SMTP log — contains non-UTF-8 bytes)',
                    ]);
                }
                return $out;
            };

            try {
                $client->send_message($email, $from_name, $to, $subject, $body, $cc, $attachments);
                $client->close();
                sem_step(12, 'send-succeeded');
                echo $sem_json([
                    'success'   => true,
                    'message'   => 'Message sent.',
                    'smtp_log'  => $client->get_smtp_debug_log(),
                ]);
                if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                else { flush(); }
            } catch (\Throwable $e) {
                // PHPMailer\PHPMailer\Exception for SMTP failures;
                // moodle_exception stores extra detail in ->a.
                $detail = (isset($e->a) && $e->a !== '') ? $e->a : $e->getMessage();
                sem_step(12, 'send-exception');
                echo $sem_json([
                    'success'  => false,
                    'message'  => 'Failed to send: ' . $detail,
                    'smtp_log' => $client->get_smtp_debug_log(),
                    'debug'    => [
                        'class'   => get_class($e),
                        'message' => $e->getMessage(),
                        'file'    => $e->getFile(),
                        'line'    => $e->getLine(),
                    ],
                ]);
                if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                else { flush(); }
            }
            break;

        // -----------------------------------------------------------------------
        case 'mark_read':
            $msgno  = required_param('msgno', PARAM_INT);
            $read   = (bool)optional_param('read', 1, PARAM_INT);
            $folder = optional_param('folder', 'INBOX', PARAM_TEXT);
            $client->connect($folder);
            $client->mark_read($msgno, $read);
            $client->close();
            echo json_encode(['success' => true]);
            break;

        // -----------------------------------------------------------------------
        case 'delete_message':
            $msgno  = required_param('msgno', PARAM_INT);
            $folder = optional_param('folder', 'INBOX', PARAM_TEXT);
            $client->delete_message($msgno, $folder);
            $client->close();
            echo json_encode(['success' => true, 'message' => 'Message deleted.']);
            break;

        // -----------------------------------------------------------------------
        case 'get_unread':
            $folder = optional_param('folder', 'INBOX', PARAM_TEXT);
            $count  = $client->get_unread_count($folder);
            $client->close();
            echo json_encode(['success' => true, 'unread' => $count]);
            break;

        // -----------------------------------------------------------------------
        case 'get_folders':
            $folders = $client->list_folders();
            $client->close();
            echo json_encode(['success' => true, 'folders' => $folders]);
            break;

        // -----------------------------------------------------------------------
        // Phase 2 actions
        // -----------------------------------------------------------------------

        case 'get_attachment':
            $msgno   = required_param('msgno', PARAM_INT);
            $partnum = required_param('partnum', PARAM_TEXT);
            // Only allow digits and dots in partnum (IMAP part spec).
            if (!preg_match('/^[\d.]+$/', $partnum)) {
                echo json_encode(['success' => false, 'message' => 'Invalid part number.']);
                exit;
            }
            $folder  = optional_param('folder', 'INBOX', PARAM_TEXT);
            $att     = $client->get_attachment($msgno, $partnum, $folder);
            $client->close();
            echo json_encode(['success' => true] + $att);
            break;

        // -----------------------------------------------------------------------
        case 'server_search':
            $folder = optional_param('folder', 'INBOX', PARAM_TEXT);
            $query  = required_param('query', PARAM_TEXT);
            $limit  = min((int)optional_param('limit', 50, PARAM_INT), 200);
            $offset = max(0, (int)optional_param('offset', 0, PARAM_INT));
            $result = $client->search_messages($folder, $query, $limit, $offset);
            $client->close();
            echo json_encode(['success' => true] + $result);
            break;

        // -----------------------------------------------------------------------
        case 'move_message':
            $msgno       = required_param('msgno', PARAM_INT);
            $from_folder = required_param('from_folder', PARAM_TEXT);
            $to_folder   = required_param('to_folder', PARAM_TEXT);
            $client->move_message($msgno, $from_folder, $to_folder);
            $client->close();
            echo json_encode(['success' => true, 'message' => 'Moved to ' . s($to_folder) . '.']);
            break;

        // -----------------------------------------------------------------------
        case 'toggle_flag':
            $msgno   = required_param('msgno', PARAM_INT);
            $flagged = (bool)optional_param('flagged', 1, PARAM_INT);
            $folder  = optional_param('folder', 'INBOX', PARAM_TEXT);
            $client->toggle_flag($msgno, $folder, $flagged);
            $client->close();
            echo json_encode(['success' => true, 'flagged' => $flagged]);
            break;

        // -----------------------------------------------------------------------
        // -----------------------------------------------------------------------
        case 'get_debug_log':
            // Admin-only: returns the contents of /tmp/sem_send_debug.txt so
            // the last send attempt can be diagnosed without SSH access.
            require_capability('local/studentemail:manage', context_system::instance());
            $sem_log = sys_get_temp_dir() . '/sem_send_debug.txt';
            if (file_exists($sem_log)) {
                $log = file_get_contents($sem_log);
                echo json_encode(['success' => true, 'log' => $log, 'path' => $sem_log]);
            } else {
                echo json_encode(['success' => true, 'log' => '(no log file yet — send an email first)', 'path' => $sem_log]);
            }
            break;

        // -----------------------------------------------------------------------
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }

} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
