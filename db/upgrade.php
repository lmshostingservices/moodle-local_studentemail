<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_studentemail_upgrade($oldversion) {
    if ($oldversion < 2026072900) {
        upgrade_plugin_savepoint(true, 2026072900, 'local', 'studentemail');
    }
    return true;
}
