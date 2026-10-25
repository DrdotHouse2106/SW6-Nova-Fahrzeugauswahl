<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Füllt stündlich den Zwischenspeicher „Kategorien mit Produkten“ für alle Fahrzeuge vorab.
 */
class WarmCategoryCacheTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nova_fahrzeugauswahl.warm_category_cache';
    }

    public static function getDefaultInterval(): int
    {
        return 3600;
    }
}
