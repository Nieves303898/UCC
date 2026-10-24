<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use InvalidArgumentException;

/** Rango de fechas del filtro + período anterior para comparar. */
final class DateRange
{
    public const PRESETS = [
        'today'      => 'Hoy',
        'yesterday'  => 'Ayer',
        'last_7d'    => 'Últimos 7 días',
        'last_14d'   => 'Últimos 14 días',
        'last_30d'   => 'Últimos 30 días',
        'last_90d'   => 'Últimos 90 días',
        'this_month' => 'Este mes',
        'last_month' => 'Mes pasado',
    ];

    public function __construct(
        public readonly DateTimeImmutable $since,
        public readonly DateTimeImmutable $until,
        public readonly string $preset = 'custom',
    ) {
    }

    public static function fromRequest(array $q): self
    {
        $preset = (string) ($q['preset'] ?? 'last_7d');
        $today = new DateTimeImmutable('today');
        if ($preset === 'custom') {
            $since = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($q['since'] ?? ''));
            $until = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($q['until'] ?? ''));
            if (!$since || !$until || $since > $until) {
                throw new InvalidArgumentException('Rango de fechas inválido');
            }
            if ($until > $today) {
                $until = $today;
            }
            return new self($since, $until, 'custom');
        }
        return match ($preset) {
            'today'      => new self($today, $today, $preset),
            'yesterday'  => new self($today->modify('-1 day'), $today->modify('-1 day'), $preset),
            'last_14d'   => new self($today->modify('-13 days'), $today, $preset),
            'last_30d'   => new self($today->modify('-29 days'), $today, $preset),
            'last_90d'   => new self($today->modify('-89 days'), $today, $preset),
            'this_month' => new self($today->modify('first day of this month'), $today, $preset),
            'last_month' => new self($today->modify('first day of last month'), $today->modify('last day of last month'), $preset),
            default      => new self($today->modify('-6 days'), $today, 'last_7d'),
        };
    }

    public function previous(): self
    {
        $days = $this->days();
        $until = $this->since->modify('-1 day');
        return new self($until->modify('-' . ($days - 1) . ' days'), $until, 'previous');
    }

    public function days(): int
    {
        return $this->since->diff($this->until)->days + 1;
    }

    public function includesToday(): bool
    {
        return $this->until->format('Y-m-d') === date('Y-m-d');
    }

    public function timeRange(): array
    {
        return ['since' => $this->since->format('Y-m-d'), 'until' => $this->until->format('Y-m-d')];
    }

    /** TTL de caché: corto si el rango incluye hoy (datos "en vivo"). */
    public function ttl(): int
    {
        return $this->includesToday()
            ? (int) Config::get('cache.realtime_ttl', 120)
            : (int) Config::get('cache.default_ttl', 900);
    }

    public function key(): string
    {
        return $this->since->format('Ymd') . '-' . $this->until->format('Ymd');
    }

    public function toArray(): array
    {
        return $this->timeRange() + ['preset' => $this->preset, 'days' => $this->days()];
    }
}
