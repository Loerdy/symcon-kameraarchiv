# Kamera-Archiv Konsole (CameraArchiveConsole)

Verbindung zur UniFi-Protect-Konsole. Die Kamera-Instanzen holen ihre Snapshots über diese Instanz. Ihre Kachel zeigt alle verbundenen Kameras auf einen Blick.

> Status: in Entwicklung. NAS folgt.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Verbindung zur UniFi-Protect-Konsole über die offizielle Integration API (API-Key)
* Abruf der Kameraliste für den [Kamera-Archiv Konfigurator](../CameraArchiveConfigurator/README.md)
* Snapshot-Abruf für die [Kamera-Instanzen](../CameraArchiveCamera/README.md), in voller Auflösung, wenn die Kamera Full-HD-Snapshots unterstützt
* Optionale Verkleinerung vor dem Speichern (Standard: an, 1280 px Breite, JPEG-Qualität 75)
* Einstellung „In-Memory-Cache verwenden“ für alle Kameras
* Übersichtskachel mit allen Kameras

### 2. Voraussetzungen

* Symcon ab Version 8.1
* PHP-Erweiterung cURL (in Symcon enthalten)
* Für die Verkleinerung: PHP-Erweiterung GD. Fehlt sie, werden die Bilder unverkleinert gespeichert.
* UniFi-OS-Konsole mit UniFi Protect und einem API-Key (Control Plane > Integrations)

### 3. Software-Installation

* Über den Module Store das Modul „Kamera-Archiv“ installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/Loerdy/symcon-kameraarchiv`

### 4. Einrichten der Instanzen in Symcon

Am einfachsten wird zuerst ein „Kamera-Archiv Konfigurator“ angelegt. Dabei wird die Konsole automatisch mit angelegt. Alternativ lässt sich die Konsole unter „Instanz hinzufügen“ über den Schnellfilter „Kamera-Archiv Konsole“ einzeln anlegen.

__Konfigurationsseite__:

| Name | Beschreibung |
| ---- | ------------ |
| Protect-Konsole | Hostname, IPv4- oder IPv6-Adresse der UniFi-OS-Konsole, optional mit Port (z. B. `192.168.1.1:443`; IPv6 mit Port in eckigen Klammern, z. B. `[fd00::1]:443`). Die Verbindung erfolgt immer per HTTPS. |
| API-Key | API-Key aus UniFi OS (Control Plane > Integrations). Er hat Administratorrechte und muss geheim bleiben. |
| Zertifikat nicht prüfen | Für Konsolen mit selbstsigniertem Zertifikat. Standardmäßig aus. |
| In-Memory-Cache verwenden | Gilt für die Bilder aller Kameras dieser Konsole, siehe unten. Standardmäßig an. |
| Bilder vor dem Speichern verkleinern | Verkleinert Bilder, die breiter als die maximale Breite sind. Kleinere Bilder werden unverändert gespeichert. Standardmäßig an. |
| Maximale Breite | Breite in Pixeln, das Seitenverhältnis bleibt erhalten. Standard 1280. |
| JPEG-Qualität | Qualität der verkleinerten Bilder (30–95). Standard 75. |

__In-Memory-Cache__:

* An (Standard): Die Bilder und der Bildindex jeder Kamera sind im Arbeitsspeicher gecacht und werden erst beim Herunterfahren von Symcon geschrieben. Das schont den Flash-Speicher der SymBox. Bei einem Stromausfall oder hartem Beenden von Symcon gehen die Bilder seit dem letzten Herunterfahren verloren.
* Aus: Jedes Bild wird sofort auf den Speicher geschrieben. Das ist für Systeme mit Festplatte gedacht (z. B. Docker auf einem NAS) und sollte auf der SymBox oder bei SD-/SSD-Speicher nicht verwendet werden.
* Eine Änderung wird beim Übernehmen sofort auf alle verbundenen Kameras angewendet.

__Testbereich__:

| Name | Beschreibung |
| ---- | ------------ |
| Verbindung testen | Fragt die Kameraliste ab und meldet das Ergebnis. |

__Status__:

| Code | Bedeutung |
| ---- | --------- |
| 102 | Bereit |
| 201 | Protect-Konsole oder API-Key fehlt |
| 202 | PHP-Erweiterung cURL ist nicht verfügbar |
| 203 | Protect-Konsole nicht erreichbar |
| 204 | API-Key ungültig oder ohne Berechtigung |
| 205 | Zertifikat konnte nicht geprüft werden |
| 206 | Unerwartete Antwort der Protect-Konsole |

Ein Fehlerstatus wird bei der nächsten erfolgreichen Anfrage wieder zurückgesetzt.

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an.

### 6. Visualisierung

Die Kachel der Konsole zeigt alle verbundenen Kameras. Sie sieht aus wie die Kachel einer Kamera, hat aber oben eine Leiste mit einem Button je Kamera. Der Button trägt den Namen der Kamera-Instanz. Zum Umbenennen eines Buttons wird also die Kamera-Instanz umbenannt. Die Reihenfolge folgt der Position der Kamera-Instanzen im Objektbaum, danach dem Namen.

Die Bedienung (Archivleiste, Filter, Vollbild, Aktionen) ist in der [Beschreibung der Kamera](../CameraArchiveCamera/README.md#6-visualisierung) erklärt.

### 7. PHP-Befehlsreferenz

Das Modul bietet keine Funktionen für Skripte. `CARC_UITestConnection` dient dem Konfigurationsformular.
