<?php

/**
 * Parser de JSON tolerante para respostas de IA.
 *
 * Modelos costumam devolver JSON com cercas de codigo, texto em volta ou
 * quebras de linha literais dentro das strings — json_decode direto falha.
 */
class Json
{
    /**
     * Extrai o primeiro objeto JSON da resposta e decodifica com reparos.
     */
    public static function parse(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;

        // Remove cercas de codigo (```json ... ```), inclusive com texto em volta
        $text = preg_replace('/```[a-zA-Z]*\s*/', '', $text) ?? $text;
        $text = str_replace('```', '', $text);
        $text = trim($text);

        if (!preg_match('/\{[\s\S]*\}/', $text, $m)) return null;
        $json = $m[0];

        // 1) Tentativa direta
        $decoded = json_decode($json, true);
        if (is_array($decoded)) return $decoded;

        // 2) Reparo: quebras de linha literais dentro de strings viram \n
        $repaired = preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"/s', function ($match) {
            return str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $match[0]);
        }, $json);

        if (is_string($repaired)) {
            $decoded = json_decode($repaired, true);
            if (is_array($decoded)) return $decoded;
        }

        // 3) Reparo extra: remove virgulas sobrando antes de } ou ]
        $cleaned = preg_replace('/,\s*([}\]])/', '$1', $repaired ?? $json);
        if (is_string($cleaned)) {
            $decoded = json_decode($cleaned, true);
            if (is_array($decoded)) return $decoded;
        }

        return null;
    }
}
