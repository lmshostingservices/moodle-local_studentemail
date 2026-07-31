<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upgrade steps for local_studentemail.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_studentemail_upgrade($oldversion) {
    // v1.3.0 — MAILBOX-CLIENT: Added native IMAP/SMTP email client (mailbox.php).
    // No DB schema changes — all mail metadata is fetched live from IMAP.
    // New settings: mailbox_enabled, smtp_host, smtp_port, smtp_encryption.
    if ($oldversion < 2026062700001) {
        upgrade_plugin_savepoint(true, 2026062700001, 'local', 'studentemail');
    }

    // v1.4.0 — MAILBOX-PHASE2: Attachment downloads, reply-all, server-side IMAP
    // search, rich text compose, email signature (user prefs), mark-unread,
    // move-to-folder, star/flag toggle, proper pagination. No DB schema changes.
    if ($oldversion < 2026062700002) {
        upgrade_plugin_savepoint(true, 2026062700002, 'local', 'studentemail');
    }

    // v1.5.9 — SMTP-DIAG: Removed ob_start() around SMTP call (was swallowing
    // fatal/timeout errors and returning empty 200 body); added PHPMailer
    // Timeout=10 s, set_time_limit(60), register_shutdown_function for fatal
    // capture, and error_log diagnostics. No DB schema changes.
    if ($oldversion < 2026062700021) {
        upgrade_plugin_savepoint(true, 2026062700021, 'local', 'studentemail');
    }

    // v1.5.10 — FIX-SENT-IMAP-HANG: Removed the imap_open() call to the Sent
    // folder that ran after every successful SMTP send. That second IMAP connect
    // (3 attempts × ~20 s each) hung when the Sent folder did not exist or the
    // server throttled connections, exceeding max_execution_time and returning
    // an empty body — causing "Server error. Please try again." in the browser
    // even when the email was delivered successfully. No DB schema changes.
    if ($oldversion < 2026062700022) {
        upgrade_plugin_savepoint(true, 2026062700022, 'local', 'studentemail');
    }

    // v1.5.11 — FIX-APPEND-IMAP-HANG: Confirmed root cause — append_to_sent()
    // was calling imap_open() to a new Sent-folder connection even when
    // $this->stream was set, so the v1.5.10 guard did not prevent the hang.
    // Disabled the Sent-folder append entirely. Added checkpoint error_log()
    // calls (1/2/3) and SMTPDebug=2 output to PHP error log so the exact hang
    // point can be identified from server logs. No DB schema changes.
    if ($oldversion < 2026062700023) {
        upgrade_plugin_savepoint(true, 2026062700023, 'local', 'studentemail');
    }

    // v1.5.12 — FLUSH-AND-FILELOG: Three fixes for the persistent empty-body
    // 200 response. (1) Added fastcgi_finish_request()/flush() after every
    // echo json_encode() in the send_message handler so bytes actually leave
    // PHP-FPM's FastCGI buffer before the process is killed. (2) Replaced
    // error_log() checkpoints with file_put_contents() to /tmp/sem_send_debug.txt
    // which persists even on SIGKILL — readable via new admin action get_debug_log
    // or cPanel File Manager. (3) Reduced PHPMailer Timeout from 10s to 5s.
    // No DB schema changes.
    if ($oldversion < 2026062700024) {
        upgrade_plugin_savepoint(true, 2026062700024, 'local', 'studentemail');
    }

    // v1.5.13 — STEP-LOGGING: Added granular STEP 1-8 file-based checkpoints
    // around every PHPMailer config line and $mail->send() call so the exact
    // line that hangs/fails is visible in /tmp/sem_send_debug.txt even after
    // a SIGKILL. Also upgraded SMTPDebug from 2 to 3 (full conversation including
    // client commands) and replaced remaining error_log() calls with
    // file_put_contents(). Added ini_set('display_errors', 1) at the top of
    // mailbox_ajax.php to surface any hidden fatal errors in the HTTP response.
    // No DB schema changes.
    if ($oldversion < 2026062700025) {
        upgrade_plugin_savepoint(true, 2026062700025, 'local', 'studentemail');
    }

    // v1.5.14 — DATAROOT-LOG: Switched debug log path from sys_get_temp_dir()
    // to $CFG->dataroot so the file is guaranteed writable by PHP and visible
    // in cPanel File Manager inside the Moodle data directory. Also added an
    // ultra-early log entry written immediately after session close (before
    // credential loading) to catch crashes that happen before the send_message
    // switch case is reached. No DB schema changes.
    if ($oldversion < 2026062700026) {
        upgrade_plugin_savepoint(true, 2026062700026, 'local', 'studentemail');
    }

    // v1.5.22 — AUTO-PW-ON-IMPORT: import_existing() and link_account() now
    // auto-generate a fresh password, set it on cPanel via API, store it
    // encrypted in the DB, and send the welcome email — exactly as create_email()
    // does. Eliminates the "Pwd externally managed" state where students linked
    // from pre-existing cPanel accounts could never access the in-Moodle mailbox
    // because no password was stored. No DB schema changes.
    if ($oldversion < 2026071700200) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/email_manager.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071700200, 'local', 'studentemail');
    }

    // v1.5.23 — CLEAR-EXT-BADGE: reset_password() now clears the stale
    // "Pwd externally managed" notes badge on success. No DB schema change.
    if ($oldversion < 2026071700300) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/email_manager.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071700300, 'local', 'studentemail');
    }

    // v1.5.24 — FILTER-DROPDOWN: Status filter dropdown in dashboard search bar.
    // No DB schema change.
    if ($oldversion < 2026071800100) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['dashboard.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071800100, 'local', 'studentemail');
    }

    // v1.5.25 — IMPORT-MATCH-FIX: import_existing() localpart extraction for
    // email-style Moodle usernames. No DB schema change.
    if ($oldversion < 2026071800200) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/email_manager.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071800200, 'local', 'studentemail');
    }

    // v1.5.26 — DATA-QUALITY: Data Quality Report modal + get_data_quality AJAX action.
    // No DB schema change.
    if ($oldversion < 2026071800300) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/email_manager.php', 'ajax.php', 'dashboard.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071800300, 'local', 'studentemail');
    }

    // v1.5.27 — CPANEL-LOCALPART-FIX + DIAGNOSE-IMPORT. No DB schema change.
    if ($oldversion < 2026071800400) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/email_manager.php', 'classes/cpanel_api.php', 'ajax.php', 'dashboard.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071800400, 'local', 'studentemail');
    }

    // v1.5.28 — IMPORT-COLLISION-FIX + DIAGNOSE-SQL. No DB schema change.
    if ($oldversion < 2026071800500) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/email_manager.php', 'ajax.php', 'dashboard.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071800500, 'local', 'studentemail');
    }

    // v1.5.29 — BULK-RESET-ALL-PASSWORDS: New bulk_reset_all_passwords AJAX action + dashboard button.
    // Resets cPanel passwords for ALL active accounts, not just those with missing passwords.
    // Fixes students linked via "Link Existing" whose stored password never matched cPanel. No DB schema change.
    if ($oldversion < 2026071800600) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/email_manager.php', 'ajax.php', 'dashboard.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026071800600, 'local', 'studentemail');
    }

    if ($oldversion < 2026072300218) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072300218, 'local', 'studentemail');
    }

    if ($oldversion < 2026072300219) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300219, 'local', 'studentemail');
    }

    if ($oldversion < 2026072300220) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300220, 'local', 'studentemail');
    }

    if ($oldversion < 2026072900221) {
        // IMAP-DEEP-DIAGNOSTIC (v1.5.33 / 29 Jul 2026):
        //
        // Replaced the blind "Test IMAP" button with a real diagnostic that actually
        // shows WHY a student's mailbox appears empty even though cPanel has mail.
        //
        // Root cause of the empty-inbox bug:
        //   imap_open() was succeeding (no exception thrown) but connecting to the
        //   WRONG mailbox — either because strip_domain was set incorrectly (sending
        //   just "jsmith" instead of "jsmith@domain.com" or vice versa), or because
        //   the stored password no longer matched cPanel. The old "Test IMAP" button
        //   only checked whether imap_open() returned a stream resource — it never
        //   checked what was inside, so it always reported "success" even when the
        //   student's INBOX was empty.
        //
        // Changes (no DB schema changes — savepoint only):
        //
        // ajax.php — test_imap action completely replaced:
        //   Now runs TWO probes back-to-back, one with the current strip_domain setting
        //   and one with the opposite. Each probe:
        //     • Opens imap_open() and captures any auth errors via imap_errors().
        //     • Calls imap_num_msg() to count INBOX messages.
        //     • Calls imap_list() + imap_reopen() to enumerate ALL folders and their
        //       individual message counts.
        //   Returns a structured JSON with both probe results plus a one-line
        //   "fix" recommendation string (e.g. "Toggle strip_domain OFF — the opposite
        //   format has 47 messages in INBOX").
        //
        // ajax.php — new action: test_imap_student
        //   Same deep probe as test_imap but accepts a userid param so the admin can
        //   test a specific student's exact stored credentials rather than a random
        //   active account.
        //
        // dashboard.php — testImap() JS function replaced:
        //   Calls the new test_imap action and renders results in a modal dialog
        //   showing: IMAP host/port/flags, username sent in each probe, INBOX count,
        //   full folder list with per-folder message counts (green = has mail), and
        //   the recommendation box.
        //
        // dashboard.php — per-row "Test Mailbox" button added:
        //   Active student rows now have a "Test Mailbox" button that calls
        //   test_imap_student for that specific student, showing the same modal
        //   with that student's exact credentials.
        upgrade_plugin_savepoint(true, 2026072900221, 'local', 'studentemail');
    }

    return true;
}