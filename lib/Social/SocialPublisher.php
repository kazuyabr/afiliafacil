<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/SocialNetworks.php';
require_once __DIR__ . '/SocialQuota.php';
require_once __DIR__ . '/SocialConnections.php';
require_once __DIR__ . '/SocialPublishers.php';
require_once __DIR__ . '/SocialOAuth.php';

/**
 * Orquestracao da publicacao unificada: cria o post + um target por rede,
 * publica (ou agenda) e calcula o status final com falha ISOLADA por rede.
 */
class SocialPublisher
{
    /**
     * Cria e publica (ou agenda) um post.
     * @param array $input caption, media_url?, media_kind?, networks[], scheduled_at?, source?
     * @return array{ok:bool, post_id?:int, status?:string, targets?:array, error?:string, errors?:array}
     */
    public static function create(int $userId, string $plan, array $input): array
    {
        $quota = SocialQuota::check($userId, $plan);
        if (!$quota['ok']) return ['ok' => false, 'error' => $quota['error']];

        $caption = trim((string)($input['caption'] ?? ''));
        $mediaUrl = trim((string)($input['media_url'] ?? ''));
        $mediaUrlsRaw = $input['media_urls'] ?? '';
        $mediaUrls = [];
        if (is_array($mediaUrlsRaw)) {
            $mediaUrls = $mediaUrlsRaw;
        } elseif (is_string($mediaUrlsRaw) && trim($mediaUrlsRaw) !== '') {
            $decoded = json_decode($mediaUrlsRaw, true);
            if (is_array($decoded)) $mediaUrls = $decoded;
        }
        $mediaUrls = array_values(array_filter(array_map(function ($u) {
            $url = trim((string)($u['url'] ?? ''));
            if ($url === '') return null;
            $kind = (string)($u['kind'] ?? 'image');
            return ['url' => $url, 'kind' => in_array($kind, ['image', 'video'], true) ? $kind : 'image'];
        }, $mediaUrls)));
        $mediaKind = $mediaUrl === '' && $mediaUrls !== [] ? (string)($mediaUrls[0]['kind'] ?? 'image')
            : ($mediaUrl === '' ? '' : (string)($input['media_kind'] ?? 'image'));
        $networks = array_values(array_unique(array_filter((array)($input['networks'] ?? []))));
        $source = ($input['source'] ?? 'agent') === 'agent' ? 'agent' : 'manual';

        if ($networks === []) {
            return ['ok' => false, 'error' => 'Selecione ao menos uma rede social conectada.'];
        }
        if ($caption === '' && $mediaUrl === '') {
            return ['ok' => false, 'error' => 'Escreva uma legenda ou envie uma mídia.'];
        }

        foreach ($networks as $n) {
            if (!SocialNetworks::supports($n)) {
                return ['ok' => false, 'error' => 'Rede não suportada: ' . $n];
            }
        }

        // Conexoes ativas para todas as redes pedidas
        $missing = [];
        foreach ($networks as $n) {
            $conn = SocialConnections::find($userId, $n);
            if (!$conn) $missing[] = SocialNetworks::label($n) . ' (não conectado)';
            elseif ($conn->status !== 'connected') $missing[] = SocialNetworks::label($n) . ' (expirada — reconecte)';
        }
        if ($missing !== []) {
            return ['ok' => false, 'error' => 'Conecte antes: ' . implode(', ', $missing)];
        }

        // Politica de midia + limites de texto por rede
        $errs = [];
        foreach ($networks as $n) {
            $m = SocialNetworks::meta()[$n];
            if ($m['media_required'] && $mediaUrl === '' && $mediaUrls === []) {
                $errs[] = SocialNetworks::label($n) . ' exige mídia (' . ($m['media'] === 'video' ? 'vídeo' : 'imagem') . ')';
            }
            $kinds = $m['media_kinds'] ?? [$m['media']];
            if (($mediaUrl !== '' || $mediaUrls !== []) && !in_array($mediaKind, $kinds, true)) {
                $pretty = array_map(fn ($k) => $k === 'video' ? 'vídeo' : ($k === 'image' ? 'imagem' : $k), $kinds);
                $errs[] = SocialNetworks::label($n) . ' aceita apenas ' . implode('/', $pretty);
            }
            if ($caption !== '' && mb_strlen($caption) > $m['max_len']) {
                $errs[] = SocialNetworks::label($n) . ': legenda excede ' . $m['max_len'] . ' caracteres (atual ' . mb_strlen($caption) . ')';
            }
        }
        if ($errs !== []) return ['ok' => false, 'errors' => $errs, 'error' => implode(' · ', $errs)];

        // Carrossel: só Instagram aceita mais de 1 mídia (2 a 10).
        if (count($mediaUrls) > 1) {
            foreach ($networks as $n) {
                if ($n !== 'instagram') {
                    return ['ok' => false, 'error' => 'Carrossel (múltiplas mídias) só é suportado no Instagram.'];
                }
            }
            if (count($mediaUrls) < 2) {
                return ['ok' => false, 'error' => 'Instagram: carrossel precisa de entre 2 e 10 mídias.'];
            }
            if (count($mediaUrls) > 10) {
                return ['ok' => false, 'error' => 'Instagram: carrossel suporta no máximo 10 mídias (recebeu ' . count($mediaUrls) . ').'];
            }
        }

        // Agendamento
        $scheduledAt = null;
        if (!empty($input['scheduled_at'])) {
            $ts = strtotime((string)$input['scheduled_at']);
            if ($ts === false) return ['ok' => false, 'error' => 'Data de agendamento inválida.'];
            if ($ts < time() + 60) return ['ok' => false, 'error' => 'O agendamento precisa ser ao menos 1 minuto no futuro.'];
            $scheduledAt = date('Y-m-d H:i:s', $ts);
        }

        if (!Database::available()) return ['ok' => false, 'error' => 'Banco de dados indisponível.'];

        try {
            $now = date('Y-m-d H:i:s');
            $post = \AfiliaFacil\Models\SocialPost::create([
                'user_id' => $userId,
                'caption' => $caption,
                'media_url' => mb_substr($mediaUrl, 0, 500),
                'media_kind' => $mediaKind,
                'media_urls' => $mediaUrls !== [] ? json_encode($mediaUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'status' => $scheduledAt !== null ? 'scheduled' : 'publishing',
                'scheduled_at' => $scheduledAt,
                'source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $targets = [];
            foreach ($networks as $n) {
                $conn = SocialConnections::find($userId, $n);
                $t = \AfiliaFacil\Models\SocialPostTarget::create([
                    'post_id' => (int)$post->id,
                    'connection_id' => (int)$conn->id,
                    'network' => $n,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $targets[] = [
                    'id' => (int)$t->id,
                    'network' => $n,
                    'network_label' => SocialNetworks::label($n),
                    'status' => 'pending',
                ];
            }

            if ($scheduledAt !== null) {
                return ['ok' => true, 'post_id' => (int)$post->id, 'status' => 'scheduled',
                    'scheduled_at' => $scheduledAt, 'targets' => $targets,
                    'quota' => $quota];
            }

            $result = self::publishPost((int)$post->id);
            $result['post_id'] = (int)$post->id;
            $result['quota'] = SocialQuota::check($userId, $plan);
            return $result;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Falha ao criar a publicação: ' . $e->getMessage()];
        }
    }

    /**
     * Publica todos os targets pendentes do post (falha isolada por rede).
     * @return array{ok:bool, status?:string, targets?:array, error?:string}
     */
    public static function publishPost(int $postId): array
    {
        if (!Database::available()) return ['ok' => false, 'error' => 'Banco de dados indisponível.'];

        try {
            $post = \AfiliaFacil\Models\SocialPost::find($postId);
            if (!$post) return ['ok' => false, 'error' => 'Publicação não encontrada.'];

            $targets = \AfiliaFacil\Models\SocialPostTarget::where('post_id', $postId)
                ->whereIn('status', ['pending', 'publishing'])->orderBy('id')->get();

            if ($targets->isEmpty()) {
                return ['ok' => false, 'error' => 'Nenhum destino pendente.'];
            }

            $postInput = [
                'caption' => (string)$post->caption,
                'media_url' => (string)$post->media_url,
                'media_kind' => (string)$post->media_kind,
                'media_urls' => (string)($post->media_urls ?? ''),
            ];

            $now = date('Y-m-d H:i:s');
            if ($post->status !== 'partial' && $post->status !== 'published') {
                $post->status = 'publishing';
                $post->updated_at = $now;
                $post->save();
            }

            foreach ($targets as $t) {
                $t->status = 'publishing';
                $t->updated_at = $now;
                $t->save();

                $conn = \AfiliaFacil\Models\SocialConnection::find($t->connection_id);
                $res = null;
                if (!$conn) {
                    $res = ['error' => 'Conexão removida.'];
                } elseif ($conn->status !== 'connected') {
                    $res = ['error' => 'Conexão expirada. Reconecte a conta.'];
                } elseif (!SocialOAuth::refreshIfExpired($conn)) {
                    $res = ['error' => 'Token expirado sem renovação possível. Reconecte a conta.'];
                } else {
                    // Recarrega após possível refresh
                    $conn = \AfiliaFacil\Models\SocialConnection::find($t->connection_id) ?: $conn;
                    // Retomada: Instagram salva o estado em pending_data quando a
                    // mídia ainda está processando — não recria os containers.
                    $resume = null;
                    if (!empty($t->pending_data)) {
                        $pd = json_decode((string)$t->pending_data, true);
                        if (is_array($pd) && !empty($pd['pending'])) $resume = $pd;
                    }
                    $res = SocialPublishers::publish($conn, $postInput, $resume);
                }

                $t->refresh();
                if (!empty($res['error'])) {
                    $t->status = 'failed';
                    $t->error = mb_substr((string)$res['error'], 0, 500);
                    $t->published_at = null;
                    $t->pending_data = null;
                } elseif (!empty($res['pending'])) {
                    // Instagram: mídia ainda processando — salva o estado e
                    // deixa 'publishing'; o próximo polling retoma (sem recriar).
                    $t->pending_data = json_encode(['pending' => $res['pending']],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $t->updated_at = date('Y-m-d H:i:s');
                    $t->save();
                    $summary['pending'] = true;
                    continue;
                } else {
                    $t->status = 'published';
                    $t->remote_id = mb_substr((string)($res['remote_id'] ?? ''), 0, 190);
                    $t->error = '';
                    $t->published_at = date('Y-m-d H:i:s');
                    $t->pending_data = null;
                }
                $t->updated_at = date('Y-m-d H:i:s');
                $t->save();
            }

            $result = self::finalize($postId);

            // Fluxos com gatilho post_published (Fase 3) — nunca propaga erro
            // nem propaga mais de um nivel (FlowRunner tem guard de cascata).
            if (!empty($result['ok'])) {
                try {
                    require_once __DIR__ . '/../Flows/FlowRunner.php';
                    FlowRunner::onPostPublished((int)$post->user_id, $postId);
                } catch (Throwable $e) {
                    // ignora: fluxos nao podem quebrar a publicacao
                }
            }

            return $result;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Falha ao publicar: ' . $e->getMessage()];
        }
    }

    /** Recalcula o status do post a partir dos targets. */
    private static function finalize(int $postId): array
    {
        $post = \AfiliaFacil\Models\SocialPost::find($postId);
        $rows = \AfiliaFacil\Models\SocialPostTarget::where('post_id', $postId)->orderBy('id')->get();

        $published = $rows->where('status', 'published')->count();
        $failed = $rows->where('status', 'failed')->count();
        $total = $rows->count();

        $post->status = $published === $total ? 'published' : ($published > 0 ? 'partial' : 'failed');
        if ($published > 0 && empty($post->published_at)) $post->published_at = date('Y-m-d H:i:s');
        $post->updated_at = date('Y-m-d H:i:s');
        $post->save();

        return [
            'ok' => $published > 0,
            'status' => $post->status,
            'published' => $published,
            'failed' => $failed,
            'targets' => $rows->map(fn ($t) => [
                'id' => (int)$t->id,
                'network' => $t->network,
                'network_label' => SocialNetworks::label($t->network),
                'status' => $t->status,
                'error' => $t->error,
                'remote_id' => $t->remote_id,
                'published_at' => $t->published_at,
            ])->values()->all(),
            'error' => $published === 0
                ? 'Nenhuma rede publicou: ' . implode(' · ', $rows->map(fn ($t) => SocialNetworks::label($t->network) . ': ' . $t->error)->all())
                : null,
        ];
    }

    /** Publica posts agendados cujo horario ja passou (chamado pelo cron e pelo polling da tela). */
    public static function processDue(int $limit = 5): array
    {
        $summary = ['processed' => 0, 'published' => 0, 'failed' => 0, 'pending' => false];
        if (!Database::available()) return $summary;

        try {
            $due = \AfiliaFacil\Models\SocialPost::where('status', 'scheduled')
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', date('Y-m-d H:i:s'))
                ->orderBy('scheduled_at')->limit($limit)->get();

            foreach ($due as $post) {
                $summary['processed']++;
                $res = self::publishPost((int)$post->id);
                if (!empty($res['ok'])) $summary['published']++;
                else $summary['failed']++;
            }
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }

        return $summary;
    }

    /**
     * Retoma targets 'publishing' que ficaram com pending_data (Instagram:
     * midia ainda processando). Nunca recria os containers — só rechama o
     * publisher com o estado salvo. Chamado pelo polling da tela e pelo cron.
     * @return array{processed:int, published:int, failed:int, pending:bool}
     */
    public static function resumePending(int $limit = 5): array
    {
        $summary = ['processed' => 0, 'published' => 0, 'failed' => 0, 'pending' => false];
        if (!Database::available()) return $summary;

        try {
            $targets = \AfiliaFacil\Models\SocialPostTarget::where('status', 'publishing')
                ->whereNotNull('pending_data')->orderBy('id')->limit($limit)->get();

            foreach ($targets as $t) {
                $summary['processed']++;
                $pd = json_decode((string)$t->pending_data, true);
                if (!is_array($pd) || empty($pd['pending'])) {
                    $t->status = 'failed';
                    $t->error = 'Estado de publicação corrompido. Refaça o post.';
                    $t->pending_data = null;
                    $t->updated_at = date('Y-m-d H:i:s');
                    $t->save();
                    $summary['failed']++;
                    continue;
                }

                $conn = \AfiliaFacil\Models\SocialConnection::find($t->connection_id);
                $res = null;
                if (!$conn || $conn->status !== 'connected') {
                    $res = ['error' => 'Conexão indisponível.'];
                } elseif (!SocialOAuth::refreshIfExpired($conn)) {
                    $res = ['error' => 'Token expirado sem renovação. Reconecte a conta.'];
                } else {
                    $conn = \AfiliaFacil\Models\SocialConnection::find($t->connection_id) ?: $conn;
                    $post = \AfiliaFacil\Models\SocialPost::find($t->post_id);
                    $postInput = $post ? [
                        'caption' => (string)$post->caption,
                        'media_url' => (string)$post->media_url,
                        'media_kind' => (string)$post->media_kind,
                        'media_urls' => (string)($post->media_urls ?? ''),
                    ] : ['caption' => '', 'media_url' => '', 'media_kind' => ''];
                    $res = SocialPublishers::publish($conn, $postInput, $pd);
                }

                if (!empty($res['error'])) {
                    $t->status = 'failed';
                    $t->error = mb_substr((string)$res['error'], 0, 500);
                    $t->pending_data = null;
                    $t->published_at = null;
                } elseif (!empty($res['pending'])) {
                    $t->pending_data = json_encode(['pending' => $res['pending']],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $summary['pending'] = true;
                } else {
                    $t->status = 'published';
                    $t->remote_id = mb_substr((string)($res['remote_id'] ?? ''), 0, 190);
                    $t->error = '';
                    $t->published_at = date('Y-m-d H:i:s');
                    $t->pending_data = null;
                }
                $t->updated_at = date('Y-m-d H:i:s');
                $t->save();
            }

            if ($summary['published'] > 0 || $summary['failed'] > 0) {
                $postIds = \AfiliaFacil\Models\SocialPostTarget::whereIn('id',
                    $targets->pluck('id')->all())->pluck('post_id')->unique()->all();
                foreach ($postIds as $pid) {
                    $r = self::finalize((int)$pid);
                    if (!empty($r['ok'])) {
                        try {
                            require_once __DIR__ . '/../Flows/FlowRunner.php';
                            FlowRunner::onPostPublished((int)\AfiliaFacil\Models\SocialPost::find($pid)->user_id, (int)$pid);
                        } catch (Throwable $e) {}
                    }
                }
            }
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }
        return $summary;
    }

    /** Historico do usuario (com targets). */
    public static function list(int $userId, int $limit = 20): array
    {
        if (!Database::available()) return [];

        try {
            $posts = \AfiliaFacil\Models\SocialPost::where('user_id', $userId)
                ->orderByDesc('id')->limit(max(1, min(50, $limit)))->get();

            $byPost = [];
            $rows = \AfiliaFacil\Models\SocialPostTarget::whereIn('post_id', $posts->pluck('id')->all())->get();
            foreach ($rows as $t) $byPost[(int)$t->post_id][] = $t;

            return $posts->map(function ($p) use ($byPost) {
                $targets = $byPost[(int)$p->id] ?? [];
                return [
                    'id' => (int)$p->id,
                    'caption' => (string)$p->caption,
                    'media_url' => (string)$p->media_url,
                    'media_kind' => (string)$p->media_kind,
                    'status' => $p->status,
                    'scheduled_at' => $p->scheduled_at,
                    'published_at' => $p->published_at,
                    'source' => $p->source,
                    'created_at' => $p->created_at,
                    'targets' => array_map(fn ($t) => [
                        'network' => $t->network,
                        'network_label' => SocialNetworks::label($t->network),
                        'status' => $t->status,
                        'error' => $t->error,
                        'remote_id' => $t->remote_id,
                    ], $targets),
                ];
            })->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function delete(int $userId, int $postId): bool
    {
        if (!Database::available()) return false;

        try {
            $post = \AfiliaFacil\Models\SocialPost::where('id', $postId)
                ->where('user_id', $userId)->first();
            if (!$post) return false;
            if (in_array($post->status, ['publishing'], true)) return false;

            \AfiliaFacil\Models\SocialPostTarget::where('post_id', $postId)->delete();
            $post->delete();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Custo estimado do X (transparencia BYOK exibida antes de publicar). */
    public static function xCostEstimate(string $caption, array $networks): ?float
    {
        if (!in_array('x', $networks, true)) return null;
        return SocialNetworks::xCost($caption);
    }
}
