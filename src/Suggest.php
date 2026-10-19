<?php

declare(strict_types=1);

namespace TinyTank;

/**
 * "Did you mean…?" for error messages about an unknown name: the known name closest to a typo.
 */
final class Suggest
{
    /**
     * ` Did you mean "title"?` when one of $names is a likely typo of a known name (case aside, at
     * most two letters off, and less than half the word), otherwise ''.
     *
     * @param list<string>|array<string> $names the unknown names
     * @param list<string>|array<string> $known the valid ones
     */
    public static function didYouMean(array $names, array $known): string
    {
        foreach ($names as $name) {
            $best = null;
            $distance = PHP_INT_MAX;
            foreach ($known as $candidate) {
                $d = levenshtein(strtolower((string) $name), strtolower((string) $candidate));
                if ($d < $distance) {
                    [$best, $distance] = [(string) $candidate, $d];
                }
            }
            if ($best !== null && $distance <= 2 && $distance * 2 < strlen((string) $name)) {
                return count($names) > 1 ? " Did you mean \"{$best}\" for \"{$name}\"?" : " Did you mean \"{$best}\"?";
            }
        }

        return '';
    }
}
