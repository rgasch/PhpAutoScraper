<?php declare(strict_types=1);

namespace Rgasch\AutoScraper;

use GuzzleHttp\Client;
use Rgasch\AutoScraper\Helpers\UserAgentHelper;
use Rgasch\AutoScraper\Crawler;

use Safe\Exceptions\PcreException;

use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\parse_url;
use function Safe\preg_replace;

class AutoScraper
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $stackList;

    /**
     * @var array<string, string>
     */
    private array $requestHeaders;

    /**
     * AutoScraper constructor.
     *
     * @param array<int, array<string, mixed>>|null $stackList  An optional initial stack list.
     * @param string|null                           $userAgent  An optional user agent string.
     */
    public function __construct(?array $stackList = null, string $userAgent = null)
    {
        $this->stackList      = $stackList ?? [];
        $this->requestHeaders = [
            'User-Agent' => $userAgent ?? UserAgentHelper::getUserAgent()
        ];
    }

    /**
     * Loads the stack list from a JSON file.
     *
     * @param string $filePath The path to the JSON file.
     *
     * @throws \Safe\Exceptions\FilesystemException If the file cannot be read.
     * @throws \Safe\Exceptions\JsonException If the file content cannot be decoded.
     */
    public function load(string $filePath): void
    {
        $data = json_decode(file_get_contents($filePath), true);

        // for backward compatibility
        if (isset($data[0])) {
            $this->stackList = $data;
            return;
        }

        $this->stackList = $data['stack_list'];
    }

    /**
     * Saves the stack list to a JSON file.
     *
     * @param string $filePath The path to the JSON file.
     *
     * @throws \Safe\Exceptions\FilesystemException If the file cannot be written.
     */
    public function save(string $filePath): void
    {
        $data = ['stack_list' => $this->stackList];
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Generates a CSS selector string based on the stack list.
     *
     * @return string The generated CSS selector string.
     */
    public function getCssSelector(): string
    {
        $selectors = [];

        foreach ($this->stackList as $stack) {
            $selectorParts = [];
            foreach ($stack['content'] as $content) {
                $tag             = $content[0];
                $attrs           = $content[1];
                $attrSelector    = $this->buildAttributeSelector($attrs);
                $selectorParts[] = $tag . $attrSelector;
            }
            $selectors[] = implode(' > ', $selectorParts);
        }

        return implode(', ', $selectors);
    }

    /**
     * Retrieves a Crawler instance based on the provided URL or HTML content.
     *
     * @param string|null $url                  The URL to fetch HTML content from.
     * @param string|null $html                 The HTML content to use directly.
     * @param array<string, mixed> $requestArgs Additional request arguments.
     *
     * @return Crawler The Crawler instance.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     * @throws \Safe\Exceptions\PcreException If the regular expression fails.
     */
    public function getCrawler(?string $url = null, ?string $html = null, array $requestArgs = []): Crawler
    {
        if ($html) {
            $html = $this->normalize(html_entity_decode($html));

            return new Crawler($html);
        }

        $html = $this->fetchHtml($url, $requestArgs);
        $html = $this->normalize(html_entity_decode($html));

        return new Crawler($html);
    }

    /**
     * Retrieves the current stack list.
     *
     * @return array<int, array<string, mixed>> The current stack list.
     */
    public function getStackList(): array
    {
        return $this->stackList;
    }

    /**
     * Sets the stack list.
     *
     * @param array<int, array<string, mixed>> $stackList The stack list to set.
     */
    public function setStackList(array $stackList): void
    {
        $this->stackList = $stackList;
    }

    /**
     * Retrieves the current request headers.
     *
     * @return array<string, string> The current request headers.
     */
    private function getValidAttrs(Crawler $node): array
    {
        $keyAttrs = ['class', 'style'];
        $attrs    = [];

        foreach ($keyAttrs as $attr) {
            $value        = $node->attr($attr);
            $attrs[$attr] = $value ?? '';
        }

        return $attrs;
    }

    /**
     * Checks if a child node contains the specified text.
     *
     * @param Crawler $child         The child node to check.
     * @param string  $text          The text to match.
     * @param string  $url           The base URL for resolving relative URLs.
     * @param float   $textFuzzRatio The ratio for fuzzy text matching.
     *
     * @return bool True if the child node contains the specified text, false otherwise.
     */
    private function childHasText(Crawler $child, string $text, string $url, float $textFuzzRatio): bool
    {
        // Create new instance of child that uses our wrapper so that we have the class variables we use available
        $child = new Crawler($child->getNode(0));

        $childText = trim($child->text());

        if ($this->textMatch($text, $childText, $textFuzzRatio)) {
            $parentText = trim($child->ancestors()->first()->text());
            if ($childText === $parentText && $child->ancestors()->count() > 1) {
                return false;
            }

            $child->wantedAttr = null;

            return true;
        }

        $nonRecText = $this->getNonRecText($child);
        if ($this->textMatch($text, $nonRecText, $textFuzzRatio)) {
            $child->isNonRecText = true;
            $child->wantedAttr   = null;

            return true;
        }

        foreach ($this->getNodeAttributes($child) as $key => $value) {
            // Not needed, docblock tells us that this will always be a string
            // if (!is_string($value)) {
            //     continue;
            // }

            $value = trim($value);
            if ($this->textMatch($text, $value, $textFuzzRatio)) {
                $child->wantedAttr = $key;

                return true;
            }

            if (in_array($key, ['href', 'src'])) {
                $fullUrl = $this->urlJoin($url, $value);
                if ($this->textMatch($text, $fullUrl, $textFuzzRatio)) {
                    $child->wantedAttr = $key;
                    $child->isFullUrl  = true;

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fetches HTML content from the provided URL with optional request arguments.
     *
     * @param string $url                       The URL to fetch HTML content from.
     * @param array<string, mixed> $requestArgs Additional request arguments.
     *
     * @return string The fetched HTML content.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     */
    private function fetchHtml(string $url, array $requestArgs = []): string
    {
        $headers = $this->requestHeaders;
        if ($url) {
            $parsedUrl       = parse_url($url);
            $headers['Host'] = $parsedUrl['host'];
        }

        $userHeaders = $requestArgs['headers'] ?? [];
        $headers     = array_merge($headers, $userHeaders);
        unset($requestArgs['headers']);

        $client   = new Client();
        $response = $client->get($url, [
            'headers' => $headers,
            'query'   => $requestArgs
        ]);

        return (string)$response->getBody();
    }

    /**
     * Retrieves the attributes of a given node.
     *
     * @param Crawler $node The node to retrieve attributes from.
     *
     * @return array<string, string> An associative array of attribute names and values.
     */
    private function getNodeAttributes(Crawler $node): array
    {
        $attributes = [];
        $element    = $node->getNode(0);
        if ($element && $element->hasAttributes()) {
            foreach ($element->attributes as $attr) {
                $attributes[$attr->name] = $attr->value;
            }
        }

        return $attributes;
    }

    /**
     * Retrieves the child nodes that contain the specified text.
     *
     * @param Crawler $crawler       The crawler instance to search within.
     * @param string  $text          The text to match.
     * @param string  $url           The base URL for resolving relative URLs.
     * @param float   $textFuzzRatio The ratio for fuzzy text matching.
     *
     * @return array<int, Crawler> An array of child nodes that contain the specified text.
     */
    private function getChildren(Crawler $crawler, string $text, string $url, float $textFuzzRatio): array
    {
        $children = [];
        $crawler->filter('*')->each(function (Crawler $node) use (&$children, $text, $url, $textFuzzRatio) {
            if ($this->childHasText($node, $text, $url, $textFuzzRatio)) {
                $children[] = $node;
            }
        });

        return array_reverse($children);
    }

    /**
     * Builds the result list based on the provided URL, wanted list, wanted dictionary, and HTML content.
     *
     * @param string|null $url                                        The URL to fetch HTML content from.
     * @param array<int, string>|null  $wantedList                    The list of wanted items.
     * @param array<string, array<int, string>>|null  $wantedDict     The dictionary of wanted items.
     * @param string|null $html                                       The HTML content to use directly.
     * @param array<string, mixed> $requestArgs                       Additional request arguments.
     * @param bool        $update                                     Whether to update the stack list.
     * @param float       $textFuzzRatio                              The ratio for fuzzy text matching.
     *
     * @return array<int, string|mixed> The result list.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     */
    public function build(
        ?string $url = null,
        ?array  $wantedList = null,
        ?array  $wantedDict = null,
        ?string $html = null,
        array   $requestArgs = [],
        bool    $update = false,
        float   $textFuzzRatio = 1.0
    ): array {
        $crawler    = $this->getCrawler($url, $html, $requestArgs);
        $resultList = [];

        if (!$update) {
            $this->stackList = [];
        }

        if ($wantedList) {
            $wantedDict = ['' => $wantedList];
        }

        $wantedList = [];

        foreach ($wantedDict as $alias => $wantedItems) {
            $wantedItems = array_map([$this, 'normalize'], $wantedItems);
            $wantedList  = array_merge($wantedList, $wantedItems);

            foreach ($wantedItems as $wanted) {
                $children = $this->getChildren($crawler, $wanted, $url, $textFuzzRatio);

                foreach ($children as $child) {
                    [$result, $stack] = $this->getResultForChild($child, $crawler, $url);
                    $stack['alias']    = $alias;
                    $resultList        = array_merge($resultList, $result);
                    $this->stackList[] = $stack;
                }
            }
        }

        $resultList = array_map(function ($item) {
            return $item->text;
        }, $resultList);

        $resultList      = $this->uniqueHashable($resultList);
        $this->stackList = $this->uniqueStackList($this->stackList);

        return $resultList;
    }

    /**
     * Builds a stack array for a given child node and URL.
     *
     * @param Crawler $child The child node to build the stack for.
     * @param string  $url   The base URL for resolving relative URLs.
     *
     * @return array<string, mixed> The built stack array.
     */
    private function buildStack(Crawler $child, string $url): array
    {
        $content = [
            [
                $child->nodeName(),
                $this->getValidAttrs($child)
            ]
        ];

        $parent = $child;
        while (true) {
            $grandParent = $parent->ancestors()->first();
            if ($grandParent->count() === 0) {
                break;
            }

            $children = $grandParent->children()->filter(
                $parent->nodeName() . $this->buildAttributeSelector($this->getValidAttrs($parent))
            );

            $index = 0;
            $children->each(function (Crawler $node, $i) use ($parent, &$index) {
                if ($node->getNode(0) === $parent->getNode(0)) {
                    $index = $i;
                }
            });

            array_unshift($content, [
                $grandParent->nodeName(),
                $this->getValidAttrs($grandParent),
                $index
            ]);

            if ($grandParent->ancestors()->count() === 0) {
                break;
            }

            $parent = $grandParent;
        }

        $stack = [
            'content'         => $content,
            'wanted_attr'     => isset($child->wantedAttr) ? $child->wantedAttr : null,
            'is_full_url'     => isset($child->isFullUrl) ? $child->isFullUrl : false,
            'is_non_rec_text' => isset($child->isNonRecText) ? $child->isNonRecText : false,
            'url'             => isset($child->isFullUrl) && $child->isFullUrl ? $url : '',
        ];

        $stack['hash']     = hash('sha256', serialize($stack));
        $stack['stack_id'] = 'rule_' . $this->getRandomStr(4);

        return $stack;
    }

    /**
     * Builds a CSS attribute selector string based on the provided attributes.
     *
     * @param array<string, string> $attributes An associative array of attribute names and values.
     *
     * @return string The generated CSS attribute selector string.
     */
    private function buildAttributeSelector(array $attributes): string
    {
        $selector = '';
        foreach ($attributes as $key => $value) {
            if ($value !== '') {
                $selector .= sprintf('[%s="%s"]', $key, $value);
            }
        }

        return $selector;
    }

    /**
     * Retrieves the result list based on the provided stack, crawler, URL, and attribute fuzziness ratio.
     *
     * @param array<string, mixed> $stack             The stack array containing the content and attributes.
     * @param Crawler              $crawler           The crawler instance to search within.
     * @param string               $url               The base URL for resolving relative URLs.
     * @param float                $attrFuzzRatio     The ratio for fuzzy attribute matching.
     * @param array<string, mixed> $options           Additional options for result retrieval.
     *
     * @return array<int, object> The result list containing text and index.
     */
    private function getResultWithStack(
        array   $stack,
        Crawler $crawler,
        string  $url,
        float   $attrFuzzRatio,
        array   $options = []
    ): array {
        $parents              = [$crawler];
        $stackContent         = $stack['content'];
        $containSiblingLeaves = $options['contain_sibling_leaves'] ?? false;

        foreach ($stackContent as $index => $item) {
            if ($item[0] === '[document]') {
                continue;
            }

            $children = [];
            foreach ($parents as $parent) {
                $attrs = $item[1];
                if ($attrFuzzRatio < 1.0) {
                    $attrs = $this->getFuzzyAttrs($attrs, $attrFuzzRatio);
                }

                $selector = $item[0] . $this->buildAttributeSelector($attrs);
                $found    = $parent->filter($selector);

                if ($found->count() === 0) {
                    continue;
                }

                if (!$containSiblingLeaves && $index === count($stackContent) - 1) {
                    $idx   = min($found->count() - 1, $stackContent[$index - 1][2]);
                    $found = $found->eq($idx);
                }

                $found->each(function (Crawler $node) use (&$children) {
                    $children[] = $node;
                });
            }

            $parents = $children;
        }

        $wantedAttr   = $stack['wanted_attr'];
        $isFullUrl    = $stack['is_full_url'];
        $isNonRecText = $stack['is_non_rec_text'] ?? false;

        $result = [];
        foreach ($parents as $i => $node) {
            $text = $this->fetchResultFromChild($node, $wantedAttr, $isFullUrl, $url, $isNonRecText);
            if ($text || ($options['keep_blank'] ?? false)) {
                $result[] = (object)['text' => $text, 'index' => $i];
            }
        }

        return $result;
    }

    /**
     * Retrieves the result list based on the provided stack, crawler, URL, and attribute fuzziness ratio using index-based matching.
     *
     * @param array<string, mixed> $stack             The stack array containing the content and attributes.
     * @param Crawler              $crawler           The crawler instance to search within.
     * @param string               $url               The base URL for resolving relative URLs.
     * @param float                $attrFuzzRatio     The ratio for fuzzy attribute matching.
     * @param array<string, mixed> $options           Additional options for result retrieval.
     *
     * @return array<int, object> The result list containing text and index.
     */
    private function getResultWithStackIndexBased(
        array   $stack,
        Crawler $crawler,
        string  $url,
        float   $attrFuzzRatio,
        array   $options = []
    ): array {
        $p            = $crawler->filter('> *')->first();
        $stackContent = $stack['content'];

        for ($index = 0; $index < count($stackContent) - 1; $index++) {
            if ($stackContent[$index][0] === '[document]') {
                continue;
            }

            $content = $stackContent[$index + 1];
            $attrs   = $content[1];
            if ($attrFuzzRatio < 1.0) {
                $attrs = $this->getFuzzyAttrs($attrs, $attrFuzzRatio);
            }

            $selector = $content[0] . $this->buildAttributeSelector($attrs);
            $found    = $p->filter($selector);

            if ($found->count() === 0) {
                return [];
            }

            $idx = min($found->count() - 1, $stackContent[$index][2]);
            $p   = $found->eq($idx);
        }

        $text = $this->fetchResultFromChild(
            $p,
            $stack['wanted_attr'],
            $stack['is_full_url'],
            $url,
            $stack['is_non_rec_text'] ?? false
        );

        if (!$text && !($options['keep_blank'] ?? false)) {
            return [];
        }

        return [(object)['text' => $text, 'index' => 0]];
    }

    /**
     * Retrieves the result and stack for a given child node and crawler.
     *
     * @param Crawler $child   The child node to process.
     * @param Crawler $crawler The crawler instance to search within.
     * @param string  $url     The base URL for resolving relative URLs.
     *
     * @return array{array<int, object>, array<string, mixed>} The result list and the built stack array.
     */
    private function getResultForChild(Crawler $child, Crawler $crawler, string $url): array
    {
        $stack  = $this->buildStack($child, $url);
        $result = $this->getResultWithStack($stack, $crawler, $url, 1.0);
        if (count($result) === 0) {
            $result = $this->getResultWithStackIndexBased($stack, $crawler, $url, 1.0);
        }

        return [$result, $stack];
    }

    /**
     * Retrieves the result list based on the provided function, URL, HTML content, crawler, and additional options.
     *
     * @param callable             $func           The function to use for result retrieval.
     * @param string|null          $url            The URL to fetch HTML content from.
     * @param string|null          $html           The HTML content to use directly.
     * @param Crawler|null         $crawler        The crawler instance to search within.
     * @param array<string, mixed> $requestArgs    Additional request arguments.
     * @param bool                 $grouped        Whether to group the results.
     * @param bool                 $groupByAlias   Whether to group the results by alias.
     * @param bool|null            $unique         Whether to ensure unique results.
     * @param float                $attrFuzzRatio  The ratio for fuzzy attribute matching.
     * @param array<string, mixed> $options        Additional options for result retrieval.
     *
     * @return array<int|string, mixed> The result list or grouped result list.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     * @throws \Safe\Exceptions\FilesystemException If the file operations fail.
     */
    private function getResultByFunc(
        callable $func,
        ?string  $url = null,
        ?string  $html = null,
        ?Crawler $crawler = null,
        array    $requestArgs = [],
        bool     $grouped = false,
        bool     $groupByAlias = false,
        ?bool    $unique = null,
        float    $attrFuzzRatio = 1.0,
        array    $options = []
    ): array {
        if (!$crawler) {
            $crawler = $this->getCrawler($url, $html, $requestArgs);
        }

        $keepOrder = $options['keep_order'] ?? false;

        if ($groupByAlias || ($keepOrder && !$grouped)) {
            $index = 0;
            $crawler->filter('*')->each(function (Crawler $node) use (&$index) {
                $node->childIndex = $index++;
            });
        }

        $resultList    = [];
        $groupedResult = [];

        foreach ($this->stackList as $stack) {
            if (!$url) {
                $url = $stack['url'] ?? '';
            }

            $result = $func($stack, $crawler, $url, $attrFuzzRatio, $options);

            if (!$grouped && !$groupByAlias) {
                $resultList = array_merge($resultList, $result);
                continue;
            }

            $groupId = $groupByAlias ? ($stack['alias'] ?? '') : $stack['stack_id'];
            if (!isset($groupedResult[$groupId])) {
                $groupedResult[$groupId] = [];
            }
            $groupedResult[$groupId] = array_merge($groupedResult[$groupId], $result);
        }

        return $this->cleanResult(
            $resultList,
            $groupedResult,
            $grouped,
            $groupByAlias,
            $unique,
            $keepOrder
        );
    }

    /**
     * Cleans and processes the result list based on the provided options.
     *
     * @param array<int, object> $resultList                   The list of results to clean.
     * @param array<string, array<int, object>> $groupedResult The grouped result list.
     * @param bool               $grouped                      Whether the results are grouped.
     * @param bool               $groupedByAlias               Whether the results are grouped by alias.
     * @param bool|null          $unique                       Whether to ensure unique results.
     * @param bool               $keepOrder                    Whether to keep the original order of results.
     *
     * @return array<int|string, mixed> The cleaned and processed result list.
     */
    private function cleanResult(
        array $resultList,
        array $groupedResult,
        bool  $grouped,
        bool  $groupedByAlias,
        ?bool $unique,
        bool  $keepOrder
    ): array {
        if (!$grouped && !$groupedByAlias) {
            if ($unique === null) {
                $unique = true;
            }

            if ($keepOrder) {
                usort($resultList, function ($a, $b) {
                    return $a->index - $b->index;
                });
            }

            $result = array_map(function ($item) {
                return $item->text;
            }, $resultList);

            if ($unique) {
                $result = $this->uniqueHashable($result);
            }

            return $result;
        }

        foreach ($groupedResult as $k => $val) {
            if ($groupedByAlias) {
                usort($val, function ($a, $b) {
                    return $a->index - $b->index;
                });
            }

            $val = array_map(function ($item) {
                return $item->text;
            }, $val);

            if ($unique) {
                $val = $this->uniqueHashable($val);
            }

            $groupedResult[$k] = $val;
        }

        return $groupedResult;
    }

    /**
     * Retrieves the result list based on the provided function, URL, HTML content, crawler, and additional options.
     *
     * @param string|null          $url                  The URL to fetch HTML content from.
     * @param string|null          $html                 The HTML content to use directly.
     * @param Crawler|null         $crawler              The crawler instance to search within.
     * @param array<string, mixed> $requestArgs          Additional request arguments.
     * @param bool                 $grouped              Whether to group the results.
     * @param bool                 $groupByAlias         Whether to group the results by alias.
     * @param bool|null            $unique               Whether to ensure unique results.
     * @param float                $attrFuzzRatio        The ratio for fuzzy attribute matching.
     * @param bool                 $keepBlank            Whether to keep blank results.
     * @param bool                 $keepOrder            Whether to keep the original order of results.
     * @param bool                 $containSiblingLeaves Whether to include sibling leaves in the results.
     *
     * @return array<int|string, mixed> The result list or grouped result list.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     * @throws \Safe\Exceptions\FilesystemException If the file operations fail.
     */
    public function getResultSimilar(
        ?string  $url = null,
        ?string  $html = null,
        ?Crawler $crawler = null,
        array    $requestArgs = [],
        bool     $grouped = false,
        bool     $groupByAlias = false,
        ?bool    $unique = null,
        float    $attrFuzzRatio = 1.0,
        bool     $keepBlank = false,
        bool     $keepOrder = false,
        bool     $containSiblingLeaves = false
    ): array {
        return $this->getResultByFunc(
            [$this, 'getResultWithStack'],
            $url,
            $html,
            $crawler,
            $requestArgs,
            $grouped,
            $groupByAlias,
            $unique,
            $attrFuzzRatio,
            [
                'keep_blank'             => $keepBlank,
                'keep_order'             => $keepOrder,
                'contain_sibling_leaves' => $containSiblingLeaves
            ]
        );
    }

    /**
     * Retrieves the result list based on the provided function, URL, HTML content, crawler, and additional options.
     *
     * @param string|null          $url            The URL to fetch HTML content from.
     * @param string|null          $html           The HTML content to use directly.
     * @param Crawler|null         $crawler        The crawler instance to search within.
     * @param array<string, mixed> $requestArgs    Additional request arguments.
     * @param bool                 $grouped        Whether to group the results.
     * @param bool                 $groupByAlias   Whether to group the results by alias.
     * @param bool|null            $unique         Whether to ensure unique results.
     * @param float                $attrFuzzRatio  The ratio for fuzzy attribute matching.
     * @param bool                 $keepBlank      Whether to keep blank results.
     *
     * @return array<int|string, mixed> The result list or grouped result list.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     * @throws \Safe\Exceptions\FilesystemException If the file operations fail.
     */
    public function getResultExact(
        ?string  $url = null,
        ?string  $html = null,
        ?Crawler $crawler = null,
        array    $requestArgs = [],
        bool     $grouped = false,
        bool     $groupByAlias = false,
        ?bool    $unique = null,
        float    $attrFuzzRatio = 1.0,
        bool     $keepBlank = false
    ): array {
        return $this->getResultByFunc(
            [$this, 'getResultWithStackIndexBased'],
            $url,
            $html,
            $crawler,
            $requestArgs,
            $grouped,
            $groupByAlias,
            $unique,
            $attrFuzzRatio,
            ['keep_blank' => $keepBlank]
        );
    }

    /**
     * Retrieves the result list based on the provided function, URL, HTML content, crawler, and additional options.
     *
     * @param string|null          $url            The URL to fetch HTML content from.
     * @param string|null          $html           The HTML content to use directly.
     * @param array<string, mixed> $requestArgs    Additional request arguments.
     * @param bool                 $grouped        Whether to group the results.
     * @param bool                 $groupByAlias   Whether to group the results by alias.
     * @param bool|null            $unique         Whether to ensure unique results.
     * @param float                $attrFuzzRatio  The ratio for fuzzy attribute matching.
     *
     * @return array<int|string, mixed> The result list or grouped result list.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     * @throws \Safe\Exceptions\FilesystemException If the file operations fail.
     */
    public function getResult(
        ?string $url = null,
        ?string $html = null,
        array   $requestArgs = [],
        bool    $grouped = false,
        bool    $groupByAlias = false,
        ?bool   $unique = null,
        float   $attrFuzzRatio = 1.0
    ): array {
        $crawler = $this->getCrawler($url, $html, $requestArgs);

        $args = [
            'url'           => $url,
            'crawler'       => $crawler,
            'grouped'       => $grouped,
            'groupByAlias'  => $groupByAlias,
            'unique'        => $unique,
            'attrFuzzRatio' => $attrFuzzRatio
        ];

        $similar = $this->getResultSimilar(...$args);
        $exact   = $this->getResultExact(...$args);

        return [$similar, $exact];
    }

    /**
     * Retrieves the result from a child node based on the specified attributes and options.
     *
     * @param Crawler $child         The child node to process.
     * @param string|null $wantedAttr The attribute to retrieve from the child node.
     * @param bool    $isFullUrl     Whether to resolve the attribute value as a full URL.
     * @param string  $url           The base URL for resolving relative URLs.
     * @param bool    $isNonRecText  Whether to retrieve non-recursive text content.
     *
     * @return string|null The retrieved result or null if not found.
     *
     * @throws \Safe\Exceptions\FilesystemException If the file operations fail.
     */
    private function fetchResultFromChild(
        Crawler $child,
        ?string $wantedAttr,
        bool    $isFullUrl,
        string  $url,
        bool    $isNonRecText
    ): ?string {
        if ($wantedAttr === null) {
            if ($isNonRecText) {
                return $this->getNonRecText($child);
            }
            return trim($child->text());
        }

        $value = $child->attr($wantedAttr);
        if ($value === null) {
            return null;
        }

        if ($isFullUrl) {
            return $this->urlJoin($url, $value);
        }

        return $value;
    }

    /**
     * Retrieves the non-recursive text content from a given node.
     *
     * @param Crawler $node The node to retrieve non-recursive text content from.
     *
     * @return string The non-recursive text content.
     */
    private function getNonRecText(Crawler $node): string
    {
        $text = '';
        foreach ($node->getNode(0)->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->nodeValue;
            }
        }
        return trim($text);
    }

    /**
     * Normalizes the provided text by removing extra whitespace.
     *
     * @param string $text The text to normalize.
     *
     * @return string The normalized text.
     *
     * @throws \Safe\Exceptions\PcreException If the regular expression fails.
     */
    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Compares two strings and determines if they match based on the specified ratio.
     *
     * @param string $text1 The first text to compare.
     * @param string $text2 The second text to compare.
     * @param float  $ratio The ratio for fuzzy text matching (1.0 for exact match).
     *
     * @return bool True if the texts match based on the ratio, false otherwise.
     */
    private function textMatch(string $text1, string $text2, float $ratio): bool
    {
        if ($ratio === 1.0) {
            return $text1 === $text2;
        }

        return similar_text($text1, $text2) / max(strlen($text1), strlen($text2)) >= $ratio;
    }

    /**
     * Joins a base URL and a relative URL to form a complete URL.
     *
     * @param string $base The base URL.
     * @param string $url  The relative URL to join with the base URL.
     *
     * @return string The complete URL.
     *
     * @throws \Safe\Exceptions\UrlException If the URL parsing fails.
     */
    private function urlJoin(string $base, string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Generates a random string of the specified length.
     *
     * @param int $length The length of the random string to generate.
     *
     * @return string The generated random string.
     */
    private function getRandomStr(int $length): string
    {
        return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
    }

    /**
     * Adjusts the attributes for fuzzy matching based on the specified ratio.
     *
     * @param array<string, string> $attrs The attributes to adjust.
     * @param float $ratio The ratio for fuzzy attribute matching.
     *
     * @return array<string, mixed> The adjusted attributes with fuzzy matching applied.
     */
    private function getFuzzyAttrs(array $attrs, float $ratio): array
    {
        return array_map(function ($val) use ($ratio) {
            //return is_string($val) && $val ? new FuzzyText($val, $ratio) : $val;
            return $val ? new FuzzyText($val, $ratio) : $val;
        }, $attrs);
    }

    /**
     * Removes duplicate values from the provided list while preserving the order.
     *
     * @param array<int|string, mixed> $list The list to process.
     *
     * @return array<int|string, mixed> The list with unique values.
     */
    private function uniqueHashable(array $list): array
    {
        return array_values(array_unique($list));
    }

    /**
     * Removes duplicate stack items from the provided stack list while preserving the order.
     *
     * @param array<int, array<string, mixed>> $stackList The stack list to process.
     *
     * @return array<int, array<string, mixed>> The stack list with unique items.
     */
    private function uniqueStackList(array $stackList): array
    {
        $seen = [];
        return array_filter($stackList, function ($item) use (&$seen) {
            $hash = $item['hash'];
            if (isset($seen[$hash])) {
                return false;
            }
            $seen[$hash] = true;
            return true;
        });
    }

    /**
     * Removes stack items from the stack list based on the provided rules.
     *
     * @param array<int> $rules The rules to remove.
     */
    public function removeRules(array $rules): void
    {
        $this->stackList = array_filter($this->stackList, fn($x) => !in_array($x['stack_id'], $rules));
        $this->stackList = array_values($this->stackList);
    }

    /**
     * Keeps stack items from the stack list based on the provided rules.
     *
     * @param array<int> $rules The rules to keep.
     */
    public function keepRules(array $rules): void
    {
        $this->stackList = array_filter($this->stackList, fn($x) => in_array($x['stack_id'], $rules));
        $this->stackList = array_values($this->stackList);
    }

    /**
     * Sets the rule aliases for the stack list.
     *
     * @param array<int, string> $ruleAliases The rule aliases to set.
     */
    public function setRuleAliases(array $ruleAliases): void
    {
        foreach ($this->stackList as &$stackItem) {
            if (isset($ruleAliases[$stackItem['stack_id']])) {
                $stackItem['alias'] = $ruleAliases[$stackItem['stack_id']];
            }
        }
    }
}