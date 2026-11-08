# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [1.0.0] - 2026-11-08

Erste Veröffentlichung.

### Hinzugefügt

- **Drei Module:**
  - **Kamera-Archiv Konsole** (Präfix `CARC`): Verbindung zur UniFi-Protect-Konsole (Hostname, IPv4 oder IPv6), API-Key, Option „Zertifikat nicht prüfen“, Verkleinerung und In-Memory-Cache für alle Kameras. „Verbindung testen“ meldet das Ergebnis und setzt den Status (nicht erreichbar, Zertifikatsfehler, API-Key ungültig, unerwartete Antwort).
  - **Kamera-Archiv Konfigurator** (Präfix `CARK`): listet die Kameras der Konsole und legt je Kamera eine Instanz an. Drittanbieter-Kameras ohne Snapshot über Protect werden gekennzeichnet und nicht angelegt.
  - **Kamera-Archiv Kamera** (Präfix `CAR`): eine Instanz je Kamera mit eigenen Auslösern, eigenen Bildern unterhalb der Instanz und eigener Kachel.
- Alle Instanzen lassen sich ohne Konfiguration anlegen und melden fehlende Einstellungen über den Status.
- **`CAR_TakeSnapshot`** ruft einen Snapshot der Kamera ab, in voller Auflösung, wenn die Kamera dies unterstützt, und speichert ihn.
- **Speicherung im RAM:** Ringpuffer je Kamera aus gecachten Bild-Medienobjekten unterhalb der Kamera-Instanz, Anzahl einstellbar (Standard 5). Der Index liegt in einem ebenfalls gecachten Medienobjekt „Bildindex“. Bilder und Index werden erst beim Herunterfahren auf den Flash geschrieben. Als „Behalten“ markierte Bilder werden nicht überschrieben. Option „In-Memory-Cache verwenden“ in der Konsole (Standard: an); ohne Cache werden Bilder und Index sofort geschrieben, z. B. für Docker auf einem NAS. Fehlt die Datei des Index (z. B. nach einem Neustart), wird mit einer Warnung im Meldungsfenster ein neuer Index begonnen; ein beschädigter Index wird dagegen nie überschrieben.
- **Verkleinern** vor dem Speichern per GD (Standard: an, 1280 px, Qualität 75). Ohne GD werden die Bilder unverkleinert gespeichert.
- **Speicheranzeige** in der Kamera-Instanz, **`CAR_DeleteImages`** und Button „Alle Bilder löschen“.
- **Auslöser** je Kamera: Liste von Variablen mit Bedingung (jede Änderung, jede Aktualisierung, wechselt auf Wert, wird mit Wert aktualisiert), Verzögerung und Sperrzeit, zusätzlich ein Mindestabstand zwischen zwei Snapshots. Der Name des Auslösers wird am Bild gespeichert.
- **Warteschlange** der fälligen Snapshots im Arbeitsspeicher, abgearbeitet von einem einzelnen Timer je Kamera.
- **Kachel** (HTML-SDK) je Kamera mit neuestem Bild, Auslöser und Zeit, Archivleiste mit Vorschaubildern und Filter nach Auslöser. Neue Bilder erscheinen ohne Neuladen.
- **Übersichtskachel** der Konsole mit allen Kameras. Die Kamera-Buttons tragen den Namen der Kamera-Instanz und lassen sich so frei benennen.
- **Vollbildansicht:** Antippen des Bildes öffnet es groß, mit Wischen bzw. Pfeilen zum neueren oder älteren Bild. Echtes Vollbild, wenn die Visualisierung es für die Kachel erlaubt, sonst über die ganze Kachel.
- **WebHook** `/hook/camera-archive-<InstanzID>` je Kamera liefert die Bilder an die Kacheln, nur mit dem geheimen Schlüssel der Instanz.
- **Aktionen in der Kachel** (Kamera und Übersicht): Snapshot aufnehmen, Bild behalten bzw. nicht mehr behalten, herunterladen und löschen (mit Rückfrage). Ein fehlgeschlagener Snapshot wird in der Kachel gemeldet.
- **Benachrichtigungen** je Kamera nach jedem gespeicherten Snapshot eines Auslösers: E-Mail mit dem Bild als Anhang (SMTP-Instanz, optional eigener Empfänger) oder Push-Nachricht der Kachel-Visualisierung. Je Zeile wählbar: Auslöser (oder alle) und eine optionale Bedingung aus Variable und Wert, z. B. nur bei Abwesenheit.
- **NAS per WebDAV:** Die Konsole lädt das Original jedes Snapshots in voller Auflösung hoch (`<Kamera>/<Datum>/<Uhrzeit>_<Auslöser>.jpg`), je Kamera einschaltbar mit eigenem Ordner und Aufbewahrung in Tagen. Warteschlange im Arbeitsspeicher mit erneuten Versuchen, wenn das NAS nicht erreichbar ist, tägliches Löschen alter Tagesordner, Kopie „behaltener“ Bilder in einen Ordner, der nie gelöscht wird. Button „NAS-Verbindung testen“.
- **NAS-Archiv in der Kachel:** Bei eingeschaltetem NAS-Upload wechselt die Taste „Archiv“ zu den Bildern auf dem NAS, mit Auswahl des Tages, Vorschaubildern (beim Upload als 320 px breite Kopie in `thumbs` abgelegt), Filter nach Auslöser, Vollbild und Download.

[Unreleased]: https://github.com/Loerdy/symcon-kameraarchiv/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Loerdy/symcon-kameraarchiv/releases/tag/v1.0.0
