<?php

trait Buffer {
	private function Lock(string $Name) {
		//$this->SendDebug(__FUNCTION__, sprintf('Locking "%s"...',$Name), 0);
        for ($i = 0; $i < 100; $i++){
            if (IPS_SemaphoreEnter(sprintf('%s%s',(string)$this->InstanceID,$Name), 1)){
				//$this->SendDebug(__FUNCTION__, sprintf('"%s" is locked',$Name), 0);
                return true;
            } else {
                //$this->SendDebug(__FUNCTION__, 'Waiting for lock...', 0);
				IPS_Sleep(mt_rand(1, 5));
            }
        }
        return false;
    }

    private function Unlock(string $Name) {
        IPS_SemaphoreLeave(sprintf('%s%s',(string)$this->InstanceID,$Name));
		//$this->SendDebug(__FUNCTION__, sprintf('Unlocked "%s"', $Name), 0);
    }

    private function UpdateBuffer(string $Name, $Value) {
		$this->UpdateBufferRaw($Name, json_encode($Value));
	}

	private function FetchBuffer(string $Name) {
		$value = $this->FetchBufferRaw($Name);
		return json_decode($value);
	}

	private function FetchBufferRaw(string $Name) {
		if($this->Lock($Name)) {
			$value = $this->GetBuffer($Name);
			//$this->SendDebug(__FUNCTION__, sprintf('Fetched "%s"',$Name), 0);
			$this->Unlock($Name);
			return $value;
		} else {
			$msg = sprintf('Failed to Fetch "%s"',$Name);
			$this->LogMessage($msg, KL_ERROR);
			$this->SendDebug(__FUNCTION__, $msg, 0);
			return false;
		}
	}

	private function UpdateBufferRaw(string $Name, $Value) {
		if($this->Lock($Name)) {
			$this->SetBuffer($Name, $Value);
			//$this->SendDebug(__FUNCTION__, sprintf('Updated "%s"',$Name), 0);
			$this->Unlock($Name);
		} else {
			$msg = sprintf('Failed to Update "%s"',$Name);
			$this->LogMessage($msg, KL_ERROR);
			$this->SendDebug(__FUNCTION__, $msg, 0);
		}
	}
}


trait Profiles {
    protected function DeleteProfile($Name) {
        if(IPS_VariableProfileExists($Name))
            IPS_DeleteVariableProfile($Name);
    }

    protected function RegisterProfileString($Name, $Icon, $Prefix, $Suffix) {

        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 3);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 3) {
                throw new Exception('Variable profile type (string) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
    }

    protected function RegisterProfileStringEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        
        $this->RegisterProfileString($Name, $Icon, $Prefix, $Suffix);

        foreach ($Associations as $association) {
            IPS_SetVariableProfileAssociation($Name, $association[0], $association[1], $association[2], $association[3]);
        }
        
        // Remove assiciations that is not specified in $Associations
        $profileAssociations = IPS_GetVariableProfile($Name)['Associations'];
        foreach($profileAssociations as $profileAssociation) {
            $found = false;
            foreach($Associations as $association) {
                if($profileAssociation['Value']==$association[0]) {
                    $found = true;
                    break;
                }
            }

            if(!$found)
                IPS_SetVariableProfileAssociation($Name, $profileAssociation['Value'], '', '', -1);    
        }
    }

    protected function RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix) {

        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 0);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 0) {
                throw new Exception('Variable profile type (boolean) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
    }

    protected function RegisterProfileBooleanEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        
        $this->RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix);

        foreach ($Associations as $association) {
            IPS_SetVariableProfileAssociation($Name, $association[0], $association[1], $association[2], $association[3]);
        }
        
        // Remove assiciations that is not specified in $Associations
        $profileAssociations = IPS_GetVariableProfile($Name)['Associations'];
        foreach($profileAssociations as $profileAssociation) {
            $found = false;
            foreach($Associations as $association) {
                if($profileAssociation['Value']==$association[0]) {
                    $found = true;
                    break;
                }
            }

            if(!$found)
                IPS_SetVariableProfileAssociation($Name, $profileAssociation['Value'], '', '', -1);    
        }
    }
    
    protected function RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize) {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 1);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 1) {
                throw new Exception('Variable profile type (integer) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
    }

    protected function RegisterProfileIntegerEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        
        if (count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        } else {
            $MinValue = $Associations[0][0];
            $MaxValue = $Associations[count($Associations) - 1][0];
        }

        $this->RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0);

        foreach ($Associations as $association) {
            IPS_SetVariableProfileAssociation($Name, $association[0], $association[1], $association[2], $association[3]);
        }
        
        // Remove assiciations that is not specified in $Associations
        $profileAssociations = IPS_GetVariableProfile($Name)['Associations'];
        foreach($profileAssociations as $profileAssociation) {
            $found = false;
            foreach($Associations as $association) {
                if($profileAssociation['Value']==$association[0]) {
                    $found = true;
                    break;
                }
            }

            if(!$found)
                IPS_SetVariableProfileAssociation($Name, $profileAssociation['Value'], '', '', -1);    
        }
    }

    protected function RegisterProfileFloat($Name, $Icon, $Prefix, $Suffix) {

        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 2);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 2) {
                throw new Exception('Variable profile type (float) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
    }

    protected function CreateProfileAssosiationList($List) {
        $count = 0;
        foreach($List as $value) {
            $assosiations[] = [$count, $value,  '', -1];
            $count++;
        }

        return $assosiations;
    }

    protected function GetProfileAssosiationName($ProfileName, $Index) {
        $profile = IPS_GetVariableProfile($ProfileName);
    
        if($profile!==false) {
            foreach($profile['Associations'] as $association) {
                if($association['Value']==$Index)
                    return $association['Name'];
            }
        } 
    
        return false;
    
    }
}