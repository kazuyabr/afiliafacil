<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/AgentSubagents.php';

class AgentKnowledge
{
    public const MAX_DOCS = 20;
    public const MAX_CHARS = 30000;
    private const FULL_INJECT_CHARS = 6000;
    private const CHUNK_CHARS = 1200;
    private const TOP_CHUNKS = 4;

    /**
     * Documentos do dono (subagent_id 0 = Sócio; >0 = subagente específico).
     */
    public static function list(int $userId, int $subagentId = 0): array
    {
        if (!Database::available()) return [];

        try {
            $query = \AfiliaFacil\Models\AgentKnowledge::where('user_id', $userId);
            if ($subagentId > 0) {
                $query->where('subagent_id', $subagentId);
            } else {
                $query->whereNull('subagent_id');
            }
            return $query->orderByDesc('updated_at')->get()->map(fn($d) => [
                'id' => (int)$d->id,
                'title' => $d->title,
                'chars' => mb_strlen((string)$d->content),
                'updated_at' => (string)$d->updated_at,
            ])->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function save(int $userId, int $subagentId, string $title, string $content, int $id = 0): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $title = trim($title);
        $content = trim($content);
        if ($title === '') return ['error' => 'Informe um título para o documento.'];
        if ($content === '') return ['error' => 'O documento está vazio.'];
        if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 120);
        if (mb_strlen($content) > self::MAX_CHARS) {
            return ['error' => 'Documento muito longo (máximo de ' . self::MAX_CHARS . ' caracteres). Divida em documentos menores.'];
        }

        try {
            if ($subagentId > 0 && !AgentSubagents::get($userId, $subagentId)) {
                return ['error' => 'Subagente não encontrado.'];
            }

            if ($id > 0) {
                $doc = \AfiliaFacil\Models\AgentKnowledge::where('id', $id)->where('user_id', $userId)->first();
                if (!$doc) return ['error' => 'Documento não encontrado.'];
                $doc->title = $title;
                $doc->content = $content;
                $doc->updated_at = date('Y-m-d H:i:s');
                $doc->save();
                return ['success' => true, 'id' => (int)$doc->id];
            }

            $count = \AfiliaFacil\Models\AgentKnowledge::where('user_id', $userId)
                ->when($subagentId > 0, fn($q) => $q->where('subagent_id', $subagentId), fn($q) => $q->whereNull('subagent_id'))
                ->count();
            if ($count >= self::MAX_DOCS) {
                return ['error' => 'Limite de ' . self::MAX_DOCS . ' documentos por agente. Apague um para adicionar outro.'];
            }

            $doc = \AfiliaFacil\Models\AgentKnowledge::create([
                'user_id' => $userId,
                'subagent_id' => $subagentId > 0 ? $subagentId : null,
                'title' => $title,
                'content' => $content,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return ['success' => true, 'id' => (int)$doc->id];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao salvar: ' . $e->getMessage()];
        }
    }

    public static function delete(int $userId, int $id): bool
    {
        if (!Database::available()) return false;

        try {
            return (bool)\AfiliaFacil\Models\AgentKnowledge::where('id', $id)->where('user_id', $userId)->delete();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!Database::available()) return null;

        try {
            $doc = \AfiliaFacil\Models\AgentKnowledge::where('id', $id)->where('user_id', $userId)->first();
            if (!$doc) return null;
            return ['id' => (int)$doc->id, 'title' => $doc->title, 'content' => (string)$doc->content, 'subagent_id' => $doc->subagent_id ? (int)$doc->subagent_id : 0];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Monta o bloco CONHECIMENTO para o prompt: docs pequenos entram inteiros;
     * base grande usa recuperação por palavras-chave contra a mensagem atual.
     */
    public static function forPrompt(int $userId, int $subagentId, string $message): string
    {
        if (!Database::available()) return '';

        try {
            $docs = [];
            if ($subagentId > 0) {
                foreach (self::loadDocs($userId, $subagentId) as $d) $docs[] = ['scope' => 'subagente', 'doc' => $d];
            }
            foreach (self::loadDocs($userId, 0) as $d) $docs[] = ['scope' => 'sócio', 'doc' => $d];
            if (empty($docs)) return '';

            $total = array_sum(array_map(fn($d) => mb_strlen($d['doc']['content']), $docs));
            if ($total <= self::FULL_INJECT_CHARS) {
                $out = "CONHECIMENTO DO USUÁRIO (material próprio dele — use quando relevante, tem prioridade sobre conhecimento geral):\n";
                foreach ($docs as $d) {
                    $out .= "\n[doc {$d['scope']}: {$d['doc']['title']}]\n" . trim($d['doc']['content']) . "\n";
                }
                return $out;
            }

            $chunks = [];
            foreach ($docs as $d) {
                foreach (self::splitChunks($d['doc']['content']) as $chunk) {
                    $chunks[] = ['scope' => $d['scope'], 'title' => $d['doc']['title'], 'text' => $chunk];
                }
            }
            $picked = self::pickChunks($chunks, $message);
            if (empty($picked)) return '';

            $out = "CONHECIMENTO DO USUÁRIO (trechos relevantes do material próprio dele — use quando relevante, tem prioridade sobre conhecimento geral):\n";
            foreach ($picked as $c) {
                $out .= "\n[doc {$c['scope']}: {$c['title']}]\n" . $c['text'] . "\n";
            }
            return $out;
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function loadDocs(int $userId, int $subagentId): array
    {
        $query = \AfiliaFacil\Models\AgentKnowledge::where('user_id', $userId);
        if ($subagentId > 0) {
            $query->where('subagent_id', $subagentId);
        } else {
            $query->whereNull('subagent_id');
        }
        return $query->orderBy('id')->get()->map(fn($d) => [
            'title' => $d->title,
            'content' => (string)$d->content,
        ])->all();
    }

    private static function splitChunks(string $content): array
    {
        $paras = preg_split('/\n\s*\n/u', trim($content)) ?: [$content];
        $chunks = [];
        $current = '';
        foreach ($paras as $para) {
            $para = trim($para);
            if ($para === '') continue;
            if (mb_strlen($current) + mb_strlen($para) + 2 > self::CHUNK_CHARS && $current !== '') {
                $chunks[] = $current;
                $current = $para;
            } else {
                $current .= ($current === '' ? '' : "\n\n") . $para;
            }
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }

    private static function keywords(string $text): array
    {
        $stop = ['para', 'como', 'mais', 'muito', 'quando', 'onde', 'isso', 'esta', 'este', 'esse', 'essa', 'aquele', 'aquela', 'com', 'dos', 'das', 'nos', 'nas', 'pelos', 'pelas', 'uma', 'uns', 'umas', 'que', 'qual', 'quais', 'seu', 'sua', 'meu', 'minha', 'the', 'and', 'for'];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) $out[$w] = true;
        }
        return array_keys($out);
    }

    private static function pickChunks(array $chunks, string $message): array
    {
        $keys = self::keywords($message);
        if (empty($keys)) return array_slice($chunks, 0, 2);

        $scored = [];
        foreach ($chunks as $i => $c) {
            $text = mb_strtolower($c['text']);
            $score = 0;
            foreach ($keys as $k) {
                if (str_contains($text, $k)) $score++;
            }
            if ($score > 0) $scored[] = ['score' => $score, 'i' => $i];
        }
        if (empty($scored)) return array_slice($chunks, 0, 2);

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $picked = [];
        foreach (array_slice($scored, 0, self::TOP_CHUNKS) as $s) {
            $picked[] = $chunks[$s['i']];
        }
        return $picked;
    }
}
