<?php

declare(strict_types=1);

include __DIR__ . "/../libs/traits.php";
include __DIR__ . "/../libs/observations.php";
include __DIR__ . "/../libs/easee.php";

class EaseeHomeCharger extends IPSModule {
	use Profiles;
	use Buffer;

	const RECEIVED_OBSERVATIONS = 'ReceivedObservations';
	
	public function Create(){
		//Never delete this line!
		parent::Create();

		$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');

		$this->RegisterPropertyInteger('UpdateInterval', 30);
		$this->RegisterPropertyString('ProductId', '');
		$this->RegisterPropertyString('Site', '');

		$this->RegisterProfileIntegerEx('EHCH.ChargerOpMode', 'Electricity', '', '', [
			[0, 'Offline', '', -1],
			[1, 'Disconnected', '', -1],
			[2, 'Awaiting Start ', '', -1],
			[3, 'Charging ', '', -1],
			[4, 'Completed ', '', -1],
			[5, 'Error' , '', -1],
			[6, 'Ready To Charge' , '', -1],
			[7, 'Awaiting authentication' , '', -1],
			[8, 'De-authenticating' , '', -1]
		]);

		$this->RegisterProfileIntegerEx('EHCH.StartCharging', 'Power', '', '', [
			[0, ' ', '', -1],
			[1, 'Authorize ', '', -1],
			[2, 'Unauthorize ', '', -1],
			[3, 'Pause ', '', -1],
			[4, 'Resume ', '', -1],
			[5, 'Toggle ', '', -1]
		]);

		$this->RegisterProfileBooleanEx('EHCH.LockCable', 'Lock', '', '', [
			[true, 'In progress...', '', -1],
			[false, 'In progress...', '', -1]
		]);

		$this->RegisterProfileBooleanEx('EHCH.ProtectAccess', 'Lock', '', '', [
			[true, 'In progress...', '', -1],
			[false, 'In progress...', '', -1]
		]);

		$this->RegisterVariableInteger('StartCharging', 'Charging', 'EHCH.StartCharging', 1);
		$this->EnableAction('StartCharging');

		$this->RegisterVariableInteger('Status', 'Status', 'EHCH.ChargerOpMode', 2);

		$this->RegisterVariableFloat('Voltage', 'Voltage', '~Volt', 3);

		$this->RegisterVariableFloat('Current', 'Current', '~Ampere', 4);

		$this->RegisterVariableFloat('TotalEnergi', 'Total Energie', '~Electricity', 5);
		
		$this->RegisterVariableBoolean('LockCable', 'Lock Cable', 'EHCH.LockCable', 6);
		$this->EnableAction('LockCable');
		
		$this->RegisterVariableBoolean('ProtectAccess', 'Protect Access', 'EHCH.ProtectAccess', 7);
		$this->EnableAction('ProtectAccess');

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
		}

		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges(){
		//Never delete this line!
		parent::ApplyChanges();

		$filter = sprintf('.*"ChildId":"%s".*|.*##AllChildren##.*', (string)$this->InstanceID);
		
		$serialNumber = $this->ReadPropertyString('ProductId');
		if($serialNumber!='') {
			$filter .= sprintf('|.*"SerialNumber":"%s".*', $serialNumber);
		}
		
		$this->SetReceiveDataFilter($filter);

		$this->SetBuffer(self::RECEIVED_OBSERVATIONS, json_encode([]));
		
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
					$this->DisableAction($Ident);

					$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerLockState', 'Ident'=> $Ident, 'ChargerId'=>$chargerId, 'State' => $Value];

					$change = [
						'Ident' => $Ident,
                		'Timestamp' => time(),
						'Value' => $Value,
						'IsEnabled' => true
					];
					
					$this->UpdateReceivedObservations($change);

					break;
				case 'protectaccess':
					$this->DisableAction($Ident);

					$config = [
						'authorizationRequired' => $Value,
						'localPreAuthorizeEnabled' => $Value,
						'localAuthorizeOfflineEnabled' => $Value,
						'allowOfflineTxForUnknownId' => $Value
					];

					$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerConfig', 'Ident'=> $Ident, 'ChargerId'=>$chargerId, 'Config' => $config];

					$change = [
						'Ident' => $Ident,
                		'Timestamp' => time(),
						'Value' => $Value,
						'IsEnabled' => true
					];
					
					$this->UpdateReceivedObservations($change);

					break;
				case 'startcharging':
					if($Value>0){
						//$this->SetValue($Ident, $Value);
						$this->DisableAction($Ident); // Disable variable in visualization until command has finished

						switch($Value) {
							case 1:
								$state = ChargingState::AUTHORIZE;
								break;
							case 2:
								$state = ChargingState::UNAUTHORIZE;
								break;
							case 3:
								$state = ChargingState::PAUSE;
								break;
							case 4:
								$state = ChargingState::RESUME;
								break;
							case 5:
								$state = ChargingState::TOGGLE;
								break;
							default:
								$state = 
						}
						
						$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargingState', 'Ident'=> $Ident, 'ChargerId'=>$chargerId, 'State' => $state];

						$change = [
							'Ident' => $Ident,
							'Timestamp' => time(),
							'Value' => $Value,
							'IsEnabled' => true
						];
						
						$this->UpdateReceivedObservations($change);
					}
					break;
				default:
					throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
			}

			if($request!=[]) {
				if(strtolower($Ident)!='refresh') {
					$this->PauseTimer();
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
						if(isset($data->Buffer->Ident)) {
							$ident =  $data->Buffer->Ident;
						}

						$observation = $this->GetReceivedObservation($ident);

						if($observation!==false) {
							if(isset($result->commandId)) {
								$observation['Id'] =  $result->commandId;
							} else {
								$observation['Id'] = 0;
							}

							if(isset($result->ticks)) {
								$observation['Ticks'] = $result->ticks;
							} else {
								$observation['Ticks'] = 0;
							}	

							$this->UpdateReceivedObservations($observation);
						}

						break;
					case 'setchargingstate':
						

						break;
					case 'setchargeraccesslevel':
						$this->SendDebug(__FUNCTION__, 'Quering for new charger status in 10s', 0);
						
						$script = "sleep(10);IPS_RequestAction(" . (string)$this->InstanceID . " ,'Refresh', 0);";

						$this->RegisterOnceTimer('EaseeChargerRefreshOnce' . (string)$this->InstanceID, $script);  // Call Refresh in a new thread

						$this->EnableAction('ProtectAccess');
						
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
			$this->EnableAction('ProtectAccess');
			$this->EnableAction('LockCable');
																			
			$script = "sleep(10);IPS_RequestAction(" . (string)$this->InstanceID . " ,'Refresh', 0);";
			$this->RegisterOnceTimer('EaseeChargerRefreshOnce' . (string)$this->InstanceID, $script); 
		}
	}

	private function InitTimer(){
		if($this->GetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID)==0) {
			$sec = $this->ReadPropertyInteger('UpdateInterval');
			$this->SendDebug(__FUNCTION__, sprintf('Setting refresh timer to %ds', $sec), 0);
			$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, $sec*1000); 				
		}
	}

	private function PauseTimer(){
		$this->SendDebug(__FUNCTION__, 'Pausing the refresh timer', 0);
		$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, 0); 
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

	private function GetReceivedObservation($Ident) {
		if($this->Lock(self::RECEIVED_OBSERVATIONS)) {
			$receivedObservations = json_decode($this->GetBuffer(self::RECEIVED_OBSERVATIONS), true);	
			$this->Unlock(self::RECEIVED_OBSERVATIONS);
			if($receivedObservations!==null && isset($receivedObservations[$Ident])) {
				return $receivedObservations[$Ident];
			}

			return false;
		}

		return false;
	}

	private function UpdateReceivedObservations($Observation) {
		if($this->Lock(self::RECEIVED_OBSERVATIONS)) {
			$receivedObservations = json_decode($this->GetBuffer(self::RECEIVED_OBSERVATIONS), true);	
			
			if($receivedObservations!==null) {
				$receivedObservations[$Observation['Ident']] = $Observation;
				$jsonList = json_encode($receivedObservations);
				$this->SetBuffer(self::RECEIVED_OBSERVATIONS, $jsonList);

				$this->SendDebug(__FUNCTION__, sprintf('New list of received observations: %s', $jsonList), 0);
			}

			$this->Unlock(self::RECEIVED_OBSERVATIONS);
		}
	}

	private function HandleProductUpdate($Data) {
		$this->SendDebug(__FUNCTION__, sprintf('Processing Product Update: %s...', json_encode($Data)), 0);

		try{
			$change = Charger::GetObservation($Data);

			$this->SendDebug(__FUNCTION__, sprintf('GetObservation returned %s', json_encode($change)), 0);

			if($change!==false) {
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is an Id that corresponds to Ident "%s"', $Data->id, $change['Ident']), 0);
				$oldObservation = $this->GetReceivedObservation($change['Ident']);
				if($oldObservation!==false) {
					if($oldObservation['Timestamp']>$change['Timestamp']) {
						$this->SendDebug(__FUNCTION__, 'Timestamp for this change is older than the last observation. Skipping the update of the variable', 0);
						
						return; 
					}

					$this->SendDebug(__FUNCTION__, 'Timestamp for this change is newer than the last observation. Updating the variable', 0);
				} else {
					$this->SendDebug(__FUNCTION__, 'This is the first observation for this ident. Updating the variable', 0);
				}

				$this->SetValueEx($change['Ident'], $change['Value']);

				if(isset($oldObservation['IsEnabled']) && $oldObservation['IsEnabled']==true) {
					$this->EnableAction($change['Ident']);
					$this->InitTimer();
				}

				$this->UpdateReceivedObservations($change);
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
			if($response!==false) {
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is an Id that corresponds to Ident "%s"', $Data->id, $response['Ident']), 0);
				$oldObservation = $this->GetReceivedObservation($response['Ident']);
				if($oldObservation!==false) {
					if($oldObservation['Timestamp']>$response['Timestamp']) {
						$this->SendDebug(__FUNCTION__, 'Timestamp for this change is older than the last observation. Skipping update', 0);

						return;
					}
				}  else {
					$this->SendDebug(__FUNCTION__, 'This observation do not correspond with a earlier sent command. Skipping update', 0);
				}

				$this->SendDebug(__FUNCTION__, 'Timestamp for this change is newer than the last observation. Checking if it matches a earlier sent command...', 0);

				if(isset($oldObservation['Ticks']) && $oldObservation['Ticks']==$response['Ticks'] && isset($response['WasAccepted']) && $response['WasAccepted']) {
					$this->SendDebug(__FUNCTION__, 'This CommandResponse match a earlier sent command. Updating...', 0);
					$this->SetValueEx($oldObservation['Ident'], $oldObservation['Value']);

					if(isset($oldObservation['IsEnabled']) && $oldObservation['IsEnabled']==true) {
						$this->EnableAction($oldObservation['Ident']);
						$this->InitTimer();
					}

					$this->UpdateReceivedObservations($response);
				} else {
					$this->SendDebug(__FUNCTION__, 'This CommandResponse do not match a earlier sent command. Skipping update', 0);
				}
			} else {
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is not corresponding to an Ident', $Data->id), 0);
			}
		} catch(Exception $e) {
			IPS_LogMessage(IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleName'], $e->getMessage());
			$this->SendDebug(__FUNCTION__, $e->getMessage(), 0);
		}	
	}
		
	private function SetValueEx(string $Ident, $Value) {
		$oldValue = $this->GetValue($Ident);
		//if($oldValue!=$Value) {
			$this->SetValue($Ident, $Value);
			$this->SendDebug(__FUNCTION__, sprintf('Modified variable with Ident "%s". New value is  "%s"', $Ident, is_bool($Value)?$Value?"true":"false":(string)$Value), 0);
		//} else {
		//	$this->SendDebug(__FUNCTION__, sprintf('The variable with Ident "%s" has not changed. Skipping update. The value is  "%s"', $Ident, is_bool($Value)?$Value?"true":"false":(string)$Value), 0);
		//}
	}
}