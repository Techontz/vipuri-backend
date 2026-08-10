<?php

namespace App\Services\Ai;

use App\Constants\Status;
use Illuminate\Support\Facades\Http;

/**
 * Thin provider abstraction over OpenAI and Gemini, ported from the source
 * system's AiGenerator.
 *
 * Credentials come from the admin settings row first, then the environment.
 * When neither is configured the caller receives a `missing_api_key` result so
 * the UI can surface the same "configure your API key" state as the original
 * — the feature is wired end to end, it just needs a key.
 */
class AiGenerator
{
    /**
     * @return array{status: string, content?: string, message?: string, code?: string}
     */
    public static function generate(array $params): array
    {
        $engine = strtolower((string) ($params['engine'] ?? 'openai'));
        $apiKey = (string) ($params['apiKey'] ?? '');
        $model = (string) ($params['model'] ?? '');
        $systemPrompt = (string) ($params['systemPrompt'] ?? '');
        $prompt = (string) ($params['prompt'] ?? '');
        $temperature = (float) ($params['temperature'] ?? 0.4);
        $maxTokens = (int) ($params['maxTokens'] ?? 512);
        $timeout = (int) ($params['timeout'] ?? config('vipuri.ai.timeout', 120));

        if ($engine === 'gemini') {
            return self::callGemini($apiKey, $model ?: config('vipuri.ai.gemini.model'), $systemPrompt, $prompt, $temperature, $maxTokens, $timeout);
        }

        return self::callOpenAi($apiKey, $model ?: config('vipuri.ai.openai.model'), $systemPrompt, $prompt, $temperature, $maxTokens, $timeout);
    }

    /** Generate using whichever engine the administrator has selected. */
    public static function generateDefault(string $systemPrompt, string $prompt, array $options = []): array
    {
        $engine = strtolower((string) ($options['engine'] ?? ''));

        if (! in_array($engine, ['gemini', 'openai'], true)) {
            $configured = (int) (gs('default_engine') ?? config('vipuri.ai.default_engine'));
            $engine = $configured === Status::GEMINI_MODEL ? 'gemini' : 'openai';
        }

        if ($engine === 'gemini') {
            $apiKey = gs('gemini_api_key') ?: config('vipuri.ai.gemini.key');

            if (! $apiKey) {
                return [
                    'status' => 'error',
                    'code' => 'missing_api_key',
                    'message' => 'Gemini API key is not configured. Set GEMINI_API_KEY or add it under Settings → AI.',
                ];
            }

            return self::generate([
                'engine' => 'gemini',
                'apiKey' => $apiKey,
                'model' => $options['model'] ?? config('vipuri.ai.gemini.model'),
                'systemPrompt' => $systemPrompt,
                'prompt' => $prompt,
                'temperature' => $options['temperature'] ?? 0.4,
                'maxTokens' => $options['maxTokens'] ?? 512,
            ]);
        }

        $apiKey = gs('openai_api_key') ?: config('vipuri.ai.openai.key');

        if (! $apiKey) {
            return [
                'status' => 'error',
                'code' => 'missing_api_key',
                'message' => 'OpenAI API key is not configured. Set OPENAI_API_KEY or add it under Settings → AI.',
            ];
        }

        return self::generate([
            'engine' => 'openai',
            'apiKey' => $apiKey,
            'model' => $options['model'] ?? (gs('openai_api_model') ?: config('vipuri.ai.openai.model')),
            'systemPrompt' => $systemPrompt,
            'prompt' => $prompt,
            'temperature' => $options['temperature'] ?? 0.4,
            'maxTokens' => $options['maxTokens'] ?? 512,
        ]);
    }

    private static function callGemini(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $prompt,
        float $temperature,
        int $maxTokens,
        int $timeout,
    ): array {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ])->timeout($timeout)->post(
                config('vipuri.ai.gemini.endpoint') . "/{$model}:generateContent",
                [
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => $temperature,
                        'maxOutputTokens' => $maxTokens,
                    ],
                ]
            );

            if (! $response->successful()) {
                return ['status' => 'error', 'message' => 'Failed to generate a response from Gemini'];
            }

            $text = $response->json('candidates.0.content.parts.0.text');

            return $text
                ? ['status' => 'success', 'content' => $text]
                : ['status' => 'error', 'message' => 'No content generated'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Gemini error: ' . $e->getMessage()];
        }
    }

    private static function callOpenAi(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $prompt,
        float $temperature,
        int $maxTokens,
        int $timeout,
    ): array {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->post(config('vipuri.ai.openai.endpoint'), [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
            ]);

            if ($response->failed()) {
                return [
                    'status' => 'error',
                    'message' => $response->json('error.message') ?? 'Failed to generate a response from OpenAI',
                ];
            }

            $text = $response->json('choices.0.message.content');

            return $text
                ? ['status' => 'success', 'content' => $text]
                : ['status' => 'error', 'message' => 'No content generated'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'OpenAI error: ' . $e->getMessage()];
        }
    }
}
