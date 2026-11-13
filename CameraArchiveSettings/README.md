# Kamera-Archiv Einstellungen (CameraArchiveSettings)

Zeigt die Einstellungen aller Kamera-Instanzen in einer Tabelle und schreibt Änderungen in die Kameras.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Eine Zeile je [Kamera-Instanz](../CameraArchiveCamera/README.md), auch über mehrere Konsolen hinweg
* Anzeigename, Bilder im RAM, Mindestabstand zwischen zwei Snapshots, Live-Ansicht (Autostart, Höchstdauer, Abrufintervall) und NAS (Upload, Ordner, Aufbewahrung) direkt in der Tabelle ändern
* Die Werte bleiben in den Kamera-Instanzen gespeichert. Die Tabelle zeigt beim Öffnen immer deren aktuellen Stand, Änderungen direkt in einer Kamera gehen also nicht verloren.

### 2. Voraussetzungen

* Symcon ab Version 9.1
* Mindestens eine Kamera-Archiv Kamera

### 3. Software-Installation

* Über den Module Store das Modul „Kamera-Archiv“ installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/Loerdy/symcon-kameraarchiv`

### 4. Einrichten der Instanzen in Symcon

Unter „Instanz hinzufügen“ kann „Kamera-Archiv Einstellungen“ mithilfe des Schnellfilters gefunden werden. Die Instanz braucht keine Verbindung zur Konsole. Eine Instanz genügt.

__Konfigurationsseite__:

Werte in den Zeilen ändern und mit „Änderungen übernehmen“ bestätigen. Nur Zeilen, die in diesem Formular geändert wurden, werden in die Kameras geschrieben; jede geänderte Kamera wird danach einmal übernommen. Auslöser und Benachrichtigungen werden weiterhin in den Kamera-Instanzen eingestellt.

| Spalte | Beschreibung |
| ------ | ------------ |
| ID | Objekt-ID der Kamera-Instanz |
| Instanz | Name der Kamera-Instanz |
| Anzeigename | Name auf den Kamera-Buttons, in Benachrichtigungen und Downloads. Leer = Name der Instanz. |
| Bilder im RAM | 1–200 |
| Mindestabstand (s) | Mindestabstand zwischen zwei Snapshots, 0–3600 s, 0 = aus |
| Live-Autostart | Live-Ansicht beim Anzeigen der Kachel starten |
| Live max. (min) | Höchstdauer der Live-Ansicht, 1–30 Minuten |
| Live-Intervall (s) | Abrufintervall der Live-Ansicht, 1–30 Sekunden |
| NAS-Upload | Snapshots auf das NAS hochladen |
| NAS-Ordner | Ordner der Kamera im Archivordner. Leer = Name der Instanz. |
| NAS-Tage | Bilder auf dem NAS aufbewahren, 0 = nie löschen |

Die Bedeutung der Werte beschreibt die [Kamera-Instanz](../CameraArchiveCamera/README.md#4-einrichten-der-instanzen-in-symcon).

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an.

### 6. Visualisierung

Das Modul hat keine Darstellung in der Visualisierung.

### 7. PHP-Befehlsreferenz

Das Modul bietet keine Funktionen für Skripte.
