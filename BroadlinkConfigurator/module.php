<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Broadlink.php';

use SymconBroadlink\Discovery;
use SymconBroadlink\Protocol;

/**
 * Sucht Broadlink-Fernbedienungen und legt mit einem Klick die
 * Geraete-Instanz an. Bereits angelegte Instanzen erkennt er an der MAC
 * oder an der Adresse; nicht gefundene stehen trotzdem in der Liste
 * (grau), damit man sie wiederfindet.
 *
 * Der Broadcast erreicht nur das eigene Netz. Fuer Geraete hinter einem
 * Router fragt die Suche jede Adresse aus "SearchRange" einzeln.
 */
class BroadlinkConfigurator extends IPSModule
{
    private const GERAET = '{38C72C7F-C696-400F-90A3-8DF2B00FAC04}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('SearchRange', '');
        $this->RegisterPropertyBoolean('Broadcast', true);
        $this->RegisterPropertyInteger('SearchTime', 3);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['actions'][0]['values'] = $this->eintraege();
        return json_encode($form);
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Gefundene Geraete: [['host', 'type', 'mac', 'name', 'locked'], ...] */
    public function Search(): array
    {
        try {
            $geraete = Discovery::search(
                $this->ReadPropertyString('SearchRange'),
                $this->ReadPropertyBoolean('Broadcast'),
                (float) max(1, min(10, $this->ReadPropertyInteger('SearchTime')))
            );
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            return [];
        }
        foreach ($geraete as &$g) {
            unset($g['macRaw']);
        }
        $this->SendDebug('Suche', json_encode($geraete), 0);
        return $geraete;
    }

    // ===================== Liste =====================

    private function eintraege(): array
    {
        $instanzen = [];
        foreach (IPS_GetInstanceListByModuleID(self::GERAET) as $id) {
            $instanzen[$id] = [
                'mac'  => Protocol::normalizeMac((string) IPS_GetProperty($id, 'MAC')),
                'host' => (string) IPS_GetProperty($id, 'Host'),
            ];
        }

        $werte = [];
        foreach ($this->Search() as $g) {
            $id = 0;
            foreach ($instanzen as $iid => $i) {
                if (($i['mac'] !== '' && $i['mac'] === $g['mac']) || ($i['mac'] === '' && $i['host'] === $g['host'])) {
                    $id = $iid;
                    break;
                }
            }
            if ($id > 0) {
                unset($instanzen[$id]);
            }
            $typ = Protocol::typeName($g['type']);
            $werte[] = [
                'name'       => $g['name'] !== '' ? $g['name'] : $typ,
                'type'       => sprintf('%s (0x%04X)', $typ, $g['type']),
                'address'    => $g['host'],
                'mac'        => $g['mac'],
                'locked'     => $g['locked'] ? 'ja - in der App entsperren' : 'nein',
                'instanceID' => $id,
                'create'     => [
                    'moduleID'      => self::GERAET,
                    'configuration' => [
                        'Host'       => $g['host'],
                        'DeviceType' => 0,
                        'MAC'        => $g['mac'],
                    ],
                    'name'          => 'Broadlink ' . ($g['name'] !== '' ? $g['name'] : $typ),
                ],
            ];
        }

        // Angelegt, aber gerade nicht gefunden
        foreach ($instanzen as $iid => $i) {
            $werte[] = [
                'name'       => IPS_GetName($iid),
                'type'       => '',
                'address'    => $i['host'],
                'mac'        => $i['mac'],
                'locked'     => '',
                'instanceID' => $iid,
            ];
        }
        return $werte;
    }
}
