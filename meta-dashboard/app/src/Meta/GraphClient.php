<?php
declare(strict_types=1);

namespace App\Meta;

use App\Config;

/**
 * Cliente mínimo de la Graph API de Meta: GET con paginación, batch,
 * appsecret_proof, reintentos y lectura de los headers de uso.
 */
final class GraphClient
{
    private static function base(): string
    {
        return rtrim((string) Config::get('meta.graph_base', 'https://graph.facebook.com'), '/') . '/';
    }

    /** Último header de uso recibido (X-Business-Use-Case-Usage / X-App-Usage). */
    public static array $lastUsage = [];

    public function __construct(private readonly string $token)
    {
    }

    public static function version(): string
    {
        return (string) Config::get('meta.graph_version', 'v24.0');
    }

    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    /** Recorre todas las páginas de un edge hasta $max elementos. */
    public function paginate(string $path, array $params = [], int $max = 500): array
    {
        $items = [];
        $params['limit'] ??= min(100, $max);
        $response = $this->get($path, $params);
        while (true) {
            foreach ($response['data'] ?? [] as $row) {
                $items[] = $row;
                if (count($items) >= $max) {
                    return $items;
                }
            }
            $next = $response['paging']['next'] ?? null;
            if (!$next) {
                return $items;
            }
            $response = $this->requestUrl('GET', $next);
        }
    }

    /**
     * Graph Batch API: hasta 50 requests en una sola llamada HTTP.
     * $requests = ['clave' => ['path' => 'act_1/insights', 'params' => [...]]]
     * Devuelve ['clave' => array|GraphException]
     */
    public function batch(array $requests): array
    {
        $results = [];
        foreach (array_chunk($requests, 50, true) as $chunk) {
            $keys = array_keys($chunk);
            $batch = [];
            foreach ($chunk as $req) {
                $relative = ltrim($req['path'], '/');
                if (!empty($req['params'])) {
                    $relative .= '?' . http_build_query($this->normalize($req['params']));
                }
                $batch[] = ['method' => 'GET', 'relative_url' => $relative];
            }
            $response = $this->request('POST', '', ['batch' => json_encode($batch), 'include_headers' => 'false']);
            foreach ($response as $i => $item) {
                $key = $keys[$i];
                if (!is_array($item)) {
                    $results[$key] = new GraphException('Respuesta vacía en batch', 0);
                    continue;
                }
                $body = json_decode($item['body'] ?? '', true) ?? [];
                $results[$key] = ($item['code'] ?? 500) >= 400
                    ? self::exceptionFrom($body, (int) $item['code'])
                    : $body;
            }
        }
        return $results;
    }

    private function normalize(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = json_encode($v);
            } elseif (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false';
            }
        }
        return $params;
    }

    private function authParams(): array
    {
        $params = ['access_token' => $this->token];
        $secret = (string) Config::get('meta.app_secret', '');
        if ($secret !== '') {
            $params['appsecret_proof'] = hash_hmac('sha256', $this->token, $secret);
        }
        return $params;
    }

    private function request(string $method, string $path, array $params): array
    {
        $url = self::base() . self::version() . '/' . ltrim($path, '/');
        return $this->requestUrl($method, $url, $this->normalize($params));
    }

    private function requestUrl(string $method, string $url, array $params = []): array
    {
        // Las URLs de "paging.next" ya traen el access_token; igual forzamos el nuestro + proof.
        $params = array_merge($params, $this->authParams());
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->send($method, $url, $params);
            } catch (GraphException $e) {
                if ($attempt < 3 && ($e->isTransient() || ($e->isRateLimit() && $attempt < 2))) {
                    usleep(500000 * $attempt * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    private function send(string $method, string $url, array $params): array
    {
        $ch = curl_init();
        $headers = [];
        if ($method === 'GET') {
            // Si la URL ya trae access_token (paging), lo reemplazamos.
            $parts = parse_url($url);
            $existing = [];
            parse_str($parts['query'] ?? '', $existing);
            $query = http_build_query(array_merge($existing, $params));
            $url = strtok($url, '?') . '?' . $query;
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        foreach (['x-business-use-case-usage', 'x-app-usage', 'x-ad-account-usage'] as $h) {
            if (isset($headers[$h])) {
                self::$lastUsage[$h] = json_decode($headers[$h], true);
            }
        }

        if ($raw === false) {
            throw new GraphException('Error de conexión con Meta: ' . $curlError, 2, 0, 'Network', 503);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new GraphException('Respuesta inválida de Meta (HTTP ' . $status . ')', 1, 0, 'Parse', $status);
        }
        if (isset($data['error']) || $status >= 400) {
            throw self::exceptionFrom($data, $status);
        }
        return $data;
    }

    public static function exceptionFrom(array $body, int $status): GraphException
    {
        $err = $body['error'] ?? [];
        $msg = $err['error_user_msg'] ?? $err['message'] ?? ('Error HTTP ' . $status);
        return new GraphException(
            (string) $msg,
            (int) ($err['code'] ?? 0),
            (int) ($err['error_subcode'] ?? 0),
            (string) ($err['type'] ?? ''),
            $status,
            $err['fbtrace_id'] ?? null,
        );
    }
}
