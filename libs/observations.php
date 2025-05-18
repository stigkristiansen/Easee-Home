<?php

declare(strict_types=1);

class Observations {
    const BOOLEAN = 0;
    const INTEGER = 1;
    const STRING = 2;
    const FLOAT = 3;

    const Observation = [];

    static function GetObservation($Observation) {
        $observations = get_called_class()::Observations;

        if($observations[$Observation->id]['IsVariable']) {
            $observation = ['Ident' => $observation[$Observation->id]['Ident']];

            switch($observations[$Observation->id]['Type']) {
                case ObservationId::BOOLEAN:
                    $observation['Value'] = (bool)$Observation->value; 
                    break;
                case ObservationId::INTEGER:
                    $observation['Value'] = (int)$Observation->value; 
                    break;
                case ObservationId::STRING:
                    $observation['Value'] = (string)$Observation->value; 
                    break;
                case ObservationId::FLOAT:
                    $observation['Value'] = (float)$Observation->value; 
                    break;
            }

            return $observation;
        }
        
        return false;
    }

}

class Charger extends Observations {

    const Observations = [
        11 => [
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
            'Type' => ObservationId::BOOLEAN,
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
            'Type' => ObservationId::BOOLEAN,
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
            'Description' => 'MAX CURRENT OFFLINE FALLBACK P3'
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
        109 => [
            'IsVariable' => true,
            'Description' => 'CHARGER OP MODE',
            'Ident' => 'Status',
            'Caption' => 'Status',
            'Type' => ObservationId::INTEGER,
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
        124 => [
            'IsVariable' => true,
            'Description' => 'LIFETIME ENERGY',
            'Ident' => 'TotalEnergi',
            'Caption' => 'Total Energi',
            'Type' => ObservationId::FLOAT,
            'Enable' => false,
            'Profile' => '~Electricity'
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
        ]
    ];
}
