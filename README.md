# AutoScraper

1. [ ] **_AutoScraper is a PHP class designed to scrape web pages and extract data based on predefined rules. 
2. [ ] This README provides examples of how to use the class to capture a scraping definition and then reuse 
3. [ ] this definition to scrape other similar pages._**

AutoScraper is a port of the [Python AutoScraper](https://github.com/alirezamika/autoscraper) library by [Alireza Mika](https://github.com/alirezamika). 
It is intended to be compatible in its public API, but it contains some additions and changes to better fit the PHP ecosystem.

## Installation

To install the AutoScraper class, use Composer:

```bash
composer require rgasch/autoscraper
```

## Usage
Capturing a Scraping Definition
To capture a scraping definition, you need to provide a URL and a wishlist of items you want to scrape from the page

```php
<?php

require 'vendor/autoload.php';

use Rgasch\AutoScraper\AutoScraper;

$scraper = new AutoScraper();
$url = 'https://example.com/page-to-scrape';
$wishlist = ['Item 1', 'Item 2', 'Item 3'];

$result = $scraper->build($url, $wishlist);

if ($result) {
    echo "Scraping definition captured successfully.\n";
    $scraper->save('path/to/save/definition.json');
} else {
    echo "Failed to capture scraping definition.\n";
}
```

## Reusing the Scraping Definition
Once you have captured and saved a scraping definition, you can reuse it to scrape other similar pages.

```php
<?php

require 'vendor/autoload.php';

use Rgasch\AutoScraper\AutoScraper;

$scraper = new AutoScraper();
$scraper->load('path/to/save/definition.json');

$url = 'https://example.com/another-page-to-scrape';
$result = $scraper->getResultSimilar($url);

if (!empty($result)) {
    echo "Scraped data:\n";
    print_r($result);
} else {
    echo "No data found.\n";
}
```

## Methods

```php
build($url, $wishlist)
```


Captures a scraping definition based on the provided URL and wishlist.  
* $url: The URL of the page to scrape.
* $wishlist: An array of items you want to scrape from the page. Usually a single item suffices.

```php
save($filePath)
```

Saves the captured scraping definition to a file.  
* $filePath: The path to save the definition file.

```php
load($filePath)
````

Loads a previously saved scraping definition from a file.  
* $filePath: The path to the definition file.

```php
getResultSimilar($url)
````

Scrapes a page using the loaded scraping definition and returns the extracted data.  
* $url: The URL of the page to scrape.

```php
getCssSelector()
```

Returns the CSS selector after you have loaded a previously saved scraping definition.

## Test Commands
There are two tests commands that you can refer to in order to see actual use cases and to 
interactively test the AutoScraper class. These commands are:

```bash
php src/AutoScraperCaptureCommand.php
```
This file prompts you for a URL and the text you wish to scrape and then saves the resulting 
CSS selector definitions into a JSON file into the resource directory. 

```bash
php src/AutoScraperScrapeCommand.php
```
This file allows you to re-use a previously saved CSS selector definition to scrape a new URL.

## Tutorials
Refer to this [gist](https://gist.github.com/alirezamika/72083221891eecd991bbc0a2a2467673) for some advanced use 
cases and tutorials on how to use the AutoScraper class. This gist is (of course) based on the Python library, 
but it should illustrate how to use the PHP version as well.

Disclaimer: I have written some tests to verify the correctness of the PHP Library, but certainly haven't covered
all areas of the functionality. It *should* work, but no guarantees are given. Besides, this is open source, 
so you know what that means (hint: pull requests are welcome).

## License
This project is licensed under the MIT License.
