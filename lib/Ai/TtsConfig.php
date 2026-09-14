<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Crypto.php';

class TtsConfig
{
    public const PROVIDERS = ['cloudflare', 'openai', 'elevenlabs', 'google'];

    public const DEFAULT_MODELS = [
        'cloudflare' => '@cf/myshell-ai/melotts',
        'openai' => 'gpt-4o-mini-tts',
        'elevenlabs' => 'eleven_multilingual_v2',
        'google' => 'gemini-2.5-flash-preview-tts',
    ];

    public const VOICES = [
        'cloudflare' => [
            ['pt-BR', 'Português (Brasil)'],
            ['en-US', 'English (US)'],
            ['es-ES', 'Español'],
            ['fr-FR', 'Français'],
        ],
        'openai' => [
            ['alloy', 'Alloy'], ['ash', 'Ash'], ['coral', 'Coral'], ['echo', 'Echo'],
            ['fable', 'Fable'], ['nova', 'Nova'], ['onyx', 'Onyx'], ['sage', 'Sage'], ['shimmer', 'Shimmer'],
        ],
        'elevenlabs' => [
            ['21m00Tcm4TlvDq8ikWAM', 'Rachel'], ['AZnzlk1XvdvUeBnXmlld', 'Domi'],
            ['EXAVITQu4vr4xnSDxMaL', 'Bella'], ['ErXwobaYiN019PkySvjV', 'Antoni'],
            ['MF3mGyEYCl7XYWbV9V6O', 'Elli'], ['TxGEqnHWrfWFTfGW9XjX', 'Josh'],
            ['VR6AewLTigWG4xSOukaG', 'Arnold'], ['pNInz6obpgDQGcFmaJgB', 'Adam'],
            ['yoZ06aMxZJJ28mfd3POQ', 'Sam'],
        ],
        'google' => [
            ['Kore', 'Kore'], ['Puck', 'Puck'], ['Charon', 'Charon'], ['Fenrir', 'Fenrir'],
            ['Aoede', 'Aoede'], ['Leda', 'Leda'], ['Orus', 'Orus'], ['Zephyr', 'Zephyr'],
        ],
    ];

    public static function forUser(int $userId): array
    {
        if (Database::available()) {
            try {
                $config = \AfiliaFacil\Models\UserAiConfig::where('user_id', $userId)
                    ->where('capability', 'tts')
                    ->where('enabled', true)
                    ->first();
                if ($config) {
                    $key = Crypto::decrypt($config->api_key_encrypted ?? '') ?? '';
                    if ($key !== '') {
                        return [
                            'provider' => $config->provider,
                            'model' => $config->model,
                            'base_url' => $config->base_url,
                            'api_key' => $key,
                            'account_id' => '',
                            'source' => 'byok',
                        ];
                    }
                }
            } catch (Throwable $e) {
            }
        }

        return [
            'provider' => 'cloudflare',
            'model' => getenv('CF_TTS_MODEL') ?: self::DEFAULT_MODELS['cloudflare'],
            'base_url' => '',
            'api_key' => getenv('CF_AI_TOKEN') ?: '',
            'account_id' => getenv('CF_ACCOUNT_ID') ?: '',
            'source' => 'platform',
        ];
    }

    public static function isAvailable(int $userId): bool
    {
        $config = self::forUser($userId);
        return ($config['api_key'] ?? '') !== '';
    }

    public static function defaultVoice(string $provider): string
    {
        $voices = self::VOICES[$provider] ?? [];
        return $voices[0][0] ?? '';
    }
}
