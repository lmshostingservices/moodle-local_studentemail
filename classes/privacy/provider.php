<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for local_studentemail.
 *
 * @package    local_studentemail
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_studentemail\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem for local_studentemail.
 *
 * The plugin stores one provisioned mailbox record per user, plus that user's
 * saved mail signature, and transmits mailbox data to an external cPanel mail
 * server.
 *
 * @package    local_studentemail
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Describe the personal data stored and transmitted by this plugin.
     *
     * @param  collection $collection The initialised collection to add items to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_studentemail_accounts',
            [
                'userid'        => 'privacy:metadata:accounts:userid',
                'email'         => 'privacy:metadata:accounts:email',
                'emailpassword' => 'privacy:metadata:accounts:emailpassword',
                'studentnumber' => 'privacy:metadata:accounts:studentnumber',
                'status'        => 'privacy:metadata:accounts:status',
                'quota_mb'      => 'privacy:metadata:accounts:quotamb',
                'notes'         => 'privacy:metadata:accounts:notes',
                'timecreated'   => 'privacy:metadata:accounts:timecreated',
                'timemodified'  => 'privacy:metadata:accounts:timemodified',
            ],
            'privacy:metadata:accounts'
        );

        $collection->add_user_preference(
            'local_studentemail_signature',
            'privacy:metadata:preference:signature'
        );

        $collection->add_external_location_link(
            'cpanel_mail_server',
            [
                'fullname'      => 'privacy:metadata:cpanel:fullname',
                'email'         => 'privacy:metadata:cpanel:email',
                'emailpassword' => 'privacy:metadata:cpanel:emailpassword',
                'quota'         => 'privacy:metadata:cpanel:quota',
                'messages'      => 'privacy:metadata:cpanel:messages',
            ],
            'privacy:metadata:cpanel'
        );

        return $collection;
    }

    /**
     * Return the contexts containing personal data for the given user.
     *
     * @param  int $userid The user to search.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {local_studentemail_accounts} a ON a.userid = c.instanceid
                 WHERE c.contextlevel = :contextlevel
                   AND a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_USER,
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Return the users within the given user context who have data here.
     *
     * @param  userlist $userlist The userlist to add user IDs to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }

        $sql = "SELECT userid
                  FROM {local_studentemail_accounts}
                 WHERE userid = :userid";
        $userlist->add_from_sql('userid', $sql, ['userid' => $context->instanceid]);
    }

    /**
     * Export all personal data for the approved contexts of a user.
     *
     * @param  approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_USER || $context->instanceid != $user->id) {
                continue;
            }

            $records = $DB->get_records('local_studentemail_accounts', ['userid' => $user->id]);
            foreach ($records as $record) {
                $data = (object)[
                    'email'         => $record->email,
                    // The stored mailbox password is a credential, not exported.
                    'emailpassword' => get_string('privacy:export:passwordwithheld', 'local_studentemail'),
                    'studentnumber' => $record->studentnumber,
                    'status'        => $record->status,
                    'quota_mb'      => $record->quota_mb,
                    'notes'         => $record->notes,
                    'timecreated'   => transform::datetime($record->timecreated),
                    'timemodified'  => transform::datetime($record->timemodified),
                ];
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:account', 'local_studentemail'), $record->id],
                    $data
                );
            }

            writer::with_context($context)->export_user_preference(
                'local_studentemail',
                'local_studentemail_signature',
                get_user_preferences('local_studentemail_signature', '', $user->id),
                get_string('privacy:metadata:preference:signature', 'local_studentemail')
            );
        }
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param  \context $context The context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_user) {
            return;
        }

        $DB->delete_records('local_studentemail_accounts', ['userid' => $context->instanceid]);
        unset_user_preference('local_studentemail_signature', $context->instanceid);
    }

    /**
     * Delete all data for the given user in the approved contexts.
     *
     * @param  approved_contextlist $contextlist The approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_USER || $context->instanceid != $userid) {
                continue;
            }
            $DB->delete_records('local_studentemail_accounts', ['userid' => $userid]);
            unset_user_preference('local_studentemail_signature', $userid);
        }
    }

    /**
     * Delete data for the approved list of users in the given context.
     *
     * @param  approved_userlist $userlist The approved context and user list.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            if ($userid != $context->instanceid) {
                continue;
            }
            $DB->delete_records('local_studentemail_accounts', ['userid' => $userid]);
            unset_user_preference('local_studentemail_signature', $userid);
        }
    }
}
