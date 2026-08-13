<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * cPanel API wrapper for local_studentemail.
 *
 * Tries UAPI first (modern, port 2083). Falls back to JSON API v2
 * (legacy XML API) automatically when UAPI returns a non-200 or
 * when a connection error occurs.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_studentemail;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * cPanel API wrapper.
 *
 * All public methods return an array:
 *   ['success' => bool, 'message' => string, 'data' => mixed]
 */
class cpanel_api {
    /** @var string cPanel hostname (no scheme, no port) */
    private $host;
    /** @var int cPanel port (default 2083) */
    private $port;
    /** @var string cPanel username */
    private $username;
    /** @var string cPanel API token */
    private $token;
    /** @var string Email domain (e.g. students.college.edu.au) */
    private $domain;

    /**
     * Constructor — reads config from plugin settings.
     */
    public function __construct() {
        $this->host     = get_config('local_studentemail', 'cpanel_host');
        $this->port     = (int)(get_config('local_studentemail', 'cpanel_port') ?: 2083);
        $this->username = get_config('local_studentemail', 'cpanel_username');
        $this->token    = get_config('local_studentemail', 'cpanel_token');
        $this->domain   = get_config('local_studentemail', 'email_domain');
    }

    /**
     * Check whether the plugin has been configured.
     */
    public function is_configured(): bool {
        return !empty($this->host) && !empty($this->username) && !empty($this->token) && !empty($this->domain);
    }

    /**
     * Create a new email account on cPanel.
     *
     * @param string $localpart  The part before @  (e.g. "stu0001")
     * @param string $password   Plaintext password
     * @param int    $quota_mb   Quota in MB (0 = unlimited)
     * @return array ['success'=>bool, 'message'=>string, 'email'=>string]
     */
    public function create_account(string $localpart, string $password, int $quota_mb = 1024): array {
        $params = [
            'email'    => $localpart,
            'domain'   => $this->domain,
            'password' => $password,
            'quota'    => $quota_mb,
        ];

        // Try UAPI first.
        $result = $this->uapi_call('Email', 'add_pop', $params);
        if ($result['success']) {
            return array_merge($result, ['email' => $localpart . '@' . $this->domain]);
        }

        // Fall back to JSON API v2.
        $result = $this->jsonapi_call('Email', 'addpop', array_merge($params, [
            'email'  => $localpart,
            'domain' => $this->domain,
        ]));
        return array_merge($result, ['email' => $localpart . '@' . $this->domain]);
    }

    /**
     * Delete an email account from cPanel.
     *
     * @param string $localpart  The part before @
     * @return array
     */
    public function delete_account(string $localpart): array {
        $params = ['email' => $localpart, 'domain' => $this->domain];

        $result = $this->uapi_call('Email', 'delete_pop', $params);
        if ($result['success']) {
            return $result;
        }
        return $this->jsonapi_call('Email', 'delpop', $params);
    }

    /**
     * Change the password of an existing email account.
     *
     * @param string $localpart
     * @param string $new_password  Plaintext new password
     * @return array
     */
    public function change_password(string $localpart, string $new_password): array {
        $params = [
            'email'    => $localpart,
            'domain'   => $this->domain,
            'password' => $new_password,
        ];

        // UAPI only — JSON API v2 does not support API-token auth on modern cPanel.
        return $this->uapi_call('Email', 'passwd_pop', $params);
    }

    /**
     * Update the quota of an email account.
     *
     * @param string $localpart
     * @param int    $quota_mb   0 = unlimited
     * @return array
     */
    public function set_quota(string $localpart, int $quota_mb): array {
        $params = [
            'email'  => $localpart,
            'domain' => $this->domain,
            'quota'  => $quota_mb,
        ];

        $result = $this->uapi_call('Email', 'edit_pop_quota', $params);
        if ($result['success']) {
            return $result;
        }
        // JSON API v2 edit_pop_quota uses same func name.
        return $this->jsonapi_call('Email', 'editquota', $params);
    }

    /**
     * List all email accounts on the configured domain.
     *
     * @return array ['success'=>bool, 'message'=>string, 'accounts'=>array]
     */
    public function list_accounts(): array {
        $params = ['domain' => $this->domain, 'skip_main' => 1];

        $result = $this->uapi_call('Email', 'list_pops_with_disk', $params);
        if ($result['success'] && isset($result['data'])) {
            return ['success' => true, 'message' => '', 'accounts' => $result['data']];
        }

        $result = $this->jsonapi_call('Email', 'listpopswithdisk', $params);
        $accounts = $result['data'] ?? [];
        return ['success' => $result['success'], 'message' => $result['message'], 'accounts' => $accounts];
    }

    /**
     * Diagnostic: return raw cPanel account list + parsed localparts.
     * Used by the diagnose_import AJAX action for debugging only.
     */
    public function diagnose_list_accounts(): array {
        $params = ['domain' => $this->domain, 'skip_main' => 1];

        // Try UAPI.
        $uapi_raw = $this->uapi_call('Email', 'list_pops_with_disk', $params);

        // Try JSON API v2 regardless (for comparison).
        $jsonapi_raw = $this->jsonapi_call('Email', 'listpopswithdisk', $params);

        // Pick whichever succeeded.
        $accounts = [];
        $source = 'none';
        if ($uapi_raw['success'] && !empty($uapi_raw['data'])) {
            $accounts = (array)$uapi_raw['data'];
            $source = 'uapi';
        } elseif ($jsonapi_raw['success'] && !empty($jsonapi_raw['data'])) {
            $accounts = (array)$jsonapi_raw['data'];
            $source = 'jsonapi';
        }

        // Show first 5 raw account objects so we can see the field names.
        $sample_raw = array_slice($accounts, 0, 5);

        // Parse localparts the way import_existing does (both old and new logic).
        $parsed = [];
        foreach (array_slice($accounts, 0, 20) as $acct) {
            $acct = (array)$acct;
            $login_raw  = $acct['login']  ?? '';
            $email_raw  = $acct['email']  ?? '';
            $user_raw   = $acct['user']   ?? ''; // some versions use 'user'

            // Old logic (buggy if login = full email).
            $old_localpart = strtolower(trim($login_raw));

            // New logic: strip @domain from login if it looks like a full email.
            $new_localpart = strtolower(trim($login_raw));
            if (!empty($new_localpart) && strpos($new_localpart, '@') !== false) {
                $new_localpart = substr($new_localpart, 0, strpos($new_localpart, '@'));
            }
            if (empty($new_localpart) && !empty($email_raw)) {
                $new_localpart = substr(strtolower($email_raw), 0, strpos($email_raw . '@', '@'));
            }
            if (empty($new_localpart) && !empty($user_raw)) {
                $new_localpart = strtolower(trim($user_raw));
                if (strpos($new_localpart, '@') !== false) {
                    $new_localpart = substr($new_localpart, 0, strpos($new_localpart, '@'));
                }
            }

            $parsed[] = [
                'login_raw'     => $login_raw,
                'email_raw'     => $email_raw,
                'user_raw'      => $user_raw,
                'old_localpart' => $old_localpart,
                'new_localpart' => $new_localpart,
            ];
        }

        return [
            'success'          => true,
            'domain_config'    => $this->domain,
            'uapi_success'     => $uapi_raw['success'],
            'uapi_message'     => $uapi_raw['message'],
            'uapi_count'       => is_array($uapi_raw['data']) ? count($uapi_raw['data']) : 0,
            'jsonapi_success'  => $jsonapi_raw['success'],
            'jsonapi_message'  => $jsonapi_raw['message'],
            'jsonapi_count'    => is_array($jsonapi_raw['data']) ? count($jsonapi_raw['data']) : 0,
            'source_used'      => $source,
            'total_accounts'   => count($accounts),
            'sample_raw_5'     => $sample_raw,
            'parsed_20'        => $parsed,
        ];
    }

    /**
     * Test connectivity to the cPanel server.
     *
     * @return array
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'cPanel not configured.'];
        }
        return $this->uapi_call('Email', 'list_pops', ['domain' => $this->domain]);
    }

    // =========================================================================
    // Private: UAPI (preferred)
    // =========================================================================

    /**
     * Call the cPanel UAPI.
     *
     * URL: https://HOST:PORT/execute/MODULE/FUNCTION
     * Auth: Authorization: cpanel USERNAME:TOKEN
     */
    private function uapi_call(string $module, string $function, array $params = []): array {
        $url  = 'https://' . $this->host . ':' . $this->port . '/execute/' . $module . '/' . $function;
        $auth = 'cpanel ' . $this->username . ':' . $this->token;

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader(['Authorization: ' . $auth]);
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => false, 'CURLOPT_SSL_VERIFYHOST' => 0]);

        // Pass params as array so Moodle's curl sets Content-Type correctly.
        $response = $curl->post($url, $params);
        $info     = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);

        if ($curl->errno !== 0) {
            return ['success' => false, 'message' => 'UAPI connection error (cURL ' . $curl->errno . '): ' . $curl->error, 'data' => null];
        }

        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $preview = substr(strip_tags($response), 0, 300);
            return ['success' => false, 'message' => "UAPI non-JSON response (HTTP {$httpcode}): {$preview}", 'data' => null];
        }

        $status = isset($json['status']) ? (int)$json['status'] : 0;
        if ($status !== 1) {
            $errors = isset($json['errors']) ? implode(', ', (array)$json['errors']) : 'Unknown error';
            return ['success' => false, 'message' => "UAPI error (HTTP {$httpcode}): {$errors}", 'data' => null];
        }

        return ['success' => true, 'message' => '', 'data' => $json['data'] ?? null];
    }

    // =========================================================================
    // Private: JSON API v2 (fallback)
    // =========================================================================

    /**
     * Call the cPanel JSON API v2 (legacy XML API in JSON mode).
     *
     * URL: https://HOST:PORT/json-api/cpanel
     * Auth: Authorization: cpanel USERNAME:TOKEN
     */
    private function jsonapi_call(string $module, string $function, array $params = []): array {
        $url = 'https://' . $this->host . ':' . $this->port . '/json-api/cpanel';
        $auth = 'cpanel ' . $this->username . ':' . $this->token;

        $query = array_merge([
            'cpanel_jsonapi_user'       => $this->username,
            'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module'     => $module,
            'cpanel_jsonapi_func'       => $function,
        ], $params);

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader(['Authorization: ' . $auth]);
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => false, 'CURLOPT_SSL_VERIFYHOST' => 0]);

        $response = $curl->post($url, http_build_query($query));

        if ($curl->errno !== 0) {
            return ['success' => false, 'message' => 'JSON API connection error: ' . $curl->error, 'data' => null];
        }

        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'JSON API invalid response', 'data' => null];
        }

        // JSON API v2 success indicator.
        $event = $json['cpanelresult']['event'] ?? [];
        $reason = $json['cpanelresult']['error'] ?? ($event['reason'] ?? 'Unknown error');
        $result = (int)($event['result'] ?? 0);

        if ($result !== 1) {
            return ['success' => false, 'message' => 'JSON API error: ' . $reason, 'data' => null];
        }

        $data = $json['cpanelresult']['data'] ?? null;
        return ['success' => true, 'message' => '', 'data' => $data];
    }
}
