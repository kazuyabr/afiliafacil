<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';

class AgentSubagents
{
    public const TEMPLATES = [
        [
            'slug' => 'trafego-meta',
            'name' => 'Analista de Tráfego Meta',
            'specialty' => 'Meta Ads (Facebook/Instagram)',
            'instructions' => 'Especialista em Meta Ads: estrutura de campanhas, públicos, criativos, orçamento e otimização de custo por resultado. Sempre recomende começar pequeno e medir antes de escalar. Explique o porquê de cada decisão em linguagem simples.',
            'tools' => ['listar_ofertas', 'ver_oferta', 'espionar_anuncios', 'consultar_quotas'],
        ],
        [
            'slug' => 'copy-vsl',
            'name' => 'Copywriter de VSL',
            'specialty' => 'Copy para VSLs e anúncios',
            'instructions' => 'Especialista em copy para VSLs, anúncios e páginas de vendas: ganchos, promessas éticas, quebra de objeções, prova e CTAs. Nunca prometa resultados garantidos. Sugira variações para teste A/B.',
            'tools' => ['listar_ofertas', 'ver_oferta', 'transcrever_midia', 'gerar_narracao'],
        ],
        [
            'slug' => 'pesquisador-ofertas',
            'name' => 'Pesquisador de Ofertas',
            'specialty' => 'Garimpo e validação de ofertas',
            'instructions' => 'Especialista em encontrar ofertas escalando no swipe file e validar nicho, estrutura e sinais de risco antes de investir. Priorize ofertas com histórico consistente e alertas sobre ofertas com sinais de golpe ou promessas ilegais.',
            'tools' => ['listar_ofertas', 'ver_oferta', 'analisar_oferta', 'consultar_quotas'],
        ],
        [
            'slug' => 'analista-metricas',
            'name' => 'Analista de Métricas',
            'specialty' => 'Leitura de métricas de campanha',
            'instructions' => 'Especialista em métricas de tráfego: CTR, CPM, CPC, CPA, ROAS, taxa de conversão e decisões de escala ou corte. Explique como ler cada número e qual ação tomar, sempre com foco em não desperdiçar orçamento.',
            'tools' => ['consultar_quotas', 'listar_ofertas'],
        ],
    ];

    private const FORBIDDEN_INSTRUCTION_PATTERNS = [
        '/\b(ignore|desconsidere|esque[çc]a)\s+(as\s+)?(regras|instru[çc][õo]es|princ[íi]pios|prote[çc][õo]es)\b/i',
        '/\b(prometa|garanta|assegure)\s+(ganhos?|lucros?|resultados?|retorno)\b/i',
        '/\b(sem\s+risco|risco\s+zero|lucro\s+garantido|ganho\s+garantido)\b/i',
        '/\b(desative|remova|desligue)\s+(a\s+)?(modera[çc][ãa]o|prote[çc][ãa]o|seguran[çc]a|filtro)\b/i',
        '/\b(n[ãa]o\s+precisa\s+seguir|n[ãa]o\s+siga)\s+(as\s+)?(regras|instru[çc][õo]es)\b/i',
    ];

    public static function limit(string $plan): int
    {
        return Plans::maxSubagents($plan);
    }

    public static function used(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            return (int)\AfiliaFacil\Models\AgentSubagent::where('user_id', $userId)->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function quota(int $userId, string $plan): array
    {
        $limit = self::limit($plan);
        $used = self::used($userId);

        if ($limit === -1) {
            return ['allowed' => true, 'used' => $used, 'limit' => -1, 'remaining' => -1];
        }

        return [
            'allowed' => $used < $limit,
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    public static function list(int $userId): array
    {
        if (!Database::available()) return [];

        try {
            return \AfiliaFacil\Models\AgentSubagent::where('user_id', $userId)
                ->orderBy('name')
                ->get()
                ->map(fn($s) => self::toArray($s))
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!Database::available()) return null;

        try {
            $subagent = \AfiliaFacil\Models\AgentSubagent::where('id', $id)->where('user_id', $userId)->first();
            return $subagent ? self::toArray($subagent) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function create(int $userId, array $data, string $createdBy = 'user'): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $validation = self::validate($data);
        if (isset($validation['error'])) return $validation;

        try {
            $subagent = \AfiliaFacil\Models\AgentSubagent::create([
                'user_id' => $userId,
                'name' => $validation['name'],
                'specialty' => $validation['specialty'],
                'instructions' => $validation['instructions'],
                'tools' => $validation['tools'],
                'active' => $data['active'] ?? true,
                'created_by' => $createdBy === 'agent' ? 'agent' : 'user',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (class_exists('Audit')) {
                Audit::log('subagent_created', 'subagent', (string)$subagent->id, ['name' => $subagent->name, 'by' => $createdBy]);
            }

            return ['success' => true, 'subagent' => self::toArray($subagent)];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao criar subagente: ' . $e->getMessage()];
        }
    }

    public static function update(int $userId, int $id, array $data): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $subagent = \AfiliaFacil\Models\AgentSubagent::where('id', $id)->where('user_id', $userId)->first();
        if (!$subagent) return ['error' => 'Subagente não encontrado'];

        $merged = [
            'name' => $data['name'] ?? $subagent->name,
            'specialty' => $data['specialty'] ?? $subagent->specialty,
            'instructions' => $data['instructions'] ?? $subagent->instructions,
            'tools' => $data['tools'] ?? $subagent->tools ?? [],
        ];

        $validation = self::validate($merged);
        if (isset($validation['error'])) return $validation;

        try {
            $subagent->name = $validation['name'];
            $subagent->specialty = $validation['specialty'];
            $subagent->instructions = $validation['instructions'];
            $subagent->tools = $validation['tools'];
            if (isset($data['active'])) $subagent->active = (bool)$data['active'];
            $subagent->updated_at = date('Y-m-d H:i:s');
            $subagent->save();

            if (class_exists('Audit')) {
                Audit::log('subagent_updated', 'subagent', (string)$subagent->id, ['name' => $subagent->name]);
            }

            return ['success' => true, 'subagent' => self::toArray($subagent)];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao atualizar subagente: ' . $e->getMessage()];
        }
    }

    public static function toggle(int $userId, int $id): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $subagent = \AfiliaFacil\Models\AgentSubagent::where('id', $id)->where('user_id', $userId)->first();
        if (!$subagent) return ['error' => 'Subagente não encontrado'];

        $subagent->active = !$subagent->active;
        $subagent->updated_at = date('Y-m-d H:i:s');
        $subagent->save();

        return ['success' => true, 'active' => (bool)$subagent->active];
    }

    public static function delete(int $userId, int $id): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $subagent = \AfiliaFacil\Models\AgentSubagent::where('id', $id)->where('user_id', $userId)->first();
        if (!$subagent) return ['error' => 'Subagente não encontrado'];

        $name = $subagent->name;
        $subagent->delete();

        if (class_exists('Audit')) {
            Audit::log('subagent_deleted', 'subagent', (string)$id, ['name' => $name]);
        }

        return ['success' => true];
    }

    public static function findActive(int $userId, $identifier): ?array
    {
        if (!Database::available()) return null;

        try {
            $query = \AfiliaFacil\Models\AgentSubagent::where('user_id', $userId)->where('active', true);
            if (is_numeric($identifier)) {
                $query->where('id', (int)$identifier);
            } else {
                $query->where('name', 'like', '%' . trim((string)$identifier) . '%');
            }
            $subagent = $query->first();
            return $subagent ? self::toArray($subagent) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function allowedTools(): array
    {
        return array_column(AgentTools::definitions(), 'name');
    }

    private static function validate(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 3) {
            return ['error' => 'O nome do subagente precisa ter ao menos 3 caracteres.'];
        }
        if (mb_strlen($name) > 80) {
            return ['error' => 'Nome muito longo (máximo 80 caracteres).'];
        }

        $specialty = mb_substr(trim((string)($data['specialty'] ?? '')), 0, 120);
        $instructions = mb_substr(trim((string)($data['instructions'] ?? '')), 0, 4000);

        if ($instructions !== '') {
            foreach (self::FORBIDDEN_INSTRUCTION_PATTERNS as $pattern) {
                if (preg_match($pattern, $instructions)) {
                    return ['error' => 'As instruções não podem contrariar os princípios de proteção do Sócio de IA (sem promessas de ganho, sem desativar moderação).'];
                }
            }
        }

        $allowed = self::allowedTools();
        $tools = [];
        foreach ((array)($data['tools'] ?? []) as $tool) {
            if (is_string($tool) && in_array($tool, $allowed, true)) {
                $tools[] = $tool;
            }
        }

        return [
            'name' => $name,
            'specialty' => $specialty,
            'instructions' => $instructions,
            'tools' => array_values(array_unique($tools)),
        ];
    }

    public static function toArray($subagent): array
    {
        return [
            'id' => (int)$subagent->id,
            'name' => $subagent->name,
            'specialty' => $subagent->specialty,
            'instructions' => $subagent->instructions,
            'tools' => $subagent->tools ?? [],
            'active' => (bool)$subagent->active,
            'created_by' => $subagent->created_by,
            'created_at' => (string)$subagent->created_at,
        ];
    }
}
