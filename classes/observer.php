<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Event observer for local_studentemail.
 *
 * Responds to Moodle user lifecycle events and keeps cPanel email
 * accounts in sync automatically.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_studentemail;

defined('MOODLE_INTERNAL') || die();

class observer {
    /**
     * A new Moodle user has been created.
     * Auto-provision if the setting is enabled.
     */
    public static function user_created(\core\event\user_created $event): void {
        if (!get_config('local_studentemail', 'auto_provision')) {
            return;
        }

        self::guard('user_created', function () use ($event) {
            global $DB;
            $user = $DB->get_record('user', ['id' => $event->objectid]);
            self::provision($user);
        });
    }

    /**
     * A user enrolment was created (alternative trigger for auto-provision).
     * Fires when a student is enrolled in a course — useful for sites
     * that create user accounts in bulk before enrolling them.
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event): void {
        if (!get_config('local_studentemail', 'auto_provision')) {
            return;
        }

        self::guard('user_enrolment_created', function () use ($event) {
            global $DB;

            // Only provision if they don't have an account yet.
            if ($DB->record_exists('local_studentemail_accounts', ['userid' => $event->relateduserid])) {
                return;
            }

            $user = $DB->get_record('user', ['id' => $event->relateduserid]);
            self::provision($user);
        });
    }

    /**
     * Provision a mailbox for a user and send credentials when appropriate.
     *
     * create_email() either creates a new mailbox (and returns its password) or
     * links a mailbox that already exists on the server via link_account().
     * link_account() resets that mailbox's password and sends the welcome email
     * itself, and returns no password, so the observer must only send the
     * welcome email for a newly created mailbox. Sending it again on the link
     * path would duplicate the email — and, with no password in the result,
     * raised a TypeError that surfaced on the admin "Add a new user" page.
     *
     * @param \stdClass|false $user Moodle user record.
     */
    private static function provision($user): void {
        if (!$user || $user->deleted || $user->id <= 1) {
            return;
        }

        // Skip guest and admin.
        if (isguestuser($user) || is_siteadmin($user)) {
            return;
        }

        $manager = new email_manager();
        $result  = $manager->create_email($user);

        if (empty($result['success'])) {
            return;
        }

        // Linked to an existing mailbox: link_account() has already handled
        // the password and the welcome email.
        if (!empty($result['linked_existing'])) {
            return;
        }

        $email    = (string)($result['email'] ?? '');
        $password = (string)($result['password'] ?? '');
        if ($email === '' || $password === '') {
            return;
        }

        if (get_config('local_studentemail', 'welcome_email_enabled')) {
            $manager->send_welcome_email($user, $email, $password);
        }
    }

    /**
     * Run observer work so that a failure can never interrupt the Moodle
     * action that triggered the event (creating, enrolling, updating or
     * deleting a user). Failures are logged instead of thrown.
     *
     * @param string   $handler Observer name, for the log line.
     * @param callable $work    The observer body.
     */
    private static function guard(string $handler, callable $work): void {
        try {
            $work();
        } catch (\Throwable $e) {
            $message = 'local_studentemail observer ' . $handler . ' failed: '
                . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine();
            error_log($message);
            debugging($message, DEBUG_DEVELOPER);
        }
    }

    /**
     * A user account was updated (handles suspension/unsuspension).
     */
    public static function user_updated(\core\event\user_updated $event): void {
        self::guard('user_updated', function () use ($event) {
            self::handle_user_updated($event);
        });
    }

    /**
     * Body of user_updated(), run inside guard().
     *
     * @param \core\event\user_updated $event
     */
    private static function handle_user_updated(\core\event\user_updated $event): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $event->objectid]);
        if (!$user || $user->id <= 1) {
            return;
        }

        // Check if user is now suspended.
        $account = $DB->get_record('local_studentemail_accounts', ['userid' => $user->id]);
        if (!$account) {
            return;
        }

        $manager = new email_manager();

        if ($user->suspended && $account->status === email_manager::STATUS_ACTIVE) {
            if (get_config('local_studentemail', 'auto_suspend')) {
                $manager->suspend_email($user->id);
            }
        } else if (!$user->suspended && $account->status === email_manager::STATUS_SUSPENDED) {
            if (get_config('local_studentemail', 'auto_restore')) {
                $manager->restore_email($user->id);
            }
        }
    }

    /**
     * A user account was deleted.
     * Archive the email so admin can still access but student cannot log in.
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        if (!get_config('local_studentemail', 'auto_archive')) {
            return;
        }

        global $DB;
        $account = $DB->get_record('local_studentemail_accounts', ['userid' => $event->objectid]);
        if (!$account || $account->status === email_manager::STATUS_ARCHIVED) {
            return;
        }

        self::guard('user_deleted', function () use ($event) {
            $manager = new email_manager();
            $manager->archive_email($event->objectid);
        });
    }
}
