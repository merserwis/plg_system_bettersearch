<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Who is searching, for the statistics: the visitor's IP address (also behind Cloudflare or another
 * proxy) and whether it is on the list of addresses left out of the statistics (the company's own
 * staff). The address is only compared, never stored.
 */

namespace Merserwis\Plugin\System\BetterSearch\Engine;

\defined('_JEXEC') or die;

final class Visitor
{
    /**
     * The visitor's IP address. "auto": the address a proxy in front of the site passes on
     * (Cloudflare CF-Connecting-IP, X-Real-IP, the first X-Forwarded-For), else the connection's.
     * Such headers can be forged, which only lets someone leave their own searches out.
     *
     * @param array $server $_SERVER
     */
    public static function ip(array $server, string $source = 'auto'): string
    {
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($source === 'remote') {
            return self::valid($remote);
        }
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            if (!empty($server[$header])) {
                $ip = self::valid(trim(explode(',', (string) $server[$header])[0]));
                if ($ip !== '') {
                    return $ip;
                }
            }
        }

        return self::valid($remote);
    }

    private static function valid(string $ip): string
    {
        // "[2001:db8::1]:443" / "1.2.3.4:5678" from some proxies
        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $ip, $m)) {
            $ip = $m[1];
        } elseif (substr_count($ip, ':') === 1 && preg_match('/^([\d.]+):\d+$/', $ip, $m)) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * The rules of the setting: one per line or separated by commas; "# comment" ignored.
     * An address (1.2.3.4, 2001:db8::1), a network (1.2.3.0/24, 2001:db8::/48) or an IPv4
     * address with * (10.48.*).
     *
     * @return string[]
     */
    public static function rules(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line !== '' && self::validRule($line)) {
                $out[] = strtolower($line);
            }
        }

        return array_values(array_unique($out));
    }

    public static function validRule(string $rule): bool
    {
        if (str_contains($rule, '*')) {
            return (bool) preg_match('/^(\d{1,3}|\*)(\.(\d{1,3}|\*)){0,3}$/', $rule);
        }
        if (str_contains($rule, '/')) {
            [$net, $bits] = explode('/', $rule, 2);
            $max = str_contains($net, ':') ? 128 : 32;

            return filter_var($net, FILTER_VALIDATE_IP) !== false && ctype_digit($bits) && (int) $bits <= $max;
        }

        return filter_var($rule, FILTER_VALIDATE_IP) !== false;
    }

    /** Whether the address matches one of the rules. */
    public static function matches(string $ip, array $rules): bool
    {
        if ($ip === '' || !$rules) {
            return false;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($rules as $rule) {
            if (str_contains($rule, '*')) {
                if (strlen($bin) === 4) {
                    $parts   = explode('.', $rule);
                    $pattern = '/^' . implode('\\.', array_map(fn ($p) => $p === '*' ? '\\d{1,3}' : $p, $parts)) . (count($parts) < 4 ? '(\\.\\d{1,3})*' : '') . '$/';
                    if (preg_match($pattern, $ip)) {
                        return true;
                    }
                }
                continue;
            }
            [$net, $bits] = str_contains($rule, '/') ? explode('/', $rule, 2) : [$rule, null];
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }
}
