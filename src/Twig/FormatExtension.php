<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\DecimalFormatter;
use App\Util\Excerpt;
use App\Util\Initials;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Presentation helpers shared by the templates: fixed-scale numbers and avatar monograms.
 */
class FormatExtension extends AbstractExtension
{
    /**
     * @return TwigFilter[] the filters exposed to Twig
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('decimal', DecimalFormatter::display(...)),
            new TwigFilter('initials', Initials::of(...)),
            new TwigFilter('excerpt', Excerpt::of(...)),
        ];
    }
}
