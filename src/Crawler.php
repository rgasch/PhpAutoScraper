<?php declare(strict_types=1);

namespace Rgasch\AutoScraper;

use Symfony\Component\DomCrawler\Crawler as BaseCrawler;

// This class only exists to provide a Crawler instance which has the extra variables
// that AutoScraper wants to use/set on the Crawler instance.
class Crawler extends BaseCrawler
{
    public int     $childIndex   = 0;
    public bool    $isFullUrl    = false;
    public bool    $isNonRecText = true;
    public ?string $wantedAttr   = null;
}