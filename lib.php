<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * lib.php for local_studentemail.
 *
 * Adds the "My Email" navigation item for students who have a provisioned
 * email account.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend the Moodle navigation tree.
 *
 * Adds "My Email" to the user's navigation so it appears in the
 * dashboard sidebar and hamburger menu. Only shown when:
 *   - The student has a provisioned email account with status = active
 *   - The webmail URL is configured
 */
function local_studentemail_extend_navigation(\global_navigation $nav): void {
    global $USER, $DB, $PAGE;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Only show to users who have an active provisioned email.
    $account = $DB->get_record('local_studentemail_accounts', ['userid' => $USER->id]);
    if (!$account || $account->status !== 'active') {
        return;
    }

    // Point to native mailbox if enabled, otherwise fall back to credential page.
    $mailbox_enabled = get_config('local_studentemail', 'mailbox_enabled');
    if (!empty($mailbox_enabled)) {
        $myemail_url = new moodle_url('/local/studentemail/mailbox.php');
    } else {
        $webmail_url = get_config('local_studentemail', 'webmail_url');
        if (empty($webmail_url)) {
            return;
        }
        $myemail_url = new moodle_url('/local/studentemail/webmail.php');
    }

    // Add "My Email" node to main navigation.
    $node = $nav->add(
        get_string('myemail', 'local_studentemail'),
        $myemail_url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_studentemail_myemail',
        new pix_icon('icon', get_string('myemail', 'local_studentemail'), 'local_studentemail')
    );

    if ($node) {
        $node->showinflatnavigation = true;
    }
}

/**
 * Extend the frontpage navigation.
 */
function local_studentemail_extend_navigation_frontpage(\navigation_node $nav): void {
    // Intentionally empty — handled by extend_navigation above.
}
