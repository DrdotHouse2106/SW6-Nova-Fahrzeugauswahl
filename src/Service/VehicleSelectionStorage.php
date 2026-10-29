<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Service;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liest die aktuell gewählte Fahrzeug-OptionId aus dem Cookie.
 *
 * Bewusst ein Cookie (nicht die Session): Der Wert muss schon beim Aufbau des
 * HTTP-Cache-Keys verfügbar sein – die Session ist zu dem Zeitpunkt nicht
 * zuverlässig gestartet. Gesetzt wird das Cookie clientseitig im Storefront-JS.
 *
 * Sicherheit: Der Cookie-Wert ist clientseitig frei setzbar. Er wird deshalb
 * nur akzeptiert, wenn er eine gültige UUID ist. Sonst würde jeder beliebige
 * Wert eine eigene HTTP-Cache-Variante erzeugen (Cache-Flooding) und als
 * ungültige ID im DAL-Filter zu einer Exception statt zur Produktliste führen.
 * Zusätzlich muss die ID zu den Filtergruppen des Verkaufskanals gehören (VehicleOptionValidator),
 * sonst erzeugt jede zufällige UUID eigene Cache-Einträge.
 */
class VehicleSelectionStorage
{
    public const COOKIE_NAME = 'vehicle-switcher-option';

    private ?VehicleOptionValidator $validator = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function setOptionValidator(VehicleOptionValidator $validator): void
    {
        $this->validator = $validator;
    }

    public function get(?string $salesChannelId = null): ?string
    {
        $request = $this->requestStack->getMainRequest() ?? $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return null;
        }

        return $this->validFromRequest($request, $salesChannelId);
    }

    /**
     * Wie fromRequest(), aber nur IDs, die zu den Fahrzeug-Optionen des Verkaufskanals gehören.
     */
    public function validFromRequest(Request $request, ?string $salesChannelId = null): ?string
    {
        $optionId = self::fromRequest($request);
        if ($optionId === null || $this->validator === null) {
            return $optionId;
        }

        $salesChannelId ??= $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        if (!\is_string($salesChannelId) || !Uuid::isValid($salesChannelId)) {
            return $optionId;
        }

        return $this->validator->isValid($optionId, $salesChannelId) ? $optionId : null;
    }

    /**
     * Liest und validiert den Cookie-Wert eines beliebigen Requests.
     * Liefert die OptionId in Kleinbuchstaben oder null, wenn kein gültiger Wert gesetzt ist.
     */
    public static function fromRequest(Request $request): ?string
    {
        return self::sanitize($request->cookies->get(self::COOKIE_NAME));
    }

    /**
     * Akzeptiert ausschließlich gültige UUIDs (32 Hex-Zeichen, Shopware-Format).
     */
    public static function sanitize(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return Uuid::isValid($value) ? $value : null;
    }
}
