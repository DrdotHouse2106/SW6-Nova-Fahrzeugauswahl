<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Pagelet\Footer\FooterPageletLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleOptionLoader;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * Fahrzeug-Beschreibung am Seitenende (Position "unten", klassischer SEO-Text-Platz).
 * Hängt den Text als Extension "vehicleSwitcherDescription" ans Footer-Pagelet.
 */
class FooterPageletSubscriber implements EventSubscriberInterface
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
            FooterPageletLoadedEvent::class => 'onFooterLoaded',
        ];
    }

    public function onFooterLoaded(FooterPageletLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->config->isActive($salesChannelId)
            || !$this->config->showActiveDescription($salesChannelId)
            || $this->config->getDescriptionPosition($salesChannelId) !== 'bottom'
        ) {
            return;
        }

        $activeId = $this->selectionStorage->get($salesChannelId);

        if ($activeId === null) {
            return;
        }

        $groupIds = $this->config->getPropertyGroupIds($salesChannelId);

        if ($groupIds === []) {
            return;
        }

        $options = $this->optionLoader->load(
            $groupIds,
            $this->config->getMaxOptions($salesChannelId),
            $this->config->getSortMode($salesChannelId),
            $this->config->getHiddenOptionIds($salesChannelId),
            $this->config->onlyWithProducts($salesChannelId) ? $salesChannelId : null,
            $event->getContext()
        );

        $option = $options->get($activeId);

        if ($option === null) {
            return;
        }

        $description = $this->optionLoader->extractDescription($option);

        if ($description === null) {
            return;
        }

        $event->getPagelet()->addExtension('vehicleSwitcherDescription', new ArrayStruct([
            'html' => $description,
            'listingOnly' => $this->config->descriptionListingOnly($salesChannelId),
            'cssClass' => $this->config->getDescriptionCssClass($salesChannelId),
        ]));
    }
}
