<?php

use Rgasch\AutoScraper\FuzzyText;

it('finds a match when texts are identical', function () {
    $fuzzyText = new FuzzyText("This is a test", 1.0);
    $result = $fuzzyText->search("This is a test");
    expect($result)->toBeTrue();
});

it('does not find a match when texts are different', function () {
    $fuzzyText = new FuzzyText("This is a test", 1.0);
    $result = $fuzzyText->search("This is another test");
    expect($result)->toBeFalse();
});

it('does not find a match for completely different texts', function () {
    $fuzzyText = new FuzzyText("This is a test", 1.0);
    $result = $fuzzyText->search("Completely different text");
    expect($result)->toBeFalse();
});

it('finds a match for empty strings', function () {
    $fuzzyText = new FuzzyText("", 1.0);
    $result = $fuzzyText->search("");
    expect($result)->toBeTrue();
});

it('does not find a match when one string is empty', function () {
    $fuzzyText = new FuzzyText("This is a test", 1.0);
    $result = $fuzzyText->search("");
    expect($result)->toBeFalse();
});
