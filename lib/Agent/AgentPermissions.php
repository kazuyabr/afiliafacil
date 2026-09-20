<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/AgentTools.php';

/**
 * Permissoes do Socio de IA por usuario (agente principal).
 *
 * - Tools de LEITURA executam automaticamente (sem confirmacao).
 * - Tools de ACAO exigem confirmacao do usuario.
 * - O cliente pode desligar tools especificas (allowlist) — vale para o Socio
 *   principal; para subagentes a allowlist e por subagente (`agent_subagents.tools`).
 */
class AgentPermissions
{
    public const PREF_KEY = 'allowed_tools';

    /** Tools que exigem confirmacao (escrita/acoes sensiveis). */
    public static function sensitive(): array
    {
        return array_values(array_diff(array_column(AgentTools::definitions(), 'name'), AgentTools::LEITURA));
    }

    /** Todas as tools conhecidas (default = tudo permitido). */
    public static function all(): array
    {
        return array_column(AgentTools::definitions(), 'name');
    }

    /** Allowlist do agente principal (default: todas). */
    public static function allowedTools(int $userId): array
    {
        if (!Database::available()) return self::all();

        try {
            $profile = \AfiliaFacil\Models\AgentProfile::where('user_id', $userId)->first();
            $prefs = $profile->preferences ?? [];
            $tools = $prefs[self::PREF_KEY] ?? null;

            if (!is_array($tools)) return self::all();

            $valid = array_values(array_intersect(array_map('strval', $tools), self::all()));
            return $valid;
        } catch (Throwable $e) {
            return self::all();
        }
    }

    public static function allows(int $userId, string $tool): bool
    {
        return in_array($tool, self::allowedTools($userId), true);
    }

    public static function isReading(string $tool): bool
    {
        return in_array($tool, AgentTools::LEITURA, true);
    }

    /** Salva a allowlist (array vazio = nega tudo; null/ausente = tudo). */
    public static function save(int $userId, array $tools): array
    {
        if (!Database::available()) return ['error' => 'Banco de dados indisponível.'];

        $valid = array_values(array_unique(array_intersect(array_map('strval', $tools), self::all())));

        try {
            $profile = \AfiliaFacil\Models\AgentProfile::where('user_id', $userId)->first();
            $prefs = $profile->preferences ?? [];
            $prefs[self::PREF_KEY] = $valid;

            if ($profile) {
                $profile->preferences = $prefs;
                $profile->updated_at = date('Y-m-d H:i:s');
                $profile->save();
            } else {
                \AfiliaFacil\Models\AgentProfile::create([
                    'user_id' => $userId,
                    'preferences' => $prefs,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return ['success' => true, 'allowed_tools' => $valid];
        } catch (Throwable $e) {
            return ['error' => 'Não foi possível salvar as permissões.'];
        }
    }

    /** Filtra as definicoes de tools para o prompt do agente principal. */
    public static function filterDefinitions(int $userId): array
    {
        $allowed = self::allowedTools($userId);
        return array_values(array_filter(AgentTools::definitions(), fn($t) => in_array($t['name'], $allowed, true)));
    }
}
