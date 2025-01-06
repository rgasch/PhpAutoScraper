<?php

use Rgasch\AutoScraper\AutoScraper;
use Rgasch\AutoScraper\Crawler;

function invokePrivateMethod($object, $methodName, ...$args)
{
    $reflection = new ReflectionClass($object);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);
    return $method->invoke($object, ...$args);
}

it('fetches result from child node', function () {
    $scraper = new AutoScraper();
    $crawler = new Crawler('<div class="test">Test Content</div>');
    $child = $crawler->filter('.test')->first();

    $result = invokePrivateMethod($scraper, 'fetchResultFromChild', $child, null, false, '', false);
    expect($result)->toBe('Test Content');
});

it('fetches non-recursive text from node', function () {
    $scraper = new AutoScraper();
    $crawler = new Crawler('<div class="test"><span>Test</span>Content</div>');
    $node = $crawler->filter('.test')->first();

    $result = invokePrivateMethod($scraper, 'getNonRecText', $node);
    expect($result)->toBe('Content');
});

it('normalizes text', function () {
    $scraper = new AutoScraper();
    $text = "  This   is   a   test  ";

    $result = invokePrivateMethod($scraper, 'normalize', $text);
    expect($result)->toBe('This is a test');
});

it('matches text with given ratio', function () {
    $scraper = new AutoScraper();
    $text1 = "This is a test";
    $text2 = "This is a test";

    $result = invokePrivateMethod($scraper, 'textMatch', $text1, $text2, 1.0);
    expect($result)->toBeTrue();
});

it('joins URLs correctly', function () {
    $scraper = new AutoScraper();
    $base = "http://example.com";
    $url = "/path/to/resource";

    $result = invokePrivateMethod($scraper, 'urljoin', $base, $url);
    expect($result)->toBe('http://example.com/path/to/resource');
});

it('generates random string of given length', function () {
    $scraper = new AutoScraper();
    $length = 10;

    $result = invokePrivateMethod($scraper, 'getRandomStr', $length);
    expect(strlen($result))->toBe($length);
});

it('removes rules from stack list', function () {
    $scraper = new AutoScraper();
    $scraper->setStackList([
                               ['stack_id' => 'rule_1'],
                               ['stack_id' => 'rule_2'],
                           ]);

    invokePrivateMethod($scraper, 'removeRules', ['rule_1']);
    $stackList = $scraper->getStackList();
    expect($stackList)->toHaveCount(1);
    expect($stackList[0]['stack_id'])->toBe('rule_2');
});

it('keeps only specified rules in stack list', function () {
    $scraper = new AutoScraper();
    $scraper->setStackList([
                               ['stack_id' => 'rule_1'],
                               ['stack_id' => 'rule_2'],
                           ]);

    invokePrivateMethod($scraper, 'keepRules', ['rule_1']);
    $stackList = $scraper->getStackList();
    expect($stackList)->toHaveCount(1);
    expect($stackList[0]['stack_id'])->toBe('rule_1');
});

it('sets rule aliases correctly', function () {
    $scraper = new AutoScraper();
    $scraper->setStackList([
                               ['stack_id' => 'rule_1'],
                               ['stack_id' => 'rule_2'],
                           ]);

    invokePrivateMethod($scraper, 'setRuleAliases', ['rule_1' => 'alias_1']);
    $stackList = $scraper->getStackList();
    expect($stackList[0]['alias'])->toBe('alias_1');
});

it('scrapes a site and saves the results', function () {
    $scraper  = new AutoScraper();
    $url      = 'https://stackoverflow.com/questions/231767/what-does-the-yield-keyword-do-in-python';
    $wishlist = ['Why yield returns an iterator?'];

    $result   = $scraper->build($url, $wishlist);
    $host     = parse_url($url, PHP_URL_HOST);
    $type     = 'list';
    $filePath = __DIR__ . "/../../resources/{$host}_{$type}.json";

    $scraper->save($filePath);

    expect(file_exists($filePath))->toBeTrue();
    $savedData = json_decode(file_get_contents($filePath), true);
    expect($savedData['stack_list'][0]['content'])->toHaveCount(11);
});

it('loads a file and returns the correct CSS selector', function () {
    $scraper  = new AutoScraper();
    $url      = 'https://stackoverflow.com/questions/231767/what-does-the-yield-keyword-do-in-python';
    $host     = parse_url($url, PHP_URL_HOST);
    $type     = 'list';
    $filePath = __DIR__ . "/../../resources/{$host}_{$type}.json";
    expect(file_exists($filePath))->toBeTrue();
    $scraper->load($filePath);
    $cssSelector = $scraper->getCssSelector();
    expect($cssSelector)->toBe('html[class="html__responsive "] > body[class="question-page unified-theme"] > div[class="container"] > div[class="snippet-hidden"] > div > div[class="inner-content clearfix"] > div[class="show-votes"] > div[class="module sidebar-related"] > div[class="related js-gps-related-questions"] > div[class="spacer"] > a[class="question-hyperlink"]');
});

it('loads a file and scrapes successfully', function () {
    $scraper  = new AutoScraper();
    $url      = 'https://stackoverflow.com/questions/231767/what-does-the-yield-keyword-do-in-python';
    $host     = parse_url($url, PHP_URL_HOST);
    $type     = 'list';
    $filePath = __DIR__ . "/../../resources/{$host}_{$type}.json";

    expect(file_exists($filePath))->toBeTrue();
    $scraper->load($filePath);
    $result = $scraper->getResultSimilar($url);
    expect($result)->not->toBeEmpty();
    expect($result[0])->toBeString();
    expect($result[0])->toEqual('Why yield returns an iterator?');
    unlink($filePath);
});

it('throws an exception when loading a non-existing file', function () {
    $scraper  = new AutoScraper();
    $filePath = __DIR__ . "/../../resources/non_existing_file.json";

    expect(fn() => $scraper->load($filePath))->toThrow(\Exception::class);
});

