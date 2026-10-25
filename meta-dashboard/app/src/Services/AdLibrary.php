<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Db;
use InvalidArgumentException;

/**
 * Biblioteca de anuncios por nicho. La API de Meta (ads_archive) sólo devuelve
 * anuncios comerciales entregados en la UE/Reino Unido; para Argentina armamos
 * búsquedas guardadas (palabras clave + competidores) que abren la Biblioteca
 * oficial ya filtrada, y un archivo de referencias (swipe file) del equipo.
 */
final class AdLibrary
{
    public const COUNTRIES = ['AR' => 'Argentina', 'UY' => 'Uruguay', 'CL' => 'Chile', 'MX' => 'México', 'BR' => 'Brasil', 'CO' => 'Colombia', 'PE' => 'Perú', 'ES' => 'España', 'US' => 'Estados Unidos', 'ALL' => 'Todos los países'];
    public const FORMATS = ['imagen' => 'Imagen', 'video' => 'Video', 'carrusel' => 'Carrusel', 'reel' => 'Reel / Story', 'catalogo' => 'Catálogo', 'otro' => 'Otro'];

    /** $clientIds: marcas visibles para el usuario (null = todas). */
    public static function all(?array $clientIds = null): array
    {
        $niches = Db::all('SELECT * FROM adlib_niches ORDER BY name');
        $countryOf = array_column($niches, 'country', 'id');
        $comp = [];
        foreach (Db::all('SELECT * FROM adlib_competitors ORDER BY name') as $c) {
            $comp[(int) $c['niche_id']][] = $c + ['library_url' => self::pageUrl($c['page_id'], $c['name'], $countryOf[$c['niche_id']] ?? 'ALL')];
        }
        $counts = [];
        foreach (Db::all('SELECT niche_id, COUNT(*) AS n FROM adlib_saved GROUP BY niche_id') as $r) {
            $counts[(int) $r['niche_id']] = (int) $r['n'];
        }
        foreach ($niches as &$n) {
            $n['id'] = (int) $n['id'];
            $n['keywords'] = self::keywords($n['keywords']);
            $n['competitors'] = $comp[$n['id']] ?? [];
            $n['saved'] = $counts[$n['id']] ?? 0;
            $n['links'] = array_map(fn ($k) => ['q' => $k, 'url' => self::searchUrl($k, $n['country'])], $n['keywords']);
        }
        unset($n);

        $saved = Db::all('SELECT s.*, c.name AS client_name, c.color AS client_color, n.name AS niche_name FROM adlib_saved s
                          LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN adlib_niches n ON n.id = s.niche_id ORDER BY s.created_at DESC, s.id DESC');
        if ($clientIds !== null) {
            $saved = array_values(array_filter($saved, fn ($s) => $s['client_id'] === null || in_array((int) $s['client_id'], $clientIds, true)));
        }
        return ['niches' => $niches, 'saved' => $saved, 'countries' => self::COUNTRIES, 'formats' => self::FORMATS];
    }

    public static function saveNiche(array $b): int
    {
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Poné un nombre al nicho.');
        }
        $country = strtoupper((string) ($b['country'] ?? 'AR'));
        $data = [
            'name' => mb_substr($name, 0, 120),
            'keywords' => implode("\n", array_slice(self::keywords((string) ($b['keywords'] ?? '')), 0, 30)),
            'country' => isset(self::COUNTRIES[$country]) ? $country : 'AR',
            'notes' => isset($b['notes']) ? mb_substr((string) $b['notes'], 0, 3000) : null,
        ];
        $id = (int) ($b['id'] ?? 0);
        if ($id) {
            self::niche($id);
            Db::update('adlib_niches', $data, ['id' => $id]);
            return $id;
        }
        return Db::insert('adlib_niches', $data + ['created_at' => Db::now()]);
    }

    public static function deleteNiche(int $id): void
    {
        self::niche($id);
        Db::exec('DELETE FROM adlib_competitors WHERE niche_id = ?', [$id]);
        Db::exec('UPDATE adlib_saved SET niche_id = NULL WHERE niche_id = ?', [$id]);
        Db::exec('DELETE FROM adlib_niches WHERE id = ?', [$id]);
    }

    /** Acepta el ID de la página o cualquier link de la Biblioteca / de Facebook. */
    public static function addCompetitor(int $nicheId, string $name, string $ref): void
    {
        self::niche($nicheId);
        $name = trim($name);
        $ref = trim($ref);
        $pageId = null;
        if (preg_match('/^\d{5,25}$/', $ref)) {
            $pageId = $ref;
        } elseif (preg_match('/[?&](?:view_all_page_id|id)=(\d{5,25})/', $ref, $m)) {
            $pageId = $m[1];
        }
        $url = $ref !== '' && !$pageId ? self::safeUrl($ref) : null;
        if ($name === '' && $url) {
            $name = trim((string) preg_replace('~^https?://(www\.|m\.)?(facebook|instagram)\.com/~i', '', $url), '/');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Poné el nombre del competidor.');
        }
        Db::insert('adlib_competitors', ['niche_id' => $nicheId, 'name' => mb_substr($name, 0, 190), 'page_id' => $pageId, 'url' => $url, 'created_at' => Db::now()]);
    }

    public static function deleteCompetitor(int $id): void
    {
        Db::exec('DELETE FROM adlib_competitors WHERE id = ?', [$id]);
    }

    public static function save(array $b, ?array $clientIds = null): int
    {
        $title = trim((string) ($b['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Poné un título a la referencia.');
        }
        $nicheId = (int) ($b['niche_id'] ?? 0) ?: null;
        $clientId = (int) ($b['client_id'] ?? 0) ?: null;
        if ($nicheId) {
            self::niche($nicheId);
        }
        if ($clientId) {
            Clients::find($clientId);
            Scope::assertClient($clientId, $clientIds === null ? null : ['clients' => $clientIds]);
        }
        $format = (string) ($b['format'] ?? '');
        $image = trim((string) ($b['image_url'] ?? ''));
        $data = [
            'niche_id' => $nicheId, 'client_id' => $clientId,
            'title' => mb_substr($title, 0, 190),
            'advertiser' => mb_substr(trim((string) ($b['advertiser'] ?? '')), 0, 190) ?: null,
            'url' => self::safeUrl((string) ($b['url'] ?? '')),
            'image_url' => $image !== '' && (str_starts_with($image, \App\Config::url('uploads/')) || (str_starts_with($image, 'https://') && self::safeUrl($image))) ? mb_substr($image, 0, 500) : null,
            'format' => isset(self::FORMATS[$format]) ? $format : null,
            'hook' => mb_substr(trim((string) ($b['hook'] ?? '')), 0, 2000) ?: null,
            'notes' => mb_substr(trim((string) ($b['notes'] ?? '')), 0, 3000) ?: null,
        ];
        $id = (int) ($b['id'] ?? 0);
        if ($id) {
            if (!Db::one('SELECT id FROM adlib_saved WHERE id = ?', [$id])) {
                throw new InvalidArgumentException('Referencia inexistente.');
            }
            Db::update('adlib_saved', $data, ['id' => $id]);
            return $id;
        }
        return Db::insert('adlib_saved', $data + ['created_by' => Auth::user()['id'] ?? null, 'created_at' => Db::now()]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM adlib_saved WHERE id = ?', [$id]);
    }

    public static function searchUrl(string $q, string $country = 'AR'): string
    {
        return 'https://www.facebook.com/ads/library/?' . http_build_query([
            'active_status' => 'active', 'ad_type' => 'all', 'country' => $country, 'is_targeted_country' => 'false',
            'media_type' => 'all', 'q' => $q, 'search_type' => 'keyword_unordered',
        ]);
    }

    public static function pageUrl(?string $pageId, string $name, string $country = 'ALL'): string
    {
        return $pageId
            ? 'https://www.facebook.com/ads/library/?' . http_build_query(['active_status' => 'active', 'ad_type' => 'all', 'country' => $country, 'is_targeted_country' => 'false', 'media_type' => 'all', 'search_type' => 'page', 'view_all_page_id' => $pageId])
            : self::searchUrl($name, $country);
    }

    private static function niche(int $id): array
    {
        $n = Db::one('SELECT * FROM adlib_niches WHERE id = ?', [$id]);
        if (!$n) {
            throw new InvalidArgumentException('Nicho inexistente.');
        }
        return $n;
    }

    private static function keywords(?string $s): array
    {
        $out = [];
        foreach (preg_split('/[\n,;]+/', (string) $s) as $k) {
            $k = trim($k);
            if ($k !== '' && !in_array(mb_strtolower($k), array_map('mb_strtolower', $out), true)) {
                $out[] = mb_substr($k, 0, 80);
            }
        }
        return $out;
    }

    private static function safeUrl(string $u): ?string
    {
        $u = trim($u);
        return $u !== '' && preg_match('~^https?://~i', $u) && filter_var($u, FILTER_VALIDATE_URL) ? mb_substr($u, 0, 500) : null;
    }
}
