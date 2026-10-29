<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Prüft, ob eine Fahrzeug-OptionId (aus Cookie oder Header) zu den konfigurierten Filtergruppen des
 * Verkaufskanals gehört – bevor sie in HTTP-Cache-Key, Menü-Zwischenspeicher oder Produktfilter landet.
 * Sonst würde jede zufällige UUID eine eigene Cache-Variante und eigene Zwischenspeicher-Einträge erzeugen.
 *
 * Die Liste gültiger IDs je Verkaufskanal ist klein und liegt im Objekt-Cache; Änderungen an Ausprägungen
 * machen sie sofort ungültig.
 */
class VehicleOptionValidator implements EventSubscriberInterface, ResetInterface
{
    private const CACHE_PREFIX = 'nova-vehicle-valid-options-';
    private const VERSION_KEY = 'nova-vehicle-valid-options-version';

    /** @var array<string, array<string, true>|null> je Verkaufskanal, null = Abfrage fehlgeschlagen */
    private array $memo = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly VehicleSwitcherConfig $config
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'property_group_option.written' => 'invalidate',
            'property_group_option.deleted' => 'invalidate',
        ];
    }

    public function isValid(string $optionId, string $salesChannelId): bool
    {
        if (!\array_key_exists($salesChannelId, $this->memo)) {
            $this->memo[$salesChannelId] = $this->load($salesChannelId);
        }

        $ids = $this->memo[$salesChannelId];

        // Im Zweifel (Datenbank nicht erreichbar) wie bisher nur das UUID-Format prüfen
        return $ids === null || isset($ids[$optionId]);
    }

    public function invalidate(): void
    {
        $this->memo = [];

        try {
            $this->cache->delete(self::VERSION_KEY);
        } catch (\Throwable) {
        }
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * @return array<string, true>|null
     */
    private function load(string $salesChannelId): ?array
    {
        if (!$this->config->isActive($salesChannelId)) {
            return [];
        }

        $groupIds = $this->config->getPropertyGroupIds($salesChannelId);
        if ($groupIds === []) {
            return [];
        }

        try {
            $version = $this->cache->get(self::VERSION_KEY, static fn (): string => bin2hex(random_bytes(8)));
            $key = self::CACHE_PREFIX . md5($version . '|' . implode(',', $groupIds));

            /** @var list<string> $ids */
            $ids = $this->cache->get($key, function (ItemInterface $item) use ($groupIds): array {
                $item->expiresAfter(86400);

                return array_map('strval', $this->connection->fetchFirstColumn(
                    'SELECT LOWER(HEX(id)) FROM property_group_option WHERE property_group_id IN (:groupIds)',
                    ['groupIds' => Uuid::fromHexToBytesList($groupIds)],
                    ['groupIds' => ArrayParameterType::BINARY]
                ));
            });
        } catch (\Throwable) {
            return null;
        }

        return array_fill_keys($ids, true);
    }
}
