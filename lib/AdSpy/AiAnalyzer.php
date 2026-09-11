<?php

require_once __DIR__ . '/AiClient.php';
require_once __DIR__ . '/AiConfig.php';
require_once __DIR__ . '/AdSpyQuota.php';

class AiAnalyzer
{
    public function analyzeCampaign(array $signals, array $ads, int $userId, string $plan): array
    {
        $quota = AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_ANALYSIS);
        if (!$quota['allowed']) {
            return ['error' => 'Sua cota de análises IA do mês foi atingida (' . $quota['used'] . '/' . $quota['limit'] . '). Faça upgrade para continuar.'];
        }

        $config = AiConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            return ['error' => 'IA não configurada. Configure o Cloudflare Workers AI da plataforma (CF_AI_TOKEN) ou sua própria chave (BYOK).'];
        }

        $summary = $this->buildAdsSummary($ads);
        $signalsText = "Domínio: {$signals['domain']}\nMarca: {$signals['brand']}\nTítulo: {$signals['title']}\nTermos: " . implode(', ', $signals['terms']);
        if (!empty($signals['checkouts'])) {
            $signalsText .= "\nCheckouts detectados: " . implode(', ', $signals['checkouts']);
        }

        $messages = [
            ['role' => 'system', 'content' => 'Você é um estrategista sênior de tráfego pago e marketing de afiliados no Brasil. Responda em português do Brasil, de forma objetiva e prática, sempre em JSON válido quando solicitado.'],
            ['role' => 'user', 'content' => "Analise a campanha abaixo e responda SOMENTE com um JSON válido no formato:\n{\"resumo\":\"...\",\"angulos\":[\"...\"],\"oferta\":\"...\",\"cta\":\"...\",\"publico\":\"...\",\"funil\":\"...\",\"termos_busca\":[\"...\"],\"sugestoes\":[\"...\"]}\n\nSINAIS DA PÁGINA CLONADA:\n{$signalsText}\n\nANÚNCIOS ENCONTRADOS NAS BIBLIOTECAS (resumo):\n{$summary}"],
        ];

        $response = AiClient::chat($messages, $config);
        if ($response === null) {
            return ['error' => 'Falha na chamada da IA (verifique a configuração do provider/chave).'];
        }

        AdSpyQuota::consume($userId, AdSpyQuota::KIND_ANALYSIS, 'analise-campanha', $config['provider'], 1, false);

        $parsed = $this->parseJson($response);
        return [
            'analysis' => $parsed ?: ['resumo' => trim($response)],
            'provider' => $config['provider'],
            'model' => $config['model'],
            'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_ANALYSIS),
        ];
    }

    private function buildAdsSummary(array $ads): string
    {
        if (empty($ads)) return 'Nenhum anúncio encontrado nas bibliotecas.';

        $lines = [];
        foreach (array_slice($ads, 0, 25) as $ad) {
            $parts = [];
            if (!empty($ad['advertiser'])) $parts[] = 'Anunciante: ' . $ad['advertiser'];
            if (!empty($ad['provider'])) $parts[] = 'Plataforma: ' . $ad['provider'];
            if (!empty($ad['started_at'])) $parts[] = 'Início: ' . $ad['started_at'];
            if (!empty($ad['text'])) $parts[] = 'Texto: ' . mb_substr($ad['text'], 0, 180);
            if (!empty($ad['title'])) $parts[] = 'Título: ' . mb_substr($ad['title'], 0, 100);
            $lines[] = '- ' . implode(' | ', $parts);
        }
        return implode("\n", $lines);
    }

    private function parseJson(string $text): ?array
    {
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
}
