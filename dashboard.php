<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student Email Manager — admin dashboard.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/studentemail/classes/cpanel_api.php');
require_once($CFG->dirroot . '/local/studentemail/classes/email_manager.php');
require_once($CFG->dirroot . '/local/studentemail/classes/unlock_verifier.php');

use local_studentemail\email_manager;

require_login();
$systemcontext = context_system::instance();
require_capability('local/studentemail:manage', $systemcontext);

// Handle CSV download before any output.
$download = optional_param('download', '', PARAM_ALPHA);
if ($download === 'csv') {
    require_sesskey();
    $manager = new email_manager();
    $csv = $manager->generate_csv_report();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_email_report_' . date('Y-m-d') . '.csv"');
    echo $csv;
    exit;
}

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/studentemail/dashboard.php'));
$PAGE->set_title(get_string('dashboard', 'local_studentemail'));
$PAGE->set_heading(get_string('dashboard', 'local_studentemail'));
$PAGE->set_pagelayout('admin');

admin_externalpage_setup('local_studentemail_dashboard');

$manager = new email_manager();
$api     = new \local_studentemail\cpanel_api();
$stats   = $manager->get_stats();

$ajax_url      = (new moodle_url('/local/studentemail/ajax.php'))->out(false);
$download_url  = (new moodle_url('/local/studentemail/dashboard.php', ['download' => 'csv', 'sesskey' => sesskey()]))->out(false);
$settings_url  = (new moodle_url('/admin/settings.php', ['section' => 'local_studentemail']))->out(false);
$sesskey       = sesskey();

// Detect "migration" scenario: many unlinked students, no active accounts.
// Show a prominent import notice in this case.
$show_import_notice = ($stats['missing'] > 0 && $stats['active'] === 0);

echo $OUTPUT->header();

// Credit-unlock banner — shown above the dashboard UI when plugin is locked.
\local_studentemail\unlock_verifier::check_and_notify();
?>
<style>
.sem-dashboard { max-width: 1200px; margin: 0 auto; padding: 0 1rem 2rem; }

/* Header */
.sem-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
.sem-header h2 { margin: 0; font-size: 1.4rem; font-weight: 700; }
.sem-header-actions { display: flex; gap: .5rem; flex-wrap: wrap; }

/* Stats */
.sem-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.sem-stat { position: relative; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.2rem 1rem; text-align: center; cursor: pointer; transition: box-shadow .15s; }
.sem-stat:hover { box-shadow: 0 2px 8px rgba(0,0,0,.1); }
.sem-stat.active-filter { border-color: #0066cc; box-shadow: 0 0 0 2px #cce0ff; }
.sem-stat-value { font-size: 2rem; font-weight: 800; margin-bottom: .2rem; }
.sem-stat-label { font-size: .78rem; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
.stat-total     .sem-stat-value { color: #1e293b; }
.stat-active    .sem-stat-value { color: #16a34a; }
.stat-missing   .sem-stat-value { color: #d97706; }
.stat-suspended .sem-stat-value { color: #7c3aed; }
.stat-archived  .sem-stat-value { color: #64748b; }
.stat-error     .sem-stat-value { color: #dc2626; }
/* Card tooltips */
.sem-stat[data-tip]:after { content: attr(data-tip); position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; font-size: .75rem; font-weight: 400; line-height: 1.45; white-space: normal; width: 200px; padding: .5rem .65rem; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,.18); opacity: 0; pointer-events: none; transition: opacity .15s; z-index: 9999; text-transform: none; letter-spacing: 0; text-align: left; }
.sem-stat[data-tip]:before { content: ''; position: absolute; bottom: calc(100% + 3px); left: 50%; transform: translateX(-50%); border: 5px solid transparent; border-top-color: #1e293b; opacity: 0; pointer-events: none; transition: opacity .15s; z-index: 9999; }
.sem-stat[data-tip]:hover:after, .sem-stat[data-tip]:hover:before { opacity: 1; }

/* Notices */
.sem-notice { border-radius: 8px; padding: 1.1rem 1.2rem; margin-bottom: 1.2rem; font-size: .875rem; line-height: 1.5; }
.sem-notice-import { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.sem-notice-import strong { display: block; margin-bottom: .3rem; font-size: .95rem; }
.sem-notice-import .sem-notice-steps { margin: .6rem 0 0; padding-left: 1.2rem; }
.sem-notice-import .sem-notice-steps li { margin-bottom: .25rem; }
.sem-nocfg { background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 1.2rem; margin-bottom: 1.5rem; font-size: .875rem; }
.sem-nocfg a { color: #92400e; font-weight: 600; }

/* Toolbar */
.sem-toolbar { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.sem-search { flex: 1; min-width: 200px; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: .9rem; }
.sem-filter-select { padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: .9rem; background: #fff; cursor: pointer; min-width: 150px; }
.sem-filter-select:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 2px #cce0ff; }
.sem-bulk-actions { display: flex; gap: .5rem; flex-wrap: wrap; }

/* Table */
.sem-table-wrap { overflow-x: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
.sem-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.sem-table th { background: #f8fafc; padding: .75rem 1rem; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.sem-table th small { display: block; font-weight: 400; font-size: .72rem; color: #94a3b8; margin-top: .1rem; }
.sem-table td { padding: .65rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.sem-table tr:last-child td { border-bottom: none; }
.sem-table tr:hover td { background: #f8fafc; }

/* Badges */
.sem-badge { display: inline-block; padding: .2rem .55rem; border-radius: 9999px; font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.badge-active    { background: #dcfce7; color: #15803d; }
.badge-suspended { background: #ede9fe; color: #7c3aed; }
.badge-archived  { background: #f1f5f9; color: #64748b; }
.badge-error     { background: #fee2e2; color: #b91c1c; }
.badge-none      { background: #fef3c7; color: #92400e; }

/* Action buttons */
.sem-actions { display: flex; gap: .35rem; flex-wrap: wrap; align-items: center; }
.sem-btn { padding: .3rem .65rem; border: none; border-radius: 5px; cursor: pointer; font-size: .78rem; font-weight: 500; transition: opacity .15s; white-space: nowrap; }
.sem-btn:hover { opacity: .82; color: inherit; }
.sem-btn:disabled { opacity: .4; cursor: not-allowed; }
.btn-create   { background: #0066cc; color: #fff; }
.btn-create:hover   { color: #fff; }
.btn-link     { background: #0891b2; color: #fff; }
.btn-link:hover     { color: #fff; }
.btn-suspend  { background: #7c3aed; color: #fff; }
.btn-suspend:hover  { color: #fff; }
.btn-restore  { background: #16a34a; color: #fff; }
.btn-restore:hover  { color: #fff; }
.btn-archive  { background: #64748b; color: #fff; }
.btn-archive:hover  { color: #fff; }
.btn-reset    { background: #d97706; color: #fff; }
.btn-reset:hover    { color: #fff; }
.btn-webmail  { background: #0f172a; color: #fff; }
.btn-webmail:hover  { color: #fff; }
.btn-bulk     { background: #1e293b; color: #fff; padding: .45rem .9rem; font-size: .82rem; border-radius: 6px; border: none; cursor: pointer; }
.btn-bulk:hover { opacity: .85; color: #fff; text-decoration: none; }
.btn-bulk-import { background: #1d4ed8; color: #fff; padding: .45rem .9rem; font-size: .82rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; }
.btn-bulk-import:hover { opacity: .88; color: #fff; text-decoration: none; }
.btn-bulk-outline { background: #fff; color: #1e293b; border: 1px solid #cbd5e1; padding: .45rem .9rem; font-size: .82rem; border-radius: 6px; cursor: pointer; }
.btn-bulk-outline:hover { background: #f8fafc; color: #1e293b; border-color: #94a3b8; text-decoration: none; }

/* Inline link form */
.sem-link-form { display: none; align-items: center; gap: .35rem; margin-top: .4rem; flex-wrap: wrap; }
.sem-link-form select { padding: .28rem .5rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: .78rem; min-width: 220px; max-width: 300px; background: #fff; cursor: pointer; }
.sem-link-form input { padding: .28rem .5rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: .78rem; width: 200px; }
.sem-link-form.open { display: flex; }
.sem-link-loading { font-size: .78rem; color: #64748b; font-style: italic; }

/* Pagination */
.sem-pagination { display: flex; align-items: center; gap: .5rem; padding: .75rem 1rem; justify-content: flex-end; border-top: 1px solid #e2e8f0; }
.sem-page-info { font-size: .82rem; color: #64748b; flex: 1; }
.sem-empty { text-align: center; padding: 3rem 1rem; color: #94a3b8; }
.sem-loading { text-align: center; padding: 2rem; color: #94a3b8; }

/* Alerts */
.sem-alert { padding: .75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: .875rem; }
.sem-alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.sem-alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

/* Note pill */
.sem-ext-note { font-size: .7rem; color: #94a3b8; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: .1rem .4rem; display: inline-block; margin-top: .25rem; }

/* Instructions panel */
/* ── How to use panel ───────────────────────────────────────── */
.sem-instructions { border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 1.5rem; background: #fff; overflow: hidden; }
.sem-instructions-header { display: flex; align-items: center; gap: .6rem; padding: .85rem 1.1rem; cursor: pointer; user-select: none; transition: background .15s; }
.sem-instructions-header:hover { background: #f8fafc; }
.sem-instructions-icon { font-size: 1rem; color: #3b82f6; flex-shrink: 0; }
.sem-instructions-title { font-size: .875rem; font-weight: 600; color: #0f172a; flex: 1; letter-spacing: -.01em; }
.sem-instructions-chevron { width: 16px; height: 16px; flex-shrink: 0; color: #94a3b8; transition: transform .2s; }
.sem-instructions-chevron:before { content: '\276F'; font-size: .7rem; display: block; transform: rotate(90deg); transition: transform .2s; }
.sem-instructions.sem-instructions-open .sem-instructions-chevron:before { transform: rotate(-90deg); }
.sem-instructions-body { display: none; border-top: 1px solid #f1f5f9; background: #fff; }
.sem-instructions.sem-instructions-open .sem-instructions-body { display: block; }

/* Two-column layout for the six steps */
.sem-instr-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.sem-instr-col { padding: 1.25rem 1.4rem; }
.sem-instr-col:first-child { border-right: 1px solid #f1f5f9; }

/* Individual step */
.sem-instr-step { display: flex; gap: .9rem; padding: .9rem 0; }
.sem-instr-step + .sem-instr-step { border-top: 1px solid #f1f5f9; }
.sem-instr-step-badge { width: 24px; height: 24px; border-radius: 50%; background: #1d4ed8; color: #fff; font-size: .72rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: .15rem; }
.sem-instr-step-body { flex: 1; min-width: 0; }
.sem-instr-step-title { font-size: .82rem; font-weight: 700; color: #0f172a; margin: 0 0 .3rem; line-height: 1.35; }
.sem-instr-step-text { font-size: .8rem; color: #475569; line-height: 1.6; margin: 0; }

/* Action list */
.sem-instr-actions { margin: .5rem 0 0; padding: 0; list-style: none; }
.sem-instr-actions li { display: flex; gap: .5rem; font-size: .79rem; color: #475569; line-height: 1.5; padding: .18rem 0; }
.sem-instr-actions li:before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0; margin-top: .48rem; }
.sem-instr-actions li strong { color: #1e293b; font-weight: 600; white-space: nowrap; }

/* Warning callout */
.sem-instr-warn { display: flex; gap: .5rem; align-items: flex-start; margin-top: .65rem; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 6px; padding: .55rem .7rem; font-size: .78rem; color: #92400e; line-height: 1.5; }
.sem-instr-warn-icon { flex-shrink: 0; margin-top: .05rem; }

@media (max-width: 760px) { .sem-instr-cols { grid-template-columns: 1fr; } .sem-instr-col:first-child { border-right: none; border-bottom: 1px solid #f1f5f9; } }

/* Data Quality modal */
.sem-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 10000; align-items: flex-start; justify-content: center; padding: 2rem 1rem; overflow-y: auto; }
.sem-modal-overlay.open { display: flex; }
.sem-modal { background: #fff; border-radius: 10px; width: 100%; max-width: 860px; box-shadow: 0 8px 32px rgba(0,0,0,.22); overflow: hidden; }
.sem-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
.sem-modal-head h3 { margin: 0; font-size: 1rem; font-weight: 700; color: #1e293b; }
.sem-modal-close { background: none; border: none; font-size: 1.4rem; line-height: 1; cursor: pointer; color: #64748b; padding: 0 .25rem; }
.sem-modal-close:hover { color: #1e293b; }
.sem-modal-body { padding: 1.25rem; }
.sem-dq-section { margin-bottom: 1.5rem; }
.sem-dq-section:last-child { margin-bottom: 0; }
.sem-dq-title { font-size: .85rem; font-weight: 700; color: #1e293b; margin: 0 0 .5rem; display: flex; align-items: center; gap: .4rem; }
.sem-dq-badge { display: inline-flex; align-items: center; justify-content: center; background: #dc2626; color: #fff; font-size: .68rem; font-weight: 800; border-radius: 9999px; min-width: 1.25rem; height: 1.25rem; padding: 0 .35rem; }
.sem-dq-badge.ok { background: #16a34a; }
.sem-dq-none { font-size: .82rem; color: #16a34a; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: .45rem .7rem; }
.sem-dq-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.sem-dq-table th { background: #f8fafc; padding: .5rem .75rem; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; }
.sem-dq-table td { padding: .5rem .75rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.sem-dq-table tr:last-child td { border-bottom: none; }
.sem-dq-table tr:hover td { background: #f8fafc; }
.sem-dq-account { background: #f1f5f9; border-radius: 6px; padding: .3rem .5rem; margin-bottom: .25rem; }
.sem-dq-account:last-child { margin-bottom: 0; }
.sem-dq-account .lbl { font-weight: 600; color: #475569; font-size: .75rem; }
.sem-dq-account .val { color: #1e293b; }
.sem-dq-account .dim { color: #94a3b8; font-size: .75rem; }
.sem-dq-warn { color: #d97706; font-weight: 600; }
.sem-dq-loading { text-align: center; color: #64748b; padding: 2rem; font-size: .9rem; }
.sem-dq-divider { border: none; border-top: 2px solid #e2e8f0; margin: 1.25rem 0; }
</style>

<div class="sem-dashboard">

  <?php if (!$api->is_configured()): ?>
  <div class="sem-nocfg">
    cPanel connection not yet configured. <a href="<?php echo $settings_url; ?>">Open Settings</a> to enter your cPanel hostname, username, API token, and email domain before using any features below.
  </div>
  <?php endif; ?>

  <?php if ($show_import_notice): ?>
  <div class="sem-notice sem-notice-import">
    <strong>Existing cPanel accounts detected — link them before creating new ones</strong>
    Your students are showing as "Not Linked" because this plugin was installed after their
    cPanel email accounts were already created. The plugin has no record of those accounts yet.
    <ol class="sem-notice-steps">
      <li><strong>Click "Import Existing Accounts"</strong> — the plugin will fetch all cPanel accounts for your domain and automatically match them to Moodle users by username. Most accounts will link in seconds.</li>
      <li><strong>Review the results</strong> — any students who couldn't be matched automatically will still show "Not Linked". Use the per-row <em>Link</em> button to enter their email address manually.</li>
      <li><strong>From now on</strong>, new student enrolments will be provisioned automatically (if auto-provisioning is enabled in Settings).</li>
    </ol>
    <p style="margin:.6rem 0 0;"><strong>Do not click "Create Missing"</strong> — that would try to create duplicate accounts in cPanel for students who already have one.</p>
  </div>
  <?php endif; ?>

  <div class="sem-header">
    <div>
      <h2><?php echo get_string('dashboard', 'local_studentemail'); ?></h2>
      <p style="margin:.25rem 0 0;color:#64748b;font-size:.85rem;"><?php echo get_string('dashboard_subtitle', 'local_studentemail'); ?></p>
    </div>
    <div class="sem-header-actions">
      <button class="btn-bulk-outline" onclick="runDiagnose()" title="Diagnose why Import Existing is not matching — shows raw cPanel API response and candidate matching for the first 5 unlinked students." style="border-color:#d97706;color:#d97706;">Diagnose Import</button>
      <button class="btn-bulk-outline" onclick="openDataQuality()" title="Run data quality checks: duplicate Moodle accounts, multiple email records per student, orphaned records, suspended mismatches.">Data Quality Report</button>
      <a href="<?php echo $settings_url; ?>" class="btn-bulk-outline" style="text-decoration:none;display:inline-block;">Settings</a>
      <a href="<?php echo $download_url; ?>" class="btn-bulk" style="text-decoration:none;display:inline-block;"><?php echo get_string('action_download_report', 'local_studentemail'); ?></a>
    </div>
  </div>

  <!-- Data Quality Modal -->
  <div class="sem-modal-overlay" id="sem-dq-overlay" onclick="if(event.target===this)closeDataQuality()">
    <div class="sem-modal">
      <div class="sem-modal-head">
        <h3>Data Quality Report</h3>
        <button class="sem-modal-close" onclick="closeDataQuality()" title="Close">&times;</button>
      </div>
      <div class="sem-modal-body" id="sem-dq-body">
        <div class="sem-dq-loading">Running checks…</div>
      </div>
    </div>
  </div>

  <!-- Stats cards -->
  <div class="sem-stats" id="sem-stats">
    <div class="sem-stat stat-total"     data-filter="all"       onclick="setFilter('all')"       data-tip="All active Moodle users. Click to clear any filter and show everyone.">
      <div class="sem-stat-value" id="stat-total"><?php echo $stats['total']; ?></div>
      <div class="sem-stat-label">Total Students</div>
    </div>
    <div class="sem-stat stat-active"    data-filter="active"    onclick="setFilter('active')"    data-tip="Students with a working linked mailbox. They can log in to cPanel email right now.">
      <div class="sem-stat-value" id="stat-active"><?php echo $stats['active']; ?></div>
      <div class="sem-stat-label">Linked &amp; Active</div>
    </div>
    <div class="sem-stat stat-missing"   data-filter="missing"   onclick="setFilter('missing')"   data-tip="No mailbox linked yet. They may already have a cPanel account — run Import Existing first. Otherwise use Create New.">
      <div class="sem-stat-value" id="stat-missing"><?php echo $stats['missing']; ?></div>
      <div class="sem-stat-label">Not Linked</div>
    </div>
    <div class="sem-stat stat-suspended" data-filter="suspended" onclick="setFilter('suspended')" data-tip="cPanel login disabled — mailbox and emails are kept. Use Restore in the Actions column to reinstate access.">
      <div class="sem-stat-value" id="stat-suspended"><?php echo $stats['suspended']; ?></div>
      <div class="sem-stat-label">Suspended</div>
    </div>
    <div class="sem-stat stat-archived"  data-filter="archived"  onclick="setFilter('archived')"  data-tip="The Moodle account was deleted but the cPanel mailbox is kept for records. No action usually needed.">
      <div class="sem-stat-value" id="stat-archived"><?php echo $stats['archived']; ?></div>
      <div class="sem-stat-label">Archived</div>
    </div>
    <div class="sem-stat stat-error"     data-filter="error"     onclick="setFilter('error')"     data-tip="Last cPanel API call failed for these students. Click to filter, then retry the action — usually a temporary cPanel outage or a credentials issue.">
      <div class="sem-stat-value" id="stat-error"><?php echo $stats['error']; ?></div>
      <div class="sem-stat-label">Errors</div>
    </div>
  </div>

  <!-- Alert area -->
  <div id="sem-alert" style="display:none;"></div>

  <!-- How to use instructions -->
  <div class="sem-instructions">
    <div class="sem-instructions-header" onclick="this.parentElement.classList.toggle('sem-instructions-open')">
      <span class="sem-instructions-icon">&#9432;</span>
      <span class="sem-instructions-title">How to use this page</span>
      <span class="sem-instructions-chevron"></span>
    </div>
    <div class="sem-instructions-body">
      <div class="sem-instr-cols">

        <div class="sem-instr-col">

          <div class="sem-instr-step">
            <div class="sem-instr-step-badge">1</div>
            <div class="sem-instr-step-body">
              <p class="sem-instr-step-title">Connect to cPanel &mdash; do this once before anything else</p>
              <p class="sem-instr-step-text">Click <strong>Settings</strong> (top-right cog) and enter your cPanel hostname, username, API token, and email domain. Every action on this page &mdash; creating accounts, linking mailboxes, resetting passwords &mdash; talks directly to your cPanel server. Nothing will work until these credentials are saved and the <em>Test cPanel</em> button confirms a live connection.</p>
            </div>
          </div>

          <div class="sem-instr-step">
            <div class="sem-instr-step-badge">2</div>
            <div class="sem-instr-step-body">
              <p class="sem-instr-step-title">Import before you create &mdash; avoid duplicates</p>
              <p class="sem-instr-step-text">If students already have cPanel mailboxes (set up before this plugin), click <strong>Import Existing Accounts</strong> first. The plugin fetches every cPanel mailbox and auto-matches them to Moodle students by username &mdash; most link in seconds. Do this before running &ldquo;Create New&rdquo;, otherwise the plugin will try to create a second mailbox for a student who already has one, causing a cPanel duplicate error.</p>
              <div class="sem-instr-warn">
                <span class="sem-instr-warn-icon">&#9888;</span>
                <span>Skip to Step 3 only if students have <strong>no</strong> existing cPanel mailboxes at all.</span>
              </div>
            </div>
          </div>

        </div>

        <div class="sem-instr-col">

          <div class="sem-instr-step">
            <div class="sem-instr-step-badge">3</div>
            <div class="sem-instr-step-body">
              <p class="sem-instr-step-title">Provision everyone still showing Not Linked</p>
              <p class="sem-instr-step-text">After importing, any student without a matched mailbox still shows <em>Not Linked</em>. Either click <strong>Create New Accounts</strong> to bulk-provision a fresh mailbox for all of them at once, or use the per-row <strong>Link Existing</strong> button to pick their cPanel account from a dropdown if one exists under a different username. Enable <em>Auto-provisioning</em> in Settings so every new enrolment is handled automatically from this point on.</p>
            </div>
          </div>

          <div class="sem-instr-step">
            <div class="sem-instr-step-badge">4</div>
            <div class="sem-instr-step-body">
              <p class="sem-instr-step-title">Day-to-day: use the Actions column and status cards</p>
              <p class="sem-instr-step-text">The <strong>Actions</strong> column on each row handles individual students: <strong>Suspend</strong> disables cPanel login without deleting the mailbox (use when a student leaves or pauses); <strong>Restore</strong> re-enables it; <strong>Reset Password</strong> generates a new credential and pushes it to cPanel instantly. <strong>Hover over any status card</strong> above for a plain-English explanation, or click a card to filter the list to just that group.</p>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="sem-toolbar">
    <input type="text" id="sem-search" class="sem-search" placeholder="<?php echo get_string('search_placeholder', 'local_studentemail'); ?>" oninput="debounceSearch(this.value)">
    <select id="sem-filter-select" class="sem-filter-select" onchange="setFilterFromDropdown(this.value)" title="Filter list by account status">
      <option value="all">All Students</option>
      <option value="missing">Not Linked</option>
      <option value="active">Active</option>
      <option value="suspended">Suspended</option>
      <option value="archived">Archived</option>
      <option value="error">Error</option>
    </select>
    <div class="sem-bulk-actions">
      <button class="btn-bulk-import" onclick="bulkImport()" title="Fetch all cPanel accounts for your domain and automatically link them to matching Moodle users. Safe to run multiple times.">
        Import Existing Accounts
      </button>
      <button class="btn-bulk" onclick="bulkAction('create_missing')" title="Create brand-new cPanel email accounts for students who have no account at all. Do NOT use this if students already have cPanel accounts — use Import instead.">
        Create New Accounts
      </button>
      <button class="btn-bulk-outline" onclick="bulkAction('suspend_leavers')" title="Suspend cPanel access for all Moodle users who are currently suspended.">
        Suspend Leavers
      </button>
      <button class="btn-bulk" onclick="fixMissingPasswords()" title="Auto-reset cPanel passwords for all imported accounts that have no stored password. Students can then open their mailbox immediately.">
        Fix Missing Passwords
      </button>
      <button class="btn-bulk" onclick="resetAllPasswords()" title="Generate fresh cPanel passwords for ALL active linked accounts. Use this after bulk-linking via 'Link Existing' to ensure every student can log in to their mailbox.">
        Reset All Passwords
      </button>
      <button class="btn-bulk-outline" onclick="testConnection()">Test cPanel</button>
      <button class="btn-bulk-outline" onclick="testImap()">Test IMAP</button>
      <button class="btn-bulk-outline" onclick="testSmtp()">Test SMTP</button>
    </div>
  </div>

  <!-- Table -->
  <div class="sem-table-wrap">
    <table class="sem-table">
      <thead>
        <tr>
          <th><?php echo get_string('col_student', 'local_studentemail'); ?></th>
          <th><?php echo get_string('col_email', 'local_studentemail'); ?>
            <small>Address linked to this plugin</small></th>
          <th><?php echo get_string('col_status', 'local_studentemail'); ?>
            <small>Not Linked = no record yet</small></th>
          <th><?php echo get_string('col_created', 'local_studentemail'); ?>
            <small>When plugin linked/created</small></th>
          <th><?php echo get_string('col_actions', 'local_studentemail'); ?></th>
        </tr>
      </thead>
      <tbody id="sem-tbody">
        <tr><td colspan="5" class="sem-loading">Loading...</td></tr>
      </tbody>
    </table>
    <div class="sem-pagination">
      <span class="sem-page-info" id="sem-page-info"></span>
      <button class="btn-bulk-outline" id="btn-prev" onclick="changePage(-1)">&#8592; Prev</button>
      <button class="btn-bulk-outline" id="btn-next" onclick="changePage(1)">Next &#8594;</button>
    </div>
  </div>
</div>

<script>
var AJAX_URL  = <?php echo json_encode($ajax_url); ?>;
var SESSKEY   = <?php echo json_encode($sesskey); ?>;
var WEBMAIL   = <?php echo json_encode(get_config('local_studentemail', 'webmail_url')); ?>;

var currentFilter = 'all';
var currentSearch = '';
var currentPage   = 0;
var perPage       = 50;
var totalRecords  = 0;
var searchTimer   = null;

function setFilter(filter) {
  currentFilter = filter;
  currentPage   = 0;
  document.querySelectorAll('.sem-stat').forEach(function (el) {
    el.classList.toggle('active-filter', el.dataset.filter === filter);
  });
  var sel = document.getElementById('sem-filter-select');
  if (sel) sel.value = filter;
  loadAccounts();
}

function setFilterFromDropdown(filter) {
  currentFilter = filter;
  currentPage   = 0;
  document.querySelectorAll('.sem-stat').forEach(function (el) {
    el.classList.toggle('active-filter', el.dataset.filter === filter);
  });
  loadAccounts();
}

function debounceSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(function () {
    currentSearch = val;
    currentPage   = 0;
    loadAccounts();
  }, 350);
}

function changePage(dir) {
  var maxPage = Math.ceil(totalRecords / perPage) - 1;
  currentPage = Math.max(0, Math.min(maxPage, currentPage + dir));
  loadAccounts();
}

function loadAccounts() {
  var tbody = document.getElementById('sem-tbody');
  tbody.innerHTML = '<tr><td colspan="5" class="sem-loading">Loading...</td></tr>';

  ajax('get_accounts', {
    search: currentSearch,
    filter: currentFilter,
    page:   currentPage,
    perpage: perPage
  }, function (resp) {
    if (!resp.success) { showAlert(resp.message, 'error'); return; }
    totalRecords = resp.data.total;
    renderTable(resp.data.records);
    renderPagination();
    loadStats();
  });
}

function renderTable(records) {
  var tbody = document.getElementById('sem-tbody');
  if (!records || records.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="sem-empty">No students found matching this filter.</td></tr>';
    return;
  }

  var html = '';
  records.forEach(function (r) {
    var email     = r.provisioned_email || '';
    var status    = r.status || 'none';
    var isLinked  = !!email && status !== 'none' && status !== 'error';
    var isExtPwd  = r.notes && r.notes.indexOf('managed externally') >= 0;

    var badgeClass = {
      active:    'badge-active',
      suspended: 'badge-suspended',
      archived:  'badge-archived',
      error:     'badge-error',
      none:      'badge-none'
    }[status] || 'badge-none';

    var badgeLabel = {
      active:    'Active',
      suspended: 'Suspended',
      archived:  'Archived',
      error:     'Error',
      pending:   'Pending',
      none:      'Not Linked'
    }[status] || status;

    // Build action buttons.
    var actions = '';
    if (!isLinked) {
      // Not yet linked — offer both automatic-create and manual link.
      actions += btn('Create New', 'btn-create',
        'doCreate(' + r.id + ')',
        'Create a brand-new cPanel mailbox for this student');
      actions += btn('Link Existing', 'btn-link',
        'toggleLinkForm(this,' + r.id + ')',
        'Link this student to a cPanel account that already exists');
    } else {
      if (status === 'active') {
        actions += btn('Suspend',      'btn-suspend', 'doSuspend(' + r.id + ')',  'Block this student\'s email access');
        actions += btn('Archive',      'btn-archive', 'doArchive(' + r.id + ')',  'Archive mailbox (student deleted from Moodle)');
        actions += btn('Reset PW',     'btn-reset',   'doResetPW(' + r.id + ')',  'Set a new password and display it here');
        actions += btn('Resend Creds', 'btn-reset',   'doResendWelcome(' + r.id + ')', 'Re-send college email address and password to student\'s personal email');
        actions += btn('Test Mailbox', 'btn-bulk-outline', 'testImapStudent(' + r.id + ',' + JSON.stringify(r.email || '') + ')', 'Deep IMAP diagnostic — shows exact folders, message counts, and which username format works');
        if (WEBMAIL) {
          actions += btn('Webmail', 'btn-webmail', "window.open('" + WEBMAIL + "','_blank')", 'Open webmail in new tab');
        }
      } else if (status === 'suspended') {
        actions += btn('Restore', 'btn-restore', 'doRestore(' + r.id + ')', 'Re-enable this student\'s email access');
        actions += btn('Archive', 'btn-archive', 'doArchive(' + r.id + ')', 'Archive and lock the mailbox');
        actions += btn('Reset PW', 'btn-reset', 'doResetPW(' + r.id + ')', 'Set a new password');
      } else if (status === 'archived') {
        actions += btn('Restore', 'btn-restore', 'doRestore(' + r.id + ')', 'Restore archived mailbox access');
        actions += btn('Reset PW', 'btn-reset', 'doResetPW(' + r.id + ')', 'Set a new password');
      } else if (status === 'error') {
        actions += btn('Retry Create', 'btn-create', 'doCreate(' + r.id + ')', 'Try provisioning again');
      }
    }

    // Inline link form (hidden by default, shown via toggleLinkForm).
    var linkForm = '<div class="sem-link-form" id="lf-' + r.id + '">'
      + '<span class="sem-link-loading" id="lf-loading-' + r.id + '">Loading accounts…</span>'
      + '<select id="lf-select-' + r.id + '" style="display:none" onchange="doLinkSelect(' + r.id + ')">'
      + '</select>'
      + '<input type="email" placeholder="Type email manually…" id="lf-input-' + r.id + '" style="display:none" />'
      + btn('Link', 'btn-link', 'doLink(' + r.id + ')', 'Link to typed address')
      + btn('Cancel', 'btn-bulk-outline', 'closeLinkForm(' + r.id + ')', 'Cancel')
      + '</div>';

    var emailCell = email
      ? esc(email) + (isExtPwd ? '<br><span class="sem-ext-note" title="' + esc(r.notes) + '">Pwd externally managed</span>' : '')
      : '<span style="color:#94a3b8;">—</span>';

    html += '<tr>'
      + '<td><strong>' + esc(r.fullname) + '</strong><br><small style="color:#94a3b8;">' + esc(r.username) + (r.idnumber ? ' · ' + esc(r.idnumber) : '') + '</small></td>'
      + '<td>' + emailCell + '</td>'
      + '<td><span class="sem-badge ' + badgeClass + '">' + badgeLabel + '</span></td>'
      + '<td style="color:#94a3b8;font-size:.8rem;">' + (r.email_date || '<span style="color:#cbd5e1;">—</span>') + '</td>'
      + '<td><div class="sem-actions">' + actions + '</div>' + linkForm + '</td>'
      + '</tr>';
  });

  tbody.innerHTML = html;
}

function btn(label, cls, onclick, title) {
  var t = title ? ' title="' + esc(title) + '"' : '';
  return '<button class="sem-btn ' + cls + '" onclick="' + onclick + '"' + t + '>' + label + '</button>';
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleLinkForm(btnEl, userid) {
  var form = document.getElementById('lf-' + userid);
  if (!form) return;
  var isOpen = form.classList.contains('open');
  if (isOpen) { closeLinkForm(userid); return; }

  // Open form and fetch cPanel accounts.
  form.classList.add('open');
  var loading = document.getElementById('lf-loading-' + userid);
  var sel     = document.getElementById('lf-select-' + userid);
  var inp     = document.getElementById('lf-input-' + userid);
  var linkBtn = form.querySelector('.btn-link');
  if (loading) loading.style.display = '';
  if (sel)     sel.style.display = 'none';
  if (inp)     inp.style.display = 'none';
  if (linkBtn) linkBtn.style.display = 'none';

  ajax('list_cpanel_accounts', {}, function (resp) {
    if (loading) loading.style.display = 'none';
    if (!resp.success) {
      // cPanel unavailable — fall back to manual text input.
      if (inp)     { inp.style.display = ''; inp.focus(); }
      if (linkBtn)   linkBtn.style.display = '';
      return;
    }
    if (!resp.accounts || resp.accounts.length === 0) {
      // No unlinked accounts found — fall back to manual entry.
      if (inp)     { inp.style.display = ''; inp.focus(); }
      if (linkBtn)   linkBtn.style.display = '';
      showAlert('No unlinked cPanel accounts found. Type the email address manually.', 'error');
      return;
    }
    // Populate dropdown — selecting auto-links (no extra click needed).
    if (sel) {
      sel.innerHTML = '<option value="">— pick a cPanel account —</option>'
        + resp.accounts.map(function (e) {
            return '<option value="' + esc(e) + '">' + esc(e) + '</option>';
          }).join('');
      sel.style.display = '';
      sel.focus();
    }
  });
}

function closeLinkForm(userid) {
  var form = document.getElementById('lf-' + userid);
  if (form) form.classList.remove('open');
}

// Called when user picks from the dropdown — one click, no button needed.
function doLinkSelect(userid) {
  var sel = document.getElementById('lf-select-' + userid);
  if (!sel || !sel.value) return;
  var email = sel.value;
  sel.disabled = true;
  ajax('link_account', {userid: userid, email: email}, function (resp) {
    sel.disabled = false;
    if (resp.success) {
      showAlert(resp.message, 'success');
      loadAccounts();
    } else {
      showAlert(resp.message, 'error');
    }
  });
}

// Fallback: manual text entry → Link button.
function doLink(userid) {
  var inp = document.getElementById('lf-input-' + userid);
  if (!inp || !inp.value.trim()) { showAlert('Please enter an email address.', 'error'); return; }
  ajax('link_account', {userid: userid, email: inp.value.trim()}, function (resp) {
    if (resp.success) {
      showAlert(resp.message, 'success');
      loadAccounts();
    } else {
      showAlert(resp.message, 'error');
    }
  });
}

function renderPagination() {
  var total = totalRecords;
  var start = currentPage * perPage + 1;
  var end   = Math.min(start + perPage - 1, total);
  document.getElementById('sem-page-info').textContent =
    total > 0 ? 'Showing ' + start + '–' + end + ' of ' + total : 'No results';
  document.getElementById('btn-prev').disabled = currentPage === 0;
  document.getElementById('btn-next').disabled = end >= total;
}

function loadStats() {
  ajax('get_stats', {}, function (resp) {
    if (!resp.success) return;
    var s = resp.stats;
    document.getElementById('stat-total').textContent     = s.total;
    document.getElementById('stat-active').textContent    = s.active;
    document.getElementById('stat-missing').textContent   = s.missing;
    document.getElementById('stat-suspended').textContent = s.suspended;
    document.getElementById('stat-archived').textContent  = s.archived;
    document.getElementById('stat-error').textContent     = s.error;
  });
}

// Per-row actions.
function doCreate(userid)  { rowAction('create_email',  userid, 'Provisioning new cPanel account...'); }
function doSuspend(userid) { rowAction('suspend_email', userid, 'Suspending...'); }
function doRestore(userid) { rowAction('restore_email', userid, 'Restoring...'); }
function doArchive(userid) { rowAction('archive_email', userid, 'Archiving...'); }
function doResetPW(userid) {
  if (!confirm('Reset password for this student? The new password will be shown here once.')) return;
  ajax('reset_password', {userid: userid}, function (resp) {
    if (resp.success) {
      showAlert('Password reset. New password: <strong>' + esc(resp.new_password) + '</strong> for ' + esc(resp.email), 'success');
      loadAccounts();
    } else {
      showAlert(resp.message, 'error');
    }
  });
}

function doResendWelcome(userid) {
  if (!confirm('Re-send the welcome email with college email address and password to this student\'s personal email address?')) return;
  ajax('resend_welcome', {userid: userid}, function (resp) {
    showAlert(resp.message, resp.success ? 'success' : 'error');
  });
}

function rowAction(action, userid, msg) {
  showAlert(msg, 'success');
  ajax(action, {userid: userid}, function (resp) {
    showAlert(resp.message, resp.success ? 'success' : 'error');
    if (resp.success) loadAccounts();
  });
}

// Bulk: Import Existing.
function bulkImport() {
  if (!confirm(
    'Import Existing Accounts\n\n' +
    'This will fetch all cPanel email accounts on your configured domain and ' +
    'automatically link them to matching Moodle users by username.\n\n' +
    'No accounts will be created or modified in cPanel — this only creates records ' +
    'inside the plugin so it can manage these accounts going forward.\n\n' +
    'Safe to run multiple times. Continue?'
  )) return;
  showAlert('Fetching cPanel accounts and matching to Moodle users… this may take a moment.', 'success');
  ajax('import_existing', {}, function (resp) {
    var type = resp.success ? 'success' : 'error';
    showAlert(resp.message, type);
    if (resp.success) loadAccounts();
  });
}

// Bulk: Create / Suspend.
function bulkAction(action) {
  var labels = {
    create_missing:  'Create brand-new cPanel email accounts for ALL students who have no account linked?\n\nOnly use this if students do NOT already have cPanel accounts. For existing accounts use "Import Existing Accounts" instead.',
    suspend_leavers: 'Suspend email accounts for ALL currently suspended Moodle users?'
  };
  if (!confirm(labels[action] || 'Continue?')) return;
  showAlert('Working… this may take a moment for large groups.', 'success');
  ajax(action, {}, function (resp) {
    showAlert(resp.message, resp.success ? 'success' : 'error');
    loadAccounts();
  });
}

function testConnection() {
  showAlert('Testing cPanel connection…', 'success');
  ajax('test_connection', {}, function (resp) {
    showAlert(
      resp.success ? 'cPanel connection successful! The API can reach your server.' : 'Connection failed: ' + resp.message,
      resp.success ? 'success' : 'error'
    );
  });
}

function fixMissingPasswords() {
  if (!confirm('This will auto-generate new cPanel passwords for all active accounts that have no stored password (imported accounts). Students can then open their mailbox immediately. Continue?')) return;
  showAlert('Fixing missing passwords…', 'success');
  ajax('fix_missing_passwords', {}, function (resp) {
    showAlert(resp.message, resp.success ? 'success' : 'error');
    if (resp.fixed > 0) { loadAccounts(); }
  });
}

function resetAllPasswords() {
  if (!confirm('This will generate a NEW cPanel password for every active linked account (all 468+). This fixes students who were linked via "Link Existing" and cannot log in to their mailbox.\n\nThis may take a minute. Continue?')) return;
  showAlert('Resetting all passwords — please wait…', 'success');
  ajax('bulk_reset_all_passwords', {}, function (resp) {
    showAlert(resp.message, resp.success ? 'success' : 'error');
    if (resp.fixed > 0) { loadAccounts(); }
  });
}

function testImap() {
  showAlert('Running deep IMAP diagnostic — checking both username formats and all folders…', 'success');
  ajax('test_imap', {}, function (resp) {
    showImapDiagModal(resp);
  });
}

function testImapStudent(userid, email) {
  showAlert('Testing IMAP mailbox for ' + email + '…', 'success');
  ajax('test_imap_student', {userid: userid}, function (resp) {
    showImapDiagModal(resp);
  });
}

function showImapDiagModal(resp) {
  // Remove any existing modal.
  var old = document.getElementById('sem-imap-diag-modal');
  if (old) old.remove();

  function fmtFolders(result) {
    if (!result.connected) {
      return '<span style="color:#dc2626">✗ Could not connect: ' + esc(result.error) + '</span>';
    }
    if (!result.folders || result.folders.length === 0) {
      return 'Connected — INBOX: <strong>' + result.inbox_count + '</strong> messages (no folder list returned)';
    }
    var rows = result.folders.map(function (f) {
      var highlight = (f.count > 0) ? ' style="color:#16a34a;font-weight:600"' : '';
      return '<tr' + highlight + '><td style="padding:2px 12px 2px 0">' + esc(f.folder) + '</td>'
           + '<td style="text-align:right">' + (f.count >= 0 ? f.count : '?') + '</td></tr>';
    });
    return '<table style="font-size:.82rem;border-collapse:collapse">'
         + '<tr><th style="text-align:left;padding:2px 12px 2px 0;color:#64748b">Folder</th>'
         + '<th style="text-align:right;color:#64748b">Messages</th></tr>'
         + rows.join('') + '</table>';
  }

  function tick(b) { return b ? '✓' : '✗'; }
  function colr(b) { return b ? 'color:#16a34a' : 'color:#dc2626'; }

  var ra = resp.result_a || {};
  var rb = resp.result_b || {};

  var body = '<div style="font-family:monospace;font-size:.8rem;background:#0f172a;color:#e2e8f0;padding:12px 16px;border-radius:6px;margin-bottom:14px;white-space:pre-wrap">'
    + 'Account : ' + (resp.email || '—') + '\n'
    + 'IMAP    : ' + (resp.imap_host || '—') + ':' + (resp.imap_port || '—') + (resp.imap_flags || '') + '\n'
    + 'Current setting: ' + (resp.strip_now ? 'strip_domain = ON  (sends localpart only)' : 'strip_domain = OFF (sends full email)')
    + '</div>';

  // Probe A
  body += '<h4 style="margin:0 0 6px;font-size:.9rem">Probe A — ' + esc(resp.label_a || '') + '</h4>';
  body += '<p style="margin:0 0 2px;font-size:.82rem"><span style="' + colr(ra.connected) + '">'
        + tick(ra.connected) + ' ' + (ra.connected ? 'Connected' : 'Failed') + '</span>'
        + ' &nbsp;·&nbsp; Username sent: <code>' + esc(ra.username || '') + '</code>'
        + ' &nbsp;·&nbsp; INBOX: <strong>' + (ra.inbox_count || 0) + '</strong> messages</p>';
  body += '<div style="margin:6px 0 14px 0">' + fmtFolders(ra) + '</div>';

  // Probe B
  body += '<h4 style="margin:0 0 6px;font-size:.9rem">Probe B — ' + esc(resp.label_b || '') + '</h4>';
  body += '<p style="margin:0 0 2px;font-size:.82rem"><span style="' + colr(rb.connected) + '">'
        + tick(rb.connected) + ' ' + (rb.connected ? 'Connected' : 'Failed') + '</span>'
        + ' &nbsp;·&nbsp; Username sent: <code>' + esc(rb.username || '') + '</code>'
        + ' &nbsp;·&nbsp; INBOX: <strong>' + (rb.inbox_count || 0) + '</strong> messages</p>';
  body += '<div style="margin:6px 0 14px 0">' + fmtFolders(rb) + '</div>';

  // Recommendation box
  var fixColor = resp.success ? '#14532d' : '#7f1d1d';
  var fixBg    = resp.success ? '#f0fdf4' : '#fef2f2';
  body += '<div style="background:' + fixBg + ';border-left:4px solid ' + fixColor + ';padding:10px 14px;border-radius:4px;font-size:.84rem;color:' + fixColor + '">'
        + '<strong>Recommendation:</strong> ' + esc(resp.fix || resp.message || '') + '</div>';

  var modal = document.createElement('div');
  modal.id = 'sem-imap-diag-modal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
  modal.innerHTML = '<div style="background:#fff;border-radius:10px;padding:24px 28px;max-width:680px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.4)">'
    + '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">'
    + '<h3 style="margin:0;font-size:1.05rem">IMAP Deep Diagnostic</h3>'
    + '<button onclick="document.getElementById(\'sem-imap-diag-modal\').remove()" style="border:none;background:none;font-size:1.4rem;cursor:pointer;line-height:1;color:#64748b">&times;</button>'
    + '</div>'
    + body
    + '</div>';

  document.body.appendChild(modal);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) modal.remove();
  });
}

function testSmtp() {
  showAlert('Testing outgoing SMTP connection…', 'success');
  ajax('test_smtp', {}, function (resp) {
    showAlert(resp.message, resp.success ? 'success' : 'error');
  });
}

function ajax(action, params, callback) {
  var data = Object.assign({action: action, sesskey: SESSKEY}, params);
  var qs   = Object.keys(data).map(function (k) {
    return encodeURIComponent(k) + '=' + encodeURIComponent(data[k] !== undefined && data[k] !== null ? data[k] : '');
  }).join('&');

  var xhr = new XMLHttpRequest();
  xhr.open('POST', AJAX_URL, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) return;
    try {
      callback(JSON.parse(xhr.responseText));
    } catch(e) {
      callback({success: false, message: 'Invalid server response: ' + xhr.responseText.substring(0, 200)});
    }
  };
  xhr.send(qs);
}

function showAlert(msg, type) {
  var el = document.getElementById('sem-alert');
  el.className = 'sem-alert sem-alert-' + (type === 'error' ? 'error' : 'success');
  el.innerHTML = msg;
  el.style.display = 'block';
  clearTimeout(el._timer);
  if (type === 'success') {
    el._timer = setTimeout(function () { el.style.display = 'none'; }, 8000);
  }
}

// Boot.
setFilter('all');
loadAccounts();

// =========================================================
// Diagnose Import
// =========================================================
function runDiagnose() {
  showAlert('Running import diagnostic — fetching raw cPanel data…', 'success');
  ajax('diagnose_import', {}, function (resp) {
    var w = window.open('', '_blank', 'width=900,height=700,scrollbars=yes');
    if (!w) { showAlert('Pop-up blocked — allow pop-ups and try again.', 'error'); return; }

    var lines = [];
    lines.push('<html><head><title>Import Diagnostic</title>');
    lines.push('<style>body{font-family:monospace;font-size:13px;padding:1rem;} h2{font-size:1rem;margin:1rem 0 .3rem;border-bottom:2px solid #333;} .ok{color:#16a34a;font-weight:700;} .bad{color:#dc2626;font-weight:700;} table{border-collapse:collapse;width:100%;margin-bottom:1rem;} th,td{border:1px solid #ddd;padding:.3rem .5rem;text-align:left;font-size:.8rem;} th{background:#f1f5f9;} pre{background:#f8fafc;border:1px solid #e2e8f0;padding:.5rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all;}</style>');
    lines.push('</head><body>');

    lines.push('<h2>1. Plugin Configuration</h2>');
    lines.push('<p>Configured email domain: <strong>' + esc(resp.domain_config || '(empty — check Settings!)') + '</strong></p>');

    lines.push('<h2>2. cPanel API Results</h2>');
    lines.push('<p>UAPI (list_pops_with_disk): <span class="' + (resp.uapi_success ? 'ok' : 'bad') + '">' + (resp.uapi_success ? 'SUCCESS' : 'FAILED') + '</span> — accounts: ' + resp.uapi_count + ' — msg: ' + esc(resp.uapi_message || '—') + '</p>');
    lines.push('<p>JSON API v2 (listpopswithdisk): <span class="' + (resp.jsonapi_success ? 'ok' : 'bad') + '">' + (resp.jsonapi_success ? 'SUCCESS' : 'FAILED') + '</span> — accounts: ' + resp.jsonapi_count + ' — msg: ' + esc(resp.jsonapi_message || '—') + '</p>');
    lines.push('<p>Source used: <strong>' + esc(resp.source_used) + '</strong> | Total accounts fetched: <strong>' + resp.total_accounts + '</strong></p>');

    lines.push('<h2>3. Raw cPanel Account Objects (first 5) — field names & values</h2>');
    if (resp.sample_raw_5 && resp.sample_raw_5.length) {
      lines.push('<pre>' + esc(JSON.stringify(resp.sample_raw_5, null, 2)) + '</pre>');
    } else {
      lines.push('<p class="bad">No accounts returned from cPanel — check domain config and API credentials.</p>');
    }

    lines.push('<h2>4. Parsed Localparts (first 20 accounts) — old vs fixed logic</h2>');
    if (resp.parsed_20 && resp.parsed_20.length) {
      lines.push('<table><thead><tr><th>login field (raw)</th><th>email field (raw)</th><th>user field (raw)</th><th>OLD localpart (was buggy)</th><th>NEW localpart (fixed)</th><th>Match?</th></tr></thead><tbody>');
      resp.parsed_20.forEach(function (p) {
        var same = p.old_localpart === p.new_localpart;
        var hasat = p.old_localpart.indexOf('@') !== -1;
        lines.push('<tr>'
          + '<td>' + esc(p.login_raw) + '</td>'
          + '<td>' + esc(p.email_raw) + '</td>'
          + '<td>' + esc(p.user_raw) + '</td>'
          + '<td class="' + (hasat ? 'bad' : 'ok') + '">' + esc(p.old_localpart) + (hasat ? ' ← CONTAINED @' : '') + '</td>'
          + '<td class="ok">' + esc(p.new_localpart) + '</td>'
          + '<td>' + (same ? 'Same' : '<span class="bad">DIFFERENT — fix applied</span>') + '</td>'
          + '</tr>');
      });
      lines.push('</tbody></table>');
    } else {
      lines.push('<p class="bad">No parsed accounts to show.</p>');
    }

    lines.push('<h2>5. SQL: Current DB State — ' + resp.sql_total_linked + ' linked accounts</h2>');
    lines.push('<p><strong>Status breakdown:</strong> ');
    if (resp.sql_status_breakdown && resp.sql_status_breakdown.length) {
      lines.push(resp.sql_status_breakdown.map(function (r) { return r.status + ': ' + r.cnt; }).join(' | '));
    }
    lines.push('</p>');

    lines.push('<h2>6. SQL: Email Unique-Constraint Violations (emails linked to 2+ users)</h2>');
    if (resp.sql_email_duplicates && resp.sql_email_duplicates.length) {
      lines.push('<p class="bad">These emails are already linked to multiple users — this is what caused "Error writing to database":</p>');
      lines.push('<table><thead><tr><th>Email</th><th>Count</th><th>User IDs</th></tr></thead><tbody>');
      resp.sql_email_duplicates.forEach(function (r) {
        lines.push('<tr><td class="bad">' + esc(r.email) + '</td><td>' + r.cnt + '</td><td>' + esc(r.userids) + '</td></tr>');
      });
      lines.push('</tbody></table>');
    } else {
      lines.push('<p class="ok">No duplicate emails in the DB — unique constraint is clean.</p>');
    }

    lines.push('<h2>7. SQL: Unlinked Moodle Users with Identical Names (firstname.lastname collision risk)</h2>');
    if (resp.sql_name_duplicates && resp.sql_name_duplicates.length) {
      lines.push('<p>These users share the same name — only the first one matched by username will be linked; the rest need manual Link Existing:</p>');
      lines.push('<table><thead><tr><th>First Name</th><th>Last Name</th><th>Count</th><th>User IDs</th><th>Usernames</th></tr></thead><tbody>');
      resp.sql_name_duplicates.forEach(function (r) {
        lines.push('<tr>'
          + '<td>' + esc(r.firstname) + '</td>'
          + '<td>' + esc(r.lastname) + '</td>'
          + '<td class="bad">' + r.cnt + '</td>'
          + '<td>' + esc(r.userids) + '</td>'
          + '<td>' + esc(r.usernames) + '</td>'
          + '</tr>');
      });
      lines.push('</tbody></table>');
      lines.push('<p><em>Each of these students has a unique cPanel account (different username = different email). The collision only happens via the firstname.lastname fallback candidate — not via username match. The import will link each by username and skip the name-based collision automatically from v1.5.28+.</em></p>');
    } else {
      lines.push('<p class="ok">No duplicate-name collisions among unlinked users.</p>');
    }

    lines.push('<h2>8. Unlinked Moodle Users (' + resp.unlinked_total + ' remaining) — first 5 + their match candidates</h2>');
    if (resp.unlinked_samples && resp.unlinked_samples.length) {
      lines.push('<table><thead><tr><th>User ID</th><th>Moodle username</th><th>Moodle email</th><th>Candidates sent to cPanel map</th></tr></thead><tbody>');
      resp.unlinked_samples.forEach(function (u) {
        lines.push('<tr>'
          + '<td>' + u.userid + '</td>'
          + '<td>' + esc(u.username) + '</td>'
          + '<td>' + esc(u.moodle_email) + '</td>'
          + '<td>' + esc(u.candidates.join(', ')) + '</td>'
          + '</tr>');
      });
      lines.push('</tbody></table>');
    } else {
      lines.push('<p class="ok">No unlinked users found — everyone is already linked!</p>');
    }

    lines.push('</body></html>');
    w.document.write(lines.join(''));
    w.document.close();
    showAlert('Diagnostic report opened in new tab.', 'success');
  });
}

// =========================================================
// Data Quality Report
// =========================================================
function openDataQuality() {
  document.getElementById('sem-dq-body').innerHTML = '<div class="sem-dq-loading">Running checks…</div>';
  document.getElementById('sem-dq-overlay').classList.add('open');
  ajax('get_data_quality', {}, function (resp) {
    if (!resp.success) {
      document.getElementById('sem-dq-body').innerHTML = '<p style="color:#dc2626">Error: ' + (resp.message || 'Unknown error') + '</p>';
      return;
    }
    renderDataQuality(resp);
  });
}

function closeDataQuality() {
  document.getElementById('sem-dq-overlay').classList.remove('open');
}

function esc(str) {
  var d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML;
}

function dqBadge(n) {
  return '<span class="sem-dq-badge' + (n === 0 ? ' ok' : '') + '">' + n + '</span>';
}

function renderDataQuality(data) {
  var html = '';

  // ---- 1. Duplicate Moodle accounts ----------------------------
  html += '<div class="sem-dq-section">';
  html += '<p class="sem-dq-title">' + dqBadge(data.duplicates.length) + ' Duplicate Moodle Accounts <small style="font-weight:400;color:#64748b;font-size:.78rem;">(same first + last name)</small></p>';
  if (data.duplicates.length === 0) {
    html += '<div class="sem-dq-none">No duplicate names found.</div>';
  } else {
    html += '<table class="sem-dq-table"><thead><tr><th>Name</th><th>Account A</th><th>Account B</th></tr></thead><tbody>';
    data.duplicates.forEach(function (d) {
      var a1 = '<div class="sem-dq-account">'
             + '<div><span class="lbl">User&nbsp;ID:</span> <span class="val">' + d.userid1 + '</span></div>'
             + '<div><span class="lbl">Username:</span> <span class="val">' + esc(d.username1) + '</span></div>'
             + '<div><span class="lbl">Email:</span> <span class="val">' + esc(d.email1) + '</span></div>'
             + '<div><span class="lbl">Last&nbsp;access:</span> <span class="val">' + esc(d.lastaccess1) + '</span></div>'
             + (d.sem_email1 ? '<div><span class="lbl">SEM:</span> <span class="val">' + esc(d.sem_email1) + '</span> <span class="dim">(' + esc(d.sem_status1) + ')</span></div>' : '<div class="dim">Not linked in SEM</div>')
             + '</div>';
      var a2 = '<div class="sem-dq-account">'
             + '<div><span class="lbl">User&nbsp;ID:</span> <span class="val">' + d.userid2 + '</span></div>'
             + '<div><span class="lbl">Username:</span> <span class="val">' + esc(d.username2) + '</span></div>'
             + '<div><span class="lbl">Email:</span> <span class="val">' + esc(d.email2) + '</span></div>'
             + '<div><span class="lbl">Last&nbsp;access:</span> <span class="val">' + esc(d.lastaccess2) + '</span></div>'
             + (d.sem_email2 ? '<div><span class="lbl">SEM:</span> <span class="val">' + esc(d.sem_email2) + '</span> <span class="dim">(' + esc(d.sem_status2) + ')</span></div>' : '<div class="dim">Not linked in SEM</div>')
             + '</div>';
      html += '<tr><td><strong>' + esc(d.name) + '</strong></td><td>' + a1 + '</td><td>' + a2 + '</td></tr>';
    });
    html += '</tbody></table>';
    html += '<p style="margin:.6rem 0 0;font-size:.78rem;color:#64748b;">Resolve by deleting the unused account in Moodle Site Admin → Users → Browse list of users. Keep the one with recent last-access or the active SEM email.</p>';
  }
  html += '</div>';

  html += '<hr class="sem-dq-divider">';

  // ---- 2. Multiple SEM records per user -------------------------
  html += '<div class="sem-dq-section">';
  html += '<p class="sem-dq-title">' + dqBadge(data.multi.length) + ' Students with Multiple Email Records</p>';
  if (data.multi.length === 0) {
    html += '<div class="sem-dq-none">No users have multiple SEM records.</div>';
  } else {
    html += '<table class="sem-dq-table"><thead><tr><th>Name</th><th>Username</th><th>Records</th><th>Emails</th></tr></thead><tbody>';
    data.multi.forEach(function (m) {
      html += '<tr><td>' + esc(m.name) + '</td><td>' + esc(m.username) + '</td>'
            + '<td><span class="sem-dq-warn">' + m.count + '</span></td>'
            + '<td style="font-size:.76rem">' + esc(m.sem_emails) + '</td></tr>';
    });
    html += '</tbody></table>';
  }
  html += '</div>';

  html += '<hr class="sem-dq-divider">';

  // ---- 3. Orphaned SEM records ----------------------------------
  html += '<div class="sem-dq-section">';
  html += '<p class="sem-dq-title">' + dqBadge(data.orphans.length) + ' Orphaned Email Records <small style="font-weight:400;color:#64748b;font-size:.78rem;">(Moodle user deleted)</small></p>';
  if (data.orphans.length === 0) {
    html += '<div class="sem-dq-none">No orphaned records.</div>';
  } else {
    html += '<table class="sem-dq-table"><thead><tr><th>Record ID</th><th>Moodle User ID</th><th>cPanel Email</th><th>Status</th></tr></thead><tbody>';
    data.orphans.forEach(function (o) {
      html += '<tr><td>' + o.record_id + '</td><td>' + o.userid + '</td><td>' + esc(o.sem_email) + '</td><td>' + esc(o.status) + '</td></tr>';
    });
    html += '</tbody></table>';
    html += '<p style="margin:.6rem 0 0;font-size:.78rem;color:#64748b;">These cPanel accounts no longer have a Moodle user. Archive or delete the cPanel mailbox if no longer needed, then these records will clean up on next import.</p>';
  }
  html += '</div>';

  html += '<hr class="sem-dq-divider">';

  // ---- 4. Suspended Moodle / Active cPanel ----------------------
  html += '<div class="sem-dq-section">';
  html += '<p class="sem-dq-title">' + dqBadge(data.mismatches.length) + ' Suspended in Moodle but cPanel Still Active</p>';
  if (data.mismatches.length === 0) {
    html += '<div class="sem-dq-none">No mismatches found.</div>';
  } else {
    html += '<table class="sem-dq-table"><thead><tr><th>Name</th><th>Username</th><th>cPanel Email</th></tr></thead><tbody>';
    data.mismatches.forEach(function (m) {
      html += '<tr><td>' + esc(m.name) + '</td><td>' + esc(m.username) + '</td><td>' + esc(m.sem_email) + '</td></tr>';
    });
    html += '</tbody></table>';
    html += '<p style="margin:.6rem 0 0;font-size:.78rem;color:#64748b;">Click "Suspend Leavers" on the dashboard toolbar to fix all these in one go.</p>';
  }
  html += '</div>';

  document.getElementById('sem-dq-body').innerHTML = html;
}
</script>

<?php echo $OUTPUT->footer(); ?>
