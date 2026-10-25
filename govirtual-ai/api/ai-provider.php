<?php

declare(strict_types=1);

require_once __DIR__ . '/ai-core.php';

function ai_provider_config(): array
{
    $fallbacks = array_values(array_filter(array_map('trim', explode(',', getenv('AI_FALLBACK_PROVIDERS') ?: 'openrouter,ollama'))));
    return [
        'primary' => strtolower(trim(getenv('AI_PROVIDER') ?: 'groq')),
        'fallbacks' => $fallbacks,
        'request_timeout' => max(8, min(60, (int)(getenv('AI_REQUEST_TIMEOUT') ?: 25))),
        'debug' => filter_var(getenv('AI_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    ];
}

function ai_provider_model(string $provider): string
{
    switch ($provider) {
        case 'groq':
            return getenv('GROQ_MODEL') ?: 'llama-3.1-8b-instant';
        case 'gemini':
            return getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite';
        case 'openrouter':
            return getenv('OPENROUTER_MODEL') ?: 'openai/gpt-4o-mini';
        case 'ollama':
            return getenv('OLLAMA_MODEL') ?: 'qwen2.5:7b';
        default:
            return '';
    }
}

function ai_gemini_models(): array
{
    $primary = trim((string)(getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite'));
    $fallbacks = array_values(array_filter(array_map('trim', explode(',', (string)(getenv('GEMINI_FALLBACK_MODELS') ?: '')))));
    return array_values(array_unique(array_filter(array_merge([$primary], $fallbacks))));
}

function ai_provider_caps(string $provider): array
{
    switch ($provider) {
        case 'groq':
        case 'openrouter':
        case 'gemini':
        case 'ollama':
            return ['supportsStructuredOutput' => true, 'supportsJsonMode' => true];
        default:
            return ['supportsStructuredOutput' => false, 'supportsJsonMode' => false];
    }
}

function ai_provider_state_file(): string
{
    return sys_get_temp_dir() . '/govirtual-ai-provider-state.json';
}

function ai_provider_state(): array
{
    $state = json_decode((string)@file_get_contents(ai_provider_state_file()), true);
    return is_array($state) ? $state : [];
}

function ai_provider_record(string $provider, bool $success, ?string $error = null): void
{
    $state = ai_provider_state();
    $row = (array)($state[$provider] ?? []);
    $failures = $success ? 0 : (int)($row['failures'] ?? 0) + 1;
    $state[$provider] = ['failures' => $failures, 'cooldown_until' => !$success && $failures >= 3 ? time() + 60 : 0, 'last_error' => $success ? null : $error];
    @file_put_contents(ai_provider_state_file(), json_encode($state), LOCK_EX);
}

function ai_provider_in_cooldown(string $provider): bool
{
    return (int)(ai_provider_state()[$provider]['cooldown_until'] ?? 0) > time();
}

function ai_http_post_json(string $url, array $payload, array $headers, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => is_string($body) ? $body : '', 'code' => $code, 'error' => $error ?: null];
}

function ai_is_retryable_provider_error(array $result): bool
{
    $code = (int)($result['http_code'] ?? $result['code'] ?? 0);
    $error = strtolower((string)($result['error'] ?? ''));
    return $code === 429 || $code >= 500 || in_array($error, ['missing_api_key', 'invalid_json', 'ollama_disabled'], true) || str_contains($error, 'timeout') || str_contains($error, 'timed out') || str_contains($error, 'could not resolve host') || str_contains($error, 'failed to connect') || str_contains($error, 'connection');
}

function ai_parse_provider_content(string $provider, array $result): array
{
    $body = $result['body'] ?? '';
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return ['success' => false, 'provider' => $provider, 'model' => '', 'content' => '', 'latency_ms' => 0, 'error' => 'invalid_json', 'raw' => $body, 'http_code' => (int)($result['code'] ?? 0)];

    switch ($provider) {
        case 'groq':
        case 'openrouter':
            return [
                'success' => isset($decoded['choices'][0]['message']['content']),
                'provider' => $provider,
                'model' => (string)($decoded['model'] ?? ''),
                'content' => trim((string)($decoded['choices'][0]['message']['content'] ?? '')),
                'latency_ms' => 0,
                'error' => null,
                'raw' => $decoded,
                'http_code' => (int)($result['code'] ?? 0),
            ];
        case 'gemini':
            return [
                'success' => isset($decoded['candidates'][0]['content']['parts'][0]['text']),
                'provider' => $provider,
                'model' => (string)($decoded['modelVersion'] ?? ''),
                'content' => trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '')),
                'latency_ms' => 0,
                'error' => null,
                'raw' => $decoded,
                'http_code' => (int)($result['code'] ?? 0),
            ];
        case 'ollama':
            return [
                'success' => isset($decoded['message']['content']) || isset($decoded['response']),
                'provider' => $provider,
                'model' => (string)($decoded['model'] ?? ''),
                'content' => trim((string)($decoded['message']['content'] ?? $decoded['response'] ?? '')),
                'latency_ms' => 0,
                'error' => null,
                'raw' => $decoded,
                'http_code' => (int)($result['code'] ?? 0),
            ];
        default:
            return ['success' => false, 'provider' => $provider, 'model' => '', 'content' => '', 'latency_ms' => 0, 'error' => 'unknown_provider', 'raw' => $decoded, 'http_code' => (int)($result['code'] ?? 0)];
    }
}

function ai_provider_request(string $provider, array $messages, array $options): array
{
    $timeout = (int)($options['timeout'] ?? 25);
    $temperature = (float)($options['temperature'] ?? 0.2);
    $maxTokens = (int)($options['max_tokens'] ?? 600);
    $model = (string)($options['model'] ?? ai_provider_model($provider));
    $jsonMode = (bool)($options['json_mode'] ?? true);
    $started = microtime(true);

    if ($provider === 'ollama') {
        if (!filter_var(getenv('OLLAMA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            return ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'ollama_disabled', 'raw' => null, 'http_code' => 0];
        }
        $url = rtrim(getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434', '/') . '/api/chat';
        $payload = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => $jsonMode ? 'json' : '', 'options' => ['temperature' => $temperature, 'num_predict' => $maxTokens]];
        $result = ai_http_post_json($url, $payload, ['Content-Type: application/json'], $timeout);
        $parsed = ai_parse_provider_content($provider, $result);
        $parsed['latency_ms'] = (int)((microtime(true) - $started) * 1000);
        return $parsed;
    }

    if ($provider === 'gemini') {
        $key = getenv('GEMINI_API_KEY') ?: '';
        if ($key === '') return ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'missing_api_key', 'raw' => null, 'http_code' => 0];
        $models = (array)($options['models'] ?? ai_gemini_models());
        $last = null;
        foreach ($models as $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
            $payload = ['systemInstruction' => ['parts' => [['text' => (string)($options['system'] ?? '')]]], 'contents' => [[ 'role' => 'user', 'parts' => [['text' => (string)($options['user'] ?? '')]] ]], 'generationConfig' => ['temperature' => $temperature, 'responseMimeType' => $jsonMode ? 'application/json' : 'text/plain', 'maxOutputTokens' => $maxTokens]];
            $result = ai_http_post_json($url, $payload, ['Content-Type: application/json'], $timeout);
            $parsed = ai_parse_provider_content($provider, $result);
            $parsed['model'] = $model;
            $parsed['latency_ms'] = (int)((microtime(true) - $started) * 1000);
            if ($parsed['success']) return $parsed;
            $last = $parsed;
        }
        return $last ?? ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'gemini_models_unavailable', 'raw' => null, 'http_code' => 0];
    }

    $base = $provider === 'groq' ? (getenv('GROQ_BASE_URL') ?: 'https://api.groq.com/openai/v1') : (getenv('OPENROUTER_BASE_URL') ?: 'https://openrouter.ai/api/v1');
    $key = $provider === 'groq' ? (getenv('GROQ_API_KEY') ?: '') : (getenv('OPENROUTER_API_KEY') ?: '');
    if ($key === '') return ['success' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'error' => 'missing_api_key', 'raw' => null, 'http_code' => 0];
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
    if ($provider === 'openrouter') $headers[] = 'HTTP-Referer: ' . (getenv('APP_URL') ?: 'https://localhost');
    $payload = ['model' => $model, 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens];
    if ($jsonMode) $payload['response_format'] = ['type' => 'json_object'];
    $result = ai_http_post_json($base . '/chat/completions', $payload, $headers, $timeout);
    $parsed = ai_parse_provider_content($provider, $result);
    $parsed['latency_ms'] = (int)((microtime(true) - $started) * 1000);
    return $parsed;
}

function ai_provider_router(array $messages, array $options = []): array
{
    $config = ai_provider_config();
    $primary = strtolower((string)($options['provider'] ?? $config['primary']));
    $candidates = array_values(array_unique(array_filter(array_merge([$primary], $config['fallbacks'], ['ollama']))));
    $attempts = [];
    $last = null;

    foreach ($candidates as $provider) {
        if (ai_provider_in_cooldown($provider)) {
            $attempts[] = ['provider' => $provider, 'success' => false, 'http_code' => 0, 'latency_ms' => 0, 'error' => 'circuit_open'];
            continue;
        }
        $request = $options['request_callback'] ?? null;
        $result = is_callable($request) ? $request($provider, $messages, $options) : ai_provider_request($provider, $messages, $options + ['timeout' => $config['request_timeout']]);
        $validStructured = !($options['json_mode'] ?? true) || ai_parse_json((string)($result['content'] ?? '')) !== null;
        if (($result['success'] ?? false) && !$validStructured) { $result['success'] = false; $result['error'] = 'malformed_structured_output'; }
        $attempts[] = ['provider' => $provider, 'success' => (bool)($result['success'] ?? false), 'http_code' => (int)($result['http_code'] ?? 0), 'latency_ms' => (int)($result['latency_ms'] ?? 0), 'error' => $result['error'] ?? null];
        if (($result['success'] ?? false) && trim((string)($result['content'] ?? '')) !== '') {
            ai_provider_record($provider, true);
            return ['success' => true, 'provider' => $provider, 'model' => (string)($result['model'] ?? ''), 'content' => (string)($result['content'] ?? ''), 'latency_ms' => (int)($result['latency_ms'] ?? 0), 'error' => null, 'fallback_used' => $provider !== $primary, 'attempted_providers' => $attempts];
        }
        $last = $result;
        ai_provider_record($provider, false, (string)($result['error'] ?? 'provider_error'));
        if (($result['error'] ?? '') !== 'malformed_structured_output' && !ai_is_retryable_provider_error($result)) break;
    }

    return ['success' => false, 'provider' => $primary, 'model' => ai_provider_model($primary), 'content' => '', 'latency_ms' => (int)($last['latency_ms'] ?? 0), 'error' => $last['error'] ?? 'provider_unavailable', 'fallback_used' => count($attempts) > 1, 'attempted_providers' => $attempts];
}

function get_ai_provider_health(): array
{
    $providers = ['groq', 'gemini', 'openrouter', 'ollama'];
    $configured = [];
    foreach ($providers as $provider) {
        switch ($provider) {
            case 'groq':
                $configured[$provider] = (getenv('GROQ_API_KEY') ?: '') !== '';
                break;
            case 'gemini':
                $configured[$provider] = (getenv('GEMINI_API_KEY') ?: '') !== '';
                break;
            case 'openrouter':
                $configured[$provider] = (getenv('OPENROUTER_API_KEY') ?: '') !== '';
                break;
            case 'ollama':
                $configured[$provider] = filter_var(getenv('OLLAMA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
                break;
        }
    }
    $state = ai_provider_state();
    $health = [];
    foreach ($configured as $provider => $isConfigured) $health[$provider] = ['configured' => $isConfigured, 'cooldown' => (int)($state[$provider]['cooldown_until'] ?? 0) > time()];
    return ['primary' => strtolower(trim(getenv('AI_PROVIDER') ?: 'groq')), 'providers' => $health];
}
