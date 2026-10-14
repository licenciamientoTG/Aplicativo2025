<?php
/**
 * Cliente mínimo de la API de Gemini (generateContent). La llave y el
 * modelo viven en header.class.php (GEMINI_API_KEY / GEMINI_MODEL) para no
 * exponerlos nunca al navegador.
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const TIMEOUT  = 60;

    /**
     * @return array ['success' => bool, 'texto' => string, 'message' => string]
     */
    public static function generar(string $instrucciones, string $prompt): array
    {
        if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
            return ['success' => false, 'texto' => '', 'message' => 'Falta configurar GEMINI_API_KEY en header.class.php'];
        }

        $body = json_encode([
            'systemInstruction' => ['parts' => [['text' => $instrucciones]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig'  => ['temperature' => 0.3],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $ch = curl_init(sprintf(self::ENDPOINT, GEMINI_MODEL));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        ]);
        $resp  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['success' => false, 'texto' => '', 'message' => 'No se pudo conectar con Gemini: ' . $error];
        }
        $data = json_decode($resp, true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            error_log('GeminiClient: ' . $code . ' ' . substr($resp, 0, 500));
            return ['success' => false, 'texto' => '', 'message' => 'Gemini respondió con error: ' . $msg];
        }

        $texto = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $p) {
            $texto .= $p['text'] ?? '';
        }
        $texto = trim($texto);
        if ($texto === '') {
            $motivo = $data['candidates'][0]['finishReason'] ?? 'sin contenido';
            return ['success' => false, 'texto' => '', 'message' => 'Gemini no devolvió texto (' . $motivo . ')'];
        }
        return ['success' => true, 'texto' => $texto, 'message' => ''];
    }
}
