# 1.12.2
- Leistung: Die Menü-Prüfung „Leere Kategorien ausblenden“ nutzt eine direkte Datenbankabfrage statt der Shopware-Aggregation (die dauerte bei großen Katalogen 30 Sekunden und mehr je Fahrzeug)
- Neu: Eine geplante Aufgabe rechnet die Prüfung stündlich für alle Fahrzeuge vor – auch der erste Aufruf nach einem Fahrzeugwechsel muss nicht mehr warten
- Neu: Einstellung **Menü-Prüfung zwischenspeichern (Stunden)**, Standard 24

# 1.12.1
- Leistung: „Leere Kategorien im Menü ausblenden“ prüft alle Kategorien mit EINER Sammelabfrage statt einer Abfrage je Kategorie und speichert das Ergebnis je Fahrzeug 15 Minuten – verhindert Zeitüberschreitungen (504) beim ersten Aufruf nach dem Cache-Leeren bei großen Kategoriebäumen

# 1.12.0
- Neu: **Filtergruppe 2 einklappen** – die Fahrzeuge der zweiten Gruppe erscheinen hinter einem kleinen Aufklapper (Standard „Weitere Fahrzeuge“); ist dort ein Fahrzeug gewählt, ist er offen

# 1.11.1
- Fahrzeug per Link: Weiterleitung auf die ursprüngliche Adresse statt /navigation/<id>; Kurzformen wie `?fahrzeug=xm` oder `?fahrzeug=jcb` werden auch ohne eingestellten Präfix erkannt (nur bei eindeutigem Treffer)

# 1.11.0
- Neu: **Fahrzeug per Link wählen** – `?fahrzeug=XM` (Name, gekürzter Name oder ID, `alle` = zurücksetzen) setzt die Auswahl und leitet auf die Adresse ohne Parameter weiter. Damit lassen sich alte Modell-Seiten als Kategorie-Typ „Link“ auf eine Teile-Kategorie mit vorgewähltem Fahrzeug umleiten
- Passtabelle am Seitenende: Teilenummern waren auf der hellen Karte unsichtbar (weiße Footer-Links) – jetzt in Akzentfarbe

# 1.10.1
- Präfix kürzen greift jetzt unabhängig von Akzenten und Schreibweise („Citroen“/„Citroën“)
- Fahrzeug-Beschreibung am Seitenende als helle Karte – auf dunklem Footer war die Überschrift kaum lesbar

# 1.10.0
- Neu: **Ausprägung „Passt zu allen Fahrzeugen“** – Artikel mit dieser Ausprägung (Öl, Werkzeug, Literatur, Universalteile) erscheinen bei jedem gewählten Fahrzeug, auch im Menü-Filter. Die Ausprägung bekommt keine Kachel

# 1.9.0
- Neu: **Leere Kategorien im Menü ausblenden** – bei gewähltem Fahrzeug zeigen Hauptmenü und mobiles Menü nur Kategorien mit passenden Produkten (auch bei dynamischen Produktgruppen). Inhaltsseiten ohne Produkte, Links und Ordner bleiben stehen

# 1.8.6
- „Nur Fahrzeuge mit Produkten anzeigen“ prüft jetzt direkt die Zuordnung Fahrzeug ↔ Produkt (aktiv und im Verkaufskanal sichtbar) – die bisherige Auswertung fand trotz zugeordneter Produkte nichts

# 1.8.5
- Kategorie-Überschrift „<Kategorie> für <Fahrzeug>“ erscheint jetzt: Die Daten hängen an der Kategorieseite statt am Header (der Header wird als eigene Unteranfrage gerendert)

# 1.8.4
- „Nur Fahrzeuge mit Produkten anzeigen“: Findet die Prüfung gar keine Zuordnung (meist veralteter Suchindex nach einer Massenbearbeitung), wird nichts mehr ausgeblendet – die Leiste verschwindet nicht komplett. Der Status-Kommentar weist darauf hin

# 1.8.3
- Neu: Status als HTML-Kommentar im Header („Nova Fahrzeugauswahl: aktiv, 15 Fahrzeuge“ bzw. der Grund, warum keine Kachel-Leiste erscheint) – im Seitenquelltext sichtbar, für Kunden unsichtbar

# 1.8.2
- Fehler behoben: Die Kachel-Leiste fehlte, wenn beim Verkaufskanal das Feld „In diesen Verkaufskanälen aktiv“ von der Vererbung getrennt (z. B. leer) gespeichert war. Maßgeblich ist jetzt immer auch die Liste bei „Alle Verkaufskanäle“

# 1.8.1
- Fehler behoben: Nach dem Update auf 1.8.0 ließ sich das Theme nicht kompilieren („Incompatible units vw and rem“) – die Schriftgröße der Kategorie-Überschrift wird jetzt unverändert an den Browser gegeben

# 1.8.0
- Neu: **Kategorie-Überschrift** (optional): Kategoriename als Überschrift, bei gewähltem Fahrzeug ergänzt – z. B. „Federkugeln für Citroën XM“. Verbindungswort einstellbar
- Neu: **CSS-Klasse der Fahrzeug-Beschreibung** – z. B. `nova-fitment`, damit Passtabellen im Stil des Nova Themes erscheinen

# 1.7.1
- Neues Plugin-Icon im Nova-Stil (Erweiterungen → Meine Erweiterungen)
- Neuer Anzeigename „Nova Fahrzeugauswahl“ (technischer Name bleibt – keine Neuinstallation nötig)
- Repository umbenannt in SW6-Nova-Fahrzeugauswahl – alte Adresse leitet weiter
