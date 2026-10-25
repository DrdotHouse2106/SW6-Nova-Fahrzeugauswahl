<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Pagelet\Header\HeaderPageletLoadedEvent;
use Shopware\Storefront\Pagelet\Menu\Offcanvas\MenuOffcanvasPageletLoadedEvent;
use Shopware\Storefront\Pagelet\PageletLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\CategoryVehicleFilter;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * Hauptmenü (Header) und mobiles Menü (Offcanvas): Kategorien ohne Produkte fürs gewählte Fahrzeug ausblenden.
 * Der Seiten-Cache unterscheidet bereits nach gewähltem Fahrzeug (CacheKeySubscriber).
 */
class NavigationTreeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly VehicleSwitcherConfig $config,
        private readonly VehicleSelectionStorage $selectionStorage,
        private readonly CategoryVehicleFilter $categoryFilter
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            HeaderPageletLoadedEvent::class => 'onNavigationLoaded',
            MenuOffcanvasPageletLoadedEvent::class => 'onNavigationLoaded',
        ];
    }

    public function onNavigationLoaded(PageletLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->config->isActive($salesChannelId) || !$this->config->hideEmptyCategories($salesChannelId)) {
            return;
        }

        $optionId = $this->selectionStorage->get();
        $pagelet = $event->getPagelet();

        if ($optionId === null || !method_exists($pagelet, 'getNavigation') || ($tree = $pagelet->getNavigation()) === null) {
            return;
        }

        $start = microtime(true);
        $this->categoryFilter->filterTree($tree, $this->config->getFilterOptionIds($optionId, $salesChannelId), $context);
        $stats = $this->categoryFilter->getStats();

        $pagelet->addExtension('vehicleSwitcherTiming', new ArrayStruct(['text' => \sprintf(
            'Menüfilter %d ms, %d Kategorieprüfungen, %d Datenbankabfragen (%d ms)',
            (microtime(true) - $start) * 1000,
            $stats['categories'],
            $stats['queries'],
            $stats['queryMs']
        )]));
    }
}
