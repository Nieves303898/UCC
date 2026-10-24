<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Meta\GraphClient;
use App\Meta\GraphException;

/**
 * Meta deprecia métricas de insights seguido (ej. "impressions" → "views").
 * Este helper prueba la lista pedida y, si alguna falla, se queda sólo con
 * las válidas y lo recuerda por 7 días. Así el panel no se rompe.
 */
final class Metrics
{
    public static function valid(GraphClient $client, string $kind, string $path, array $metrics, array $params): array
    {
        return Cache::remember('metrics:' . $kind . ':' . implode(',', $metrics), 86400 * 7, function () use ($client, $path, $metrics, $params) {
            try {
                $client->get($path, $params + ['metric' => implode(',', $metrics)]);
                return $metrics;
            } catch (GraphException $e) {
                if (!$e->isInvalidParam()) {
                    throw $e;
                }
            }
            $valid = [];
            foreach ($metrics as $m) {
                try {
                    $client->get($path, $params + ['metric' => $m]);
                    $valid[] = $m;
                } catch (GraphException $e) {
                    if (!$e->isInvalidParam()) {
                        throw $e;
                    }
                }
            }
            return $valid;
        });
    }

    /** Convierte la respuesta de /insights en ['metric' => valor total]. */
    public static function totals(array $response): array
    {
        $out = [];
        foreach ($response['data'] ?? [] as $row) {
            if (isset($row['total_value'])) {
                $out[$row['name']] = $row['total_value']['value'] ?? 0;
                continue;
            }
            $values = $row['values'] ?? [];
            $last = end($values);
            $value = $last['value'] ?? 0;
            if (is_array($value)) {
                $value = array_sum(array_filter($value, 'is_numeric'));
            }
            $out[$row['name']] = $value;
        }
        return $out;
    }

    /** Convierte /insights con period=day en series ['metric' => ['YYYY-MM-DD' => valor]]. */
    public static function series(array $response): array
    {
        $out = [];
        foreach ($response['data'] ?? [] as $row) {
            foreach ($row['values'] ?? [] as $v) {
                $date = substr((string) ($v['end_time'] ?? ''), 0, 10);
                $value = $v['value'] ?? 0;
                if (is_array($value)) {
                    $value = array_sum(array_filter($value, 'is_numeric'));
                }
                // end_time es el fin del día: el dato corresponde al día anterior.
                $day = date('Y-m-d', strtotime($date . ' -1 day'));
                $out[$row['name']][$day] = ($out[$row['name']][$day] ?? 0) + $value;
            }
        }
        return $out;
    }
}
