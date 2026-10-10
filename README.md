# SymconBroadlink

IP-Symcon-Modul für die **Broadlink-Fernbedienungen** der RM-Reihe (RM4 pro,
RM4 mini). Es lernt und sendet IR-Codes lokal per UDP, ohne Broadlink-Cloud,
ohne IFTTT und ohne Zusatzdienst, nur mit PHP auf der Symcon-Box.

Das Modul kann:

- Geräte suchen, auch in einem anderen Netz als Symcon (Adressbereich)
- IR-Codes lernen (30 Sekunden Zeit für die Taste) und unter einem Namen
  speichern
- gelernte Codes, fremde Codes (Base64/Hex) und Samsung-Codes aus
  Code-Sammlungen senden
- je Befehl einen eigenen Schalter anlegen, z. B. für die HomeKit-Bridge
  und Apple Kurzbefehle
- die Codes des früheren Broadlink-Skripts einmalig übernehmen

## Abgrenzung zum Store-Modul „Broadlink RM“

Im Symcon-Store gibt es „Broadlink RM“ (Fonzo/Wolbolar, Version 0.4, letzte
Änderung Februar 2020). Es kennt die RM4-Reihe nicht. Die Geräte RM4 pro
(0x520B) und RM4 mini (0x520C) fehlen in seiner Typenliste. Seine Befehle
gehen im Format der älteren RM-Geräte raus, ohne die Längenangabe, die die
RM4-Reihe erwartet. Mit einem RM4 lernt und sendet es deshalb nicht.

Dieses Modul unterscheidet beide Formate nach dem Gerätetyp, wie
python-broadlink. Neuere unbekannte Typen (ab 0x5000) behandelt es wie RM4.

## Voraussetzungen

- IP-Symcon ab 8.0, geschrieben für 9.0 auf einer SymBox
- PHP-Erweiterungen **openssl** und **sockets** (bei Symcon dabei)
- Broadlink RM4 pro oder RM4 mini, in der Broadlink-App eingerichtet und
  **entsperrt** („Gerät sperren“ aus). Ein gesperrtes Gerät lehnt jede
  lokale Anmeldung ab. Die Suche zeigt es in der Spalte „Gesperrt“ an.
- UDP-Port 80 von Symcon zum Gerät offen (Firewall zwischen den Netzen)

Geschrieben für RM4 pro und RM4 mini; das Protokoll entspricht dem früheren
Skript, das mit beiden lief. Ältere RM-Geräte sind vorgesehen, aber nicht
ausprobiert.

## Netz: Broadcast oder Adressbereich

Die Broadlink-App und python-broadlink suchen per Broadcast. Der erreicht
nur das Netz, in dem Symcon steht; Router reichen ihn nicht weiter. Hängen
die Fernbedienungen in einem anderen Netz (etwa einem eigenen WLAN für
Geräte), findet der Broadcast sie nicht.

Der Konfigurator kann deshalb zusätzlich einen **Adressbereich** abfragen.
Er schickt dann an jede Adresse ein Hallo-Paket, z. B. `192.168.1.0/24`,
`192.168.1.20-40` oder einzelne Adressen, durch Komma getrennt (höchstens
1024 Adressen). Der Bereich steht nur in der Instanz.

Ohne Suche lässt sich die Adresse in der Geräte-Instanz auch von Hand
eintragen. Am zuverlässigsten ist eine **feste Adresse im Router**. Die
Ersatzsuche über die MAC (wenn die Adresse nicht antwortet) läuft per
Broadcast und hilft deshalb nur im selben Netz.

## Einrichtung

1. In der **Modulsteuerung** (Kern-Instanzen → Modules) diese URL hinzufügen:
   `https://github.com/HeikoSchroers/SymconBroadlink`
2. Eine Instanz **Broadlink Configurator** anlegen. Steht Symcon in einem
   anderen Netz, den Adressbereich der Fernbedienungen eintragen und
   **Änderungen übernehmen**. Die Liste zeigt die gefundenen Geräte. Mit
   **Erstellen** entsteht die Geräte-Instanz mit Adresse und MAC.
3. In der Geräte-Instanz **Verbindung testen**. Die Antwort nennt Name, Typ
   und Protokoll.
4. Unter **Lernen** einen Namen eintragen (z. B. „TV an“), **Lernen (30 s)**
   klicken und die Taste der Original-Fernbedienung auf das Broadlink-Gerät
   richten. Die Zeile darüber meldet „… gelernt“ oder den Fehler.
5. Optional unter **Befehls-Schalter** die Befehle ankreuzen, die einen
   eigenen Schalter bekommen sollen, und **Änderungen übernehmen**.

## Codes aus dem früheren Broadlink-Skript

Das Skript „Broadlink“ legte gelernte Codes als String-Variablen unter sich
ab, Wert als JSON `{"geraet": "RM4 pro", "code": "<base64>"}`. Unter
**Codes aus dem alten Broadlink-Skript übernehmen** das Skript wählen, den
Gerätenamen aus dem Skript eintragen (damit jede Instanz nur ihre Codes
bekommt) und **Übernehmen** klicken. Gleichnamige Befehle bleiben
unverändert. Im Skript wird nichts gelöscht; das Aufräumen bleibt Handarbeit.

## Variablen

| Variable | Typ | Bedeutung |
|---|---|---|
| je angekreuztem Befehl | Schalter | Ein sendet den Code, danach fällt der Schalter auf Aus (Taster) |

Weitere Variablen legt das Modul nicht an. Die Codes stehen als Attribut in
der Instanz, nicht in Variablen. Sie erscheinen damit weder in der
Visualisierung noch im Archiv.

## Funktionen

| Funktion | Bedeutung |
|---|---|
| `BL_SendCommand($id, 'TV an')` | gelernten Befehl senden |
| `BL_SendSamsung($id, 0xE0E09966)` | Samsung-Code senden (32 Bit wie in Code-Sammlungen) |
| `BL_SendRaw($id, $Code)` | Broadlink-Code (Base64 oder Hex) senden, ohne ihn zu speichern |
| `BL_Learn($id, 'TV an')` | lernen und speichern; blockiert bis zu 30 s |
| `BL_StartLearning($id, 'TV an')` | lernen im Hintergrund (wie der Knopf im Formular) |
| `BL_SetCommand($id, 'TV an', $Code)` | Code von Hand speichern (Base64 oder Hex) |
| `BL_DeleteCommand($id, 'TV an')` | Befehl löschen |
| `BL_GetCommands($id)` | gelernte Befehle als Name => Code (Base64) |
| `BL_ImportScriptVariables($id, $SkriptID, 'RM4 pro')` | Codes des alten Skripts übernehmen; liefert die Anzahl |
| `BL_TestConnection($id)` | anmelden und Name, Typ, Adresse als Text liefern |

Die Sende- und Lernfunktionen liefern `true` bei Erfolg. Bei einem Fehler
melden sie eine Warnung und liefern `false`. Jeder Aufruf meldet sich neu am
Gerät an; Senden dauert damit unter einer Sekunde.

`BLC_Search($idKonfigurator)` liefert die gefundenen Geräte, auch ohne
Instanz.

## Eigenheiten

- **Nur IR.** RF (433 MHz beim RM4 pro) braucht einen eigenen Lernablauf
  mit Frequenzsuche und ist nicht eingebaut. Gelernte RF-Codes aus anderen
  Werkzeugen lassen sich aber mit `BL_SetCommand` speichern und senden.
- **Wiederholung bei Funkstörung.** Antwortet das Gerät nicht, schickt das
  Modul dasselbe Paket bis zu zweimal erneut (wie python-broadlink). Ging
  nur die Antwort verloren, sendet das Gerät den Code doppelt. Bei
  getrennten Ein/Aus-Codes schadet das nicht; bei Umschalt-Codes (eine Taste
  für Ein und Aus) kann es stören.
- **Samsung-Codes** gehen mit einer Wiederholung raus (der Rahmen zweimal),
  damit der Fernseher sie sicher erkennt.
- **Ping:** Broadlink-Geräte ab Firmware 53 starten neu, wenn sie länger
  weder die Cloud noch einen Ping sehen. Wer die Cloud sperrt, schaltet in
  der Instanz den Ping ein (jede Minute, ohne Anmeldung).
- Der Sitzungsschlüssel, den das Gerät bei der Anmeldung vergibt, bleibt im
  Speicher und wird nicht gespeichert.

## Herkunft

Das Protokoll ist aus [python-broadlink](https://github.com/mjg59/python-broadlink)
(MIT-Lizenz, Copyright (c) 2014 Mike Ryan, Copyright (c) 2016 Matthew
Garrett) nach PHP nachgebaut, nur der benötigte Teil: Hallo-Paket, Anmeldung
mit AES-128-CBC, IR-Befehle in beiden Formaten (RM4 und älteres RM), Ping
und die Pulsdarstellung der IR-Codes. Der feste Startschlüssel und das IV
der Anmeldung stammen von dort. Sie sind öffentlich und kein Geheimnis.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Der Lizenztext von python-broadlink steht dort mit.
