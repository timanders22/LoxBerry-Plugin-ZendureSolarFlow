# LoxBerry-Plugin: Zendure SolarFlow

Bindet **Zendure SolarFlow** an Loxone an — **ohne Cloud, ohne Zendure-Konto**.
Unterstützt beide lokalen Wege: die HTTP-Schnittstelle der neueren Geräte und
lokales MQTT für die ältere Reihe.

> **Fassung 0.9.0 — ungeprüft.** Das Plugin wurde ohne Zendure-Gerät gebaut.
> Aufbau, Sprachdateien, Endpunkt und Oberfläche sind geprüft; ob die
> Eigenschaftsnamen der eigenen Firmware passen und ob die Schreibbefehle am
> Gerät wirken, ist es **nicht**. Deshalb 0.9.0 und nicht 1.0.0.
>
> Die Selbstaktualisierung zeigt auf dieses Repository und ist eingeschaltet.
> Bei gleicher Fassung wird niemandem ein Update angeboten; sobald 1.0.0
> erscheint, greift sie von selbst.

## Zwei Wege, beide lokal

| | HTTP | MQTT |
|---|---|---|
| Geräte | SolarFlow 800 / 800 Plus / 800 Pro, AC-Reihe | Hub 1200, Hub 2000, Hyper 2000, Ace 1500, AIO 2400 |
| Einrichtung | IP-Adresse eintragen, fertig | Gerät einmalig auf den LoxBerry-Broker umstellen |
| Abruf | `GET http://<ip>/properties/report` | Gerät meldet von selbst |
| Schreiben | `POST http://<ip>/properties/write` | `iot/<Produktschlüssel>/<Gerätekennung>/properties/write` bzw. `.../function/invoke` |
| Zendure-App | läuft weiter | verliert die Verbindung zu diesem Gerät |

Ein Gerät kann entweder Cloud oder lokal, nicht beides.

## Reines PHP

Kein Python, keine virtuelle Umgebung, kein Umweg um PEP 668. Gebraucht werden
nur `mosquitto_sub` und `mosquitto_pub` — und die auch nur, wenn mindestens ein
Gerät über MQTT läuft. Die Broker-Zugangsdaten stehen dabei **nicht** auf der
Kommandozeile, sondern in der Vorgabedatei von mosquitto mit den Rechten 0600;
auf der Kommandozeile stünden sie in der Prozessliste.

## Aufbau

    bin/zendure_dienst.php    Abrufdienst: HTTP-Abruf, MQTT-Horcher,
                              Befehlswarteschlange, MQTT-Publish, Selbsttest
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    webfrontend/htmlauth/     Bedienoberfläche (fünf Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + gemeinsame Bibliothek

Drei Aufgaben, drei Dateien. Weder Oberfläche noch Endpunkt sprechen je selbst
mit einem Gerät — sie lesen den Zwischenspeicher und legen Befehle in einer
Warteschlange ab, die der Dienst im Sekundentakt abarbeitet.

## Vier Befehlssätze

Die Geräte nehmen Steuerbefehle in **unterschiedlicher Form** entgegen. Ein
falscher Satz führt nicht zu einer Fehlermeldung — es passiert schlicht nichts.
Jede Form ist im Quelltext der offiziellen Home-Assistant-Integration
nachgesehen:

| Satz | Form | Geräte |
|---|---|---|
| `zensdk` | `properties/write` mit `smartMode`, `acMode`, `outputLimit`, `inputLimit` | SolarFlow 800 / 2400 |
| `hyper2000` | `function/invoke` `deviceAutomation`, `autoModelValue` als Objekt, Laden über `autoModelProgram 1` mit Preisliste | Hyper 2000 |
| `ace_aio` | wie oben, aber Laden über `autoModelProgram 2` ohne Preisliste | Ace 1500, AIO 2400 |
| `hub` | `autoModelValue` als **blosse Zahl** statt als Objekt; kein Netzladen | Hub 1200 |

Nicht nachgesehen und deshalb **nicht** in der Modelltabelle: Hub 2000,
SolarFlow 1600 AC+, SolarFlow 4000 AC+. Für diese wird der Satz von Hand
gewählt und im Reiter *Test* durchprobiert.

## Schreibbremse — bitte nicht abschalten

Die offizielle Integration merkt zur Ace 1500 an, dass jeder Schreibvorgang im
Flash des Geräts landet und ein ungebremster Regelkreis dessen
Schreibfestigkeit binnen Monaten aufbrauchen würde. Dieses Plugin lässt deshalb
für **alle** Geräte höchstens einen Befehl je 30 s durch und rastert Sollwerte
auf 50 W. Die Rasterung wird in der Antwort gemeldet, nicht verschwiegen.

**Folge für Loxone:** Der Sendetakt muss länger sein als die Bremse. 60 s gegen
30 s Bremse ist ein sicheres Verhältnis.

## Kein Watchdog

Zendure stoppt nicht von selbst, wenn Loxone schweigt — ein gesetzter Sollwert
bleibt stehen. Wer das nicht will, lässt Loxone vor dem geplanten Herunterfahren
einmal `aktion=aus` senden.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&geraet=N` | `ZENDURE;OK=..;SOC=..;SOCMIN=..;SOCMAX=..;PV=..;HAUS=..;NETZ=..;LADEN=..;ENTLADEN=..;BATP=..;GRENZEAUS=..;GRENZEEIN=..;ACMODUS=..;PACKS=..;DVOLT=..;TEMP=..;ALTER=..` |
| `?token=T&aktion=packs&geraet=N` | Werte je Akkupack |
| `?token=T&aktion=liste` | alle eingerichteten Geräte |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=entladen&watt=W` | Abgabe ins Hausnetz vorgeben |
| `?token=T&aktion=laden&watt=W` | aus dem Netz laden |
| `?token=T&aktion=aus` | Regie an das Gerät zurückgeben |
| `?token=T&aktion=socmin&prozent=P` | untere Ladezustandsgrenze |
| `?token=T&aktion=socmax&prozent=P` | obere Ladezustandsgrenze |
| `?token=T&aktion=abruf` | sofort abrufen |

**Ein Strich als Wert** heißt: das Gerät hat dieses Feld nicht geliefert. Es
wird bewusst keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone
behält dann den letzten gültigen Wert; deshalb gehören `ALTER` und `OK` immer
mit ausgewertet.

Schaltende Aufrufe antworten mit `SET;OK=…`: `1` erledigt, `0` abgelehnt (mit
Grund), `2` eingereiht, aber ohne Antwort in der Wartezeit.

## Nicht belegte Einheiten

Temperatur und Zellspannung reicht die Home-Assistant-Integration unverändert
durch — in welcher Einheit sie kommen, steht nirgends. Das Plugin zeigt sie
deshalb ab Werk **roh** an und bietet im Reiter *Einstellungen* zwei
Umrechnungen zur Auswahl, die man nach einem Blick auf den Rohwert einschaltet.

## Datenschutz

Keine persönlichen Daten im Plugin, keine Verbindung nach außen. Alles bleibt
im Heimnetz.

## Lizenz

MIT — siehe [LICENSE](LICENSE). Alle Protokollangaben stammen aus
[Zendure/Zendure-HA](https://github.com/Zendure/Zendure-HA) (ebenfalls MIT).
