<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Social/SocialNetworks.php';
require_once __DIR__ . '/../Social/SocialHttp.php';
require_once __DIR__ . '/../Social/SocialConnections.php';
require_once __DIR__ . '/../Social/SocialAppCredentials.php';

/**
 * Conexao oficial das redes (híbrido pragmático):
 * - OAuth 2.0 quando ha credenciais de app — do env (plataforma) ou do
 *   proprio usuario (BYOK de app, salvas em SocialAppCredentials); criar o
 *   app ja e obrigatorio para gerar token e o modo dev NAO exige App Review
 *   para recursos do proprio usuario.
 * - Caminho manual (token colado, BYOK) como alternativa — mesmo com o
 *   probe diagnosticando permissoes/expiracao de forma acionavel.
 * O usuario ve sempre o que esta conectado e pode desconectar (revoke local).
 */
class SocialOAuth
{
    public const ENV_KEYS = [
        'meta' => ['META_APP_ID', 'META_APP_SECRET'],
        'threads' => ['THREADS_APP_ID', 'THREADS_APP_SECRET'],
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

    /** Provider de credenciais da rede (threads usa app ID proprio da Threads). Aceita a rede ou o proprio nome do provider ('meta'). */
    public static function providerFor(string $network): string
    {
        return match ($network) {
            'facebook', 'instagram', 'meta' => 'meta',
            'threads' => 'threads',
            'x' => 'x',
            'tiktok' => 'tiktok',
            default => '',
        };
    }

    /**
     * Credenciais OAuth do provedor da rede. Prioridade: credencial do
     * usuario (BYOK de app) > env da plataforma.
     * @return array{configured:bool, id:string, secret:string, source:string}
     */
    public static function credentials(string $network, ?int $userId = null): array
    {
        $provider = self::providerFor($network);
        if ($provider === '') {
            return ['configured' => false, 'id' => '', 'secret' => '', 'source' => ''];
        }
        if ($userId !== null && $userId > 0) {
            $own = SocialAppCredentials::get($userId, $provider);
            if ($own !== null) {
                return ['configured' => true, 'id' => $own['app_id'],
                    'secret' => $own['secret'], 'source' => 'user'];
            }
        }
        $pair = self::envPair($provider);
        $pair['source'] = $pair['configured'] ? 'env' : '';
        return $pair;
    }

    public static function redirectUri(): string
    {
        return Config::getBaseUrl() . '/admin/api/social.php?action=callback';
    }

    private static function scopes(string $network): string
    {
        return match ($network) {
            'facebook' => 'pages_show_list,pages_read_engagement,pages_manage_posts',
            'instagram' => 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish',
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
        $cred = self::credentials($network, $userId);
        if (!$cred['configured']) {
            return ['ok' => false, 'error' => 'oauth_not_configured'];
        }

        $state = self::stateFor($network, $userId);
        $redirect = rawurlencode(self::redirectUri());

        // Threads tem Authorization Window PROPRIA (threads.com) com o
        // Threads App ID — o dialog do Facebook rejeita os scopes threads_*.
        if ($network === 'threads') {
            $url = 'https://threads.com/oauth/authorize?client_id=' . rawurlencode($cred['id'])
                . '&redirect_uri=' . $redirect
                . '&response_type=code'
                . '&scope=' . rawurlencode(self::scopes($network))
                . '&state=' . rawurlencode($state);
            return ['ok' => true, 'url' => $url];
        }

        if (in_array($network, ['facebook', 'instagram'], true)) {
            $url = 'https://www.facebook.com/v26.0/dialog/oauth?client_id=' . rawurlencode($cred['id'])
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
            // Facebook devolve error_reason/error_description alem do error cru —
            // sem eles a mensagem vira "access_denied" sem contexto acionavel.
            $detail = trim((string)($q['error_description'] ?? ''));
            if ($detail === '') $detail = trim((string)($q['error_reason'] ?? ''));
            $msg = 'Autorização recusada: ' . mb_substr((string)$q['error'], 0, 120);
            if ($detail !== '' && strcasecmp($detail, (string)$q['error']) !== 0) {
                $msg .= ' — ' . mb_substr($detail, 0, 200);
            }
            return ['ok' => false, 'network' => $network, 'error' => $msg];
        }

        $code = (string)($q['code'] ?? '');
        if ($code === '') return ['ok' => false, 'network' => $network, 'error' => 'Código de autorização ausente.'];

        try {
            $result = match ($network) {
                'facebook', 'instagram' => self::exchangeMeta($network, $code, $userId),
                'threads' => self::exchangeThreads($code, $userId),
                'x' => self::exchangeX($code, $userId),
                'tiktok' => self::exchangeTiktok($code, $userId),
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

    private static function exchangeMeta(string $network, string $code, int $userId): array
    {
        $cred = self::credentials('meta', $userId);
        $tok = SocialHttp::json('GET', 'https://graph.facebook.com/v26.0/oauth/access_token', [
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
        $long = SocialHttp::json('GET', 'https://graph.facebook.com/v26.0/oauth/access_token', [
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
        $pages = SocialHttp::json('GET', 'https://graph.facebook.com/v26.0/me/accounts', [
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

        return ['ok' => false, 'error' => 'Rede desconhecida no Meta.'];
    }

    /**
     * Threads: Authorization Window propria (threads.com) + exchange em
     * graph.threads.net. O code gera token curto (1h) + refresh (60 dias);
     * trocamos por long-lived (60 dias) via refresh_access_token para o
     * agendamento nao morrer antes da proxima publicacao.
     */
    private static function exchangeThreads(string $code, int $userId): array
    {
        $cred = self::credentials('threads', $userId);
        $tok = SocialHttp::json('GET', 'https://graph.threads.net/oauth/access_token', [
            'form' => [
                'client_id' => $cred['id'],
                'client_secret' => $cred['secret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::redirectUri(),
            ],
        ]);
        if (empty($tok['access_token'])) {
            return ['ok' => false, 'error' => SocialHttp::errorMsg($tok, 'Falha ao autorizar no Threads.')];
        }

        $userToken = (string)$tok['access_token'];
        $refresh = !empty($tok['refresh_token']) ? (string)$tok['refresh_token'] : null;
        $expires = !empty($tok['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int)$tok['expires_in']) : null;

        if ($refresh !== null) {
            $long = SocialHttp::json('GET', 'https://graph.threads.net/refresh_access_token', [
                'form' => ['grant_type' => 'th_refresh_token', 'access_token' => $refresh],
            ]);
            if (!empty($long['access_token'])) {
                $userToken = (string)$long['access_token'];
                $expires = !empty($long['expires_in'])
                    ? date('Y-m-d H:i:s', time() + (int)$long['expires_in'])
                    : date('Y-m-d H:i:s', time() + 60 * 86400);
            }
        }

        $me = SocialHttp::json('GET', 'https://graph.threads.net/v1.0/me', [
            'form' => ['access_token' => $userToken, 'fields' => 'id,username'],
        ]);
        if (empty($me['id'])) {
            return ['ok' => false, 'error' => SocialHttp::errorMsg($me, 'Não foi possível identificar o perfil do Threads.')];
        }

        return ['ok' => true, 'token' => $userToken, 'refresh_token' => $refresh,
            'expires_at' => $expires,
            'account_id' => (string)$me['id'], 'account_name' => (string)($me['username'] ?? ''),
            'meta' => ['threads_user_id' => (string)$me['id'], 'via' => 'oauth']];
    }

    private static function exchangeX(string $code, int $userId): array
    {
        $cred = self::credentials('x', $userId);
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

    private static function exchangeTiktok(string $code, int $userId): array
    {
        $cred = self::credentials('tiktok', $userId);
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
     * Valida um token colado pelo usuario (caminho manual/BYOK) e descobre a
     * conta. Quando ha credenciais de app, diagnostica permissoes/expiracao
     * de forma acionavel (aponta o passo do guia que precisa corrigir).
     * @return array{ok:bool, token?:string, account_id?:string, account_name?:string, meta?:array, note?:string, error?:string}
     */
    public static function probe(string $network, string $token, ?int $userId = null): array
    {
        $token = trim($token);
        if ($token === '') return ['ok' => false, 'error' => 'Cole um token de acesso válido.'];

        switch ($network) {
            case 'facebook': {
                $me = SocialHttp::json('GET', 'https://graph.facebook.com/v26.0/me', [
                    'form' => ['access_token' => $token, 'fields' => 'id,name'],
                ]);
                if (empty($me['id'])) {
                    return ['ok' => false, 'error' => SocialHttp::errorMsg($me, 'Token inválido ou expirado no Facebook.')];
                }
                $pages = SocialHttp::json('GET', 'https://graph.facebook.com/v26.0/me/accounts', [
                    'form' => ['access_token' => $token, 'fields' => 'id,name,access_token'],
                ]);
                $page = ($pages['data'] ?? [])[0] ?? null;
                if ($page) {
                    return self::metaDiag($userId, (string)$page['access_token'], 'facebook', [
                        'ok' => true, 'token' => (string)$page['access_token'],
                        'account_id' => (string)$page['id'], 'account_name' => (string)($page['name'] ?? ''),
                        'meta' => ['page_id' => (string)$page['id'], 'via' => 'manual']]);
                }
                // Sem Paged interfaces: assume que o token colado ja e de uma Pagina
                return self::metaDiag($userId, $token, 'facebook', [
                    'ok' => true, 'token' => $token,
                    'account_id' => (string)$me['id'], 'account_name' => (string)($me['name'] ?? ''),
                    'meta' => ['page_id' => (string)$me['id'], 'via' => 'manual']]);
            }

            case 'instagram': {
                $pages = SocialHttp::json('GET', 'https://graph.facebook.com/v26.0/me/accounts', [
                    'form' => ['access_token' => $token,
                        'fields' => 'id,name,access_token,instagram_business_account{id,username}'],
                ]);
                if (isset($pages['error'])) {
                    return ['ok' => false, 'error' => SocialHttp::errorMsg($pages, 'Token inválido no Meta.')];
                }
                foreach (($pages['data'] ?? []) as $page) {
                    $ig = $page['instagram_business_account'] ?? null;
                    if (!empty($ig['id'])) {
                        return self::metaDiag($userId, (string)($page['access_token'] ?? $token), 'instagram', [
                            'ok' => true, 'token' => (string)($page['access_token'] ?? $token),
                            'account_id' => (string)$ig['id'], 'account_name' => (string)($ig['username'] ?? ''),
                            'meta' => ['ig_user_id' => (string)$ig['id'], 'page_id' => (string)$page['id'], 'via' => 'manual']]);
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
                    return ['ok' => false, 'error' => self::xProbeError($me)];
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
                // Verifica o escopo video.publish (sem ele TODO post falha)
                $vi = SocialHttp::json('GET', 'https://open.tiktokapis.com/v2/oauth/token/', [
                    'form' => ['access_token' => $token],
                ]);
                $scope = trim((string)($vi['scope'] ?? '')) ?: trim((string)($vi['data']['scope'] ?? ''));
                if ($scope !== '') {
                    $parts = preg_split('/[\s,]+/', $scope) ?: [];
                    if (!in_array('video.publish', $parts, true)) {
                        return ['ok' => false, 'error' => 'TikTok: token sem o escopo video.publish — ative o produto '
                            . 'Content Posting API (Direct Post) no seu app e gere o token com esse escopo (passo 1 do guia).'];
                    }
                }
                return ['ok' => true, 'token' => $token,
                    'account_id' => (string)$u['open_id'], 'account_name' => (string)($u['display_name'] ?? ''),
                    'meta' => ['open_id' => (string)$u['open_id'], 'via' => 'manual']];
            }
        }

        return ['ok' => false, 'error' => 'Rede desconhecida.'];
    }

    /**
     * Diagnostico do token Meta via debug_token (só quando ha credencial de
     * app e o token foi emitido POR ESSE app — tokens de outro app sao
     * ignorados para nao dar falso negativo). Falta de permissao critica =
     * erro acionavel apontando o passo do guia; token perto de expirar =
     * note (nao bloqueia).
     */
    private static function metaDiag(int $userId, string $token, string $network, array $probe): array
    {
        $cred = self::credentials('meta', $userId);
        if (empty($cred['configured'])) return $probe;

        $d = SocialHttp::json('GET', 'https://graph.facebook.com/v26.0/debug_token', [
            'form' => ['input_token' => $token,
                'access_token' => $cred['id'] . '|' . $cred['secret']],
        ]);
        $data = $d['data'] ?? null;
        if (!is_array($data)) return $probe;
        // so diagnostica token emitido pelo app configurado
        if (($data['app_id'] ?? '') === '' || (string)$data['app_id'] !== (string)$cred['id']) {
            return $probe;
        }
        if (isset($data['is_valid']) && $data['is_valid'] === false) {
            return ['ok' => false, 'error' => 'Token inválido ou revogado no Meta — gere um novo no '
                . 'Graph API Explorer (passo 3 do guia) e cole novamente.'];
        }
        $scopes = $data['scopes'] ?? null;
        if (is_array($scopes) && $scopes) {
            $missing = array_values(array_diff(['pages_manage_posts'], $scopes));
            if ($missing) {
                $req = 'pages_show_list, pages_read_engagement, pages_manage_posts'
                    . ($network === 'instagram' ? ', instagram_basic, instagram_content_publish' : '');
                return ['ok' => false, 'error' => 'Token sem a permissão pages_manage_posts — no Graph API '
                    . 'Explorer gere o token marcando: ' . $req . ' (passo 3 do guia).'];
            }
        }
        $exp = (int)($data['expires_at'] ?? 0);
        if ($exp > 0 && $exp < time() + 7 * 86400) {
            $probe['note'] = 'Token expira em ' . max(1, (int)ceil(($exp - time()) / 86400))
                . ' dia(s) — prefira o login oficial (abaixo), que renova sozinho.';
        }
        return $probe;
    }

    /** Erro acionavel do probe do X (billing x permissao x token invalido). */
    private static function xProbeError(array $me): string
    {
        $raw = strtolower(json_encode($me));
        $status = (int)($me['_status'] ?? 0);
        if ($status === 402 || preg_match('#credit|billing|payment|usage cap#i', $raw)) {
            return 'X: app sem créditos — ative o pay-per-use em console.x.com > Billing (passo 3 do guia). '
                . 'Sem créditos nenhum post é aceito.';
        }
        if ($status === 403 || str_contains($raw, 'forbidden') || str_contains($raw, 'insufficient')
            || str_contains($raw, 'tweet.write') || str_contains($raw, 'scope')) {
            return 'X: token sem permissão de escrita — gere com "Read and write" (escopo tweet.write) em '
                . 'console.x.com > User authentication settings (passo 2 do guia) ou use o login oficial abaixo.';
        }
        return SocialHttp::errorMsg($me, 'Token inválido ou expirado no X.');
    }

    /** Caminho manual: valida o token colado e salva a conexao. */
    public static function saveManual(int $userId, string $network, string $token): array
    {
        if (!SocialNetworks::supports($network)) return ['ok' => false, 'error' => 'Rede desconhecida.'];

        $probe = self::probe($network, $token, $userId);
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
            $cred = self::credentials('x', (int)$conn->user_id);
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
        } elseif ($conn->network === 'threads') {
            // th_refresh_token nao exige client secret; renova o long-lived (60d)
            $r = SocialHttp::json('GET', 'https://graph.threads.net/refresh_access_token', [
                'form' => ['grant_type' => 'th_refresh_token', 'access_token' => $refresh],
            ]);
            if (!empty($r['access_token'])) {
                $new = ['token' => (string)$r['access_token'],
                    'refresh_token' => $r['refresh_token'] ?? $refresh,
                    'expires_at' => !empty($r['expires_in'])
                        ? date('Y-m-d H:i:s', time() + (int)$r['expires_in'])
                        : date('Y-m-d H:i:s', time() + 60 * 86400)];
            }
        } elseif ($conn->network === 'tiktok') {
            $cred = self::credentials('tiktok', (int)$conn->user_id);
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
