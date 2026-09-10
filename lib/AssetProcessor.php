<?php

require_once __DIR__ . '/SafePcre.php';

class AssetProcessor
{
    private string $sourceDomain;
    private string $baseUrl;
    private array $downloaded = [];
    private array $cssMap = [];
    private array $failed = [];
    private array $failedAssets = [];
    private int $timeout = 30;
    private ?R2Storage $storage = null;
    private string $storagePrefix = 'clones/';
    private string $mediaMode = 'base64';

    public function __construct(string $sourceDomain, ?array $storageConfig = null, string $storagePrefix = 'clones/')
    {
        SafePcre::bootstrap();
        $this->sourceDomain = $sourceDomain;
        $this->baseUrl = "https://{$sourceDomain}";
        $this->storagePrefix = rtrim($storagePrefix, '/') . '/';

        if ($storageConfig) {
            $this->mediaMode = $storageConfig['media_mode'] ?? 'base64';
            if ($this->mediaMode === 'r2' && !empty($storageConfig['enabled'])) {
                require_once __DIR__ . '/R2Storage.php';
                $r2 = new R2Storage($storageConfig);
                if ($r2->isConfigured()) $this->storage = $r2;
            }
        }
    }

    public function getFailedAssets(): array
    {
        return array_values(array_unique($this->failedAssets));
    }

    public function processHtml(string $html): string
    {
        SafePcre::bootstrap();
        $html = $this->fixLazyLoading($html);
        $html = $this->downloadAndInlineCss($html);
        $html = $this->inlineStyleTagUrls($html);
        $html = $this->inlineInlineStyleUrls($html);
        $html = $this->downloadAndInlineScripts($html);
        $html = $this->rewriteImageUrls($html);
        $html = $this->processSrcset($html);
        $html = $this->processPictureSources($html);
        $html = $this->processVideoPosters($html);
        $html = $this->processDataAttributes($html);
        $html = $this->processSvgImages($html);
        $html = $this->processLinkAssets($html);
        $html = $this->fixBackgroundImages($html);
        $html = $this->addFontAwesomeCdn($html);
        $html = $this->addGoogleFontsCdn($html);
        return $html;
    }

    public function processForZip(string $html, string $assetsDir): string
    {
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0777, true);
        }

        $html = $this->processHtml($html);
        $html = $this->downloadRemainingAssets($html, $assetsDir);
        return $html;
    }

    private function downloadRemainingAssets(string $html, string $assetsDir): string
    {
        $allUrls = [];

        preg_match_all('/<source\b[^>]*src=["\']([^"\']+)["\']/i', $html, $m);
        $allUrls = array_merge($allUrls, $m[1] ?? []);

        preg_match_all('/srcset=["\']([^"\']+)["\']/i', $html, $m);
        foreach ($m[1] ?? [] as $srcset) {
            $parts = self::splitSrcset($srcset);
            foreach ($parts as $part) {
                $part = trim($part);
                if (preg_match('/^(\S+)/', $part, $sm)) $allUrls[] = $sm[1];
            }
        }

        preg_match_all('/src="([^"]+)"/i', $html, $m);
        foreach ($m[1] ?? [] as $src) {
            if (strpos($src, 'data:') !== 0 && strpos($src, '#') !== 0 && strpos($src, 'proxy.php') === false) {
                $allUrls[] = $src;
            }
        }

        preg_match_all('/<meta\b[^>]*content=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp|ico)[^"\']*)["\']/i', $html, $m);
        foreach ($m[1] ?? [] as $url) {
            if (strpos($url, 'http') === 0 || strpos($url, '/') === 0) $allUrls[] = $url;
        }

        preg_match_all('/<link\b[^>]*href=["\']([^"\']+\.(?:png|jpg|jpeg|gif|webp|ico)[^"\']*)["\']/i', $html, $m);
        foreach ($m[1] ?? [] as $url) $allUrls[] = $url;

        $urlMap = [];
        foreach (array_unique($allUrls) as $url) {
            if (isset($urlMap[$url])) continue;
            $resolved = $this->resolveUrl($url);
            if (strpos($resolved, 'data:') === 0) continue;
            $host = parse_url($resolved, PHP_URL_HOST) ?? '';
            if ($host !== $this->sourceDomain && !str_ends_with($host, '.' . $this->sourceDomain)) continue;

            $content = $this->fetchUrl($resolved);
            if ($content === null) { $urlMap[$url] = $url; continue; }

            $path = parse_url($resolved, PHP_URL_PATH);
            $basename = basename($path);
            $basename = SafePcre::replace('/[^a-zA-Z0-9._-]/', '_', $basename);
            if (empty($basename) || $basename === '_') $basename = md5($url) . '.bin';

            $safeName = $basename;
            $counter = 0;
            while (file_exists($assetsDir . '/' . $safeName)) {
                $counter++;
                $ext = pathinfo($basename, PATHINFO_EXTENSION);
                $name = pathinfo($basename, PATHINFO_FILENAME);
                $safeName = $name . '_' . $counter . ($ext ? '.' . $ext : '');
            }

            file_put_contents($assetsDir . '/' . $safeName, $content);
            $urlMap[$url] = 'assets/' . $safeName;
        }

        foreach ($urlMap as $original => $local) {
            if ($original === $local) continue;
            $escaped = preg_quote($original, '/');
            $html = SafePcre::replace('#' . $escaped . '#', $local, $html);
        }

        return $html;
    }

    public function rewriteRemainingUrls(string $html, string $assetsDir): string
    {
        $cssFiles = glob($assetsDir . '/*.css');
        foreach ($cssFiles as $cssFile) {
            $css = file_get_contents($cssFile);
            $rewritten = false;

            $css = SafePcre::replaceCallback('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', function($m) use ($assetsDir, &$rewritten) {
                $url = $m[1];
                if (strpos($url, 'data:') === 0 || strpos($url, '#') === 0) return $m[0];

                $resolved = $this->resolveUrl($url);

                $path = parse_url($resolved, PHP_URL_PATH);
                $basename = basename($path);
                $basename = SafePcre::replace('/[^a-zA-Z0-9._-]/', '_', $basename);
                if (empty($basename) || $basename === '_') $basename = md5($url) . '.bin';

                $existingFile = null;
                foreach (glob($assetsDir . '/*') as $f) {
                    if (basename($f) === $basename) { $existingFile = $f; break; }
                }

                if ($existingFile) {
                    $rewritten = true;
                    return "url('../assets/" . basename($existingFile) . "')";
                }

                $content = $this->fetchUrl($resolved);
                if ($content) {
                    file_put_contents($assetsDir . '/' . $basename, $content);
                    $rewritten = true;
                    return "url('../assets/" . $basename . "')";
                }

                return $m[0];
            }, $css);

            if ($rewritten) file_put_contents($cssFile, $css);
        }

        return $html;
    }

    public static function rewriteForPreview(string $html, string $sourceDomain): string
    {
        SafePcre::bootstrap();
        if (empty($sourceDomain)) return $html;

        $baseUrl = "https://{$sourceDomain}";

        $html = self::rewriteLinkTags($html, $baseUrl, $sourceDomain);
        $html = self::rewriteImgTags($html, $baseUrl, $sourceDomain);
        $html = self::rewriteScriptTags($html, $baseUrl, $sourceDomain);
        $html = self::rewriteSourceTags($html, $baseUrl, $sourceDomain);
        $html = self::rewriteMetaTags($html, $baseUrl, $sourceDomain);
        $html = self::rewriteInlineCssUrls($html, $baseUrl, $sourceDomain);
        $html = self::rewriteStyleTags($html, $baseUrl, $sourceDomain);
        $html = self::fixLazyLoadingForPreview($html);
        $html = self::addCdnResources($html);

        return $html;
    }

    public static function rewriteForZip(string $html, string $sourceDomain): string
    {
        SafePcre::bootstrap();
        if (empty($sourceDomain)) return $html;

        $baseUrl = "https://{$sourceDomain}";

        $html = self::rewriteLinkTagsZip($html, $baseUrl, $sourceDomain);
        $html = self::rewriteImgTagsZip($html, $baseUrl, $sourceDomain);
        $html = self::rewriteScriptTagsZip($html, $baseUrl, $sourceDomain);
        $html = self::rewriteSourceTagsZip($html, $baseUrl, $sourceDomain);
        $html = self::rewriteMetaTagsZip($html, $baseUrl, $sourceDomain);
        $html = self::rewriteInlineCssUrlsZip($html, $baseUrl, $sourceDomain);
        $html = self::rewriteStyleTagsZip($html, $baseUrl, $sourceDomain);
        $html = self::fixLazyLoadingForPreview($html);
        $html = self::addCdnResources($html);

        return $html;
    }

    private static function proxyUrlZip(string $url, string $baseUrl): string
    {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $baseUrl . $url;
        } elseif (strpos($url, 'http') !== 0) {
            $url = $baseUrl . '/' . $url;
        }
        return 'proxy.php?url=' . urlencode($url);
    }

    private static function rewriteLinkTagsZip(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<link\b([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $tag = $m[0];
            $attrs = $m[1];
            if (preg_match('/href=(["\'])([^"\']+)\1/i', $attrs, $hm)) {
                if (self::shouldProxyUrl($hm[2], $sourceDomain)) {
                    $tag = str_replace($hm[0], 'href=' . $hm[1] . self::proxyUrlZip($hm[2], $baseUrl) . $hm[1], $tag);
                }
            }
            return $tag;
        }, $html);
    }

    private static function rewriteImgTagsZip(string $html, string $baseUrl, string $sourceDomain): string
    {
        $html = SafePcre::replaceCallback('/<img\b([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $tag = $m[0];
            $attrs = $m[1];
            if (preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $attrs, $sm)) {
                if (self::shouldProxyUrl($sm[2], $sourceDomain)) {
                    $tag = str_replace($sm[0], 'src=' . $sm[1] . self::proxyUrlZip($sm[2], $baseUrl) . $sm[1], $tag);
                }
            }
            if (preg_match('/data-original-src=(["\'])([^"\']+)\1/i', $attrs, $dm)) {
                if (self::shouldProxyUrl($dm[2], $sourceDomain)) {
                    $tag = str_replace($dm[0], 'data-original-src=' . $dm[1] . self::proxyUrlZip($dm[2], $baseUrl) . $dm[1], $tag);
                }
            }
            if (preg_match('/srcset=(["\'])([^"\']+)\1/i', $attrs, $ssm)) {
                $srcset = $ssm[2];
                $rewritten = '';
                foreach (self::splitSrcset($srcset) as $part) {
                    $part = trim($part);
                    if (self::isInvalidSrcsetEntry($part)) continue;
                    if (!preg_match('/^(\S+)(\s+\S+)?$/', $part, $pm)) continue;
                    $url = $pm[1];
                    $dpr = $pm[2] ?? '';
                    if (strpos($url, 'data:') === 0) {
                        $rewritten .= $part . ', ';
                    } elseif (self::shouldProxyUrl($url, $sourceDomain)) {
                        $rewritten .= self::proxyUrlZip($url, $baseUrl) . $dpr . ', ';
                    } else {
                        $rewritten .= $part . ', ';
                    }
                }
                if ($rewritten === '') {
                    $tag = str_replace($ssm[0], '', $tag);
                } else {
                    $tag = str_replace($ssm[0], 'srcset=' . $ssm[1] . rtrim($rewritten, ', ') . $ssm[1], $tag);
                }
            }
            return $tag;
        }, $html);
        return $html;
    }

    private static function rewriteScriptTagsZip(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<script\b([^>]*?)src=(["\'])([^"\']+)\2([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $src = $m[3];
            if (self::shouldProxyUrl($src, $sourceDomain)) {
                return '<script' . $m[1] . 'src=' . $m[2] . self::proxyUrlZip($src, $baseUrl) . $m[2] . $m[4] . '>';
            }
            return $m[0];
        }, $html);
    }

    private static function rewriteSourceTagsZip(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<source\b([^>]*?)src=(["\'])([^"\']+)\2([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $src = $m[3];
            if (self::shouldProxyUrl($src, $sourceDomain)) {
                return '<source' . $m[1] . 'src=' . $m[2] . self::proxyUrlZip($src, $baseUrl) . $m[2] . $m[4] . '>';
            }
            return $m[0];
        }, $html);
    }

    private static function rewriteMetaTagsZip(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<meta\b([^>]*?)content=(["\'])([^"\']*\.(?:jpg|jpeg|png|gif|webp|ico)[^"\']*)\2([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $content = $m[3];
            if (self::shouldProxyUrl($content, $sourceDomain)) {
                return '<meta' . $m[1] . 'content=' . $m[2] . self::proxyUrlZip($content, $baseUrl) . $m[2] . $m[4] . '>';
            }
            return $m[0];
        }, $html);
    }

    private static function rewriteInlineCssUrlsZip(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/style=(["\'])([^"\']*)\1/i', function($m) use ($baseUrl, $sourceDomain) {
            $quote = $m[1];
            $css = $m[2];
            $css = SafePcre::replaceCallback('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', function($u) use ($baseUrl, $sourceDomain) {
                if (self::shouldProxyUrl($u[1], $sourceDomain)) {
                    return 'url(' . self::proxyUrlZip($u[1], $baseUrl) . ')';
                }
                return $u[0];
            }, $css);
            return 'style=' . $quote . $css . $quote;
        }, $html);
    }

    private static function rewriteStyleTagsZip(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<style\b([^>]*)>(.*?)<\/style>/is', function($m) use ($baseUrl, $sourceDomain) {
            $attrs = $m[1];
            $css = $m[2];
            $css = SafePcre::replaceCallback('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', function($u) use ($baseUrl, $sourceDomain) {
                if (self::shouldProxyUrl($u[1], $sourceDomain)) {
                    return 'url(' . self::proxyUrlZip($u[1], $baseUrl) . ')';
                }
                return $u[0];
            }, $css);
            return '<style' . $attrs . '>' . $css . '</style>';
        }, $html);
    }

    private static function shouldProxyUrl(string $url, string $sourceDomain): bool
    {
        if (empty($url)) return false;
        if (strpos($url, 'data:') === 0) return false;
        if (strpos($url, 'javascript:') === 0) return false;
        if (strpos($url, '#') === 0) return false;
        if (strpos($url, 'mailto:') === 0) return false;
        if (strpos($url, 'tel:') === 0) return false;

        $blocked = [
            'google-analytics.com', 'googletagmanager.com', 'google.com', 'googleapis.com',
            'facebook.net', 'facebook.com', 'doubleclick.net',
            'cdnjs.cloudflare.com', 'cloudflare.com',
            'taboola.com', 'outbrain.com', 'hotjar.com',
            'cloudflareinsights.com', 'youtube.com',
            'googlesyndication.com', 'googleadservices.com',
        ];

        $host = parse_url($url, PHP_URL_HOST) ?? '';
        if (empty($host)) {
            $host = parse_url('https://' . $url, PHP_URL_HOST) ?? '';
        }

        foreach ($blocked as $domain) {
            if ($host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain) {
                return false;
            }
        }

        if ($host === $sourceDomain || substr($host, -(strlen($sourceDomain) + 1)) === '.' . $sourceDomain) {
            return true;
        }

        if (strpos($url, '/') === 0 || strpos($url, 'http') !== 0) {
            return true;
        }

        return false;
    }

    private static function proxyUrl(string $url, string $baseUrl): string
    {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $baseUrl . $url;
        } elseif (strpos($url, 'http') !== 0) {
            $url = $baseUrl . '/' . $url;
        }
        return '/proxy.php?url=' . urlencode($url);
    }

    private static function rewriteLinkTags(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<link\b([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $tag = $m[0];
            $attrs = $m[1];

            if (preg_match('/href=(["\'])([^"\']+)\1/i', $attrs, $hm)) {
                if (self::shouldProxyUrl($hm[2], $sourceDomain)) {
                    $proxied = self::proxyUrl($hm[2], $baseUrl);
                    $tag = str_replace($hm[0], 'href=' . $hm[1] . $proxied . $hm[1], $tag);
                }
            }
            return $tag;
        }, $html);
    }

    private static function rewriteImgTags(string $html, string $baseUrl, string $sourceDomain): string
    {
        $html = SafePcre::replaceCallback('/<img\b([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $tag = $m[0];
            $attrs = $m[1];

            if (preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $attrs, $sm)) {
                if (self::shouldProxyUrl($sm[2], $sourceDomain)) {
                    $proxied = self::proxyUrl($sm[2], $baseUrl);
                    $tag = str_replace($sm[0], 'src=' . $sm[1] . $proxied . $sm[1], $tag);
                }
            }

            foreach (['data-original-src', 'data-lazy-src'] as $lazyAttr) {
                if (preg_match('/' . $lazyAttr . '=(["\'])([^"\']+)\1/i', $attrs, $dm)) {
                    if (self::shouldProxyUrl($dm[2], $sourceDomain)) {
                        $proxied = self::proxyUrl($dm[2], $baseUrl);
                        $tag = str_replace($dm[0], $lazyAttr . '=' . $dm[1] . $proxied . $dm[1], $tag);
                    }
                }
            }

            if (preg_match('/srcset=(["\'])([^"\']+)\1/i', $attrs, $ssm)) {
                $srcset = $ssm[2];
                $parts = self::splitSrcset($srcset);
                $newParts = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (self::isInvalidSrcsetEntry($part)) continue;
                    if (preg_match('/^(\S+)(\s+\S+)?$/', $part, $pm)) {
                        if (strpos($pm[1], 'data:') === 0) {
                            $newParts[] = $part;
                        } elseif (self::shouldProxyUrl($pm[1], $sourceDomain)) {
                            $newParts[] = self::proxyUrl($pm[1], $baseUrl) . (isset($pm[2]) ? $pm[2] : '');
                        } else {
                            $newParts[] = $part;
                        }
                    }
                }
                if (empty($newParts)) {
                    $tag = str_replace($ssm[0], '', $tag);
                } else {
                    $tag = str_replace($ssm[0], 'srcset=' . $ssm[1] . implode(', ', $newParts) . $ssm[1], $tag);
                }
            }

            return $tag;
        }, $html);

        return $html;
    }

    private static function rewriteScriptTags(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<script\b([^>]*?)src=(["\'])([^"\']+)\2([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $src = $m[3];
            if (self::shouldProxyUrl($src, $sourceDomain)) {
                $proxied = self::proxyUrl($src, $baseUrl);
                return '<script' . $m[1] . 'src=' . $m[2] . $proxied . $m[2] . $m[4] . '>';
            }
            return $m[0];
        }, $html);
    }

    private static function rewriteSourceTags(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<source\b([^>]*?)src=(["\'])([^"\']+)\2([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $src = $m[3];
            if (self::shouldProxyUrl($src, $sourceDomain)) {
                $proxied = self::proxyUrl($src, $baseUrl);
                return '<source' . $m[1] . 'src=' . $m[2] . $proxied . $m[2] . $m[4] . '>';
            }
            return $m[0];
        }, $html);
    }

    private static function rewriteMetaTags(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<meta\b([^>]*?)content=(["\'])([^"\']*\.(?:jpg|jpeg|png|gif|webp|ico)[^"\']*)\2([^>]*)>/i', function($m) use ($baseUrl, $sourceDomain) {
            $url = $m[3];
            if (self::shouldProxyUrl($url, $sourceDomain)) {
                $proxied = self::proxyUrl($url, $baseUrl);
                return '<meta' . $m[1] . 'content=' . $m[2] . $proxied . $m[2] . $m[4] . '>';
            }
            return $m[0];
        }, $html);
    }

    private static function rewriteInlineCssUrls(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/style=(["\'])([^"\']*)\1/i', function($m) use ($baseUrl, $sourceDomain) {
            $quote = $m[1];
            $style = $m[2];
            $rewritten = SafePcre::replaceCallback('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', function($um) use ($baseUrl, $sourceDomain) {
                $url = $um[1];
                if (self::shouldProxyUrl($url, $sourceDomain)) {
                    return 'url(' . self::proxyUrl($url, $baseUrl) . ')';
                }
                return $um[0];
            }, $style);
            return 'style=' . $quote . $rewritten . $quote;
        }, $html);
    }

    private static function rewriteStyleTags(string $html, string $baseUrl, string $sourceDomain): string
    {
        return SafePcre::replaceCallback('/<style\b([^>]*)>(.*?)<\/style>/is', function($m) use ($baseUrl, $sourceDomain) {
            $attrs = $m[1];
            $css = $m[2];
            $rewritten = SafePcre::replaceCallback('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', function($um) use ($baseUrl, $sourceDomain) {
                $url = $um[1];
                if (self::shouldProxyUrl($url, $sourceDomain)) {
                    return 'url(' . self::proxyUrl($url, $baseUrl) . ')';
                }
                return $um[0];
            }, $css);
            return '<style' . $attrs . '>' . $rewritten . '</style>';
        }, $html);
    }

    private static function fixLazyLoadingForPreview(string $html): string
    {
        $html = SafePcre::replaceCallback('/data-original-src=(["\'])([^"\']+)\1/i', function($m) {
            return 'src=' . $m[1] . $m[2] . $m[1];
        }, $html);
        $html = SafePcre::replaceCallback('/data-lazy-src=(["\'])([^"\']+)\1/i', function($m) {
            return 'src=' . $m[1] . $m[2] . $m[1];
        }, $html);
        $html = SafePcre::replace('/loading=["\']lazy["\']/i', 'loading="eager"', $html);
        return $html;
    }

    private static function addCdnResources(string $html): string
    {
        $html = SafePcre::replace('#<link[^>]+href=["\'][^"\']*font-awesome[^"\']*["\'][^>]*/?>#i', '', $html);
        $html = SafePcre::replace('#<link[^>]+href=["\'][^"\']*fontawesome[^"\']*["\'][^>]*/?>#i', '', $html);
        $html = SafePcre::replace('#<link[^>]+href=["\'][^"\']*\/all\.min\.css[^"\']*["\'][^>]*/?>#i', '', $html);

        $cdn = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">';
        $googleFonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        $jquery = '<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>';

        $html = SafePcre::replace('/<\/head>/i', "{$cdn}\n{$googleFonts}\n{$jquery}\n</head>", $html, 1);

        return $html;
    }

    private function fixLazyLoading(string $html): string
    {
        foreach (['data-original-src', 'data-lazy-src', 'data-src', 'data-lazy'] as $attr) {
            $html = SafePcre::replaceCallback('/' . $attr . '=(["\'])([^"\']+)\1/i', function ($m) {
                if (strpos($m[2], 'data:') === 0 || strpos($m[2], '#') === 0) return $m[0];
                if (strpos($m[2], 'url(') === 0) return $m[0];
                return 'src=' . $m[1] . $m[2] . $m[1];
            }, $html);
        }
        $html = SafePcre::replace('/loading=["\']lazy["\']/i', 'loading="eager"', $html);
        return $html;
    }

    private function inlineStyleTagUrls(string $html): string
    {
        return SafePcre::replaceCallback('/<style\b([^>]*)>(.*?)<\/style>/is', function ($m) {
            $attrs = $m[1];
            $css = $this->rewriteCssUrlsInline($m[2]);
            return '<style' . $attrs . '>' . $css . '</style>';
        }, $html);
    }

    private function inlineInlineStyleUrls(string $html): string
    {
        return SafePcre::replaceCallback('/style=(["\'])([^"\']*)\1/i', function ($m) {
            $quote = $m[1];
            $css = $this->rewriteCssUrlsInline($m[2]);
            return 'style=' . $quote . $css . $quote;
        }, $html);
    }

    private function rewriteCssUrlsInline(string $css): string
    {
        $css = SafePcre::replaceCallback('/url\(\s*([\'"]?)([^\'")\s]+)\1\s*\)/i', function ($m) {
            $url = $m[2];
            if (strpos($url, 'data:') === 0 || strpos($url, '#') === 0) return $m[0];
            $resolved = $this->resolveUrl($url);
            if (strpos($resolved, $this->baseUrl) !== 0) return $m[0];
            $local = $this->downloadAsset($resolved);
            if ($local) return 'url(' . $local . ')';
            return 'url(' . $resolved . ')';
        }, $css);

        $css = SafePcre::replaceCallback('/image-set\((.*?)\)/is', function ($m) {
            $inner = $m[1];
            $inner = SafePcre::replaceCallback('/url\(\s*([\'"]?)([^\'")\s]+)\1\s*\)/i', function ($um) {
                $url = $um[2];
                if (strpos($url, 'data:') === 0) return $um[0];
                $resolved = $this->resolveUrl($url);
                if (strpos($resolved, $this->baseUrl) !== 0) return $um[0];
                $local = $this->downloadAsset($resolved);
                return $local ? 'url(' . $local . ')' : $um[0];
            }, $inner);
            return 'image-set(' . $inner . ')';
        }, $css);

        return $css;
    }

    private function processSrcset(string $html): string
    {
        return SafePcre::replaceCallback('/\bsrcset=(["\'])([^"\']+)\1/i', function ($m) {
            $quote = $m[1];
            $parts = self::splitSrcset($m[2]);
            $out = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') continue;
                if (self::isInvalidSrcsetEntry($part)) continue;
                if (!preg_match('/^(\S+)(\s+\S+)?$/', $part, $pm)) continue;
                $url = $pm[1];
                $descriptor = $pm[2] ?? '';
                if (strpos($url, 'data:') === 0) { $out[] = $part; continue; }
                $resolved = $this->resolveUrl($url);
                if (strpos($resolved, $this->baseUrl) !== 0) { $out[] = $part; continue; }
                $local = $this->downloadAsset($resolved);
                $out[] = ($local ?: $resolved) . $descriptor;
            }
            if (empty($out)) return '';
            return 'srcset=' . $quote . implode(', ', $out) . $quote;
        }, $html);
    }

    public static function isInvalidSrcsetEntry(string $entry): bool
    {
        if (preg_match('/^data:[a-z+\/-]+;base64,\s*$/i', $entry)) return true;

        $url = preg_split('/\s+/', $entry)[0] ?? '';
        if ($url === '') return true;

        if (preg_match('/^[A-Za-z0-9+\/=]{60,}$/', $url)) return true;

        return false;
    }

    public static function splitSrcset(string $srcset): array
    {
        $parts = [];
        $current = '';
        $inData = false;
        $len = strlen($srcset);

        for ($i = 0; $i < $len; $i++) {
            $ch = $srcset[$i];

            if (!$inData && substr($srcset, $i, 5) === 'data:') {
                $inData = true;
            }

            if ($ch === ',') {
                $next = $i + 1 < $len ? $srcset[$i + 1] : ' ';
                if ($inData && $next !== ' ' && $next !== "\t" && $next !== "\n" && $next !== "\r") {
                    $current .= $ch;
                    continue;
                }
                $parts[] = trim($current);
                $current = '';
                $inData = false;
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') $parts[] = trim($current);
        return $parts;
    }

    private function processPictureSources(string $html): string
    {
        return SafePcre::replaceCallback('/<source\b([^>]*?)src=(["\'])([^"\']+)\2([^>]*)>/i', function ($m) {
            $url = $m[3];
            if (strpos($url, 'data:') === 0) return $m[0];
            $resolved = $this->resolveUrl($url);
            if (strpos($resolved, $this->baseUrl) !== 0) return $m[0];
            $local = $this->downloadAsset($resolved);
            if (!$local) return $m[0];
            return '<source' . $m[1] . 'src=' . $m[2] . $local . $m[2] . $m[4] . '>';
        }, $html);
    }

    private function processVideoPosters(string $html): string
    {
        return SafePcre::replaceCallback('/\bposter=(["\'])([^"\']+)\1/i', function ($m) {
            $url = $m[2];
            if (strpos($url, 'data:') === 0) return $m[0];
            $resolved = $this->resolveUrl($url);
            if (strpos($resolved, $this->baseUrl) !== 0) return $m[0];
            $local = $this->downloadAsset($resolved);
            if (!$local) return 'poster=' . $m[1] . $resolved . $m[1];
            return 'poster=' . $m[1] . $local . $m[1];
        }, $html);
    }

    private function processDataAttributes(string $html): string
    {
        return SafePcre::replaceCallback('/\b(data-(?:bg|background|original|lazy|src|image))=(["\'])([^"\']+)\2/i', function ($m) {
            $attr = $m[1];
            $quote = $m[2];
            $value = $m[3];
            if (strpos($value, 'data:') === 0 || strpos($value, '#') === 0) return $m[0];
            if (strpos($value, 'url(') === 0) return $m[0];
            $resolved = $this->resolveUrl($value);
            if (strpos($resolved, $this->baseUrl) !== 0) return $m[0];
            $local = $this->downloadAsset($resolved);
            if (!$local) return $m[0];
            return $attr . '=' . $quote . $local . $quote;
        }, $html);
    }

    private function processSvgImages(string $html): string
    {
        return SafePcre::replaceCallback('/<(?:image|use)\b([^>]*?)(?:xlink:href|href)=(["\'])([^"\']+)\2([^>]*)>/i', function ($m) {
            $url = $m[3];
            if (strpos($url, 'data:') === 0 || strpos($url, '#') === 0) return $m[0];
            $resolved = $this->resolveUrl($url);
            if (strpos($resolved, $this->baseUrl) !== 0) return $m[0];
            $local = $this->downloadAsset($resolved);
            if (!$local) return $m[0];
            return str_replace($m[3], $local, $m[0]);
        }, $html);
    }

    private function processLinkAssets(string $html): string
    {
        return SafePcre::replaceCallback('/<link\b([^>]*)>/i', function ($m) {
            $tag = $m[0];
            $attrs = $m[1];
            $isIcon = preg_match('/rel=(["\'])(?:icon|shortcut icon|apple-touch-icon|mask-icon)\1/i', $attrs);
            $isPreloadAsset = preg_match('/rel=(["\'])preload\1/i', $attrs) && preg_match('/as=(["\'])(?:image|font)\1/i', $attrs);
            if (!$isIcon && !$isPreloadAsset) return $tag;
            if (preg_match('/href=(["\'])([^"\']+)\1/i', $attrs, $hm)) {
                $url = $hm[2];
                if (strpos($url, 'data:') === 0) return $tag;
                $resolved = $this->resolveUrl($url);
                if (strpos($resolved, $this->baseUrl) !== 0) return $tag;
                $local = $this->downloadAsset($resolved);
                if (!$local) return $tag;
                $tag = str_replace($hm[0], 'href=' . $hm[1] . $local . $hm[1], $tag);
            }
            return $tag;
        }, $html);
    }

    private function downloadAndInlineCss(string $html): string
    {
        preg_match_all('/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $html, $matches1);
        preg_match_all('/<link\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*\brel=["\']stylesheet["\'][^>]*>/i', $html, $matches2);
        $allLinks = array_unique(array_merge($matches1[1] ?? [], $matches2[1] ?? []));

        if (empty($allLinks)) return $html;

        $combinedCss = '';
        $seen = [];
        $cdnLinks = [];

        foreach ($allLinks as $cssUrl) {
            $resolved = $this->resolveUrl($cssUrl);
            if (isset($seen[$resolved])) continue;
            $seen[$resolved] = true;

            $host = parse_url($resolved, PHP_URL_HOST) ?? '';
            $isSourceCss = ($host === $this->sourceDomain || str_ends_with($host, '.' . $this->sourceDomain));

            if (!$isSourceCss) {
                $cdnLinks[] = $cssUrl;
                continue;
            }

            $cssContent = $this->fetchUrl($resolved);
            if ($cssContent === null) { $cdnLinks[] = $cssUrl; continue; }

            $cssDir = dirname(parse_url($resolved, PHP_URL_PATH));
            $cssContent = $this->rewriteCssUrls($cssContent, $cssDir);
            $combinedCss .= "\n/* {$resolved} */\n{$cssContent}\n";
        }

        $html = SafePcre::replace('/<link\b[^>]*(?:rel=["\']stylesheet["\'][^>]*href=["\'][^"\']+["\']|href=["\'][^"\']+["\'][^>]*rel=["\']stylesheet["\'][^>]*)\/?>/i', '', $html);

        if (!empty($combinedCss)) {
            $html = SafePcre::replace('/<\/head>/i', "<style data-cloned=\"true\">\n{$combinedCss}\n</style>\n</head>", $html, 1);
        }

        return $html;
    }

    private function rewriteCssUrls(string $css, string $cssDir): string
    {
        $css = SafePcre::replaceCallback('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', function($m) use ($cssDir) {
            $url = $m[1];
            if (strpos($url, 'data:') === 0) return $m[0];
            $fullUrl = $this->resolveRelativeUrl($url, $cssDir);
            $localPath = $this->downloadAsset($fullUrl);
            if ($localPath) {
                return "url('{$localPath}')";
            }
            return $m[0];
        }, $css);

        $css = SafePcre::replaceCallback('/@import\s+[\'"]([^\'"]+)[\'"]/i', function($m) use ($cssDir) {
            $url = $this->resolveRelativeUrl($m[1], $cssDir);
            $content = $this->fetchUrl($url);
            if ($content) {
                $importDir = dirname(parse_url($url, PHP_URL_PATH));
                $content = $this->rewriteCssUrls($content, $importDir);
                return $content;
            }
            return $m[0];
        }, $css);

        return $css;
    }

    private function resolveRelativeUrl(string $url, string $baseDir): string
    {
        $url = trim($url);
        if (strpos($url, 'data:') === 0) return $url;
        if (strpos($url, '//') === 0) return 'https:' . $url;
        if (strpos($url, 'http') === 0) return $url;
        if (strpos($url, '/') === 0) return $this->baseUrl . $url;
        return $this->baseUrl . $baseDir . '/' . $url;
    }

    private function downloadAndInlineScripts(string $html): string
    {
        preg_match_all('/<script\b[^>]+src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i', $html, $matches);

        if (empty($matches[0])) return $html;

        $skipDomains = [
            'google-analytics.com', 'googletagmanager.com', 'google.com', 'googleapis.com',
            'facebook.net', 'facebook.com', 'doubleclick.net',
            'clarity.ms', 'analytics.tiktok.com', 'tiktok.com',
            'cloudflareinsights.com', 'hotjar.com',
        ];

        foreach ($matches[0] as $i => $fullTag) {
            $src = $matches[1][$i];
            $srcUrl = $this->resolveUrl($src);
            $host = parse_url($srcUrl, PHP_URL_HOST) ?? '';

            $skip = false;
            foreach ($skipDomains as $domain) {
                if (str_contains($host, $domain)) { $skip = true; break; }
            }
            if ($skip) continue;

            $content = $this->fetchUrl($srcUrl);
            if ($content && strlen($content) < 500000) {
                $html = str_replace($fullTag, "<script data-cloned=\"true\">\n{$content}\n</script>", $html);
            }
        }

        return $html;
    }

    private function rewriteImageUrls(string $html): string
    {
        $html = SafePcre::replaceCallback('/<img\b[^>]+src=["\']([^"\']+)["\']/i', function($m) {
            $url = $m[1];
            if (strpos($url, 'data:') === 0 || strpos($url, '#') === 0) return $m[0];
            $resolved = $this->resolveUrl($url);
            $local = $this->downloadAsset($resolved);
            if ($local) return str_replace($m[1], $local, $m[0]);
            return $m[0];
        }, $html);

        return $html;
    }

    private function fixBackgroundImages(string $html): string
    {
        return SafePcre::replaceCallback('/style=["\']([^"\']*background-image\s*:\s*url\(\s*[\'"]?[^\'")\s]+[\'"]?\s*\)[^"\']*)["\']/i', function($m) {
            return $m[0];
        }, $html);
    }

    private function addFontAwesomeCdn(string $html): string
    {
        $html = SafePcre::replace('#<link[^>]+href=["\'][^"\']*font-awesome[^"\']*["\'][^>]*/?>#i', '', $html);
        $html = SafePcre::replace('#<link[^>]+href=["\'][^"\']*fontawesome[^"\']*["\'][^>]*/?>#i', '', $html);
        $html = SafePcre::replace('#<link[^>]+href=["\'][^"\']*\/all\.min\.css[^"\']*["\'][^>]*/?>#i', '', $html);
        $cdn = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">';
        $html = SafePcre::replace('/<\/head>/i', "{$cdn}\n</head>", $html, 1);
        return $html;
    }

    private function addGoogleFontsCdn(string $html): string
    {
        if (preg_match('/fonts\.googleapis\.com/i', $html)) {
            $fontsLink = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
            $html = SafePcre::replace('/<\/head>/i', "{$fontsLink}\n</head>", $html, 1);
        }
        return $html;
    }

    private function resolveUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) return $url;
        if (strpos($url, 'data:') === 0) return $url;
        if (strpos($url, '//') === 0) return 'https:' . $url;
        if (strpos($url, 'http') === 0) return $url;
        if (strpos($url, '/') === 0) return $this->baseUrl . $url;
        return $this->baseUrl . '/' . $url;
    }

    private function fetchUrl(string $url): ?string
    {
        if (isset($this->downloaded[$url])) return $this->downloaded[$url];
        if (isset($this->failed[$url])) return null;

        $content = null;
        for ($attempt = 1; $attempt <= 2 && $content === null; $attempt++) {
            $content = $this->curlFetch($url);
        }

        if ($content === null) {
            $this->failed[$url] = true;
            $this->failedAssets[] = $url;
            return null;
        }

        $this->downloaded[$url] = $content;
        return $content;
    }

    private function curlFetch(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_REFERER => 'https://' . $this->sourceDomain . '/',
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
        ]);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $httpCode >= 400) return null;
        return $content;
    }

    private function downloadAsset(string $url): ?string
    {
        if (isset($this->cssMap[$url])) return $this->cssMap[$url];

        if ($this->mediaMode === 'original' && $this->storage === null) {
            $resolved = strpos($url, 'http') === 0 ? $url : $this->resolveUrl($url);
            $this->cssMap[$url] = $resolved;
            return $resolved;
        }

        $content = $this->fetchUrl($url);
        if ($content === null) return null;

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $mimeMap = [
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject', 'otf' => 'font/otf',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        if ($this->storage) {
            $key = $this->storagePrefix . md5($url) . ($ext ? '.' . $ext : '.bin');
            $publicUrl = $this->storage->upload($key, $content, $mime);
            if ($publicUrl !== null) {
                $this->cssMap[$url] = $publicUrl;
                return $publicUrl;
            }
        }

        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($content);
        $this->cssMap[$url] = $dataUri;
        return $dataUri;
    }

    public function getDownloadedCount(): int { return count($this->downloaded); }
    public function getCssMap(): array { return $this->cssMap; }
}
