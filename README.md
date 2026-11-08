# Kamera-Archiv für Symcon

Snapshots von Kameras per Auslöser (Klingel, Türkontakt, Bewegungsmelder …) aufnehmen und je Kamera in einem Archiv ablegen – mit Kachel-Visualisierung, neueste Bilder zuerst.

> Status: in Entwicklung. Konzept siehe [docs/KONZEPT.md](docs/KONZEPT.md).

## Module

| Modul | Beschreibung |
| ----- | ------------ |
| [Kamera-Archiv Konsole](CameraArchiveConsole/README.md) | Verbindung zur UniFi-Protect-Konsole, Bildeinstellungen, Übersichtskachel aller Kameras |
| [Kamera-Archiv Konfigurator](CameraArchiveConfigurator/README.md) | Listet die Kameras der Konsole und legt je Kamera eine Instanz an |
| [Kamera-Archiv Kamera](CameraArchiveCamera/README.md) | Eine Kamera: Auslöser, gespeicherte Snapshots, eigene Kachel |

Einrichtung: einen „Kamera-Archiv Konfigurator“ anlegen (die Konsole wird dabei mit angelegt), in der Konsole Host und API-Key eintragen, dann im Konfigurator die gewünschten Kameras anlegen.

## Branches

- `main` – veröffentlichte Versionen
- `beta` – Entwicklung
