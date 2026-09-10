<?php

require_once __DIR__ . '/AssetProcessor.php';
require_once __DIR__ . '/SafePcre.php';

class Cloner
{
    private array $checkoutDomains = [
        'hotmart', 'kiwify', 'eduzz', 'braip', 'monetizze', 'cartpanda',
        'payt.com.br', 'ticto', 'nutror', 'perfectpay', 'lastlink',
        'mindminers', 'vendd', 'klickmembers', 'membertoo',
    ];

    private array $ctaClasses = [
        'elementor-button', 'cta', 'btn-primary', 'btn-cta', 'buy-button', 'checkout-btn',
        'ct-link-text', 'pulse-button', 'oxy-pro-menu',
    ];

    public function process(string $html, string $affiliateLink, string $mode = 'paste'): array
    {
        SafePcre::bootstrap();
        $originalSize = strlen($html);

        $sourceDomain = $this->extractDomain($html);
        if (!empty($sourceDomain)) {
            $processor = new AssetProcessor($sourceDomain);
            $html = $processor->processHtml($html);
        }

        $html = $this->detectAndReplaceCtas($html, $affiliateLink);
        $html = $this->removeTrackingScripts($html);

        $html = SafePcre::replace('/[\x{FEFF}]/u', '', $html);

        return [
            'html' => $html,
            'original_size' => $originalSize,
            'processed_size' => strlen($html),
            'ctas' => $this->lastCtas,
            'mode' => $mode,
            'affiliate_link' => $affiliateLink,
            'source_domain' => $sourceDomain,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private array $lastCtas = [];

    private function detectAndReplaceCtas(string $html, string $affiliateLink): string
    {
        $ctas = [];

        $html = SafePcre::replaceCallback('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m) use ($affiliateLink, &$ctas) {
            $href = $m[1];
            $content = $m[2];
            $fullTag = $m[0];

            if (empty($href) || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, '#')) {
                return $fullTag;
            }

            $isCheckout = $this->isCheckoutLink($href);
            $isCta = $this->looksLikeCta($fullTag, $content);

            if (!$isCheckout && !$isCta) {
                return $fullTag;
            }

            $text = trim(strip_tags($content));
            $ctas[] = [
                'old_href' => $href,
                'new_href' => $affiliateLink,
                'text' => !empty($text) ? $text : '(sem texto)',
                'class' => $this->extractClass($fullTag),
                'type' => $isCheckout ? 'checkout' : 'button',
            ];

            $newTag = str_replace(
                'href="' . htmlspecialchars($href) . '"',
                'href="' . htmlspecialchars($affiliateLink) . '"',
                $fullTag
            );
            $newTag = str_replace(
                "href='" . htmlspecialchars($href) . "'",
                "href='" . htmlspecialchars($affiliateLink) . "'",
                $newTag
            );

            $newTag = str_replace('<a ', '<a data-cloned-cta="true" data-original-href="' . htmlspecialchars($href) . '" ', $newTag);

            return $newTag;
        }, $html);

        $html = SafePcre::replaceCallback('/<button[^>]*>(.*?)<\/button>/is', function($m) use ($affiliateLink, &$ctas) {
            $content = $m[1];
            $fullTag = $m[0];

            if (!$this->looksLikeCta($fullTag, $content)) {
                return $fullTag;
            }

            $text = trim(strip_tags($content));
            $ctas[] = [
                'old_href' => '(button)',
                'new_href' => $affiliateLink,
                'text' => !empty($text) ? $text : '(sem texto)',
                'class' => $this->extractClass($fullTag),
                'type' => 'button-no-href',
            ];

            $onclick = "window.location.href='" . addslashes($affiliateLink) . "'";
            $newTag = str_replace('<button', '<button data-cloned-cta="true" onclick="' . $onclick . '"', $fullTag);

            return $newTag;
        }, $html);

        $this->lastCtas = $ctas;
        return $html;
    }

    private function removeTrackingScripts(string $html): string
    {
        $trackingPatterns = [
            '/<script[^>]*googletagmanager\.com[^>]*><\/script>/is',
            '/<script[^>]*cloudflareinsights\.com[^>]*><\/script>/is',
            '/<script[^>]*facebook\.net\/en_US\/fbevents\.js[^>]*><\/script>/is',
            '/<script[^>]*connect\.facebook\.net[^>]*><\/script>/is',
            '/<script[^>]*analytics\.tiktok\.com[^>]*><\/script>/is',
            '/<script[^>]*snap\.licdn\.com[^>]*><\/script>/is',
            '/<script[^>]*bat\.bing\.com[^>]*><\/script>/is',
            '/<script[^>]*hotjar\.com[^>]*><\/script>/is',
            '/<script[^>]*mc\.yandex\.ru[^>]*><\/script>/is',
            '/<script[^>]*pagead2\.googlesyndication\.com[^>]*><\/script>/is',
            '/<script[^>]*adservice\.google\.com[^>]*><\/script>/is',
            '/<script[^>]*taboola\.com[^>]*><\/script>/is',
            '/<script[^>]*outbrain\.com[^>]*><\/script>/is',
        ];

        foreach ($trackingPatterns as $pattern) {
            $html = SafePcre::replace($pattern, '', $html);
        }

        $html = SafePcre::replaceCallback('/<script[^>]*>(.*?)<\/script>/is', function($m) {
            $content = $m[1];
            $inlinePatterns = [
                '/window\.dataLayer\s*=\s*window\.dataLayer\s*\|\|\s*\[\];\s*function\s+gtag\s*\(\)/s',
                '/gtag\(\s*[\'"]event/s',
                '/fbevents\.js/s',
                '/_fbq\s*=/s',
                '/fbevents\.init/s',
                '/dataLayer\.push\s*\(\s*\{[^}]*event\s*:/s',
                '/function\s+gtag\s*\(\)\s*\{dataLayer\.push/s',
                '/if\s*\(\s*!window\.gtag/s',
                '/window\._linkedin_data_partner_ids/s',
                '/_linkedin_data_partner_ids\s*=/s',
                '/gtm\.start/s',
            ];

            foreach ($inlinePatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    return '<!-- tracking removed -->';
                }
            }

            return $m[0];
        }, $html);

        return $html;
    }

    private function isCheckoutLink(string $href): bool
    {
        $hrefLower = strtolower($href);
        foreach ($this->checkoutDomains as $domain) {
            if (str_contains($hrefLower, $domain)) return true;
        }
        return false;
    }

    private function looksLikeCta(string $tag, string $content): bool
    {
        $class = strtolower($this->extractClass($tag));
        foreach ($this->ctaClasses as $ctaClass) {
            if (str_contains($class, $ctaClass)) return true;
        }

        $ctaKeywords = [
            'comprar', 'quero', 'adquirir', 'garantir', 'entrar', 'iniciar',
            'começar', 'acesso', 'aproveitar', 'resgatar', 'inscrever',
            'buy', 'get', 'start', 'join', 'access', 'claim', 'enroll',
            'garantir vaga', 'garantir minha vaga', 'quero acesso',
        ];

        $text = strtolower(strip_tags($content));
        foreach ($ctaKeywords as $kw) {
            if (str_contains($text, $kw)) return true;
        }

        return false;
    }

    private function extractClass(string $tag): string
    {
        if (preg_match('/class=["\']([^"\']+)["\']/i', $tag, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractDomain(string $html): string
    {
        $skipDomains = [
            'ogp.me', 'schema.org', 'w3.org', 'xmlns.com',
            'google.com', 'googleapis.com', 'googletagmanager.com',
            'facebook.com', 'facebook.net', 'twitter.com',
            'cdnjs.cloudflare.com', 'cloudflare.com',
        ];

        if (preg_match_all('#https?://([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})#', $html, $matches)) {
            $counts = [];
            foreach ($matches[1] as $host) {
                $host = strtolower($host);
                $skip = false;
                foreach ($skipDomains as $sd) {
                    if ($host === $sd || substr($host, -(strlen($sd) + 1)) === '.' . $sd) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $counts[$host] = ($counts[$host] ?? 0) + 1;
                }
            }
            if (!empty($counts)) {
                arsort($counts);
                return array_key_first($counts);
            }
        }
        return '';
    }

    public function fetchUrl(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'URL invalida'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            ],
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false || $httpCode >= 400) {
            return ['success' => false, 'error' => $error !== '' ? $error : "HTTP {$httpCode}"];
        }

        return ['success' => true, 'html' => $html, 'http_code' => $httpCode];
    }
}
