<?php
declare(strict_types=1);

namespace App\Services;

/** Normaliza filas de Ads Insights a métricas de negocio claras. */
final class AdsInsights
{
    public const FIELDS = 'spend,impressions,reach,frequency,clicks,inline_link_clicks,ctr,cpc,cpm,'
        . 'inline_link_click_ctr,actions,action_values,purchase_roas';

    private const ACTION_GROUPS = [
        'purchases'          => ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'],
        'leads'              => ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead', 'leadgen_grouped'],
        'messages'           => ['onsite_conversion.messaging_conversation_started_7d', 'onsite_conversion.total_messaging_connection'],
        'add_to_cart'        => ['omni_add_to_cart', 'add_to_cart', 'offsite_conversion.fb_pixel_add_to_cart'],
        'initiate_checkout'  => ['omni_initiated_checkout', 'initiate_checkout', 'offsite_conversion.fb_pixel_initiate_checkout'],
        'landing_page_views' => ['omni_landing_page_view', 'landing_page_view'],
        'video_views'        => ['video_view'],
        'post_engagement'    => ['post_engagement'],
        'app_installs'       => ['omni_app_install', 'mobile_app_install', 'app_install'],
        'registrations'      => ['omni_complete_registration', 'complete_registration', 'offsite_conversion.fb_pixel_complete_registration'],
    ];

    public const RESULT_LABELS = [
        'purchases' => 'Compras', 'leads' => 'Leads', 'messages' => 'Conversaciones',
        'link_clicks' => 'Clics en enlace', 'reach' => 'Alcance', 'video_views' => 'Reproducciones',
        'post_engagement' => 'Interacciones', 'app_installs' => 'Instalaciones', 'landing_page_views' => 'Visitas a landing',
        'registrations' => 'Registros',
    ];

    public static function empty(): array
    {
        return self::normalize([]);
    }

    public static function normalize(array $row, ?string $objective = null): array
    {
        $num = fn (string $k) => isset($row[$k]) ? (float) $row[$k] : 0.0;
        $m = [
            'spend'       => $num('spend'),
            'impressions' => (int) $num('impressions'),
            'reach'       => (int) $num('reach'),
            'frequency'   => $num('frequency'),
            'clicks'      => (int) $num('clicks'),
            'link_clicks' => (int) $num('inline_link_clicks'),
        ];
        $actions = self::actionMap($row['actions'] ?? []);
        $values = self::actionMap($row['action_values'] ?? []);
        foreach (self::ACTION_GROUPS as $key => $types) {
            $m[$key] = (int) self::pick($actions, $types);
        }
        $m['purchase_value'] = self::pick($values, self::ACTION_GROUPS['purchases']);
        if (!$m['link_clicks']) {
            $m['link_clicks'] = (int) ($actions['link_click'] ?? 0);
        }
        $m = self::derive($m);

        [$resultKey] = self::resultFor($objective ?? ($row['objective'] ?? null), $m);
        $m['result_key'] = $resultKey;
        $m['result_label'] = self::RESULT_LABELS[$resultKey] ?? 'Resultados';
        $m['results'] = (int) ($m[$resultKey] ?? 0);
        $m['cost_per_result'] = $m['results'] > 0 ? $m['spend'] / $m['results'] : null;
        $m['cost_per_result_tax'] = $m['results'] > 0 ? $m['spend_tax'] / $m['results'] : null;
        return $m;
    }

    /** Recalcula los ratios (sirve también después de sumar filas). */
    public static function derive(array $m): array
    {
        $div = fn ($a, $b) => $b > 0 ? $a / $b : null;
        // Gasto real con impuestos (Argentina: IVA + percepciones, configurable).
        $m['spend_tax'] = $m['spend'] * (1 + \App\Settings::taxRate());
        $m['ctr'] = $div($m['clicks'] * 100, $m['impressions']);
        $m['link_ctr'] = $div($m['link_clicks'] * 100, $m['impressions']);
        $m['cpc'] = $div($m['spend'], $m['link_clicks']);
        $m['cpm'] = $div($m['spend'] * 1000, $m['impressions']);
        $m['frequency'] = $div($m['impressions'], $m['reach']) ?? 0;
        $m['roas'] = $div($m['purchase_value'], $m['spend']);
        $m['cost_per_purchase'] = $div($m['spend'], $m['purchases']);
        $m['cost_per_lead'] = $div($m['spend'], $m['leads']);
        $m['cost_per_message'] = $div($m['spend'], $m['messages']);
        $m['conversion_rate'] = $div($m['purchases'] * 100, $m['link_clicks']);
        return $m;
    }

    /** Suma varias filas normalizadas (el alcance se suma: es aproximado entre objetos). */
    public static function sum(array $rows): array
    {
        $keys = ['spend', 'impressions', 'reach', 'clicks', 'link_clicks', 'purchase_value', ...array_keys(self::ACTION_GROUPS)];
        $total = array_fill_keys($keys, 0);
        foreach ($rows as $r) {
            foreach ($keys as $k) {
                $total[$k] += $r[$k] ?? 0;
            }
        }
        $total = self::derive($total);
        $total['results'] = array_sum(array_map(fn ($r) => $r['results'] ?? 0, $rows));
        $total['cost_per_result'] = $total['results'] > 0 ? $total['spend'] / $total['results'] : null;
        $total['cost_per_result_tax'] = $total['results'] > 0 ? $total['spend_tax'] / $total['results'] : null;
        return $total;
    }

    /** Qué cuenta como "resultado" según el objetivo de la campaña. */
    public static function resultFor(?string $objective, array $m): array
    {
        $first = function (array $keys) use ($m) {
            foreach ($keys as $k) {
                if (($m[$k] ?? 0) > 0) {
                    return $k;
                }
            }
            return $keys[0];
        };
        $key = match ($objective) {
            'OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES' => $first(['purchases', 'messages', 'leads', 'initiate_checkout', 'add_to_cart']),
            'OUTCOME_LEADS', 'LEAD_GENERATION' => $first(['leads', 'messages', 'registrations']),
            'MESSAGES' => 'messages',
            'OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT', 'PAGE_LIKES', 'EVENT_RESPONSES' => $first(['messages', 'post_engagement']),
            'OUTCOME_TRAFFIC', 'LINK_CLICKS' => 'link_clicks',
            'OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS' => 'reach',
            'VIDEO_VIEWS' => 'video_views',
            'OUTCOME_APP_PROMOTION', 'APP_INSTALLS' => 'app_installs',
            default => $first(['purchases', 'leads', 'messages', 'link_clicks']),
        };
        return [$key];
    }

    private static function actionMap(array $list): array
    {
        $map = [];
        foreach ($list as $a) {
            if (isset($a['action_type'])) {
                $map[$a['action_type']] = (float) ($a['value'] ?? 0);
            }
        }
        return $map;
    }

    /** Toma el primer tipo de acción presente (evita contar dos veces la misma compra). */
    private static function pick(array $map, array $types): float
    {
        foreach ($types as $t) {
            if (isset($map[$t])) {
                return $map[$t];
            }
        }
        return 0.0;
    }
}
