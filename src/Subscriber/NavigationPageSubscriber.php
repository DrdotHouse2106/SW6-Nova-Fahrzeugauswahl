<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleOptionLoader;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * Daten für die Kategorie-Überschrift „<Kategorie> für <Fahrzeug>“ als Extension „vehicleSwitcherHeading“ an der Seite.
 * Der Header wird per ESI als eigene Unteranfrage gerendert – seine Extension ist im Seiteninhalt nicht verfügbar.
 */
class NavigationPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly VehicleSwitcherConfig $config,
        private readonly VehicleOptionLoader $optionLoader,
        private readonly VehicleSelectionStorage $selectionStorage
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded',
        ];
    }

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->config->isActive($salesChannelId) || !$this->config->showCategoryHeading($salesChannelId)) {
            return;
        }

        $vehicle = null;
        $activeId = $this->selectionStorage->get($salesChannelId);
        $groupIds = $this->config->getPropertyGroupIds($salesChannelId);

        if ($activeId !== null && $groupIds !== []) {
            $option = $this->optionLoader->load(
                $groupIds,
                $this->config->getMaxOptions($salesChannelId),
                $this->config->getSortMode($salesChannelId),
                $this->config->getHiddenOptionIds($salesChannelId),
                $this->config->onlyWithProducts($salesChannelId) ? $salesChannelId : null,
                $event->getContext()
            )->get($activeId);

            if ($option !== null) {
                $vehicle = $option->getTranslation('name') ?? $option->getName();
            }
        }

        $event->getPage()->addExtension('vehicleSwitcherHeading', new ArrayStruct([
            'vehicle' => $vehicle,
            'joiner' => $this->config->getCategoryHeadingJoiner($salesChannelId),
        ]));
    }
}
