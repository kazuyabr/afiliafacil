<?php

require_once __DIR__ . '/SafePcre.php';

class CloneValidator
{
    public const CLONER_VERSION = '1.1.0';

    public static function validate(string $html): array
    {
        $issues = [];

        if (preg_match_all('/srcset=(["\'])([^"\']*)\1/i', $html, $matches)) {
            foreach ($matches[2] as $srcset) {
                if (preg_match('/data:[a-z+\/-]+;base64,\s*(?:,|$)/i', $srcset)) {
                    $issues[] = ['type' => 'srcset_empty_data_uri', 'message' => 'srcset com data URI sem payload (imagem não renderiza)'];
                    continue;
                }
                if (preg_match('#https?://[^"\s,]+/[A-Za-z0-9+/=]{80,}#', $srcset)) {
                    $issues[] = ['type' => 'srcset_corrupted_url', 'message' => 'srcset com URL corrompida (base64 como caminho)'];
                }
            }
        }

        $corruptedCount = preg_match_all('#https?://[a-z0-9.-]+/[A-Za-z0-9+/=]{80,}#i', $html);
        if ($corruptedCount > 0) {
            $issues[] = ['type' => 'corrupted_urls', 'message' => $corruptedCount . ' URL(s) corrompida(s) com base64 no caminho'];
        }

        $emptyDataUris = preg_match_all('/data:[a-z+\/-]+;base64,(?=[\s"\',<>])/i', $html);
        if ($emptyDataUris > 0) {
            $issues[] = ['type' => 'empty_data_uris', 'message' => $emptyDataUris . ' data URI(s) vazio(s)'];
        }

        $imgsWithoutSrc = preg_match_all('/<img\b(?![^>]*\bsrc=)[^>]*>/i', $html);
        if ($imgsWithoutSrc > 0) {
            $issues[] = ['type' => 'img_without_src', 'message' => $imgsWithoutSrc . ' imagem(ns) sem src'];
        }

        if (preg_match_all('/<img\b[^>]*>/i', $html, $imgTags)) {
            $duplicatedSrc = 0;
            $lazyLeftover = 0;
            foreach ($imgTags[0] as $tag) {
                if (preg_match_all('/\bsrc=/i', $tag) >= 2) $duplicatedSrc++;
                if (preg_match('/\bdata-(?:lazy-src|lazy-srcset|lazy-sizes|src|original-src)\s*=/i', $tag)) $lazyLeftover++;
            }
            if ($duplicatedSrc > 0) {
                $issues[] = ['type' => 'img_duplicate_src', 'message' => $duplicatedSrc . ' imagem(ns) com atributo src duplicado (placeholder vence e a imagem real não aparece)'];
            }
            if ($lazyLeftover > 0) {
                $issues[] = ['type' => 'img_lazy_leftover', 'message' => $lazyLeftover . ' imagem(ns) com atributos de lazy-load não resolvidos (data-lazy-*)'];
            }
        }

        return $issues;
    }

    public static function isHealthy(array $issues): bool
    {
        return empty($issues);
    }
}
