<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Audit.php';

class ContentModerator
{
    public const CATEGORY_CRIME = 'crime';
    public const CATEGORY_PROFANITY = 'profanity';
    public const CATEGORY_PII = 'pii';

    private const CRIME_PATTERNS = [
        '/\b(como|quero|vou|preciso|me ajude a|ensina a|plano para)\s+(matar|assassinar)\b(?!.{0,40}(concorr|mercado|vendas|tr[áa]fego|audi[êe]ncia|custo|pre[çc]o))/i',
        '/\b(como|quero|vou|preciso|me ajude a|ensina a|plano para)\s+(roubar|assaltar|furtar|sequestrar|extorquir|chantagear|traficar|contrabandear|lavar dinheiro|fraudar|estelionatar|clonar cart[ãa]o|invadir|hackear)\b/i',
        '/\b(matar|assassinar)\s+(algu[ée]m|uma pessoa|meu|minha|ele|ela|voc[êe]|o vizinho|a vizinha)\b/i',
        '/\b(esconder|sumir com)\s+(o\s+)?corpo\b/i',
        '/\b(n[ãa]o ser pego|escapar da pol[íi]cia|burlar a lei|crime perfeito)\b/i',
        '/\b(estelionato|pedofilia|abuso infantil|tr[áa]fico de (drogas|armas|pessoas)|lavagem de dinheiro|extors[ãa]o|sequestro|terrorismo|recepta[çc][ãa]o|agiotagem|pornografia infantil)\b/i',
        '/\b(vender|comprar)\s+(droga|drogas|maconha|coca[íi]na|crack|arma|armas de fogo)\b/i',
        '/\b(documento|identidade|dinheiro|nota)\s+fals[oa]\b/i',
        '/\b(pirata|pirataria|conte[úu]do pirata|baixar ilegal|download ilegal)\b/i',
        '/\b(golpe|estelionato)\s+(do|da|no|na|em)\b/i',
    ];

    private const PROFANITY_WORDS = [
        'caralho', 'porra', 'merda', 'puta', 'putaria', 'foder', 'fodase', 'foda-se',
        'buceta', 'pica', 'pinto', 'cuzao', 'cu-zao', 'arrombado', 'viado', 'viadinho',
        'corno', 'cornuda', 'filho da puta', 'fdp', 'desgraçado', 'desgracado',
        'otario', 'otário', 'idiota de merda', 'vai se foder', 'vai tomar no',
    ];

    private const PII_PATTERNS = [
        ['pattern' => '/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/', 'label' => 'CPF'],
        ['pattern' => '/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/', 'label' => 'CNPJ'],
        ['pattern' => '/\b(?:\+?55\s?)?\(?\d{2}\)?\s?9?\d{4}[-\s]?\d{4}\b/', 'label' => 'telefone'],
        ['pattern' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', 'label' => 'e-mail'],
        ['pattern' => '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/', 'label' => 'cartão'],
        ['pattern' => '/\b\d{5}-\d{3}\b/', 'label' => 'CEP'],
    ];

    public static function screen(string $text, string $context = '', int $userId = 0): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['allowed' => true, 'action' => 'allow', 'clean' => $text, 'category' => '', 'reason' => '', 'matches' => []];
        }

        foreach (self::CRIME_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $eventId = self::logEvent($userId, $context, self::CATEGORY_CRIME, 'blocked', 'Possível conteúdo criminoso: "' . mb_substr($m[0], 0, 120) . '"', $text, '');
                return [
                    'allowed' => false,
                    'action' => 'block',
                    'clean' => '',
                    'category' => self::CATEGORY_CRIME,
                    'reason' => 'Não posso ajudar com isso. A AfiliaFacil não permite uso para atividades ilegais, e este registro foi armazenado com data, hora e conteúdo para eventual solicitação de autoridades.',
                    'matches' => [$m[0]],
                    'event_id' => $eventId,
                ];
            }
        }

        $matches = [];
        $clean = $text;

        foreach (self::PROFANITY_WORDS as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            if (preg_match($pattern, $clean)) {
                $matches[] = $word;
                $clean = preg_replace($pattern, '[redigido]', $clean);
            }
        }

        foreach (self::PII_PATTERNS as $pii) {
            if (preg_match($pii['pattern'], $clean)) {
                $matches[] = $pii['label'];
                $clean = preg_replace($pii['pattern'], '[' . $pii['label'] . ' removido]', $clean);
            }
        }

        if (!empty($matches)) {
            self::logEvent($userId, $context, self::CATEGORY_PII, 'redacted', 'Dados sensíveis/palavras redigidas: ' . implode(', ', array_unique($matches)), $text, $clean);
            return [
                'allowed' => true,
                'action' => 'redact',
                'clean' => $clean,
                'category' => self::CATEGORY_PII,
                'reason' => '',
                'matches' => array_values(array_unique($matches)),
            ];
        }

        return ['allowed' => true, 'action' => 'allow', 'clean' => $text, 'category' => '', 'reason' => '', 'matches' => []];
    }

    /**
     * Redacao para SAIDA da IA: aplica apenas profanidade + PII (nunca bloqueia o texto inteiro).
     * O bloqueio por crime e para a ENTRADA do usuario (screen) — aplicar aqui gerava falso
     * positivo e apagava a resposta inteira (ex.: falar de jogos com termos de guerra).
     */
    public static function redact(string $text): string
    {
        $text = trim($text);
        if ($text === '') return $text;

        $clean = $text;

        foreach (self::PROFANITY_WORDS as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            $clean = preg_replace($pattern, '[redigido]', $clean);
        }

        foreach (self::PII_PATTERNS as $pii) {
            $clean = preg_replace($pii['pattern'], '[' . $pii['label'] . ' removido]', $clean);
        }

        return (string)$clean;
    }

    public static function sanitizeForTraining(string $text): array
    {
        $clean = $text;

        foreach (self::PII_PATTERNS as $pii) {
            $clean = preg_replace($pii['pattern'], '[removido]', $clean);
        }

        foreach (self::PROFANITY_WORDS as $word) {
            $clean = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', '[redigido]', $clean);
        }

        $phiPatterns = [
            '/\b(hiv|aids|c[âa]ncer|depress[ãa]o|bipolar|esquizofrenia|diabetes|epilepsia)\b/iu',
            '/\b(diagn[óo]stico|laudo m[ée]dico|receita m[ée]dica|exame de sangue|interna[çc][ãa]o)\b/iu',
        ];
        $phi = false;
        foreach ($phiPatterns as $pattern) {
            if (preg_match($pattern, $clean)) {
                $phi = true;
                $clean = preg_replace($pattern, '[dado sensível]', $clean);
            }
        }

        return ['text' => $clean, 'had_pii' => $clean !== $text, 'had_phi' => $phi];
    }

    public static function logEvent(int $userId, string $context, string $category, string $action, string $reason, string $content, string $cleanContent): ?int
    {
        if (!Database::available()) return null;

        try {
            $event = \AfiliaFacil\Models\ModerationEvent::create([
                'user_id' => $userId,
                'context' => mb_substr($context, 0, 40),
                'category' => $category,
                'action' => $action,
                'reason' => mb_substr($reason, 0, 500),
                'content' => mb_substr($content, 0, 8000),
                'clean_content' => mb_substr($cleanContent, 0, 8000),
                'ip' => Audit::clientIp(),
                'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($action === 'blocked') {
                Audit::log('moderation_blocked', 'user', (string)$userId, ['context' => $context, 'category' => $category]);
            }

            return (int)$event->id;
        } catch (Throwable $e) {
            return null;
        }
    }
}
