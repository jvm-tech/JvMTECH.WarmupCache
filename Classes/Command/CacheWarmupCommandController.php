<?php

namespace JvMTECH\WarmupCache\Command;

use Flowpack\JobQueue\Common\Job\JobManager;
use JvMTECH\WarmupCache\Job\UrlRequestJob;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use Neos\Flow\Core\Booting\Scripts;

class CacheWarmupCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var JobManager
     */
    protected $jobManager;

    private $selectedPreset = null;

    private $subpagesOnly = false;
    private $siblingsOnly = false;

    private $addToQueue = false;

    private $extractedUrls = [];

    /**
     * @Flow\InjectConfiguration(path="basicauth")
     * @var array
     */
    protected $basicauth;

    /**
     * @Flow\InjectConfiguration(path="presets")
     * @var array
     */
    protected $presets;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow")
     * @var array
     */
    protected $flowSettings;

    protected function _getClientConfig():array
    {
        $clientConfig = [];
        if ($this->basicauth && !empty($this->basicauth['login']) && !empty($this->basicauth['pass']))
            $clientConfig['auth'] = [$this->basicauth['login'], $this->basicauth['pass']];
        return $clientConfig;
    }

    /**
     * Run all in yaml configured presets.
     *
     * @param bool $addToQueue
     * @param bool $outputResults
     * @return void
     */
    public function runAllPresetsCommand(bool $addToQueue = false, bool $outputResults = false)
    {
        $this->output->outputLine('Running all configured presets');

        foreach ($this->presets as $presetName => $presetConfig) {
            $commandIdentifier = '';
            if (array_key_exists('sitemaps', $presetConfig)) {
                $commandIdentifier = 'jvmtech.warmupcache:cachewarmup:extracturlsfromsitemap';
            }

            if (array_key_exists('urls', $presetConfig)) {
                $commandIdentifier = 'jvmtech.warmupcache:cachewarmup:extracturls';
            }

            if ($commandIdentifier) {
                $this->output->outputLine('Running preset: ' . $presetName . ' with command: ' . $commandIdentifier);

                try {
                    Scripts::executeCommand(
                        $commandIdentifier,
                        $this->flowSettings,
                        $outputResults,
                        ['preset' => $presetName, 'addToQueue' => $addToQueue]
                    );
                } catch (\Exception $e) {
                    $this->outputLine('Error: ' . $e->getMessage());
                }
            }
        }

        $this->output->outputLine('All configured presets run');
    }

    /**
     * Crawls urls to extract all hrefs.
     * *
     * @param string $preset Preset name with settings
     * @param string $urls The URLs list to crawl, separated with comma (,)
     * @param string $limits Limit extracted urls. Format: ,,2,5 - empty is 0.
     * @param bool $subpages Extract links only to subpages.
     * @param bool $siblings Extract links to siblings pages (& subpages).
     * @param bool $verbose If should return info about extracted urls.
     * @param bool $addToQueue If should add to queue.
     * @return void
     */
    public function extractUrlsCommand(string $preset='', string $urls='', string $limits = '', bool $subpages = false,
                                       bool $siblings = false, bool $verbose = false, bool $addToQueue = false): void
    {
        $this->selectedPreset = $preset;

        $this->urlList = [];
        $this->limits = '';
        $this->verbose = $verbose;

        $this->subpagesOnly = $subpages;
        $this->siblingsOnly = $siblings;
        $this->addToQueue = $addToQueue;

        $this->httpClient = new Client($this->_getClientConfig());

        $this->settings = [
            "whitelist" => [

            ],
            "blacklist" => [
            ]
        ];

        if (!empty($preset) && $this->presets[$preset]) {
            $this->settings['whitelist'] = $this->presets[$preset]['allowlist'] ?? [];
            $this->settings['blacklist'] = $this->presets[$preset]['denylist'] ?? [];
            $this->urlList = $this->presets[$preset]['urls'] ?? [];
            $this->limits = $this->presets[$preset]['limits'] ?? '';
        }

        if (empty($this->urlList) && !is_null($urls)) {
            $this->urlList = explode(',', $urls);
        }

        if (empty($this->limits) && !is_null($limits)) {
            $this->limits = $limits;
        }

        $limitsList = $this->_prepareLimits($this->limits, $this->urlList);

        foreach ($this->urlList as $id => $url) {
            var_dump($url);
            $this->url = $url;
            $this->baseUrl = $this->getBaseUrl($url);
            $this->limit = $limitsList[$id];

            if ($this->verbose) {
                $this->outputLine("\nFetching content from: %s", [$url]);
            }

            // Make the HTTP request
            $this->httpClient->requestAsync('GET', $url)
                ->then(
                    function (ResponseInterface $response) {
                        $html = (string)$response->getBody();
                        $this->extractLinks($html, $this->baseUrl, $this->settings["whitelist"], $this->settings["blacklist"], $this->limit, $this->subpagesOnly, $this->siblingsOnly);
                    },
                    function (RequestException $e) {
                        $this->outputLine('Error: ' . $e->getMessage());
                    }
                )->wait();
        }

        // expanding links
        if (isset($this->presets[$this->selectedPreset]['addUrls']) && is_array($this->presets[$this->selectedPreset]['addUrls']) && count($this->presets[$this->selectedPreset]['addUrls']))
            $this->extractedUrls = $this->addSubpages($this->presets[$this->selectedPreset]['addUrls'], $this->extractedUrls);

        $this->extractedUrls = array_values(array_unique($this->extractedUrls));

        $this->outputLine("\n\n");
        foreach ($this->extractedUrls as $id => $link) {
            if ($this->addToQueue) {
                $this->jobManager->queue("warmupcache", new UrlRequestJob($link));
            }
            if (!$this->verbose) {
                $this->outputLine($id . '. ' . $link);
            }
        }
    }

    /**
     * Crawls the sitemaps to extract URLs.
     *
     * @param string $sitemaps The URL list of the sitemaps to crawl, separated with comma (,)
     * @param string $preset Preset name with settings
     * @param string $limits Limit extracted urls. Format: ,,2,5 - empty is 0.
     * @param bool $verbose If should return info about extracted urls.
     * @param bool $addToQueue If should add to queue.
     * @return void
     */
    public function extractUrlsFromSitemapCommand(string $sitemaps = null, string $preset = null, string $limits = '', bool $verbose = false, bool $addToQueue = false)
    {
        $this->selectedPreset = $preset;

        $this->urlList = [];
        $this->limits = '';
        $this->verbose = $verbose;
        $this->addToQueue = $addToQueue;


        $this->httpClient = new Client($this->_getClientConfig());

        $this->settings = [
            "whitelist" => [
            ],
            "blacklist" => [
            ]
        ];

        if (!empty($preset) && $this->presets[$preset]) {
            $this->settings['whitelist'] = $this->presets[$preset]['allowlist'] ?? [];
            $this->settings['blacklist'] = $this->presets[$preset]['denylist'] ?? [];
            $this->urlList = $this->presets[$preset]['sitemaps'] ?? [];
            $this->limits = $this->presets[$preset]['limits'] ?? '';
        }

        if (empty($this->urlList) && !is_null($sitemaps)) {
            $this->urlList = explode(',', $sitemaps);
        }

        if (empty($this->limits) && !is_null($limits)) {
            $this->limits = $limits;
        }

        $limitsList = $this->_prepareLimits($this->limits, $this->urlList);
        foreach ($this->urlList as $id => $sitemapUrl) {
            $this->limit = $limitsList[$id];

            // Fetch the sitemap content
            try {
                $response = $this->httpClient->request('GET', $sitemapUrl);
                $body = (string)$response->getBody();
            } catch (RequestException $e) {
                $this->outputLine('Error fetching sitemap: ' . $e->getMessage());
//                return;
            }

            // Parse the sitemap content
            $this->extractLinksFromSitemap($body, $this->settings["whitelist"], $this->settings["blacklist"], $this->limit);
        }
        $this->extractedUrls = array_values(array_unique($this->extractedUrls));

        $this->outputLine("\n\n");
        foreach ($this->extractedUrls as $id => $link) {
            if ($this->addToQueue) {
                $this->jobManager->queue("warmupcache", new UrlRequestJob($link));
            }
            if (!$this->verbose) {
                $this->outputLine($id . '. ' . $link);
            }
        }
    }

    /**
     * Extracts URLs from the sitemap content
     * *
     * @param string $sitemapContent
     * @param array $whitelist
     * @param array $blacklist
     * @param int $limit
     * @return array An array of URLs
     */
    private function extractLinksFromSitemap(string $sitemapContent, array $whitelist = [], array $blacklist = [], int $limit = 0): array
    {
        $acceptedLinks = [];
        $sitemapXml = simplexml_load_string($sitemapContent);

        if ($sitemapXml === false) {
            $this->outputLine('Error parsing the sitemap XML.');
            return $acceptedLinks;
        }

        // Detect if it's a sitemap index
        if (isset($sitemapXml->sitemap)) {
            foreach ($sitemapXml->sitemap as $sitemap) {
                $childSitemapUrl = trim((string)$sitemap->loc);
                // Here you might want to call crawlSitemapCommand recursively,
                // or fetch and parse the child sitemap depending on your needs.
                // For simplicity, a recursive call is used but not sure if it will work
                $this->extractUrlsFromSitemapCommand($childSitemapUrl);
            }
        } else {
            $no = 0;
            // Regular sitemap with URLs
            foreach ($sitemapXml->url as $url) {
                $href = trim((string)$url->loc);

                $isWhitelisted = empty($whitelist) || $this->isLinkAllowed($href, $whitelist);
                $isBlacklisted = !empty($blacklist) && $this->isLinkAllowed($href, $blacklist);

                if ($isWhitelisted && !$isBlacklisted) {
                    if (!$limit || ($limit>$no++)) {
                        $acceptedLinks[] = $href;
                    }
                }
            }
        }

        $this->extractedUrls = array_merge($this->extractedUrls, $acceptedLinks);

        // expanding links
        if (isset($this->presets[$this->selectedPreset]['addUrls']) && is_array($this->presets[$this->selectedPreset]['addUrls']) && count($this->presets[$this->selectedPreset]['addUrls']))
            $this->extractedUrls = $this->addSubpages($this->presets[$this->selectedPreset]['addUrls'], $this->extractedUrls);

        return $acceptedLinks;
    }

    /**
     * @param string $limitsStr
     * @param array $urls
     * @return array
     */
    private function _prepareLimits(string $limitsStr, array $urls): array
    {
        $limits = array_map(function($value){
           return $value === "" ? 0 : (int) $value;
        }, explode(',', $limitsStr));

        return array_replace(array_fill(0, count($urls), 0), $limits);
    }

    /**
     * Parses the HTML content and extracts links, converting relative URLs to absolute.
     * * Filters out unwanted URLs based on specified criteria.
     * *
     * @param string $html The HTML content to parse
     * @param string $base The base URL of the crawled webpage
     * @param array $whitelist List of allowed URL patterns
     * @param array $blacklist List of disallowed URL patterns
     * @param int $limit
     * @param bool $subpagesOnly
     * @param bool $siblingsOnly
     * @return void
     */
    private function extractLinks(string $html, string $base, array $whitelist = [], array $blacklist = [], int $limit = 0, bool $subpagesOnly = false, bool $siblingsOnly = false): void
    {
        // Parse the HTML content
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $links = $dom->getElementsByTagName('a');

        if ($this->verbose) {
            $this->outputLine('Links found: ' . count($links));
        }

        $acceptedLinks = [];
        foreach ($links as $link) {
            // Extract the href value of each link
            $href = $this->stripAnchorFromUrl($link->getAttribute('href'));

            if (empty($href)) {
                continue;
            }

            // Convert relative links to absolute
            $href = $this->relativeToAbsoluteUrl($href, $base);

            // Skip external links
            if (strpos($href, $base) !== 0) {
//                var_dump('skipping: ' .$href . ', (' . $base . ')');
                continue;
            }

            if ($siblingsOnly) {
                $pathSegments = explode('/', $this->url);
                array_pop($pathSegments);
                $siblingsParent = implode('/', $pathSegments);

                if (strpos($href, $siblingsParent) !== 0)
                    continue;
            }

            if ($subpagesOnly) {
                if (strpos($href, $this->url) !== 0 || ($href === $this->url))
                    continue;
            }

            // Filter links based on the whitelist and blacklist
            $isWhitelisted = empty($whitelist) || $this->isLinkAllowed($href, $whitelist);
            $isBlacklisted = !empty($blacklist) && $this->isLinkAllowed($href, $blacklist);

            if ($isWhitelisted && !$isBlacklisted) {
                $acceptedLinks[] = $href;
            }
        }

        $uniqueLinks = array_values(array_unique($acceptedLinks));

        if ($this->verbose) {
            $this->outputLine('Links accepted: ' . count($acceptedLinks) . " unique: " . count($uniqueLinks));
            if ($limit) {
                $this->outputLine('Limited to: ' . $limit);
            }
        }

        foreach ($uniqueLinks as $id => $link) {
            if (!$limit || ($limit>$id)) {
                $this->extractedUrls[] = $link;

                if ($this->verbose) {
                    $this->outputLine($id . '. ' . $link);
                }
            }
        }
    }

    /**
     * @param string $url
     * @return string
     */
    function stripAnchorFromUrl(string $url): string
    {
        // Parse the URL to get its components
        $parsedUrl = parse_url($url);

        // Rebuild the URL without the 'fragment' part
        $urlWithoutFragment = (isset($parsedUrl['scheme']) ? "{$parsedUrl['scheme']}://" : '') .
            (isset($parsedUrl['user']) ? "{$parsedUrl['user']}" : '') .
            (isset($parsedUrl['pass']) ? ":{$parsedUrl['pass']}" : '') .
            (isset($parsedUrl['user']) ? "@" : '') .
            (isset($parsedUrl['host']) ? "{$parsedUrl['host']}" : '') .
            (isset($parsedUrl['port']) ? ":{$parsedUrl['port']}" : '') .
            (isset($parsedUrl['path']) ? "{$parsedUrl['path']}" : '') .
            (isset($parsedUrl['query']) ? "?{$parsedUrl['query']}" : '');

        return $urlWithoutFragment;
    }

    /**
     * @param string $url
     * @return string
     */
    function getBaseUrl(string $url): string {
        // Parse the URL and return the scheme and host
        $parsedUrl = parse_url($url);
        $baseUrl = (isset($parsedUrl['scheme']) ? "{$parsedUrl['scheme']}://" : '') .
            (isset($parsedUrl['host']) ? "{$parsedUrl['host']}" : '');

        return $baseUrl;
    }

    /**
     * Converts a relative URL to an absolute URL.
     * *
     * @param string $url The URL to convert
     * @param string $base The base URL
     * @return string The absolute URL
     */
    private function relativeToAbsoluteUrl(string $url, string $base): string
    {
        // If URL seems to be absolute, return as is
        if (parse_url($url, PHP_URL_SCHEME) != '') {
            return $url;
        }

        // If URL starts with a slash, append to base URL (without path)
        if (strpos($url, '/') === 0) {
            return rtrim(parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST), '/') . $url;
        }

        // Else append to base URL (with path)
        $path = parse_url($base, PHP_URL_PATH);
        $path = substr($path, 0, strrpos($path, '/'));
        return rtrim($base, '/') . $path . '/' . ltrim($url, '/');
    }

    /**
     * Determines if the link matches any pattern in the provided list.
     * *
     * @param string $link The link to check
     * @param array $patterns List of patterns to match against
     * @return bool TRUE if link matches any pattern, FALSE otherwise
     */
    private function isLinkAllowed(string $link, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $link)) {
                return true;
            }
        }
        return false;
    }


    /**
     * Adds a subpage to each link in the list that matches a specific pattern.
     * *
     * @param array $links The original list of links.
     * @param string $pattern The pattern to match for each link.
     * @param string $subpage The subpage to add to matching links.
     * @return array The updated list of links with added subpages for matches.
     */
    function addSubpageToMatchingLinks(array $links, string $pattern, string $subpage): array {
        $updatedLinks = $links; // Copy the original array to avoid modifying the input directly

        foreach ($links as $link) {
            if (preg_match($pattern, $link)) {
                $newLink = $link . $subpage;
                $updatedLinks[] = $newLink;
            }
        }

        return $updatedLinks;
    }

    /**
     * @param array|null $settings
     * @param array $_urls
     * @return array
     */
    function addSubpages(array $settings = null, array $_urls = []): array
    {
        $urls = $_urls;
        if (is_array($settings) && count($settings)) {
            foreach ($settings as $subpage => $pattern) {
                $urls = $this->addSubpageToMatchingLinks($urls, $pattern, $subpage);
            }
        }
        return $urls;
    }
}
