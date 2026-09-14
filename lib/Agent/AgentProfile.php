<?php

require_once __DIR__ . '/../Database.php';

class AgentProfile
{
    public const FIELDS = ['niche', 'budget', 'experience'];

    public static function forUser(int $userId): array
    {
        $empty = ['niche' => '', 'budget' => '', 'experience' => '', 'goals' => [], 'preferences' => []];

        if (!Database::available()) return $empty;

        try {
            $profile = \AfiliaFacil\Models\AgentProfile::where('user_id', $userId)->first();
            if (!$profile) return $empty;

            return [
                'niche' => (string)$profile->niche,
                'budget' => (string)$profile->budget,
                'experience' => (string)$profile->experience,
                'goals' => $profile->goals ?? [],
                'preferences' => $profile->preferences ?? [],
            ];
        } catch (Throwable $e) {
            return $empty;
        }
    }

    public static function update(int $userId, array $data): void
    {
        if (!Database::available()) return;

        $payload = [];
        foreach (self::FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                $payload[$field] = mb_substr(trim($data[$field]), 0, 60);
            }
        }
        if (isset($data['goals']) && is_array($data['goals'])) {
            $payload['goals'] = array_slice(array_values(array_filter(array_map(
                fn($g) => is_string($g) ? mb_substr(trim($g), 0, 190) : null,
                $data['goals']
            ))), 0, 10);
        }
        if (isset($data['preferences']) && is_array($data['preferences'])) {
            $payload['preferences'] = array_slice($data['preferences'], 0, 20, true);
        }

        if (empty($payload)) return;

        try {
            $profile = \AfiliaFacil\Models\AgentProfile::where('user_id', $userId)->first();
            $payload['updated_at'] = date('Y-m-d H:i:s');

            if ($profile) {
                $profile->fill($payload);
                $profile->save();
            } else {
                $payload['user_id'] = $userId;
                $payload['created_at'] = date('Y-m-d H:i:s');
                \AfiliaFacil\Models\AgentProfile::create($payload);
            }
        } catch (Throwable $e) {
        }
    }

    public static function describe(array $profile): string
    {
        $parts = [];
        if ($profile['niche'] !== '') $parts[] = 'nicho: ' . $profile['niche'];
        if ($profile['budget'] !== '') $parts[] = 'orçamento: ' . $profile['budget'];
        if ($profile['experience'] !== '') $parts[] = 'experiência: ' . $profile['experience'];
        if (!empty($profile['goals'])) $parts[] = 'objetivos: ' . implode('; ', $profile['goals']);
        return $parts ? implode(' | ', $parts) : 'ainda não conhecido';
    }
}
