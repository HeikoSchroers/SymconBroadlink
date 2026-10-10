<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Broadlink.php';

use SymconBroadlink\AuthException;
use SymconBroadlink\Device;
use SymconBroadlink\Discovery;
use SymconBroadlink\Ir;
use SymconBroadlink\LockedException;
use SymconBroadlink\NotReachableException;
use SymconBroadlink\Protocol;

/**
 * Broadlink-Fernbedienung der RM-Reihe (RM4 pro, RM4 mini, aeltere RM):
 * IR-Codes lernen, speichern und senden, lokal per UDP ohne Cloud.
 *
 * Jeder Befehl meldet sich neu am Geraet an (Hallo, Anmeldung, Befehl;
 * zusammen unter einer Sekunde). Den Sitzungsschluessel vergibt das Geraet
 * dabei jedes Mal neu, er wird nirgends gespeichert.
 *
 * Gelernte Codes stehen im Attribut "Codes" (Name => Base64). Auf Wunsch
 * bekommt jeder Befehl eine Schalter-Variable, die beim Einschalten den
 * Code sendet und danach wieder auf Aus faellt (Taster, z. B. fuer die
 * HomeKit-Bridge).
 *
 * Lernen wartet bis zu 30 s auf eine Taste. Aus dem Formular laeuft es
 * deshalb in einem eigenen Thread (RunLearning), damit die Konsole nicht
 * haengt.
 */
class BroadlinkDevice extends IPSModule
{
    // Darstellung von IP-Symcon (/usr/share/symcon/presentations/)
    private const P_SCHALTER = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';

    private const LERNZEIT = 30;

    // Status der Instanz (form.json)
    private const S_NICHT_ERREICHBAR = 201;
    private const S_GESPERRT = 202;
    private const S_ABGELEHNT = 203;

    private string $letzterFehler = '';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('DeviceType', 0);
        $this->RegisterPropertyString('MAC', '');
        $this->RegisterPropertyBoolean('KeepAlive', false);
        $this->RegisterPropertyString('CommandSwitches', '[]');

        $this->RegisterAttributeString('Codes', '{}');
        $this->RegisterAttributeString('LearnInfo', '');
        $this->RegisterAttributeString('FoundAddress', '');

        $this->RegisterTimer('Ping', 0, 'BL_Ping($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Adresse geaendert oder Neustart: erst wieder die eingetragene versuchen
        $this->WriteAttributeString('FoundAddress', '');

        $this->registerVariables();
        $this->SetTimerInterval('Ping', $this->ReadPropertyBoolean('KeepAlive') ? 60 * 1000 : 0);

        if ($this->ReadPropertyString('Host') === '' && $this->ReadPropertyString('MAC') === '') {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if (str_starts_with($Ident, 'CMD_')) {
            foreach (array_keys($this->codes()) as $name) {
                $name = (string) $name;
                if (self::schalterIdent($name) === $Ident) {
                    if ($Value) {
                        $this->SetValue($Ident, true);
                        $this->SendCommand($name);
                    }
                    // Taster: faellt nach dem Senden wieder auf Aus
                    $this->SetValue($Ident, false);
                    return;
                }
            }
            throw new Exception('Befehl zu ' . $Ident . ' ist nicht mehr gelernt');
        }
        throw new Exception('Unbekannter Ident ' . $Ident);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $codes = $this->codes();

        $liste = [];
        $optionen = [];
        foreach ($codes as $name => $code) {
            $laenge = strlen((string) base64_decode($code));
            $liste[] = ['Name' => $name, 'Bytes' => $laenge, 'Code' => strlen($code) > 48 ? substr($code, 0, 48) . '…' : $code];
            $optionen[] = ['caption' => $name, 'value' => $name];
        }
        if (!$optionen) {
            $optionen[] = ['caption' => '(noch keine Befehle)', 'value' => ''];
        }

        $info = $this->ReadAttributeString('LearnInfo');
        $gefunden = $this->ReadAttributeString('FoundAddress');
        $form['actions'] = $this->formularFuellen($form['actions'], [
            'Commands'     => ['values' => $liste],
            'Command'      => ['options' => $optionen, 'value' => $optionen[0]['value']],
            'LearnInfo'    => ['caption' => $info !== '' ? $info : 'Namen eintragen, „Lernen“ drücken und innerhalb von 30 Sekunden die Taste der Original-Fernbedienung auf das Broadlink-Gerät richten.'],
            'FoundAddress' => ['caption' => 'Zuletzt gefunden unter ' . $gefunden . ' (abweichend von der eingetragenen Adresse)', 'visible' => $gefunden !== ''],
        ]);
        $form['elements'] = $this->formularFuellen($form['elements'], [
            'CommandSwitches' => ['values' => $this->schalterListe()],
        ]);
        return json_encode($form);
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Gelernten Befehl senden. */
    public function SendCommand(string $Name): bool
    {
        $codes = $this->codes();
        if (!isset($codes[$Name])) {
            return $this->fehler('Befehl „' . $Name . '“ ist nicht gelernt', true) ?? false;
        }
        return $this->senden(base64_decode($codes[$Name]), $Name);
    }

    /** Code senden, ohne ihn zu speichern: Base64 oder Hex (Broadlink-Format, wie python-broadlink). */
    public function SendRaw(string $Code): bool
    {
        try {
            $bin = Ir::decode($Code);
        } catch (Exception $e) {
            return $this->fehler($e->getMessage(), true) ?? false;
        }
        return $this->senden($bin, 'Code');
    }

    /** Samsung-IR-Code aus einer Code-Sammlung senden, z. B. 0xE0E09966 (TV ein). */
    public function SendSamsung(int $Code): bool
    {
        return $this->senden(Ir::samsung($Code), sprintf('Samsung 0x%08X', $Code));
    }

    /**
     * Code lernen und unter $Name speichern; wartet bis zu 30 s auf eine
     * Taste und blockiert so lange. Fuer das Formular gibt es StartLearning.
     */
    public function Learn(string $Name): bool
    {
        return $this->lernen($Name);
    }

    /** Lernen im eigenen Thread starten; Fortschritt steht im Formular. */
    public function StartLearning(string $Name): void
    {
        $Name = self::name($Name);
        $this->SetBuffer('LearnName', $Name);
        $this->lernInfo('Starte den Lernmodus für „' . $Name . '“ ...');
        $this->RegisterOnceTimer('Learning', 'BL_RunLearning($_IPS[\'TARGET\']);');
    }

    /** Intern: Lernen im eigenen Thread (gestartet von StartLearning). */
    public function RunLearning(): void
    {
        $name = $this->GetBuffer('LearnName');
        $this->SetBuffer('LearnName', '');
        if ($name !== '') {
            $this->lernen($name);
        }
    }

    /** Code von Hand speichern (Base64 oder Hex); ueberschreibt einen gleichnamigen. */
    public function SetCommand(string $Name, string $Code): bool
    {
        try {
            $Name = self::name($Name);
            $bin = Ir::decode($Code);
        } catch (Exception $e) {
            return $this->fehler($e->getMessage(), true) ?? false;
        }
        $codes = $this->codes();
        $codes[$Name] = base64_encode($bin);
        $this->codesSpeichern($codes);
        return true;
    }

    public function DeleteCommand(string $Name): bool
    {
        $codes = $this->codes();
        if (!isset($codes[$Name])) {
            return $this->fehler('Befehl „' . $Name . '“ ist nicht gelernt', true) ?? false;
        }
        unset($codes[$Name]);
        $this->codesSpeichern($codes);
        return true;
    }

    /** Gelernte Befehle: Name => Code (Base64). */
    public function GetCommands(): array
    {
        return $this->codes();
    }

    /**
     * Einmalige Uebernahme aus dem frueheren Broadlink-Skript: Dessen Codes
     * liegen als String-Variablen unter dem Skript, Wert als JSON
     * {"geraet": "...", "code": "<base64>"}. $Device filtert nach dem
     * Geraetenamen dort (leer = alle). Gleichnamige Befehle bleiben
     * unangetastet; im Skript wird nichts geloescht. Liefert die Anzahl
     * uebernommener Codes.
     */
    public function ImportScriptVariables(int $ScriptID, string $Device): int
    {
        if ($ScriptID <= 0 || !IPS_ScriptExists($ScriptID)) {
            return $this->fehler('Kein Skript ausgewählt', true) ?? 0;
        }
        $codes = $this->codes();
        $neu = 0;
        foreach (IPS_GetChildrenIDs($ScriptID) as $id) {
            if (!IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] != VARIABLETYPE_STRING) {
                continue;
            }
            $eintrag = json_decode(GetValueString($id), true);
            if (!is_array($eintrag) || !isset($eintrag['code']) || base64_decode((string) $eintrag['code'], true) === false) {
                continue;
            }
            if ($Device !== '' && strcasecmp((string) ($eintrag['geraet'] ?? ''), $Device) != 0) {
                continue;
            }
            $name = IPS_GetName($id);
            if (isset($codes[$name])) {
                $this->SendDebug('Import', '„' . $name . '“ gibt es schon, übersprungen', 0);
                continue;
            }
            $codes[$name] = (string) $eintrag['code'];
            $neu++;
        }
        if ($neu > 0) {
            $this->codesSpeichern($codes);
        }
        $this->SendDebug('Import', $neu . ' Codes aus Skript ' . $ScriptID, 0);
        return $neu;
    }

    /** Anmelden und das Ergebnis als Text liefern (ohne Befehl). */
    public function TestConnection(): string
    {
        $info = $this->ausfuehren(fn (Device $d) => $d->info, false);
        if ($info === null) {
            return 'Keine Verbindung: ' . $this->letzterFehler;
        }
        return sprintf("Verbunden mit %s\nTyp %s (0x%04X), %s\nAdresse %s, MAC %s",
            $info['name'] !== '' ? $info['name'] : 'Broadlink',
            Protocol::typeName($info['type']), $info['type'],
            Protocol::withLength($info['type']) ? 'RM4-Protokoll' : 'älteres RM-Protokoll',
            $info['host'], $info['mac']);
    }

    /** Intern: Ping fuer Geraete ab Firmware 53 ohne Cloud (Zeitgeber). */
    public function Ping(): void
    {
        $host = $this->ReadAttributeString('FoundAddress') ?: $this->ReadPropertyString('Host');
        if ($host === '') {
            return;
        }
        try {
            (new Device($host))->ping();
        } catch (Exception $e) {
            $this->SendDebug('Ping', $e->getMessage(), 0);
        }
    }

    // ===================== Senden und Lernen =====================

    private function senden(string $code, string $was): bool
    {
        $ok = $this->ausfuehren(function (Device $d) use ($code) {
            $d->send($code);
            return true;
        }) !== null;
        if ($ok) {
            $this->SendDebug('Senden', $was . ' (' . strlen($code) . ' Bytes)', 0);
        }
        return $ok;
    }

    private function lernen(string $name): bool
    {
        try {
            $name = self::name($name);
        } catch (Exception $e) {
            $this->lernInfo($e->getMessage());
            return false;
        }
        $code = $this->ausfuehren(function (Device $d) use ($name) {
            $d->enterLearning();
            $this->lernInfo('Lernmodus aktiv (LED leuchtet): Taste für „' . $name . '“ jetzt drücken, ' . self::LERNZEIT . ' Sekunden Zeit.');
            $ende = time() + self::LERNZEIT;
            while (time() < $ende) {
                IPS_Sleep(1000);
                $code = $d->checkData();
                if ($code !== null) {
                    return $code;
                }
            }
            throw new Exception('in ' . self::LERNZEIT . ' Sekunden keine Taste empfangen');
        }, true, (self::LERNZEIT + 15) * 1000);

        if ($code === null) {
            $this->lernInfo('Lernen von „' . $name . '“ fehlgeschlagen: ' . $this->letzterFehler);
            return false;
        }
        $codes = $this->codes();
        $codes[$name] = base64_encode($code);
        $this->codesSpeichern($codes);
        $this->lernInfo('„' . $name . '“ gelernt am ' . date('d.m.Y H:i') . ' (' . strlen($code) . ' Bytes).');
        return true;
    }

    // ===================== Verbindung =====================

    /**
     * Fuehrt $befehl auf einem angemeldeten Geraet aus. Gibt dessen Ergebnis
     * zurueck oder null bei Fehler; mit $warnen als Warnung fuer den
     * Aufrufer (Formular, Skript, Visualisierung).
     */
    private function ausfuehren(callable $befehl, bool $warnen = true, int $warten = 15000)
    {
        $sperre = 'BL_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, $warten)) {
            return $this->fehler('Broadlink ist gerade belegt (lernt gerade?)', $warnen);
        }
        try {
            $d = $this->oeffnen();
            $ergebnis = $befehl($d);
            if ($this->GetStatus() != IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }
            return $ergebnis;
        } catch (LockedException $e) {
            $this->SetStatus(self::S_GESPERRT);
            return $this->fehler($e->getMessage(), $warnen);
        } catch (AuthException $e) {
            $this->SetStatus(self::S_ABGELEHNT);
            return $this->fehler($e->getMessage(), $warnen);
        } catch (Exception $e) {
            return $this->fehler($e->getMessage(), $warnen);
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    private function fehler(string $text, bool $warnen)
    {
        $this->letzterFehler = $text;
        $this->SendDebug('Fehler', $text, 0);
        if ($warnen) {
            trigger_error($text, E_USER_WARNING);
        }
        return null;
    }

    /**
     * Angemeldetes Geraet unter der eingetragenen Adresse. Antwortet dort
     * nichts und ist eine MAC eingetragen, sucht die Instanz das Geraet per
     * Broadcast (nur im eigenen Netz) und merkt sich die neue Adresse bis
     * zum naechsten ApplyChanges.
     */
    private function oeffnen(): Device
    {
        $adressen = array_unique(array_filter([$this->ReadAttributeString('FoundAddress'), $this->ReadPropertyString('Host')]));
        $fehler = 'keine Adresse eingetragen';
        foreach ($adressen as $adresse) {
            try {
                return $this->verbinden($adresse);
            } catch (NotReachableException $e) {
                $fehler = $e->getMessage();
            }
        }

        $mac = Protocol::normalizeMac($this->ReadPropertyString('MAC'));
        if ($mac !== '') {
            foreach (Discovery::search('', true, 2.0) as $g) {
                if ($g['mac'] === $mac) {
                    $d = $this->verbinden($g['host']);
                    $this->WriteAttributeString('FoundAddress', $g['host']);
                    $this->SendDebug('Suche', $mac . ' gefunden unter ' . $g['host'], 0);
                    return $d;
                }
            }
            $fehler .= ', per Broadcast nicht gefunden';
        }
        $this->SetStatus(self::S_NICHT_ERREICHBAR);
        throw new Exception('Broadlink nicht erreichbar (' . $fehler . ')');
    }

    private function verbinden(string $host): Device
    {
        $d = new Device($host, $this->ReadPropertyInteger('DeviceType'));
        $d->debug = fn (string $text) => $this->SendDebug('Broadlink', $text, 0);
        $d->connect();
        return $d;
    }

    private function lernInfo(string $text): void
    {
        $this->WriteAttributeString('LearnInfo', $text);
        $this->SendDebug('Lernen', $text, 0);
        $this->UpdateFormField('LearnInfo', 'caption', $text);
    }

    // ===================== Codes und Variablen =====================

    private function codes(): array
    {
        return json_decode($this->ReadAttributeString('Codes'), true) ?: [];
    }

    private function codesSpeichern(array $codes): void
    {
        ksort($codes, SORT_NATURAL | SORT_FLAG_CASE);
        $this->WriteAttributeString('Codes', json_encode($codes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT));

        // Die Schalter-Liste haengt an der Eigenschaft: neue Befehle dort
        // ergaenzen, geloeschte entfernen, die Haken bleiben erhalten.
        $liste = json_encode($this->schalterListe());
        if ($liste !== $this->ReadPropertyString('CommandSwitches')) {
            IPS_SetProperty($this->InstanceID, 'CommandSwitches', $liste);
            IPS_ApplyChanges($this->InstanceID);
        } else {
            $this->registerVariables();
        }
        $this->ReloadForm();
    }

    /** Schalter je angekreuztem Befehl, z. B. fuer die HomeKit-Bridge. */
    private function registerVariables(): void
    {
        $codes = $this->codes();
        $soll = [];
        $pos = 10;
        foreach (json_decode($this->ReadPropertyString('CommandSwitches'), true) ?: [] as $zeile) {
            $name = (string) ($zeile['Name'] ?? '');
            if (empty($zeile['Switch']) || !isset($codes[$name])) {
                continue;
            }
            $ident = self::schalterIdent($name);
            $soll[$ident] = true;
            $this->RegisterVariableBoolean($ident, $name, self::pSchalter('Remote'), $pos++);
            $this->EnableAction($ident);
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $ident = IPS_GetObject($id)['ObjectIdent'];
            if (str_starts_with($ident, 'CMD_') && !isset($soll[$ident])) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    /** Alle gelernten Befehle mit dem Haken aus der Eigenschaft. */
    private function schalterListe(): array
    {
        $haken = [];
        foreach (json_decode($this->ReadPropertyString('CommandSwitches'), true) ?: [] as $zeile) {
            $haken[(string) ($zeile['Name'] ?? '')] = !empty($zeile['Switch']);
        }
        $liste = [];
        foreach (array_keys($this->codes()) as $name) {
            $liste[] = ['Name' => (string) $name, 'Switch' => $haken[$name] ?? false];
        }
        return $liste;
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new Exception('Der Befehl braucht einen Namen (höchstens 64 Zeichen)');
        }
        return $name;
    }

    private static function schalterIdent(string $name): string
    {
        return sprintf('CMD_%08x', crc32($name));
    }

    private static function pSchalter(string $icon): array
    {
        return ['PRESENTATION' => self::P_SCHALTER, 'ICON_TRUE' => $icon, 'ICON_FALSE' => $icon, 'USE_ICON_FALSE' => false, 'USAGE_TYPE' => 0];
    }

    /** Setzt Felder in Formular-Elementen (auch verschachtelt) nach Name. */
    private function formularFuellen(array $elemente, array $werte): array
    {
        foreach ($elemente as &$e) {
            if (isset($e['name'], $werte[$e['name']])) {
                $e = array_merge($e, $werte[$e['name']]);
            }
            if (isset($e['items'])) {
                $e['items'] = $this->formularFuellen($e['items'], $werte);
            }
        }
        return $elemente;
    }
}
