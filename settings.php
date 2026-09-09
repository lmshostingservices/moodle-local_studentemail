<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin settings for local_studentemail.
 *
 * Registers: Site Admin → Plugins → Local plugins → Student Email Manager
 * AND the dashboard external page link.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$hasaccess = $hassiteconfig || has_capability('local/studentemail:manage', context_system::instance());

if ($hasaccess) {

    // Credit-unlock check — shows a warning notification in the admin UI.
    if (!defined('DOING_AJAX')) {
        require_once($CFG->dirroot . '/local/studentemail/classes/unlock_verifier.php');
        \local_studentemail\unlock_verifier::check_and_notify();
    }

    // -------------------------------------------------------------------------
    // Parent category — appears as a single collapsible entry in Local plugins
    // -------------------------------------------------------------------------
    $ADMIN->add('localplugins', new admin_category(
        'local_studentemail_cat',
        get_string('pluginname', 'local_studentemail')
    ));

    // Dashboard link nested under the category
    $ADMIN->add('local_studentemail_cat', new admin_externalpage(
        'local_studentemail_dashboard',
        get_string('dashboard', 'local_studentemail'),
        new moodle_url('/local/studentemail/dashboard.php'),
        'local/studentemail:manage'
    ));

    // -------------------------------------------------------------------------
    // Settings page (nested under same category)
    // -------------------------------------------------------------------------
    $settings = new admin_settingpage('local_studentemail', get_string('settings_nav', 'local_studentemail'));

    // --- cPanel Connection ---------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_studentemail/cpanel_heading',
        get_string('settings_cpanel', 'local_studentemail'),
        get_string('settings_cpanel_desc', 'local_studentemail')
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/cpanel_host',
        get_string('cpanel_host', 'local_studentemail'),
        get_string('cpanel_host_desc', 'local_studentemail'),
        '',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/cpanel_port',
        get_string('cpanel_port', 'local_studentemail'),
        get_string('cpanel_port_desc', 'local_studentemail'),
        '2083',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/cpanel_username',
        get_string('cpanel_username', 'local_studentemail'),
        get_string('cpanel_username_desc', 'local_studentemail'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_studentemail/cpanel_token',
        get_string('cpanel_token', 'local_studentemail'),
        get_string('cpanel_token_desc', 'local_studentemail'),
        ''
    ));

    // --- Email Format --------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_studentemail/email_heading',
        get_string('settings_email', 'local_studentemail'),
        get_string('settings_email_desc', 'local_studentemail')
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/email_domain',
        get_string('email_domain', 'local_studentemail'),
        get_string('email_domain_desc', 'local_studentemail'),
        '',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/prefer_existing_mailbox',
        get_string('prefer_existing_mailbox', 'local_studentemail'),
        get_string('prefer_existing_mailbox_desc', 'local_studentemail'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'local_studentemail/email_format',
        get_string('email_format', 'local_studentemail'),
        get_string('email_format_desc', 'local_studentemail'),
        'autonumber',
        [
            'autonumber' => get_string('email_format_autonumber', 'local_studentemail'),
            'username'   => get_string('email_format_username', 'local_studentemail'),
            'idnumber'   => get_string('email_format_idnumber', 'local_studentemail'),
            'firstlast'  => get_string('email_format_firstlast', 'local_studentemail'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/email_prefix',
        get_string('email_prefix', 'local_studentemail'),
        get_string('email_prefix_desc', 'local_studentemail'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/email_start_number',
        get_string('email_start_number', 'local_studentemail'),
        get_string('email_start_number_desc', 'local_studentemail'),
        '5000',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/email_next_number',
        get_string('email_next_number', 'local_studentemail'),
        get_string('email_next_number_desc', 'local_studentemail'),
        '5001',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/email_quota_mb',
        get_string('email_quota_mb', 'local_studentemail'),
        get_string('email_quota_mb_desc', 'local_studentemail'),
        '1024',
        PARAM_INT
    ));

    // --- Mailbox Client (native IMAP) ----------------------------------------
    $settings->add(new admin_setting_heading(
        'local_studentemail/mailbox_heading',
        get_string('settings_mailbox', 'local_studentemail'),
        get_string('settings_mailbox_desc', 'local_studentemail')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/mailbox_enabled',
        get_string('mailbox_enabled', 'local_studentemail'),
        get_string('mailbox_enabled_desc', 'local_studentemail'),
        '1'
    ));

    // --- IMAP (for reading in mailbox) ----------------------------------------
    $settings->add(new admin_setting_heading(
        'local_studentemail/imap_heading',
        get_string('settings_imap', 'local_studentemail'),
        get_string('settings_imap_desc', 'local_studentemail')
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/imap_host',
        get_string('imap_host', 'local_studentemail'),
        get_string('imap_host_desc', 'local_studentemail'),
        '',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/imap_port',
        get_string('imap_port', 'local_studentemail'),
        get_string('imap_port_desc', 'local_studentemail'),
        '993',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_studentemail/imap_encryption',
        get_string('imap_encryption', 'local_studentemail'),
        get_string('imap_encryption_desc', 'local_studentemail'),
        'ssl',
        [
            'ssl'  => get_string('imap_enc_ssl', 'local_studentemail'),
            'tls'  => get_string('imap_enc_tls', 'local_studentemail'),
            'none' => get_string('imap_enc_none', 'local_studentemail'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/imap_novalidate',
        get_string('imap_novalidate', 'local_studentemail'),
        get_string('imap_novalidate_desc', 'local_studentemail'),
        '0'
    ));

    // --- SMTP (for sending from mailbox) -------------------------------------
    $settings->add(new admin_setting_heading(
        'local_studentemail/smtp_heading',
        get_string('settings_smtp', 'local_studentemail'),
        get_string('settings_smtp_desc', 'local_studentemail')
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/smtp_host',
        get_string('smtp_host', 'local_studentemail'),
        get_string('smtp_host_desc', 'local_studentemail'),
        '',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/smtp_port',
        get_string('smtp_port', 'local_studentemail'),
        get_string('smtp_port_desc', 'local_studentemail'),
        '587',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_studentemail/smtp_encryption',
        get_string('smtp_encryption', 'local_studentemail'),
        get_string('smtp_encryption_desc', 'local_studentemail'),
        'tls',
        [
            'tls'  => get_string('smtp_enc_tls', 'local_studentemail'),
            'ssl'  => get_string('smtp_enc_ssl', 'local_studentemail'),
            'none' => get_string('smtp_enc_none', 'local_studentemail'),
        ]
    ));

    // --- Webmail Portal (legacy external link — kept for fallback) ------------
    $settings->add(new admin_setting_heading(
        'local_studentemail/webmail_heading',
        get_string('settings_webmail', 'local_studentemail'),
        get_string('settings_webmail_desc', 'local_studentemail')
    ));

    $settings->add(new admin_setting_configtext(
        'local_studentemail/webmail_url',
        get_string('webmail_url', 'local_studentemail'),
        get_string('webmail_url_desc', 'local_studentemail'),
        '',
        PARAM_URL
    ));

    // --- Automation Rules ----------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_studentemail/automation_heading',
        get_string('settings_automation', 'local_studentemail'),
        get_string('settings_automation_desc', 'local_studentemail')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/auto_provision',
        get_string('auto_provision', 'local_studentemail'),
        get_string('auto_provision_desc', 'local_studentemail'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/welcome_email_enabled',
        get_string('welcome_email_enabled', 'local_studentemail'),
        get_string('welcome_email_enabled_desc', 'local_studentemail'),
        '0'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/auto_suspend',
        get_string('auto_suspend', 'local_studentemail'),
        get_string('auto_suspend_desc', 'local_studentemail'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/auto_restore',
        get_string('auto_restore', 'local_studentemail'),
        get_string('auto_restore_desc', 'local_studentemail'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_studentemail/auto_archive',
        get_string('auto_archive', 'local_studentemail'),
        get_string('auto_archive_desc', 'local_studentemail'),
        '1'
    ));

    $ADMIN->add('local_studentemail_cat', $settings);
    $settings = null; // Handled manually via admin_category above.
}
