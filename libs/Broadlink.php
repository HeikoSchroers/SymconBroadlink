<?php

declare(strict_types=1);

/**
 * Lokales UDP-Protokoll der Broadlink-Fernbedienungen (RM-Reihe): Suche,
 * Anmeldung und IR-Befehle, ohne Cloud.
 *
 * Nachgebaut nach python-broadlink (https://github.com/mjg59/python-broadlink,
 * MIT-Lizenz, Copyright (c) 2014 Mike Ryan, Copyright (c) 2016 Matthew
 * Garrett), nur der benoetigte Teil:
 * - Hallo-Paket 0x06 (Suche, Typ, MAC, Sperre)
 * - Anmeldung 0x65 mit dem festen Startschluessel, danach AES-128-CBC mit
 *   dem Sitzungsschluessel des Geraets
 * - Befehle 0x6A: 0x02 senden, 0x03 Lernmodus, 0x04 gelernten Code holen;
 *   die RM4-Reihe und das neuere RM mini 3 erwarten davor eine Laenge
 * - Ping 0x01 (fuettert den Watchdog ab Firmware 53, wenn die Cloud fehlt)
 *
 * Schluessel und IV unten sind der oeffentliche Startschluessel aus
 * python-broadlink, kein Geheimnis. Den Sitzungsschluessel vergibt das
 * Geraet bei jeder Anmeldung neu; er bleibt im Speicher.
 *
 * Braucht die PHP-Erweiterungen openssl und sockets.
 */

namespace SymconBroadlink;

use Exception;

final class Protocol
{
    public const PORT = 80;
    public const START_KEY = '097628343fe99e23765c1513accf8b02';
    public const IV = '562e17996d093d28ddb3ba695a2e6f58';

    // Typen mit Laengenpraefix vor dem Befehl (python-broadlink: rmminib,
    // rm4mini, rm4pro). Aeltere RM (0x27xx) senden ohne.
    private const MIT_LAENGE = [
        0x5F36, 0x6507, 0x6508,
        0x51DA, 0x5209, 0x520C, 0x520D, 0x5211, 0x5212, 0x5216, 0x521C, 0x6070,
        0x610E, 0x610F, 0x62BC, 0x62BE, 0x6364, 0x648D, 0x6539, 0x653A,
        0x520B, 0x5213, 0x5218, 0x6026, 0x6184, 0x61A2, 0x649B, 0x653C,
    ];

    private const NAMEN = [
        0x520B => 'RM4 pro', 0x5213 => 'RM4 pro', 0x6026 => 'RM4 pro', 0x61A2 => 'RM4 pro',
        0x649B => 'RM4 pro', 0x653C => 'RM4 pro', 0x5218 => 'RM4C pro', 0x6184 => 'RM4C pro',
        0x51DA => 'RM4 mini', 0x520C => 'RM4 mini', 0x5216 => 'RM4 mini', 0x521C => 'RM4 mini',
        0x610E => 'RM4 mini', 0x62BC => 'RM4 mini', 0x648D => 'RM4 mini', 0x653A => 'RM4 mini',
        0x520D => 'RM4C mini', 0x6070 => 'RM4C mini', 0x610F => 'RM4C mini', 0x62BE => 'RM4C mini',
        0x6539 => 'RM4C mini', 0x5209 => 'RM4 TV mate', 0x5212 => 'RM4 TV mate', 0x5211 => 'RM4C mate',
        0x6364 => 'RM4S', 0x5F36 => 'RM mini 3', 0x6507 => 'RM mini 3', 0x6508 => 'RM mini 3',
    ];

    // Fehlercodes im Kopf der Antwort (python-broadlink exceptions.py)
    private const FEHLER = [
        -1 => 'Anmeldung abgelehnt', -2 => 'abgemeldet', -3 => 'Gerät offline',
        -4 => 'Befehl nicht unterstützt', -5 => 'Speicher voll', -7 => 'Schlüssel abgelaufen',
        -8 => 'Sendefehler', -10 => 'Lesefehler (noch kein Code)',
    ];

    public static function typeName(int $typ): string
    {
        if (isset(self::NAMEN[$typ])) {
            return self::NAMEN[$typ];
        }
        return $typ >= 0x2712 && $typ <= 0x27DE ? sprintf('RM (0x%04X)', $typ) : sprintf('0x%04X', $typ);
    }

    /** RM4-Format mit Laengenpraefix? Unbekannte neuere Typen (ab 0x5000) wie RM4. */
    public static function withLength(int $typ): bool
    {
        return in_array($typ, self::MIT_LAENGE, true) || $typ >= 0x5000;
    }

    public static function errorText(int $code): string
    {
        return (self::FEHLER[$code] ?? 'Fehler') . ' (' . $code . ')';
    }

    public static function checksum(string $daten): int
    {
        return (0xbeaf + array_sum(unpack('C*', $daten) ?: [])) & 0xffff;
    }

    public static function hello(): string
    {
        $p = str_repeat("\0", 0x30);
        $p[0x26] = "\x06";
        return substr_replace($p, pack('v', self::checksum($p)), 0x20, 2);
    }

    public static function ping(): string
    {
        $p = str_repeat("\0", 0x30);
        $p[0x26] = "\x01";
        return $p;
    }

    /**
     * Antwort auf das Hallo-Paket auswerten:
     * ['host', 'type', 'mac' (aa:bb:..), 'macRaw', 'name', 'locked'] oder null.
     */
    public static function parseHello(string $antwort, string $host): ?array
    {
        if (strlen($antwort) < 0x40) {
            return null;
        }
        $roh = substr($antwort, 0x3a, 6);
        $name = substr($antwort, 0x40, 0x3f);
        $name = substr($name, 0, (int) strcspn($name, "\0"));
        return [
            'host'   => $host,
            'type'   => unpack('v', substr($antwort, 0x34, 2))[1],
            'mac'    => implode(':', str_split(bin2hex(strrev($roh)), 2)),
            'macRaw' => $roh,
            'name'   => mb_check_encoding($name, 'UTF-8') ? $name : '',
            // python-broadlink: letztes Byte der Antwort
            'locked' => ord($antwort[strlen($antwort) - 1]) != 0,
        ];
    }

    public static function normalizeMac(string $mac): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac));
        return strlen($hex) == 12 ? implode(':', str_split($hex, 2)) : '';
    }
}

final class Discovery
{
    /**
     * Sucht Broadlink-Geraete: Broadcast ins eigene Netz und/oder je eine
     * Anfrage an jede Adresse aus $bereiche (Router reichen Broadcasts nicht
     * weiter). Liefert die Antworten aus Protocol::parseHello, je MAC einmal.
     *
     * $bereiche: "192.168.1.0/24", "192.168.1.20-40", "192.168.1.7",
     * mehrere durch Komma oder Leerzeichen getrennt.
     */
    public static function search(string $bereiche, bool $broadcast, float $sekunden = 2.0): array
    {
        $ziele = self::addresses($bereiche);
        if ($broadcast) {
            $ziele[] = '255.255.255.255';
        }
        if (!$ziele) {
            return [];
        }

        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($s === false) {
            throw new Exception('UDP-Socket: ' . socket_strerror(socket_last_error()));
        }
        socket_set_option($s, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_nonblock($s);
        $hallo = Protocol::hello();
        $gefunden = [];
        try {
            // Zweimal senden: einzelne Pakete gehen im WLAN verloren
            for ($runde = 0; $runde < 2; $runde++) {
                foreach ($ziele as $ip) {
                    @socket_sendto($s, $hallo, strlen($hallo), 0, $ip, Protocol::PORT);
                }
                $ende = microtime(true) + $sekunden / 2;
                while (($rest = $ende - microtime(true)) > 0) {
                    $lesen = [$s];
                    $w = $e = null;
                    if (!@socket_select($lesen, $w, $e, 0, (int) ($rest * 1e6))) {
                        continue;
                    }
                    $daten = '';
                    $von = '';
                    $port = 0;
                    while (@socket_recvfrom($s, $daten, 2048, 0, $von, $port)) {
                        $g = Protocol::parseHello($daten, $von);
                        if ($g !== null) {
                            $gefunden[$g['mac']] = $g;
                        }
                    }
                }
            }
        } finally {
            socket_close($s);
        }
        return array_values($gefunden);
    }

    /** Adressbereiche in einzelne IPv4-Adressen aufloesen (hoechstens 1024). */
    public static function addresses(string $bereiche): array
    {
        $liste = [];
        foreach (preg_split('/[\s,;]+/', trim($bereiche), -1, PREG_SPLIT_NO_EMPTY) as $teil) {
            if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $teil, $m) && ip2long($m[1]) !== false && $m[2] <= 32) {
                if ($m[2] < 22) {
                    throw new Exception('Adressbereich ' . $teil . ' zu groß (höchstens /22 = 1024 Adressen)');
                }
                $maske = $m[2] == 32 ? 0xffffffff : (0xffffffff << (32 - $m[2])) & 0xffffffff;
                $netz = ip2long($m[1]) & $maske;
                $anzahl = 1 << (32 - $m[2]);
                for ($i = $anzahl > 2 ? 1 : 0; $i < ($anzahl > 2 ? $anzahl - 1 : $anzahl); $i++) {
                    $liste[] = long2ip($netz + $i);
                }
            } elseif (preg_match('#^(\d+\.\d+\.\d+\.)(\d+)-(\d+)$#', $teil, $m) && $m[2] <= $m[3] && $m[3] <= 255) {
                for ($i = (int) $m[2]; $i <= (int) $m[3]; $i++) {
                    $liste[] = $m[1] . $i;
                }
            } elseif (ip2long($teil) !== false) {
                $liste[] = $teil;
            } else {
                throw new Exception('Adressbereich nicht verstanden: ' . $teil . ' (Beispiele: 192.168.1.0/24, 192.168.1.20-40, 192.168.1.7)');
            }
        }
        $liste = array_values(array_unique($liste));
        if (count($liste) > 1024) {
            throw new Exception('Adressbereich zu groß (höchstens 1024 Adressen)');
        }
        return $liste;
    }
}

/** Eine Verbindung zu einem RM-Geraet: Hallo, Anmeldung, Befehle. */
final class Device
{
    /** @var callable|null fn(string $text) */
    public $debug = null;

    public array $info = [];

    private string $ip;
    private string $schluessel;
    private int $id = 0;
    private int $zaehler;
    private int $typ;
    private string $macRoh = '';
    /** @var resource|\Socket|null */
    private $socket = null;

    public function __construct(string $host, private int $typVorgabe = 0, private float $wartezeit = 2.0)
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new Exception('Adresse ' . $host . ' nicht aufgelöst');
        }
        $this->ip = $ip;
        $this->typ = $typVorgabe;
        $this->schluessel = hex2bin(Protocol::START_KEY);
        $this->zaehler = random_int(0x8000, 0xffff);
    }

    public function __destruct()
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
        }
    }

    /** Hallo und Anmeldung. $info danach wie Protocol::parseHello. */
    public function connect(): array
    {
        $antwort = $this->udp(Protocol::hello());
        $info = $antwort === null ? null : Protocol::parseHello($antwort, $this->ip);
        if ($info === null) {
            throw new NotReachableException('Broadlink unter ' . $this->ip . ' antwortet nicht');
        }
        $this->info = $info;
        $this->macRoh = $info['macRaw'];
        if ($this->typVorgabe == 0) {
            $this->typ = $info['type'];
        }
        $this->log(sprintf('Hallo: %s, Typ 0x%04X, MAC %s%s', $info['name'], $info['type'], $info['mac'], $info['locked'] ? ', gesperrt' : ''));
        if ($info['locked']) {
            throw new LockedException('Gerät ist gesperrt - in der Broadlink-App „Gerät sperren“ ausschalten');
        }

        $anmeldung = str_repeat("\0", 0x50);
        $anmeldung = substr_replace($anmeldung, str_repeat('1', 16), 0x04, 16);
        $anmeldung[0x1e] = "\x01";
        $anmeldung[0x2d] = "\x01";
        $anmeldung = substr_replace($anmeldung, 'Symcon', 0x30, 6);
        [$daten, $fehler] = $this->packet(0x65, $anmeldung);
        if ($fehler !== 0 || strlen($daten) < 20) {
            throw new AuthException('Anmeldung fehlgeschlagen: ' . Protocol::errorText($fehler), $fehler);
        }
        $this->id = unpack('V', substr($daten, 0, 4))[1];
        $this->schluessel = substr($daten, 4, 16);
        return $info;
    }

    /** IR- oder RF-Code (Broadlink-Format, binaer) senden. */
    public function send(string $code): void
    {
        $this->rm(0x02, $code);
    }

    public function enterLearning(): void
    {
        $this->rm(0x03);
    }

    /** Gelernten Code holen; null, solange noch keiner da ist. */
    public function checkData(): ?string
    {
        try {
            $code = $this->rm(0x04);
        } catch (DeviceException $e) {
            // -10 Lesefehler, -5 Speicher: noch keine Taste empfangen
            if (in_array($e->getCode(), [-10, -5], true)) {
                return null;
            }
            throw $e;
        }
        return $code === '' ? null : $code;
    }

    /**
     * Ping ohne Anmeldung und ohne Antwort. Ab Firmware 53 startet das
     * Geraet neu, wenn es laengere Zeit weder Cloud noch Ping sieht
     * (python-broadlink device.ping, empfohlen alle 2 Minuten).
     */
    public function ping(): void
    {
        $this->socket();
        $p = Protocol::ping();
        @socket_sendto($this->socket, $p, strlen($p), 0, $this->ip, Protocol::PORT);
    }

    private function rm(int $befehl, string $daten = ''): string
    {
        $laenge = Protocol::withLength($this->typ);
        $nutz = $laenge ? pack('vV', strlen($daten) + 4, $befehl) . $daten : pack('V', $befehl) . $daten;
        [$antwort, $fehler] = $this->packet(0x6a, $nutz);
        if ($fehler !== 0) {
            throw new DeviceException(sprintf('Befehl 0x%02X: %s', $befehl, Protocol::errorText($fehler)), $fehler);
        }
        if ($laenge) {
            $n = unpack('v', substr($antwort, 0, 2))[1];
            return (string) substr($antwort, 6, max(0, $n - 4));
        }
        return (string) substr($antwort, 4);
    }

    /** Paket senden; liefert [entschluesselte Nutzdaten, Fehlercode]. */
    private function packet(int $befehl, string $nutz): array
    {
        $this->zaehler = ($this->zaehler + 1) & 0xffff;
        $p = str_repeat("\0", 0x38);
        $p = substr_replace($p, hex2bin('5aa5aa555aa5aa55'), 0, 8);
        $p = substr_replace($p, pack('v', $this->typ), 0x24, 2);
        $p = substr_replace($p, pack('v', $befehl), 0x26, 2);
        $p = substr_replace($p, pack('v', $this->zaehler), 0x28, 2);
        $p = substr_replace($p, $this->macRoh, 0x2a, 6);
        $p = substr_replace($p, pack('V', $this->id), 0x30, 4);
        $p = substr_replace($p, pack('v', Protocol::checksum($nutz)), 0x34, 2);
        if (strlen($nutz) % 16) {
            $nutz .= str_repeat("\0", 16 - strlen($nutz) % 16);
        }
        $p .= openssl_encrypt($nutz, 'AES-128-CBC', $this->schluessel, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, hex2bin(Protocol::IV));
        $p = substr_replace($p, pack('v', Protocol::checksum($p)), 0x20, 2);

        $antwort = $this->udp($p);
        if ($antwort === null || strlen($antwort) < 0x38) {
            throw new NotReachableException(sprintf('keine Antwort auf Befehl 0x%02X', $befehl));
        }
        $fehler = unpack('s', pack('S', unpack('v', substr($antwort, 0x22, 2))[1]))[1];
        $daten = '';
        if (strlen($antwort) > 0x38) {
            $daten = (string) openssl_decrypt(substr($antwort, 0x38), 'AES-128-CBC', $this->schluessel, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, hex2bin(Protocol::IV));
        }
        return [$daten, $fehler];
    }

    /**
     * Bis zu drei Versuche mit demselben Paket (gleicher Zaehler), wie
     * python-broadlink: Die Geraete haengen im WLAN, einzelne Pakete gehen
     * verloren. Antworten von anderen Adressen werden verworfen.
     */
    private function udp(string $paket): ?string
    {
        $this->socket();
        for ($versuch = 0; $versuch < 3; $versuch++) {
            @socket_sendto($this->socket, $paket, strlen($paket), 0, $this->ip, Protocol::PORT);
            $ende = microtime(true) + $this->wartezeit;
            while (($rest = $ende - microtime(true)) > 0) {
                $lesen = [$this->socket];
                $w = $e = null;
                if (!@socket_select($lesen, $w, $e, 0, (int) ($rest * 1e6))) {
                    continue;
                }
                $daten = '';
                $von = '';
                $port = 0;
                if (@socket_recvfrom($this->socket, $daten, 2048, 0, $von, $port) && $von === $this->ip) {
                    return $daten;
                }
            }
            if ($versuch < 2) {
                $this->log('keine Antwort, neuer Versuch');
            }
        }
        return null;
    }

    private function socket(): void
    {
        if ($this->socket === null) {
            $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($s === false) {
                throw new Exception('UDP-Socket: ' . socket_strerror(socket_last_error()));
            }
            $this->socket = $s;
        }
    }

    private function log(string $text): void
    {
        if ($this->debug) {
            ($this->debug)($text);
        }
    }
}

/** IR-Codes im Broadlink-Format bauen und einlesen. */
final class Ir
{
    private const TAKT = 32.84;   // Mikrosekunden je Takt (python-broadlink pulses_to_data)

    /**
     * IR-Code aus Puls- und Pausenlaengen in Mikrosekunden. $wiederholungen
     * sendet den Rahmen zusaetzlich so oft erneut.
     */
    public static function fromPulses(array $pulse, int $wiederholungen = 0): string
    {
        $d = '';
        foreach ($pulse as $us) {
            $takte = (int) ($us / self::TAKT);
            $d .= $takte > 255 ? "\0" . pack('n', $takte) : chr($takte);
        }
        return "\x26" . chr(max(0, min(255, $wiederholungen))) . pack('v', strlen($d)) . $d;
    }

    /**
     * Samsung-IR-Code (32 Bit in der Schreibweise der Code-Sammlungen,
     * z. B. 0xE0E09966). Kopf 4500/4500 us, Bits MSB zuerst. Der Rahmen
     * geht zweimal raus; bei getrennten Ein/Aus-Codes schadet das nicht.
     */
    public static function samsung(int $code): string
    {
        $p = [4500, 4500];
        for ($bit = 31; $bit >= 0; $bit--) {
            $p[] = 560;
            $p[] = ($code >> $bit) & 1 ? 1690 : 560;
        }
        $p[] = 560;
        $p[] = 47000;
        return self::fromPulses($p, 1);
    }

    /** Code als Base64 oder Hex (wie aus python-broadlink) in Binaerdaten. */
    public static function decode(string $code): string
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($code !== '' && strlen($code) % 2 == 0 && ctype_xdigit($code)) {
            $bin = hex2bin($code);
        } else {
            $bin = base64_decode($code, true);
        }
        if ($bin === false || strlen($bin) < 4) {
            throw new Exception('Code ist weder Base64 noch Hex');
        }
        return $bin;
    }
}

class DeviceException extends Exception
{
}

final class NotReachableException extends DeviceException
{
}

final class LockedException extends DeviceException
{
}

final class AuthException extends DeviceException
{
}
