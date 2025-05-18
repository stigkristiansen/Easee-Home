<?php

declare(strict_types=1);

class Observations {
    const BOOLEAN = 0;
    const INTEGER = 1;
    const STRING = 2;
    const FLOAT = 3;

    const Observation = [];

    static function GetObservation($Observation) {
        if(!isset($Observation->id) || !isset($Observation->value)) {
            $error = 'Observation is invalid. Missing "id" and/or "value"';    
            throw new Exception($error);
        }

        $observations = get_called_class()::Observations;
        
        if(isset($observations[$Observation->id])) {
            if($observations[$Observation->id]['IsVariable']) {
                $change = ['Ident' => $observation[$Observation->id]['Ident']];
    
                switch($observations[$Observation->id]['Type']) {
                    case Observations::BOOLEAN:
                        $change['Value'] = (bool)$Observation->value; 
                        break;
                    case Observations::INTEGER:
                        $change['Value'] = (int)$Observation->value; 
                        break;
                    case Observations::STRING:
                        $change['Value'] = (string)$Observation->value; 
                        break;
                    case Observations::FLOAT:
                        $change['Value'] = (float)$Observation->value; 
                        break;
                }
    
                return $change;
            }
            
            return false;
        } else {
            $error = sprintf('Observation Id %d is not defined in class %s', $Observation->id, get_called_class());
            throw new Exception($error);
        }
        
    }

}

class Charger extends Observations {

    const Observations = [
        11 => [
            'IsVariable' => false,
            'Description'  => 'CHARGER OFFLINE REASON'
        ],
        15 => [
            'IsVariable' => false,
            'Description'  => 'LOCAL PRE AUTHORIZE ENABLED'
        ],
        16 => [
            'IsVariable' => false,
            'Description' => 'LOCAL AUTHORIZE OFFLINE ENABLED'
        ],
        17 => [
            'IsVariable' => false,
            'Description' => 'ALLOW OFFLINE TX FOR UNKNOWN ID'
        ],
        21 => [
            'IsVariable' => false,
            'Description' => 'DETECTED POWER GRID TYPE'
        ],
        22 => [
            'IsVariable' => false,
            'Description' => 'Circuit Max Current P1'
        ],
        23 => [
            'IsVariable' => false,
            'Description' => 'Circuit Max Current P2'
        ],
        24 => [
            'IsVariable' => false,
            'Description' => 'Circuit Max Current P3'
        ],
        30 => [
            'IsVariable' => true,
            'Description' => 'LOCK CABLE PERMANENTLY',
            'Ident' => 'LockCable',
            'Caption' => 'Lock Cable',
            'Type' => Observations::BOOLEAN,
            'Enable' => true,
            'Profile' => 'EHCH.LockCable',
            'Icon' => 'Lock',
            'Assoc' => [
                [true, 'Locking...', '', -1],
                [false, 'Unlocking', '', -1]
            ]
        ],
        31 => [
            'IsVariable' => false,
            'Description' => 'IS ENABLED'
        ],
        36 => [
            'IsVariable' => false,
            'Description' => 'WIFI SSID'
        ],
        37 => [
            'IsVariable' => false,
            'Description' => 'NOT DOCUMENTED'
        ],
        38 => [
            'IsVariable' => false,
            'Description' => 'PHASE MODE'
        ],
        40 => [
            'IsVariable' => false,
            'Description' => 'LED STRIP BRIGHTNESS'
        ],
        41 => [
            'IsVariable' => false,
            'Description' => 'LOCAL AUTHORIZATION REQUIRED'
        ],
        42 => [
            'IsVariable' => true,
            'Description' => 'AUTHORIZATION REQUIRED',
            'Ident' => 'ProtectAccess',
            'Caption' => 'Protect Access',
            'Type' => Observations::BOOLEAN,
            'Enable' => true,
            'Profile' => 'EHCH.ProtectAccess',
            'Icon' => 'Lock',
            'Assoc' => [
				[true, 'Protecting...', '', -1],
				[false, 'Unprotecting...', '', -1]
			]
        ],
        44 => [
            'IsVariable' => false,
            'Description' => 'SMART BUTTON ENABLED'
        ],
        46 => [
            'IsVariable' => false,
            'Description' => 'LEDMODE'
        ],
        47 => [
            'IsVariable' => false,
            'Description' => 'MAX CHARGER CURRENT'
        ],
        48 => [
            'IsVariable' => false,
            'Description' => 'DYNAMIC CHARGER CURRENT'
        ],
        50 => [
            'IsVariable' => false,
            'Description' => 'MAX CURRENT OFFLINE FALLBACK P1'
        ],
        51 => [
            'IsVariable' => false,
            'Description' => 'MAX CURRENT OFFLINE FALLBACK P2'
        ],
        52 => [
            'IsVariable' => false,
            'Description' => 'MAX CURRENT OFFLINE FALLBACK P3'
        ],
        62 => [
            'IsVariable' => false,
            'Description' => 'CHARGING SCHEDULE'
        ],
        68 => [
            'IsVariable' => false,
            'Description' => 'MAX CURRENT OFFLINE FALLBACK P3'
        ],
        70 => [
            'IsVariable' => false,
            'Description' => 'Circuit Total Allocated Phase Conductor Current L1'
        ],
        71 => [
            'IsVariable' => false,
            'Description' => 'Circuit Total Allocated Phase Conductor Current L2'
        ],
        72 => [
            'IsVariable' => false,
            'Description' => 'Circuit Total Allocated Phase Conductor Current L3'
        ],
        73 => [
            'IsVariable' => false,
            'Description' => 'Circuit Total Phase Conductor Current L1'
        ],
        74 => [
            'IsVariable' => false,
            'Description' => 'Circuit Total Phase Conductor Current L2'
        ],
        75 => [
            'IsVariable' => false,
            'Description' => 'Circuit Total Phase Conductor Current L3'
        ],
        80 => [
            'IsVariable' => false,
            'Description' => 'Software relase'
        ],
        81 => [
            'IsVariable' => false,
            'Description' => 'ICCID'
        ],
        96 => [
            'IsVariable' => false,
            'Description' => 'REASON FOR NO CURRENT'
        ],
        100 => [
            'IsVariable' => false,
            'Description' => 'PILOT MODE'
        ],
        102 => [
            'IsVariable' => false,
            'Description' => 'SMART CHARGING'
        ],
        103 => [
            'IsVariable' => false,
            'Description' => 'CABLE LOCKED'
        ],
        104 => [
            'IsVariable' => false,
            'Description' => 'CABLE RATING'
        ],
        107 => [
            'IsVariable' => false,
            'Description' => 'BACKPLATE ID'
        ],
        109 => [
            'IsVariable' => true,
            'Description' => 'CHARGER OP MODE',
            'Ident' => 'Status',
            'Caption' => 'Status',
            'Type' => Observations::INTEGER,
            'Enable' => false,
            'Profile' => 'EHCH.ChargerOpMode',
            'Icon' => 'Electricity',
            'Assoc' => [
				[1, 'Disconnected', '', -1],
				[2, 'Awaiting Start ', '', -1],
				[3, 'Charging ', '', -1],
				[4, 'Completed ', '', -1],
				[5, 'Error' , '', -1],
				[6, 'Ready To Charge' , '', -1]
			]
        ],
        110 => [
            'IsVariable' => false,
            'Description' => 'OUTPUT PHASE'
        ],
        111 => [
            'IsVariable' => false,
            'Description' => 'Dynamic Circuit Current P1'
        ],
        112 => [
            'IsVariable' => false,
            'Description' => 'Dynamic Circuit Current P2'
        ],
        113 => [
            'IsVariable' => false,
            'Description' => 'Dynamic Circuit Current P3'
        ],
        114 => [
            'IsVariable' => false,
            'Description' => 'Output Current'
        ],
        116 => [
            'IsVariable' => false,
            'Description' => 'DERATING ACTIVE'
        ],
        118 => [
            'IsVariable' => false,
            'Description' => 'Error String'
        ],
        119 => [
            'IsVariable' => false,
            'Description' => 'ERROR CODE'
        ],
        120 => [
            'IsVariable' => false,
            'Description' => 'TOTAL POWER'
        ],
        121 => [
            'IsVariable' => false,
            'Description' => 'SESSION ENERGY'
        ],
        122 => [
            'IsVariable' => false,
            'Description' => 'ENERGY PER HOUR'
        ],
        124 => [
            'IsVariable' => true,
            'Description' => 'LIFETIME ENERGY',
            'Ident' => 'TotalEnergi',
            'Caption' => 'Total Energi',
            'Type' => Observations::FLOAT,
            'Enable' => false,
            'Profile' => '~Electricity'
        ],
        130 => [
            'IsVariable' => false,
            'Description' => 'CELL RSSI'
        ],
        132 => [
            'IsVariable' => false,
            'Description' => 'WIFI RSSI'
        ],
        134 => [
            'IsVariable' => false,
            'Description' => 'WIFI ADDRESS'
        ],
        140 => [
            'IsVariable' => false,
            'Description' => 'UNDOCUMENTED'
        ],
        141 => [
            'IsVariable' => false,
            'Description' => 'CURRENT CONNECTION'
        ],
        146 => [
            'IsVariable' => false,
            'Description' => 'LOCAL NODE TYPE'
        ],
        147 => [
            'IsVariable' => false,
            'Description' => 'UNDOCUMENTED'
        ],
        148 => [
            'IsVariable' => false,
            'Description' => 'UNDOCUMENTED'
        ],
        149 => [
            'IsVariable' => false,
            'Description' => 'LOCAL PARENT ADDR OR NUM OF NODES'
        ],
        150 => [
            'IsVariable' => false,
            'Description' => 'TEMP MAX'
        ],
        182 => [
            'IsVariable' => false,
            'Description' => 'INT CURRENT T2'
        ],
        183 => [
            'IsVariable' => false,
            'Description' => 'IN VOLT T2T5'
        ],
        183 => [
            'IsVariable' => false,
            'Description' => 'INT CURRENT T3'
        ],
        184 => [
            'IsVariable' => false,
            'Description' => 'INT CURRENT T4'
        ],
        185 => [
            'IsVariable' => false,
            'Description' => 'INT CURRENT T5'
        ],
        194 => [
            'IsVariable' => false,
            'Description' => 'IN VOLT T2T3'
        ],
        195 => [
            'IsVariable' => false,
            'Description' => 'IN VOLT T2T4'
        ],
        196 => [
            'IsVariable' => false,
            'Description' => 'IN VOLT T2T5'
        ],
        219 => [
            'IsVariable' => false,
            'Description' => 'UNDOCUMENTED'
        ],
        230 => [
            'IsVariable' => false,
            'Description' => 'EQ AVAILABLE CURRENT P1'
        ],
        321 => [
            'IsVariable' => false,
            'Description' => 'EQ AVAILABLE CURRENT P2'
        ],
        232 => [
            'IsVariable' => false,
            'Description' => 'EQ AVAILABLE CURRENT P3'
        ],
        233 => [
            'IsVariable' => false,
            'Description' => 'UNDOCUMENTED'
        ],
        234 => [
            'IsVariable' => false,
            'Description' => 'UNDOCUMENTED'
        ],
        241 => [
            'IsVariable' => false,
            'Description' => 'UNDOCUMENTED'
        ],
        250 => [
            'IsVariable' => false,
            'Description' => 'CONNECTED TO CLOUD'
        ],
        251 => [
            'IsVariable' => false,
            'Description' => 'CLOUD DISCONNECT REASON'
        ]
    ];
}
