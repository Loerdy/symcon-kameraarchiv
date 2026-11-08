# Kamera-Archiv Kamera (CameraArchiveCamera)

Eine Kamera der UniFi-Protect-Konsole: nimmt per Auslöser oder manuell Snapshots auf, speichert die neuesten Bilder als Medienobjekte unterhalb der Instanz und zeigt sie in einer Kachel an.

> Status: in Entwicklung. Mail und NAS folgen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Snapshots über die [Kamera-Archiv Konsole](../CameraArchiveConsole/README.md)
* Auslöser: Variablen (Klingel, Türkontakt, Bewegungsmelder …) lösen Snapshots aus, mit Verzögerung und Sperrzeit
* Ringpuffer der neuesten Bilder als Medienobjekte unterhalb der Instanz (Standard: 5 Bilder)
* Kachel mit neuestem Bild, Archivleiste, Filter nach Auslöser und Vollbildansicht

### 2. Voraussetzungen

* Symcon ab Version 8.1
* Eine eingerichtete Kamera-Archiv Konsole

### 3. Software-Installation

* Über den Module Store das Modul „Kamera-Archiv“ installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/Loerdy/symcon-kameraarchiv`

### 4. Einrichten der Instanzen in Symcon

Die Kamera-Instanzen werden mit dem [Kamera-Archiv Konfigurator](../CameraArchiveConfigurator/README.md) angelegt. Er trägt die Protect-Kamera-ID ein und verbindet die Instanz mit der Konsole.

Der Name der Instanz erscheint als Name der Kamera in den Kacheln. Zum Umbenennen des Kamera-Buttons wird einfach die Instanz umbenannt.

__Konfigurationsseite__:

| Name | Beschreibung |
| ---- | ------------ |
| Protect-Kamera-ID | Wird vom Konfigurator gesetzt. |
| Bilder im RAM | Anzahl der gespeicherten Bilder (1–200, Standard 5). |
| Auslöser | Liste der Auslöser, Bearbeiten über das Stift-Symbol. Je Auslöser: Aktiv, Name (erscheint als Auslöser am Bild; leer = Name der Variable), Variable, Bedingung, Wert (bei „Wechselt auf Wert“ und „Wird mit Wert aktualisiert“; bei Bool-Variablen Auswahl wahr/falsch, sonst Eingabe als Zahl mit Komma oder Punkt bzw. als Text, Groß-/Kleinschreibung zählt, leer löst nie aus), Verzögerung (0–60 s) und Sperrzeit (0–3600 s, Standard 30 s). Das Wertfeld passt sich der gewählten Variable an. Rot markiert: Variable fehlt. |
| Mindestabstand zwischen zwei Snapshots | Gilt über alle Auslöser dieser Kamera hinweg, z. B. wenn Klingel und Türkontakt gleichzeitig auslösen. Standard 5 s, 0 = aus. |

Soll ein Ereignis mehrere Kameras auslösen (z. B. die Klingel für Haustür und Einfahrt), wird der Auslöser in jeder dieser Kameras eingetragen.

__Bedingungen__:

| Bedingung | Löst aus, wenn … |
| --------- | ---------------- |
| Jede Änderung | sich der Wert ändert |
| Jede Aktualisierung | die Variable aktualisiert wird, auch mit gleichem Wert |
| Wechselt auf Wert | sich der Wert auf den gewählten Wert ändert (z. B. Türkontakt wird geöffnet). Zyklische Meldungen mit gleichem Wert lösen nicht erneut aus. |
| Wird mit Wert aktualisiert | die Variable mit dem gewählten Wert aktualisiert wird, auch ohne Änderung (z. B. Klingel, die bei jedem Druck erneut true meldet) |

__Ablauf der Auslöser__:

* Die Instanz reagiert auf Aktualisierungen der Auslöser-Variablen und stellt fällige Snapshots in eine Warteschlange. Ein einzelner Timer nimmt sie zum fälligen Zeitpunkt nacheinander auf.
* Die Sperrzeit eines Auslösers zählt ab seiner letzten Auslösung. Der Mindestabstand verhindert zwei Snapshots kurz hintereinander.
* Inaktive Auslöser und eine unvollständige Konfiguration (keine Kamera-ID, keine Konsole) lösen keinen Snapshot aus (Hinweis in der Debug-Ausgabe).
* Die Warteschlange liegt nur im Arbeitsspeicher. Bei einem Neustart von Symcon gehen noch nicht aufgenommene Snapshots verloren, und Änderungen während des Neustarts werden nicht nachgeholt.

__Testbereich__:

| Name | Beschreibung |
| ---- | ------------ |
| Snapshot testen | Ruft einen Snapshot ab und speichert ihn. Details stehen in der Debug-Ausgabe der Konsole. |
| Alle Bilder löschen | Löscht nach Rückfrage alle Bilder dieser Kamera, auch die als „Behalten“ markierten. |

__Speicherung__:

* Jedes Bild liegt in einem eigenen Medienobjekt (Ident `IMG_<Nr>`) direkt unterhalb der Instanz. Ein Medienobjekt wird erst beim ersten Bild für diesen Platz angelegt und danach immer wieder überschrieben.
* Ist die eingestellte Anzahl erreicht, überschreibt ein neues Bild das älteste Bild, das nicht als „Behalten“ markiert ist. Sind alle Bilder markiert, wird kein neues Bild gespeichert.
* Wird die Anzahl verringert, werden die ältesten nicht markierten Bilder gelöscht. Markierte Bilder bleiben erhalten.
* Der Index (Zeitpunkt, Auslöser, „Behalten“, Größe) liegt im Medienobjekt „Bildindex“ (Ident `ImageIndex`).
* Ob die Medienobjekte im Arbeitsspeicher gecacht werden, legt die Option „In-Memory-Cache verwenden“ der Konsole fest (Standard: an).
* Name und Sichtbarkeit der Medienobjekte werden nur beim Anlegen gesetzt (versteckt) und können danach frei geändert werden.
* Ist der Bildindex beschädigt, speichert die Instanz keine Bilder mehr und meldet dies im Meldungsfenster. Abhilfe: Medienobjekt „Bildindex“ löschen. Die vorhandenen Bild-Medienobjekte werden dann nach und nach wieder überschrieben.

__Status__:

| Code | Bedeutung |
| ---- | --------- |
| 102 | Bereit |
| 201 | Keine Kamera gewählt (Instanz mit dem Konfigurator anlegen) |
| 202 | Nicht mit einer Kamera-Archiv Konsole verbunden |

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an.

### 6. Visualisierung

Die Instanz bringt eine eigene Kachel mit (HTML-SDK). Die Kachel der Konsole zeigt dieselbe Ansicht für alle Kameras, mit einem Button je Kamera.

* In der Mitte das ausgewählte Bild mit Auslöser und Zeit. Ohne Auswahl wird immer das neueste Bild angezeigt, neue Snapshots erscheinen automatisch.
* Unten die Archivleiste mit Vorschaubildern, neueste zuerst, und ein Filter nach Auslöser (sobald es mehr als einen Auslöser gibt).
* Oben rechts auf dem Bild liegen die Aktionen:
  * **Snapshot aufnehmen** (Kamera-Symbol): Das neue Bild erscheint, sobald es gespeichert ist. Während der Aufnahme dreht sich das Symbol. Schlägt die Aufnahme fehl, erscheint ein Hinweis in der Kachel. Sperrzeiten und Mindestabstand der Auslöser gelten hier nicht.
  * **Behalten** (Lesezeichen): Ein markiertes Bild wird vom Ringpuffer nicht überschrieben. Erneutes Antippen hebt die Markierung auf.
  * **Herunterladen**: speichert das Bild als `<Name der Instanz>_<Datum>_<Uhrzeit>.jpg`. Ob die Datei gespeichert oder nur angezeigt wird, hängt von Browser bzw. App ab.
  * **Löschen** (Papierkorb): Das erste Antippen fragt nach („Löschen?“), das zweite innerhalb von 4 Sekunden löscht das Bild, auch wenn es als „Behalten“ markiert ist.
* Antippen des großen Bildes öffnet die Vollbildansicht. Wischen oder die Pfeile wechseln zum neueren oder älteren Bild, Antippen des Bildes oder das Kreuz schließt die Ansicht. Am Rechner gehen auch die Pfeiltasten und Escape. Erlaubt der Browser bzw. die App für die Kachel echtes Vollbild, füllt die Ansicht den ganzen Bildschirm, sonst die ganze Kachel.

Die Bilder liefert der WebHook der Instanz (`/hook/camera-archive-<InstanzID>`). WebHooks sind ohne Anmeldung erreichbar, auch über Symcon Connect. Der WebHook liefert deshalb nur Bilder, wenn die Anfrage den geheimen Schlüssel der Instanz enthält. Diesen Schlüssel erhalten nur die Kacheln der Instanz und der Konsole, die selbst durch das Passwort der Visualisierung geschützt sind. Die Aktionen der Kachel laufen nicht über den WebHook, sondern über die Visualisierung.

### 7. PHP-Befehlsreferenz

`boolean CAR_TakeSnapshot(integer $InstanzID);`
Ruft einen Snapshot der Kamera ab und speichert ihn (Auslöser „manual“). Liefert `true`, wenn das Bild gespeichert wurde.

Beispiel:
`CAR_TakeSnapshot(12345);`

`boolean CAR_DeleteImages(integer $InstanzID);`
Löscht alle gespeicherten Bilder der Kamera, auch die als „Behalten“ markierten. Liefert `true` bei Erfolg.

Beispiel:
`CAR_DeleteImages(12345);`

Die Funktionen `CAR_UITestSnapshot`, `CAR_UIDeleteImages`, `CAR_UITriggerForm` und `CAR_UITriggerUpdate` dienen dem Konfigurationsformular und sind nicht für Skripte gedacht.
