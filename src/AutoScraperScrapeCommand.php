<?php

require_once 'vendor/autoload.php';

use Rgasch\AutoScraper\AutoScraper;
use function Safe\parse_url;

function getUserInput(string $prompt = null): string|false
{
    return readline($prompt);
}

// Sample input: https://stackoverflow.com/questions/231767/what-does-the-yield-keyword-do-in-python
$url = getUserInput('Enter the URL you wish to scrape: ');

// The value you enter here will be used to build the filename the scrape definitions are loaded from
$type = getUserInput('Enter the type of page this is (index, detail, etc.): ');
$host = parse_url($url, PHP_URL_HOST);
$file = __DIR__ . "/../resources/{$host}_{$type}.json";

if (!file_exists($file)) {
    // We do this here to generate a nice error message.
    // If you don't do this, an exception will be thrown that your program can then catch and process.
    die ("\nERROR: Unable to find definition file: $file\n");
}


// Build scraper, scrape and save result
$scraper = new AutoScraper();
$scraper->load($file);
$result = $scraper->getResultSimilar($url);

print ("\n");
print ("The following definition file was used:\n");
print ("  - $file\n");
print ("\n");

print ("The following entries were scraped:\n");
foreach ($result as $entry) {
    print ("  - $entry\n");
}
print ("\n");

print ("The CSS selector for these entries is:\n");
print ("  - " . $scraper->getCssSelector() . "\n");
print ("\n");
