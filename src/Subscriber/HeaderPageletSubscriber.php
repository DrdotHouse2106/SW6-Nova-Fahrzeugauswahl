<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Pagelet\Header\HeaderPageletLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleOptionLoader;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;
use Ulber\FahrzeugSchnellauswahl\Struct\VehicleSwitcherStruct;

/**
 * Hängt die Fahrzeug-Optionen + aktive Auswahl als Extension an das Header-Pagelet.
 * Läuft auf jeder Seite (Header ist global) -> Switcher ist global sichtbar.
 */
class HeaderPageletSubscriber implements EventSubscriberInterface
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
            HeaderPageletLoadedEvent::class => 'onHeaderLoaded',
        ];
    }

    public function onHeaderLoaded(HeaderPageletLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->config->isActive($salesChannelId)) {
            $this->status($event, 'nicht aktiv – Verkaufskanal fehlt in „In diesen Verkaufskanälen aktiv“');

            return;
        }

        $groupIds = $this->config->getPropertyGroupIds($salesChannelId);

        if ($groupIds === []) {
            $this->status($event, 'keine Filtergruppe (Eigenschaft) für diesen Verkaufskanal eingestellt');

            return;
        }

        $start = microtime(true);
        $options = $this->optionLoader->load(
            $groupIds,
            $this->config->getMaxOptions($salesChannelId),
            $this->config->getSortMode($salesChannelId),
            $this->config->getHiddenOptionIds($salesChannelId),
            $this->config->onlyWithProducts($salesChannelId) ? $salesChannelId : null,
            $event->getContext()
        );

        $event->getPagelet()->addExtension('vehicleSwitcherOptionsTiming', new ArrayStruct([
            'text' => \sprintf('Fahrzeugliste %d ms', (microtime(true) - $start) * 1000),
        ]));

        if ($options->count() === 0) {
            $this->status($event, 'keine anzeigbaren Ausprägungen (Gruppe leer, alle ausgeblendet oder ohne sichtbare Produkte im Verkaufskanal)');

            return;
        }

        $activeId = $this->selectionStorage->get();

        // Aktive Option muss zu den aktuell sichtbaren Optionen passen,
        // sonst kann der Kunde sie nie wieder abwählen.
        if ($activeId !== null && !$options->has($activeId)) {
            $activeId = null;
        }

        // Gekürzte Beschriftungen für die Kacheln (Präfix wie "Citroën" raus).
        $stripPrefixes = $this->config->getStripPrefixes($salesChannelId);
        $displayLabels = [];

        if ($stripPrefixes !== []) {
            foreach ($options as $option) {
                $name = $option->getTranslation('name') ?? $option->getName() ?? '';
                $short = $this->config->applyStripPrefixes($name, $stripPrefixes);

                if ($short !== $name) {
                    $displayLabels[$option->getId()] = $short;
                }
            }
        }

        // Beschreibung/Spezifikation des aktiven Fahrzeugs (Custom-Field) – nur bei Position "oben".
        $activeDescription = null;

        if (
            $activeId !== null
            && $this->config->showActiveDescription($salesChannelId)
            && $this->config->getDescriptionPosition($salesChannelId) === 'top'
        ) {
            $activeOption = $options->get($activeId);
            $activeDescription = $activeOption !== null
                ? $this->optionLoader->extractDescription($activeOption)
                : null;
        }

        $activeOptionName = null;
        if ($activeId !== null && ($activeOption = $options->get($activeId)) !== null) {
            $activeOptionName = $activeOption->getTranslation('name') ?? $activeOption->getName();
        }

        $this->status($event, 'aktiv, ' . $options->count() . ' Fahrzeuge'
            . ($this->optionLoader->lastProductCheckWasEmpty()
                ? ' (Prüfung „nur Fahrzeuge mit Produkten“ fand keine Zuordnung – Suchindex prüfen, nichts ausgeblendet)'
                : ''));

        $event->getPagelet()->addExtension(
            'vehicleSwitcher',
            new VehicleSwitcherStruct(
                $options,
                $activeId,
                $this->config->showAllOption($salesChannelId),
                $this->config->getAllOptionLabel($salesChannelId),
                $this->config->getGroupLabels($salesChannelId),
                $groupIds,
                $this->config->getLayout($salesChannelId),
                $displayLabels,
                $activeDescription,
                $this->config->descriptionListingOnly($salesChannelId),
                $activeOptionName,
                $this->config->showCategoryHeading($salesChannelId),
                $this->config->getCategoryHeadingJoiner($salesChannelId),
                $this->config->getDescriptionCssClass($salesChannelId)
            )
        );

        // Filtergruppe 2 hinter „Weitere Fahrzeuge“ – offen, wenn das gewählte Fahrzeug dort liegt
        $collapsedGroupId = $this->config->getCollapsedGroupId($salesChannelId);
        if ($collapsedGroupId !== null && \in_array($collapsedGroupId, $groupIds, true)) {
            $activeOption = $activeId !== null ? $options->get($activeId) : null;
            $event->getPagelet()->addExtension('vehicleSwitcherMore', new ArrayStruct([
                'groupId' => $collapsedGroupId,
                'label' => $this->config->getCollapseLabel($salesChannelId),
                'open' => $activeOption !== null && $activeOption->getGroupId() === $collapsedGroupId,
            ]));
        }
    }

    /**
     * Status als HTML-Kommentar im Header – zeigt ohne Admin-Zugang, warum keine Kachel-Leiste erscheint.
     */
    private function status(HeaderPageletLoadedEvent $event, string $text): void
    {
        $event->getPagelet()->addExtension('vehicleSwitcherStatus', new ArrayStruct(['text' => $text]));
    }
}
