<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Service;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Tree\Tree;
use Shopware\Core\Content\Category\Tree\TreeItem;
use Shopware\Core\Content\ProductStream\Service\AbstractProductStreamBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Blendet im Menü Kategorien aus, die für das gewählte Fahrzeug keine Produkte haben.
 *
 * Ausgeblendet wird nur, was überhaupt Produkte hat, aber keine fürs Fahrzeug – Inhaltsseiten ohne Produkte
 * (z. B. „Über uns“), Links und Ordner mit sichtbaren Unterkategorien bleiben stehen.
 */
class CategoryVehicleFilter
{
    /** @var array<string, bool> */
    private array $memo = [];

    /** @var array<string, array<string, true>> Kategorie-IDs mit Produkten je Fahrzeug-Auswahl */
    private array $categorySets = [];

    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly AbstractProductStreamBuilder $streamBuilder,
        private readonly CacheInterface $cache,
        private readonly Connection $connection,
        private readonly VehicleSwitcherConfig $config
    ) {
    }

    /**
     * @param list<string> $optionIds gewähltes Fahrzeug plus „Passt zu allen Fahrzeugen“
     */
    public function filterTree(Tree $tree, array $optionIds, SalesChannelContext $context): void
    {
        $tree->setTree($this->filterItems($tree->getTree(), $optionIds, $context));
    }

    /**
     * @param array<TreeItem> $items
     *
     * @return array<TreeItem>
     */
    private function filterItems(array $items, array $optionIds, SalesChannelContext $context): array
    {
        $kept = [];

        foreach ($items as $key => $item) {
            $hadChildren = $item->getChildren() !== [];
            $item->setChildren($this->filterItems($item->getChildren(), $optionIds, $context));

            if ($this->keep($item->getCategory(), $hadChildren, $item->getChildren() !== [], $optionIds, $context)) {
                $kept[$key] = $item;
            }
        }

        return $kept;
    }

    private function keep(CategoryEntity $category, bool $hadChildren, bool $hasChildren, array $optionIds, SalesChannelContext $context): bool
    {
        if ($hasChildren || $category->getType() === CategoryDefinition::TYPE_LINK) {
            return true;
        }

        if ($category->getType() === CategoryDefinition::TYPE_FOLDER) {
            // Ordner, deren Unterkategorien alle ausgeblendet wurden
            return !$hadChildren;
        }

        // Inhaltsseite ohne Produkte → nicht anfassen
        if (!$this->hasProducts($category, null, $context)) {
            return !$hadChildren;
        }

        return $this->hasProducts($category, $optionIds, $context);
    }

    private function hasProducts(CategoryEntity $category, ?array $optionIds, SalesChannelContext $context): bool
    {
        $key = $category->getId() . '-' . ($optionIds === null ? 'all' : implode(',', $optionIds));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $streamId = $category->getProductStreamId();
        if ($category->getProductAssignmentType() === CategoryDefinition::PRODUCT_ASSIGNMENT_TYPE_PRODUCT_STREAM && $streamId !== null) {
            // Dynamische Produktgruppe: einzeln prüfen (selten)
            $criteria = new Criteria();
            $criteria->setLimit(1);
            $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);
            $criteria->setTitle('vehicle-switcher::category-has-products');
            $this->streamBuilder->enrichCriteria($criteria, $streamId, $context->getContext());
            if ($optionIds !== null) {
                $criteria->addFilter(new EqualsAnyFilter('product.properties.id', $optionIds));
            }
            try {
                $result = $this->productRepository->searchIds($criteria, $context)->getIds() !== [];
            } catch (\Throwable) {
                $result = true;
            }
        } else {
            // Feste Zuordnung: EINE Sammelabfrage für alle Kategorien statt einer Abfrage je Kategorie
            $set = $this->categoriesWithProducts($optionIds, $context);
            $result = $set === null || isset($set[$category->getId()]);
        }

        return $this->memo[$key] = $result;
    }

    /**
     * Alle Kategorie-IDs (inkl. Elternkategorien über product_category_tree), die sichtbare Produkte enthalten –
     * optional nur Produkte mit einer der Fahrzeug-Ausprägungen. Ergebnis wird je Verkaufskanal und Fahrzeug
     * zwischengespeichert (Dauer einstellbar, Standard 24 Stunden).
     *
     * Direkte SQL-Abfrage statt DAL-Aggregation: Die Aggregation über categoriesRo mit vererbten Eigenschaften
     * dauerte bei großen Katalogen 30 Sekunden und mehr.
     *
     * @param list<string>|null $optionIds
     *
     * @return array<string, true>|null null = Abfrage fehlgeschlagen (im Zweifel alles anzeigen)
     */
    private function categoriesWithProducts(?array $optionIds, SalesChannelContext $context): ?array
    {
        $options = $optionIds ?? [];
        sort($options);
        $memoKey = $optionIds === null ? 'all' : implode(',', $options);
        if (\array_key_exists($memoKey, $this->categorySets)) {
            return $this->categorySets[$memoKey];
        }

        $salesChannelId = $context->getSalesChannelId();

        try {
            $ids = $this->cache->get($this->cacheKey($salesChannelId, $optionIds), function (ItemInterface $item) use ($optionIds, $salesChannelId): array {
                $item->expiresAfter($this->config->getCategoryCacheHours($salesChannelId) * 3600);

                return $this->queryCategoryIds($optionIds, $salesChannelId);
            });
        } catch (\Throwable) {
            return $this->categorySets[$memoKey] = null;
        }

        return $this->categorySets[$memoKey] = array_fill_keys($ids, true);
    }

    /**
     * Rechnet die Liste neu und legt sie in den Zwischenspeicher (geplante Aufgabe), damit kein
     * Seitenaufruf selbst rechnen muss.
     *
     * @param list<string>|null $optionIds null = alle Produkte
     */
    public function warmUp(string $salesChannelId, ?array $optionIds): void
    {
        $ids = $this->queryCategoryIds($optionIds, $salesChannelId);
        $key = $this->cacheKey($salesChannelId, $optionIds);

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($ids, $salesChannelId): array {
            $item->expiresAfter($this->config->getCategoryCacheHours($salesChannelId) * 3600);

            return $ids;
        });
    }

    /**
     * @param list<string>|null $optionIds
     */
    private function cacheKey(string $salesChannelId, ?array $optionIds): string
    {
        $options = $optionIds ?? [];
        sort($options);

        return 'nova-vehicle-categories-' . md5($salesChannelId . '|' . ($optionIds === null ? 'all' : implode(',', $options)));
    }

    /**
     * @param list<string>|null $optionIds
     *
     * @return list<string>
     */
    private function queryCategoryIds(?array $optionIds, string $salesChannelId): array
    {
        // Varianten erben Eigenschaften und Sichtbarkeiten über die Spalten product.properties / product.visibilities
        $sql = 'SELECT DISTINCT LOWER(HEX(pct.category_id))
            FROM product p
            LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
            INNER JOIN product_visibility pv ON pv.product_id = IFNULL(p.visibilities, p.id)
                AND pv.product_version_id = p.version_id
                AND pv.sales_channel_id = :salesChannelId
                AND pv.visibility >= :visibility
            INNER JOIN product_category_tree pct ON pct.product_id = p.id AND pct.product_version_id = p.version_id';

        $params = [
            'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
            'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
        ];
        $types = [];

        if ($optionIds !== null) {
            $sql .= '
            INNER JOIN product_property pp ON pp.product_id = IFNULL(p.properties, p.id)
                AND pp.product_version_id = p.version_id
                AND pp.property_group_option_id IN (:optionIds)';
            $params['optionIds'] = Uuid::fromHexToBytesList($optionIds);
            $types['optionIds'] = ArrayParameterType::BINARY;
        }

        $sql .= '
            WHERE p.version_id = :version AND COALESCE(p.active, parent.active) = 1';

        return array_values(array_map('strval', $this->connection->fetchFirstColumn($sql, $params, $types)));
    }
}
