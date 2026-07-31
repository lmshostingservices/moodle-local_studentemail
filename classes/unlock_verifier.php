<?php
/**
 * Plugin unlock verification for Student Email Manager.
 * Deducts 5,000 credits on first use; permanently unlocks the plugin for this site.
 *
 * @package    local_studentemail
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_studentemail;

defined('MOODLE_INTERNAL') || die();

class unlock_verifier {

    const PLUGIN_ID       = 'studentemail';
    const CREDITS_REQUIRED = 5000;
    const API_URL         = 'https://lms-labs.com/api/plugin-unlock/verify';
    const UNLOCK_URL      = 'https://lms-labs.com/api/plugin-unlock';
    const CACHE_KEY       = 'local_studentemail_unlocked';
    const CACHE_DURATION  = 86400; // 24 hours
    const PERM_KEY        = 'unlocked_permanently';

    /**
     * Returns true if this site has unlocked the plugin.
     * Three-tier check: permanent DB flag → 24 h cache → live API.
     */
    public static function is_unlocked(): bool {
        // Tier 1: permanent DB flag — never purged by Moodle cache clears.
        if (get_config('local_studentemail', self::PERM_KEY) === '1') {
            return true;
        }

        // Tier 2: 24-hour positive cache (negative results are NOT cached so
        // that payment is picked up immediately on the next page load).
        $cache  = \cache::make('core', 'config');
        $cached = $cache->get(self::CACHE_KEY);
        if ($cached !== false && is_array($cached)
                && !empty($cached['unlocked'])
                && isset($cached['expires'])
                && $cached['expires'] > time()) {
            set_config(self::PERM_KEY, '1', 'local_studentemail');
            return true;
        }

        // Tier 3: live API check.
        $result = self::verify_with_api();

        // If credentials are present and credits are available, auto-unlock now.
        if (!$result['unlocked'] && $result['has_credentials'] && $result['has_credits']) {
            if (self::auto_unlock()) {
                $result['unlocked'] = true;
            }
        }

        if ($result['unlocked']) {
            $cache->set(self::CACHE_KEY, [
                'unlocked' => true,
                'expires'  => time() + self::CACHE_DURATION,
            ]);
            set_config(self::PERM_KEY, '1', 'local_studentemail');
        }

        return $result['unlocked'];
    }

    /**
     * Call on settings/dashboard pages to show a warning if not unlocked.
     * Returns true if unlocked, false if locked (caller may block UI).
     */
    public static function check_and_notify(): bool {
        if (self::is_unlocked()) {
            return true;
        }
        \core\notification::warning(
            'Student Email Manager requires <strong>' . number_format(self::CREDITS_REQUIRED) .
            ' AI credits</strong> to activate. Visit your ' .
            '<a href="https://lms-labs.com" target="_blank">AI Grader dashboard</a> ' .
            'to unlock this plugin.'
        );
        return false;
    }

    public static function clear_cache(): void {
        $cache = \cache::make('core', 'config');
        $cache->delete(self::CACHE_KEY);
    }

    private static function verify_with_api(): array {
        global $CFG;
        $creds = self::get_credentials();

        // Fail-open when no credentials are configured (fresh install).
        if (empty($creds['siteid']) || empty($creds['apikey'])) {
            return ['unlocked' => false, 'has_credentials' => false, 'has_credits' => false];
        }

        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 10, 'CURLOPT_CONNECTTIMEOUT' => 5]);

        $response = $curl->get(self::API_URL, [
            'pluginId' => self::PLUGIN_ID,
            'siteId'   => $creds['siteid'],
            'apiKey'   => $creds['apikey'],
        ]);
        $httpcode = (int)($curl->info['http_code'] ?? 0);

        if ($httpcode !== 200 || empty($response)) {
            // Fail-open on network errors so paying customers are never blocked.
            return ['unlocked' => true, 'has_credentials' => true, 'has_credits' => true];
        }

        $data    = json_decode($response, true);
        $credits = isset($data['credits']) ? (int)$data['credits'] : 0;

        return [
            'unlocked'        => !empty($data['unlocked']),
            'has_credentials' => true,
            'has_credits'     => ($credits >= self::CREDITS_REQUIRED) || ($credits === -1),
        ];
    }

    private static function auto_unlock(): bool {
        global $CFG;
        $creds = self::get_credentials();
        if (empty($creds['siteid']) || empty($creds['apikey'])) {
            return false;
        }

        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 15,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        $response = $curl->post(self::UNLOCK_URL, json_encode([
            'pluginId' => self::PLUGIN_ID,
            'siteId'   => $creds['siteid'],
            'apiKey'   => $creds['apikey'],
        ]));
        $httpcode = (int)($curl->info['http_code'] ?? 0);

        if ($httpcode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            return !empty($data['success']);
        }
        return false;
    }

    private static function get_credentials(): array {
        $siteid = '';
        $apikey = '';

        // Prefer Central Config (local_aiconfig) — same source as all AI plugins.
        if (class_exists('\\local_aiconfig\\config')) {
            $siteid = \local_aiconfig\config::get_site_id();
            $apikey = \local_aiconfig\config::get_api_key();
        }

        // Fall back to plugin-specific settings.
        if (empty($siteid)) {
            $siteid = get_config('local_studentemail', 'siteid');
        }
        if (empty($apikey)) {
            $apikey = get_config('local_studentemail', 'apikey');
        }

        return ['siteid' => (string)($siteid ?: ''), 'apikey' => (string)($apikey ?: '')];
    }
}
