<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\DecimalFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Presentation helpers for numbers stored at a fixed scale.
 */
class FormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('decimal', DecimalFormatter::display(...)),
        ];
    }
}
