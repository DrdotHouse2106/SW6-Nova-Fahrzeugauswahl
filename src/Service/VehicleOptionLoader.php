<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Service;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Ulber\FahrzeugSchnellauswahl\Migration\Migration1788960000CreateVehicleDescriptionField;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Lädt die Fahrzeug-Optionen der konfigurierten Filtergruppen.
 * Die Reihenfolge der Gruppen entspricht der Konfigurations-Reihenfolge
 * (Filtergruppe 1, dann Filtergruppe 2) – nicht der property_group.position.
 */
class VehicleOptionLoader
{
    private bool $lastCheckEmpty = false;

    /**
     * true, wenn „Nur Fahrzeuge mit Produkten“ beim letzten Laden keinen einzigen Treffer fand
     * (dann wurde nichts ausgeblendet).
     */
    public function lastProductCheckWasEmpty(): bool
    {
        return $this->lastCheckEmpty;
    }

    public function __construct(
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly VehicleSwitcherConfig $config
    ) {
    }

    /**
     * @param list<string>                 $groupIds  Gruppen in gewünschter Anzeige-Reihenfolge
     * @param VehicleSwitcherConfig::SORT_* $sortMode
     * @param list<string>                 $hiddenIds explizit ausgeblendete Options-IDs
     * @param string|null                  $productSalesChannelId gesetzt => nur Optionen mit sichtbaren Produkten
     */
    public function load(
        array $groupIds,
        int $limit,
        string $sortMode,
        array $hiddenIds,
        ?string $productSalesChannelId,
        Context $context
    ): PropertyGroupOptionCollection {
        $this->lastCheckEmpty = false;

        if ($groupIds === []) {
            return new PropertyGroupOptionCollection();
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('groupId', $groupIds));
        $criteria->setLimit(max($limit, 1));
        $criteria->setTitle('vehicle-switcher::options');

        match ($sortMode) {
            VehicleSwitcherConfig::SORT_NAME_ASC => $criteria->addSorting(
                new FieldSorting('name', FieldSorting::ASCENDING)
            ),
            VehicleSwitcherConfig::SORT_NAME_DESC => $criteria->addSorting(
                new FieldSorting('name', FieldSorting::DESCENDING)
            ),
            default => $criteria
                ->addSorting(new FieldSorting('position', FieldSorting::ASCENDING))
                ->addSorting(new FieldSorting('name', FieldSorting::ASCENDING)),
        };

        /** @var PropertyGroupOptionCollection $found */
        $found = $this->propertyGroupOptionRepository->search($criteria, $context)->getEntities();

        // Nach Gruppen-Reihenfolge umsortieren; DAL-Sortierung bleibt je Gruppe erhalten.
        $ordered = new PropertyGroupOptionCollection();

        foreach ($groupIds as $groupId) {
            foreach ($found as $option) {
                if ($option->getGroupId() === $groupId) {
                    $ordered->add($option);
                }
            }
        }

        // Explizit ausgeblendete Optionen entfernen.
        if ($hiddenIds !== []) {
            $ordered = $ordered->filter(
                static fn ($option): bool => !\in_array($option->getId(), $hiddenIds, true)
            );
        }

        // Optional: nur Optionen behalten, denen ein sichtbares Produkt zugeordnet ist.
        if ($productSalesChannelId !== null && $ordered->count() > 0) {
            $usedIds = $this->findOptionIdsWithProducts(
                array_values($ordered->getIds()),
                $productSalesChannelId,
                $context
            );

            // Findet die Prüfung gar nichts, ist eher der Suchindex veraltet (z. B. nach Massenbearbeitung,
            // Warteschlange nicht abgearbeitet) als dass kein einziges Fahrzeug Produkte hat – dann nichts ausblenden.
            if ($usedIds === []) {
                $this->lastCheckEmpty = true;
            } else {
                $ordered = $ordered->filter(
                    static fn ($option): bool => \in_array($option->getId(), $usedIds, true)
                );
            }
        }

        return $ordered;
    }

    /**
     * HTML-Beschreibung (Custom-Field) einer Ausprägung – oder null.
     */
    public function extractDescription(PropertyGroupOptionEntity $option): ?string
    {
        $customFields = $option->getTranslation('customFields') ?? $option->getCustomFields();

        $value = \is_array($customFields)
            ? ($customFields[Migration1788960000CreateVehicleDescriptionField::CUSTOM_FIELD_NAME] ?? null)
            : null;

        if (\is_string($value) && trim(strip_tags($value)) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param list<string> $optionIds
     *
     * @return list<string>
     */
    private function findOptionIdsWithProducts(array $optionIds, string $salesChannelId, Context $context): array
    {
        if ($optionIds === []) {
            return [];
        }

        // Direkte Abfrage auf product_property statt DAL (die brauchte bei großen Katalogen 30 Sekunden),
        // zwischengespeichert wie der Menüfilter (Standard 24 Stunden). Varianten erben über product.properties.
        sort($optionIds);
        $cacheKey = 'nova-vehicle-used-options-' . md5($salesChannelId . '|' . implode(',', $optionIds));

        try {
            /** @var list<string> $ids */
            $ids = $this->cache->get($cacheKey, function (ItemInterface $item) use ($optionIds, $salesChannelId): array {
                $item->expiresAfter($this->config->getCategoryCacheHours($salesChannelId) * 3600);

                return array_values(array_map('strval', $this->connection->fetchFirstColumn(
                    'SELECT DISTINCT LOWER(HEX(pp.property_group_option_id))
                    FROM product p
                    LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
                    INNER JOIN product_visibility pv ON pv.product_id = IFNULL(p.visibilities, p.id)
                        AND pv.product_version_id = p.version_id
                        AND pv.sales_channel_id = :salesChannelId
                        AND pv.visibility >= :visibility
                    INNER JOIN product_property pp ON pp.product_id = IFNULL(p.properties, p.id)
                        AND pp.product_version_id = p.version_id
                        AND pp.property_group_option_id IN (:optionIds)
                    WHERE p.version_id = :version AND COALESCE(p.active, parent.active) = 1',
                    [
                        'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                        'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
                        'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                        'optionIds' => Uuid::fromHexToBytesList($optionIds),
                    ],
                    ['optionIds' => ArrayParameterType::BINARY]
                )));
            });
        } catch (\Throwable) {
            // Im Zweifel nichts ausblenden
            return [];
        }

        return array_values(array_filter(
            $optionIds,
            static fn (string $id): bool => \in_array($id, $ids, true)
        ));
    }
}
