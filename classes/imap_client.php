<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * IMAP/SMTP client for the Student Email mailbox page.
 *
 * Wraps PHP's built-in imap_* functions and Moodle's bundled PHPMailer.
 * Reads IMAP settings from auth_studentemail config and SMTP settings
 * from local_studentemail config.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_studentemail;

defined('MOODLE_INTERNAL') || die();

class imap_client {
    /** @var string Full IMAP mailbox spec e.g. {mail.host:993/imap/ssl}INBOX */
    private string $mailbox_spec;
    /** @var string Email address used as username */
    private string $username;
    /** @var string Plaintext mailbox password */
    private string $password;
    /** @var resource|false IMAP stream */
    private $stream = false;
    /** @var string Accumulated PHPMailer SMTP debug output for the last send_message() call */
    private string $smtp_debug_log = '';
    /** @var string Raw MIME source of the last successfully sent message (for the Sent append) */
    private string $last_sent_mime = '';
    /** @var string Diagnostic notes from the last Sent/Drafts IMAP append */
    private string $append_log = '';
    /** @var array Resolved special-folder names, keyed by type ('sent'|'drafts') */
    private array $special_folder_cache = [];
    /** @var string Folder the last Sent/Drafts append targeted */
    private string $last_append_folder = '';

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(string $email, string $password) {
        $this->username = $email;
        $this->password = $password;
        $this->mailbox_spec = $this->build_mailbox_spec('INBOX');
    }

    /**
     * Build an IMAP mailbox connection string for the given folder.
     */
    private function build_mailbox_spec(string $folder = 'INBOX'): string {
        $host       = trim(get_config('auth_studentemail', 'imap_host') ?? '');
        // Fall back to local_studentemail imap settings if auth plugin not installed.
        if (empty($host)) {
            $host = trim(get_config('local_studentemail', 'imap_host') ?? '');
        }
        // Strip comma-separated failover hosts — just use first.
        if (strpos($host, ',') !== false) {
            $parts = explode(',', $host);
            $host  = trim($parts[0]);
        }

        // Prefer auth_studentemail config; fall back to local_studentemail settings.
        $port_raw   = get_config('auth_studentemail', 'imap_port');
        $port       = (int)((!empty($port_raw) ? $port_raw : null) ?? get_config('local_studentemail', 'imap_port') ?? 993);
        $type_raw   = get_config('auth_studentemail', 'imap_type');
        $type       = (!empty($type_raw) ? $type_raw : null) ?: (get_config('local_studentemail', 'imap_type') ?: 'imap');
        $enc_raw    = get_config('auth_studentemail', 'imap_encryption');
        $encryption = (!empty($enc_raw) ? $enc_raw : null) ?: (get_config('local_studentemail', 'imap_encryption') ?: 'ssl');
        $novalidate = !empty(get_config('auth_studentemail', 'imap_novalidate')) || !empty(get_config('local_studentemail', 'imap_novalidate'));
        $strip      = !empty(get_config('auth_studentemail', 'strip_domain')) || !empty(get_config('local_studentemail', 'imap_strip_domain'));

        $flags = '/' . $type;
        switch ($encryption) {
            case 'ssl':
                $flags .= '/ssl';
                break;
            case 'tls':
                $flags .= '/tls';
                break;
        }
        if ($novalidate) {
            $flags .= '/novalidate-cert';
        }

        // For IMAP login most cPanel servers want the bare username (strip @domain).
        $this->username = $strip ? preg_replace('/@.+$/', '', $this->username) : $this->username;

        // Defensive: a folder name that already carries a {...} specification
        // (as imap_list() returns) must never be specified a second time.
        $folder = $this->strip_mailbox_spec($folder);

        return '{' . $host . ':' . $port . $flags . '}' . $folder;
    }

    /**
     * Remove a leading IMAP mailbox specification from a folder name.
     *
     * '{mail.host:993/imap/ssl}INBOX.Sent' becomes 'INBOX.Sent'.
     *
     * @param  string $folder
     * @return string
     */
    private function strip_mailbox_spec(string $folder): string {
        return trim(preg_replace('/^\{[^}]*\}/', '', trim($folder)));
    }

    // -------------------------------------------------------------------------
    // Connection management
    // -------------------------------------------------------------------------

    /**
     * Open (or reuse) an IMAP connection to the given folder.
     *
     * @throws \moodle_exception on failure
     */
    public function connect(string $folder = 'INBOX'): void {
        if (!function_exists('imap_open')) {
            throw new \moodle_exception('error_noimap', 'local_studentemail');
        }

        $spec = $this->build_mailbox_spec($folder);

        // Bound every IMAP wait so a slow or unreachable mail server can never
        // hold a PHP worker until max_execution_time kills it mid-response.
        if (function_exists('imap_timeout')) {
            @imap_timeout(IMAP_OPENTIMEOUT, 15);
            @imap_timeout(IMAP_READTIMEOUT, 15);
            @imap_timeout(IMAP_WRITETIMEOUT, 15);
            @imap_timeout(IMAP_CLOSETIMEOUT, 15);
        }

        // Open with 2 retries and the 15-second timeouts set above.
        $stream = @imap_open(
            $spec,
            $this->username,
            $this->password,
            0,
            2,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
        );

        if (!$stream) {
            $err = imap_last_error() ?: 'IMAP connection failed';
            throw new \moodle_exception('error_imap_connect', 'local_studentemail', '', $err);
        }
        if ($this->stream) {
            @imap_close($this->stream);
        }
        $this->stream = $stream;
    }

    public function close(): void {
        if ($this->stream) {
            @imap_close($this->stream, CL_EXPUNGE);
            $this->stream = false;
        }
    }

    // -------------------------------------------------------------------------
    // Folder list
    // -------------------------------------------------------------------------

    /**
     * Return an array of folder names the student can access.
     */
    public function list_folders(): array {
        $this->connect('INBOX');
        $server = '{' . $this->get_host_spec() . '}';
        $raw    = @imap_list($this->stream, $server, '*');
        if (!$raw) {
            return ['INBOX'];
        }

        $folders = [];
        foreach ($raw as $f) {
            // The imap_list() call returns the full mailbox spec, e.g.
            // {mail.host:993/imap/ssl}INBOX.Sent — and the flags it carries do
            // not always match $server. Strip whatever specification prefix is
            // present so only the folder name remains; a stale prefix would be
            // fed straight back into build_mailbox_spec() and produce a
            // double-specified mailbox that no IMAP command can address.
            $name = $this->strip_mailbox_spec(str_replace($server, '', $f));
            // Decode modified UTF-7.
            if (function_exists('mb_convert_encoding')) {
                $name = mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
            }
            if ($name !== '') {
                $folders[] = $name;
            }
        }

        // Sort: INBOX first, then alphabetical.
        usort($folders, function ($a, $b) {
            if (strtoupper($a) === 'INBOX') return -1;
            if (strtoupper($b) === 'INBOX') return 1;
            return strcmp($a, $b);
        });

        return $folders;
    }

    // -------------------------------------------------------------------------
    // Message list
    // -------------------------------------------------------------------------

    /**
     * Get an array of message summaries for the given folder.
     *
     * @param  string $folder   IMAP folder name
     * @param  int    $limit    Max messages to return (most recent first)
     * @param  int    $offset   Pagination offset
     * @return array
     */
    public function get_messages(string $folder = 'INBOX', int $limit = 50, int $offset = 0): array {
        $this->connect($folder);

        $total = imap_num_msg($this->stream);
        if ($total === 0) {
            return ['messages' => [], 'total' => 0];
        }

        // Build sequence from newest to oldest.
        $start = max(1, $total - $offset - $limit + 1);
        $end   = max(1, $total - $offset);
        if ($start > $end) {
            return ['messages' => [], 'total' => $total];
        }

        $sequence = $start . ':' . $end;
        $overviews = @imap_fetch_overview($this->stream, $sequence, 0);
        if (!$overviews) {
            return ['messages' => [], 'total' => $total];
        }

        // Reverse so newest is first.
        $overviews = array_reverse($overviews);

        $messages = [];
        foreach ($overviews as $ov) {
            $messages[] = [
                'msgno'    => (int)$ov->msgno,
                'uid'      => (int)$ov->uid,
                'subject'  => $this->decode_header($ov->subject ?? '(no subject)'),
                'from'     => $this->decode_header($ov->from ?? ''),
                'date'     => $this->format_date($ov->date ?? ''),
                'date_raw' => $ov->date ?? '',
                'seen'     => !empty($ov->seen),
                'flagged'  => !empty($ov->flagged),
                'answered' => !empty($ov->answered),
                'size'     => (int)($ov->size ?? 0),
            ];
        }

        return ['messages' => $messages, 'total' => $total];
    }

    // -------------------------------------------------------------------------
    // Full message fetch
    // -------------------------------------------------------------------------

    /**
     * Fetch a single message body + headers by message number.
     *
     * @param  int    $msgno  Message sequence number
     * @param  string $folder IMAP folder
     * @return array
     */
    public function get_message(int $msgno, string $folder = 'INBOX'): array {
        $this->connect($folder);

        $header = @imap_headerinfo($this->stream, $msgno);
        if (!$header) {
            throw new \moodle_exception('error_message_notfound', 'local_studentemail');
        }

        $structure = @imap_fetchstructure($this->stream, $msgno);
        [$body_html, $body_plain, $attachments] = $this->extract_body($msgno, $structure);

        // Mark as read.
        @imap_setflag_full($this->stream, (string)$msgno, '\\Seen');

        $from_addr = '';
        $from_name = '';
        if (!empty($header->from)) {
            $f = $header->from[0];
            $from_addr = $f->mailbox . '@' . $f->host;
            $from_name = !empty($f->personal)
                ? $this->decode_header($f->personal) : $from_addr;
        }

        $to_list = [];
        if (!empty($header->to)) {
            foreach ($header->to as $t) {
                $to_list[] = (!empty($t->personal) ? $this->decode_header($t->personal) . ' ' : '')
                    . '<' . $t->mailbox . '@' . $t->host . '>';
            }
        }

        // CC recipients.
        $cc_list = [];
        if (!empty($header->cc)) {
            foreach ($header->cc as $c) {
                if (!empty($c->mailbox) && !empty($c->host)) {
                    $cc_list[] = (!empty($c->personal) ? $this->decode_header($c->personal) . ' ' : '')
                        . '<' . $c->mailbox . '@' . $c->host . '>';
                }
            }
        }

        return [
            'msgno'       => $msgno,
            'uid'         => imap_uid($this->stream, $msgno),
            'subject'     => $this->decode_header($header->subject ?? '(no subject)'),
            'from_name'   => $from_name,
            'from_email'  => $from_addr,
            'to'          => implode(', ', $to_list),
            'cc'          => implode(', ', $cc_list),
            'date'        => $this->format_date($header->date ?? ''),
            'date_raw'    => $header->date ?? '',
            'body_html'   => $body_html ? $this->sanitise_html($body_html) : '',
            'body_plain'  => $body_plain,
            'attachments' => $attachments,
        ];
    }

    // -------------------------------------------------------------------------
    // Unread count
    // -------------------------------------------------------------------------

    public function get_unread_count(string $folder = 'INBOX'): int {
        $this->connect($folder);
        $check = @imap_mailboxmsginfo($this->stream);
        return $check ? (int)$check->Unread : 0;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function mark_read(int $msgno, bool $read = true): void {
        // Caller is responsible for connecting to the correct folder first.
        // Do NOT call $this->connect() here — it would reset the stream to INBOX
        // and break mark-read on non-INBOX folders.
        if ($read) {
            @imap_setflag_full($this->stream, (string)$msgno, '\\Seen');
        } else {
            @imap_clearflag_full($this->stream, (string)$msgno, '\\Seen');
        }
    }

    public function delete_message(int $msgno, string $folder = 'INBOX'): void {
        $this->connect($folder);
        @imap_delete($this->stream, (string)$msgno);
        @imap_expunge($this->stream);
    }

    public function toggle_flag(int $msgno, string $folder, bool $flagged): void {
        $this->connect($folder);
        if ($flagged) {
            @imap_setflag_full($this->stream, (string)$msgno, '\\Flagged');
        } else {
            @imap_clearflag_full($this->stream, (string)$msgno, '\\Flagged');
        }
    }

    // -------------------------------------------------------------------------
    // Server-side search (IMAP SEARCH)
    // -------------------------------------------------------------------------

    /**
     * Search messages in a folder using IMAP SEARCH.
     *
     * Searches subject, from, and body text.
     *
     * @param  string $folder
     * @param  string $query  Search term (plain text)
     * @param  int    $limit
     * @param  int    $offset
     * @return array  ['messages' => [...], 'total' => N]
     */
    public function search_messages(string $folder, string $query, int $limit = 50, int $offset = 0): array {
        $this->connect($folder);

        $q = addcslashes(trim($query), '"\\');
        // Build: OR OR SUBJECT "q" FROM "q" TEXT "q"
        $criteria = 'OR OR SUBJECT "' . $q . '" FROM "' . $q . '" TEXT "' . $q . '"';

        $uids = @imap_search($this->stream, $criteria, SE_UID);
        if (!$uids) {
            return ['messages' => [], 'total' => 0];
        }

        // Newest first.
        $uids  = array_reverse($uids);
        $total = count($uids);
        $slice = array_slice($uids, $offset, $limit);

        $messages = [];
        foreach ($slice as $uid) {
            $msgno = @imap_msgno($this->stream, $uid);
            if (!$msgno) continue;
            $ov = @imap_fetch_overview($this->stream, (string)$msgno, 0);
            if (!$ov) continue;
            $o = $ov[0];
            $messages[] = [
                'msgno'    => (int)$o->msgno,
                'uid'      => (int)$o->uid,
                'subject'  => $this->decode_header($o->subject ?? '(no subject)'),
                'from'     => $this->decode_header($o->from ?? ''),
                'date'     => $this->format_date($o->date ?? ''),
                'date_raw' => $o->date ?? '',
                'seen'     => !empty($o->seen),
                'flagged'  => !empty($o->flagged),
                'answered' => !empty($o->answered),
                'size'     => (int)($o->size ?? 0),
            ];
        }

        return ['messages' => $messages, 'total' => $total];
    }

    // -------------------------------------------------------------------------
    // Attachment fetch
    // -------------------------------------------------------------------------

    /**
     * Fetch a single attachment part and return it base64-encoded.
     *
     * @param  int    $msgno   Message sequence number
     * @param  string $partnum MIME part number (e.g. "2" or "1.2")
     * @param  string $folder
     * @return array  ['filename', 'mime', 'size', 'data' (base64)]
     * @throws \moodle_exception if attachment exceeds 20 MB
     */
    public function get_attachment(int $msgno, string $partnum, string $folder = 'INBOX'): array {
        $this->connect($folder);

        $structure = @imap_fetchstructure($this->stream, $msgno);
        $part_info = $this->find_part_by_num($structure, $partnum);

        $raw  = @imap_fetchbody($this->stream, $msgno, $partnum);
        $data = $this->decode_part($raw, $part_info ? ($part_info->encoding ?? ENCBASE64) : ENCBASE64);

        // Enforce 20 MB download limit.
        if (strlen($data) > 20 * 1024 * 1024) {
            throw new \moodle_exception('error_attachment_toolarge', 'local_studentemail');
        }

        $filename = '';
        $mime     = 'application/octet-stream';

        if ($part_info) {
            $type_map = [
                TYPETEXT        => 'text',
                TYPEMULTIPART   => 'multipart',
                TYPEMESSAGE     => 'message',
                TYPEAPPLICATION => 'application',
                TYPEAUDIO       => 'audio',
                TYPEVIDEO       => 'video',
                TYPEIMAGE       => 'image',
                TYPEOTHER       => 'application',
            ];
            $type_str = $type_map[$part_info->type ?? TYPEOTHER] ?? 'application';
            $subtype  = strtolower($part_info->subtype ?? 'octet-stream');
            $mime     = $type_str . '/' . $subtype;

            if (!empty($part_info->dparameters)) {
                foreach ($part_info->dparameters as $dp) {
                    if (strtolower($dp->attribute) === 'filename') {
                        $filename = $this->decode_header($dp->value);
                    }
                }
            }
            if (empty($filename) && !empty($part_info->parameters)) {
                foreach ($part_info->parameters as $p) {
                    if (strtolower($p->attribute) === 'name') {
                        $filename = $this->decode_header($p->value);
                    }
                }
            }
        }

        return [
            'filename' => $filename ?: 'attachment',
            'mime'     => $mime,
            'size'     => strlen($data),
            'data'     => base64_encode($data),
        ];
    }

    /**
     * Walk the message structure to find a part by its dotted part number.
     */
    private function find_part_by_num(object $structure, string $partnum): ?object {
        $parts = explode('.', $partnum);
        $current = $structure;
        foreach ($parts as $i => $idx) {
            $idx = (int)$idx - 1;
            if ($i === 0 && empty($current->parts)) {
                // Single-part message — part "1" refers to the whole structure.
                return $current;
            }
            if (!isset($current->parts[$idx])) {
                return null;
            }
            $current = $current->parts[$idx];
        }
        return $current;
    }

    public function move_message(int $msgno, string $from_folder, string $to_folder): void {
        $this->connect($from_folder);
        $server  = '{' . $this->get_host_spec() . '}';
        @imap_mail_move($this->stream, (string)$msgno, $server . $to_folder);
        @imap_expunge($this->stream);
    }

    // -------------------------------------------------------------------------
    // Send via SMTP (Moodle bundled PHPMailer)
    // -------------------------------------------------------------------------

    /**
     * Send an email on behalf of the student via SMTP.
     *
     * @param  string $from_email Student's college email address
     * @param  string $from_name  Student's display name
     * @param  string $to         Recipient address(es), comma-separated
     * @param  string $subject
     * @param  string $body_html  HTML body (will auto-generate plain text)
     * @throws \moodle_exception on SMTP failure
     */
    public function send_message(
        string $from_email,
        string $from_name,
        string $to,
        string $subject,
        string $body_html,
        string $cc = '',
        array $attachments = []
    ): void {
        global $CFG;

        require_once($CFG->dirroot . '/lib/phpmailer/moodle_phpmailer.php');

        $smtp_host = trim(get_config('local_studentemail', 'smtp_host') ?? '');
        $smtp_port = (int)(get_config('local_studentemail', 'smtp_port') ?? 587);
        $smtp_enc  = get_config('local_studentemail', 'smtp_encryption') ?: 'tls';

        if (empty($smtp_host)) {
            throw new \moodle_exception('error_nosmtp', 'local_studentemail');
        }

        // Reset SMTP debug log for this send attempt.
        $this->smtp_debug_log = '';
        $dbg = function (string $line): void {
            $this->smtp_debug_log .= '[' . date('H:i:s') . '] ' . $line . "\n";
        };

        $dbg("STEP 1 - send_message entered — host={$smtp_host}:{$smtp_port} enc={$smtp_enc} from={$from_email} user={$this->username}");

        $mail = new \moodle_phpmailer();
        $dbg('STEP 2 - moodle_phpmailer created');

        // Capture the full SMTP conversation so it can be returned in the JSON
        // response and logged to the browser console via semDebug().
        $mail->SMTPDebug   = 3;
        $mail->Debugoutput = function (string $str, int $level) use ($dbg): void {
            $dbg('SMTP> ' . trim($str));
        };
        $mail->Timeout       = 5;
        $mail->SMTPKeepAlive = false;

        $mail->isSMTP();
        $dbg('STEP 3 - isSMTP() called');

        $mail->Host = $smtp_host;
        $dbg("STEP 4 - Host set: {$smtp_host}");

        $mail->SMTPAuth = true;
        $mail->Username = $this->username;
        $dbg("STEP 5 - Username set: {$this->username}");

        $mail->Password = $this->password;
        $dbg('STEP 6 - Password set (length=' . strlen($this->password) . ')');

        $mail->Port = $smtp_port;

        switch ($smtp_enc) {
            case 'ssl':
                $mail->SMTPSecure = 'ssl';
                break;
            case 'tls':
                $mail->SMTPSecure = 'tls';
                break;
            default:
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($from_email, $from_name);

        // Parse comma-separated recipients.
        foreach (explode(',', $to) as $addr) {
            $addr = trim($addr);
            if (!empty($addr)) {
                $mail->addAddress($addr);
            }
        }

        // CC recipients.
        if (!empty($cc)) {
            foreach (explode(',', $cc) as $addr) {
                $addr = trim($addr);
                if (!empty($addr)) {
                    $mail->addCC($addr);
                }
            }
        }

        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = $body_html;
        $mail->AltBody  = strip_tags($body_html);
        $mail->CharSet  = 'UTF-8';

        // Add file attachments.
        foreach ($attachments as $att) {
            $mail->addAttachment($att['tmp'], $att['name'], 'base64', $att['mime']);
        }

        // Always disable SSL cert verification for SMTP.
        // cPanel shared-hosting servers present a cert for their real hostname
        // (e.g. syn04ce.syd5.hostyourservices.net) while being accessed via a
        // custom domain (e.g. webmail.aioplms.com.au). PHP OpenSSL rejects the
        // hostname mismatch, causing "SMTP connect() failed" with garbled TLS
        // bytes in the error detail. This is standard practice for cPanel SMTP.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
        $dbg('STEP 6b - SMTPOptions: SSL cert verification disabled (cPanel hostname-mismatch bypass)');

        $dbg('STEP 7 - About to call $mail->send()');
        try {
            $send_result = $mail->send();
        } catch (\Throwable $phpmailer_ex) {
            // PHPMailer throws when $mail->exceptions = true.
            // Keep message ASCII-safe — SMTP log is returned separately via get_smtp_debug_log().
            $dbg('STEP 7b - PHPMailer threw: ' . $phpmailer_ex->getMessage());
            throw new \Exception(
                'PHPMailer exception: ' . $phpmailer_ex->getMessage()
                . ' | ErrorInfo: ' . $mail->ErrorInfo,
                0, $phpmailer_ex
            );
        }
        $dbg('STEP 8 - $mail->send() returned: ' . ($send_result ? 'TRUE (delivered)' : 'FALSE — ErrorInfo: [' . $mail->ErrorInfo . ']'));
        if (!$send_result) {
            // Keep message ASCII-safe — smtp_log in the JSON response carries the full conversation.
            throw new \Exception(
                'SMTP send() returned false.'
                . ' ErrorInfo: [' . $mail->ErrorInfo . ']'
                . ' | Host: ' . $mail->Host
                . ' | Port: ' . $mail->Port
                . ' | SMTPAuth: ' . ($mail->SMTPAuth ? 'yes' : 'no')
            );
        }

        // Capture the exact MIME source that went out over SMTP so the caller can
        // append it to the cPanel mailbox's Sent folder AFTER the HTTP response has
        // been flushed (see append_sent_copy()). Capturing is cheap and cannot hang;
        // only the IMAP append itself is deferred.
        $this->last_sent_mime = '';
        if (method_exists($mail, 'getSentMIMEMessage')) {
            try {
                $this->last_sent_mime = (string)$mail->getSentMIMEMessage();
                $dbg('STEP 9 - captured sent MIME (' . strlen($this->last_sent_mime) . ' bytes) for Sent-folder append');
            } catch (\Throwable $e) {
                $dbg('STEP 9 - could not capture sent MIME: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Sent / Drafts folder support (real cPanel IMAP folders)
    // -------------------------------------------------------------------------

    /**
     * Diagnostic notes from the last Sent/Drafts append attempt.
     */
    public function get_append_log(): string {
        return $this->append_log;
    }

    /**
     * The folder the last Sent/Drafts append targeted.
     *
     * @return string
     */
    public function get_last_append_folder(): string {
        return $this->last_append_folder;
    }

    /**
     * True when send_message() captured a MIME source that can be filed in Sent.
     */
    public function has_sent_copy(): bool {
        return $this->last_sent_mime !== '';
    }

    /**
     * Append the last sent message to the mailbox's Sent folder.
     *
     * MUST be called after the JSON response has been flushed to the browser —
     * the student's send must never fail because the IMAP append was slow.
     *
     * @return bool true when the copy was filed.
     */
    public function append_sent_copy(): bool {
        if ($this->last_sent_mime === '') {
            $this->append_log .= "no captured MIME — nothing to file in Sent\n";
            return false;
        }
        $folder = $this->find_special_folder('sent');
        if ($folder === '') {
            $this->append_log .= "no Sent folder available\n";
            return false;
        }
        $this->last_append_folder = $folder;
        return $this->append_raw($folder, $this->last_sent_mime, "\\Seen");
    }

    /**
     * Save a draft into the mailbox's Drafts folder.
     *
     * Any earlier revision of the same draft (matched on the X-SEM-Draft-Id
     * header) is removed first, so the folder holds one entry per draft rather
     * than one per autosave tick.
     *
     * @return array ['folder' => string, 'replaced' => int]
     */
    public function save_draft(
        string $from_email,
        string $from_name,
        string $to,
        string $subject,
        string $body_html,
        string $cc = '',
        string $draftid = ''
    ): array {
        $folder = $this->find_special_folder('drafts');
        if ($folder === '') {
            throw new \moodle_exception('draft_nodraftsfolder', 'local_studentemail');
        }

        $replaced = 0;
        if ($draftid !== '') {
            $replaced = $this->delete_drafts_by_id($draftid, $folder);
        }

        $raw = $this->build_draft_mime($from_email, $from_name, $to, $subject, $body_html, $cc, $draftid);
        $this->last_append_folder = $folder;
        $ok  = $this->append_raw($folder, $raw, "\\Draft \\Seen");
        if (!$ok) {
            throw new \moodle_exception('draft_notsaved', 'local_studentemail', '',
                $this->append_log !== '' ? $this->append_log : imap_last_error());
        }
        return ['folder' => $folder, 'replaced' => $replaced];
    }

    /**
     * Delete every message in Drafts carrying the given X-SEM-Draft-Id.
     *
     * @return int number of drafts removed
     */
    public function delete_drafts_by_id(string $draftid, string $folder = ''): int {
        if ($draftid === '') {
            return 0;
        }
        if ($folder === '') {
            $folder = $this->find_special_folder('drafts', false);
        }
        if ($folder === '') {
            return 0;
        }
        try {
            $this->connect($folder);
        } catch (\Throwable $e) {
            $this->append_log .= 'could not open ' . $folder . ' to clear old drafts: ' . $e->getMessage() . "\n";
            return 0;
        }
        // Draft ids are generated by this plugin (hex only) — safe inside the search string.
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $draftid);
        $hits = @imap_search($this->stream, 'HEADER X-SEM-Draft-Id "' . $safe . '"');
        if (!$hits) {
            return 0;
        }
        foreach ($hits as $msgno) {
            @imap_delete($this->stream, (string)$msgno);
        }
        @imap_expunge($this->stream);
        return count($hits);
    }

    /**
     * Resolve the server's Sent or Drafts folder, creating it when missing.
     *
     * cPanel/Dovecot servers name these Sent/Drafts or INBOX.Sent/INBOX.Drafts
     * depending on the namespace separator, so the name is discovered rather
     * than assumed.
     *
     * @param  string $type   'sent' or 'drafts'
     * @param  bool   $create create the folder when the server has none
     * @return string folder name, or '' when unavailable
     */
    public function find_special_folder(string $type, bool $create = true): string {
        $type = ($type === 'drafts') ? 'drafts' : 'sent';
        if (isset($this->special_folder_cache[$type])) {
            return $this->special_folder_cache[$type];
        }

        $needle     = ($type === 'drafts') ? 'draft' : 'sent';
        $candidates = ($type === 'drafts')
            ? ['Drafts', 'INBOX.Drafts', 'INBOX/Drafts', 'Draft', 'INBOX.Draft']
            : ['Sent', 'INBOX.Sent', 'INBOX/Sent', 'Sent Items', 'INBOX.Sent Items',
               'Sent Messages', 'INBOX.Sent Messages'];

        $names = [];
        try {
            $names = $this->list_folders();
        } catch (\Throwable $e) {
            $this->append_log .= 'folder list failed: ' . $e->getMessage() . "\n";
        }
        $this->append_log .= 'folders seen: ' . implode(', ', $names) . "\n";

        // 1. Exact match against the names the server reported.
        foreach ($candidates as $c) {
            foreach ($names as $n) {
                if (strcasecmp($n, $c) === 0 && $this->folder_exists($n)) {
                    return $this->cache_folder($type, $n);
                }
            }
        }

        // 2. Any reported folder whose leaf name contains "sent" / "draft".
        foreach ($names as $n) {
            $leaf = preg_replace('/^INBOX[.\/]/i', '', $n);
            if (stripos($leaf, $needle) !== false && $this->folder_exists($n)) {
                return $this->cache_folder($type, $n);
            }
        }

        // 3. The server list may be unusable (imap_list can fail or return
        //    unparseable names) — probe the conventional names directly.
        foreach ($candidates as $c) {
            if ($this->folder_exists($c)) {
                return $this->cache_folder($type, $c);
            }
        }

        if (!$create) {
            return '';
        }

        // 4. Create it, matching the server's namespace prefix when it uses one.
        $prefix = '';
        foreach ($names as $n) {
            if (preg_match('/^INBOX([.\/])/i', $n, $m)) {
                $prefix = 'INBOX' . $m[1];
                break;
            }
        }
        $target = $prefix . ($type === 'drafts' ? 'Drafts' : 'Sent');
        try {
            $this->ensure_stream();
            $spec = $this->build_mailbox_spec($target);
            $made = @imap_createmailbox($this->stream, $spec);
            @imap_subscribe($this->stream, $spec);
            $this->append_log .= 'create "' . $target . '": ' . ($made ? 'OK' : 'FAILED ' . $this->imap_error()) . "\n";
            if (!$made && !$this->folder_exists($target)) {
                return '';
            }
        } catch (\Throwable $e) {
            $this->append_log .= 'could not create ' . $target . ': ' . $e->getMessage() . "\n";
            return '';
        }

        return $this->cache_folder($type, $target);
    }

    /**
     * Remember and return a resolved special-folder name.
     *
     * @param  string $type   'sent' or 'drafts'
     * @param  string $folder Resolved folder name
     * @return string
     */
    private function cache_folder(string $type, string $folder): string {
        $this->special_folder_cache[$type] = $folder;
        $this->append_log .= 'resolved ' . $type . ' folder: "' . $folder . "\"\n";
        return $folder;
    }

    /**
     * Check that a folder can actually be addressed on the server.
     *
     * @param  string $folder
     * @return bool
     */
    private function folder_exists(string $folder): bool {
        if ($folder === '') {
            return false;
        }
        try {
            $this->ensure_stream();
        } catch (\Throwable $e) {
            return false;
        }
        $status = @imap_status($this->stream, $this->build_mailbox_spec($folder), SA_MESSAGES);
        return $status !== false;
    }

    /**
     * Return the most recent IMAP error text, or a placeholder.
     *
     * @return string
     */
    private function imap_error(): string {
        $errors = imap_errors();
        if (!empty($errors)) {
            return implode(' | ', array_slice($errors, -3));
        }
        return imap_last_error() ?: 'unknown IMAP error';
    }

    /**
     * Open a stream if none is currently open (defaults to INBOX).
     */
    private function ensure_stream(): void {
        if (!$this->stream) {
            $this->connect('INBOX');
        }
    }

    /**
     * Append a raw RFC-822 message to a folder on the server.
     */
    private function append_raw(string $folder, string $raw, string $flags = ''): bool {
        try {
            $this->ensure_stream();
        } catch (\Throwable $e) {
            $this->append_log .= 'append connect failed: ' . $e->getMessage() . "\n";
            return false;
        }
        // IMAP APPEND requires CRLF line endings.
        $raw  = preg_replace("/\r\n|\r|\n/", "\r\n", $raw);
        $spec = $this->build_mailbox_spec($folder);
        $ok   = @imap_append($this->stream, $spec, $raw, $flags);
        $this->append_log .= 'append ' . strlen($raw) . ' bytes to "' . $folder . '" (' . $spec . '): '
            . ($ok ? 'OK' : 'FAILED ' . $this->imap_error()) . "\n";
        return (bool)$ok;
    }

    /**
     * Build a simple RFC-822 HTML message for a draft.
     *
     * Hand-rolled rather than routed through PHPMailer because a draft is
     * routinely incomplete (no recipient yet), which PHPMailer rejects.
     */
    private function build_draft_mime(
        string $from_email,
        string $from_name,
        string $to,
        string $subject,
        string $body_html,
        string $cc,
        string $draftid
    ): string {
        $enc = function (string $v): string {
            $v = trim(preg_replace('/[\r\n]+/', ' ', $v));
            if ($v === '' || !preg_match('/[^\x20-\x7E]/', $v)) {
                return $v;
            }
            return '=?UTF-8?B?' . base64_encode($v) . '?=';
        };
        $addr = function (string $list): string {
            $out = [];
            foreach (explode(',', $list) as $a) {
                $a = trim(preg_replace('/[\r\n]+/', ' ', $a));
                if ($a !== '') {
                    $out[] = $a;
                }
            }
            return implode(', ', $out);
        };

        $from = $from_name !== ''
            ? $enc($from_name) . ' <' . $from_email . '>'
            : $from_email;

        $domain = substr(strrchr($from_email, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $from,
        ];
        // A draft is routinely unaddressed — omit an empty To rather than send
        // an empty header, which some IMAP servers reject on APPEND.
        if ($addr($to) !== '') {
            $headers[] = 'To: ' . $addr($to);
        }
        $headers = array_merge($headers, [
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'X-Mailer: Moodle Student Email Manager',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ]);
        if ($addr($cc) !== '') {
            $headers[] = 'Cc: ' . $addr($cc);
        }
        if ($draftid !== '') {
            $headers[] = 'X-SEM-Draft-Id: ' . preg_replace('/[^A-Za-z0-9._-]/', '', $draftid);
        }

        $body = chunk_split(base64_encode($body_html), 76, "\r\n");

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Return the accumulated SMTP debug log from the last send_message() call.
     *
     * This string is included in the JSON response so the browser console
     * (semDebug) can display the full SMTP conversation without needing
     * SSH or cPanel file access.
     */
    public function get_smtp_debug_log(): string {
        return $this->smtp_debug_log;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function get_host_spec(): string {
        $host = trim(get_config('auth_studentemail', 'imap_host') ?? '');
        if (empty($host)) {
            $host = trim(get_config('local_studentemail', 'imap_host') ?? '');
        }
        if (strpos($host, ',') !== false) {
            $parts = explode(',', $host);
            $host  = trim($parts[0]);
        }
        $port = (int)(get_config('auth_studentemail', 'imap_port') ?? 993);
        return $host . ':' . $port;
    }

    /**
     * Decode MIME-encoded email header value (RFC 2047).
     */
    private function decode_header(string $value): string {
        if (empty($value)) return '';
        $decoded = imap_mime_header_decode($value);
        if (!$decoded) return $value;

        $result = '';
        foreach ($decoded as $part) {
            $charset = strtoupper($part->charset ?? 'UTF-8');
            $text    = $part->text ?? '';
            if ($charset !== 'UTF-8' && $charset !== 'DEFAULT' && function_exists('mb_convert_encoding')) {
                $text = @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
            }
            $result .= $text;
        }
        return $result;
    }

    /**
     * Format an email date string into a human-friendly form.
     */
    private function format_date(string $date): string {
        if (empty($date)) return '';
        $ts = @strtotime($date);
        if (!$ts) return $date;

        $now   = time();
        $diff  = $now - $ts;

        if ($diff < 86400 && date('d', $ts) === date('d', $now)) {
            return date('g:i A', $ts); // Today: show time
        } elseif ($diff < 7 * 86400) {
            return date('D g:i A', $ts); // This week: day + time
        } else {
            return date('d M Y', $ts); // Older: full date
        }
    }

    /**
     * Extract plain text, HTML body, and attachment list from a message structure.
     *
     * @return array [html, plain, attachments]
     */
    private function extract_body(int $msgno, object $structure): array {
        $html        = '';
        $plain       = '';
        $attachments = [];

        if (isset($structure->parts) && count($structure->parts) > 0) {
            $this->parse_parts($msgno, $structure->parts, $html, $plain, $attachments, '');
        } else {
            // Single-part message.
            $body = @imap_fetchbody($this->stream, $msgno, '1');
            $body = $this->decode_part($body, $structure->encoding ?? ENC7BIT);

            if ($structure->type === TYPETEXT) {
                $charset = $this->get_charset($structure);
                if (strtolower($structure->subtype ?? '') === 'html') {
                    $html  = $this->convert_charset($body, $charset);
                } else {
                    $plain = $this->convert_charset($body, $charset);
                }
            }
        }

        return [$html, $plain, $attachments];
    }

    private function parse_parts(int $msgno, array $parts, string &$html, string &$plain, array &$attachments, string $prefix): void {
        foreach ($parts as $index => $part) {
            $partnum = $prefix === '' ? (string)($index + 1) : $prefix . '.' . ($index + 1);

            // Check if this is an attachment.
            $is_attachment = false;
            $filename      = '';
            if (!empty($part->ifdparameters)) {
                foreach ($part->dparameters as $dp) {
                    if (strtolower($dp->attribute) === 'filename') {
                        $is_attachment = true;
                        $filename      = $this->decode_header($dp->value);
                    }
                }
            }
            if (!$is_attachment && !empty($part->ifparameters)) {
                foreach ($part->parameters as $p) {
                    if (strtolower($p->attribute) === 'name') {
                        $is_attachment = true;
                        $filename      = $this->decode_header($p->value);
                    }
                }
            }
            // Parts with Content-Disposition: attachment.
            if (!empty($part->ifdisposition) && strtolower($part->disposition ?? '') === 'attachment') {
                $is_attachment = true;
            }

            if ($is_attachment && !empty($filename)) {
                $attachments[] = [
                    'filename' => $filename,
                    'partnum'  => $partnum,
                    'size'     => $part->bytes ?? 0,
                    'type'     => $part->subtype ?? 'octet-stream',
                ];
                continue;
            }

            // Recurse into multipart.
            if ($part->type === TYPEMULTIPART && !empty($part->parts)) {
                $this->parse_parts($msgno, $part->parts, $html, $plain, $attachments, $partnum);
                continue;
            }

            if ($part->type === TYPETEXT) {
                $charset = $this->get_charset($part);
                $body    = @imap_fetchbody($this->stream, $msgno, $partnum);
                $body    = $this->decode_part($body, $part->encoding ?? ENC7BIT);
                $body    = $this->convert_charset($body, $charset);

                if (strtolower($part->subtype ?? '') === 'html') {
                    $html  .= $body;
                } else {
                    $plain .= $body;
                }
            }
        }
    }

    private function decode_part(string $data, int $encoding): string {
        switch ($encoding) {
            case ENCBASE64:
                // Base64 from imap_fetchbody() carries CRLF line breaks — strip before decoding.
                return base64_decode(str_replace(["\r", "\n", " "], '', $data));
            case ENCQUOTEDPRINTABLE:
                return quoted_printable_decode($data);
            default:
                return $data;
        }
    }

    private function get_charset(object $structure): string {
        if (!empty($structure->parameters)) {
            foreach ($structure->parameters as $p) {
                if (strtolower($p->attribute) === 'charset') {
                    return strtoupper($p->value);
                }
            }
        }
        return 'UTF-8';
    }

    private function convert_charset(string $text, string $charset): string {
        if ($charset === 'UTF-8' || empty($charset)) return $text;
        if (function_exists('mb_convert_encoding')) {
            return @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
        }
        return $text;
    }

    /**
     * Sanitise HTML email body to prevent XSS.
     * Strips scripts, event handlers, and dangerous protocols.
     * Wraps result in a sandboxed container.
     */
    private function sanitise_html(string $html): string {
        // Remove <script> blocks.
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        // Remove on* event attributes — quoted (double), quoted (single), and unquoted.
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*\'[^\']*\'/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);
        // Remove javascript: protocol from any link-bearing attribute.
        $html = preg_replace(
            '/(href|src|action|formaction)\s*=\s*["\']?\s*javascript:/i',
            '$1="about:blank"',
            $html
        );
        // Remove <meta>, <link>, and <base> tags.
        $html = preg_replace('/<(meta|link|base)\b[^>]*>/i', '', $html);
        // Remove <style> blocks (may contain expression() or behavior:).
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        // Open all links in a new tab.
        $html = preg_replace('/<a\s/i', '<a target="_blank" rel="noopener noreferrer" ', $html);

        return $html;
    }
}
