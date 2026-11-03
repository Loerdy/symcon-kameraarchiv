# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt

- **Modul Kamera-Archiv (CameraArchive)** mit Präfix `CAR`. Die Instanz lässt sich ohne Konfiguration anlegen und meldet fehlende Einstellungen über den Status.
- **UniFi Protect Integration API:** Protect-Konsole (Hostname, IPv4 oder IPv6, optional mit Port), API-Key und Option „Zertifikat nicht prüfen“.
- **Verbindung testen** meldet das Ergebnis und setzt den Status (nicht erreichbar, Zertifikatsfehler, API-Key ungültig, unerwartete Antwort).
- **Kameras abrufen** schlägt die Kameras der Konsole in der Kameraliste vor; die Auswahl wird mit „Übernehmen“ gespeichert. Kameras ohne Snapshot über Protect (z. B. Drittanbieter) werden rot, nicht verbundene Kameras gelb markiert.
- **`CAR_TakeSnapshot`** ruft einen Snapshot einer aktiven Kamera ab, in voller Auflösung, wenn die Kamera dies unterstützt. Das Bild wird vorerst nur in der Debug-Ausgabe gemeldet und noch nicht gespeichert.
