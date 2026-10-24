<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die (Sales-Channel-spezifische) Plugin-Konfiguration aus.
 */
class VehicleSwitcherConfig
{
    private const PREFIX = 'FahrzeugSchnellauswahl.config.';

    public const SORT_POSITION = 'position';
    public const SORT_NAME_ASC = 'nameAsc';
    public const SORT_NAME_DESC = 'nameDesc';

    public const LAYOUT_BAR = 'bar';
    public const LAYOUT_STACKED = 'stacked';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function isActive(?string $salesChannelId): bool
    {
        if ($salesChannelId === null) {
            return false;
        }

        // Die Liste wird bei „Alle Verkaufskanäle“ gepflegt. Wurde das Feld beim Kanal von der Vererbung
        // getrennt (z. B. leer gespeichert), darf das die globale Freischaltung nicht aufheben.
        foreach ([null, $salesChannelId] as $scope) {
            $ids = $this->systemConfigService->get(self::PREFIX . 'activeSalesChannels', $scope);
            if (\is_array($ids) && \in_array($salesChannelId, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reihenfolge: erst Gruppe A, dann Gruppe B.
     *
     * @return list<string>
     */
    public function getPropertyGroupIds(?string $salesChannelId): array
    {
        $ids = [];

        foreach (['propertyGroupIdA', 'propertyGroupIdB'] as $key) {
            $value = $this->systemConfigService->get(self::PREFIX . $key, $salesChannelId);

            if (\is_string($value) && $value !== '') {
                $ids[] = $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Explizit ausgeblendete Ausprägungen (property_group_option-IDs).
     *
     * @return list<string>
     */
    public function getHiddenOptionIds(?string $salesChannelId): array
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'hiddenOptionIds', $salesChannelId);

        if (!\is_array($value)) {
            $value = [];
        }

        // Ausprägungen „Passt zu allen Fahrzeugen“ bekommen nie eine Kachel
        return array_values(array_unique(array_merge(
            array_filter($value, static fn ($id): bool => \is_string($id) && $id !== ''),
            $this->getUniversalOptionIds($salesChannelId)
        )));
    }

    /**
     * Ausprägungen „Passt zu allen Fahrzeugen“: deren Artikel erscheinen bei jedem gewählten Fahrzeug.
     *
     * @return list<string>
     */
    public function getUniversalOptionIds(?string $salesChannelId): array
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'universalOptionIds', $salesChannelId);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($id): bool => \is_string($id) && $id !== ''));
    }

    /**
     * Gewähltes Fahrzeug plus „Passt zu allen Fahrzeugen“ – Grundlage für alle Fahrzeug-Filter.
     *
     * @return list<string>
     */
    public function getFilterOptionIds(string $optionId, ?string $salesChannelId): array
    {
        return array_values(array_unique([$optionId, ...$this->getUniversalOptionIds($salesChannelId)]));
    }

    public function onlyWithProducts(?string $salesChannelId): bool
    {
        return (bool) $this->systemConfigService->get(self::PREFIX . 'onlyWithProducts', $salesChannelId);
    }

    public function getMaxOptions(?string $salesChannelId): int
    {
        $value = (int) $this->systemConfigService->get(self::PREFIX . 'maxOptions', $salesChannelId);

        return $value > 0 ? $value : 60;
    }

    public function getLayout(?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'layout', $salesChannelId);

        return $value === self::LAYOUT_STACKED ? self::LAYOUT_STACKED : self::LAYOUT_BAR;
    }

    public function getSortMode(?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'sortMode', $salesChannelId);

        return \in_array($value, [self::SORT_POSITION, self::SORT_NAME_ASC, self::SORT_NAME_DESC], true)
            ? $value
            : self::SORT_POSITION;
    }

    public function showAllOption(?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'showAllOption', $salesChannelId);

        // Default: anzeigen (auch wenn der Wert noch nie gesetzt wurde).
        return $value === null ? true : (bool) $value;
    }

    public function showActiveDescription(?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'showActiveDescription', $salesChannelId);

        return $value === null ? true : (bool) $value;
    }

    /**
     * @return 'top'|'bottom'
     */
    public function getDescriptionPosition(?string $salesChannelId): string
    {
        return $this->systemConfigService->get(self::PREFIX . 'descriptionPosition', $salesChannelId) === 'bottom'
            ? 'bottom'
            : 'top';
    }

    /**
     * Beschreibung nur auf Kategorie-/Such-/Startseite anzeigen?
     * (Die Routen-Prüfung selbst passiert im Template – im Pagelet ist die
     * echte Seiten-Route nicht verfügbar.)
     */
    public function descriptionListingOnly(?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'descriptionListingOnly', $salesChannelId);

        return $value === null ? true : (bool) $value;
    }

    /**
     * Eigene Beschriftung der „Alle"-Kachel. Null = Standardtext (Snippet).
     */
    public function getAllOptionLabel(?string $salesChannelId): ?string
    {
        return $this->trimToNull($this->systemConfigService->get(self::PREFIX . 'allOptionLabel', $salesChannelId));
    }

    /**
     * Überschrift auf Kategorieseiten: Kategoriename, bei aktivem Fahrzeug mit „für <Fahrzeug>“.
     */
    public function showCategoryHeading(?string $salesChannelId): bool
    {
        return (bool) $this->systemConfigService->get(self::PREFIX . 'categoryHeading', $salesChannelId);
    }

    /**
     * Menü: Kategorien ohne Produkte fürs gewählte Fahrzeug ausblenden.
     */
    public function hideEmptyCategories(?string $salesChannelId): bool
    {
        return (bool) $this->systemConfigService->get(self::PREFIX . 'hideEmptyCategories', $salesChannelId);
    }

    public function getCategoryHeadingJoiner(?string $salesChannelId): string
    {
        return $this->trimToNull($this->systemConfigService->get(self::PREFIX . 'categoryHeadingJoiner', $salesChannelId)) ?? 'für';
    }

    /**
     * Zusätzliche CSS-Klassen für den Beschreibungs-Block (z. B. „nova-fitment“ für Tabellen im Theme-Stil).
     * Nur Buchstaben, Ziffern, Binde- und Unterstrich – alles andere wird verworfen.
     */
    public function getDescriptionCssClass(?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'descriptionCssClass', $salesChannelId);

        if (!\is_string($value)) {
            return '';
        }

        $classes = array_filter(
            preg_split('/\s+/', trim($value)) ?: [],
            static fn (string $c): bool => (bool) preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $c)
        );

        return implode(' ', $classes);
    }

    /**
     * Optionale Überschrift je konfigurierter Gruppe.
     * Leerer Wert = keine Überschrift.
     *
     * @return array<string, string> groupId => Überschrift
     */
    public function getGroupLabels(?string $salesChannelId): array
    {
        $labels = [];

        $map = [
            'propertyGroupIdA' => 'groupLabelA',
            'propertyGroupIdB' => 'groupLabelB',
        ];

        foreach ($map as $groupKey => $labelKey) {
            $groupId = $this->systemConfigService->get(self::PREFIX . $groupKey, $salesChannelId);
            $label = $this->trimToNull($this->systemConfigService->get(self::PREFIX . $labelKey, $salesChannelId));

            if (\is_string($groupId) && $groupId !== '' && $label !== null) {
                $labels[$groupId] = $label;
            }
        }

        return $labels;
    }

    /**
     * Präfixe, die in der Kachel-Beschriftung entfernt werden (Anzeige only).
     * Komma-getrennt konfigurierbar, z. B. "Citroën, Citroen".
     *
     * @return list<string>
     */
    public function getStripPrefixes(?string $salesChannelId): array
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'stripLabelPrefix', $salesChannelId);

        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        $prefixes = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $prefixes[] = $part;
            }
        }

        // Längste zuerst, damit "Citroën AK" vor "Citroën" greift.
        usort($prefixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $prefixes;
    }

    /**
     * Entfernt das erste passende Präfix vom Anfang des Namens.
     *
     * @param list<string> $prefixes
     */
    public function applyStripPrefixes(string $name, array $prefixes): string
    {
        foreach ($prefixes as $prefix) {
            // Vergleich ohne Akzente/Groß-Klein: „Citroen“ und „Citroën“ (auch zerlegt eingegeben) greifen gleich
            $foldedPrefix = self::fold($prefix);
            if ($foldedPrefix !== '' && str_starts_with(self::fold($name), $foldedPrefix)) {
                $name = self::nfc($name);
                $stripped = trim(mb_substr($name, mb_strlen($foldedPrefix)));
                // Führende Trenner (–, -, :) mit entfernen.
                $stripped = ltrim($stripped, " \t-–—:•|");

                return $stripped !== '' ? $stripped : $name;
            }
        }

        return $name;
    }

    private function trimToNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function nfc(string $value): string
    {
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value) : $value;
    }

    /** Kleinbuchstaben ohne Akzente – je Zeichen genau ein Zeichen, damit die Länge zum NFC-Namen passt */
    private static function fold(string $value): string
    {
        $value = trim($value);
        if (class_exists(\Normalizer::class)) {
            $value = (string) preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value);
        }

        return mb_strtolower($value);
    }
}
