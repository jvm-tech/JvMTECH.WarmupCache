# JvMTECH.CacheWarmup Package for Neos CMS #
[![License](https://poser.pugx.org/jvmtech/warmupcache/license)](https://packagist.org/packages/jvmtech/warmupcache)

Warm up your website's cache by crawling URLs or sitemaps, and include lists of allowed and denied patterns.

## Installation
```
composer require jvmtech/warmupcache
```

## How to use it?
By default the specified urls/sitemaps will be just scanned. To be able to warmup all crawled urls the Queue need to be set up.
```
flow queue:setup warmupcache
```
Then execute one of described below commands with --add-to-queue parameter to add URLs to queue.

The last step is queue execution.
You can do it standard way executing one job after another:
```
./flow job:work warmupcache
watch ./flow queue:list # wait until warmupcache is zero
```
or you can use parallel execution like below (keep in mind that in this case you need to specify right limit (amount of jobs in queue divided by amount of processes you will use))
```
seq 4 | xargs -I{} -P4 sh -c "FLOW_CONTEXT=Your/Context ./flow job:work warmupcache --limit 100 --verbose"
```

## Commands

### cachewarmup:extracturls - crawling URL(s) to extract all hrefs.
Useful when we would like to cherry-pick which pages/subpages would be warmed up like for example subpages of product page.
Example usage (scanning 3 home page variants)
```
./flow cachewarmup:extracturls --urls=https://your.site/ch-de/institutional,https:/your.site/ch-de/pro,https://your.site/ch-de/private --add-to-queue
```

### cachewarmup:extracturlsfromsitemap - crawling sitemap to extract hrefs.
Useful when we would like to warmup most pages from the sitemap.
Example usage:
```
./flow cachewarmup:extracturlsfromsitemap --sitemaps=https://your.site/ch-de/pro/sitemap.xml --add-to-queue
./flow cachewarmup:extracturlsfromsitemap --preset=homepage
```


## Presets configuration
Both commands can be used with `--preset <preset_name>` parameter and use predefined settings that includes: 
* urls/sitemaps list, 
* allow list / deny list
* limits (how many pages should be loaded)
* additional urls settings (sufixes that would be added to pages matching specified pattern)

```
JvMTECH:
  WarmupCache:
    basicauth:
#      login: ''
#      pass: ''

    presets:
    
#      Example #1    
#      We want to warmup only subpages that are linked to from home page
#      as we don't want to scan all insights or news
#      skipping mailto & _Resources links
      'homepage':
        urls:
          - 'https://your.site/ch-de/pro/'
          - 'https://your.site/ch-de/private/'
        allowlist:
          # if values specified only pages matching pattern will be whitelisted
        denylist:
          - '/mailto\:/i'  # to exclude all "mailto:" links
          - '/\/_Resources\//i' # to exclude links containing "_Resources"
#        limit: 0

#      Example #2
#      Each shareclass page has 3 functional subpages that are not in sitemap,
#      but we want to have them cached - check addUrls section
      'products':
        sitemaps:
          - 'https://your.site/ch-de/pro/sitemap.xml'
          - 'https://your.site/ch-de/private/sitemap.xml'
        allowlist:
          - '/\/products\//i'
        denylist:
        limits: 0,0 # for each sitemap separated with comma (,)
        addUrls: # links finishing with -currencySymbol are extended with subpages
          '/prices.json': '/(-usd|-eur|-chf|-gbp)$/i'
          '/productdocuments': '/(-usd|-eur|-chf|-gbp)$/i'
          '/productdocumentssimple': '/(-usd|-eur|-chf|-gbp)$/i'

```

---

by [jvmtech.ch](https://jvmtech.ch)
