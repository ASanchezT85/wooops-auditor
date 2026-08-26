<?php
declare(strict_types=1);

namespace WooOps\Auditor\Audit;

/**
 * Severity levels, their ordering and their health-score penalty.
 */
final class Severity
{
    public const PASS     = 'PASS';
    public const INFO     = 'INFO';
    public const LOW      = 'LOW';
    public const MEDIUM   = 'MEDIUM';
    public const HIGH     = 'HIGH';
    public const CRITICAL = 'CRITICAL';

    /** Highest first. Used to order findings in every report. */
    public const ORDER = [
        self::CRITICAL,
        self::HIGH,
        self::MEDIUM,
        self::LOW,
        self::INFO,
        self::PASS,
    ];

    /** Documented in docs/CHECKS.md — heuristic, not science. */
    public const PENALTY = [
        self::PASS     => 0,
        self::INFO     => 0,
        self::LOW      => 2,
        self::MEDIUM   => 5,
        self::HIGH     => 12,
        self::CRITICAL => 25,
    ];

    public static function rank(string $severity): int
    {
        $i = array_search($severity, self::ORDER, true);
        return $i === false ? count(self::ORDER) : $i;
    }

    public static function penalty(string $severity): int
    {
        return self::PENALTY[$severity] ?? 0;
    }

    public static function isValid(string $severity): bool
    {
        return array_key_exists($severity, self::PENALTY);
    }

    /** The more severe of two levels. */
    public static function max(string $a, string $b): string
    {
        return self::rank($a) <= self::rank($b) ? $a : $b;
    }
}
