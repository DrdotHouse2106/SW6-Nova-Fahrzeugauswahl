# Fahrzeug-Schnellauswahl (Horizontal Vehicle Switcher)

[![Spendier mir einen Kaffee](https://img.shields.io/badge/☕_Spendier_mir_einen_Kaffee-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

Schlankes Shopware 6.6+ Plugin für eine globale, horizontale Fahrzeug-Schnellauswahl
im Storefront-Header. Kein Dropdown – Fahrzeuge werden als Kacheln / Pills nebeneinander
angezeigt. Single-Select per Klick, global über alle Kategorien.

> English version: [README.en.md](README.en.md)

## Funktionsweise

| Schritt | Was passiert |
|--------|--------------|
| Datenbasis | Ganz normale Shopware-**Eigenschaften** (Properties). Im Admin werden eine oder zwei „Filtergruppen" (= je eine Eigenschaftsgruppe) ausgewählt. Wie diese befüllt werden – manuell, per Import oder aus einem ERP – ist dem Plugin egal. |
| Anzeige | `HeaderPageletSubscriber` hängt die Optionen der Gruppen als Extension `vehicleSwitcher` an das Header-Pagelet. Twig rendert die Pill-Leiste unter dem Header. |
| Auswahl | JS-Plugin `VehicleSwitcher`: Klick → genau ein Fahrzeug aktiv (kein Stacking). Klick auf das aktive Fahrzeug oder auf „Alle Fahrzeuge" → Auswahl aufgehoben. Der Wert wird in ein **Cookie** (`vehicle-switcher-option`) geschrieben und in `localStorage` gespiegelt, dann ein Reload. Kein AJAX/Route – das Cookie ist beim nächsten Request sofort da. |
| Globaler Filter | `ProductListingSubscriber` hört auf `ProductListingCriteriaEvent` / `ProductSearchCriteriaEvent` / `ProductSuggestCriteriaEvent`, liest das Cookie und fügt bei aktivem Fahrzeug `EqualsFilter('product.properties.id', <optionId>)` zum `Criteria` hinzu – für **jede** Kategorie / Suche. |
| HTTP-Cache | `CacheKeySubscriber` nimmt die OptionId in den HTTP-Cache-Key auf (`HttpCacheKeyEvent` + `Product*RouteCacheKeyEvent`), damit gecachte Kategorie-/Suchseiten pro Fahrzeug unterschieden werden. |
| Multi-Shop | Feld **„In diesen Verkaufskanälen aktiv"** – eine Mehrfachauswahl. Leiste **und** Filter greifen nur in den gewählten Kanälen. Kein Vererbungs-Gefummel pro Kanal. |

## Verzeichnisstruktur

```
.
├── composer.json
└── src/
    ├── FahrzeugSchnellauswahl.php
    ├── Subscriber/
    │   ├── HeaderPageletSubscriber.php          # Optionen -> Template
    │   ├── ProductListingSubscriber.php         # globaler Criteria-Filter
    │   └── CacheKeySubscriber.php               # OptionId -> HTTP-Cache-Key
    ├── Service/
    │   ├── VehicleSwitcherConfig.php            # SystemConfig (pro SalesChannel)
    │   ├── VehicleSelectionStorage.php          # Cookie lesen (Single-Select)
    │   └── VehicleOptionLoader.php              # property_group_option laden
    ├── Struct/
    │   └── VehicleSwitcherStruct.php
    ├── Migration/
    │   └── …CreateVehicleDescriptionField.php   # Custom-Field an property_group_option
    └── Resources/
        ├── config/
        │   ├── config.xml                       # Admin-Konfiguration
        │   └── services.xml
        ├── snippet/
        │   ├── de_DE/messages.de-DE.json
        │   └── en_GB/messages.en-GB.json        # nur technischer Fallback
        ├── views/storefront/
        │   ├── layout/header/header.html.twig
        │   └── component/vehicle-switcher/vehicle-switcher.html.twig
        └── app/storefront/
            ├── src/
            │   ├── main.js
            │   ├── plugin/vehicle-switcher/vehicle-switcher.plugin.js
            │   └── scss/base.scss
            └── dist/…/fahrzeug-schnellauswahl.js   # kompiliert, mitgeliefert
```

## Installation

```bash
# Plugin nach custom/plugins/FahrzeugSchnellauswahl legen (Ordnername = Plugin-Klassenname)
bin/console plugin:refresh
bin/console plugin:install --activate FahrzeugSchnellauswahl
bin/console cache:clear

# Storefront-Assets bauen
bin/build-storefront.sh          # oder: bin/console theme:compile
```

## Konfiguration (Admin)

Einstellungen → System → Plugins → *Fahrzeug-Schnellauswahl* → *Konfiguration*

1. Bei **„Alle Verkaufskanäle"** (oben) bleiben.
2. Feld **„In diesen Verkaufskanälen aktiv"**: die Verkaufskanäle wählen, in denen die Schnellauswahl erscheinen soll. Leer = nirgends aktiv.
3. Karte **Filtergruppen**: „Filtergruppe 1 – Eigenschaft" wählen, optional „Filtergruppe 2".
4. Speichern.

Die **Beschriftungs- und Darstellungs-Einstellungen** kannst du zusätzlich pro Verkaufskanal überschreiben (oben den Kanal wählen, am Feld die Vererbung lösen). Für Text- und Auswahlfelder funktioniert das problemlos.

### Ausblenden

| Feld | Wirkung |
|---|---|
| **Einzelne Fahrzeuge ausblenden** | Mehrfachauswahl von Ausprägungen, die **nicht** in der Leiste erscheinen (z. B. noch nicht gepflegt). |
| **Nur Fahrzeuge mit Produkten anzeigen** | Blendet automatisch alle Ausprägungen aus, denen im Verkaufskanal kein sichtbares Produkt zugeordnet ist. Kostet pro Seitenaufruf eine zusätzliche (indizierte) Abfrage – mit aktivem HTTP-Cache vernachlässigbar. |

### Filtergruppen

| Feld | Wirkung |
|---|---|
| **Filtergruppe 1 / 2 – Eigenschaft** | Die Shopware-Eigenschaft, deren Optionen als Kacheln erscheinen. **Filtergruppe 1 wird zuerst angezeigt, dann Filtergruppe 2** – unabhängig von der Sortierung der Eigenschaftsgruppen in Shopware. Für „zuerst die A-Typen" also die A-Typ-Eigenschaft in Filtergruppe 1 legen. |
| **Filtergruppe 1 / 2 – Überschrift** | Kleiner Text direkt vor den Kacheln dieser Gruppe. **Leer = keine Überschrift** (Standard – es stehen dann nur die Fahrzeugtypen da). Das Feld liegt direkt unter der jeweiligen Gruppe. |

### Darstellung

| Einstellung | Wirkung |
|---|---|
| **Anzeige** | `Alles in einem Balken` (Standard): eine Reihe, erst Gruppe 1, dann Gruppe 2. `Filtergruppen untereinander`: jede Gruppe in einer eigenen Zeile, „Alle"-Kachel in einer Zeile darüber. |
| **Sortierung der Fahrzeuge** | Innerhalb einer Gruppe: `Manuelle Reihenfolge` (Standard) = Drag-&-Drop-Reihenfolge aus der Eigenschaftsgruppe. Alternativ `A–Z` / `Z–A`. |
| **Maximale Anzahl Kacheln** | Obergrenze, falls eine Gruppe sehr viele Optionen hat. |
| **Präfix in den Kacheln ausblenden** | Text, der am Anfang jeder Kachel-Beschriftung entfernt wird – z. B. `Citroën`, sodass aus „Citroën 2CV6" nur „2CV6" wird. **Nur die Anzeige** – die Eigenschaft selbst (Filter, SEO, Produktdetails) bleibt voll erhalten, der Tooltip zeigt weiter den kompletten Namen. Mehrere Präfixe mit Komma. Kombiniere es mit der Gruppen-Überschrift „Citroën" → `Citroën: [2CV6] [2CV4] …`. |
| **Wortende in den Kacheln ausblenden** | z. B. „Umbau“ – aus „Burton Umbau“ wird „Burton“. Nur ganze Wörter, nur die Anzeige. |
| **„Alle"-Kachel anzeigen** | Kachel zum Aufheben des Filters. Aus = keine Reset-Kachel (Filter lässt sich weiter durch Klick auf die aktive Kachel aufheben). |
| **Fahrzeug-Beschreibung anzeigen** | Zeigt einen HTML-Block mit der Spezifikation des aktiven Fahrzeugs (Hubraum, Baujahre, Modellvarianten …). Gepflegt wird der Text **pro Ausprägung** im Custom-Field *„Fahrzeug-Beschreibung"* (Kataloge → Eigenschaften → Ausprägung öffnen → Reiter *Zusatzfelder*) – oder per ERP-Sync auf das Feld `vehicle_switcher_description`. |
| **Position der Fahrzeug-Beschreibung** | `Oben` (direkt unter der Kachel-Leiste) oder `Unten` (Seitenende über dem Footer – klassischer SEO-Text-Platz, stört die Produktliste nicht). |
| **Nur auf Kategorie-/Suchseiten** | Empfohlen (Default an): der Text erscheint dann nicht auf jeder Produkt-/Inhaltsseite → weniger doppelter Boilerplate-Content. |
| **CSS-Klasse der Fahrzeug-Beschreibung** | Zusätzliche Klasse für den Beschreibungs-Block, z. B. `nova-fitment` – dann erscheinen Tabellen (Passtabellen „welches Teil passt“) im Stil des Nova Themes. |
| **Kategorie-Überschrift anzeigen** | Zeigt auf Kategorieseiten den Kategorienamen als Überschrift, bei gewähltem Fahrzeug ergänzt: „Federkugeln für Citroën XM“. Nur einschalten, wenn die Erlebniswelt keine eigene Überschrift hat. |
| **Verbindungswort in der Überschrift** | Standard „für“. |
| **Ausprägung „Passt zu allen Fahrzeugen“** | Z. B. „Alle Fahrzeuge“ in der Fahrzeug-Eigenschaft anlegen und an Öl, Werkzeug, Literatur und Universalteile vergeben. Diese Artikel erscheinen bei jedem gewählten Fahrzeug; die Ausprägung bekommt keine Kachel. Artikel ganz ohne Fahrzeug-Ausprägung verschwinden bei gewähltem Fahrzeug. |
| **Fahrzeug per Link** | Jede Shop-Adresse mit `?fahrzeug=XM` (Name, gekürzter Name oder ID; `alle` = zurücksetzen) wählt das Fahrzeug und leitet auf dieselbe Adresse ohne Parameter weiter. |
| **Filtergruppe 2 einklappen** | Zweite Fahrzeuggruppe (z. B. DS/ID/HY/Traction) hinter einem Aufklapper „Weitere Fahrzeuge“ statt dauerhaft in der Leiste. Beschriftung frei wählbar. |
| **Leere Kategorien im Menü ausblenden** | Bei gewähltem Fahrzeug zeigen Haupt- und mobiles Menü nur Kategorien, die für dieses Fahrzeug Produkte haben (auch mit dynamischen Produktgruppen). Inhaltsseiten ohne Produkte, Links und Ordner bleiben stehen. |
| **Menü-Prüfung zwischenspeichern (Stunden)** | Wie lange gespeichert bleibt, welche Kategorien je Fahrzeug Produkte haben. Standard 24, wird zusätzlich stündlich vorberechnet. |
| **Beschriftung der „Alle"-Kachel** | Freier Text, z. B. `Alle` oder `Zurücksetzen`. Leer = Standardtext. |

Die sichtbaren Kacheltexte selbst sind die **Namen der Eigenschafts-Optionen** – die änderst du direkt in Shopware unter *Kataloge → Eigenschaften* (bzw. mehrsprachig je Übersetzung).

## Eigenschaften befüllen

Das Plugin liest nur `property_group` / `property_group_option` und die Zuordnung
`product.properties`. Wer die Werte pflegt, ist offen:

- von Hand im Admin (Kataloge → Eigenschaften, dann am Produkt zuweisen),
- per Produkt-Import (CSV / API),
- aus einem ERP (z. B. ERPNext-Multiselect-Feld → Shopware-Eigenschaft) über den
  bestehenden Produkt-Sync.

Sobald die Optionen einer konfigurierten Gruppe an Produkten hängen, erscheinen sie
automatisch als Kacheln.

## Hinweise

- Der Filter greift serverseitig über das Cookie → nach einem Klick lädt die Seite einmal neu.
- Es ist immer **genau ein** Fahrzeug aktiv oder keins (kein Cookie = „Alle Fahrzeuge").
- Der Cookie-Wert wird serverseitig nur akzeptiert, wenn er eine gültige UUID ist (`VehicleSelectionStorage::sanitize`). Ungültige Werte werden ignoriert und gelangen weder in den HTTP-Cache-Key noch in den Produktfilter.
- Die Fahrzeug-Beschreibung (Zusatzfeld) wird mit `sw_sanitize` ausgegeben, nicht mit `raw`.
- Ist die im Cookie gespeicherte Option in den aktuell konfigurierten Gruppen nicht
  (mehr) vorhanden, wird sie ignoriert, damit der Kunde nicht in einem leeren Filter feststeckt.
- Das kompilierte Storefront-JS (`dist/`) ist **im Plugin enthalten** – auf Produktivsystemen
  reicht `bin/console theme:compile`, kein `bin/build-storefront.sh` / Node nötig.
- Damit die Kachel-Leiste unter einem **eigenen Theme** erscheint, muss dessen `theme.json`
  in `style` **und** `script` den Eintrag `@Plugins` enthalten.

## Häufige Probleme und Lösungen

### Zeitüberschreitung (504) nach dem Cache-Leeren

**Messen:** Im Seitenquelltext stehen im Kopfbereich HTML-Kommentare „Nova Fahrzeugauswahl: Fahrzeugliste … ms“ und „Menüfilter … ms“.

**Ursache 2 (bis 1.12.4):** „Nur Fahrzeuge mit Produkten anzeigen“ prüfte über Shopwares Datenschicht – bei FranceTec rund 30 Sekunden für jeden ungecachten Kopfbereich. Ab 1.12.5 direkte Abfrage mit Zwischenspeicher.

**Ursache:** Bis 1.12.0 prüfte „Leere Kategorien im Menü ausblenden“ jede Menükategorie mit einer eigenen Abfrage – bei mehreren hundert Kategorien dauerte der erste Aufruf zu lange

**Lösung:** Ab 1.12.3 genügt eine schnelle Datenbankabfrage je Fahrzeug, auch für Kategorien mit dynamischer Produktgruppe (über Shopwares Tabelle `product_stream_mapping`). Das Ergebnis wird zwischengespeichert, Standard 24 Stunden, einstellbar unter „Menü-Prüfung zwischenspeichern“. Die geplante Aufgabe `nova_fahrzeugauswahl.warm_category_cache` rechnet es zusätzlich stündlich für alle Fahrzeuge vor. Neue oder leer gewordene Kategorien erscheinen im Menü deshalb erst nach der nächsten Vorberechnung, sofort nach „Caches leeren“. Voraussetzung: Die geplanten Aufgaben laufen (Einstellungen → System → Geplante Aufgaben bzw. Cronjob/Worker). Fehlt die Aufgabe dort nach dem Update, registriert sie `bin/console scheduled-task:register` oder das nächste Plugin-Update.

### Alte Modell-Links (z. B. /BX/) zeigen eine leere Seite

**Ursache:** Ist schon ein anderes Fahrzeug gewählt, filtert die Modell-Kategorie BX nach diesem Fahrzeug – oft bleibt nichts übrig

**Lösung (ab 1.11.0):** Modell-Kategorie auf Typ „Link“ umstellen, externer Link `https://<shop>/<Teile-Kategorie>/?fahrzeug=BX`. Shopware leitet die alte Adresse weiter, das Plugin wählt das Fahrzeug und entfernt den Parameter wieder

### Teilenummern in der Passtabelle unsichtbar (1.10.1)

**Ursache:** Die Theme-Regel für Footer-Links (weiß) war gleich stark wie die Plugin-Regel und gewann

**Lösung:** Ab 1.11.0 stärkere Regel; Linkfarbe über `--vs-desc-footer-link` anpassbar

### Passtabelle am Seitenende auf dunklem Footer kaum lesbar (bis 1.10.0)

**Ursache:** Die Beschreibung steht im Footer-Bereich und erbte dessen dunklen Hintergrund

**Lösung:** Ab 1.10.1 erscheint sie als helle Karte. Farben anpassbar über die CSS-Variablen `--vs-desc-footer-bg`, `--vs-desc-footer-color`, `--vs-desc-footer-heading`

### Kacheln zeigen trotz „Präfix entfernen“ den vollen Namen (bis 1.10.0)

**Ursache:** Der Vergleich war akzent- und kodierungsabhängig („Citroen“ ≠ „Citroën“)

**Lösung:** Ab 1.10.1 wird ohne Akzente verglichen. Greift es trotzdem nicht: prüfen, ob das Feld auf Verkaufskanal-Ebene leer gespeichert ist und den globalen Wert überdeckt

### Öl, Werkzeug oder Literatur verschwinden, sobald ein Fahrzeug gewählt ist

**Ursache:** Der Fahrzeugfilter zeigt nur Artikel mit der gewählten Ausprägung; Artikel ohne Fahrzeug-Zuordnung fallen heraus

**Lösung:** Ausprägung „Alle Fahrzeuge“ anlegen, diesen Artikeln zuordnen und in der Konfiguration unter *Passt zu allen Fahrzeugen* auswählen (ab 1.10.0)

### Kategorie-Überschrift „… für Citroën XM“ erscheint nicht (bis 1.8.4)

**Ursache:** Der Header wird in Shopware 6.7 als eigene Unteranfrage (ESI) gerendert; Daten, die am Header hängen, sieht der Seiteninhalt nicht

**Lösung:** Ab 1.8.5 hängen die Daten an der Kategorieseite. Außerdem muss „Kategorie-Überschrift anzeigen“ eingeschaltet sein

### Kachel-Leiste fehlt nach einer Massenbearbeitung der Fahrzeug-Eigenschaft

**Ursache:** Die Prüfung „Nur Fahrzeuge mit Produkten“ liest den Suchindex. Ist er nach einer Massenbearbeitung noch nicht aktualisiert (Warteschlange/Elasticsearch), findet sie keine Zuordnung

**Lösung:** Ab 1.8.4 blendet das Plugin dann nichts aus. Index aktualisieren: *Einstellungen → System → Caches & Indizes → Indizes aktualisieren* (bzw. `bin/console dal:refresh:index`); prüfen, ob die Warteschlange abgearbeitet wird

### Kachel-Leiste erscheint nicht – Ursache finden

**Ursache:** Freischaltung, Filtergruppe oder Ausprägungen passen nicht

**Lösung:** Seitenquelltext öffnen (Strg+U) und nach „Nova Fahrzeugauswahl:“ suchen – der Kommentar nennt den Grund (ab 1.8.3)

### Kachel-Leiste erscheint nicht, obwohl der Verkaufskanal freigeschaltet ist

**Ursache:** Beim Verkaufskanal wurde das Feld „In diesen Verkaufskanälen aktiv“ von der Vererbung getrennt und leer gespeichert – bis 1.8.1 hob das die Freischaltung bei „Alle Verkaufskanäle“ auf

**Lösung:** Auf 1.8.2 aktualisieren. Das Feld nur bei „Alle Verkaufskanäle“ pflegen; beim einzelnen Kanal die Vererbung bestehen lassen

### „Das Theme konnte für diese Shops nicht kompiliert werden“ nach dem Update auf 1.8.0

**Ursache:** Shopwares SCSS-Compiler (scssphp) rechnet `clamp(1.5rem, 1.2rem + 1.2vw, …)` selbst aus und scheitert an den gemischten Einheiten rem und vw

**Lösung:** Auf 1.8.1 aktualisieren und danach unter *Inhalte → Themes* speichern oder `bin/console theme:compile` ausführen. Die Schriftgröße wird jetzt mit `unquote()` unverändert an den Browser übergeben

## Unterstützen

Wenn dir dieses Projekt weiterhilft, freue ich mich über eine kleine Spende:

[![Spenden via PayPal](https://img.shields.io/badge/Spenden-PayPal-0070ba?logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

### Für Unternehmen: Unterstützung für die Weiterentwicklung

Ihr setzt das Projekt im Betrieb ein und möchtet die Weiterentwicklung unterstützen? Gern auch
**mit Rechnung** – einmalig oder **monatlich**. Auf Wunsch nenne ich euch hier unter
„Unterstützer“ mit Firmenname und Link.

Bitte beachtet: Ich bin kein Profi-Entwickler, das Projekt ist mein Hobby. Eine Unterstützung
ist deshalb kein Supportvertrag – Support, Reaktionszeiten oder Gewährleistung kann ich nicht
anbieten. Wünsche und Verbesserungsvorschläge nehme ich aber gerne mit auf.

Kontakt: [info@francetec.de](mailto:info@francetec.de)

#### Unterstützer

*Noch keine – über euren Namen hier würde ich mich freuen.*
