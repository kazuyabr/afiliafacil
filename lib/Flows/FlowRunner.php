<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/../Social/SocialHttp.php';
require_once __DIR__ . '/../Social/SocialNetworks.php';
require_once __DIR__ . '/../Social/SocialPublisher.php';

/**
 * Motor de fluxos simples (sem canvas): gatilhos herdados dos templates n8n —
 * schedule (hora fixa + dias da semana) e post_published — e acoes
 * publish_post (publica via SocialPublisher) e webhook (POST JSON).
 *
 * Protecoes: sem cascata (depth guard no post_published), last_run_at gravado
 * ANTES da execucao (re-entrancia) e cada execucao deixa um flow_runs.
 */
class FlowRunner
{
    public const TRIGGERS = ['schedule', 'post_published'];
    public const ACTIONS = ['publish_post', 'webhook'];
    public const TZ = 'America/Sao_Paulo';

    /** Guarda contra cascata (post publicado por um fluxo dispara outro fluxo...). */
    private static int $depth = 0;

    // ------------------------------------------------------------ CRUD

    /** @return array lista de fluxos do usuario (configs decodificadas). */
    public static function list(int $userId): array
    {
        if (!Database::available()) return [];
        try {
            return \AfiliaFacil\Models\Flow::where('user_id', $userId)
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn ($f) => self::present($f))->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Cria um fluxo com validacao completa de trigger/action configs.
     * @return array{ok:bool, id?:int, error?:string, errors?:array}
     */
    public static function create(int $userId, array $input): array
    {
        if (!Database::available()) return ['ok' => false, 'error' => 'Banco de dados indisponível.'];

        $name = trim((string)($input['name'] ?? ''));
        $trigger = (string)($input['trigger_kind'] ?? '');
        $action = (string)($input['action_kind'] ?? '');
        // Aceita config como array (JSON body) ou string JSON (FormData)
        $tCfg = $input['trigger_config'] ?? [];
        $aCfg = $input['action_config'] ?? [];
        if (is_string($tCfg)) { $d = json_decode($tCfg, true); $tCfg = is_array($d) ? $d : []; }
        if (is_string($aCfg)) { $d = json_decode($aCfg, true); $aCfg = is_array($d) ? $d : []; }
        if (!is_array($tCfg)) $tCfg = [];
        if (!is_array($aCfg)) $aCfg = [];

        if ($name === '') return ['ok' => false, 'error' => 'Dê um nome ao fluxo.'];
        if (mb_strlen($name) > 120) return ['ok' => false, 'error' => 'Nome muito longo (máx. 120).'];
        if (!in_array($trigger, self::TRIGGERS, true)) return ['ok' => false, 'error' => 'Gatilho inválido.'];
        if (!in_array($action, self::ACTIONS, true)) return ['ok' => false, 'error' => 'Ação inválida.'];

        if ($trigger === 'schedule') {
            $err = self::validateSchedule($tCfg);
            if ($err !== null) return ['ok' => false, 'error' => $err];
        }
        $err = self::validateAction($action, $aCfg);
        if ($err !== null) return ['ok' => false, 'error' => $err];

        try {
            $now = date('Y-m-d H:i:s');
            $flow = \AfiliaFacil\Models\Flow::create([
                'user_id' => $userId,
                'name' => $name,
                'trigger_kind' => $trigger,
                'trigger_config' => json_encode($tCfg, JSON_UNESCAPED_UNICODE),
                'action_kind' => $action,
                'action_config' => json_encode($aCfg, JSON_UNESCAPED_UNICODE),
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['ok' => true, 'id' => (int)$flow->id, 'flow' => self::present($flow)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Falha ao criar o fluxo: ' . $e->getMessage()];
        }
    }

    /** Ativa/desativa (ou alterna quando $enabled é null). */
    public static function toggle(int $userId, int $flowId, ?bool $enabled = null): array
    {
        if (!Database::available()) return ['ok' => false, 'error' => 'Banco indisponível.'];
        try {
            $flow = \AfiliaFacil\Models\Flow::where('id', $flowId)->where('user_id', $userId)->first();
            if (!$flow) return ['ok' => false, 'error' => 'Fluxo não encontrado.'];
            $flow->enabled = $enabled !== null ? $enabled : !$flow->enabled;
            $flow->updated_at = date('Y-m-d H:i:s');
            $flow->save();
            return ['ok' => true, 'enabled' => (bool)$flow->enabled];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function delete(int $userId, int $flowId): bool
    {
        if (!Database::available()) return false;
        try {
            $flow = \AfiliaFacil\Models\Flow::where('id', $flowId)->where('user_id', $userId)->first();
            if (!$flow) return false;
            \AfiliaFacil\Models\FlowRun::where('flow_id', $flowId)->delete();
            $flow->delete();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Execucoes recentes do fluxo (para a UI). */
    public static function runs(int $userId, int $flowId, int $limit = 10): array
    {
        if (!Database::available()) return [];
        try {
            $flow = \AfiliaFacil\Models\Flow::where('id', $flowId)->where('user_id', $userId)->first();
            if (!$flow) return [];
            return \AfiliaFacil\Models\FlowRun::where('flow_id', $flowId)
                ->orderByDesc('id')->limit(max(1, min(50, $limit)))->get()
                ->map(fn ($r) => [
                    'id' => (int)$r->id,
                    'event' => $r->event,
                    'status' => $r->status,
                    'detail' => $r->detail,
                    'ran_at' => $r->ran_at,
                ])->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------- execucao

    /**
     * Roda fluxos de gatilho schedule cuja ultima ocorrencia ja passou
     * (chamado pelo cron e pelo polling da tela).
     */
    public static function runDue(int $limit = 10): array
    {
        $summary = ['processed' => 0, 'ok' => 0, 'failed' => 0];
        if (!Database::available()) return $summary;

        try {
            $flows = \AfiliaFacil\Models\Flow::where('enabled', true)
                ->where('trigger_kind', 'schedule')->orderBy('id')
                ->limit(max(1, min(50, $limit)))->get();

            foreach ($flows as $flow) {
                $dueAt = self::lastDueAt(
                    self::cfg($flow, 'trigger'),
                    $flow->last_run_at ? (string)$flow->last_run_at : null,
                    (string)$flow->created_at
                );
                if ($dueAt === null) continue;

                $summary['processed']++;
                $res = self::run($flow, 'schedule', ['due_at' => $dueAt]);
                if (!empty($res['ok'])) $summary['ok']++;
                else $summary['failed']++;
            }
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }

        return $summary;
    }

    /**
     * Dispara os fluxos do usuario quando um post foi publicado.
     * Chamado por SocialPublisher::publishPost — nunca propaga erro.
     */
    public static function onPostPublished(int $userId, int $postId): array
    {
        $summary = ['triggered' => 0, 'ok' => 0, 'failed' => 0];
        if (self::$depth >= 1) return $summary; // sem cascata entre fluxos
        if (!Database::available()) return $summary;

        try {
            $flows = \AfiliaFacil\Models\Flow::where('user_id', $userId)
                ->where('enabled', true)->where('trigger_kind', 'post_published')->get();
            if ($flows->isEmpty()) return $summary;

            $post = \AfiliaFacil\Models\SocialPost::find($postId);
            if (!$post) return $summary;

            $networks = \AfiliaFacil\Models\SocialPostTarget::where('post_id', $postId)
                ->get()->map(fn ($t) => $t->network)->values()->all();

            $data = [
                'post_id' => (int)$postId,
                'caption' => mb_substr((string)$post->caption, 0, 300),
                'status' => (string)$post->status,
                'networks' => $networks,
            ];

            self::$depth++;
            try {
                foreach ($flows as $flow) {
                    $summary['triggered']++;
                    $res = self::run($flow, 'post_published', $data);
                    if (!empty($res['ok'])) $summary['ok']++;
                    else $summary['failed']++;
                }
            } finally {
                self::$depth--;
            }
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }

        return $summary;
    }

    /** Executa um fluxo (gravando o run e atualizando last_run_at/last_status). */
    public static function run(\AfiliaFacil\Models\Flow $flow, string $event, array $data = []): array
    {
        // Marca ANTES de executar: se a acao publicar um post e disparar de novo
        // este mesmo gatilho, a re-entrancia e cortada pela hora do run.
        $now = date('Y-m-d H:i:s');
        $flow->last_run_at = $now;
        $flow->updated_at = $now;
        $flow->save();

        try {
            $cfg = self::cfg($flow, 'action');
            $res = $flow->action_kind === 'publish_post'
                ? self::execPublishPost($flow, $cfg, $data)
                : self::execWebhook($flow, $cfg, $event, $data);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'detail' => 'Erro: ' . $e->getMessage()];
        }

        $ok = !empty($res['ok']);
        $detail = mb_substr((string)($res['detail'] ?? ($ok ? 'OK' : 'Falha')), 0, 2000);

        $flow->last_status = $ok ? 'ok' : 'failed';
        $flow->save();

        try {
            \AfiliaFacil\Models\FlowRun::create([
                'flow_id' => (int)$flow->id,
                'event' => $event,
                'status' => $ok ? 'ok' : 'failed',
                'detail' => $detail,
                'ran_at' => $now,
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            // log best-effort
        }

        return ['ok' => $ok, 'detail' => $detail, 'flow_id' => (int)$flow->id];
    }

    // ------------------------------------------------------- acoes

    private static function execPublishPost(\AfiliaFacil\Models\Flow $flow, array $cfg, array $data): array
    {
        $user = \AfiliaFacil\Models\User::find((int)$flow->user_id);
        if (!$user) return ['ok' => false, 'detail' => 'Usuário do fluxo não encontrado.'];
        if (empty($user->active)) return ['ok' => false, 'detail' => 'Conta desativada.'];

        $plan = (string)$user->plan;
        $res = SocialPublisher::create((int)$flow->user_id, $plan, [
            'caption' => (string)($cfg['caption'] ?? ''),
            'media_url' => (string)($cfg['media_url'] ?? ''),
            'media_kind' => (string)($cfg['media_kind'] ?? ''),
            'networks' => array_values((array)($cfg['networks'] ?? [])),
            'source' => 'manual',
        ]);

        if (empty($res['ok'])) {
            return ['ok' => false, 'detail' => 'Publicação: ' . (string)($res['error'] ?? 'falha')];
        }

        $targets = array_map(
            fn ($t) => SocialNetworks::label($t['network']) . '=' . $t['status'],
            (array)($res['targets'] ?? [])
        );
        return ['ok' => true, 'detail' => 'Post #' . (int)$res['post_id'] . ' (' . $res['status'] . ') — '
            . implode(', ', $targets)];
    }

    private static function execWebhook(\AfiliaFacil\Models\Flow $flow, array $cfg, string $event, array $data): array
    {
        $url = trim((string)($cfg['url'] ?? ''));
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'detail' => 'URL de webhook inválida (use http/https).'];
        }

        $payload = [
            'flow_id' => (int)$flow->id,
            'flow_name' => (string)$flow->name,
            'event' => $event,
            'user_id' => (int)$flow->user_id,
            'data' => $data,
            'fired_at' => date('c'),
        ];
        $resp = SocialHttp::request('POST', $url, ['json' => $payload, 'timeout' => 15]);
        $ok = $resp['status'] >= 200 && $resp['status'] < 300;
        return [
            'ok' => $ok,
            'detail' => 'POST ' . $url . ' → HTTP ' . $resp['status']
                . ($ok ? '' : ' — ' . mb_substr($resp['body'], 0, 300)),
        ];
    }

    // ------------------------------------------------------- helpers

    /**
     * Ultima ocorrencia vencida do schedule (hora/dias em America/Sao_Paulo).
     * Retorna o datetime da ocorrecao quando ainda nao foi executada, senao null.
     */
    public static function lastDueAt(array $cfg, ?string $lastRunAt, string $createdAt): ?string
    {
        $time = (string)($cfg['time'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) return null;

        $days = array_values(array_unique(array_filter(
            array_map('intval', (array)($cfg['days'] ?? [])),
            fn ($d) => $d >= 0 && $d <= 6
        )));
        if ($days === []) $days = [0, 1, 2, 3, 4, 5, 6];

        try {
            $tz = new DateTimeZone(self::TZ);
            $occ = new DateTime('today ' . $time, $tz);
            while ($occ->getTimestamp() > time()) {
                $occ->modify('-1 day');
            }
            for ($i = 0; $i < 8; $i++) {
                if (in_array((int)$occ->format('w'), $days, true)) {
                    $occStr = $occ->format('Y-m-d H:i:s');
                    $baseline = $lastRunAt ?: $createdAt; // fluxo novo nao "atrasa" execucoes antigas
                    return $baseline < $occStr ? $occStr : null;
                }
                $occ->modify('-1 day');
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /** Le uma config JSON do fluxo. */
    public static function cfg(\AfiliaFacil\Models\Flow $flow, string $which): array
    {
        $raw = (string)($which === 'trigger' ? $flow->trigger_config : $flow->action_config);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function validateSchedule(array $cfg): ?string
    {
        $time = (string)($cfg['time'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return 'Informe um horário válido (HH:MM).';
        }
        $days = $cfg['days'] ?? null;
        if (!is_array($days) || $days === []) return 'Escolha ao menos um dia da semana.';
        foreach ($days as $d) {
            if (!is_numeric($d) || (int)$d < 0 || (int)$d > 6) return 'Dia da semana inválido.';
        }
        return null;
    }

    private static function validateAction(string $action, array $cfg): ?string
    {
        if ($action === 'webhook') {
            $url = trim((string)($cfg['url'] ?? ''));
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
                return 'Informe uma URL válida (http/https) para o webhook.';
            }
            return null;
        }

        // publish_post: replica as regras do composer (rede conectada valida na execucao)
        $networks = array_values(array_unique(array_filter((array)($cfg['networks'] ?? []))));
        if ($networks === []) return 'Selecione ao menos uma rede social.';
        foreach ($networks as $n) {
            if (!SocialNetworks::supports($n)) return 'Rede não suportada: ' . $n;
        }
        $caption = trim((string)($cfg['caption'] ?? ''));
        $mediaUrl = trim((string)($cfg['media_url'] ?? ''));
        if ($caption === '' && $mediaUrl === '') return 'Escreva a legenda do post.';
        foreach ($networks as $n) {
            $m = SocialNetworks::meta()[$n];
            if ($m['media_required'] && $mediaUrl === '') {
                return SocialNetworks::label($n) . ' exige mídia (' . ($m['media'] === 'video' ? 'vídeo' : 'imagem') . ').';
            }
            if ($caption !== '' && mb_strlen($caption) > $m['max_len']) {
                return SocialNetworks::label($n) . ': legenda excede ' . $m['max_len'] . ' caracteres.';
            }
        }
        return null;
    }

    /** Versao "limpa" do fluxo para a API/UI (configs decodificadas). */
    public static function present(\AfiliaFacil\Models\Flow $f): array
    {
        return [
            'id' => (int)$f->id,
            'name' => (string)$f->name,
            'trigger_kind' => (string)$f->trigger_kind,
            'trigger_config' => self::cfg($f, 'trigger'),
            'action_kind' => (string)$f->action_kind,
            'action_config' => self::cfg($f, 'action'),
            'enabled' => (bool)$f->enabled,
            'last_run_at' => $f->last_run_at,
            'last_status' => $f->last_status,
            'created_at' => $f->created_at,
        ];
    }
}
