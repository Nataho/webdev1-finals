<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Small shared Twig filters used across the admin tables.
 */
final class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('middle_truncate', [$this, 'middleTruncate']),
        ];
    }

    /**
     * Collapses long values from the middle so both the start and the end
     * stay visible: "mayshel@estrerabotanicals.com" becomes "mays…icals.com".
     * Short values (and anything the head+tail wouldn't shorten meaningfully)
     * are returned untouched. Pair with a title="…" attribute to keep the
     * full value reachable on hover.
     */
    public function middleTruncate(string $value, int $head = 4, int $tail = 9): string
    {
        $length = mb_strlen($value);

        // collapsing must actually save meaningful space to be worth it
        if ($length <= $head + $tail + 2) {
            return $value;
        }

        return mb_substr($value, 0, $head).'…'.mb_substr($value, -$tail);
    }
}
