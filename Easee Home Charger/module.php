<?php

declare(strict_types=1);

include __DIR__ . "/../libs/traits.php";
include __DIR__ . "/../libs/observations.php";
include __DIR__ . "/../libs/easee.php";

class EaseeHomeCharger extends IPSModule {
	use Profiles;
	use Buffer;
	
	public function Create(){
		//Never delete this line!
		parent::Create();

		$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');

		$this->RegisterPropertyInteger('UpdateInterval', 30);
		$this->RegisterPropertyString('ProductId', '');
		$this->RegisterPropertyString('Site', '');

		foreach(Charger::Observations as $observation) {
			if($observation['IsVariable']) {
				switch($observation['Type']) {
					case Observations::BOOLEAN:
						$type = 'Boolean';
						break;
					case Observations::INTEGER:
						$type = 'Integer';
						break;
					case Observations::STRING:
						$type = 'String';
						break;
					case Observations::FLOAT:
						$type = 'Float';
						break;
				}

				if(!IPS_VariableProfileExists($observation['Profile'])) {
					if(isset($observation['Assoc'])) {
						$this->{'RegisterProfile'.$type.'Ex'}($observation['Profile'], $observation['Icon'], '', '', $observation['Assoc']);	
					} else {
						$this->{'RegisterProfile'.$type}($observation['Profile'], $observation['Icon'], '', '');	
					}
				}
				
				$this->{'RegisterVariable'.$type}($observation['Ident'], $observation['Caption'], $observation['Profile'], $observation['Position']);
				if($observation['Enable']) {
					$this->EnableAction($observation['Ident']);
				}
			}
		}

		/*$this->RegisterProfileIntegerEx('EHCH.ChargerOpMode', 'Electricity', '', '', [
			[0, 'Offline', '', -1],
			[1, 'Disconnected', '', -1],
			[2, 'Awaiting Start... ', '', -1],
			[3, 'Charging... ', '', -1],
			[4, 'Completed ', '', -1],
			[5, 'Error' , '', -1],
			[6, 'Ready To Charge' , '', -1],
			[7, 'Awaiting Authentication...' , '', -1],
			[8, 'De-authenticating...' , '', -1]
	]);

		$this->RegisterProfileIntegerEx('EHCH.StartCharging', 'Power', '', '', [
			[0, ' ', '', -1],
			[1, 'Start', '', -1],
			[2, 'Stop', '', -1],
			[3, 'Pause ', '', -1],
			[4, 'Resume ', '', -1],
			[5, 'Toggle ', '', -1],
			[6, 'Override Schedule', '', -1],
			[99, 'Authenticate', '', -1]
		]);

		$this->RegisterProfileBooleanEx('EHCH.LockCable', 'Lock', '', '', [
			[true, 'In progress...', '', -1],
			[false, 'In progress...', '', -1]
		]);

		$this->RegisterProfileBooleanEx('EHCH.ProtectAccess', 'Lock', '', '', [
			[true, 'In progress...', '', -1],
			[false, 'In progress...', '', -1]
		]);

		$this->RegisterProfileBooleanEx('EHCH.Authorize', 'Key-skeleton', '', '', [
			[true, 'Authorized', '', -1],
			[false, 'Unauthorized', '', -1]
		]);

		$this->RegisterVariableInteger('StartCharging', 'Charging', 'EHCH.StartCharging', 1);
		$this->EnableAction('StartCharging');

		$this->RegisterVariableInteger('Status', 'Status', 'EHCH.ChargerOpMode', 2);

		$this->RegisterVariableFloat('Voltage', 'Voltage', '~Volt', 3);

		$this->RegisterVariableFloat('Current', 'Current', '~Ampere', 4);

		$this->RegisterVariableFloat('TotalEnergi', 'Total Energy', '~Electricity', 5);
		
		$this->RegisterVariableBoolean('Authorize', 'Authorized Status', 'EHCH.Authorize', 6);

		$this->RegisterVariableBoolean('LockCable', 'Lock Cable', 'EHCH.LockCable', 7);
		$this->EnableAction('LockCable');
		
		$this->RegisterVariableBoolean('ProtectAccess', 'Enabled', 'EHCH.ProtectAccess', 8);
		$this->EnableAction('ProtectAccess');
*/
		$this->RegisterTimer('EaseeChargerRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);

		
		

	}

	public function Destroy(){
		$module = json_decode(file_get_contents(__DIR__ . '/module.json'));
		if(count(IPS_GetInstanceListByModuleID($module->id))==0) {
			$this->DeleteProfile('EHCH.ChargerOpMode');
			$this->DeleteProfile('EHCH.StartCharging');
			$this->DeleteProfile('EHCH.LockCable');
			$this->DeleteProfile('EHCH.ProtectAccess');
			$this->DeleteProfile('EHCH.Authorize');
		}

		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges(){
		//Never delete this line!
		parent::ApplyChanges();

		// Renaming display name for variable after switching to enable/disable charger for protection
		$id = $this->GetIDForIdent('ProtectAccess');
		if ($id > 0) {
			IPS_SetName($id, "Enabled");
		}

		$filter = sprintf('.*"ChildId":"%s".*|.*##AllChildren##.*', (string)$this->InstanceID);
		
		$serialNumber = $this->ReadPropertyString('ProductId');
		if($serialNumber!='') {
			$filter .= sprintf('|.*"SerialNumber":"%s".*', $serialNumber);
		}
		
		$this->SetReceiveDataFilter($filter);
		
		if (IPS_GetKernelRunlevel() == KR_READY) {
			$this->InitTimer();
		}
	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

		if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
			$this->InitTimer();
		}
	}

	public function RequestAction($Ident, $Value) {
		try {
			$this->SendDebug(__FUNCTION__, sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);

			$chargerId = $this->ReadPropertyString('ProductId');

			$request = [];
			
			switch (strtolower($Ident)) {
				case 'refresh':
					$request = $this->RefreshRequest($chargerId, $Value);
					
					$this->InitTimer(); // Reset timer back to configured interval 
					break;
				case 'lockcable':
					$this->SetValueEx($Ident, $Value);
					$this->DisableAction($Ident); // Disable variable in visualization until command has finished

					$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerLockState', 'Ident'=> $Ident, 'ChargerId'=>$chargerId, 'State' => $Value];

					break;
				case 'protectaccess':
					$this->DisableAction($Ident); // Disable variable in visualization until command has finished
					
					$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'EnableCharger', 'Ident'=> $Ident, 'ChargerId'=>$chargerId, 'State' => $Value];

					break;
				case 'startcharging':
					$this->SetValueEx($Ident, $Value);

					if($Value==99) {
						$Value = 1;
					}

					if($Value>0){
						$this->DisableAction($Ident); // Disable variable in visualization until command has finished

						$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargingState', 'Ident'=> $Ident, 'ChargerId'=>$chargerId, 'State' => $Value];
					}

					break;
				default:
					throw new Exception(sprintf('ReqestAction called for unkown Ident "%s"', $Ident));
			}

			if($request!=[]) {
				if(strtolower($Ident)!='refresh') {
					$this->DelayTimer();
				}

				$this->SendDebug(__FUNCTION__, sprintf('Sending a request to the gateway: %s', json_encode($request)), 0);
				$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $request]));
			}

		} catch(Exception $e) {
			$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(__FUNCTION__, sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}

	public function ReceiveData($JSONString) {
		try {
			$data = json_decode($JSONString);
			$this->SendDebug(__FUNCTION__, sprintf('Received data from parent: %s', json_encode($data->Buffer)), 0);

			$msg = '';
			if(!isset($data->Buffer->Function) ) {
				$msg = 'Missing "Function"';
			} 
			if(!isset($data->Buffer->Success) ) {
				if(strlen($msg)>0) {
					$msg += ', missing "Status"';
				} else {
					$msg = 'Missing "Status"';
				}
			} 
			if(!isset($data->Buffer->Result) ) {
				if(strlen($msg)>0) {
					$msg += ', missing "Result"';
				} else {
					$msg = 'Missing "Result"';
				}
			} 
			
			if(strlen($msg)>0) {
				throw new Exception('Invalid data receieved from parent. ' . $msg);
			}
			
			$success = $data->Buffer->Success;
			$result = $data->Buffer->Result;

			if($success) {
				$function = strtolower($data->Buffer->Function);
				$ident = '';
				switch($function) {
					case 'productupdate':
						$this->HandleProductUpdate($result);
						break;
					case 'commandresponse':
						$this->HandleCommandResponse($result);
						break;
					case 'subscribe':
						// Send message back to parent to subscribe to SignalR
						$chargerId = $this->ReadPropertyString('ProductId');
						$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'Subscribe','ChargerId'=>$chargerId, 'WithCurrentState' => true];
						
						$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $request]));
						
						break;
					case 'getchargerobservations':
						if(isset($result->observations)) {
							$mid = $this->ReadPropertyString('ProductId');  // GetChargerObservations returns without the mid-propery.  Add it to all returned observations
							foreach($result->observations as $observation) {
								$observation->mid = $mid; 
								$this->HandleProductUpdate($observation);
							}
						}
						break;
					case 'getproducts':
						break;
					case 'getchargerconfig':
						if(isset($result->lockCablePermanently)) {
							$this->SetValueEx('LockCable', $result->lockCablePermanently);
						}

						if(isset($result->authorizationRequired)) {
							$this->SetValueEx('ProtectAccess', $result->authorizationRequired);
						}
						break;
					case 'setchargerlockstate':
					case 'setchargerconfig':
					case 'setchargingstate':
					case 'enablecharger':
						break;
					default:
						throw new Exception(sprintf('Unknown function "%s()" receeived in repsponse from gateway', $function));
				}

				$this->SendDebug(__FUNCTION__, sprintf('Processed the result from %s(): %s...', $data->Buffer->Function, json_encode($result)), 0);
			} else {
				throw new Exception(sprintf('The gateway returned an error: %s', $result));
			}
			
		} catch(Exception $e) {
			$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(__FUNCTION__, sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), 0);

			$this->SendDebug(__FUNCTION__, 'Setting EnableAction to True for StartCharging, ProtectAccess and LockCable', 0);

			$this->EnableAction('StartCharging');
			$this->SetValueEx('StartCharging', 0);

			$this->EnableAction('ProtectAccess');
			$this->EnableAction('LockCable');
																			
			//$script = "sleep(10);IPS_RequestAction(" . (string)$this->InstanceID . " ,'Refresh', 0);";
			//$this->RegisterOnceTimer('EaseeChargerRefreshOnce' . (string)$this->InstanceID, $script); 
		}
	}

	private function InitTimer(){
			$sec = $this->ReadPropertyInteger('UpdateInterval');
			$this->SendDebug(__FUNCTION__, sprintf('Setting refresh timer to %ds', $sec), 0);
			$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, $sec*1000); 				
	}

	private function DelayTimer(){
		$this->SendDebug(__FUNCTION__, 'Delaying the refresh timer for 60 sec', 0);
		$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, 60000); 
	}

	private function RefreshRequest(string $ChargerId, $Ident) : array {
		if(strlen($ChargerId)>0) {
			$ids = Charger::GetObservationIdsWithVariable();

			if($ids!==false) {
				$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerObservations','ChargerId'=>$ChargerId, 'ObserationIds'=>$ids];
			}

			return $request;
		}

		return [];
	}

	private function HandleProductUpdate($Data) {
		$this->SendDebug(__FUNCTION__, sprintf('Processing Product Update: %s...', json_encode($Data)), 0);

		try{
			$change = Charger::GetObservation($Data);

			$this->SendDebug(__FUNCTION__, sprintf('GetObservation returned %s', json_encode($change)), 0);

			if($change!==false) {
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is an Id that corresponds to Ident "%s"', $Data->id, $change['Ident']), 0);
				
				$variableId = IPS_GetObjectIDByIdent($change['Ident'], $this->InstanceID);
				$variableProperties = IPS_GetVariable($variableId);
				
				$this->SendDebug(__FUNCTION__, sprintf('The cloud change occured on %d', $change['Timestamp']), 0);
				$this->SendDebug(__FUNCTION__, sprintf('The local change occured on %d', $variableProperties['VariableChanged']), 0);

				if(!HasAction($variableId) && $variableProperties['VariableChanged'] <= $change['Timestamp'] && $change['Enable']) {
					$this->SendDebug(__FUNCTION__, sprintf('HasAction is FALSE and new observation has been received for "%s", enabling action...', $change['Ident']), 0);
					$this->EnableAction($change['Ident']);
				}

				if(isset($change['CustomHandling']) && strlen($change['CustomHandling'])>0) {
					$this->SendDebug(__FUNCTION__, sprintf('Updating "%s" through custom handler...', $change['Ident']), 0);
					self::{$change['CustomHandling']}($change['Ident'], $change['Value']);
				} else {
					$this->SendDebug(__FUNCTION__, sprintf('Updating "%s"...', $change['Ident']), 0);
					$this->SetValueEx($change['Ident'], $change['Value']);
				}
			} else {
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is not corresponding to an Ident. There is nothing to update', $Data->id), 0);
			}
		} catch(Exception $e) {
			IPS_LogMessage(IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleName'], $e->getMessage());
			$this->SendDebug(__FUNCTION__, $e->getMessage(), 0);
		}	

	}

	private function HandleCommandResponse($Data) {
		
		$this->SendDebug(__FUNCTION__, sprintf('Processing Command Response: %s...', json_encode($Data)), 0);

		try{
			$response = Charger::GetObservation($Data);

			$this->SendDebug(__FUNCTION__, sprintf('GetObservation returned: %s...', json_encode($response)), 0);

			if($response!==false) {
				if($response['Linked']) {
					$this->SendDebug(__FUNCTION__, 'The Observation Id is linked to another Observation Id', 0);
				}
				
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is an id that corresponds to Ident "%s"', $Data->id, $response['Ident']), 0);
				
				if($response['WasAccepted']) {
					$this->SendDebug(__FUNCTION__, sprintf('The command for changing %s was accepted by the cloud api', $response['Ident']), 0);					
				} else {
					$this->SendDebug(__FUNCTION__, sprintf('The command for changing %s was not accepted by the cloud api. Stopping the process...', $response['Ident']), 0);
				
					$this->EnableAction($response['Ident']);

					switch(strtolower($response['Ident'])) {
						case 'lockcable':
						case 'prototectaccess':
							$this->SetValueEx($response['Ident'], !$this->GetValue($response['Ident']));
							break;
						case 'startcharging':
							$this->SetValueEx($response['Ident'], 0);
							break;
					}	
				}
			} else {
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is not corresponding to an Ident', $Data->id), 0);
			}
		} catch(Exception $e) {
			IPS_LogMessage(IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleName'], $e->getMessage());
			$this->SendDebug(__FUNCTION__, $e->getMessage(), 0);
		}	
	}

	private function HandleChargerOpMode(string $Ident, $Value) {
		$this->SendDebug(__FUNCTION__, 'Executing custom handler for ChargerOpMode', 0);

		// Set Variable for Charging back to blank
		$this->SetValueEx('StartCharging', 0);

		// Handle Variable "Autorize" according to value of Op Mode
		switch($Value) {
			case 0:
			case 1:
			case 5:
			case 8:
				$this->SetValueEx('Authorize', false);
				break;
			case 7:
				$this->SetValueEx('Authorize', false);
				break; 
			case 2:
			case 3:
			case 4:
			case 6:
				$this->SetValueEx('Authorize', true);
				break;
			default:
				throw new Exception(sprintf('Invalid vale for Charger Op Mode: %d', $Value));
		}

		$this->SetValueEx($Ident, $Value);
	}
		
	private function SetValueEx(string $Ident, $Value) {
		//$oldValue = $this->GetValue($Ident);
		//if($oldValue!=$Value) {
			$this->SetValue($Ident, $Value);
			$this->SendDebug(__FUNCTION__, sprintf('Modified variable with Ident "%s". New value is  "%s"', $Ident, is_bool($Value)?$Value?"true":"false":(string)$Value), 0);
		//} else {
		//	$this->SendDebug(__FUNCTION__, sprintf('The variable with Ident "%s" has not changed. Skipping update. The value is  "%s"', $Ident, is_bool($Value)?$Value?"true":"false":(string)$Value), 0);
		//}
	}
}