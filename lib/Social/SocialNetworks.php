<?php

/**
 * Catalogo de redes sociais: metadados de UI, politica de midia, limites
 * de texto e custo estimado do X (BYOK — o usuario paga direto a X).
 */
class SocialNetworks
{
    public const ALL = ['facebook', 'instagram', 'threads', 'x', 'tiktok'];

    public static function meta(): array
    {
        return [
            'facebook' => [
                'name' => 'Facebook',
                'icon' => 'fab fa-facebook-f',
                'color' => '#1877f2',
                'desc' => 'Publica na sua Página do Facebook.',
                'media' => 'image',
                'media_required' => false,
                'max_len' => 63206,
                'beta' => false,
            ],
            'instagram' => [
                'name' => 'Instagram',
                'icon' => 'fab fa-instagram',
                'color' => '#E1306C',
                'desc' => 'Publica no feed — a conexão usa o login do Facebook (conta profissional ligada a uma Página).',
                'media' => 'image',
                'media_required' => true,
                'max_len' => 2200,
                'beta' => false,
            ],
            'threads' => [
                'name' => 'Threads',
                'icon' => 'fa-brands fa-threads',
                'color' => '#101010',
                'desc' => 'Publica no seu perfil do Threads.',
                'media' => 'image',
                'media_required' => false,
                'max_len' => 500,
                'beta' => false,
            ],
            'x' => [
                'name' => 'X (Twitter)',
                'icon' => 'fab fa-x-twitter',
                'color' => '#101010',
                'desc' => 'Publica no seu perfil. A API do X é paga por você direto à X (BYOK) — custo exibido antes de publicar.',
                'media' => 'image',
                'media_required' => false,
                'max_len' => 280,
                'beta' => false,
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'icon' => 'fab fa-tiktok',
                'color' => '#101010',
                'desc' => 'Vídeo público. Beta: até a auditoria da app, o TikTok pode manter o post privado.',
                'media' => 'video',
                'media_required' => true,
                'max_len' => 2200,
                'beta' => true,
            ],
        ];
    }

    public static function supports(string $network): bool
    {
        return in_array($network, self::ALL, true);
    }

    public static function label(string $network): string
    {
        return self::meta()[$network]['name'] ?? $network;
    }

    public static function maxLength(string $network): int
    {
        return self::meta()[$network]['max_len'] ?? 280;
    }

    /**
     * Custo estimado de um post no X em USD (tarifa desde abr/2026:
     * $0.015/post sem link, $0.20/post com link). Pago pelo usuario
     * direto a X (BYOK) — a plataforma nao arca com o custo.
     */
    public static function xCost(string $caption): float
    {
        return preg_match('#https?://\S+#i', $caption) ? 0.20 : 0.015;
    }
}
