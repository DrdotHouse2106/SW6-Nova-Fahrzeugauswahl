<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * Globaler Kategoriefilter: sobald ein Fahrzeug in der Session aktiv ist,
 * wird JEDE Produktauflistung (Kategorie / Suche) auf diese OptionId eingeschränkt.
 */
class ProductListingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly VehicleSwitcherConfig $config,
        private readonly VehicleSelectionStorage $selectionStorage
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCriteriaEvent::class => 'applyVehicleFilter',
            ProductSearchCriteriaEvent::class => 'applyVehicleFilter',
            ProductSuggestCriteriaEvent::class => 'applyVehicleFilter',
        ];
    }

    public function applyVehicleFilter(ProductListingCriteriaEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->config->isActive($salesChannelId)) {
            return;
        }

        $optionId = $this->selectionStorage->get($salesChannelId);

        if ($optionId === null) {
            return;
        }

        // Als "normaler" Filter (nicht Post-Filter): wirkt auf Ergebnis UND Aggregationen,
        // damit die Sidebar-Filter zur eingeschränkten Fahrzeug-Auswahl passen.
        $event->getCriteria()->addFilter(
            new EqualsAnyFilter('product.properties.id', $this->config->getFilterOptionIds($optionId, $salesChannelId))
        );
    }
}
