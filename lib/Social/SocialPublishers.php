<?php

require_once __DIR__ . '/SocialNetworks.php';
require_once __DIR__ . '/SocialHttp.php';
require_once __DIR__ . '/SocialConnections.php';
require_once __DIR__ . '/../Config.php';

/**
 * Publicacao por rede via API oficial. Cada publisher devolve
 * ['remote_id' => ...] ou ['error' => ...] — o falha de uma rede nunca
 * afeta as demais (status por target em social_post_targets).
 */
class SocialPublishers
{
    /**
     * @param object $conn linha de social_connections (token criptografado)
     * @param array $post caption, media_url, media_kind
     * @return array{remote_id?:string, error?:string}
     */
    public static function publish(object $conn, array $post, ?array $resume = null): array
    {
        $token = SocialConnections::tokenFor($conn);
        if ($token === null || $token === '') {
            return ['error' => 'Token da conexão ausente. Reconecte a conta.'];
        }
        $meta = SocialConnections::metaFor($conn);

        return match ((string)$conn->network) {
            'facebook' => self::facebook($token, $meta, $post),
            'instagram' => self::instagram($token, $meta, $post, $resume),
            'threads' => self::threads($token, $meta, $post),
            'x' => self::x($token, $meta, $post),
            'tiktok' => self::tiktok($token, $meta, $post),
            default => ['error' => 'Rede não suportada.'],
        };
    }

    private static function facebook(string $token, array $meta, array $post): array
    {
        $pageId = (string)($meta['page_id'] ?? '');
        if ($pageId === '') return ['error' => 'Conexão sem Página do Facebook. Reconecte a conta.'];

        if (!empty($post['media_url'])) {
            $r = SocialHttp::json('POST', "https://graph.facebook.com/v26.0/{$pageId}/photos", [
                'form' => ['url' => $post['media_url'], 'message' => (string)$post['caption'],
                    'access_token' => $token],
            ]);
        } else {
            $r = SocialHttp::json('POST', "https://graph.facebook.com/v26.0/{$pageId}/feed", [
                'form' => ['message' => (string)$post['caption'], 'access_token' => $token],
            ]);
        }

        if (!empty($r['id'])) return ['remote_id' => (string)$r['id']];
        return ['error' => SocialHttp::errorMsg($r, 'Falha ao publicar no Facebook.')];
    }

    private static function instagram(string $token, array $meta, array $post, ?array $resume = null): array
    {
        $igId = (string)($meta['ig_user_id'] ?? '');
        if ($igId === '') return ['error' => 'Conexão sem conta do Instagram. Reconecte a conta.'];

        // Retomada: o orquestrador salvou o estado em pending_data (os containers
        // já existem na Meta) — não recrie nada, só espere e publique.
        if (is_array($resume) && !empty($resume['pending'])) {
            $res = self::igPublishState($igId, $token, $resume['pending']);
            return is_array($res) ? $res : ['error' => (string)$res];
        }

        $medias = self::igMediaList($post);
        if (count($medias) === 0) return ['error' => 'Instagram exige ao menos uma imagem ou vídeo na publicação.'];

        $caption = (string)$post['caption'];
        if (count($medias) > 1) {
            if (count($medias) > 10) {
                return ['error' => 'Instagram: carrossel suporta no máximo 10 mídias (recebeu ' . count($medias) . ').'];
            }
            $containers = [];
            foreach ($medias as $m) {
                $c = self::igCreateContainer($igId, $token, $m, true);
                if (isset($c['error'])) return $c;
                $containers[] = (string)$c['id'];
            }
            $pending = ['type' => 'carousel', 'containers' => $containers, 'message' => $caption];
        } else {
            $c = self::igCreateContainer($igId, $token, $medias[0], false);
            if (isset($c['error'])) return $c;
            $pending = ['type' => 'single', 'containers' => [(string)$c['id']], 'message' => $caption];
        }
        $res = self::igPublishState($igId, $token, $pending);
        return is_array($res) ? $res : ['error' => (string)$res];
    }

    private static function threads(string $token, array $meta, array $post): array
    {
        $thId = (string)($meta['threads_user_id'] ?? '');
        if ($thId === '') return ['error' => 'Conexão sem perfil do Threads. Reconecte a conta.'];

        $form = ['text' => mb_substr((string)$post['caption'], 0, 500), 'access_token' => $token];
        if (!empty($post['media_url'])) {
            $form['media_type'] = 'IMAGE';
            $form['image_url'] = $post['media_url'];
        } else {
            $form['media_type'] = 'TEXT';
        }

        $create = SocialHttp::json('POST', "https://graph.threads.net/v1.0/{$thId}/threads", ['form' => $form]);
        if (empty($create['id'])) {
            return ['error' => SocialHttp::errorMsg($create, 'Falha ao criar a publicação no Threads.')];
        }

        $pub = SocialHttp::json('POST', "https://graph.threads.net/v1.0/{$thId}/threads_publish", [
            'form' => ['creation_id' => (string)$create['id'], 'access_token' => $token],
        ]);
        if (!empty($pub['id'])) return ['remote_id' => (string)$pub['id']];
        return ['error' => SocialHttp::errorMsg($pub, 'Falha ao publicar no Threads.')];
    }

    private static function x(string $token, array $meta, array $post): array
    {
        $payload = ['text' => (string)$post['caption']];

        if (!empty($post['media_url'])) {
            $bytes = self::mediaBytes((string)$post['media_url']);
            if ($bytes === null) {
                return ['error' => 'Não foi possível baixar a imagem para publicar no X.'];
            }
            $tmp = tempnam(sys_get_temp_dir(), 'sx');
            file_put_contents($tmp, $bytes);
            $mime = self::mimeFor((string)$post['media_url'], (string)($post['media_kind'] ?? 'image'));
            $up = SocialHttp::json('POST', 'https://upload.x.com/1.1/media/upload.json', [
                'headers' => ['Authorization: Bearer ' . $token],
                'multipart' => [
                    'media' => new CURLFile($tmp, $mime, 'media.' . self::extFor($mime)),
                    'media_category' => 'tweet_image',
                ],
            ]);
            @unlink($tmp);
            if (empty($up['media_id_string'])) {
                return ['error' => 'Falha ao enviar a imagem ao X: ' . SocialHttp::errorMsg($up, 'upload recusado')];
            }
            $payload['media'] = ['media_ids' => [(string)$up['media_id_string']]];
        }

        $r = SocialHttp::json('POST', 'https://api.x.com/2/tweets', [
            'headers' => ['Authorization: Bearer ' . $token],
            'json' => $payload,
        ]);
        if (!empty($r['data']['id'])) return ['remote_id' => (string)$r['data']['id']];
        return ['error' => SocialHttp::errorMsg($r, 'Falha ao publicar no X.')];
    }

    private static function tiktok(string $token, array $meta, array $post): array
    {
        if (empty($post['media_url'])) return ['error' => 'TikTok exige um vídeo na publicação.'];

        $r = SocialHttp::json('POST', 'https://open.tiktokapis.com/v2/post/publish/video/init/', [
            'headers' => ['Authorization: Bearer ' . $token],
            'json' => [
                'post_info' => [
                    'title' => mb_substr((string)$post['caption'], 0, 2200),
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_duet' => false,
                    'disable_comment' => false,
                    'disable_stitch' => false,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => (string)$post['media_url'],
                ],
            ],
        ]);

        if (!empty($r['data']['publish_id'])) return ['remote_id' => (string)$r['data']['publish_id']];
        $err = SocialHttp::errorMsg($r, 'Falha ao publicar no TikTok.');
        if (stripos($err, 'audit') !== false || stripos($err, 'permission') !== false || stripos($err, '40103') !== false) {
            $err .= ' (Beta: até a auditoria da app, o TikTok pode exigir conta verificada — acompanhe o status na tela de conexões.)';
        }
        return ['error' => $err];
    }

    /** Bytes da midia: URL local mapeada para arquivo (uploads/) ou URL remota. */
    private static function mediaBytes(string $url): ?string
    {
        $base = Config::getBaseUrl();
        if ($base !== '' && str_starts_with($url, $base)) {
            $rel = ltrim(substr($url, strlen($base)), '/');
            $local = Config::getRootDir() . '/' . $rel;
            if (is_file($local)) {
                $raw = file_get_contents($local);
                return $raw === false ? null : $raw;
            }
        }
        return SocialHttp::bytes($url);
    }

    private static function mimeFor(string $url, string $kind): string
    {
        $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => $kind === 'video' ? 'video/mp4' : 'image/jpeg',
        };
    }

    private static function extFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            default => 'jpg',
        };
    }

    // ---------------- Instagram (imagem / Reel / carrossel 2-10) ----------------

    /** @return array<int, array{url:string, kind:string}> */
    private static function igMediaList(array $post): array
    {
        $urls = [];
        if (!empty($post['media_urls'])) {
            $decoded = json_decode((string)$post['media_urls'], true);
            if (is_array($decoded)) $urls = $decoded;
        }
        if ($urls === [] && !empty($post['media_url'])) {
            $urls = [['url' => (string)$post['media_url'], 'kind' => (string)($post['media_kind'] ?? 'image')]];
        }
        $out = [];
        foreach ($urls as $u) {
            $url = trim((string)($u['url'] ?? ''));
            if ($url === '') continue;
            $kind = (string)($u['kind'] ?? 'image');
            if (!in_array($kind, ['image', 'video'], true)) $kind = 'image';
            $out[] = ['url' => $url, 'kind' => $kind];
        }
        return $out;
    }

    /** Cria um container de mídia para o Instagram. */
    private static function igCreateContainer(string $igId, string $token, array $media, bool $isCarouselItem): array
    {
        $params = ['access_token' => $token];
        if ($media['kind'] === 'video') {
            $params['video_url'] = $media['url'];
            $params['media_type'] = $isCarouselItem ? 'VIDEO' : 'REELS';
        } else {
            $params['image_url'] = $media['url'];
        }
        if ($isCarouselItem) {
            $params['is_carousel_item'] = 'true';
        }
        $r = SocialHttp::json('POST', "https://graph.facebook.com/v26.0/{$igId}/media", ['form' => $params]);
        if (empty($r['id'])) {
            return ['error' => SocialHttp::errorMsg($r, 'Falha ao criar a mídia no Instagram.')];
        }
        return ['id' => (string)$r['id']];
    }

    /** Espera os containers processarem (IN_PROGRESS) e publica.
     * Chamado tanto no fluxo inicial (create) quanto na retomada (o orquestrador
     * salva o estado em pending_data e rechama aqui sem recriar os containers).
     * Se ainda estiver processando quando o worker morre, devolve ['pending' => $pending]
     * para que o próximo polling retome — nunca recria o post.
     */
    public static function igPublishState(string $igId, string $token, array $pending, int $attempt = 0): array
    {
        if ($attempt > 10) return ['error' => 'Tempo de processamento da mídia no Instagram excedido. Tente novamente.'];

        $type = (string)($pending['type'] ?? 'single');
        $containers = (array)($pending['containers'] ?? []);

        if ($type === 'carousel' && !isset($pending['carouselId'])) {
            $children = implode(',', $containers);
            $r = SocialHttp::json('POST', "https://graph.facebook.com/v26.0/{$igId}/media", [
                'form' => [
                    'media_type' => 'CAROUSEL', 'children' => $children,
                    'caption' => (string)($pending['message'] ?? ''), 'access_token' => $token,
                ],
            ]);
            if (empty($r['id'])) {
                return ['error' => SocialHttp::errorMsg($r, 'Falha ao criar o carrossel no Instagram.')];
            }
            $pending['carouselId'] = (string)$r['id'];
        }

        $checkId = $type === 'carousel' ? (string)($pending['carouselId'] ?? '') : (string)($containers[0] ?? '');
        if ($checkId === '') return ['error' => 'Container do Instagram ausente.'];

        $st = SocialHttp::json('GET', "https://graph.facebook.com/v26.0/{$checkId}", [
            'form' => ['fields' => 'status_code,status', 'access_token' => $token],
        ]);
        $code = (string)($st['status_code'] ?? '');
        if ($code === 'ERROR' || $code === 'EXPIRED') {
            return ['error' => SocialHttp::errorMsg($st, 'Instagram não processou a mídia.')];
        }
        if ($code === 'IN_PROGRESS') {
            if ($attempt < 3) {
                sleep(20);
                return self::igPublishState($igId, $token, $pending, $attempt + 1);
            }
            // Ainda processando: devolve o estado para o orquestrador salvar
            // e retomar no próximo polling — sem recriar o post.
            return ['pending' => $pending];
        }

        $creationId = $type === 'carousel' ? (string)$pending['carouselId'] : (string)$containers[0];
        $pub = SocialHttp::json('POST', "https://graph.facebook.com/v26.0/{$igId}/media_publish", [
            'form' => ['creation_id' => $creationId, 'access_token' => $token],
        ]);
        if (!empty($pub['id'])) return ['remote_id' => (string)$pub['id']];
        return ['error' => SocialHttp::errorMsg($pub, 'Falha ao publicar no Instagram.')];
    }
}
