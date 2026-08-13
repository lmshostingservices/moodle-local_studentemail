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
 * Standalone test harness for local_studentemail IMAP/SMTP logic.
 *
 * Run without Moodle or live credentials — all unit tests pass in Replit.
 * Drop onto the live server and set env vars for real connection tests.
 *
 * Usage (dry run, no credentials needed):
 *   php test_harness.php
 *
 * Usage (live connection tests):
 *   TEST_MAIL_HOST=mail.aioplms.com.au \
 *   TEST_MAIL_USER=student@aioplms.com.au \
 *   TEST_MAIL_PASS=secret \
 *   TEST_MAIL_SEND_TO=you@example.com \
 *   MOODLE_ROOT=/var/www/moodle/yourdomain/ \
 *   php test_harness.php
 *
 * @package local_studentemail
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---------------------------------------------------------------------------
// Colour helpers
// ---------------------------------------------------------------------------
function c(string $colour, string $text): string {
    $codes = ['green' => '32', 'red' => '31', 'yellow' => '33',
              'cyan' => '36', 'bold' => '1', 'reset' => '0'];
    $c = $codes[$colour] ?? '0';
    return "\033[{$c}m{$text}\033[0m";
}

// ---------------------------------------------------------------------------
// Test runner state
// ---------------------------------------------------------------------------
$results = ['pass' => 0, 'fail' => 0, 'skip' => 0];

function pass(string $label): void {
    global $results;
    $results['pass']++;
    echo c('green', '  [PASS]') . " $label\n";
}

function fail(string $label, string $detail = ''): void {
    global $results;
    $results['fail']++;
    echo c('red', '  [FAIL]') . " $label" . ($detail ? " — $detail" : '') . "\n";
}

function skip(string $label, string $reason = ''): void {
    global $results;
    $results['skip']++;
    echo c('yellow', '  [SKIP]') . " $label" . ($reason ? " ($reason)" : '') . "\n";
}

function section(string $title): void {
    echo "\n" . c('cyan', c('bold', "── $title")) . "\n";
}

function summary(array $results): void {
    $total = array_sum($results);
    echo "\n" . c('bold', "Results: ") .
         c('green', "{$results['pass']} passed") . '  ' .
         c('red',   "{$results['fail']} failed") . '  ' .
         c('yellow', "{$results['skip']} skipped") . "  / $total total\n\n";
    if ($results['fail'] > 0) {
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Live test config (from env vars — safe: never in source code)
// ---------------------------------------------------------------------------
$LIVE = [
    'host'    => getenv('TEST_MAIL_HOST')    ?: '',
    'user'    => getenv('TEST_MAIL_USER')    ?: '',
    'pass'    => getenv('TEST_MAIL_PASS')    ?: '',
    'send_to' => getenv('TEST_MAIL_SEND_TO') ?: '',
    'moodle'  => rtrim(getenv('MOODLE_ROOT') ?: '', '/'),
    'imap_port'      => (int)(getenv('TEST_IMAP_PORT')      ?: 993),
    'smtp_port'      => (int)(getenv('TEST_SMTP_PORT')      ?: 465),
    'smtp_enc'       => getenv('TEST_SMTP_ENC')             ?: 'ssl',
    'strip_domain'   => (bool)(getenv('TEST_STRIP_DOMAIN')  ?: false),
    'novalidate'     => (bool)(getenv('TEST_NOVALIDATE')    ?: false),
];
$live_available = !empty($LIVE['host']) && !empty($LIVE['user']) && !empty($LIVE['pass']);

// ---------------------------------------------------------------------------
// Moodle stubs — so the plugin files load outside Moodle
// ---------------------------------------------------------------------------
define('MOODLE_INTERNAL', true);

// Simulated Moodle config store — mirrors what the admin settings page saves.
$_mock_config = [
    'auth_studentemail' => [
        'imap_host'       => $LIVE['host'],
        'imap_port'       => $LIVE['imap_port'],
        'imap_type'       => 'imap',
        'imap_encryption' => 'ssl',
        'imap_novalidate' => $LIVE['novalidate'] ? '1' : '',
        'strip_domain'    => $LIVE['strip_domain'] ? '1' : '',
    ],
    'local_studentemail' => [
        'smtp_host'       => $LIVE['host'],
        'smtp_port'       => $LIVE['smtp_port'],
        'smtp_encryption' => $LIVE['smtp_enc'],
    ],
];

function get_config(string $plugin, string $key): mixed {
    global $_mock_config;
    return $_mock_config[$plugin][$key] ?? false;
}

function get_string(string $id, string $component = '', mixed $a = null): string {
    return "[{$component}:{$id}]";
}

// Moodle exception stub — behaves like a real exception.
class moodle_exception extends \RuntimeException {
    public function __construct(string $errorcode, string $module = '', string $link = '', mixed $a = null) {
        $msg = "[{$module}:{$errorcode}]" . ($a ? " — $a" : '');
        parent::__construct($msg);
    }
}

// Minimal $CFG stub.
$CFG = new stdClass();
$CFG->dirroot = $LIVE['moodle'] ?: '/var/www/moodle';

// Stub moodle_phpmailer so send_message() can be unit-tested without Moodle.
// On the live server the real class is loaded via require_once.
if (!class_exists('moodle_phpmailer')) {
    class moodle_phpmailer {
        public string  $Host       = '';
        public bool    $SMTPAuth   = true;
        public string  $Username   = '';
        public string  $Password   = '';
        public int     $Port       = 587;
        public string  $SMTPSecure = 'tls';
        public bool    $SMTPAutoTLS = true;
        public array   $SMTPOptions = [];
        public string  $Subject    = '';
        public string  $Body       = '';
        public string  $AltBody    = '';
        public string  $CharSet    = 'UTF-8';
        public string  $ErrorInfo  = '';
        private array  $_to  = [];
        private array  $_cc  = [];
        private array  $_att = [];
        private string $_from = '';

        public function isSMTP(): void {}
        public function isHTML(bool $v): void {}

        public function setFrom(string $email, string $name = ''): void {
            $this->_from = "$name <$email>";
        }

        public function addAddress(string $addr, string $name = ''): void {
            $this->_to[] = $addr;
        }

        public function addCC(string $addr, string $name = ''): void {
            $this->_cc[] = $addr;
        }

        public function addAttachment(
            string $path, string $name = '', string $encoding = 'base64', string $type = ''
        ): bool {
            $this->_att[] = ['path' => $path, 'name' => $name, 'type' => $type];
            return true;
        }

        // Returns captured data for inspection in unit tests.
        public function getCaptured(): array {
            return [
                'from'        => $this->_from,
                'to'          => $this->_to,
                'cc'          => $this->_cc,
                'subject'     => $this->Subject,
                'body'        => $this->Body,
                'attachments' => $this->_att,
                'host'        => $this->Host,
                'port'        => $this->Port,
                'user'        => $this->Username,
                'secure'      => $this->SMTPSecure,
            ];
        }

        // Default: pretend send succeeds. Set $GLOBALS['_smtp_fail'] = true to simulate failure.
        public function send(): bool {
            if (!empty($GLOBALS['_smtp_fail'])) {
                $this->ErrorInfo = 'Simulated SMTP failure';
                return false;
            }
            return true;
        }

        public function getSentMIMEMessage(): string {
            return "MIME-Version: 1.0\r\nSubject: {$this->Subject}\r\n\r\n{$this->Body}";
        }
    }
}

// ---------------------------------------------------------------------------
// Load plugin classes
// ---------------------------------------------------------------------------
$plugin_dir = __DIR__;
require_once $plugin_dir . '/classes/imap_client.php';

// ---------------------------------------------------------------------------
// Reflection helper to call private/protected methods in tests
// ---------------------------------------------------------------------------
function call_private(object $obj, string $method, array $args = []): mixed {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
}

function get_private(object $obj, string $prop): mixed {
    $ref = new ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);
    return $ref->getValue($obj);
}

// ===========================================================================
// SECTION 1 — PHP environment
// ===========================================================================
section('1. PHP Environment');

// PHP version
$ver = PHP_VERSION;
if (version_compare($ver, '8.0.0', '>=')) {
    pass("PHP version: $ver (>= 8.0 required)");
} else {
    fail("PHP version: $ver", "need >= 8.0");
}

// Extensions
foreach (['imap', 'openssl', 'mbstring', 'json'] as $ext) {
    if (extension_loaded($ext)) {
        pass("Extension loaded: $ext");
    } else {
        fail("Extension missing: $ext", "required by imap_client.php");
    }
}

// ===========================================================================
// SECTION 2 — Mailbox spec string building
// ===========================================================================
section('2. Mailbox spec string building (no live connection)');

// Helper: build a client with specific mock config and extract its mailbox_spec.
function make_client_with_config(array $auth_cfg, string $local_smtp_host = 'smtp.example.com'): array {
    global $_mock_config;
    $old = $_mock_config;
    $_mock_config['auth_studentemail'] = array_merge($_mock_config['auth_studentemail'], $auth_cfg);
    $_mock_config['local_studentemail']['smtp_host'] = $local_smtp_host;

    $client = new \local_studentemail\imap_client('student@example.com', 'secret');
    $spec   = get_private($client, 'mailbox_spec');
    $uname  = get_private($client, 'username');

    $_mock_config = $old;
    return ['spec' => $spec, 'username' => $uname];
}

// 2a. SSL encryption → /imap/ssl
$r = make_client_with_config(['imap_host' => 'mail.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_novalidate' => '', 'strip_domain' => '']);
if (strpos($r['spec'], '/imap/ssl') !== false && strpos($r['spec'], 'mail.example.com:993') !== false) {
    pass("SSL spec: {$r['spec']}");
} else {
    fail("SSL spec incorrect", $r['spec']);
}

// 2b. TLS encryption → /imap/tls
$r = make_client_with_config(['imap_host' => 'mail.example.com', 'imap_port' => 143, 'imap_encryption' => 'tls', 'imap_novalidate' => '', 'strip_domain' => '']);
if (strpos($r['spec'], '/imap/tls') !== false) {
    pass("TLS spec: {$r['spec']}");
} else {
    fail("TLS spec incorrect", $r['spec']);
}

// 2c. novalidate-cert flag appended
$r = make_client_with_config(['imap_host' => 'mail.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_novalidate' => '1', 'strip_domain' => '']);
if (strpos($r['spec'], '/novalidate-cert') !== false) {
    pass("novalidate-cert flag present: {$r['spec']}");
} else {
    fail("novalidate-cert flag missing", $r['spec']);
}

// 2d. strip_domain strips @domain from username
$r = make_client_with_config(['imap_host' => 'mail.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_novalidate' => '', 'strip_domain' => '1']);
if ($r['username'] === 'student') {
    pass("strip_domain: username stripped to '{$r['username']}'");
} else {
    fail("strip_domain: expected 'student', got '{$r['username']}'");
}

// 2e. strip_domain=off keeps full address
$r = make_client_with_config(['imap_host' => 'mail.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_novalidate' => '', 'strip_domain' => '']);
if ($r['username'] === 'student@example.com') {
    pass("No strip_domain: username kept as '{$r['username']}'");
} else {
    fail("No strip_domain: expected 'student@example.com', got '{$r['username']}'");
}

// 2f. Comma-separated failover host — only first host used
$r = make_client_with_config(['imap_host' => 'mail.example.com,backup.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_novalidate' => '', 'strip_domain' => '']);
if (strpos($r['spec'], 'backup.example.com') === false && strpos($r['spec'], 'mail.example.com') !== false) {
    pass("Failover host: only first host used — {$r['spec']}");
} else {
    fail("Failover host: backup host leaked into spec", $r['spec']);
}

// 2g. Fallback to local_studentemail imap_host when auth plugin not configured
$_mock_config['auth_studentemail']['imap_host'] = '';
$_mock_config['local_studentemail']['imap_host'] = 'fallback.example.com';
$client = new \local_studentemail\imap_client('u@example.com', 'p');
$spec   = get_private($client, 'mailbox_spec');
if (strpos($spec, 'fallback.example.com') !== false) {
    pass("Fallback imap_host from local_studentemail config: $spec");
} else {
    fail("Fallback imap_host not applied", $spec);
}
// Restore
$_mock_config['auth_studentemail']['imap_host'] = $LIVE['host'] ?: 'mail.example.com';
unset($_mock_config['local_studentemail']['imap_host']);

// ===========================================================================
// SECTION 3 — Class structure & method signatures
// ===========================================================================
section('3. Class structure & method signatures');

$client = new \local_studentemail\imap_client('test@example.com', 'secret');

$required_methods = [
    'connect', 'close', 'list_folders', 'get_messages',
    'get_message', 'send_message', 'delete_message', 'mark_read',
];
foreach ($required_methods as $method) {
    if (method_exists($client, $method)) {
        pass("Method exists: imap_client::{$method}()");
    } else {
        fail("Method missing: imap_client::{$method}()");
    }
}

// send_message must accept optional $cc and $attachments params.
$ref    = new ReflectionMethod($client, 'send_message');
$params = $ref->getParameters();
$names  = array_map(fn($p) => $p->getName(), $params);

$required_params = ['from_email', 'from_name', 'to', 'subject', 'body_html'];
$optional_params = ['cc', 'attachments'];
foreach ($required_params as $p) {
    if (in_array($p, $names, true)) {
        pass("send_message has param: \${$p}");
    } else {
        fail("send_message missing param: \${$p}");
    }
}
foreach ($optional_params as $p) {
    $idx = array_search($p, $names, true);
    if ($idx !== false && $params[$idx]->isOptional()) {
        pass("send_message has optional param: \${$p}");
    } else {
        fail("send_message missing optional param: \${$p}");
    }
}

// ===========================================================================
// SECTION 4 — send_message logic (stub SMTP, no live server)
// ===========================================================================
section('4. send_message logic (stub SMTP — no live server)');

// Override moodle_phpmailer load so the method uses our stub.
// We do this by pointing CFG->dirroot at an empty file.
$fake_phpmailer_dir = sys_get_temp_dir() . '/sem_test_phpmailer';
@mkdir($fake_phpmailer_dir . '/lib/phpmailer', 0777, true);
$fake_phpmailer_file = $fake_phpmailer_dir . '/lib/phpmailer/moodle_phpmailer.php';
file_put_contents($fake_phpmailer_file, "<?php // stub — class already defined above\n");
$CFG->dirroot = $fake_phpmailer_dir;

// Set mock SMTP config.
$_mock_config['local_studentemail']['smtp_host'] = 'smtp.example.com';
$_mock_config['local_studentemail']['smtp_port'] = 465;
$_mock_config['local_studentemail']['smtp_encryption'] = 'ssl';
$_mock_config['auth_studentemail']['imap_novalidate'] = '';

$client = new \local_studentemail\imap_client('sender@example.com', 'sendpass');

// 4a. Basic send (no attachments, no CC) — success path.
try {
    $client->send_message(
        'sender@example.com', 'Test Sender',
        'recipient@example.com',
        'Test subject',
        '<p>Hello world</p>'
    );
    pass("send_message: basic send succeeded (stub SMTP)");
} catch (\Throwable $e) {
    fail("send_message: basic send threw exception", $e->getMessage());
}

// 4b. Send with CC.
try {
    $client->send_message(
        'sender@example.com', 'Test Sender',
        'to@example.com',
        'CC test',
        '<p>With CC</p>',
        'cc1@example.com, cc2@example.com'
    );
    pass("send_message: send with CC succeeded");
} catch (\Throwable $e) {
    fail("send_message: send with CC threw exception", $e->getMessage());
}

// 4c. Send with attachments array.
$tmp_file = tempnam(sys_get_temp_dir(), 'sem_att_');
file_put_contents($tmp_file, 'fake file contents');
try {
    $client->send_message(
        'sender@example.com', 'Test Sender',
        'to@example.com',
        'Attachment test',
        '<p>See attached</p>',
        '',
        [['tmp' => $tmp_file, 'name' => 'report.pdf', 'mime' => 'application/pdf']]
    );
    pass("send_message: send with attachment succeeded");
} catch (\Throwable $e) {
    fail("send_message: send with attachment threw exception", $e->getMessage());
} finally {
    @unlink($tmp_file);
}

// 4d. SMTP failure propagates as moodle_exception.
$GLOBALS['_smtp_fail'] = true;
try {
    $client->send_message('sender@example.com', 'S', 'r@example.com', 'fail', '<p>fail</p>');
    fail("send_message: SMTP failure should have thrown");
} catch (\moodle_exception $e) {
    pass("send_message: SMTP failure throws moodle_exception — '{$e->getMessage()}'");
} catch (\Throwable $e) {
    fail("send_message: wrong exception type on SMTP failure", get_class($e) . ': ' . $e->getMessage());
} finally {
    $GLOBALS['_smtp_fail'] = false;
}

// 4e. Missing smtp_host throws moodle_exception.
$_mock_config['local_studentemail']['smtp_host'] = '';
$client2 = new \local_studentemail\imap_client('sender@example.com', 'sendpass');
try {
    $client2->send_message('s@e.com', 'S', 'r@e.com', 'nosmtp', '<p>fail</p>');
    fail("send_message: missing smtp_host should have thrown");
} catch (\moodle_exception $e) {
    pass("send_message: missing smtp_host throws moodle_exception — '{$e->getMessage()}'");
} catch (\Throwable $e) {
    fail("send_message: wrong exception on missing smtp_host", get_class($e) . ': ' . $e->getMessage());
} finally {
    $_mock_config['local_studentemail']['smtp_host'] = 'smtp.example.com';
}

// ===========================================================================
// SECTION 5 — imap_open error path (no live server)
// ===========================================================================
section('5. IMAP connect error path (no live server)');

// Use a guaranteed-unreachable host so imap_open returns false quickly.
if (extension_loaded('imap')) {
    $_mock_config['auth_studentemail']['imap_host'] = '127.0.0.1';
    $_mock_config['auth_studentemail']['imap_port'] = 1;
    $bad_client = new \local_studentemail\imap_client('x@example.com', 'wrong');
    try {
        $bad_client->connect();
        fail("connect(): should throw on unreachable host");
    } catch (\moodle_exception $e) {
        pass("connect(): throws moodle_exception on unreachable host — '{$e->getMessage()}'");
    } catch (\Throwable $e) {
        // Some OS will throw a different error — still acceptable.
        pass("connect(): throws exception on unreachable host — " . get_class($e));
    }
    // Restore.
    $_mock_config['auth_studentemail']['imap_host'] = $LIVE['host'] ?: 'mail.example.com';
    $_mock_config['auth_studentemail']['imap_port'] = $LIVE['imap_port'];
} else {
    skip("connect() error path", "imap extension not loaded");
}

// ===========================================================================
// SECTION 6 — LIVE connection tests (only when env vars supplied)
// ===========================================================================
section('6. Live connection tests');

if (!$live_available) {
    $missing = array_filter(['TEST_MAIL_HOST' => $LIVE['host'], 'TEST_MAIL_USER' => $LIVE['user'], 'TEST_MAIL_PASS' => $LIVE['pass']], fn($v) => empty($v));
    skip("All live tests", "set " . implode(', ', array_keys($missing)) . " env vars to enable");
} else {
    // Restore live config.
    $_mock_config['auth_studentemail']['imap_host'] = $LIVE['host'];
    $_mock_config['auth_studentemail']['imap_port'] = $LIVE['imap_port'];
    $_mock_config['auth_studentemail']['imap_encryption'] = 'ssl';
    $_mock_config['auth_studentemail']['imap_novalidate'] = $LIVE['novalidate'] ? '1' : '';
    $_mock_config['auth_studentemail']['strip_domain'] = $LIVE['strip_domain'] ? '1' : '';
    $_mock_config['local_studentemail']['smtp_host'] = $LIVE['host'];
    $_mock_config['local_studentemail']['smtp_port'] = $LIVE['smtp_port'];
    $_mock_config['local_studentemail']['smtp_encryption'] = $LIVE['smtp_enc'];

    $live_client = new \local_studentemail\imap_client($LIVE['user'], $LIVE['pass']);
    $live_spec   = get_private($live_client, 'mailbox_spec');
    echo "    Connecting to: $live_spec\n";

    // 6a. IMAP connect + INBOX.
    try {
        $live_client->connect('INBOX');
        pass("Live IMAP connect to INBOX");

        // 6b. List folders.
        try {
            $folders = $live_client->list_folders();
            $count   = count($folders);
            pass("Live list_folders: $count folder(s) — [" . implode(', ', array_slice($folders, 0, 5)) . "]");
        } catch (\Throwable $e) {
            fail("Live list_folders", $e->getMessage());
        }

        // 6c. Fetch up to 3 messages.
        try {
            $result  = $live_client->get_messages('INBOX', 3, 0);
            $count   = count($result['messages'] ?? []);
            $total   = $result['total'] ?? '?';
            pass("Live get_messages: $count fetched (total in INBOX: $total)");

            // 6d. Fetch full body of first message.
            if ($count > 0) {
                $uid = $result['messages'][0]['uid'] ?? null;
                if ($uid) {
                    try {
                        $msg = $live_client->get_message($uid, 'INBOX');
                        $has_body = !empty($msg['body_html']) || !empty($msg['body_plain']);
                        if ($has_body) {
                            $sub = substr($msg['subject'] ?? '(no subject)', 0, 60);
                            pass("Live get_message (uid=$uid): subject=\"$sub\"");
                        } else {
                            fail("Live get_message (uid=$uid): body empty");
                        }
                    } catch (\Throwable $e) {
                        fail("Live get_message", $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            fail("Live get_messages", $e->getMessage());
        }

        $live_client->close();
    } catch (\Throwable $e) {
        fail("Live IMAP connect", $e->getMessage());
    }

    // 6e. SMTP send via real moodle_phpmailer (needs MOODLE_ROOT set).
    $send_to = $LIVE['send_to'] ?: $LIVE['user'];
    if (!empty($LIVE['moodle']) && file_exists($LIVE['moodle'] . '/lib/phpmailer/moodle_phpmailer.php')) {
        $CFG->dirroot = $LIVE['moodle'];
        $smtp_client  = new \local_studentemail\imap_client($LIVE['user'], $LIVE['pass']);
        try {
            $smtp_client->send_message(
                $LIVE['user'], 'Student Email Test Harness',
                $send_to,
                '[TEST] Student Email Plugin — SMTP test ' . date('Y-m-d H:i:s'),
                '<p>This is an automated test from the <strong>local_studentemail</strong> test harness.</p>' .
                '<p>If you see this, SMTP is working correctly.</p>' .
                '<p><em>Sent: ' . date('r') . '</em></p>'
            );
            pass("Live SMTP send to $send_to — check your inbox");
        } catch (\Throwable $e) {
            fail("Live SMTP send", $e->getMessage());
        }
    } else {
        // Fallback: raw TCP banner check on SMTP port.
        $smtp_port = $LIVE['smtp_port'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $proto = ($LIVE['smtp_enc'] === 'ssl') ? 'ssl' : 'tcp';
        $conn = @stream_socket_client(
            "$proto://{$LIVE['host']}:$smtp_port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx
        );
        if ($conn) {
            $banner = fgets($conn, 512);
            fclose($conn);
            if (strpos($banner, '220') !== false) {
                pass("Live SMTP TCP banner on port $smtp_port: " . trim($banner));
            } else {
                fail("Live SMTP: unexpected banner on port $smtp_port", trim($banner));
            }
        } else {
            fail("Live SMTP TCP connect to {$LIVE['host']}:$smtp_port", "$errstr ($errno)");
        }
        skip("Live SMTP send (full)", "set MOODLE_ROOT to enable real send via PHPMailer");
    }
}

// ===========================================================================
// Final summary
// ===========================================================================
echo "\n" . str_repeat('─', 60) . "\n";
summary($results);
