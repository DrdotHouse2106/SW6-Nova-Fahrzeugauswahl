<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingRouteCacheKeyEvent;
use Shopware\Core\Content\Product\Events\ProductSearchRouteCacheKeyEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestRouteCacheKeyEvent;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheKeyEvent;
use Shopware\Core\Framework\Adapter\Cache\StoreApiRouteCacheKeyEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;

/**
 * Nimmt die gewählte Fahrzeug-OptionId in den HTTP-Cache-Key auf, damit
 * gecachte Kategorie-/Suchseiten pro Fahrzeug unterschieden werden.
 * Ohne das würde bei aktivem HTTP-Cache immer die "ungefilterte" Seite
 * ausgeliefert.
 */
class CacheKeySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly VehicleSelectionStorage $selectionStorage)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            HttpCacheKeyEvent::class => 'onHttpCacheKey',
            ProductListingRouteCacheKeyEvent::class => 'onRouteCacheKey',
            ProductSearchRouteCacheKeyEvent::class => 'onRouteCacheKey',
            ProductSuggestRouteCacheKeyEvent::class => 'onRouteCacheKey',
        ];
    }

    public function onHttpCacheKey(HttpCacheKeyEvent $event): void
    {
        // Nur gültige Fahrzeug-Optionen des Verkaufskanals in den Cache-Key aufnehmen (siehe VehicleSelectionStorage)
        $optionId = $this->selectionStorage->validFromRequest($event->request);

        if ($optionId !== null) {
            $event->add('vehicle-switcher', $optionId);
        }
    }

    public function onRouteCacheKey(StoreApiRouteCacheKeyEvent $event): void
    {
        $optionId = $this->selectionStorage->validFromRequest($event->getRequest(), $event->getContext()->getSalesChannelId());

        if ($optionId !== null) {
            $event->addPart('vehicle-switcher-' . $optionId);
        }
    }
}
