<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Language strings for local_studentemail.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']               = 'Student Email Manager';
$string['plugindesc']               = 'Automatically provision and manage cPanel email accounts for Moodle students.';

// Capabilities
$string['studentemail:manage']      = 'Manage Student Email accounts';
$string['studentemail:viewown']     = 'View own student email';

// Nav
$string['myemail']                  = 'My Email';
$string['myemail_desc']             = 'Access your college email inbox';

// Settings headings
$string['settings_cpanel']          = 'cPanel Connection';
$string['settings_cpanel_desc']     = '<strong>Important:</strong> This plugin connects using a cPanel <strong>API Token</strong> — not your cPanel login password. To create one: log into cPanel → Security → Manage API Tokens → Create → name it "Moodle Student Email" → copy the token → paste it into the API Token field below. Do not paste your login password into the API Token field.';
$string['settings_email']           = 'Email Format';
$string['settings_email_desc']      = 'Configure how student email addresses are generated.';
$string['settings_automation']      = 'Automation Rules';
$string['settings_automation_desc'] = 'Control when accounts are automatically created, suspended, or archived.';
$string['settings_webmail']         = 'Webmail Portal';
$string['settings_webmail_desc']    = 'Roundcube webmail URL used for the student "My Email" portal.';

// Settings fields
$string['cpanel_host']              = 'cPanel Hostname';
$string['cpanel_host_desc']         = 'Hostname or IP of your cPanel server (e.g. server.college.com.au). Do not include https:// or port.';
$string['cpanel_port']              = 'cPanel Port';
$string['cpanel_port_desc']         = 'Usually 2083 (SSL) or 2082 (non-SSL). Default: 2083.';
$string['cpanel_username']          = 'cPanel Username';
$string['cpanel_username_desc']     = 'The cPanel account username that owns the email domain.';
$string['cpanel_token']             = 'cPanel API Token';
$string['cpanel_token_desc']        = '<strong>This is an API Token — not your cPanel login password.</strong> To generate one: cPanel → Security → Manage API Tokens → Create → name it "Moodle Student Email" → copy the full token string and paste it here. The token looks like a long random string (e.g. A1B2C3D4E5...). If you paste your login password here, the connection will fail with an authentication error.';
$string['email_domain']             = 'Email Domain';
$string['email_domain_desc']        = 'The domain for student email addresses (e.g. students.college.edu.au).';
$string['email_format']             = 'Email Address Format';
$string['email_format_desc']        = 'How the local part (before @) of each student email is generated.';
$string['email_format_autonumber']  = 'Auto-number: prefix + sequential number (e.g. stu0001)';
$string['email_format_username']    = 'Moodle username (e.g. jsmith)';
$string['email_format_idnumber']    = 'Student ID / idnumber field (e.g. STU001234)';
$string['email_format_firstlast']   = 'firstname.lastname (e.g. john.smith)';
$string['email_prefix']             = 'Email Prefix';
$string['email_prefix_desc']        = 'Prefix for auto-number format only (e.g. "stu" produces stu0001). Keep it short and lowercase. <strong>If you already have existing email accounts on cPanel using the format prefix+YYMMDD (e.g. stu231001), sequential numbers will NOT collide</strong> — sequential numbers are 4–5 digits (e.g. 5001) while date-format numbers are always 6 digits (e.g. 231001). They are safe to use together on the same domain.';
$string['email_start_number']       = 'Starting Number';
$string['email_start_number_desc']  = 'The first sequential number assigned to new students. If prefix is "stu" and this is 5000, the first student gets stu0001. <strong>Note:</strong> if your cPanel already has date-format accounts (e.g. stu231001 through stu260999), sequential 4-digit numbers like 5001 are completely safe — there is no overlap between 4-digit and 6-digit numbers on the same prefix.';
$string['email_next_number']        = 'Next Number (auto-managed)';
$string['email_next_number_desc']   = 'The next number that will be assigned. Do not change this unless you need to reset the sequence.';
$string['email_quota_mb']           = 'Default Mailbox Quota (MB)';
$string['email_quota_mb_desc']      = 'Default storage quota per student mailbox in megabytes. 1024 = 1 GB. Set 0 for unlimited.';
$string['webmail_url']              = 'Roundcube Webmail URL';
$string['webmail_url_desc']         = 'Full URL to your Roundcube installation (e.g. https://webmail.students.college.edu.au). Students are redirected here when they click "My Email".';
$string['auto_provision']           = 'Auto-create email on new enrolment';
$string['auto_provision_desc']      = 'When a student is enrolled in Moodle, automatically create their email account on cPanel.';
$string['welcome_email_enabled']    = 'Send welcome email with credentials on provisioning';
$string['welcome_email_enabled_desc'] = 'When enabled, a one-time welcome email is automatically sent to the student\'s personal email address (stored in their Moodle profile) when their college email account is created. The email contains their college email address and password so they can log in. Admins can also resend this at any time using the "Resend Creds" button in the dashboard.';
$string['welcome_email_subject']    = 'Your {$a} email account is ready';
$string['welcome_email_body']       = 'Hi {$a->firstname},

Your college email account has been set up. Use the details below to log in to {$a->site}.

College email address: {$a->email}
Password: {$a->password}

Log in here: {$a->loginurl}

Once logged in, you can access your college email inbox by clicking "My Email" in the navigation menu.

Please keep these details safe. If you ever need your password reset, contact your administrator.

This is an automated message — please do not reply.';
$string['auto_suspend']             = 'Auto-suspend email when user is suspended';
$string['auto_suspend_desc']        = 'When a Moodle user account is suspended, block their webmail access.';
$string['auto_archive']             = 'Auto-archive email when user is deleted';
$string['auto_archive_desc']        = 'When a Moodle user is deleted, change the mailbox password so they can no longer log in.';
$string['auto_restore']             = 'Auto-restore email when user is reactivated';
$string['auto_restore_desc']        = 'When a suspended Moodle user is unsuspended, restore their webmail access.';

// Dashboard
$string['dashboard']                = 'Dashboard';
$string['dashboard_subtitle']       = 'Manage student email accounts provisioned on cPanel';
$string['settings_nav']             = 'Settings';
$string['stat_total']               = 'Total Students';
$string['stat_active']              = 'Email Accounts';
$string['stat_missing']             = 'Missing';
$string['stat_suspended']           = 'Suspended';
$string['stat_archived']            = 'Archived';
$string['stat_error']               = 'Errors';
$string['action_create_missing']    = 'Create Missing';
$string['action_sync']              = 'Sync Moodle Users';
$string['action_suspend_leavers']   = 'Suspend Leavers';
$string['action_download_report']   = 'Download Report';
$string['search_placeholder']       = 'Search by name, username or email...';
$string['filter_all']               = 'All';
$string['filter_active']            = 'Active';
$string['filter_missing']           = 'Missing';
$string['filter_suspended']         = 'Suspended';
$string['filter_archived']          = 'Archived';
$string['filter_error']             = 'Errors';
$string['col_student']              = 'Student';
$string['col_email']                = 'Email Address';
$string['col_status']               = 'Status';
$string['col_created']              = 'Created';
$string['col_actions']              = 'Actions';
$string['status_active']            = 'Active';
$string['status_suspended']         = 'Suspended';
$string['status_archived']          = 'Archived';
$string['status_pending']           = 'Pending';
$string['status_error']             = 'Error';
$string['status_none']              = 'No email';
$string['btn_create']               = 'Create Email';
$string['btn_suspend']              = 'Suspend';
$string['btn_restore']              = 'Restore';
$string['btn_archive']              = 'Archive';
$string['btn_reset_password']       = 'Reset Password';
$string['btn_view_webmail']         = 'Open Webmail';
$string['confirm_create_missing']   = 'This will create email accounts for all {$a} students who do not have one. Continue?';
$string['confirm_suspend_leavers']  = 'This will suspend email accounts for all users who are suspended in Moodle. Continue?';
$string['no_students']              = 'No students found matching your search.';
$string['loading']                  = 'Loading...';
$string['success_created']          = 'Email account created: {$a}';
$string['success_suspended']        = 'Email account suspended.';
$string['success_restored']         = 'Email account restored.';
$string['success_archived']         = 'Email account archived.';
$string['success_password_reset']   = 'Password reset. New password sent to admin.';
$string['error_nocpanel']           = 'cPanel connection not configured. Please visit Site Admin → Plugins → Student Email Manager → Settings.';
$string['error_cpanel_fail']        = 'cPanel API error: {$a}';
$string['error_already_exists']     = 'Email account already exists for this student.';
$string['error_no_email']           = 'This student does not have a provisioned email account yet.';
$string['error_format']             = 'Could not generate a valid email address for this user. Check the email format settings.';

// Mailbox client settings
$string['settings_imap']            = 'IMAP (Incoming Mail)';
$string['settings_imap_desc']       = 'Required for students to read email in the built-in mailbox. Use your cPanel mail server details. Most cPanel servers accept IMAP on port 993 (SSL). If you also use the Student Email IMAP Auth plugin, its settings will be used instead of these.';
$string['imap_host']                = 'IMAP Hostname';
$string['imap_host_desc']           = 'Mail server hostname for incoming mail (e.g. webmail.college.edu.au or mail.college.edu.au). Usually the same server as your SMTP host.';
$string['imap_port']                = 'IMAP Port';
$string['imap_port_desc']           = '993 for SSL (recommended), 143 for STARTTLS.';
$string['imap_encryption']          = 'IMAP Encryption';
$string['imap_encryption_desc']     = 'Encryption method for the IMAP connection. SSL (port 993) is recommended for cPanel servers.';
$string['imap_enc_ssl']             = 'SSL (port 993 — recommended)';
$string['imap_enc_tls']             = 'STARTTLS (port 143)';
$string['imap_enc_none']            = 'None (not recommended)';
$string['imap_novalidate']          = 'Skip SSL certificate validation';
$string['imap_novalidate_desc']     = 'Tick this if your mail server uses a self-signed certificate and the connection fails with a certificate error. Not recommended for production.';
$string['settings_mailbox']         = 'Student Email Client';
$string['settings_mailbox_desc']    = 'Enable the built-in email client so students can read and send email directly inside Moodle. Requires the PHP imap extension and the SMTP settings below. When enabled, "My Email" in the navigation opens the full mailbox instead of the credentials page.';
$string['mailbox_enabled']          = 'Enable built-in mailbox';
$string['mailbox_enabled_desc']     = 'When enabled, students clicking "My Email" in the navigation go to the native email client inside Moodle. When disabled, they see the credentials page with a link to Roundcube.';
$string['settings_smtp']            = 'SMTP (Outgoing Mail)';
$string['settings_smtp_desc']       = 'Required for students to send email from the built-in mailbox. Use your cPanel mail server details. Most cPanel servers accept SMTP on port 587 (STARTTLS) or 465 (SSL). The same hostname you use for IMAP usually works for SMTP.';
$string['smtp_host']                = 'SMTP Hostname';
$string['smtp_host_desc']           = 'Mail server hostname for outgoing mail (e.g. mail.students.college.edu.au). Usually the same as your IMAP host.';
$string['smtp_port']                = 'SMTP Port';
$string['smtp_port_desc']           = '587 for STARTTLS (recommended), 465 for SSL, 25 for plain (not recommended).';
$string['smtp_encryption']          = 'SMTP Encryption';
$string['smtp_encryption_desc']     = 'Encryption method for the SMTP connection. Use TLS (port 587) or SSL (port 465) for cPanel servers.';
$string['smtp_enc_tls']             = 'STARTTLS (port 587 — recommended)';
$string['smtp_enc_ssl']             = 'SSL (port 465)';
$string['smtp_enc_none']            = 'None (not recommended)';

// Mailbox page
$string['mailbox_title']            = 'Student Email';
$string['mailbox_no_account']       = 'No email account found';
$string['mailbox_no_account_desc']  = 'You do not have a college email account set up yet. Please contact your administrator.';
$string['compose']                  = 'Compose';
$string['back_to_inbox']            = 'Back';

// Phase 2 — mailbox UI additions
$string['reply_all']                = 'Reply All';
$string['mark_unread']              = 'Mark Unread';
$string['move_to']                  = 'Move to';
$string['move_done']                = 'Moved to {$a}';
$string['star_message']             = 'Star message';
$string['unstar_message']           = 'Remove star';
$string['search_server']            = 'Search';
$string['search_clear']             = 'Clear search';
$string['pagination_info']          = '{$a->from}–{$a->to} of {$a->total}';
$string['prev_page']                = 'Previous';
$string['next_page']                = 'Next';
$string['cc_label']                 = 'CC';
$string['signature_label']          = 'Signature';
$string['signature_edit']           = 'Edit signature';
$string['signature_save']           = 'Save signature';
$string['signature_saved']          = 'Signature saved';
$string['signature_placeholder']    = 'Your email signature (e.g. name, job title, contact)';
$string['attach_download']          = 'Download';
$string['error_attachment_toolarge'] = 'This attachment is too large to download here (max 20 MB). Please access it via webmail.';
$string['error_noimap']             = 'The PHP imap extension is not installed on this Moodle server. Please ask your server administrator to enable it.';
$string['error_imap_connect']       = 'Could not connect to the mail server: {$a}';
$string['error_message_notfound']   = 'Message not found or could not be read.';
$string['error_nosmtp']             = 'SMTP (outgoing mail) is not configured. Please ask your administrator to set up SMTP settings for this plugin.';
$string['error_lock']                = 'Could not acquire the email numbering lock. Please try again in a moment.';
$string['error_smtp_send']          = 'Failed to send message: {$a}';

// Webmail portal
$string['webmail_title']            = 'My Email';
$string['webmail_notconfigured']    = 'The webmail URL has not been configured. Please contact your administrator.';
$string['webmail_noaccount']        = 'You do not have a college email account yet. Please contact your administrator.';
$string['webmail_suspended']        = 'Your email account has been suspended. Please contact your college.';
$string['webmail_archived']         = 'Your email account has been archived. Please contact your college.';
$string['webmail_redirecting']      = 'Opening your email inbox...';
$string['webmail_open_manually']    = 'Click here if you are not redirected automatically';
$string['webmail_youraddress']      = 'Your email address: {$a}';

// Privacy API.
$string['privacy:metadata:accounts'] = 'Details of the cPanel mailbox provisioned for each student.';
$string['privacy:metadata:accounts:userid'] = 'The ID of the Moodle user the mailbox belongs to.';
$string['privacy:metadata:accounts:email'] = 'The full email address provisioned for the user.';
$string['privacy:metadata:accounts:emailpassword'] = 'The encrypted mailbox password used to sign the user in to their mailbox.';
$string['privacy:metadata:accounts:studentnumber'] = 'The sequential student number assigned when the autonumber address format is used.';
$string['privacy:metadata:accounts:status'] = 'The status of the mailbox, such as active, suspended, archived, pending or error.';
$string['privacy:metadata:accounts:quotamb'] = 'The mailbox storage quota in megabytes.';
$string['privacy:metadata:accounts:notes'] = 'Administrator notes and error messages recorded against the mailbox.';
$string['privacy:metadata:accounts:timecreated'] = 'The time the mailbox record was created.';
$string['privacy:metadata:accounts:timemodified'] = 'The time the mailbox record was last modified.';
$string['privacy:metadata:preference:signature'] = 'The email signature the user has saved for outgoing messages.';
$string['privacy:metadata:cpanel'] = 'Mailbox data exchanged with the external cPanel mail server that hosts the student mailboxes. Messages, drafts and sent copies are stored on that server and are not controlled by Moodle.';
$string['privacy:metadata:cpanel:fullname'] = 'The full name of the user, sent as the display name of the mailbox and of outgoing messages.';
$string['privacy:metadata:cpanel:email'] = 'The email address of the mailbox created or accessed on the mail server.';
$string['privacy:metadata:cpanel:emailpassword'] = 'The mailbox password, sent to create the account and to authenticate IMAP and SMTP sessions.';
$string['privacy:metadata:cpanel:quota'] = 'The mailbox storage quota requested for the account.';
$string['privacy:metadata:cpanel:messages'] = 'The content of messages the user reads, sends, or saves as drafts, including recipients, subjects, bodies and attachments.';
$string['privacy:path:account'] = 'Student email account';
$string['privacy:export:passwordwithheld'] = 'Withheld: mailbox credential not exported.';

// Compose, drafts and sent copies.
$string['savedraft'] = 'Save draft';
$string['savingdraft'] = 'Saving...';
$string['discard'] = 'Discard';
$string['restore'] = 'Restore';
$string['draft_saving'] = 'Saving draft...';
$string['draft_saved'] = 'Draft saved.';
$string['draft_savedto'] = 'Draft saved to {$a->folder} at {$a->time}';
$string['draft_savefailed'] = 'The draft could not be saved on the mail server. A copy is kept in this browser.';
$string['draft_restored'] = 'Restored your unsaved draft.';
$string['draft_restoredlocal'] = 'Draft restored from this browser. Save it to keep it on the mail server.';
$string['draft_editing'] = 'Editing draft. Changes are saved back to the Drafts folder.';
$string['draft_discardconfirm'] = 'Discard this message? Any saved draft will be deleted.';
$string['draft_unsaved'] = 'You have an unsaved draft.';
$string['draft_unsaveddetail'] = 'Unsaved draft "{$a->subject}" from {$a->time}.';
$string['draft_nodraftsfolder'] = 'The Drafts folder is not available on the mail server.';
$string['draft_notsaved'] = 'The draft could not be saved on the mail server: {$a}';
$string['sent_filed'] = 'A copy has been filed in your Sent folder.';
$string['messagesent'] = 'Message sent.';
$string['success_linked_existing'] = 'Linked to the existing mailbox {$a} instead of creating a second one.';
$string['success_unlinked'] = 'Unlinked from {$a}. The mailbox and its mail are untouched on the server.';
$string['error_notlinked'] = 'This student is not linked to a mailbox.';
$string['error_mailboxclaimed'] = 'The mailbox {$a->email} is already linked to {$a->name}. Unlink it from that student first.';
$string['prefer_existing_mailbox'] = 'Use an existing mailbox when one is found';
$string['prefer_existing_mailbox_desc'] = 'Before creating a mailbox for a student, check whether one already exists for them on the mail server and link that instead. Matching is on exact address only, and a mailbox already linked to another student is never used. Turn this off to always create a new address, which can leave a student with two mailboxes: one that Moodle sends to, and another that the built-in mailbox opens.';
$string['sent_notfiled'] = 'Message sent, but the copy could not be saved to your Sent folder. Please tell your administrator.';
$string['sent_filedto'] = 'A copy has been filed in {$a}.';
