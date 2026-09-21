<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Settings.php';
require_once __DIR__ . '/../Json.php';
require_once __DIR__ . '/../AdSpy/AiClient.php';
require_once __DIR__ . '/../AdSpy/AiConfig.php';
require_once __DIR__ . '/OfferManager.php';

class OfferAi
{
    public function analyze(int $offerId, int $userId = 0): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $candidates = AiConfig::candidates($userId);
        if (empty($candidates)) {
            return ['error' => 'IA não configurada (CF_AI_TOKEN da plataforma ou BYOK do usuário).'];
        }

        try {
            $offer = \AfiliaFacil\Models\Offer::find($offerId);
            if (!$offer) return ['error' => 'Oferta não encontrada'];

            $creatives = $offer->creatives()->orderByDesc('started_at')->limit(20)->get();
            $creativeLines = [];
            foreach ($creatives as $c) {
                $parts = [];
                if ($c->title !== '') $parts[] = 'Título: ' . mb_substr($c->title, 0, 120);
                if ($c->body !== '') $parts[] = 'Texto: ' . mb_substr($c->body, 0, 200);
                if ($c->cta !== '') $parts[] = 'CTA: ' . $c->cta;
                if ($c->landing_page !== '') $parts[] = 'Página: ' . $c->landing_page;
                if (!empty($parts)) $creativeLines[] = '- ' . implode(' | ', $parts);
            }

            $context = "OFERTA:\nNome: {$offer->name}\nAnunciante: {$offer->advertiser}\nDomínio: {$offer->domain}\nAnúncios ativos: {$offer->ads_count}\nVariação: {$offer->scale_pct}%\n\nCRIATIVOS:\n" . implode("\n", $creativeLines ?: ['Nenhum criativo capturado.']);

            $messages = [
                ['role' => 'system', 'content' => 'Você é um analista sênior de ofertas de marketing de afiliados no Brasil. Responda em português do Brasil, SOMENTE com JSON válido.'],
                ['role' => 'user', 'content' => "Analise a oferta abaixo e responda SOMENTE com JSON no formato:\n{\"nicho\":\"financas|saude|emagrecimento|relacionamento|espiritualidade|educacao|negocios|tecnologia|outros\",\"estrutura\":\"vsl|quiz|low_ticket|infoproduto|carta\",\"idioma\":\"pt|es|en\",\"score\":0-100,\"resumo\":\"...\",\"publico\":\"...\",\"angulos\":[\"...\"],\"sugestoes\":[\"...\"]}\n\nO score mede o potencial de escala da oferta (quantidade de anúncios ativos + variação + qualidade dos criativos).\n\n{$context}"],
            ];

            \AiClient::setUsageUser($userId);
        \AiClient::setUsageUser($userId);
        $response = AiClient::chatWithFallback($messages, $candidates);
            if ($response === null) {
                return ['error' => 'Falha na chamada da IA (verifique provider/chave).'];
            }

            $parsed = $this->parseJson($response);
            if (!$parsed) {
                return ['error' => 'IA retornou resposta inválida.'];
            }

            $offer->niche = $this->sanitize($parsed['nicho'] ?? '', OfferManager::NICHES, $offer->niche);
            $offer->structure = $this->sanitize($parsed['estrutura'] ?? '', OfferManager::STRUCTURES, $offer->structure);
            $offer->language = $this->sanitize($parsed['idioma'] ?? '', ['pt', 'es', 'en'], $offer->language ?: 'pt');
            $offer->score = max(0, min(100, (int)($parsed['score'] ?? 0)));
            $offer->ai_summary = mb_substr($parsed['resumo'] ?? '', 0, 2000);
            $offer->ai_data = $parsed;
            $offer->updated_at = date('Y-m-d H:i:s');
            $offer->save();

            $this->maybeAutoApprove($offer);

            return ['success' => true, 'offer_id' => (int)$offer->id, 'analysis' => $parsed, 'provider' => $config['provider']];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function analyzePending(int $limit = 10, int $userId = 0): array
    {
        if (!Database::available()) return ['analyzed' => 0, 'errors' => []];

        $summary = ['analyzed' => 0, 'errors' => []];

        try {
            $offers = \AfiliaFacil\Models\Offer::where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('ai_summary')->orWhere('ai_summary', '');
                })
                ->orderByDesc('ads_count')
                ->limit($limit)
                ->get();

            foreach ($offers as $offer) {
                $result = $this->analyze((int)$offer->id, $userId);
                if (!empty($result['success'])) {
                    $summary['analyzed']++;
                } else {
                    $summary['errors'][(string)$offer->id] = $result['error'] ?? 'erro';
                }
            }
        } catch (Throwable $e) {
            $summary['errors']['analyze'] = $e->getMessage();
        }

        return $summary;
    }

    public function suggestTerms(int $userId = 0): ?array
    {
        $candidates = AiConfig::candidates($userId);
        if (empty($candidates)) return null;

        $existing = \AfiliaFacil\Models\Offer::where('status', 'approved')
            ->orderByDesc('score')
            ->limit(20)
            ->pluck('name')
            ->all();

        $messages = [
            ['role' => 'system', 'content' => 'Você é um estrategista de marketing de afiliados. Responda SOMENTE com JSON válido.'],
            ['role' => 'user', 'content' => "Sugira 10 termos de busca em português para encontrar novas ofertas escalando nas bibliotecas de anúncios (Meta/Google/TikTok). Evite repetir ofertas conhecidas. Responda com JSON: {\"termos\":[\"...\"],\"nichos\":[\"...\"]}\n\nOfertas conhecidas: " . implode(', ', $existing)],
        ];

        $response = AiClient::chatWithFallback($messages, $candidates);
        if ($response === null) return null;

        return $this->parseJson($response);
    }

    private function maybeAutoApprove($offer): void
    {
        $enabled = Settings::get('offers_auto_approve', '0') === '1';
        if (!$enabled) return;

        $threshold = (int)Settings::get('offers_auto_approve_score', '70');
        if ((int)$offer->score < $threshold) return;

        $manager = new OfferManager();
        $manager->approve((int)$offer->id);
    }

    private function sanitize(string $value, array $allowed, string $fallback): string
    {
        $value = mb_strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function parseJson(string $text): ?array
    {
        return Json::parse($text);
    }
}
