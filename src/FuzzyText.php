<?php declare(strict_types=1);

namespace Rgasch\AutoScraper;

class FuzzyText
{
    private int $matchCount;

    public function __construct(
        private readonly string $text,
        private readonly float $ratioLimit)
    {
    }

    public function getMatchCount(): int
    {
        return $this->matchCount;
    }

    public function search(string $text): bool
    {
        return $this->sequenceMatcherRatio($this->text, $text) >= $this->ratioLimit;
    }

    private function sequenceMatcherRatio(string $a, string $b): float
    {
        if (!strlen($a) && !strlen($b)) {
            return 1.0;
        }

        $this->matchCount = similar_text($a, $b, $percent);

        return $percent / 100;
    }
}
