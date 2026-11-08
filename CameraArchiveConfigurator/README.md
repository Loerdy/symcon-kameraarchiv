# Kamera-Archiv Konfigurator (CameraArchiveConfigurator)

Listet die Kameras der UniFi-Protect-Konsole und legt je Kamera eine Kamera-Instanz an.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Liste aller Kameras der verbundenen [Kamera-Archiv Konsole](../CameraArchiveConsole/README.md) mit Typ, Zustand und Snapshot-Unterstützung
* Anlegen einer [Kamera-Instanz](../CameraArchiveCamera/README.md) je Kamera per Klick
* Anzeige vorhandener Kamera-Instanzen, auch wenn die Konsole die Kamera nicht mehr kennt

### 2. Voraussetzungen

* Symcon ab Version 8.1
* Eine eingerichtete Kamera-Archiv Konsole

### 3. Software-Installation

* Über den Module Store das Modul „Kamera-Archiv“ installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/Loerdy/symcon-kameraarchiv`

### 4. Einrichten der Instanzen in Symcon

Unter „Instanz hinzufügen“ kann der „Kamera-Archiv Konfigurator“ mithilfe des Schnellfilters gefunden werden. Beim Anlegen wird eine vorhandene Kamera-Archiv Konsole zugeordnet oder eine neue angelegt.

__Konfigurationsseite__:

| Spalte | Beschreibung |
| ------ | ------------ |
| Name | Name der Kamera in Protect. Er wird beim Anlegen als Name der Instanz übernommen und kann danach frei geändert werden. Bei vorhandenen Instanzen zeigt die Spalte den Namen der Instanz. |
| Typ | Kameratyp laut Protect |
| Zustand | Verbindungszustand laut Protect, oder „Nicht auf der Konsole gefunden“ |
| Snapshot | „Kein Snapshot über Protect“ bei Drittanbieter-Kameras. Für sie kann keine Instanz angelegt werden, da Protect keine Snapshots liefert. |
| Kamera-ID | ID der Kamera in Protect |

Ist die Konsole nicht erreichbar oder unvollständig eingerichtet, erscheint oberhalb der Liste ein Hinweis, und es werden nur die vorhandenen Kamera-Instanzen angezeigt.

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an.

### 6. Visualisierung

Der Konfigurator hat keine Darstellung in der Visualisierung.

### 7. PHP-Befehlsreferenz

Das Modul bietet keine Funktionen für Skripte.
