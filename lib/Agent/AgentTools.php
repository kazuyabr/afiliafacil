<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/../PageManager.php';
require_once __DIR__ . '/../Cloner.php';
require_once __DIR__ . '/../Crypto.php';
require_once __DIR__ . '/../Offers/OfferManager.php';
require_once __DIR__ . '/../Offers/OfferQuota.php';
require_once __DIR__ . '/../Offers/OfferAi.php';
require_once __DIR__ . '/../AdSpy/AdSpyManager.php';
require_once __DIR__ . '/../AdSpy/AdSpyQuota.php';
require_once __DIR__ . '/../Ai/SttConfig.php';
require_once __DIR__ . '/../Ai/SttClient.php';
require_once __DIR__ . '/../Ai/SttQuota.php';
require_once __DIR__ . '/../Ai/TtsConfig.php';
require_once __DIR__ . '/../Ai/TtsClient.php';
require_once __DIR__ . '/../Ai/TtsQuota.php';
require_once __DIR__ . '/../Ai/MediaDetector.php';
require_once __DIR__ . '/../Moderation/ContentModerator.php';
require_once __DIR__ . '/../Web/WebSearch.php';
require_once __DIR__ . '/AgentSubagents.php';
require_once __DIR__ . '/AgentPrompts.php';

class AgentTools
{
    public const LEITURA = ['consultar_quotas', 'listar_ofertas', 'listar_minhas_paginas', 'listar_transcricoes', 'listar_narracoes', 'pesquisar_web'];

    public static function definitions(): array
    {
        return [
            ['name' => 'consultar_quotas', 'params' => [], 'desc' => 'Consulta as quotas e limites do plano do usuário (anúncios, IA, ofertas, transcrições, narrações).'],
            ['name' => 'listar_ofertas', 'params' => ['q?' => 'busca livre (nome, anunciante ou domínio)', 'niche?' => 'nicho', 'structure?' => 'estrutura', 'order?' => 'score|scale|ads|recent', 'limit?' => 'máx 20'], 'desc' => 'Lista ofertas aprovadas no swipe file (validadas nas bibliotecas de anúncios). Use "q" para buscar por termo livre.'],
            ['name' => 'listar_minhas_paginas', 'params' => [], 'desc' => 'Lista as páginas clonadas do usuário.'],
            ['name' => 'listar_transcricoes', 'params' => [], 'desc' => 'Lista as últimas transcrições do usuário.'],
            ['name' => 'listar_narracoes', 'params' => [], 'desc' => 'Lista as últimas narrações (TTS) do usuário.'],
            ['name' => 'ver_oferta', 'params' => ['id' => 'ID da oferta'], 'desc' => 'Abre o dossiê completo de uma oferta (criativos, páginas, análise). Consome 1 visualização.'],
            ['name' => 'espionar_anuncios', 'params' => ['query' => 'termo, domínio ou anunciante', 'providers?' => 'meta|google|tiktok'], 'desc' => 'Busca anúncios ativos nas bibliotecas (Meta/Google/TikTok). Consome 1 busca.'],
            ['name' => 'pesquisar_web', 'params' => ['query' => 'termo de pesquisa', 'limit?' => 'máx 10 (padrão 6)'], 'desc' => 'Pesquisa na web (Google/fallback gratuito) para investigar mercado, tendências, concorrentes, referências e notícias. Use SEMPRE que precisar de informação externa: não diga "não encontrei" sem ter pesquisado aqui. Não consome quota do plano (usa a chave do usuário ou o limite diário da plataforma).'],
            ['name' => 'analisar_oferta', 'params' => ['id' => 'ID da oferta'], 'desc' => 'Analisa uma oferta com IA (nicho, estrutura, score, ângulos). Consome 1 análise IA.'],
            ['name' => 'transcrever_midia', 'params' => ['url' => 'URL da página/VSL/áudio'], 'desc' => 'Transcreve um vídeo/áudio (com timestamps). Consome 1 transcrição.'],
            ['name' => 'gerar_narracao', 'params' => ['text' => 'texto (máx 5000)', 'voice?' => 'voz'], 'desc' => 'Gera narração (TTS) a partir de um texto. Consome 1 narração.'],
            ['name' => 'clonar_pagina', 'params' => ['url' => 'URL da página', 'affiliate_link' => 'link de afiliado', 'name?' => 'nome'], 'desc' => 'Clona uma página de vendas e aplica o link de afiliado. Consome 1 página do plano.'],
            ['name' => 'criar_subagente', 'params' => ['name' => 'nome do especialista', 'specialty?' => 'especialidade', 'instructions?' => 'instruções', 'tools?' => 'ferramentas permitidas'], 'desc' => 'Cria um subagente especializado (ex.: analista de Meta Ads) que você e o usuário poderão consultar depois.'],
            ['name' => 'delegar_subagente', 'params' => ['subagent' => 'nome ou id do subagente', 'question' => 'pergunta'], 'desc' => 'Consulta um subagente ativo e traz a resposta dele para a conversa (sem custo de cota extra).'],
        ];
    }

    public static function execute(string $tool, array $args, array $user, int $userId, array $context = []): array
    {
        try {
            return match ($tool) {
                'consultar_quotas' => self::consultarQuotas($user, $userId),
                'listar_ofertas' => self::listarOfertas($args),
                'listar_minhas_paginas' => self::listarPaginas($userId),
                'listar_transcricoes' => self::listarTranscricoes($userId),
                'listar_narracoes' => self::listarNarracoes($userId),
                'ver_oferta' => self::verOferta($args, $user, $userId),
                'espionar_anuncios' => self::espionar($args, $user, $userId),
                'pesquisar_web' => self::pesquisarWeb($args, $userId),
                'analisar_oferta' => self::analisarOferta($args, $user, $userId),
                'transcrever_midia' => self::transcrever($args, $user, $userId),
                'gerar_narracao' => self::gerarNarracao($args, $user, $userId),
                'clonar_pagina' => self::clonar($args, $userId),
                'criar_subagente' => self::criarSubagente($args, $user, $userId, $context),
                'delegar_subagente' => self::delegarSubagente($args, $user, $userId, $context),
                default => ['success' => false, 'summary' => 'Ferramenta desconhecida.', 'render' => null],
            };
        } catch (Throwable $e) {
            return ['success' => false, 'summary' => 'Erro ao executar: ' . $e->getMessage(), 'render' => null];
        }
    }

    private static function criarSubagente(array $args, array $user, int $userId, array $context): array
    {
        if (!empty($context['is_subagent'])) {
            return ['success' => false, 'summary' => 'Subagentes não podem criar outros subagentes. Volte ao Sócio de IA principal para isso.', 'render' => null];
        }

        $quota = AgentSubagents::quota($userId, $user['plan']);
        if (!$quota['allowed']) {
            return ['success' => false, 'summary' => 'Limite de subagentes do plano atingido (' . $quota['used'] . '/' . $quota['limit'] . '). Faça upgrade para criar mais especialistas.', 'render' => null];
        }

        $result = AgentSubagents::create($userId, $args, 'agent');
        if (isset($result['error'])) {
            return ['success' => false, 'summary' => $result['error'], 'render' => null];
        }

        return [
            'success' => true,
            'summary' => 'Subagente "' . $result['subagent']['name'] . '" criado (' . ($result['subagent']['specialty'] ?: 'especialista') . '). Você já pode consultá-lo na aba Subagentes.',
            'render' => ['type' => 'subagente', 'data' => $result['subagent']],
        ];
    }

    private static function delegarSubagente(array $args, array $user, int $userId, array $context): array
    {
        if (!empty($context['is_subagent'])) {
            return ['success' => false, 'summary' => 'Subagentes não podem delegar para outros subagentes.', 'render' => null];
        }

        $question = trim((string)($args['question'] ?? ''));
        if ($question === '') {
            return ['success' => false, 'summary' => 'Informe a pergunta que o subagente deve responder.', 'render' => null];
        }

        $subagent = AgentSubagents::findActive($userId, $args['subagent'] ?? '');
        if (!$subagent) {
            return ['success' => false, 'summary' => 'Subagente não encontrado ou inativo. Verifique os subagentes disponíveis.', 'render' => null];
        }

        $config = AiConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            return ['success' => false, 'summary' => 'IA não configurada (CF_AI_TOKEN da plataforma ou BYOK).', 'render' => null];
        }

        $response = AiClient::chat([
            ['role' => 'system', 'content' => AgentPrompts::subagent($subagent)],
            ['role' => 'user', 'content' => $question],
        ], $config);

        if ($response === null) {
            return ['success' => false, 'summary' => 'O subagente não conseguiu responder agora (falha na IA).', 'render' => null];
        }

        $text = ContentModerator::redact(trim($response));

        return [
            'success' => true,
            'summary' => 'Resposta do subagente "' . $subagent['name'] . '": ' . mb_substr($text, 0, 800),
            'render' => ['type' => 'subagente_resposta', 'data' => ['name' => $subagent['name'], 'text' => $text]],
        ];
    }

    private static function consultarQuotas(array $user, int $userId): array
    {
        $plan = $user['plan'];
        $data = [
            'plano' => Plans::planName($plan),
            'paginas' => ['limite' => Plans::maxPages($plan)],
            'dominios' => ['limite' => Plans::maxDomains($plan)],
            'adspy' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH),
            'ia' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_ANALYSIS),
            'ofertas' => OfferQuota::check($userId, $plan),
            'transcricoes' => SttQuota::check($userId, $plan),
            'narracoes' => TtsQuota::check($userId, $plan),
            'agente' => AgentQuota::check($userId, $plan),
        ];

        return [
            'success' => true,
            'summary' => 'Quotas do plano ' . $data['plano'] . ': ' . json_encode($data, JSON_UNESCAPED_UNICODE),
            'render' => ['type' => 'quotas', 'data' => $data],
        ];
    }

    private static function listarOfertas(array $args): array
    {
        $manager = new OfferManager();
        $query = trim((string)($args['q'] ?? ''));
        $niche = trim((string)($args['niche'] ?? ''));
        $structure = trim((string)($args['structure'] ?? ''));
        $order = (string)($args['order'] ?? 'score');
        $limit = min(20, max(1, (int)($args['limit'] ?? 10)));

        $filters = ['q' => $query, 'niche' => $niche, 'structure' => $structure, 'order' => $order];
        $result = $manager->list($filters, $limit);
        $items = self::mapOffers($result['items']);
        $usedTerm = $query;
        $tried = [];

        // Variacoes automaticas quando o termo exato nao retorna nada
        // (ex: "jogos digitais" -> "jogos"/"jogo"; "games" -> "game").
        if (empty($items) && $query !== '') {
            foreach (self::queryVariations($query) as $variant) {
                $tried[] = $variant;
                $alt = $manager->list(['q' => $variant, 'niche' => $niche, 'structure' => $structure, 'order' => $order], $limit);
                if (!empty($alt['items'])) {
                    $items = self::mapOffers($alt['items']);
                    $usedTerm = $variant;
                    break;
                }
            }
        }

        if (!empty($items)) {
            $summary = count($items) . ' ofertas aprovadas encontradas';
            if ($query !== '' && $usedTerm !== $query) {
                $summary .= ' (busca ajustada de "' . $query . '" para "' . $usedTerm . '")';
            } elseif ($query !== '') {
                $summary .= ' para "' . $query . '"';
            }
            if ($niche !== '') $summary .= ' no nicho "' . $niche . '"';
            $summary .= ': ' . json_encode($items, JSON_UNESCAPED_UNICODE);
        } else {
            $term = $query !== '' ? $query : $niche;
            $summary = 'NENHUMA oferta para "' . $term . '"';
            if (!empty($tried)) {
                $summary .= ' (variações testadas: ' . implode(', ', array_slice($tried, 0, 6)) . ')';
            }
            $summary .= '.';

            $niches = OfferManager::topNiches(6);
            if (!empty($niches)) {
                $list = implode(', ', array_map(fn($n) => $n['label'] . ' (' . $n['total'] . ')', $niches));
                $summary .= ' Nichos COM ofertas no swipe: ' . $list . '.';
            }

            $summary .= ' PRÓXIMO PASSO OBRIGATÓRIO: NÃO peça ao usuário para "tentar outro termo". Investigue você mesmo: use espionar_anuncios (bibliotecas Meta/Google/TikTok) e pesquisar_web no termo pedido, e apresente o cenário com 2-3 caminhos concretos (os nichos disponíveis acima, os anunciantes ativos que encontrar, validar a oferta do próprio usuário).';
        }

        return [
            'success' => true,
            'summary' => $summary,
            'render' => ['type' => 'ofertas', 'data' => $items],
        ];
    }

    private static function mapOffers(array $offers): array
    {
        return array_map(fn($o) => [
            'id' => $o['id'],
            'name' => $o['name'],
            'niche' => $o['niche'],
            'structure' => $o['structure'],
            'ads_count' => $o['ads_count'],
            'scale_pct' => $o['scale_pct'],
            'score' => $o['score'],
            'domain' => $o['domain'],
        ], $offers);
    }

    /**
     * Variacoes morfologicas do termo (palavras individuais + singular/plural).
     * Ex: "jogos digitais" -> ["jogos","jogo","digitais","digital"].
     */
    private static function queryVariations(string $query): array
    {
        $base = mb_strtolower(trim($query));
        if ($base === '') return [];

        $variations = [];
        foreach (preg_split('/\s+/', $base) ?: [] as $word) {
            $word = trim($word, "-_.,;:!?()[]{}\"'");
            if (mb_strlen($word) < 3) continue;

            $variations[] = $word;
            if (str_ends_with($word, 's')) {
                $variations[] = self::singularize($word);
            } else {
                $variations[] = $word . 's';
            }
        }

        if (str_ends_with($base, 's')) {
            $variations[] = self::singularize($base);
        }

        $variations = array_values(array_unique(array_filter(
            $variations,
            fn($v) => $v !== '' && mb_strlen($v) >= 3 && $v !== $base
        )));

        return array_slice($variations, 0, 6);
    }

    /**
     * Singular simples pt-BR: digitais->digital, papeis->papel, homens->homem, games->game.
     */
    private static function singularize(string $word): string
    {
        if (!str_ends_with($word, 's') || mb_strlen($word) < 4) return $word;

        $suffixes = ['ais' => 'al', 'eis' => 'el', 'ois' => 'ol', 'uis' => 'ul', 'ns' => 'm'];
        foreach ($suffixes as $plural => $singular) {
            if (str_ends_with($word, $plural)) {
                return mb_substr($word, 0, -mb_strlen($plural)) . $singular;
            }
        }

        return mb_substr($word, 0, -1);
    }

    private static function listarPaginas(int $userId): array
    {
        $pm = new PageManager();
        $pages = array_slice($pm->listByUser($userId), 0, 20);
        $items = array_map(fn($p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'status' => $p['status'] ?? '',
            'domain' => $p['domain'] ?? '',
            'source_domain' => $p['source_domain'] ?? '',
        ], $pages);

        return [
            'success' => true,
            'summary' => count($items) . ' páginas do usuário: ' . json_encode($items, JSON_UNESCAPED_UNICODE),
            'render' => ['type' => 'paginas', 'data' => $items],
        ];
    }

    private static function listarTranscricoes(int $userId): array
    {
        $items = [];
        if (Database::available()) {
            $items = \AfiliaFacil\Models\Transcription::where('user_id', $userId)
                ->orderByDesc('id')->limit(5)->get()
                ->map(fn($t) => ['id' => (int)$t->id, 'url' => $t->source_url, 'status' => $t->status, 'chars' => mb_strlen((string)$t->text)])
                ->all();
        }

        return [
            'success' => true,
            'summary' => count($items) . ' transcrições recentes: ' . json_encode($items, JSON_UNESCAPED_UNICODE),
            'render' => ['type' => 'transcricoes', 'data' => $items],
        ];
    }

    private static function listarNarracoes(int $userId): array
    {
        $items = [];
        if (Database::available()) {
            $items = \AfiliaFacil\Models\TtsGeneration::where('user_id', $userId)
                ->orderByDesc('id')->limit(5)->get()
                ->map(fn($t) => ['id' => (int)$t->id, 'status' => $t->status, 'chars' => (int)$t->chars, 'voice' => $t->voice])
                ->all();
        }

        return [
            'success' => true,
            'summary' => count($items) . ' narrações recentes: ' . json_encode($items, JSON_UNESCAPED_UNICODE),
            'render' => ['type' => 'narracoes', 'data' => $items],
        ];
    }

    private static function verOferta(array $args, array $user, int $userId): array
    {
        $id = (int)($args['id'] ?? 0);
        $manager = new OfferManager();
        $offer = $manager->get($id);

        if (!$offer || $offer['status'] !== 'approved') {
            return ['success' => false, 'summary' => 'Oferta não encontrada ou não aprovada.', 'render' => null];
        }

        OfferQuota::consume($userId, $id);

        return [
            'success' => true,
            'summary' => 'Oferta "' . $offer['name'] . '": ' . $offer['ads_count'] . ' anúncios, escala ' . $offer['scale_pct'] . '%, score ' . $offer['score'] . '. Nicho: ' . ($offer['niche'] ?: 'n/d') . '. Estrutura: ' . ($offer['structure'] ?: 'n/d') . '. Resumo IA: ' . mb_substr((string)$offer['ai_summary'], 0, 400),
            'render' => ['type' => 'oferta', 'data' => [
                'id' => $offer['id'],
                'name' => $offer['name'],
                'advertiser' => $offer['advertiser'],
                'domain' => $offer['domain'],
                'niche' => $offer['niche'],
                'structure' => $offer['structure'],
                'ads_count' => $offer['ads_count'],
                'scale_pct' => $offer['scale_pct'],
                'score' => $offer['score'],
                'ai_summary' => $offer['ai_summary'],
                'sparkline' => $offer['sparkline'],
                'source_url' => $offer['source_url'],
                'creatives_count' => count($offer['creatives'] ?? []),
                'pages_count' => count($offer['pages'] ?? []),
            ]],
        ];
    }

    private static function pesquisarWeb(array $args, int $userId): array
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['success' => false, 'summary' => 'Informe o termo de pesquisa.', 'render' => null];
        }

        $limit = (int)($args['limit'] ?? 6);
        $search = WebSearch::search($userId, $query, $limit);

        if (empty($search['success'])) {
            return ['success' => false, 'summary' => $search['error'] ?? 'Falha na pesquisa web.', 'render' => null];
        }

        $results = $search['results'];
        $summary = count($results) . ' resultados na web para "' . $query . '" (fonte: ' . $search['provider'] . ').';
        if (($search['source'] ?? '') === 'platform' && ($search['remaining'] ?? -1) >= 0) {
            $summary .= ' Restam ' . $search['remaining'] . ' pesquisas web hoje com a chave da plataforma.';
        }

        return [
            'success' => true,
            'summary' => $summary,
            'render' => ['type' => 'web', 'data' => $results],
        ];
    }

    private static function espionar(array $args, array $user, int $userId): array
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['success' => false, 'summary' => 'Informe um termo, domínio ou anunciante.', 'render' => null];
        }

        $providers = $args['providers'] ?? ['meta', 'google', 'tiktok'];
        $providers = array_values(array_intersect((array)$providers, ['meta', 'google', 'tiktok']));
        if (empty($providers)) $providers = ['meta', 'google', 'tiktok'];

        $manager = new AdSpyManager();
        $search = $manager->search($userId, $user['plan'], $query, $providers, ['countries' => ['BR'], 'country' => 'BR']);

        $ads = [];
        foreach ($search['results'] as $pid => $result) {
            foreach (array_slice($result['ads'] ?? [], 0, 12) as $ad) {
                $ad['provider'] = $pid;
                $ads[] = $ad;
            }
        }

        $summary = count($ads) . ' anúncios encontrados para "' . $query . '".';
        if (!empty($search['errors'])) {
            $summary .= ' Avisos: ' . implode(' | ', array_slice($search['errors'], 0, 3));
        }

        return [
            'success' => true,
            'summary' => $summary,
            'render' => ['type' => 'anuncios', 'data' => array_slice($ads, 0, 24)],
        ];
    }

    private static function analisarOferta(array $args, array $user, int $userId): array
    {
        $id = (int)($args['id'] ?? 0);
        $ai = new OfferAi();
        $result = $ai->analyze($id, $userId);

        if (empty($result['success'])) {
            return ['success' => false, 'summary' => $result['error'] ?? 'Falha na análise.', 'render' => null];
        }

        AdSpyQuota::consume($userId, AdSpyQuota::KIND_ANALYSIS, 'agente-analise-oferta', 'agent', 1, false);

        $analysis = $result['analysis'] ?? [];
        return [
            'success' => true,
            'summary' => 'Análise concluída. Score ' . ($analysis['score'] ?? 0) . '. Nicho: ' . ($analysis['nicho'] ?? 'n/d') . '. Resumo: ' . mb_substr($analysis['resumo'] ?? '', 0, 400),
            'render' => ['type' => 'analise', 'data' => $analysis],
        ];
    }

    private static function transcrever(array $args, array $user, int $userId): array
    {
        $url = trim((string)($args['url'] ?? ''));
        if ($url === '') {
            return ['success' => false, 'summary' => 'Informe a URL da mídia/página.', 'render' => null];
        }

        $config = SttConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            return ['success' => false, 'summary' => 'Transcrição não configurada (CF_AI_TOKEN da plataforma ou BYOK).', 'render' => null];
        }

        $input = ['url' => $url];
        if (!MediaDetector::isMediaUrl($url)) {
            $media = MediaDetector::detect($url);
            if (empty($media['success'])) {
                return ['success' => false, 'summary' => $media['error'] ?? 'Mídia não detectada.', 'render' => null];
            }
            $input = ['url' => $media['media_url']];
        }

        $id = SttQuota::create($userId, $input['url'], $config['provider'], $config['source'] ?? 'platform');
        if ($id === null) {
            return ['success' => false, 'summary' => 'Falha ao registrar a transcrição.', 'render' => null];
        }

        $result = SttClient::transcribe($input, $config);
        if (empty($result['success'])) {
            SttQuota::fail($id, $result['error'] ?? 'erro');
            return ['success' => false, 'summary' => $result['error'] ?? 'Falha na transcrição.', 'render' => null];
        }

        SttQuota::complete($id, $result);

        $screen = ContentModerator::screen((string)$result['text'], 'transcription', $userId);
        if (!$screen['allowed']) {
            SttQuota::complete($id, array_merge($result, ['text' => $screen['reason'], 'words' => []]));
            return [
                'success' => false,
                'summary' => 'A transcrição foi bloqueada pela moderação: ' . $screen['reason'],
                'render' => null,
            ];
        }
        if ($screen['action'] === 'redact') {
            SttQuota::complete($id, array_merge($result, ['text' => $screen['clean']]));
        }

        return [
            'success' => true,
            'summary' => 'Transcrição concluída (' . mb_strlen($screen['clean']) . ' caracteres). Início: ' . mb_substr($screen['clean'], 0, 500),
            'render' => ['type' => 'transcricao', 'data' => [
                'id' => $id,
                'text' => $screen['clean'],
                'words' => count($result['words'] ?? []),
                'duration' => $result['duration'] ?? 0,
            ]],
        ];
    }

    private static function gerarNarracao(array $args, array $user, int $userId): array
    {
        $text = trim((string)($args['text'] ?? ''));
        if ($text === '') {
            return ['success' => false, 'summary' => 'Informe o texto para narração.', 'render' => null];
        }

        $screen = ContentModerator::screen($text, 'tts', $userId);
        if (!$screen['allowed']) {
            return ['success' => false, 'summary' => $screen['reason'], 'render' => null];
        }
        $text = $screen['clean'];

        $config = TtsConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            return ['success' => false, 'summary' => 'Narração não configurada (CF_AI_TOKEN da plataforma ou BYOK).', 'render' => null];
        }

        $voice = trim((string)($args['voice'] ?? '')) ?: TtsConfig::defaultVoice($config['provider']);
        $id = TtsQuota::create($userId, $config['provider'], (string)$config['model'], $voice, $config['provider'] === 'google' ? 'wav' : 'mp3', $text, $config['source'] ?? 'platform');
        if ($id === null) {
            return ['success' => false, 'summary' => 'Falha ao registrar a narração.', 'render' => null];
        }

        $result = TtsClient::generate($text, $config, $voice);
        if (empty($result['success'])) {
            TtsQuota::fail($id, $result['error'] ?? 'erro');
            return ['success' => false, 'summary' => $result['error'] ?? 'Falha na geração.', 'render' => null];
        }

        $ext = $result['format'] === 'wav' ? 'wav' : 'mp3';
        $dir = Config::getUploadsDir() . '/tts';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $filename = 'tts-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $filename, $result['audio']) === false) {
            TtsQuota::fail($id, 'Falha ao salvar o áudio.');
            return ['success' => false, 'summary' => 'Falha ao salvar o áudio gerado.', 'render' => null];
        }

        TtsQuota::complete($id, $filename);
        return [
            'success' => true,
            'summary' => 'Narração gerada (' . mb_strlen($text) . ' caracteres, voz ' . $voice . ').',
            'render' => ['type' => 'narracao', 'data' => ['id' => $id, 'voice' => $voice, 'format' => $ext]],
        ];
    }

    private static function clonar(array $args, int $userId): array
    {
        $url = trim((string)($args['url'] ?? ''));
        $affiliateLink = trim((string)($args['affiliate_link'] ?? ''));
        $name = trim((string)($args['name'] ?? ''));

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'summary' => 'URL inválida para clonagem.', 'render' => null];
        }
        if ($affiliateLink === '') {
            return ['success' => false, 'summary' => 'O link de afiliado é obrigatório para clonar.', 'render' => null];
        }

        $cloner = new Cloner();
        $fetch = $cloner->fetchUrl($url);
        if (empty($fetch['success'])) {
            return ['success' => false, 'summary' => 'Não foi possível buscar a URL: ' . ($fetch['error'] ?? 'erro'), 'render' => null];
        }

        $storageConfig = self::loadUserStorage($userId);
        $pageId = time() + random_int(1, 9999);
        $result = $cloner->process($fetch['html'], $affiliateLink, 'url', $storageConfig, 'clones/' . $pageId);

        $sourceDomain = $result['source_domain'] ?? '';
        if ($name === '') {
            $name = $sourceDomain ?: 'Página Clonada ' . date('d/m/Y H:i');
        }

        $pm = new PageManager();
        $pm->create([
            'id' => $pageId,
            'user_id' => $userId,
            'name' => $name,
            'type' => 'clone',
            'html' => $result['html'],
            'source_domain' => $sourceDomain,
            'failed_assets' => $result['failed_assets'] ?? [],
            'cloner_version' => $result['cloner_version'] ?? '',
            'affiliate_link' => $affiliateLink,
            'status' => 'active',
        ]);

        $failed = count($result['failed_assets'] ?? []);
        return [
            'success' => true,
            'summary' => 'Página clonada com sucesso (ID ' . $pageId . ', ' . $failed . ' assets com falha). Nome: ' . $name,
            'render' => ['type' => 'clone', 'data' => ['id' => $pageId, 'name' => $name, 'failed_assets' => $failed]],
        ];
    }

    private static function loadUserStorage(int $userId): ?array
    {
        if (!Database::available()) return null;

        try {
            $config = \AfiliaFacil\Models\StorageConfig::where('user_id', $userId)->first();
            if (!$config || !$config->enabled) return null;

            $secret = Crypto::decrypt($config->secret_encrypted ?? '') ?? '';
            if ($secret === '') return null;

            return [
                'enabled' => (bool)$config->enabled,
                'account_id' => $config->account_id,
                'access_key' => $config->access_key,
                'secret_key' => $secret,
                'bucket' => $config->bucket,
                'public_url' => $config->public_url,
                'media_mode' => $config->media_mode ?: 'base64',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}
