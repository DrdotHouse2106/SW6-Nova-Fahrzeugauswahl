<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Service;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Tree\Tree;
use Shopware\Core\Content\Category\Tree\TreeItem;
use Shopware\Core\Content\ProductStream\Service\AbstractProductStreamBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
    /** Wie lange die Liste „Kategorien mit Produkten“ je Fahrzeug zwischengespeichert wird (Sekunden) */
    private const CACHE_TTL = 900;

    /** @var array<string, bool> */
    private array $memo = [];

    /** @var array<string, array<string, true>> Kategorie-IDs mit Produkten je Fahrzeug-Auswahl */
    private array $categorySets = [];

    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly AbstractProductStreamBuilder $streamBuilder,
        private readonly CacheInterface $cache
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
     * Alle Kategorie-IDs (inkl. Elternkategorien über categoriesRo), die sichtbare Produkte enthalten –
     * optional nur Produkte mit einer der Fahrzeug-Ausprägungen. Ergebnis wird je Verkaufskanal,
     * Sprache und Fahrzeug kurz zwischengespeichert, damit ein kalter Cache nicht hunderte Abfragen auslöst.
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

        $cacheKey = 'nova-vehicle-categories-' . md5(implode('|', [
            $context->getSalesChannelId(),
            $context->getLanguageId(),
            $context->getCurrentCustomerGroup()->getId(),
            $memoKey,
        ]));

        try {
            $ids = $this->cache->get($cacheKey, function (ItemInterface $item) use ($optionIds, $context): array {
                $item->expiresAfter(self::CACHE_TTL);

                $criteria = new Criteria();
                $criteria->setLimit(1);
                $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);
                $criteria->setTitle('vehicle-switcher::categories-with-products');
                if ($optionIds !== null) {
                    $criteria->addFilter(new EqualsAnyFilter('product.properties.id', $optionIds));
                }
                $criteria->addAggregation(new TermsAggregation('nova-vehicle-categories', 'product.categoriesRo.id', 10000));

                $result = $this->productRepository->aggregate($criteria, $context)->get('nova-vehicle-categories');

                return $result instanceof TermsResult ? array_values(array_map('strval', $result->getKeys())) : [];
            });
        } catch (\Throwable) {
            return $this->categorySets[$memoKey] = null;
        }

        return $this->categorySets[$memoKey] = array_fill_keys($ids, true);
    }
}
