<?php

declare(strict_types=1);

include __DIR__ . "/../libs/traits.php";
include __DIR__ . "/../libs/observations.php";

	class EaseeHomeEqualizer extends IPSModule {
		use Profiles;

		public function Create(){
			//Never delete this line!
			parent::Create();

			$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');

			$this->RegisterPropertyInteger('UpdateInterval', 15);
			$this->RegisterPropertyString('ProductId', '');
			$this->RegisterPropertyString('Site', '');

			$this->RegisterProfileFloat('EHEQ.Watt', 'IPS', '', ' W');

			$this->RegisterVariableFloat('CurrentUsage', 'Usage', 'EHEQ.Watt', 1);
			$this->RegisterVariableFloat('CurrentAvailable', 'Available', 'EHEQ.Watt', 2);
			
			$this->RegisterVariableFloat('VoltageNL1', 'Phase 1 (V)', '~Volt', 3);
			$this->RegisterVariableFloat('CurrentL1', 'Phase 1 (A)', '~Ampere', 4);

			$this->RegisterVariableFloat('VoltageNL2', 'Phase 2 (V)', '~Volt', 5);
			$this->RegisterVariableFloat('CurrentL2', 'Phase 2 (A)', '~Ampere', 6);

			$this->RegisterVariableFloat('VoltageNL3', 'Phase 3 (V)', '~Volt', 7);
			$this->RegisterVariableFloat('CurrentL3', 'Phase 3 (A)', '~Ampere', 8);

			$this->RegisterTimer('EaseeEqualizerRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

			$this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy(){
			$module = json_decode(file_get_contents(__DIR__ . '/module.json'));
			if(count(IPS_GetInstanceListByModuleID($module->id))==0) {
				$this->DeleteProfile('EHEQ.Watt');
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

			//$this->SetReceiveDataFilter('.*"ChildId":"' . (string)$this->InstanceID .'".*');

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
	
				$equalizerId = $this->ReadPropertyString('ProductId');

				$request = null;
				switch (strtolower($Ident)) {
					case 'refresh':
						$request = $this->Refresh($equalizerId);
						
						break;
					default:
						throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
				}

				if($request!=null) {
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
						$msg += ', missing "Buffer"';
					} else {
						$msg = 'Missing "Buffer"';
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
					switch($function) {
						case 'getdeviceobservations':  
							if(isset($result->observations)) {
								$mid = $this->ReadPropertyString('ProductId');  // GetDeviceObservations returns without the mid-propery.  Add it to all returned observations
								$activePower = [];
								foreach($result->observations as $observation) {
									if(in_array($observation->id, [40,44])) {
										$activePower[$observation->id] = $observation->value;
									} else {
										$observation->mid = $mid; 
										$this->HandleProductUpdate($observation);
									}
								}

								if(isset($activePower[40]) && isset($activePower[44])) {
									$this->SetValueEx('CurrentAvailable', ($activePower[44] - $activePower[40]));
								}
							}
							break;
						case 'subscribe':
							$chargerId = $this->ReadPropertyString('ProductId');
							$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'Subscribe','ChargerId'=>$chargerId, 'WithCurrentState' => true];
						
							$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $request]));
							break;
						default:
							throw new Exception(sprintf('Unknown function "%s()" receeived in repsponse from gateway', $function));
					}
					
					$this->SendDebug(__FUNCTION__, sprintf('Processed the result from %s(): %s...', $data->Buffer->Function, json_encode($result)), 0);
				} else {
					throw new Exception(sprintf('The gateway returned an error: %s',$result));
				}
				
			} catch(Exception $e) {
				$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(__FUNCTION__, sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), 0);
			}
		}

			private function HandleProductUpdate($Data) {
		$this->SendDebug(__FUNCTION__, sprintf('Processing Product Update: %s...', json_encode($Data)), 0);

		try{
			$change = Equalizer::GetObservation($Data);

			$this->SendDebug(__FUNCTION__, sprintf('GetObservation returned %s', json_encode($change)), 0);

			if($change!==false) {
				$this->SendDebug(__FUNCTION__, sprintf('Observation Id %d is an Id that corresponds to Ident "%s"', $Data->id, $change['Ident']), 0);
				
				$variableId = IPS_GetObjectIDByIdent($change['Ident'], $this->InstanceID);
				$variableProperties = IPS_GetVariable($variableId);
				
				$this->SendDebug(__FUNCTION__, sprintf('The cloud change occured on %d', $change['Timestamp']), 0);
				$this->SendDebug(__FUNCTION__, sprintf('The local change occured on %d', $variableProperties['VariableChanged']), 0);

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

		private function InitTimer(){
			$this->SetTimerInterval('EaseeEqualizerRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000); 
		}

		private function Refresh(string $EqualizerId) : array {
			if(strlen($EqualizerId)>0) {
				$ids = Equalizer::GetObservationIdsWithVariable().',40,44';

				$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetDeviceObservations','DeviceId'=>$EqualizerId, 'ObserationIds'=>$ids];
				
				return $request;
			}

			return [];
		}

		private function SetValueEx(string $Ident, $Value) {
			$oldValue = $this->GetValue($Ident);
			if($oldValue!=$Value) {
				$this->SetValue($Ident, $Value);
				$this->SendDebug(__FUNCTION__, sprintf('Modifed variable with Ident "%s". New value is  "%s"', $Ident, (string)$Value), 0);
			}
		}
	}