<?php declare(strict_types=1);

require_once 'vendor/autoload.php';

use Rgasch\AutoScraper\AutoScraper;
use function Safe\parse_url;

function getUserInput(string $prompt = null): string|false
{
    return readline($prompt);
}


// Sample input: https://stackoverflow.com/questions/231767/what-does-the-yield-keyword-do-in-python
$url = getUserInput('Enter the URL you wish to scrape: ');

// The value you enter here will be used to build the filename the scrape definitions are saved to
$type = getUserInput('Enter the type of page this is (index, list, detail, etc.): ');

// Sample input: Why yield returns an iterator?
print ("Now enter the text(s) you wish to scrape from the page. When you are finished, enter an empty line:\n");
$wishlist = [];
do {
    $text = getUserInput();
    if ($text) {
        $wishlist[] = $text;
    }
} while ($text);


// Build scraper, scrape and save result
$scraper = new AutoScraper();
$result  = $scraper->build($url, $wishlist);
$host    = parse_url($url, PHP_URL_HOST);

$scraper->save(__DIR__ . "/../resources/{$host}_{$type}.json");


print ("The following entries were scraped:\n");
foreach ($result as $entry) {
    print ("  - $entry\n");
}
print ("\n");

print ("The CSS selector for these entries is:\n");
print ("  - " . $scraper->getCssSelector() . "\n");
print ("\n");

print ("Results saved to resources/{$host}_{$type}.json\n");



