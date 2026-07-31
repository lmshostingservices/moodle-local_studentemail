<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Event observers for local_studentemail.
 *
 * Fires on user creation, suspension/unsuspension, and deletion so
 * email accounts are kept in sync with Moodle automatically.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_created',
        'callback'  => '\local_studentemail\observer::user_created',
        'priority'  => 100,
    ],
    [
        'eventname' => '\core\event\\user_updated',
        'callback'  => '\local_studentemail\observer::user_updated',
        'priority'  => 100,
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\local_studentemail\observer::user_deleted',
        'priority'  => 100,
    ],
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback'  => '\local_studentemail\observer::user_enrolment_created',
        'priority'  => 100,
    ],
];
