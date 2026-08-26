<?php
declare(strict_types=1);

namespace WooOps\Auditor\Support;

final class Format
{
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . ' hours';
        }
        return round($seconds / 86400, 1) . ' days';
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }

    public static function money(float $amount, string $currency): string
    {
        return number_format($amount, 2) . ' ' . $currency;
    }
}
