# Kamera-Archiv für Symcon

Snapshots von Kameras per Auslöser (Klingel, Türkontakt, Bewegungsmelder …) aufnehmen und je Kamera in einem Archiv ablegen – mit Kachel-Visualisierung, neueste Bilder zuerst.

Voraussetzungen: Symcon ab Version 9.1, eine UniFi-OS-Konsole mit UniFi Protect und API-Key. Optional ein NAS mit WebDAV (z. B. Synology) und eine SMTP-Instanz bzw. Kachel-Visualisierung für Benachrichtigungen. Technisches Konzept: [docs/KONZEPT.md](docs/KONZEPT.md), Änderungen: [CHANGELOG.md](CHANGELOG.md).

## Module

| Modul | Beschreibung |
| ----- | ------------ |
| [Kamera-Archiv Konsole](CameraArchiveConsole/README.md) | Verbindung zur UniFi-Protect-Konsole, Bildeinstellungen, Übersichtskachel aller Kameras |
| [Kamera-Archiv Konfigurator](CameraArchiveConfigurator/README.md) | Listet die Kameras der Konsole und legt je Kamera eine Instanz an |
| [Kamera-Archiv Kamera](CameraArchiveCamera/README.md) | Eine Kamera: Auslöser, gespeicherte Snapshots, eigene Kachel |
| [Kamera-Archiv Einstellungen](CameraArchiveSettings/README.md) | Einstellungen aller Kameras in einer Tabelle (optional) |

Einrichtung: einen „Kamera-Archiv Konfigurator“ anlegen (die Konsole wird dabei mit angelegt), in der Konsole Host und API-Key eintragen, dann im Konfigurator die gewünschten Kameras anlegen.

## Branches

- `main` – veröffentlichte Versionen
- `beta` – Testversionen
- `development` – Entwicklung
