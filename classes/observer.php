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

        global $DB;
        $user = $DB->get_record('user', ['id' => $event->objectid]);
        if (!$user || $user->deleted || $user->id <= 1) {
            return;
        }

        // Skip guest and admin.
        if (isguestuser($user) || is_siteadmin($user)) {
            return;
        }

        $manager = new email_manager();
        $result  = $manager->create_email($user);

        if ($result['success'] && get_config('local_studentemail', 'welcome_email_enabled')) {
            $manager->send_welcome_email($user, $result['email'], $result['password']);
        }
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

        global $DB;

        // Only provision if they don't have an account yet.
        if ($DB->record_exists('local_studentemail_accounts', ['userid' => $event->relateduserid])) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $event->relateduserid]);
        if (!$user || $user->deleted || $user->id <= 1) {
            return;
        }
        if (isguestuser($user) || is_siteadmin($user)) {
            return;
        }

        $manager = new email_manager();
        $result  = $manager->create_email($user);

        if ($result['success'] && get_config('local_studentemail', 'welcome_email_enabled')) {
            $manager->send_welcome_email($user, $result['email'], $result['password']);
        }
    }

    /**
     * A user account was updated (handles suspension/unsuspension).
     */
    public static function user_updated(\core\event\user_updated $event): void {
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

        $manager = new email_manager();
        $manager->archive_email($event->objectid);
    }
}
