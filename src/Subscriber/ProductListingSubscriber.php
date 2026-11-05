<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Content\Product\Events\ProductCrossSellingIdsCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductCrossSellingStreamCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSliderStaticCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSliderStreamCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * Globaler Kategoriefilter: sobald ein Fahrzeug in der Session aktiv ist,
 * wird JEDE Produktauflistung (Kategorie / Suche) auf diese OptionId eingeschränkt – und (abschaltbar über
 * „Auch Cross-Selling und Produkt-Slider filtern“) Cross-Selling auf der Produktseite sowie Produkt-Slider
 * in Erlebniswelten.
 *
 * Schnittstelle für andere Plugins (z. B. eine Suche, die bei 0 Treffern „für andere Modelle“ anbietet):
 * - Criteria-State SKIP_STATE: Filter für diese eine Abfrage weglassen.
 * - Criteria-Erweiterung EXTENSION (`optionId`): gesetzt, wenn der Filter angewendet wurde.
 */
class ProductListingSubscriber implements EventSubscriberInterface
{
    public const SKIP_STATE = 'nova-vehicle-filter-skip';
    public const EXTENSION = 'novaVehicleFilter';

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
            ProductCrossSellingIdsCriteriaEvent::class => 'onCrossSelling',
            ProductCrossSellingStreamCriteriaEvent::class => 'onCrossSelling',
            ProductSliderStaticCriteriaEvent::class => 'onSlider',
            ProductSliderStreamCriteriaEvent::class => 'onSlider',
        ];
    }

    public function applyVehicleFilter(ProductListingCriteriaEvent $event): void
    {
        $this->apply($event->getCriteria(), $event->getSalesChannelContext());
    }

    public function onCrossSelling(ProductCrossSellingIdsCriteriaEvent|ProductCrossSellingStreamCriteriaEvent $event): void
    {
        if ($this->config->filterSliders($event->getSalesChannelContext()->getSalesChannelId())) {
            $this->apply($event->getCriteria(), $event->getSalesChannelContext());
        }
    }

    public function onSlider(ProductSliderStaticCriteriaEvent|ProductSliderStreamCriteriaEvent $event): void
    {
        if ($this->config->filterSliders($event->getSalesChannelContext()->getSalesChannelId())) {
            $this->apply($event->criteria, $event->getSalesChannelContext());
        }
    }

    private function apply(Criteria $criteria, SalesChannelContext $context): void
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->config->isActive($salesChannelId)) {
            return;
        }

        if ($criteria->hasState(self::SKIP_STATE)) {
            return;
        }

        $optionId = $this->selectionStorage->get($salesChannelId);

        if ($optionId === null) {
            return;
        }

        // Als "normaler" Filter (nicht Post-Filter): wirkt auf Ergebnis UND Aggregationen,
        // damit die Sidebar-Filter zur eingeschränkten Fahrzeug-Auswahl passen.
        $criteria->addFilter(
            new EqualsAnyFilter('product.properties.id', $this->config->getFilterOptionIds($optionId, $salesChannelId))
        );
        $criteria->addExtension(self::EXTENSION, new ArrayStruct(['optionId' => $optionId]));
    }
}
