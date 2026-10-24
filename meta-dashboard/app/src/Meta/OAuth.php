<?php
declare(strict_types=1);

namespace App\Meta;

use App\Config;

/** Flujo de "Iniciar sesión con Facebook" para obtener tokens de larga duración. */
final class OAuth
{
    public static function redirectUri(): string
    {
        return Config::url('index.php?r=oauth-callback');
    }

    public static function loginUrl(string $state): string
    {
        $params = [
            'client_id'     => Config::get('meta.app_id'),
            'redirect_uri'  => self::redirectUri(),
            'state'         => $state,
            'response_type' => 'code',
        ];
        $configId = (string) Config::get('meta.config_id', '');
        if ($configId !== '') {
            $params['config_id'] = $configId;
        } else {
            $params['scope'] = implode(',', (array) Config::get('meta.scopes', []));
        }
        return 'https://www.facebook.com/' . GraphClient::version() . '/dialog/oauth?' . http_build_query($params);
    }

    /** Código → token corto → token largo (60 días). */
    public static function exchangeCode(string $code): array
    {
        $short = self::tokenRequest([
            'client_id'     => Config::get('meta.app_id'),
            'client_secret' => Config::get('meta.app_secret'),
            'redirect_uri'  => self::redirectUri(),
            'code'          => $code,
        ]);
        return self::longLived($short['access_token']);
    }

    public static function longLived(string $token): array
    {
        return self::tokenRequest([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => Config::get('meta.app_id'),
            'client_secret'     => Config::get('meta.app_secret'),
            'fb_exchange_token' => $token,
        ]);
    }

    /** Info del token: vencimiento, scopes, usuario. */
    public static function debugToken(string $token): array
    {
        $appToken = Config::get('meta.app_id') . '|' . Config::get('meta.app_secret');
        $url = rtrim((string) Config::get('meta.graph_base', 'https://graph.facebook.com'), '/') . '/' . GraphClient::version() . '/debug_token?' . http_build_query([
            'input_token'  => $token,
            'access_token' => $appToken,
        ]);
        $data = self::httpGet($url);
        return $data['data'] ?? [];
    }

    private static function tokenRequest(array $params): array
    {
        $url = rtrim((string) Config::get('meta.graph_base', 'https://graph.facebook.com'), '/') . '/' . GraphClient::version() . '/oauth/access_token?' . http_build_query($params);
        $data = self::httpGet($url);
        if (empty($data['access_token'])) {
            throw GraphClient::exceptionFrom($data, 400);
        }
        return $data;
    }

    private static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new GraphException('No se pudo contactar a Meta', 2, 0, 'Network', $status ?: 503);
        }
        if (isset($data['error'])) {
            throw GraphClient::exceptionFrom($data, $status);
        }
        return $data;
    }
}
