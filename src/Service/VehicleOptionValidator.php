<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
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
 *
 * Für den HTTP-Cache-Schlüssel gibt es zusätzlich eine kanalübergreifende Liste (isKnown): Shopware bildet
 * diesen Schlüssel, bevor der Verkaufskanal des Requests bekannt ist. Sie enthält die Ausprägungen aller
 * Filtergruppen, die in irgendeinem Verkaufskanal eingestellt sind, und hängt nicht von Nutzereingaben ab.
 * isValid() verlangt beide Listen, damit eine im Kanal akzeptierte ID nie im HTTP-Cache-Schlüssel fehlt
 * (sonst landete eine gefilterte Seite unter dem ungefilterten Schlüssel).
 */
class VehicleOptionValidator implements EventSubscriberInterface, ResetInterface
{
    private const CACHE_PREFIX = 'nova-vehicle-valid-options-';
    private const VERSION_KEY = 'nova-vehicle-valid-options-version';
    private const ALL_CACHE_PREFIX = 'nova-vehicle-known-options-';
    private const CONFIG_PREFIX = 'FahrzeugSchnellauswahl.config.';
    private const GROUP_KEYS = ['propertyGroupIdA', 'propertyGroupIdB'];

    /** @var array<string, array<string, true>|null> je Verkaufskanal, null = Abfrage fehlgeschlagen */
    private array $memo = [];

    /** @var array<string, true>|null|false false = noch nicht geladen, null = Abfrage fehlgeschlagen */
    private array|null|false $known = false;

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
            SystemConfigChangedEvent::class => 'onConfigChanged',
        ];
    }

    public function isValid(string $optionId, string $salesChannelId): bool
    {
        if (!$this->isKnown($optionId)) {
            return false;
        }

        if (!\array_key_exists($salesChannelId, $this->memo)) {
            $this->memo[$salesChannelId] = $this->load($salesChannelId);
        }

        $ids = $this->memo[$salesChannelId];

        // Im Zweifel (Datenbank nicht erreichbar) wie bisher nur das UUID-Format prüfen
        return $ids === null || isset($ids[$optionId]);
    }

    /**
     * Gehört die ID zu einer Filtergruppe, die in irgendeinem Verkaufskanal eingestellt ist?
     * Für Stellen, an denen der Verkaufskanal noch nicht feststeht (HTTP-Cache-Schlüssel).
     */
    public function isKnown(string $optionId): bool
    {
        if ($this->known === false) {
            $this->known = $this->loadKnown();
        }

        // Im Zweifel (Cache/Datenbank nicht erreichbar) wie bisher nur das UUID-Format prüfen
        return $this->known === null || isset($this->known[$optionId]);
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        foreach (self::GROUP_KEYS as $key) {
            if ($event->getKey() === self::CONFIG_PREFIX . $key) {
                $this->invalidate();

                return;
            }
        }
    }

    public function invalidate(): void
    {
        $this->memo = [];
        $this->known = false;

        try {
            $this->cache->delete(self::VERSION_KEY);
        } catch (\Throwable) {
        }
    }

    public function reset(): void
    {
        $this->memo = [];
        $this->known = false;
    }

    /**
     * Ausprägungen aller in irgendeinem Verkaufskanal (oder global) eingestellten Filtergruppen.
     * Der Cache-Schlüssel hängt nur an der Versionsmarke – neue Marke bei geänderten Ausprägungen oder Filtergruppen.
     *
     * @return array<string, true>|null
     */
    private function loadKnown(): ?array
    {
        try {
            $version = $this->cache->get(self::VERSION_KEY, static fn (): string => bin2hex(random_bytes(8)));

            /** @var list<string> $ids */
            $ids = $this->cache->get(self::ALL_CACHE_PREFIX . md5($version), function (ItemInterface $item): array {
                $item->expiresAfter(86400);

                $groupIds = [];
                $values = $this->connection->fetchFirstColumn(
                    'SELECT configuration_value FROM system_config WHERE configuration_key IN (:keys)',
                    ['keys' => array_map(static fn (string $key): string => self::CONFIG_PREFIX . $key, self::GROUP_KEYS)],
                    ['keys' => ArrayParameterType::STRING]
                );
                foreach ($values as $value) {
                    $decoded = json_decode((string) $value, true);
                    $groupId = \is_array($decoded) ? ($decoded['_value'] ?? null) : null;
                    if (\is_string($groupId) && Uuid::isValid($groupId)) {
                        $groupIds[$groupId] = true;
                    }
                }

                if ($groupIds === []) {
                    return [];
                }

                return array_map('strval', $this->connection->fetchFirstColumn(
                    'SELECT LOWER(HEX(id)) FROM property_group_option WHERE property_group_id IN (:groupIds)',
                    ['groupIds' => Uuid::fromHexToBytesList(array_keys($groupIds))],
                    ['groupIds' => ArrayParameterType::BINARY]
                ));
            });
        } catch (\Throwable) {
            return null;
        }

        return array_fill_keys($ids, true);
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
