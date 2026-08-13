<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student Email — native IMAP mailbox page (Phase 2).
 *
 * Full email client rendered inside a standard Moodle page.
 * Phase 2 adds: attachment downloads, reply-all, server-side IMAP search,
 * rich text compose with formatting toolbar, email signature, mark-unread,
 * move-to-folder, star/flag messages, proper pagination with page counter.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/studentemail/classes/email_manager.php');

use local_studentemail\email_manager;

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/studentemail/mailbox.php'));
$PAGE->set_title(get_string('mailbox_title', 'local_studentemail'));
$PAGE->set_heading(get_string('mailbox_title', 'local_studentemail'));
$PAGE->set_pagelayout('standard');

// Check the student has an active account.
$manager = new email_manager();
$creds   = $manager->get_account_for_user($USER->id);

$has_account   = !empty($creds) && $creds['status'] === 'active';
$student_email = $has_account ? $creds['email'] : '';
$student_name  = fullname($USER);

// SMTP configured?
$smtp_host = trim(get_config('local_studentemail', 'smtp_host') ?? '');
$can_send  = !empty($smtp_host);

// Load saved signature (stored in user preferences, max 2 KB).
$student_sig = $has_account ? get_user_preferences('local_studentemail_signature', '') : '';

// AJAX URL + sesskey.
$ajax_url = (new moodle_url('/local/studentemail/mailbox_ajax.php'))->out(false);
$sesskey  = sesskey();

echo $OUTPUT->header();
?>

<?php if (!$has_account): ?>
  <div class="sem-no-account">
    <div class="sem-no-account-inner">
      <div class="sem-no-account-icon">&#9993;</div>
      <h2><?php echo get_string('mailbox_no_account', 'local_studentemail'); ?></h2>
      <p><?php echo get_string('mailbox_no_account_desc', 'local_studentemail'); ?></p>
    </div>
  </div>
<?php else: ?>

<div id="sem-mailbox" class="sem-mailbox"
  data-ajax="<?php echo s($ajax_url); ?>"
  data-sesskey="<?php echo s($sesskey); ?>"
  data-email="<?php echo s($student_email); ?>"
  data-name="<?php echo s($student_name); ?>"
  data-cansend="<?php echo $can_send ? '1' : '0'; ?>"
  data-signature="<?php echo s($student_sig); ?>">

  <!-- ═══ Mobile sidebar toggle ═══ -->
  <button class="sem-mob-toggle" id="sem-mob-toggle" onclick="SEM.toggleSidebar()" aria-label="Menu">
    <span class="sem-mob-bar"></span>
    <span class="sem-mob-bar"></span>
    <span class="sem-mob-bar"></span>
  </button>
  <div class="sem-mob-overlay" id="sem-mob-overlay" onclick="SEM.closeSidebar()"></div>

  <!-- ═══════════════════════════════════════════════
       SIDEBAR
  ═══════════════════════════════════════════════ -->
  <aside class="sem-sidebar" id="sem-sidebar">

    <div class="sem-sidebar-header">
      <div class="sem-sidebar-avatar" id="sem-avatar">
        <?php echo s(mb_substr($student_name, 0, 1)); ?>
      </div>
      <div class="sem-sidebar-identity">
        <div class="sem-sidebar-name"><?php echo s($student_name); ?></div>
        <div class="sem-sidebar-email"><?php echo s($student_email); ?></div>
      </div>
    </div>

    <?php if ($can_send): ?>
    <button class="sem-compose-btn" id="sem-compose-btn" onclick="SEM.openCompose()">
      <span class="sem-compose-icon">&#9998;</span>
      <?php echo get_string('compose', 'local_studentemail'); ?>
    </button>
    <?php endif; ?>

    <nav class="sem-folders" id="sem-folders">
      <div id="sem-all-folders"><div class="sem-folder-loading">Loading folders&hellip;</div></div>
    </nav>

    <div class="sem-sidebar-footer">
      <div class="sem-sidebar-foot-email"><?php echo s($student_email); ?></div>
    </div>
  </aside>

  <!-- ═══════════════════════════════════════════════
       MAIN PANE
  ═══════════════════════════════════════════════ -->
  <main class="sem-main" id="sem-main">

    <!-- ── List pane ──────────────────────────────── -->
    <div class="sem-list-pane" id="sem-list-pane">

      <div class="sem-list-toolbar">
        <div class="sem-folder-title-wrap">
          <span class="sem-folder-title" id="sem-folder-title">Inbox</span>
          <span class="sem-folder-unread-badge" id="sem-folder-unread" style="display:none"></span>
        </div>
        <div class="sem-search-wrap">
          <input type="text" class="sem-search-input" id="sem-search-input"
            placeholder="Search mail…"
            onkeydown="if(event.key==='Enter'){SEM.triggerSearch();}">
          <button class="sem-search-btn" onclick="SEM.triggerSearch()" title="Search">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </button>
          <button class="sem-search-clear" id="sem-search-clear" onclick="SEM.clearSearch()" title="Clear" style="display:none">&#10005;</button>
        </div>
        <button class="sem-refresh-btn" onclick="SEM.refreshFolder()" title="Refresh">&#8635;</button>
      </div>

      <div class="sem-message-list" id="sem-message-list">
        <div class="sem-loading" id="sem-list-loading">
          <div class="sem-spinner"></div>
          <span>Loading messages…</span>
        </div>
      </div>

      <div class="sem-pagination" id="sem-pagination" style="display:none">
        <button class="sem-page-btn" id="sem-page-prev" onclick="SEM.prevPage()" disabled>&#8592; Prev</button>
        <span class="sem-page-info" id="sem-page-info">1–50 of 50</span>
        <button class="sem-page-btn" id="sem-page-next" onclick="SEM.nextPage()">Next &#8594;</button>
      </div>
    </div>

    <!-- ── Detail pane ────────────────────────────── -->
    <div class="sem-detail-pane" id="sem-detail-pane" style="display:none">

      <div class="sem-detail-toolbar">
        <button class="sem-back-btn" onclick="SEM.closeMessage()">
          &#8592; <?php echo get_string('back_to_inbox', 'local_studentemail'); ?>
        </button>
        <div class="sem-detail-actions">
          <?php if ($can_send): ?>
          <button class="sem-action-btn" id="sem-reply-btn" onclick="SEM.openReply()">&#8617; Reply</button>
          <button class="sem-action-btn" id="sem-replyall-btn" onclick="SEM.openReplyAll()">&#8617;&#8617; All</button>
          <button class="sem-action-btn" id="sem-forward-btn" onclick="SEM.openForward()">&#8618; Forward</button>
          <?php endif; ?>
          <div class="sem-move-wrap" id="sem-move-wrap">
            <button class="sem-action-btn" id="sem-move-btn" onclick="SEM.toggleMoveMenu()">Move &#9660;</button>
            <div class="sem-move-menu" id="sem-move-menu" style="display:none"></div>
          </div>
          <button class="sem-action-btn" id="sem-markunread-btn" onclick="SEM.markUnread()">&#9679; Unread</button>
          <button class="sem-action-btn sem-action-delete" id="sem-delete-btn" onclick="SEM.deleteCurrentMessage()">&#128465; Delete</button>
        </div>
      </div>

      <div class="sem-detail-header" id="sem-detail-header"></div>

      <div class="sem-detail-body" id="sem-detail-body">
        <div class="sem-loading" id="sem-detail-loading" style="display:none">
          <div class="sem-spinner"></div>
          <span>Loading message…</span>
        </div>
        <div id="sem-detail-content"></div>
      </div>

      <div class="sem-attachments-panel" id="sem-attachments-panel" style="display:none">
        <div class="sem-attachments-label">&#128206; Attachments</div>
        <div class="sem-attachments-list" id="sem-attachments-list"></div>
      </div>
    </div>

    <!-- ── Compose / Reply overlay ────────────────── -->
    <div class="sem-compose-backdrop" id="sem-compose-backdrop" style="display:none" onclick="SEM.closeCompose()"></div>
    <div class="sem-compose-pane" id="sem-compose-pane" style="display:none">
      <div class="sem-compose-header">
        <span id="sem-compose-title"><?php echo get_string('compose', 'local_studentemail'); ?></span>
        <button class="sem-compose-close" onclick="SEM.closeCompose()">&#10005;</button>
      </div>
      <div class="sem-compose-form">

        <div class="sem-compose-field">
          <label class="sem-compose-label">To</label>
          <input type="text" class="sem-compose-input" id="sem-compose-to"
            placeholder="recipient@example.com">
        </div>

        <div class="sem-compose-field">
          <label class="sem-compose-label">CC</label>
          <input type="text" class="sem-compose-input" id="sem-compose-cc"
            placeholder="cc@example.com (optional)">
        </div>

        <div class="sem-compose-field">
          <label class="sem-compose-label">Subject</label>
          <input type="text" class="sem-compose-input" id="sem-compose-subject">
        </div>

        <!-- Rich-text toolbar -->
        <div class="sem-rte-toolbar" id="sem-rte-toolbar">
          <button type="button" class="sem-rte-btn" onclick="SEM.execCmd('bold')" title="Bold"><b>B</b></button>
          <button type="button" class="sem-rte-btn" onclick="SEM.execCmd('italic')" title="Italic"><i>I</i></button>
          <button type="button" class="sem-rte-btn" onclick="SEM.execCmd('underline')" title="Underline"><u>U</u></button>
          <div class="sem-rte-divider"></div>
          <button type="button" class="sem-rte-btn" onclick="SEM.insertOrderedList()" title="Numbered list">1.</button>
          <button type="button" class="sem-rte-btn" onclick="SEM.insertUnorderedList()" title="Bullet list">&#8226;</button>
          <div class="sem-rte-divider"></div>
          <button type="button" class="sem-rte-btn" onclick="SEM.insertLink()" title="Insert link">&#128279;</button>
          <button type="button" class="sem-rte-btn" onclick="SEM.execCmd('removeFormat')" title="Clear formatting">Tx</button>
          <div class="sem-rte-divider"></div>
          <button type="button" class="sem-rte-btn" onclick="SEM.triggerAttach()" title="Attach file">&#128206;</button>
          <input type="file" id="sem-attach-input" multiple style="display:none" onchange="SEM.handleFileAttach(this)">
        </div>

        <!-- Attached file chips -->
        <div class="sem-attach-chips" id="sem-attach-chips" style="display:none"></div>

        <!-- Editable message body -->
        <div class="sem-compose-editor-wrap">
          <div contenteditable="true" id="sem-compose-editor"
            class="sem-compose-editor"
            data-placeholder="Write your message here…"></div>
        </div>

        <!-- Signature strip -->
        <div class="sem-sig-strip" id="sem-sig-strip">
          <div class="sem-sig-label">
            <span>Signature</span>
            <button type="button" class="sem-sig-edit-btn" id="sem-sig-edit-btn" onclick="SEM.openSigEdit()">Edit</button>
          </div>
          <div class="sem-sig-preview" id="sem-sig-preview"></div>

          <div class="sem-sig-editor-wrap" id="sem-sig-editor-wrap" style="display:none">
            <textarea class="sem-sig-textarea" id="sem-sig-textarea"
              placeholder="Your email signature (e.g. name, job title, contact)…" rows="4"></textarea>
            <div class="sem-sig-editor-actions">
              <button type="button" class="sem-sig-save-btn" onclick="SEM.saveSignature()">Save signature</button>
              <button type="button" class="sem-cancel-btn" onclick="SEM.closeSigEdit()">Cancel</button>
              <span class="sem-sig-saved-msg" id="sem-sig-saved-msg" style="display:none">Saved!</span>
            </div>
          </div>
        </div>

        <div class="sem-compose-footer">
          <button class="sem-send-btn" onclick="SEM.sendMessage()">
            <span id="sem-send-label">Send</span>
          </button>
          <button class="sem-cancel-btn" onclick="SEM.closeCompose()">Cancel</button>
          <span class="sem-send-status" id="sem-send-status"></span>
        </div>
      </div>
    </div>

  </main>
</div>

<?php endif; ?>

<style>
/* ================================================================
   Student Email Mailbox — Premium redesign
   Strict Moodle CSS: no :has(), :is(), :where(), @layer, @container
================================================================ */

/* ── Reset & base ──────────────────────────── */
.sem-mailbox *,
.sem-mailbox *::before,
.sem-mailbox *::after {
  box-sizing: border-box;
}

.sem-mailbox {
  display: flex;
  height: calc(100vh - 130px);
  min-height: 540px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
  margin: 0 0 24px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  font-size: 14px;
  position: relative;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
}

/* ── Mobile toggle button ──────────────────── */
.sem-mob-toggle {
  display: none;
  position: absolute;
  top: 12px;
  left: 12px;
  z-index: 200;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 8px 10px;
  cursor: pointer;
  flex-direction: column;
  gap: 5px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.sem-mob-bar {
  display: block;
  width: 20px;
  height: 2px;
  background: #374151;
  border-radius: 2px;
}
.sem-mob-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.35);
  z-index: 149;
}
.sem-mob-overlay-open { display: block; }

/* ── Sidebar ───────────────────────────────── */
.sem-sidebar {
  width: 240px;
  min-width: 240px;
  background: #f8fafc;
  border-right: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: transform 0.22s cubic-bezier(0.4,0,0.2,1);
}

.sem-sidebar-header {
  padding: 20px 16px 16px;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  align-items: center;
  gap: 11px;
}

.sem-sidebar-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 16px;
  flex-shrink: 0;
  letter-spacing: 0;
}

.sem-sidebar-identity { overflow: hidden; min-width: 0; }

.sem-sidebar-name {
  font-weight: 600;
  font-size: 14px;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sem-sidebar-email {
  font-size: 12px;
  color: #6b7280;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 1px;
}

.sem-compose-btn {
  margin: 16px 14px 12px;
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 10px 18px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  width: calc(100% - 28px);
  text-decoration: none;
  letter-spacing: -0.01em;
  box-shadow: 0 1px 3px rgba(37,99,235,0.3);
  transition: background 0.15s, box-shadow 0.15s;
}
.sem-compose-btn:hover {
  background: #1d4ed8;
  color: #fff;
  text-decoration: none;
  box-shadow: 0 2px 8px rgba(37,99,235,0.35);
}
.sem-compose-btn:focus { outline: 2px solid #93c5fd; outline-offset: 1px; }
.sem-compose-icon { font-size: 16px; line-height: 1; }

.sem-folders {
  flex: 1;
  overflow-y: auto;
  padding: 8px 0 8px;
}

.sem-folders-section-label {
  padding: 10px 18px 4px;
  font-size: 11px;
  font-weight: 600;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.sem-folder-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 18px;
  cursor: pointer;
  font-size: 14px;
  color: #374151;
  user-select: none;
  border-left: 3px solid transparent;
  transition: background 0.12s, color 0.12s;
  position: relative;
}
.sem-folder-item:hover { background: #dde3ea; color: #111827; }
.sem-folder-active {
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 600;
  border-left-color: #2563eb;
}
.sem-folder-icon {
  font-size: 15px;
  flex-shrink: 0;
  opacity: 0.75;
}
.sem-folder-active .sem-folder-icon { opacity: 1; }
.sem-folder-label { flex: 1; }
.sem-folder-badge {
  background: #ef4444;
  color: #fff;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  padding: 1px 7px;
  min-width: 20px;
  text-align: center;
  line-height: 1.5;
}
.sem-folder-divider {
  height: 1px;
  background: #e5e7eb;
  margin: 8px 16px;
}

.sem-sidebar-footer {
  padding: 12px 16px;
  border-top: 1px solid #e5e7eb;
}
.sem-sidebar-foot-email {
  font-size: 11px;
  color: #9ca3af;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── Main pane ─────────────────────────────── */
.sem-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  position: relative;
  overflow: hidden;
  background: #fff;
}

/* ── List pane ─────────────────────────────── */
.sem-list-pane {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.sem-list-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  border-bottom: 1px solid #f3f4f6;
  flex-shrink: 0;
  background: #fff;
}

.sem-folder-title-wrap {
  display: flex;
  align-items: center;
  gap: 9px;
  flex-shrink: 0;
}

.sem-folder-title {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
  letter-spacing: -0.02em;
}

.sem-folder-unread-badge {
  background: #dbeafe;
  color: #1d4ed8;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 700;
  padding: 1px 8px;
  line-height: 1.6;
}

.sem-search-wrap {
  flex: 1;
  min-width: 160px;
  display: flex;
  align-items: center;
  border: 1px solid #e5e7eb;
  border-radius: 20px;
  background: #f9fafb;
  overflow: hidden;
  transition: border-color 0.15s, background 0.15s;
}
.sem-search-wrap:focus-within {
  border-color: #93c5fd;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(147,197,253,0.25);
}

.sem-search-input {
  flex: 1;
  border: none;
  outline: none;
  padding: 8px 12px;
  font-size: 14px;
  color: #374151;
  background: transparent;
}
.sem-search-input::placeholder { color: #9ca3af; }

.sem-search-btn,
.sem-search-clear {
  background: none;
  border: none;
  padding: 7px 10px;
  cursor: pointer;
  color: #9ca3af;
  display: flex;
  align-items: center;
  transition: color 0.12s;
}
.sem-search-btn:hover, .sem-search-clear:hover { color: #374151; }

.sem-refresh-btn {
  background: none;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 7px 11px;
  cursor: pointer;
  font-size: 17px;
  color: #6b7280;
  flex-shrink: 0;
  line-height: 1;
  transition: background 0.12s, color 0.12s;
}
.sem-refresh-btn:hover { background: #d1d5db; color: #111827; }

/* ── Message list ──────────────────────────── */
.sem-message-list {
  flex: 1;
  overflow-y: auto;
}

.sem-message-row {
  display: flex;
  align-items: flex-start;
  gap: 4px;
  padding: 13px 18px 13px 14px;
  border-bottom: 1px solid #f3f4f6;
  cursor: pointer;
  transition: background 0.1s;
}
.sem-message-row:hover { background: #e2e8f0; }
.sem-message-row:last-child { border-bottom: none; }

.sem-unread { background: #fafbff; }
.sem-unread .sem-msg-from { font-weight: 700; color: #111827; }
.sem-unread .sem-msg-subject { font-weight: 600; color: #111827; }

.sem-msg-star {
  width: 26px;
  flex-shrink: 0;
  text-align: center;
  font-size: 16px;
  color: #e5e7eb;
  cursor: pointer;
  padding: 1px 2px;
  user-select: none;
  transition: color 0.15s;
  margin-top: 2px;
}
.sem-msg-star:hover { color: #f59e0b; }
.sem-msg-star-active { color: #f59e0b; }

.sem-msg-body {
  flex: 1;
  min-width: 0;
}

.sem-msg-top {
  display: flex;
  align-items: baseline;
  gap: 0;
  margin-bottom: 3px;
}

.sem-msg-from {
  font-size: 14px;
  color: #374151;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex-shrink: 0;
  max-width: 200px;
}

.sem-msg-subject {
  font-size: 14px;
  color: #6b7280;
  font-weight: 400;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;
  padding-left: 6px;
}

.sem-msg-date {
  font-size: 12px;
  color: #9ca3af;
  white-space: nowrap;
  flex-shrink: 0;
  margin-left: auto;
  padding-left: 12px;
}

.sem-msg-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 1px;
}

.sem-msg-paperclip {
  font-size: 12px;
  color: #9ca3af;
}

.sem-msg-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #2563eb;
  flex-shrink: 0;
  margin-top: 7px;
}

/* ── Pagination ────────────────────────────── */
.sem-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 11px 18px;
  border-top: 1px solid #f3f4f6;
  flex-shrink: 0;
  background: #fff;
}

.sem-page-btn {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 7px;
  padding: 6px 14px;
  font-size: 13px;
  cursor: pointer;
  color: #374151;
  font-weight: 500;
  transition: background 0.12s;
}
.sem-page-btn:hover:not(:disabled) { background: #d1d5db; }
.sem-page-btn:disabled { opacity: 0.35; cursor: default; }

.sem-page-info {
  font-size: 13px;
  color: #6b7280;
  min-width: 90px;
  text-align: center;
}

/* ── Detail pane ───────────────────────────── */
.sem-detail-pane {
  position: absolute;
  top: 0; right: 0; bottom: 0; left: 0;
  background: #fff;
  display: flex;
  flex-direction: column;
  z-index: 10;
  overflow: hidden;
}

.sem-detail-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 18px;
  border-bottom: 1px solid #f3f4f6;
  flex-shrink: 0;
  flex-wrap: wrap;
}

.sem-back-btn {
  background: none;
  border: 1px solid #e5e7eb;
  border-radius: 7px;
  padding: 7px 14px;
  font-size: 13px;
  cursor: pointer;
  color: #374151;
  white-space: nowrap;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 5px;
  transition: background 0.12s;
}
.sem-back-btn:hover { background: #d1d5db; }

.sem-detail-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-left: auto;
}

.sem-action-btn {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 7px;
  padding: 7px 13px;
  font-size: 13px;
  cursor: pointer;
  color: #374151;
  white-space: nowrap;
  font-weight: 500;
  transition: background 0.12s;
}
.sem-action-btn:hover { background: #d1d5db; }
.sem-action-delete { color: #dc2626; }
.sem-action-delete:hover { background: #fecaca; border-color: #f87171; }

/* Move-to dropdown */
.sem-move-wrap { position: relative; }
.sem-move-menu {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  z-index: 100;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  min-width: 170px;
  overflow: hidden;
}
.sem-move-option {
  display: block;
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  padding: 10px 16px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
  transition: background 0.1s;
}
.sem-move-option:hover { background: #d1d5db; }

.sem-detail-header {
  padding: 20px 24px 16px;
  border-bottom: 1px solid #f3f4f6;
  flex-shrink: 0;
}

.sem-detail-subject {
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  margin-bottom: 12px;
  letter-spacing: -0.02em;
  line-height: 1.3;
}

.sem-detail-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 20px;
}

.sem-detail-meta-row {
  display: flex;
  gap: 6px;
  font-size: 13px;
}
.sem-detail-meta-label { font-weight: 600; color: #374151; }
.sem-detail-meta-value { color: #6b7280; }

.sem-detail-body {
  flex: 1;
  overflow-y: auto;
  padding: 24px;
}

.sem-detail-iframe {
  width: 100%;
  border: none;
  min-height: 300px;
}

.sem-detail-plain {
  white-space: pre-wrap;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  font-size: 14px;
  color: #374151;
  line-height: 1.7;
}

/* ── Attachments panel ─────────────────────── */
.sem-attachments-panel {
  padding: 14px 24px;
  border-top: 1px solid #f3f4f6;
  background: #f9fafb;
  flex-shrink: 0;
}

.sem-attachments-label {
  font-size: 11px;
  font-weight: 700;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 10px;
}

.sem-attachments-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.sem-attachment-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 7px 12px;
  font-size: 13px;
  color: #374151;
  max-width: 260px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.sem-attachment-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 150px;
  font-weight: 500;
}

.sem-attachment-dl {
  background: none;
  border: none;
  color: #2563eb;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  padding: 0;
  white-space: nowrap;
  flex-shrink: 0;
}
.sem-attachment-dl:hover { text-decoration: underline; }

/* ── Compose backdrop ──────────────────────── */
.sem-compose-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 1040;
}

/* ── Compose pane ──────────────────────────── */
.sem-compose-pane {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 600px;
  max-width: calc(100vw - 32px);
  height: 80vh;
  max-height: 680px;
  min-height: 420px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  box-shadow: 0 24px 64px rgba(0,0,0,0.18), 0 0 0 1px rgba(0,0,0,0.06);
  display: flex;
  flex-direction: column;
  z-index: 1050;
}

.sem-compose-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 13px 18px;
  background: #111827;
  color: #f9fafb;
  border-radius: 12px 12px 0 0;
  flex-shrink: 0;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: -0.01em;
}

.sem-compose-close {
  background: none;
  border: none;
  color: #6b7280;
  cursor: pointer;
  font-size: 18px;
  padding: 0;
  line-height: 1;
  transition: color 0.12s;
}
.sem-compose-close:hover { color: #f9fafb; }

.sem-compose-form {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.sem-compose-field {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #f3f4f6;
  flex-shrink: 0;
}

.sem-compose-label {
  width: 68px;
  padding: 10px 16px;
  font-size: 12px;
  font-weight: 600;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  flex-shrink: 0;
}

.sem-compose-input {
  flex: 1;
  border: none;
  outline: none;
  padding: 10px 12px;
  font-size: 14px;
  color: #111827;
  background: transparent;
}

/* Rich text toolbar */
.sem-rte-toolbar {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 7px 14px;
  border-bottom: 1px solid #f3f4f6;
  flex-shrink: 0;
  background: #fafafa;
}

.sem-rte-btn {
  background: none;
  border: 1px solid transparent;
  border-radius: 5px;
  padding: 4px 8px;
  font-size: 13px;
  cursor: pointer;
  color: #374151;
  min-width: 30px;
  line-height: 1.4;
  transition: background 0.1s, color 0.1s;
}
.sem-rte-btn:hover { background: #d1d5db; border-color: #9ca3af; color: #111827; }
.sem-rte-btn:active { background: #dbeafe; border-color: #bfdbfe; color: #1d4ed8; }

/* File chip row in compose */
.sem-attach-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 6px 14px;
  border-bottom: 1px solid #f3f4f6;
  flex-shrink: 0;
  background: #fafafa;
}
.sem-attach-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  padding: 3px 8px;
  font-size: 12px;
  color: #1d4ed8;
  font-weight: 500;
  max-width: 220px;
}
.sem-attach-chip-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sem-attach-chip-rm {
  background: none;
  border: none;
  cursor: pointer;
  color: #93c5fd;
  font-size: 14px;
  padding: 0;
  line-height: 1;
  flex-shrink: 0;
  transition: color 0.1s;
}
.sem-attach-chip-rm:hover { color: #1d4ed8; }

.sem-rte-divider {
  width: 1px;
  height: 18px;
  background: #e5e7eb;
  margin: 0 4px;
}

/* Editable body */
.sem-compose-editor-wrap {
  flex: 1;
  overflow-y: auto;
  position: relative;
}

.sem-compose-editor {
  min-height: 100%;
  padding: 14px 16px;
  font-size: 14px;
  color: #111827;
  line-height: 1.65;
  outline: none;
}

.sem-compose-editor.is-empty::before {
  content: attr(data-placeholder);
  color: #9ca3af;
  pointer-events: none;
  display: block;
}

/* Signature strip */
.sem-sig-strip {
  border-top: 1px solid #f3f4f6;
  padding: 10px 16px;
  flex-shrink: 0;
  background: #fafafa;
}

.sem-sig-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 700;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 4px;
}

.sem-sig-edit-btn {
  background: none;
  border: none;
  font-size: 12px;
  color: #2563eb;
  cursor: pointer;
  font-weight: 600;
  padding: 0;
  text-transform: none;
  letter-spacing: 0;
}
.sem-sig-edit-btn:hover { text-decoration: underline; }

.sem-sig-preview {
  font-size: 12px;
  color: #6b7280;
  white-space: pre-wrap;
  max-height: 50px;
  overflow: hidden;
}

.sem-sig-textarea {
  width: 100%;
  border: 1px solid #e5e7eb;
  border-radius: 7px;
  padding: 9px 12px;
  font-size: 13px;
  font-family: inherit;
  color: #374151;
  resize: vertical;
  box-sizing: border-box;
  margin-top: 8px;
  outline: none;
  transition: border-color 0.15s;
}
.sem-sig-textarea:focus { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(147,197,253,0.2); }

.sem-sig-editor-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
}

.sem-sig-save-btn {
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 7px 16px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.12s;
}
.sem-sig-save-btn:hover { background: #1d4ed8; }

.sem-sig-saved-msg { font-size: 12px; color: #16a34a; font-weight: 600; }

.sem-compose-footer {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-top: 1px solid #f3f4f6;
  flex-shrink: 0;
  background: #fff;
}

.sem-send-btn {
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 9px 24px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  letter-spacing: -0.01em;
  transition: background 0.12s, box-shadow 0.12s;
  box-shadow: 0 1px 3px rgba(37,99,235,0.25);
}
.sem-send-btn:hover { background: #1d4ed8; box-shadow: 0 2px 8px rgba(37,99,235,0.3); }
.sem-send-btn:disabled { opacity: 0.6; cursor: default; box-shadow: none; }

.sem-cancel-btn {
  background: none;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 9px 16px;
  font-size: 14px;
  cursor: pointer;
  color: #374151;
  font-weight: 500;
  transition: background 0.12s;
}
.sem-cancel-btn:hover { background: #d1d5db; }

.sem-send-status {
  font-size: 13px;
  margin-left: 4px;
}

/* ── Loading / empty ───────────────────────── */
.sem-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
  padding: 60px 20px;
  color: #9ca3af;
  font-size: 14px;
}

.sem-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #f3f4f6;
  border-top-color: #2563eb;
  border-radius: 50%;
  animation: sem-spin 0.75s linear infinite;
}
@keyframes sem-spin { to { transform: rotate(360deg); } }

.sem-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 80px 20px;
  color: #9ca3af;
  text-align: center;
}
.sem-empty-icon {
  font-size: 48px;
  line-height: 1;
  margin-bottom: 4px;
  opacity: 0.5;
}
.sem-empty-title {
  font-size: 16px;
  font-weight: 600;
  color: #374151;
}
.sem-empty-sub {
  font-size: 14px;
  color: #9ca3af;
}

/* ── No account ────────────────────────────── */
.sem-no-account {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 340px;
  padding: 40px 20px;
}
.sem-no-account-inner { text-align: center; max-width: 380px; }
.sem-no-account-icon { font-size: 52px; margin-bottom: 18px; color: #d1d5db; line-height: 1; }

/* ── Scrollbar ─────────────────────────────── */
.sem-message-list::-webkit-scrollbar,
.sem-detail-body::-webkit-scrollbar,
.sem-folders::-webkit-scrollbar,
.sem-compose-editor-wrap::-webkit-scrollbar { width: 5px; }
.sem-message-list::-webkit-scrollbar-thumb,
.sem-detail-body::-webkit-scrollbar-thumb,
.sem-folders::-webkit-scrollbar-thumb,
.sem-compose-editor-wrap::-webkit-scrollbar-thumb {
  background: #e5e7eb;
  border-radius: 99px;
}
.sem-message-list::-webkit-scrollbar-thumb:hover,
.sem-detail-body::-webkit-scrollbar-thumb:hover { background: #d1d5db; }

/* ── Mobile responsive ─────────────────────── */
@media (max-width: 700px) {
  .sem-mailbox {
    height: calc(100vh - 80px);
    border-radius: 0;
    border-left: none;
    border-right: none;
    margin: 0;
  }

  .sem-mob-toggle { display: flex; }

  .sem-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 150;
    transform: translateX(-100%);
    width: 280px;
    min-width: 280px;
    border-radius: 0 16px 16px 0;
    box-shadow: 4px 0 24px rgba(0,0,0,0.12);
  }
  .sem-sidebar-open { transform: translateX(0); }

  .sem-list-toolbar { padding: 12px 14px 12px 54px; }
  .sem-folder-title { font-size: 16px; }

  .sem-compose-pane {
    width: calc(100vw - 20px);
    max-width: calc(100vw - 20px);
    height: 90vh;
    max-height: 90vh;
    border-radius: 12px;
  }

  .sem-msg-from { max-width: 140px; }
  .sem-detail-body { padding: 16px; }
  .sem-detail-header { padding: 14px 16px 12px; }
  .sem-detail-subject { font-size: 17px; }
}

@media (min-width: 701px) and (max-width: 960px) {
  .sem-sidebar { width: 200px; min-width: 200px; }
  .sem-compose-btn { font-size: 13px; }
  .sem-folder-item { font-size: 13px; }
  .sem-compose-pane { width: 440px; }
}
</style>

<script>
(function () {
'use strict';

var mb = document.getElementById('sem-mailbox');
if (!mb) return;

// ── semDebug: styled console logger ────────────────────────────────────────
// All SEM debug output is tagged with a blue badge so it's easy to filter.
// Usage: semDebug("label", optionalData)
// In Chrome DevTools Console, filter by "[SEM]" to see only SEM messages.
function semDebug(msg, data) {
  if (data !== undefined) {
    console.log(
      '%c[SEM]%c ' + msg,
      'background:#0d6efd;color:#fff;padding:1px 6px;border-radius:3px;font-weight:bold',
      'color:inherit',
      data
    );
  } else {
    console.log(
      '%c[SEM]%c ' + msg,
      'background:#0d6efd;color:#fff;padding:1px 6px;border-radius:3px;font-weight:bold',
      'color:inherit'
    );
  }
}
window.semDebug = semDebug; // expose globally so DevTools console can call it

// Catch any uncaught JS error inside the mailbox and surface it visibly.
window.addEventListener('error', function (ev) {
  semDebug('Uncaught JS error: ' + ev.message + ' @ ' + ev.filename + ':' + ev.lineno);
  var listEl = document.getElementById('sem-message-list');
  if (listEl && !listEl.querySelector('.sem-message-row, .sem-empty-state')) {
    listEl.innerHTML = '<div class="sem-empty-state"><div style="color:#dc2626">JavaScript error: ' +
      String(ev.message || 'unknown').replace(/</g,'&lt;') + '</div>' +
      '<div style="font-size:12px;color:#6b7280;margin-top:8px">Open browser console (F12) for details.</div></div>';
  }
});

var AJAX_URL   = mb.dataset.ajax;
var SESSKEY    = mb.dataset.sesskey;
var MY_EMAIL   = mb.dataset.email;
var MY_NAME    = mb.dataset.name;
var CAN_SEND   = mb.dataset.cansend === '1';
var PAGE_SIZE  = 50;

// ── State ──────────────────────────────────────────────────────────────
var state = {
  folder:      'INBOX',
  page:        1,
  total:       0,
  allMessages: [],   // current page messages
  openMsgno:   null,
  openMsg:     null,
  folders:     [],
  signature:   mb.dataset.signature || '',
  isSearch:    false,
  searchQuery: ''
};

// ── AJAX helper (URL-encoded) ───────────────────────────────────────────
function ajax(params, cb) {
  params.sesskey = SESSKEY;
  var action = params.action || '?';
  var qs = Object.keys(params).map(function (k) {
    return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
  }).join('&');
  var xhr = new XMLHttpRequest();
  xhr.open('POST', AJAX_URL, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  semDebug('→ Sending AJAX', { action: action });
  xhr.onload = function () {
    var step = xhr.getResponseHeader('X-SEM-Step');
    var fatal = xhr.getResponseHeader('X-FATAL');
    try {
      var r = JSON.parse(xhr.responseText);
      semDebug('← Response', { action: action, httpStatus: xhr.status, success: r.success, message: r.message || r.error || '', semStep: step });
      if (r.smtp_log) {
        semDebug('SMTP conversation for action=' + action, '\n' + r.smtp_log);
      }
      if (r.debug) {
        semDebug('PHP debug info', r.debug);
      }
      if (fatal) {
        semDebug('PHP FATAL header', fatal);
      }
      cb(r);
    } catch (e) {
      semDebug('Non-JSON response (parse error)', { action: action, httpStatus: xhr.status, semStep: step, body: xhr.responseText.substring(0, 600) });
      if (fatal) { semDebug('PHP FATAL header', fatal); }
      cb({ success: false, message: 'Server error. Please try again.' });
    }
  };
  xhr.onerror = function () {
    semDebug('Network error', { action: action });
    cb({ success: false, message: 'Network error.' });
  };
  xhr.send(qs);
}

// ── AJAX helper (FormData — only used when file attachments are present) ──
function ajaxForm(formData, cb) {
  formData.append('sesskey', SESSKEY);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', AJAX_URL, true);
  semDebug('→ Sending AJAX (FormData)', { action: 'send_message' });
  xhr.onload = function () {
    var step = xhr.getResponseHeader('X-SEM-Step');
    var fatal = xhr.getResponseHeader('X-FATAL');
    try {
      var r = JSON.parse(xhr.responseText);
      semDebug('← Response', { action: 'send_message(form)', httpStatus: xhr.status, success: r.success, message: r.message || r.error || '', semStep: step });
      if (r.smtp_log) {
        semDebug('SMTP conversation (send_message/form)', '\n' + r.smtp_log);
      }
      if (r.debug) {
        semDebug('PHP debug info', r.debug);
      }
      if (fatal) { semDebug('PHP FATAL header', fatal); }
      cb(r);
    } catch (e) {
      semDebug('Non-JSON response (FormData)', { httpStatus: xhr.status, semStep: step, body: xhr.responseText.substring(0, 600) });
      if (fatal) { semDebug('PHP FATAL header', fatal); }
      cb({ success: false, message: 'Server error. Please try again.' });
    }
  };
  xhr.onerror = function () {
    semDebug('Network error', { action: 'send_message(form)' });
    cb({ success: false, message: 'Network error.' });
  };
  xhr.send(formData);
}

// ── Folder loading ─────────────────────────────────────────────────────
function loadFolder(folder, el, resetPage) {
  if (resetPage !== false) { state.page = 1; }
  state.folder    = folder;
  state.isSearch  = false;
  state.searchQuery = '';

  document.getElementById('sem-search-input').value = '';
  document.getElementById('sem-search-clear').style.display = 'none';

  var items = document.querySelectorAll('.sem-folder-item');
  for (var i = 0; i < items.length; i++) items[i].classList.remove('sem-folder-active');
  if (el) { el.classList.add('sem-folder-active'); }
  else {
    var match = document.querySelector('.sem-folder-item[data-folder="' + folder + '"]');
    if (match) match.classList.add('sem-folder-active');
  }

  document.getElementById('sem-folder-title').textContent = folderLabel(folder);
  document.getElementById('sem-detail-pane').style.display = 'none';
  document.getElementById('sem-compose-pane').style.display = 'none';

  var offset = (state.page - 1) * PAGE_SIZE;
  showListLoading(true);
  ajax({ action: 'get_folder', folder: folder, limit: PAGE_SIZE, offset: offset }, function (data) {
    showListLoading(false);
    if (!data.success) { renderError(data.message || data.error || 'Unable to load messages. Please refresh or contact support.'); return; }
    state.total       = data.total || 0;
    state.allMessages = data.messages || [];
    renderMessageList(state.allMessages);
    updatePagination(state.total, offset);
  });
}
window.SEM = window.SEM || {};
SEM.loadFolder = loadFolder;

function folderLabel(f) {
  var map = { INBOX: 'Inbox', Sent: 'Sent', Drafts: 'Drafts', Trash: 'Trash', Spam: 'Spam' };
  return map[f] || f;
}

function refreshFolder() {
  loadFolder(state.folder, null, false);
}
SEM.refreshFolder = refreshFolder;

// ── Pagination ─────────────────────────────────────────────────────────
function updatePagination(total, offset) {
  var pag = document.getElementById('sem-pagination');
  if (!total) { pag.style.display = 'none'; return; }
  var from = offset + 1;
  var to   = Math.min(offset + PAGE_SIZE, total);
  document.getElementById('sem-page-info').textContent = from + '–' + to + ' of ' + total;
  document.getElementById('sem-page-prev').disabled = (state.page <= 1);
  document.getElementById('sem-page-next').disabled = (to >= total);
  pag.style.display = total > 0 ? 'flex' : 'none';

  // Do NOT show total message count in the unread badge — it is misleading.
  // Unread count is already managed by refreshBadge() in the sidebar.
  document.getElementById('sem-folder-unread').style.display = 'none';
}

function prevPage() {
  if (state.page <= 1) return;
  state.page--;
  if (state.isSearch) { runSearch(); } else { loadFolder(state.folder, null, false); }
}
SEM.prevPage = prevPage;

function nextPage() {
  var offset = state.page * PAGE_SIZE;
  if (offset >= state.total) return;
  state.page++;
  if (state.isSearch) { runSearch(); } else { loadFolder(state.folder, null, false); }
}
SEM.nextPage = nextPage;

// ── Server search ──────────────────────────────────────────────────────
function triggerSearch() {
  var q = document.getElementById('sem-search-input').value.trim();
  if (!q) { clearSearch(); return; }
  state.searchQuery = q;
  state.isSearch    = true;
  state.page        = 1;
  document.getElementById('sem-search-clear').style.display = 'flex';
  runSearch();
}
SEM.triggerSearch = triggerSearch;

function runSearch() {
  var offset = (state.page - 1) * PAGE_SIZE;
  showListLoading(true);
  ajax({ action: 'server_search', folder: state.folder, query: state.searchQuery, limit: PAGE_SIZE, offset: offset }, function (data) {
    showListLoading(false);
    if (!data.success) { renderError(data.message || data.error || 'Search failed. Please try again.'); return; }
    state.total       = data.total || 0;
    state.allMessages = data.messages || [];
    renderMessageList(state.allMessages, true);
    updatePagination(state.total, offset);
    document.getElementById('sem-folder-title').textContent = 'Results for "' + state.searchQuery + '"';
    document.getElementById('sem-folder-unread').style.display = 'none';
  });
}

function clearSearch() {
  state.isSearch    = false;
  state.searchQuery = '';
  state.page        = 1;
  document.getElementById('sem-search-input').value = '';
  document.getElementById('sem-search-clear').style.display = 'none';
  loadFolder(state.folder, null, true);
}
SEM.clearSearch = clearSearch;

// ── Message list rendering ─────────────────────────────────────────────
function renderMessageList(messages, isSearch) {
  var el = document.getElementById('sem-message-list');
  if (!messages.length) {
    var emptyTitle = isSearch ? 'No results found' : folderLabel(state.folder) + ' is empty';
    var emptySub   = isSearch ? 'Try different keywords or clear your search.' : 'New messages will appear here.';
    el.innerHTML = '<div class="sem-empty-state">' +
      '<div class="sem-empty-icon">&#9993;</div>' +
      '<div class="sem-empty-title">' + esc(emptyTitle) + '</div>' +
      '<div class="sem-empty-sub">' + esc(emptySub) + '</div>' +
      '</div>';
    return;
  }
  var html = '';
  for (var i = 0; i < messages.length; i++) {
    var m   = messages[i];
    var cls = 'sem-message-row' + (m.seen ? '' : ' sem-unread');
    var starCls = 'sem-msg-star' + (m.flagged ? ' sem-msg-star-active' : '');
    html += '<div class="' + cls + '" data-msgno="' + m.msgno + '" data-flagged="' + (m.flagged ? '1' : '0') + '">' +
      '<div class="' + starCls + '" title="Star" onclick="SEM.toggleFlag(' + m.msgno + ',this,event)">&#9733;</div>' +
      '<div class="sem-msg-body" onclick="SEM.openMessage(' + m.msgno + ')">' +
        '<div class="sem-msg-top">' +
          '<span class="sem-msg-from">' + esc(m.from) + '</span>' +
          '<span class="sem-msg-subject">' + esc(m.subject) + '</span>' +
          '<span class="sem-msg-date">' + esc(m.date) + '</span>' +
        '</div>' +
      '</div>' +
      (!m.seen ? '<div class="sem-msg-dot"></div>' : '') +
    '</div>';
  }
  el.innerHTML = html;
}

// ── Open / close message ───────────────────────────────────────────────
function openMessage(msgno) {
  state.openMsgno = msgno;
  state.openMsg   = null;

  var detail = document.getElementById('sem-detail-pane');
  detail.style.display = 'flex';

  document.getElementById('sem-detail-header').innerHTML = '';
  document.getElementById('sem-detail-content').innerHTML = '';
  document.getElementById('sem-attachments-panel').style.display = 'none';
  document.getElementById('sem-detail-loading').style.display = 'flex';
  document.getElementById('sem-move-menu').style.display = 'none';

  buildMoveMenu();

  ajax({ action: 'get_message', msgno: msgno, folder: state.folder }, function (data) {
    document.getElementById('sem-detail-loading').style.display = 'none';
    if (!data.success) { document.getElementById('sem-detail-content').innerHTML = '<p style="color:#dc2626">' + esc(data.message) + '</p>'; return; }
    var msg = data.message;
    state.openMsg = msg;

    document.getElementById('sem-detail-header').innerHTML =
      '<div class="sem-detail-subject">' + esc(msg.subject) + '</div>' +
      '<div class="sem-detail-meta">' +
        '<div class="sem-detail-meta-row"><span class="sem-detail-meta-label">From:</span><span class="sem-detail-meta-value">' + esc(msg.from_name) + ' &lt;' + esc(msg.from_email) + '&gt;</span></div>' +
        '<div class="sem-detail-meta-row"><span class="sem-detail-meta-label">To:</span><span class="sem-detail-meta-value">' + esc(msg.to) + '</span></div>' +
        (msg.cc ? '<div class="sem-detail-meta-row"><span class="sem-detail-meta-label">CC:</span><span class="sem-detail-meta-value">' + esc(msg.cc) + '</span></div>' : '') +
        '<div class="sem-detail-meta-row"><span class="sem-detail-meta-label">Date:</span><span class="sem-detail-meta-value">' + esc(msg.date) + '</span></div>' +
      '</div>';

    if (msg.body_html) {
      var iframe = document.createElement('iframe');
      iframe.className = 'sem-detail-iframe';
      iframe.sandbox   = 'allow-popups allow-popups-to-escape-sandbox';
      iframe.srcdoc    = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' +
        'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px;color:#111827;padding:4px;margin:0;word-break:break-word;}' +
        'img{max-width:100%;height:auto;}a{color:#2563eb;}' +
        '</style></head><body>' + msg.body_html + '</body></html>';

      var content = document.getElementById('sem-detail-content');
      content.innerHTML = '';
      content.appendChild(iframe);

      // Auto-resize iframe.
      iframe.onload = function () {
        try {
          var h = iframe.contentDocument.body.scrollHeight;
          iframe.style.height = Math.max(h + 20, 200) + 'px';
        } catch (e) { iframe.style.height = '400px'; }
      };
    } else {
      document.getElementById('sem-detail-content').innerHTML =
        '<div class="sem-detail-plain">' + esc(msg.body_plain || '(empty message)') + '</div>';
    }

    if (msg.attachments && msg.attachments.length) {
      var ap   = document.getElementById('sem-attachments-panel');
      var list = document.getElementById('sem-attachments-list');
      var ahtml = '';
      for (var i = 0; i < msg.attachments.length; i++) {
        var a = msg.attachments[i];
        ahtml += '<div class="sem-attachment-chip">' +
          '<span class="sem-attachment-name" title="' + esc(a.filename) + '">' + esc(a.filename) + '</span>' +
          '<span class="sem-attach-size" style="font-size:11px;color:#9ca3af;flex-shrink:0">' + formatSize(a.size) + '</span>' +
          '<button class="sem-attachment-dl" onclick="SEM.downloadAttachment(' + state.openMsgno + ',\'' + escJs(a.partnum) + '\',\'' + escJs(a.filename) + '\')">Download</button>' +
        '</div>';
      }
      list.innerHTML = ahtml;
      ap.style.display = 'block';
    }

    // Mark row as read.
    var rows = document.querySelectorAll('.sem-message-row');
    for (var r = 0; r < rows.length; r++) {
      if (parseInt(rows[r].dataset.msgno, 10) === msgno) {
        rows[r].classList.remove('sem-unread');
        var dot = rows[r].querySelector('.sem-msg-dot');
        if (dot) dot.style.display = 'none';
      }
    }
  });
}
SEM.openMessage = openMessage;

function closeMessage() {
  document.getElementById('sem-detail-pane').style.display = 'none';
  state.openMsgno = null;
  state.openMsg   = null;
}
SEM.closeMessage = closeMessage;

// ── Star / flag ────────────────────────────────────────────────────────
function toggleFlag(msgno, starEl, ev) {
  if (ev) ev.stopPropagation();
  var row     = starEl.parentNode;
  var flagged = row.dataset.flagged !== '1';
  row.dataset.flagged = flagged ? '1' : '0';
  starEl.classList.toggle('sem-msg-star-active', flagged);
  ajax({ action: 'toggle_flag', msgno: msgno, folder: state.folder, flagged: flagged ? '1' : '0' }, function () {});
}
SEM.toggleFlag = toggleFlag;

// ── Mark unread ────────────────────────────────────────────────────────
function markUnread() {
  if (!state.openMsgno) return;
  ajax({ action: 'mark_read', msgno: state.openMsgno, folder: state.folder, read: '0' }, function (data) {
    if (!data.success) return;
    var rows = document.querySelectorAll('.sem-message-row');
    for (var r = 0; r < rows.length; r++) {
      if (parseInt(rows[r].dataset.msgno, 10) === state.openMsgno) {
        rows[r].classList.add('sem-unread');
      }
    }
    closeMessage();
  });
}
SEM.markUnread = markUnread;

// ── Move to folder ─────────────────────────────────────────────────────
function buildMoveMenu() {
  var menu  = document.getElementById('sem-move-menu');
  var html  = '';
  var known = ['INBOX', 'Sent', 'Drafts', 'Trash', 'Spam'];
  var all   = known.concat(state.folders.filter(function (f) { return known.indexOf(f) < 0; }));
  for (var i = 0; i < all.length; i++) {
    if (all[i] === state.folder) continue;
    html += '<button class="sem-move-option" onclick="SEM.moveToFolder(\'' + escJs(all[i]) + '\')">' + esc(folderLabel(all[i])) + '</button>';
  }
  menu.innerHTML = html || '<div style="padding:10px 14px;font-size:12px;color:#9ca3af">No other folders</div>';
}

function toggleMoveMenu() {
  var menu = document.getElementById('sem-move-menu');
  menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}
SEM.toggleMoveMenu = toggleMoveMenu;

/**
 * Remove a message row from the list DOM, decrement state.total, and
 * refresh the current page if the page is now empty.
 * Called by both moveToFolder and deleteCurrentMessage.
 */
function removeRowAndUpdateCount(msgno) {
  var rows = document.querySelectorAll('.sem-message-row');
  for (var r = 0; r < rows.length; r++) {
    if (parseInt(rows[r].dataset.msgno, 10) === msgno) {
      rows[r].parentNode.removeChild(rows[r]);
    }
  }
  state.total = Math.max(0, state.total - 1);
  var remaining = document.querySelectorAll('.sem-message-row').length;
  if (remaining === 0 && state.total > 0) {
    // Last item on a mid-set page; back up one page then reload.
    state.page = Math.max(1, state.page - 1);
    if (state.isSearch) { runSearch(); } else { loadFolder(state.folder, null, false); }
  } else if (remaining === 0) {
    // Folder is now empty — reload to show the empty-state message.
    if (state.isSearch) { runSearch(); } else { loadFolder(state.folder, null, true); }
  } else {
    // Update the x–y of N info text without a full reload.
    var offset = (state.page - 1) * PAGE_SIZE;
    document.getElementById('sem-page-info').textContent =
      (offset + 1) + '–' + Math.min(offset + remaining, state.total) + ' of ' + state.total;
  }
}

function moveToFolder(toFolder) {
  if (!state.openMsgno) return;
  document.getElementById('sem-move-menu').style.display = 'none';
  var msgno = state.openMsgno;
  ajax({ action: 'move_message', msgno: msgno, from_folder: state.folder, to_folder: toFolder }, function (data) {
    if (!data.success) { alert(data.message); return; }
    closeMessage();
    removeRowAndUpdateCount(msgno);
  });
}
SEM.moveToFolder = moveToFolder;

// ── Delete ─────────────────────────────────────────────────────────────
function deleteCurrentMessage() {
  if (!state.openMsgno || !confirm('Delete this message?')) return;
  var msgno = state.openMsgno;
  ajax({ action: 'delete_message', msgno: msgno, folder: state.folder }, function (data) {
    if (!data.success) { alert(data.message); return; }
    closeMessage();
    removeRowAndUpdateCount(msgno);
  });
}
SEM.deleteCurrentMessage = deleteCurrentMessage;

// ── Attachment download ────────────────────────────────────────────────
function downloadAttachment(msgno, partnum, filename) {
  ajax({ action: 'get_attachment', msgno: msgno, partnum: partnum, folder: state.folder }, function (data) {
    if (!data.success) { alert(data.message); return; }
    try {
      var binary = atob(data.data);
      var bytes  = new Uint8Array(binary.length);
      for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
      var blob = new Blob([bytes], { type: data.mime || 'application/octet-stream' });
      var url  = URL.createObjectURL(blob);
      var a    = document.createElement('a');
      a.href   = url;
      a.download = data.filename || filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
    } catch (e) {
      alert('Could not download attachment: ' + e.message);
    }
  });
}
SEM.downloadAttachment = downloadAttachment;

// ── Compose / Reply ────────────────────────────────────────────────────
/**
 * Build editor HTML: one blank line for typing, then optional signature,
 * then the optional quoted block. This is the single source of truth for
 * compose content so signature is consistent across new/reply/forward.
 */
function composeBody(quote) {
  var sigHtml = '';
  if (state.signature) {
    sigHtml = '<br><div contenteditable="false" style="border-top:1px solid #e5e7eb;padding-top:6px;margin-top:6px;color:#6b7280;font-size:12px;white-space:pre-wrap;">' + esc(state.signature) + '</div>';
  }
  return '<p><br></p>' + sigHtml + (quote || '');
}

function openCompose() {
  resetCompose();
  document.getElementById('sem-compose-title').textContent = 'New Message';
  setEditorContent(composeBody(''));
  document.getElementById('sem-compose-backdrop').style.display = 'block';
  document.getElementById('sem-compose-pane').style.display = 'flex';
  document.getElementById('sem-compose-to').focus();
}
SEM.openCompose = openCompose;

function openReply() {
  if (!state.openMsg) return;
  resetCompose();
  document.getElementById('sem-compose-title').textContent = 'Reply';
  document.getElementById('sem-compose-to').value    = state.openMsg.from_email;
  document.getElementById('sem-compose-subject').value = prefixSubject('Re', state.openMsg.subject);
  setEditorContent(composeBody(quoteHtml(state.openMsg)));
  document.getElementById('sem-compose-backdrop').style.display = 'block';
  document.getElementById('sem-compose-pane').style.display = 'flex';
}
SEM.openReply = openReply;

function openReplyAll() {
  if (!state.openMsg) return;
  resetCompose();
  document.getElementById('sem-compose-title').textContent = 'Reply All';

  var addrs = [];
  var src   = (state.openMsg.from_email || '') + ',' + (state.openMsg.to || '') + ',' + (state.openMsg.cc || '');
  var parts = src.split(',');
  for (var i = 0; i < parts.length; i++) {
    var a = parts[i].trim().replace(/^.*<|>$/g, '').trim();
    if (a && a.toLowerCase() !== MY_EMAIL.toLowerCase() && addrs.indexOf(a) < 0) {
      addrs.push(a);
    }
  }
  document.getElementById('sem-compose-to').value      = addrs.join(', ');
  document.getElementById('sem-compose-subject').value  = prefixSubject('Re', state.openMsg.subject);
  setEditorContent(composeBody(quoteHtml(state.openMsg)));
  document.getElementById('sem-compose-backdrop').style.display = 'block';
  document.getElementById('sem-compose-pane').style.display = 'flex';
}
SEM.openReplyAll = openReplyAll;

function openForward() {
  if (!state.openMsg) return;
  resetCompose();
  document.getElementById('sem-compose-title').textContent = 'Forward';
  document.getElementById('sem-compose-subject').value = prefixSubject('Fwd', state.openMsg.subject);
  setEditorContent(composeBody(quoteHtml(state.openMsg)));
  document.getElementById('sem-compose-backdrop').style.display = 'block';
  document.getElementById('sem-compose-pane').style.display = 'flex';
  document.getElementById('sem-compose-to').focus();
}
SEM.openForward = openForward;

function closeCompose() {
  document.getElementById('sem-compose-pane').style.display = 'none';
  document.getElementById('sem-compose-backdrop').style.display = 'none';
}
SEM.closeCompose = closeCompose;

function resetCompose() {
  document.getElementById('sem-compose-to').value      = '';
  document.getElementById('sem-compose-cc').value      = '';
  document.getElementById('sem-compose-subject').value = '';
  document.getElementById('sem-send-status').textContent = '';
  // Clear file attachments.
  var fileInput = document.getElementById('sem-attach-input');
  if (fileInput) { fileInput.value = ''; state.attachedFiles = []; }
  var chips = document.getElementById('sem-attach-chips');
  if (chips) { chips.innerHTML = ''; chips.style.display = 'none'; }
  closeSigEdit();
  updateSigPreview();
}

function prefixSubject(prefix, subject) {
  var s = subject || '';
  if (s.toLowerCase().indexOf(prefix.toLowerCase() + ':') === 0) return s;
  return prefix + ': ' + s;
}

function quoteHtml(msg) {
  var quoteHead = '<br><br><div style="border-left:3px solid #d1d5db;padding-left:12px;margin-top:12px;color:#6b7280;font-size:13px;">' +
    '<p style="margin:0 0 8px"><strong>' + esc(msg.from_name) + '</strong> wrote on ' + esc(msg.date) + ':</p>';
  var body = msg.body_html
    ? msg.body_html
    : '<pre style="white-space:pre-wrap;font-size:13px">' + esc(msg.body_plain || '') + '</pre>';
  return quoteHead + body + '</div>';
}

// ── Rich text editor ───────────────────────────────────────────────────
function execCmd(cmd) {
  document.getElementById('sem-compose-editor').focus();
  document.execCommand(cmd, false, null);
}
SEM.execCmd = execCmd;

function insertOrderedList() {
  document.getElementById('sem-compose-editor').focus();
  document.execCommand('insertOrderedList', false, null);
}
SEM.insertOrderedList = insertOrderedList;

function insertUnorderedList() {
  document.getElementById('sem-compose-editor').focus();
  document.execCommand('insertUnorderedList', false, null);
}
SEM.insertUnorderedList = insertUnorderedList;

function insertLink() {
  var url = prompt('Enter URL (e.g. https://example.com):');
  if (url) {
    if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
    document.getElementById('sem-compose-editor').focus();
    document.execCommand('createLink', false, url);
  }
}
SEM.insertLink = insertLink;

function getEditorContent() {
  return document.getElementById('sem-compose-editor').innerHTML || '';
}

function setEditorContent(html) {
  var editor = document.getElementById('sem-compose-editor');
  editor.innerHTML = html;
  syncEditorEmpty(editor);
}

function syncEditorEmpty(editor) {
  editor = editor || document.getElementById('sem-compose-editor');
  editor.classList.toggle('is-empty', !editor.textContent.trim());
}

// ── Signature management ───────────────────────────────────────────────
function openSigEdit() {
  document.getElementById('sem-sig-editor-wrap').style.display = 'block';
  document.getElementById('sem-sig-textarea').value = state.signature;
  document.getElementById('sem-sig-edit-btn').textContent = 'Close';
  document.getElementById('sem-sig-edit-btn').onclick = function () { closeSigEdit(); };
}
SEM.openSigEdit = openSigEdit;

function closeSigEdit() {
  document.getElementById('sem-sig-editor-wrap').style.display = 'none';
  document.getElementById('sem-sig-saved-msg').style.display = 'none';
  document.getElementById('sem-sig-edit-btn').textContent = 'Edit';
  document.getElementById('sem-sig-edit-btn').onclick = function () { openSigEdit(); };
}
SEM.closeSigEdit = closeSigEdit;

function saveSignature() {
  var sig = document.getElementById('sem-sig-textarea').value;
  ajax({ action: 'save_signature', signature: sig }, function (data) {
    if (!data.success) { alert(data.message); return; }
    state.signature = sig;
    updateSigPreview();
    var saved = document.getElementById('sem-sig-saved-msg');
    saved.style.display = 'inline';
    setTimeout(function () { saved.style.display = 'none'; closeSigEdit(); }, 1800);
  });
}
SEM.saveSignature = saveSignature;

function updateSigPreview() {
  var el = document.getElementById('sem-sig-preview');
  el.textContent = state.signature || '(no signature set)';
}

// ── Send message ───────────────────────────────────────────────────────
function sendMessage() {
  var to      = document.getElementById('sem-compose-to').value.trim();
  var cc      = document.getElementById('sem-compose-cc').value.trim();
  var subject = document.getElementById('sem-compose-subject').value.trim();
  var body    = getEditorContent();

  semDebug('sendMessage() called', { to: to, cc: cc, subject: subject, bodyLength: body.length });

  if (!to) { alert('Please enter at least one recipient.'); return; }
  if (!subject) { alert('Please enter a subject.'); return; }

  var btn    = document.querySelector('.sem-send-btn');
  var status = document.getElementById('sem-send-status');
  btn.disabled = true;
  document.getElementById('sem-send-label').textContent = 'Sending…';
  status.textContent = '';
  status.style.color = '';

  var fileInput = document.getElementById('sem-attach-input');
  var hasFiles  = fileInput && fileInput.files && fileInput.files.length > 0;
  semDebug('Preparing request', { hasFiles: hasFiles, fileCount: hasFiles ? fileInput.files.length : 0 });

  function handleResponse(data) {
    btn.disabled = false;
    document.getElementById('sem-send-label').textContent = 'Send';
    if (data.success) {
      semDebug('Send SUCCESS');
      status.textContent = 'Sent!';
      status.style.color = '#16a34a';
      setTimeout(function () { closeCompose(); }, 1400);
    } else {
      semDebug('Send FAILED', { message: data.message, debug: data.debug || null });
      status.textContent = data.message || 'Send failed.';
      status.style.color = '#dc2626';
    }
  }

  if (hasFiles) {
    // Files present — must use FormData (multipart). Note: some WAFs block
    // multipart POSTs; without files we use URL-encoded to avoid this.
    semDebug('Using FormData path (has attachments)');
    var fd = new FormData();
    fd.append('action', 'send_message');
    fd.append('to', to);
    fd.append('cc', cc);
    fd.append('subject', subject);
    fd.append('body', body);
    for (var i = 0; i < fileInput.files.length; i++) {
      fd.append('attachments[]', fileInput.files[i]);
    }
    ajaxForm(fd, handleResponse);
  } else {
    // No attachments — use standard URL-encoded POST (same as all other
    // actions). This avoids WAF rules that block multipart/form-data.
    semDebug('Using URL-encoded path (no attachments)');
    ajax({ action: 'send_message', to: to, cc: cc, subject: subject, body: body }, handleResponse);
  }
}
SEM.sendMessage = sendMessage;

// ── File attachment helpers ─────────────────────────────────────────────
function triggerAttach() {
  var fi = document.getElementById('sem-attach-input');
  if (fi) fi.click();
}
SEM.triggerAttach = triggerAttach;

function handleFileAttach(input) {
  if (!input.files || !input.files.length) return;
  var chips = document.getElementById('sem-attach-chips');
  chips.style.display = 'flex';
  // Rebuild chips from all currently selected files.
  var html = '';
  for (var i = 0; i < input.files.length; i++) {
    var name = input.files[i].name;
    var size = formatSize(input.files[i].size);
    html += '<div class="sem-attach-chip">' +
      '<span class="sem-attach-chip-name" title="' + esc(name) + '">' + esc(name) + '</span>' +
      '<span style="font-size:10px;color:#93c5fd;flex-shrink:0">' + size + '</span>' +
    '</div>';
  }
  chips.innerHTML = html;
}
SEM.handleFileAttach = handleFileAttach;

// ── Loading helpers ────────────────────────────────────────────────────
function showListLoading(show) {
  var el = document.getElementById('sem-list-loading');
  if (!el) return;
  if (show) {
    el.style.display = 'flex';
    document.getElementById('sem-message-list').innerHTML = '';
    document.getElementById('sem-message-list').appendChild(el);
  } else {
    el.style.display = 'none';
  }
}

function renderError(msg) {
  document.getElementById('sem-message-list').innerHTML =
    '<div class="sem-empty-state"><div style="color:#dc2626">' + esc(msg) + '</div></div>';
}

// ── Unread badge auto-refresh ──────────────────────────────────────────
function refreshBadge() {
  ajax({ action: 'get_unread', folder: 'INBOX' }, function (data) {
    if (!data.success) return;
    var badge = document.getElementById('badge-INBOX');
    if (!badge) return;
    if (data.unread > 0) {
      badge.textContent = data.unread;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
  });
}

// ── Folder list fetch (for Move To menu) ──────────────────────────────
function fetchFolders() {
  ajax({ action: 'get_folders' }, function (data) {
    if (data.success && data.folders) {
      state.folders = data.folders;
      renderExtraFolders(data.folders);
    }
  });
}

function folderIcon(name) {
  var n = name.toLowerCase();
  if (n === 'inbox')                                    return '&#128229;'; // inbox tray
  if (n.indexOf('sent') >= 0)                           return '&#128228;'; // outbox
  if (n.indexOf('draft') >= 0)                          return '&#128196;'; // page
  if (n.indexOf('trash') >= 0 || n.indexOf('deleted') >= 0 || n.indexOf('bin') >= 0) return '&#128465;'; // wastebasket
  if (n.indexOf('spam') >= 0  || n.indexOf('junk') >= 0)  return '&#128683;'; // no entry
  if (n.indexOf('archive') >= 0)                        return '&#128450;'; // file cabinet
  return '&#128193;'; // generic folder
}
function folderLabel(name) {
  // Friendly display name — strip "INBOX." prefix if present.
  return name.replace(/^INBOX\./i, '');
}
function renderExtraFolders(folders) {
  var wrap = document.getElementById('sem-all-folders');
  if (!wrap) return;
  var html = '';
  var shownDivider = false;
  // Standard folders in preferred order (case-insensitive match against what server returns).
  var standard = ['inbox', 'sent', 'drafts', 'trash', 'spam', 'junk', 'deleted'];
  var ordered = [], extras = [];
  // Separate standard from non-standard preserving server names.
  folders.forEach(function (f) {
    var lo = f.toLowerCase().replace(/^inbox\./i, '');
    var isStandard = standard.some(function (s) { return lo.indexOf(s) >= 0; });
    if (isStandard) { ordered.push(f); } else { extras.push(f); }
  });
  var all = ordered.concat(extras);
  var firstExtra = ordered.length; // divider position
  all.forEach(function (f, idx) {
    if (idx === firstExtra && extras.length > 0) {
      html += '<div class="sem-folder-divider"></div>';
    }
    var isInbox  = f.toUpperCase() === 'INBOX';
    var active   = isInbox ? ' sem-folder-active' : '';
    var badgeHtml = isInbox ? '<span class="sem-folder-badge" id="badge-INBOX" style="display:none"></span>' : '';
    html += '<div class="sem-folder-item' + active + '" data-folder="' + esc(f) + '" onclick="SEM.loadFolder(\'' + escJs(f) + '\',this)">' +
      '<span class="sem-folder-icon">' + folderIcon(f) + '</span>' +
      '<span class="sem-folder-label">' + esc(folderLabel(f)) + '</span>' +
      badgeHtml +
    '</div>';
  });
  wrap.innerHTML = html || '<div class="sem-folder-loading">No folders found.</div>';
}

// ── Close move menu on outside click ──────────────────────────────────
document.addEventListener('click', function (e) {
  var wrap = document.getElementById('sem-move-wrap');
  var menu = document.getElementById('sem-move-menu');
  if (menu && menu.style.display !== 'none') {
    if (!wrap || !wrap.contains(e.target)) {
      menu.style.display = 'none';
    }
  }
});

// ── Size formatter ─────────────────────────────────────────────────────
function formatSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
  return (bytes / 1048576).toFixed(1) + ' MB';
}

// ── Escape helpers ─────────────────────────────────────────────────────
function esc(s) {
  return String(s || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function escJs(s) {
  return String(s || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// ── Mobile sidebar toggle ──────────────────────────────────────────────
function toggleSidebar() {
  var sb  = document.getElementById('sem-sidebar');
  var ov  = document.getElementById('sem-mob-overlay');
  if (!sb) return;
  var open = sb.classList.contains('sem-sidebar-open');
  if (open) {
    sb.classList.remove('sem-sidebar-open');
    if (ov) { ov.classList.remove('sem-mob-overlay-open'); }
  } else {
    sb.classList.add('sem-sidebar-open');
    if (ov) { ov.classList.add('sem-mob-overlay-open'); }
  }
}
SEM.toggleSidebar = toggleSidebar;

function closeSidebar() {
  var sb = document.getElementById('sem-sidebar');
  var ov = document.getElementById('sem-mob-overlay');
  if (sb) sb.classList.remove('sem-sidebar-open');
  if (ov) ov.classList.remove('sem-mob-overlay-open');
}
SEM.closeSidebar = closeSidebar;

// Close sidebar when a folder is selected on mobile.
var origLoadFolder = SEM.loadFolder;
SEM.loadFolder = function (folder, el, resetPage) {
  closeSidebar();
  return origLoadFolder(folder, el, resetPage);
};

// ── Init ───────────────────────────────────────────────────────────────
updateSigPreview();
fetchFolders();
loadFolder('INBOX', document.querySelector('.sem-folder-item[data-folder="INBOX"]'));
refreshBadge();
// Badge refresh every 10 minutes — minimal IMAP polling to keep server load low.
// The message list itself only reloads on explicit user action (folder click / refresh button).
setInterval(refreshBadge, 600000);

// Keep compose editor placeholder in sync as the user types.
document.getElementById('sem-compose-editor').addEventListener('input', function () {
  syncEditorEmpty(this);
});

})();
</script>

<?php echo $OUTPUT->footer(); ?>
