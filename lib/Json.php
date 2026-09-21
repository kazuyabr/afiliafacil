<?php

/**
 * Parser de JSON tolerante para respostas de IA.
 *
 * Modelos costumam devolver JSON com cercas de codigo, texto em volta, quebras de
 * linha literais dentro das strings ou ate TRUNCADO (resposta cortada no meio) —
 * json_decode direto falha em todos esses casos.
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

        // Do primeiro { ate o ultimo } (ou ate o fim, quando truncado)
        $start = strpos($text, '{');
        if ($start === false) return null;

        $json = substr($text, $start);
        $lastClose = strrpos($json, '}');
        if ($lastClose !== false) {
            $json = substr($json, 0, $lastClose + 1);
        }

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

        $base = is_string($repaired) ? $repaired : $json;

        // 3) Reparo: JSON TRUNCADO (fecha strings/arrays/objetos abertos)
        $closed = self::closeOpenStructures($base);
        $decoded = json_decode($closed, true);
        if (is_array($decoded)) return $decoded;

        // 4) Reparo extra: remove virgulas sobrando antes de } ou ]
        $cleaned = preg_replace('/,\s*([}\]])/', '$1', $closed);
        if (is_string($cleaned)) {
            $decoded = json_decode($cleaned, true);
            if (is_array($decoded)) return $decoded;
        }

        return null;
    }

    /**
     * Fecha estruturas abertas de um JSON truncado (respeitando strings/escapes).
     */
    public static function closeOpenStructures(string $json): string
    {
        $curly = 0;
        $square = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0, $len = strlen($json); $i < $len; $i++) {
            $ch = $json[$i];

            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($ch === '"') { $inString = !$inString; continue; }
            if ($inString) continue;

            if ($ch === '{') $curly++;
            elseif ($ch === '}') $curly = max(0, $curly - 1);
            elseif ($ch === '[') $square++;
            elseif ($ch === ']') $square = max(0, $square - 1);
        }

        $out = $json;
        if ($inString) $out .= '"';
        $out .= str_repeat(']', $square);
        $out .= str_repeat('}', $curly);

        return $out;
    }
}
