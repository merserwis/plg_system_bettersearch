<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 *
 * Small WebP copies of product images for the result lists (a 64 px thumbnail should not load a
 * 1500 px photo). Made once with GD and kept in media/plg_system_bettersearch/thumbs; the name
 * changes when the original changes. A request spends at most a time budget making them — the
 * original is used meanwhile and the next request continues.
 *
 * Existing copies are found with file stats only (no image decoding): the name carries the
 * original's modification time and size, and a ".none" marker remembers that the original is not
 * larger than a copy would be.
 */

namespace Merserwis\Plugin\System\BetterSearch\Render;

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;

final class Thumbs
{
    public const DIR = 'media/plg_system_bettersearch/thumbs';

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    private float $budget;

    private float $spent = 0.0;

    private int $quality;

    private bool $absolute;

    /** @var array<string, array{0: string, 1: string}> results of this request */
    private array $memo = [];

    public function __construct(float $budget = 1.0, int $quality = 80, bool $absolute = false)
    {
        $this->budget   = $budget;
        $this->quality  = max(40, min(95, $quality));
        $this->absolute = $absolute;
    }

    /**
     * [src, srcset] of a thumbnail at the given CSS width (1x and 2x), or ['', ''] when the original
     * should be used.
     *
     * @return array{0: string, 1: string}
     */
    public function get(string $image, int $width): array
    {
        if ($width <= 0 || trim($image) === '' || !function_exists('imagewebp')) {
            return ['', ''];
        }

        return $this->memo[$image . '|' . $width] ??= $this->resolve($image, $width);
    }

    /** @return array{0: string, 1: string} */
    private function resolve(string $image, int $width): array
    {
        $file = $this->localImage($image);
        if ($file === null) {
            return ['', ''];
        }
        $mtime = @filemtime($file);
        $size  = @filesize($file);
        if (!$mtime || !$size) {
            return ['', ''];
        }

        $base = ($this->absolute ? rtrim(Uri::root(), '/') : Uri::root(true)) . '/' . self::DIR . '/';
        $dir  = JPATH_ROOT . '/' . self::DIR;
        // the path relative to the site: moving the site keeps the thumbnails
        $root = (string) realpath(JPATH_ROOT);
        $rel  = $root !== '' && str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
        $hash = substr(md5($rel . '|' . $mtime . '|' . $size . '|' . $this->quality), 0, 16);

        $set     = [];
        $missing = [];
        foreach ([$width, $width * 2] as $w) {
            $name = $hash . '-' . $w . '.webp';
            if (is_file($dir . '/' . $name)) {
                $set[$w] = $base . $name . ' ' . $w . 'w';
                continue;
            }
            if (is_file($dir . '/' . $hash . '-' . $w . '.none')) {
                // the original is not larger than this copy would be: it serves these screens itself
                break;
            }
            $missing[] = $w;
        }

        if ($missing) {
            // only now the image itself is looked at
            $info = @getimagesize($file);
            if (!$info || !in_array($info[2], self::TYPES, true) || $info[0] < 2 || $info[1] < 2) {
                return ['', ''];
            }
            $wanted = [];
            foreach ($missing as $w) {
                if ($w >= $info[0]) {
                    if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
                        @touch($dir . '/' . $hash . '-' . $w . '.none');
                    }
                    break;
                }
                $wanted[] = $w;
            }
            foreach ($this->make($file, $info, $hash, $wanted) as $w) {
                $set[$w] = $base . $hash . '-' . $w . '.webp ' . $w . 'w';
            }
        }

        // never offer a 2x without the 1x (the 1x is the src); a missing copy means "not made yet"
        if (!isset($set[$width])) {
            return ['', ''];
        }
        ksort($set);

        return [strtok($set[$width], ' '), implode(', ', $set)];
    }

    /** Deletes every thumbnail and marker; returns the number of files removed. */
    public static function clear(): int
    {
        $n = 0;
        foreach (glob(JPATH_ROOT . '/' . self::DIR . '/*.{webp,none}', GLOB_BRACE) ?: [] as $file) {
            $n += @unlink($file) ? 1 : 0;
        }

        return $n;
    }

    /** Absolute path of an image inside the site, or null (external URL, missing file, outside the site). */
    private function localImage(string $image): ?string
    {
        $url = HTMLHelper::cleanImageURL(trim($image))->url;
        if (preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $url)) {
            return null;
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $root = Uri::root(true);
        if ($root !== '' && str_starts_with($path, $root . '/')) {
            $path = substr($path, strlen($root));
        }
        $site = realpath(JPATH_ROOT);
        $file = realpath(JPATH_ROOT . '/' . ltrim($path, '/'));

        return $site && $file && str_starts_with($file, $site . DIRECTORY_SEPARATOR) && is_file($file)
            && preg_match('/\.(jpe?g|png|webp|gif)$/i', $file) ? $file : null;
    }

    /**
     * Makes the copies of the given widths (largest first, the smaller ones resampled from it, so
     * the original is decoded once). Returns the widths made; stops when the time budget is spent
     * or something fails.
     *
     * @param int[] $widths
     *
     * @return int[]
     */
    private function make(string $file, array $info, string $hash, array $widths): array
    {
        if (!$widths || $this->spent >= $this->budget) {
            return [];
        }
        // decoded image + copies must fit in the memory left to PHP
        $limit = $this->memoryLimit();
        if ($limit > 0 && memory_get_usage() + $info[0] * $info[1] * 5 + 16 * 1048576 > $limit) {
            return [];
        }
        $dir = JPATH_ROOT . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [];
        }

        $t0   = hrtime(true);
        $made = [];
        try {
            $src = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
                IMAGETYPE_PNG  => @imagecreatefrompng($file),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
                IMAGETYPE_GIF  => @imagecreatefromgif($file),
                default        => false,
            };
            if (!$src) {
                return [];
            }
            // photos taken sideways: browsers honour the EXIF orientation, GD does not
            if ($info[2] === IMAGETYPE_JPEG) {
                $angle = [3 => 180, 6 => -90, 8 => 90][$this->jpegOrientation($file)] ?? 0;
                if ($angle !== 0 && ($rotated = imagerotate($src, $angle, 0))) {
                    $src = $rotated;
                }
            }

            rsort($widths);
            $from = $src;
            foreach ($widths as $width) {
                $w      = imagesx($from);
                $h      = imagesy($from);
                $height = max(1, (int) round($h * $width / $w));
                $dst    = imagecreatetruecolor($width, $height);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                imagecopyresampled($dst, $from, 0, 0, 0, 0, $width, $height, $w, $h);

                // written under a temporary name first: a visitor never gets a half-written file
                $name = $hash . '-' . $width . '.webp';
                $tmp  = $dir . '/' . $name . '.' . bin2hex(random_bytes(4)) . '.tmp';
                $ok   = @imagewebp($dst, $tmp, $this->quality) && @rename($tmp, $dir . '/' . $name);
                if (!$ok) {
                    @unlink($tmp);
                    break;
                }
                $made[] = $width;
                $from   = $dst;
            }

            return $made;
        } catch (\Throwable $e) {
            return $made;
        } finally {
            $this->spent += (hrtime(true) - $t0) / 1e9;
        }
    }

    /** EXIF orientation of a JPEG read from the header (no exif extension needed). */
    private function jpegOrientation(string $file): int
    {
        $data = @file_get_contents($file, false, null, 0, 131072);
        if (!is_string($data) || !str_starts_with($data, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        $len = strlen($data);
        while ($pos + 4 <= $len && $data[$pos] === "\xFF") {
            $marker = ord($data[$pos + 1]);
            $size   = unpack('n', substr($data, $pos + 2, 2))[1];
            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                $tiff  = $pos + 10;
                $le    = substr($data, $tiff, 2) === 'II';
                $short = fn (int $o) => $o + 2 <= $len ? unpack($le ? 'v' : 'n', substr($data, $o, 2))[1] : 0;
                $long  = fn (int $o) => $o + 4 <= $len ? unpack($le ? 'V' : 'N', substr($data, $o, 4))[1] : 0;
                $ifd   = $tiff + $long($tiff + 4);
                $count = $short($ifd);
                for ($i = 0; $i < $count && $i < 200; $i++) {
                    $entry = $ifd + 2 + $i * 12;
                    if ($short($entry) === 0x0112) {
                        $value = $short($entry + 8);

                        return $value >= 1 && $value <= 8 ? $value : 1;
                    }
                }

                return 1;
            }
            if ($marker === 0xDA || $size < 2) {
                break;
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1073741824,
            'm'     => $number * 1048576,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
