<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Social/SocialNetworks.php';
require_once __DIR__ . '/../Social/SocialHttp.php';
require_once __DIR__ . '/../Social/SocialConnections.php';

/**
 * Conexao oficial das redes (híbrido pragmático):
 * - OAuth 2.0 quando as credenciais da app estao configuradas (env).
 * - Caminho manual (token do proprio usuario, BYOK) enquanto o App Review
 *   da Meta/X/TikTok nao sai — mesmas APIs oficiais, sem credencial da plataforma.
 * O usuario ve sempre o que esta conectado e pode desconectar (revoke local).
 */
class SocialOAuth
{
    public const ENV_KEYS = [
        'meta' => ['META_APP_ID', 'META_APP_SECRET'],
        'x' => ['X_CLIENT_ID', 'X_CLIENT_SECRET'],
        'tiktok' => ['TIKTOK_CLIENT_KEY', 'TIKTOK_CLIENT_SECRET'],
    ];

    private static function envPair(string $kind): array
    {
        $keys = self::ENV_KEYS[$kind] ?? [];
        $vals = array_map(fn ($k) => trim((string)(getenv($k) ?: '')), $keys);
        return ['configured' => count($vals) === 2 && !in_array('', $vals, true),
            'id' => $vals[0] ?? '', 'secret' => $vals[1] ?? ''];
    }

    /** Credenciais OAuth do provedor da rede (meta|x|tiktok). */
    public static function credentials(string $network): array
    {
        if (in_array($network, ['facebook', 'instagram', 'threads'], true)) return self::envPair('meta');
        if ($network === 'x') return self::envPair('x');
        if ($network === 'tiktok') return self::envPair('tiktok');
        return ['configured' => false, 'id' => '', 'secret' => ''];
    }

    public static function redirectUri(): string
    {
        return Config::getBaseUrl() . '/admin/api/social.php?action=callback';
    }

    private static function scopes(string $network): string
    {
        return match ($network) {
            'facebook' => 'pages_read_engagement,pages_manage_posts',
            'instagram' => 'pages_read_engagement,pages_manage_posts,instagram_content_publish',
            'threads' => 'threads_basic,threads_content_publish',
            'x' => 'tweet.read,tweet.write,users.read,offline.access',
            'tiktok' => 'video.publish,user.info.basic',
            default => '',
        };
    }

    private static function stateFor(string $network, int $userId): string
    {
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['social_oauth'] = ['network' => $network, 'user_id' => $userId, 'nonce' => $nonce];
        return base64_encode(json_encode(['n' => $network, 'u' => $userId, 'r' => $nonce]));
    }

    /** @return array{ok:bool, url?:string, error?:string} */
    public static function authorizeUrl(string $network, int $userId): array
    {
        if (!SocialNetworks::supports($network)) return ['ok' => false, 'error' => 'Rede desconhecida.'];
        $cred = self::credentials($network);
        if (!$cred['configured']) {
            return ['ok' => false, 'error' => 'oauth_not_configured'];
        }

        $state = self::stateFor($network, $userId);
        $redirect = rawurlencode(self::redirectUri());

        if (in_array($network, ['facebook', 'instagram', 'threads'], true)) {
            $url = 'https://www.facebook.com/v21.0/dialog/oauth?client_id=' . rawurlencode($cred['id'])
                . '&redirect_uri=' . $redirect
                . '&scope=' . rawurlencode(self::scopes($network))
                . '&state=' . rawurlencode($state);
            return ['ok' => true, 'url' => $url];
        }

        if ($network === 'x') {
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $_SESSION['social_oauth']['verifier'] = $verifier;
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $url = 'https://twitter.com/i/oauth2/authorize?response_type=code'
                . '&client_id=' . rawurlencode($cred['id'])
                . '&redirect_uri=' . $redirect
                . '&scope=' . rawurlencode(self::scopes('x'))
                . '&state=' . rawurlencode($state)
                . '&code_challenge=' . rawurlencode($challenge)
                . '&code_challenge_method=s256';
            return ['ok' => true, 'url' => $url];
        }

        // tiktok
        $url = 'https://www.tiktok.com/v2/auth/authorize/?client_key=' . rawurlencode($cred['id'])
            . '&scope=' . rawurlencode(self::scopes('tiktok'))
            . '&response_type=code'
            . '&redirect_uri=' . $redirect
            . '&state=' . rawurlencode($state);
        return ['ok' => true, 'url' => $url];
    }

    /** Troca o code do OAuth pelo token e salva a conexao. */
    public static function handleCallback(array $q): array
    {
        $network = (string)($_SESSION['social_oauth']['network'] ?? '');
        $userId = (int)($_SESSION['social_oauth']['user_id'] ?? 0);
        $nonce = (string)($_SESSION['social_oauth']['nonce'] ?? '');

        if ($network === '' || $userId <= 0) return ['ok' => false, 'error' => 'Sessão OAuth ausente. Refaça a conexão.'];

        $state = (string)($q['state'] ?? '');
        $parsed = json_decode((string)base64_decode($state), true);
        if (!is_array($parsed) || ($parsed['n'] ?? '') !== $network || (int)($parsed['u'] ?? 0) !== $userId
            || ($parsed['r'] ?? '') !== $nonce) {
            return ['ok' => false, 'error' => 'Estado OAuth inválido (possible CSRF). Refaça a conexão.'];
        }
        if (!empty($q['error'])) {
            return ['ok' => false, 'network' => $network,
                'error' => 'Autorização recusada: ' . mb_substr((string)$q['error'], 0, 120)];
        }

        $code = (string)($q['code'] ?? '');
        if ($code === '') return ['ok' => false, 'network' => $network, 'error' => 'Código de autorização ausente.'];

        try {
            $result = match ($network) {
                'facebook', 'instagram', 'threads' => self::exchangeMeta($network, $code),
                'x' => self::exchangeX($code),
                'tiktok' => self::exchangeTiktok($code),
                default => ['ok' => false, 'error' => 'Rede desconhecida.'],
            };
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => 'Falha na troca de token: ' . $e->getMessage()];
        }

        unset($_SESSION['social_oauth']);
        if (!empty($result['ok'])) {
            $saved = SocialConnections::upsert($userId, $network, $result);
            if (!$saved) return ['ok' => false, 'network' => $network, 'error' => 'Falha ao salvar a conexão.'];
            return ['ok' => true, 'network' => $network, 'account_name' => (string)($result['account_name'] ?? '')];
        }
        $result['network'] = $network;
        return $result;
    }

    private static function exchangeMeta(string $network, string $code): array
    {
        $cred = self::credentials('meta');
        $tok = SocialHttp::json('GET', 'https://graph.facebook.com/v21.0/oauth/access_token', [
            'form' => [
                'client_id' => $cred['id'],
                'redirect_uri' => self::redirectUri(),
                'client_secret' => $cred['secret'],
                'code' => $code,
            ],
        ]);
        if (empty($tok['access_token'])) {
            return ['ok' => false, 'error' => SocialHttp::errorMsg($tok, 'Falha ao autorizar no Meta.')];
        }

        // Token long-lived (60 dias) — menos reconexoes para o usuario
        $long = SocialHttp::json('GET', 'https://graph.facebook.com/v21.0/oauth/access_token', [
            'form' => [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $cred['id'],
                'client_secret' => $cred['secret'],
                'fb_exchange_token' => $tok['access_token'],
            ],
        ]);
        $userToken = $long['access_token'] ?? $tok['access_token'];
        $expires = !empty($long['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int)$long['expires_in']) : null;

        return self::discoverMeta($network, $userToken, $expires);
    }

    /** Descobre a conta alvo (Página / IG / Threads) a partir de um token do usuario Meta. */
    private static function discoverMeta(string $network, string $userToken, ?string $expires): array
    {
        $pages = SocialHttp::json('GET', 'https://graph.facebook.com/v21.0/me/accounts', [
            'form' => ['access_token' => $userToken,
                'fields' => 'id,name,access_token,instagram_business_account{id,username}'],
        ]);
        $pageList = $pages['data'] ?? [];

        if ($network === 'facebook') {
            $page = $pageList[0] ?? null;
            if (!$page) {
                return ['ok' => false, 'error' => 'Nenhuma Página do Facebook encontrada nesta conta. Crie uma Página e tente novamente.'];
            }
            return ['ok' => true, 'token' => (string)($page['access_token'] ?? $userToken),
                'expires_at' => null,
                'account_id' => (string)$page['id'], 'account_name' => (string)($page['name'] ?? ''),
                'meta' => ['page_id' => (string)$page['id'], 'via' => 'oauth']];
        }

        if ($network === 'instagram') {
            foreach ($pageList as $page) {
                $ig = $page['instagram_business_account'] ?? null;
                if (!empty($ig['id'])) {
                    return ['ok' => true, 'token' => (string)($page['access_token'] ?? $userToken),
                        'expires_at' => null,
                        'account_id' => (string)$ig['id'], 'account_name' => (string)($ig['username'] ?? ''),
                        'meta' => ['ig_user_id' => (string)$ig['id'], 'page_id' => (string)$page['id'], 'via' => 'oauth']];
                }
            }
            return ['ok' => false, 'error' => 'Nenhuma conta profissional do Instagram ligada a uma Página. Ligue a conta em Configurações do Instagram e tente novamente.'];
        }

        // threads
        $me = SocialHttp::json('GET', 'https://graph.threads.net/v1.0/me', [
            'form' => ['access_token' => $userToken, 'fields' => 'id,username'],
        ]);
        if (empty($me['id'])) {
            return ['ok' => false, 'error' => SocialHttp::errorMsg($me, 'Não foi possível identificar o perfil do Threads.')];
        }
        return ['ok' => true, 'token' => $userToken, 'expires_at' => $expires,
            'account_id' => (string)$me['id'], 'account_name' => (string)($me['username'] ?? ''),
            'meta' => ['threads_user_id' => (string)$me['id'], 'via' => 'oauth']];
    }

    private static function exchangeX(string $code): array
    {
        $cred = self::credentials('x');
        $verifier = (string)($_SESSION['social_oauth']['verifier'] ?? '');
        $tok = SocialHttp::json('POST', 'https://api.x.com/2/oauth2/token', [
            'headers' => ['Authorization: Basic ' . base64_encode($cred['id'] . ':' . $cred['secret'])],
            'form' => [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => self::redirectUri(),
                'code_verifier' => $verifier,
            ],
        ]);
        if (empty($tok['access_token'])) {
            return ['ok' => false, 'error' => SocialHttp::errorMsg($tok, 'Falha ao autorizar no X.')];
        }
        $expires = !empty($tok['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int)$tok['expires_in']) : null;

        $me = SocialHttp::json('GET', 'https://api.x.com/2/users/me', [
            'headers' => ['Authorization: Bearer ' . $tok['access_token']],
            'form' => ['user.fields' => 'id,name,username'],
        ]);
        $u = $me['data'] ?? [];

        return ['ok' => true, 'token' => (string)$tok['access_token'],
            'refresh_token' => $tok['refresh_token'] ?? null, 'expires_at' => $expires,
            'account_id' => (string)($u['id'] ?? ''), 'account_name' => (string)($u['username'] ?? ''),
            'meta' => ['username' => (string)($u['username'] ?? ''), 'via' => 'oauth']];
    }

    private static function exchangeTiktok(string $code): array
    {
        $cred = self::credentials('tiktok');
        $tok = SocialHttp::json('POST', 'https://open.tiktokapis.com/v2/oauth/token/', [
            'form' => [
                'client_key' => $cred['id'],
                'client_secret' => $cred['secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => self::redirectUri(),
            ],
        ]);
        if (empty($tok['access_token'])) {
            return ['ok' => false, 'error' => SocialHttp::errorMsg($tok, 'Falha ao autorizar no TikTok.')];
        }
        $expires = !empty($tok['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int)$tok['expires_in']) : null;

        $user = SocialHttp::json('POST', 'https://open.tiktokapis.com/v2/user/info/', [
            'headers' => ['Authorization: Bearer ' . $tok['access_token']],
            'form' => ['fields' => 'open_id,display_name,avatar_url'],
        ]);
        $u = $user['data']['user'] ?? [];

        return ['ok' => true, 'token' => (string)$tok['access_token'],
            'refresh_token' => $tok['refresh_token'] ?? null, 'expires_at' => $expires,
            'account_id' => (string)($u['open_id'] ?? ($tok['open_id'] ?? '')),
            'account_name' => (string)($u['display_name'] ?? ''),
            'meta' => ['open_id' => (string)($u['open_id'] ?? ''), 'via' => 'oauth']];
    }

    /**
     * Valida um token colado pelo usuario (caminho manual/BYOK) e descobre a conta.
     * @return array{ok:bool, token?:string, account_id?:string, account_name?:string, meta?:array, error?:string}
     */
    public static function probe(string $network, string $token): array
    {
        $token = trim($token);
        if ($token === '') return ['ok' => false, 'error' => 'Cole um token de acesso válido.'];

        switch ($network) {
            case 'facebook': {
                $me = SocialHttp::json('GET', 'https://graph.facebook.com/v21.0/me', [
                    'form' => ['access_token' => $token, 'fields' => 'id,name'],
                ]);
                if (empty($me['id'])) {
                    return ['ok' => false, 'error' => SocialHttp::errorMsg($me, 'Token inválido ou expirado no Facebook.')];
                }
                $pages = SocialHttp::json('GET', 'https://graph.facebook.com/v21.0/me/accounts', [
                    'form' => ['access_token' => $token, 'fields' => 'id,name,access_token'],
                ]);
                $page = ($pages['data'] ?? [])[0] ?? null;
                if ($page) {
                    return ['ok' => true, 'token' => (string)$page['access_token'],
                        'account_id' => (string)$page['id'], 'account_name' => (string)($page['name'] ?? ''),
                        'meta' => ['page_id' => (string)$page['id'], 'via' => 'manual']];
                }
                // Sem Paged interfaces: assume que o token colado ja e de uma Pagina
                return ['ok' => true, 'token' => $token,
                    'account_id' => (string)$me['id'], 'account_name' => (string)($me['name'] ?? ''),
                    'meta' => ['page_id' => (string)$me['id'], 'via' => 'manual']];
            }

            case 'instagram': {
                $pages = SocialHttp::json('GET', 'https://graph.facebook.com/v21.0/me/accounts', [
                    'form' => ['access_token' => $token,
                        'fields' => 'id,name,access_token,instagram_business_account{id,username}'],
                ]);
                if (isset($pages['error'])) {
                    return ['ok' => false, 'error' => SocialHttp::errorMsg($pages, 'Token inválido no Meta.')];
                }
                foreach (($pages['data'] ?? []) as $page) {
                    $ig = $page['instagram_business_account'] ?? null;
                    if (!empty($ig['id'])) {
                        return ['ok' => true, 'token' => (string)($page['access_token'] ?? $token),
                            'account_id' => (string)$ig['id'], 'account_name' => (string)($ig['username'] ?? ''),
                            'meta' => ['ig_user_id' => (string)$ig['id'], 'page_id' => (string)$page['id'], 'via' => 'manual']];
                    }
                }
                return ['ok' => false, 'error' => 'Nenhuma conta profissional do Instagram ligada a uma Página do Facebook neste token.'];
            }

            case 'threads': {
                $me = SocialHttp::json('GET', 'https://graph.threads.net/v1.0/me', [
                    'form' => ['access_token' => $token, 'fields' => 'id,username'],
                ]);
                if (empty($me['id'])) {
                    return ['ok' => false, 'error' => SocialHttp::errorMsg($me, 'Token inválido no Threads.')];
                }
                return ['ok' => true, 'token' => $token,
                    'account_id' => (string)$me['id'], 'account_name' => (string)($me['username'] ?? ''),
                    'meta' => ['threads_user_id' => (string)$me['id'], 'via' => 'manual']];
            }

            case 'x': {
                $me = SocialHttp::json('GET', 'https://api.x.com/2/users/me', [
                    'headers' => ['Authorization: Bearer ' . $token],
                    'form' => ['user.fields' => 'id,name,username'],
                ]);
                if (empty($me['data']['id'])) {
                    return ['ok' => false, 'error' => SocialHttp::errorMsg($me, 'Token inválido ou expirado no X.')];
                }
                return ['ok' => true, 'token' => $token,
                    'account_id' => (string)$me['data']['id'],
                    'account_name' => (string)($me['data']['username'] ?? ''),
                    'meta' => ['username' => (string)($me['data']['username'] ?? ''), 'via' => 'manual']];
            }

            case 'tiktok': {
                $me = SocialHttp::json('POST', 'https://open.tiktokapis.com/v2/user/info/', [
                    'headers' => ['Authorization: Bearer ' . $token],
                    'form' => ['fields' => 'open_id,display_name,avatar_url'],
                ]);
                $u = $me['data']['user'] ?? null;
                if (empty($u['open_id'])) {
                    return ['ok' => false, 'error' => SocialHttp::errorMsg($me, 'Token inválido ou expirado no TikTok.')];
                }
                return ['ok' => true, 'token' => $token,
                    'account_id' => (string)$u['open_id'], 'account_name' => (string)($u['display_name'] ?? ''),
                    'meta' => ['open_id' => (string)$u['open_id'], 'via' => 'manual']];
            }
        }

        return ['ok' => false, 'error' => 'Rede desconhecida.'];
    }

    /** Caminho manual: valida o token colado e salva a conexao. */
    public static function saveManual(int $userId, string $network, string $token): array
    {
        if (!SocialNetworks::supports($network)) return ['ok' => false, 'error' => 'Rede desconhecida.'];

        $probe = self::probe($network, $token);
        if (empty($probe['ok'])) return $probe;

        $saved = SocialConnections::upsert($userId, $network, [
            'token' => $probe['token'],
            'account_id' => $probe['account_id'] ?? '',
            'account_name' => $probe['account_name'] ?? '',
            'meta' => $probe['meta'] ?? [],
        ]);
        if (!$saved) return ['ok' => false, 'error' => 'Falha ao salvar a conexão.'];

        return ['ok' => true, 'network' => $network, 'account_name' => $probe['account_name'] ?? ''];
    }

    /**
     * Renova o token quando expirou (X e TikTok tem refresh_token).
     * Sem refresh → marca a conexao como 'expired' (usuario reconecta).
     * @return bool true = token utilizavel
     */
    public static function refreshIfExpired(object $conn): bool
    {
        $expiresAt = (string)($conn->token_expires_at ?? '');
        if ($expiresAt === '' || strtotime($expiresAt) > time()) return true;

        $refresh = !empty($conn->refresh_token) ? Crypto::decrypt((string)$conn->refresh_token) : null;
        if ($refresh === null || $refresh === '') {
            self::markExpired((int)$conn->id);
            return false;
        }

        $new = null;
        if ($conn->network === 'x') {
            $cred = self::credentials('x');
            $r = SocialHttp::json('POST', 'https://api.x.com/2/oauth2/token', [
                'headers' => ['Authorization: Basic ' . base64_encode($cred['id'] . ':' . $cred['secret'])],
                'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $refresh],
            ]);
            if (!empty($r['access_token'])) {
                $new = ['token' => (string)$r['access_token'],
                    'refresh_token' => $r['refresh_token'] ?? $refresh,
                    'expires_at' => !empty($r['expires_in'])
                        ? date('Y-m-d H:i:s', time() + (int)$r['expires_in']) : null];
            }
        } elseif ($conn->network === 'tiktok') {
            $cred = self::credentials('tiktok');
            $r = SocialHttp::json('POST', 'https://open.tiktokapis.com/v2/oauth/token/', [
                'form' => ['client_key' => $cred['id'], 'client_secret' => $cred['secret'],
                    'grant_type' => 'refresh_token', 'refresh_token' => $refresh],
            ]);
            if (!empty($r['access_token'])) {
                $new = ['token' => (string)$r['access_token'],
                    'refresh_token' => $r['refresh_token'] ?? $refresh,
                    'expires_at' => !empty($r['expires_in'])
                        ? date('Y-m-d H:i:s', time() + (int)$r['expires_in']) : null];
            }
        }

        if ($new === null) {
            self::markExpired((int)$conn->id);
            return false;
        }

        $meta = json_decode((string)($conn->account_meta ?? ''), true);
        $ok = SocialConnections::upsert((int)$conn->user_id, (string)$conn->network, array_merge([
            'account_id' => $conn->account_id,
            'account_name' => $conn->account_name,
            'meta' => is_array($meta) ? $meta : [],
        ], $new));
        if (!$ok) {
            self::markExpired((int)$conn->id);
            return false;
        }
        return true;
    }

    private static function markExpired(int $connectionId): void
    {
        try {
            \AfiliaFacil\Models\SocialConnection::where('id', $connectionId)
                ->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
        }
    }
}
