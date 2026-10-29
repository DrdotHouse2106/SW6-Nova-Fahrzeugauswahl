<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Subscriber;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleOptionLoader;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSelectionStorage;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * Fahrzeug per Link wählen: „?fahrzeug=XM“ (Name, gekürzter Name oder ID; „alle“ hebt die Auswahl auf).
 * Setzt das Cookie und leitet auf dieselbe Adresse ohne Parameter weiter – keine doppelten Seiten für Suchmaschinen.
 * Damit lassen sich alte Modell-Seiten (Kategorie-Typ „Link“) und Startseiten-Knöpfe auf eine Teile-Kategorie
 * mit vorgewähltem Fahrzeug umleiten.
 */
class VehicleQuerySubscriber implements EventSubscriberInterface
{
    public const PARAM = 'fahrzeug';
    private const COOKIE_MAX_AGE = 60 * 60 * 24 * 30;

    public function __construct(
        private readonly VehicleSwitcherConfig $config,
        private readonly VehicleOptionLoader $optionLoader
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Nach der Auflösung des Verkaufskanal-Kontexts
        return [KernelEvents::CONTROLLER => ['onController', -20]];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->query->has(self::PARAM) || $request->getMethod() !== 'GET') {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext || !$this->config->isActive($context->getSalesChannelId())) {
            return;
        }

        // ?fahrzeug[]=… ist kein gültiger Wert (InputBag::get() würde mit HTTP 400 abbrechen)
        $raw = $request->query->all()[self::PARAM] ?? '';
        $value = \is_string($raw) ? trim($raw) : null;
        $reset = $value !== null && \in_array(VehicleSwitcherConfig::matchKey($value), ['', 'alle', 'all', 'allefahrzeuge'], true);
        $optionId = $reset || $value === null ? null : $this->resolve($value, $context);

        // Unbekannter Wert: Parameter still entfernen, Auswahl unverändert lassen
        $query = $request->query->all();
        unset($query[self::PARAM]);
        // Ursprüngliche (SEO-)Adresse, nicht die intern aufgelöste /navigation/<id>
        $original = (string) $request->attributes->get('sw-original-request-uri', '');
        $path = $original !== '' ? (string) parse_url($original, \PHP_URL_PATH) : $request->getBaseUrl() . $request->getPathInfo();
        $url = $request->getSchemeAndHttpHost() . $path . ($query !== [] ? '?' . http_build_query($query) : '');

        $response = new RedirectResponse($url, 302);
        $response->setPrivate();
        if ($reset || $optionId !== null) {
            $response->headers->setCookie(Cookie::create(
                VehicleSelectionStorage::COOKIE_NAME,
                $optionId,
                $reset ? 1 : time() + self::COOKIE_MAX_AGE,
                '/',
                null,
                $request->isSecure(),
                false,
                false,
                Cookie::SAMESITE_LAX
            ));
        }

        $event->setController(static fn (): RedirectResponse => $response);
    }

    private function resolve(string $value, SalesChannelContext $context): ?string
    {
        $salesChannelId = $context->getSalesChannelId();
        $groupIds = $this->config->getPropertyGroupIds($salesChannelId);
        if ($groupIds === []) {
            return null;
        }

        $options = $this->optionLoader->load(
            $groupIds,
            500,
            $this->config->getSortMode($salesChannelId),
            [],
            null,
            $context->getContext()
        );

        $id = strtolower($value);
        if (Uuid::isValid($id)) {
            return $options->has($id) ? $id : null;
        }

        $key = VehicleSwitcherConfig::matchKey($value);
        if ($key === '') {
            return null;
        }

        // 1. Voller Name oder Name ohne eingestellten Präfix
        $prefixes = $this->config->getStripPrefixes($salesChannelId);
        $tails = [];
        foreach ($options as $option) {
            $name = (string) ($option->getTranslation('name') ?? $option->getName());
            if ($key === VehicleSwitcherConfig::matchKey($name)
                || $key === VehicleSwitcherConfig::matchKey($this->config->applyStripPrefixes($name, $prefixes))
            ) {
                return $option->getId();
            }

            // Namensende ab einer Wortgrenze: „Citroën XM“ → „XM“, „JCB Fastrac“ → „Fastrac“
            $words = preg_split('/\s+/u', trim($name)) ?: [];
            for ($i = 1; $i < \count($words); ++$i) {
                if ($key === VehicleSwitcherConfig::matchKey(implode(' ', \array_slice($words, $i)))) {
                    $tails[$option->getId()] = true;
                }
            }
            // Namensanfang ab Wortgrenze: „JCB Fastrac“ → „JCB“
            for ($i = 1; $i < \count($words); ++$i) {
                if ($key === VehicleSwitcherConfig::matchKey(implode(' ', \array_slice($words, 0, $i)))) {
                    $tails[$option->getId()] = true;
                }
            }
        }

        // 2. Nur eindeutige Treffer
        return \count($tails) === 1 ? (string) array_key_first($tails) : null;
    }
}
