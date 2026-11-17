<?php
declare(strict_types=1);

/**
 * FSRS-5 (Free Spaced Repetition Scheduler) mit den Standardgewichten.
 * Formeln nach https://github.com/open-spaced-repetition/fsrs4anki/wiki/The-Algorithm
 *
 * S = Stabilität in Tagen (nach S Tagen liegt die Erinnerungswahrscheinlichkeit bei 90 %)
 * D = Schwierigkeit 1..10
 * R = Erinnerungswahrscheinlichkeit (retrievability)
 * G = Bewertung 1 Nochmal, 2 Schwer, 3 Gut, 4 Einfach
 */
final class Fsrs
{
    public const W = [
        0.40255, 1.18385, 3.173, 15.69105, 7.1949, 0.5345, 1.4604, 0.0046, 1.54575, 0.1192,
        1.01925, 1.9395, 0.11, 0.29605, 2.2698, 0.2315, 2.9898, 0.51655, 0.6621,
    ];
    public const DECAY = -0.5;
    public const FACTOR = 19 / 81; // 0.9^(1/DECAY) - 1

    public static function retrievability(float $elapsedDays, float $s): float
    {
        return (1 + self::FACTOR * $elapsedDays / $s) ** self::DECAY;
    }

    /** Tage, bis R auf $retention gefallen ist. */
    public static function interval(float $s, float $retention): float
    {
        return $s / self::FACTOR * ($retention ** (1 / self::DECAY) - 1);
    }

    public static function initStability(int $g): float
    {
        return max(self::W[$g - 1], 0.1);
    }

    public static function initDifficulty(int $g): float
    {
        return self::clampD(self::W[4] - exp(self::W[5] * ($g - 1)) + 1);
    }

    public static function nextDifficulty(float $d, int $g): float
    {
        $delta = -self::W[6] * ($g - 3);
        $d1 = $d + $delta * (10 - $d) / 9;                                  // lineare Dämpfung
        $d2 = self::W[7] * self::initDifficulty(4) + (1 - self::W[7]) * $d1; // Rückkehr zum Mittel
        return self::clampD($d2);
    }

    public static function recallStability(float $d, float $s, float $r, int $g): float
    {
        $hard = $g === 2 ? self::W[15] : 1.0;
        $easy = $g === 4 ? self::W[16] : 1.0;
        return $s * (1 + exp(self::W[8]) * (11 - $d) * $s ** (-self::W[9])
            * (exp((1 - $r) * self::W[10]) - 1) * $hard * $easy);
    }

    public static function forgetStability(float $d, float $s, float $r): float
    {
        $long = self::W[11] * $d ** (-self::W[12]) * (($s + 1) ** self::W[13] - 1) * exp((1 - $r) * self::W[14]);
        $short = $s / exp(self::W[17] * self::W[18]);
        return max(0.1, min($long, $short));
    }

    /** Wiederholung am selben Tag (Lernphase). */
    public static function shortTermStability(float $s, int $g): float
    {
        return max(0.1, $s * exp(self::W[17] * ($g - 3 + self::W[18])));
    }

    private static function clampD(float $d): float
    {
        return min(10.0, max(1.0, $d));
    }
}
