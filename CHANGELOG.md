# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt

- **Modul Kamera-Archiv (CameraArchive)** mit Präfix `CAR`. Die Instanz lässt sich ohne Konfiguration anlegen und meldet fehlende Einstellungen über den Status.
- **UniFi Protect Integration API:** Protect-Konsole (Hostname, IPv4 oder IPv6, optional mit Port), API-Key und Option „Zertifikat nicht prüfen“.
- **Verbindung testen** meldet das Ergebnis und setzt den Status (nicht erreichbar, Zertifikatsfehler, API-Key ungültig, unerwartete Antwort).
- **Kameras abrufen** schlägt die Kameras der Konsole in der Kameraliste vor; die Auswahl wird mit „Übernehmen“ gespeichert. Kameras ohne Snapshot über Protect (z. B. Drittanbieter) werden rot, nicht verbundene Kameras gelb markiert.
- **`CAR_TakeSnapshot`** ruft einen Snapshot einer aktiven Kamera ab, in voller Auflösung, wenn die Kamera dies unterstützt, und speichert ihn.
- **Speicherung im RAM:** Ringpuffer je Kamera aus gecachten Bild-Medienobjekten unterhalb der Instanz, Anzahl je Kamera in der Kameraliste einstellbar (Standard 30). Der Index liegt in einem ebenfalls gecachten Medienobjekt „Bildindex“. Bilder und Index werden erst beim Herunterfahren auf den Flash geschrieben. Als „Behalten“ markierte Bilder werden nicht überschrieben.
- **Verkleinern** vor dem Speichern per GD (Standard: an, 1280 px, Qualität 75). Ohne GD werden die Bilder unverkleinert gespeichert.
- **Speicheranzeige** im Formular (Gesamt und je Kamera).
- **`CAR_DeleteImages`** und Button „Bilder entfernter Kameras löschen“.
