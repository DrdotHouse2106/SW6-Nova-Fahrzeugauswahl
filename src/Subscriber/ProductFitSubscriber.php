<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * „Passt zu Ihrem …“ auf der Produktseite: vergleicht das gewählte Fahrzeug (Cookie, Teil des
 * HTTP-Cache-Schlüssels) mit den Fahrzeug-Ausprägungen des Artikels. Extension „vehicleFit“ an der Seite:
 * status fits | universal | notListed, vehicle (Anzeigename), vehicles (passende Fahrzeuge des Artikels).
 * Ohne gewähltes Fahrzeug oder ohne Fahrzeugzuordnung (bei „ohne Zuordnung = universell“) kein Hinweis.
 */
class ProductFitSubscriber implements EventSubscriberInterface
{
    private const MAX_LISTED = 8;

    public function __construct(
        private readonly VehicleSwitcherConfig $config,
        private readonly VehicleSelectionStorage $selectionStorage,
        private readonly EntityRepository $optionRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPage'];
    }

    public function onProductPage(ProductPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        if (!$this->config->isActive($salesChannelId) || !$this->config->showFitBadge($salesChannelId)) {
            return;
        }

        $activeId = $this->selectionStorage->get($salesChannelId);
        $groupIds = $this->config->getPropertyGroupIds($salesChannelId);
        if ($activeId === null || $groupIds === []) {
            return;
        }

        $product = $event->getPage()->getProduct();
        $ids = array_values(array_unique([...($product->getPropertyIds() ?? []), $activeId]));

        $criteria = new Criteria($ids);
        $criteria->addFilter(new EqualsAnyFilter('groupId', $groupIds));
        $options = $this->optionRepository->search($criteria, $event->getContext())->getEntities();

        $active = $options->get($activeId);
        if (!$active instanceof PropertyGroupOptionEntity) {
            return;
        }

        $universalIds = $this->config->getUniversalOptionIds($salesChannelId);
        $productIds = array_values(array_diff(array_intersect($product->getPropertyIds() ?? [], $options->getIds()), [$activeId]));
        $fits = \in_array($activeId, $product->getPropertyIds() ?? [], true);
        $universal = array_intersect($productIds, $universalIds) !== [];

        if ($fits) {
            $status = 'fits';
        } elseif ($universal) {
            $status = 'universal';
        } elseif ($productIds === [] && $this->config->unassignedUniversalGroupIds($salesChannelId) !== []) {
            return; // ohne Fahrzeugzuordnung: gilt als universell, Hinweis wäre geraten
        } else {
            $status = 'notListed';
        }

        $prefixes = $this->config->getStripPrefixes($salesChannelId);
        $suffixes = $this->config->getStripSuffixes($salesChannelId);
        $label = fn (PropertyGroupOptionEntity $o): string => $this->config->applyStripSuffixes(
            $this->config->applyStripPrefixes((string) ($o->getTranslation('name') ?? $o->getName()), $prefixes),
            $suffixes
        );

        $vehicles = [];
        foreach (array_diff($productIds, $universalIds) as $id) {
            $vehicles[] = $label($options->get($id));
        }
        sort($vehicles, \SORT_NATURAL | \SORT_FLAG_CASE);

        $event->getPage()->addExtension('vehicleFit', new ArrayStruct([
            'status' => $status,
            'vehicle' => $label($active),
            'vehicles' => \array_slice($vehicles, 0, self::MAX_LISTED),
            'more' => max(0, \count($vehicles) - self::MAX_LISTED),
        ]));
    }
}
