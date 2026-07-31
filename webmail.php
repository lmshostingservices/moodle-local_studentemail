<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student "My Email" portal page.
 *
 * When a student clicks "My Email" in the Moodle navigation they land here.
 *
 * What this page does:
 *   1. Shows the student their provisioned college email address.
 *   2. Shows their mailbox password (masked, with reveal + copy buttons).
 *   3. Provides a button to open Roundcube webmail in a new tab.
 *
 * WHY WE DO NOT AUTO-POST TO ROUNDCUBE (important architecture note):
 *   Roundcube 1.4+ enforces CSRF token validation on the login endpoint
 *   (/?_task=login). A hidden auto-POST form submitted without a valid
 *   _token value is rejected by Roundcube with a "Request time expired"
 *   error. This is correct security behaviour and cannot be bypassed without
 *   modifying Roundcube's server-side code.
 *
 *   Additionally, cPanel/Roundcube is NOT an OAuth2/OpenID/SAML identity
 *   provider. There is no standard federated login protocol available.
 *
 *   The reliable solution used here: show the student their credentials
 *   on the Moodle page (since the session is already authenticated by Moodle)
 *   and let them paste into Roundcube's own login form.
 *
 *   For IMAP-based Moodle login (so students log INTO Moodle using their
 *   college email credentials): install the separate auth_imap plugin and
 *   point it at the cPanel server. This is a separate concern.
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
$PAGE->set_url(new moodle_url('/local/studentemail/webmail.php'));
$PAGE->set_title(get_string('webmail_title', 'local_studentemail'));
$PAGE->set_heading(get_string('webmail_title', 'local_studentemail'));
$PAGE->set_pagelayout('standard');

$webmail_url = get_config('local_studentemail', 'webmail_url');
if (empty($webmail_url)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('webmail_notconfigured', 'local_studentemail'), 'error');
    echo $OUTPUT->footer();
    exit;
}

$manager = new email_manager();
$account = $manager->get_account_for_user($USER->id);

echo $OUTPUT->header();
?>
<style>
.sem-portal {
    max-width: 520px;
    margin: 3rem auto;
    padding: 0 1rem;
    text-align: center;
}
.sem-portal-icon {
    width: 72px;
    height: 72px;
    background: #e8f0fe;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2rem;
}
.sem-portal h2 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 .4rem;
    color: #1e293b;
}
.sem-portal-sub {
    color: #64748b;
    font-size: .9rem;
    margin: 0 0 2rem;
}
.sem-cred-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: left;
    margin-bottom: 1.5rem;
}
.sem-cred-row {
    margin-bottom: 1.2rem;
}
.sem-cred-row:last-child {
    margin-bottom: 0;
}
.sem-cred-label {
    font-size: .75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #94a3b8;
    margin-bottom: .35rem;
}
.sem-cred-value {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    padding: .6rem .9rem;
    font-family: 'Courier New', Courier, monospace;
    font-size: .95rem;
    color: #1e293b;
    word-break: break-all;
}
.sem-cred-text {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sem-copy-btn {
    flex-shrink: 0;
    background: none;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    padding: .25rem .5rem;
    cursor: pointer;
    font-size: .75rem;
    color: #64748b;
    transition: background .15s, color .15s;
}
.sem-copy-btn:hover {
    background: #e2e8f0;
    color: #334155;
}
.sem-copy-btn.copied {
    background: #dcfce7;
    border-color: #86efac;
    color: #15803d;
}
.sem-reveal-btn {
    flex-shrink: 0;
    background: none;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    padding: .25rem .5rem;
    cursor: pointer;
    font-size: .75rem;
    color: #64748b;
    transition: background .15s;
}
.sem-reveal-btn:hover {
    background: #e2e8f0;
}
.sem-open-btn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    background: #0066cc;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: .75rem 1.75rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s;
    width: 100%;
    justify-content: center;
    box-sizing: border-box;
}
.sem-open-btn:hover {
    background: #0052a3;
    color: #fff;
    text-decoration: none;
}
.sem-hint {
    margin-top: 1rem;
    font-size: .8rem;
    color: #94a3b8;
    line-height: 1.5;
}
.sem-alert-box {
    padding: 1.2rem;
    border-radius: 8px;
    margin-top: 1rem;
    font-size: .9rem;
}
.sem-alert-suspended {
    background: #ede9fe;
    border: 1px solid #c4b5fd;
    color: #6d28d9;
}
.sem-alert-archived {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #475569;
}
.sem-alert-none {
    background: #fef3c7;
    border: 1px solid #fde68a;
    color: #92400e;
}
</style>

<div class="sem-portal">
  <div class="sem-portal-icon">&#9993;</div>

<?php if (!$account): ?>
  <h2><?php echo get_string('webmail_title', 'local_studentemail'); ?></h2>
  <div class="sem-alert-box sem-alert-none"><?php echo get_string('webmail_noaccount', 'local_studentemail'); ?></div>

<?php elseif ($account['status'] === 'suspended'): ?>
  <h2><?php echo get_string('webmail_title', 'local_studentemail'); ?></h2>
  <div class="sem-alert-box sem-alert-suspended"><?php echo get_string('webmail_suspended', 'local_studentemail'); ?></div>

<?php elseif ($account['status'] === 'archived'): ?>
  <h2><?php echo get_string('webmail_title', 'local_studentemail'); ?></h2>
  <div class="sem-alert-box sem-alert-archived"><?php echo get_string('webmail_archived', 'local_studentemail'); ?></div>

<?php else:
  $email    = $account['email'];
  $password = $account['password'];
  $webmail  = rtrim($webmail_url, '/') . '/';
?>
  <h2><?php echo get_string('webmail_title', 'local_studentemail'); ?></h2>
  <p class="sem-portal-sub">Use these credentials to log in to your college email inbox.</p>

  <div class="sem-cred-card">
    <div class="sem-cred-row">
      <div class="sem-cred-label">Email address</div>
      <div class="sem-cred-value">
        <span class="sem-cred-text" id="email-text"><?php echo s($email); ?></span>
        <button class="sem-copy-btn" onclick="copyText('email-text', this)" title="Copy email">Copy</button>
      </div>
    </div>
    <div class="sem-cred-row">
      <div class="sem-cred-label">Password</div>
      <div class="sem-cred-value">
        <span class="sem-cred-text" id="pass-text" data-real="<?php echo s($password); ?>" style="letter-spacing:.15em;">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
        <button class="sem-reveal-btn" id="reveal-btn" onclick="toggleReveal()" title="Show/hide password">Show</button>
        <button class="sem-copy-btn" onclick="copyReal('pass-text', this)" title="Copy password">Copy</button>
      </div>
    </div>
  </div>

  <a href="<?php echo s($webmail); ?>" target="_blank" class="sem-open-btn">
    &#128274;&nbsp; Open Webmail &rarr;
  </a>

  <p class="sem-hint">
    A new tab will open with the Roundcube webmail login page.<br>
    Enter the email address and password shown above to sign in.
  </p>

  <script>
  (function() {
    var revealed = false;

    window.toggleReveal = function() {
      var el  = document.getElementById('pass-text');
      var btn = document.getElementById('reveal-btn');
      revealed = !revealed;
      if (revealed) {
        el.textContent = el.dataset.real;
        el.style.letterSpacing = 'normal';
        btn.textContent = 'Hide';
      } else {
        el.textContent = '\u2022'.repeat(12);
        el.style.letterSpacing = '.15em';
        btn.textContent = 'Show';
      }
    };

    window.copyText = function(id, btn) {
      var text = document.getElementById(id).textContent;
      navigator.clipboard.writeText(text).then(function() {
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(function() { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
      }).catch(function() {
        fallbackCopy(text);
      });
    };

    window.copyReal = function(id, btn) {
      var text = document.getElementById(id).dataset.real;
      navigator.clipboard.writeText(text).then(function() {
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(function() { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
      }).catch(function() {
        fallbackCopy(text);
      });
    };

    function fallbackCopy(text) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
  })();
  </script>
<?php endif; ?>
</div>

<?php echo $OUTPUT->footer(); ?>
