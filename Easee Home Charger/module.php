<?php

declare(strict_types=1);

include __DIR__ . "/../libs/traits.php";

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

			$this->RegisterProfileIntegerEx('EHCH.ChargerOpMode', 'Electricity', '', '', [
				[1, 'Disconnected', '', -1],
				[2, 'Awaiting Start ', '', -1],
				[3, 'Charging ', '', -1],
				[4, 'Completed ', '', -1],
				[5, 'Error' , '', -1],
				[6, 'Ready To Charge' , '', -1]
			]);

			$this->RegisterProfileIntegerEx('EHCH.StartCharging', 'Power', '', '', [
				[0, ' ', '', -1],
				[1, 'Start', '', -1],
				[2, 'Stop ', '', -1]
			]);

			$this->RegisterProfileBooleanEx('EHCH.LockCable', 'Lock', '', '', [
				[true, 'Locking...', '', -1],
				[false, 'Unlocking...', '', -1]
			]);

			$this->RegisterProfileBooleanEx('EHCH.ProtectAccess', 'Lock', '', '', [
				[true, 'Protecting...', '', -1],
				[false, 'Unprotecting...', '', -1]
			]);

			$this->RegisterVariableInteger('StartCharging', 'Charging', 'EHCH.StartCharging', 1);
			$this->EnableAction('StartCharging');

			$this->RegisterVariableInteger('Status', 'Status', 'EHCH.ChargerOpMode', 2);

			$this->RegisterVariableFloat('Voltage', 'Voltage', '~Volt', 3);

			$this->RegisterVariableFloat('Current', 'Current', '~Ampere', 4);

			$this->RegisterVariableFloat('TotalEnergi', 'Total Energi', '~Electricity', 5);
			
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

			$this->SetReceiveDataFilter('.*"ChildId":"' . (string)$this->InstanceID .'".*');

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
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);
	
				$chargerId = $this->ReadPropertyString('ProductId');

				$request = null;
				
				switch (strtolower($Ident)) {
					case 'getcommandstate':
						$request = $this->GetCommandStateReqest($chargerId, $Value);
						//$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction:GetCommandState: %s', $Value), 0);
						break;
					case 'refresh':
						if(is_string($Value)) {
							$request = $this->Refresh($chargerId, $Value);
						} else {
							$request = $this->Refresh($chargerId);
						}
						
						$this->InitTimer(); // Reset timer back to configured interval
						break;
					case 'lockcable':
						$this->SetValue($Ident, $Value);
						$this->DisableAction($Ident); // Disable variable in GUI
						
						$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerLockState','ChargerId'=>$chargerId, 'State' => $Value];
						break;
					case 'protectaccess':
						$this->SetValue($Ident, $Value);
						$this->DisableAction($Ident); // Disable variable in GUI
						
						$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerAccessLevel','ChargerId'=>$chargerId, 'UseKey' => $Value];
						break;
					case 'startcharging':
						if($Value>0){
							$this->SetValue($Ident, $Value);
							$this->DisableAction($Ident); // Disable variable in GUI
							
							$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargingState','ChargerId'=>$chargerId, 'State' => $Value==1?true:false];
						}
						break;
					default:
						throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
				}

				if($request!=null) {
					if(strtolower($Ident)!='refresh') {
						$this->PauseTimer();
					}

					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Sending a request to the gateway: %s', json_encode($request)), 0);
					$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $request]));
				}

			} catch(Exception $e) {
				$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
			}
		}

		public function ReceiveData($JSONString) {
			try {
				$data = json_decode($JSONString);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from parent: %s', json_encode($data->Buffer)), 0);
			 
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
					$ident = '';
					switch($function) {
						case 'getchargerstate':
							//IPS_SetVariableCustomProfile($this->GetIDForIdent('StartCharging'), 'EHCH.StartCharging'); 
							$this->SetValue('StartCharging', 0);
							$this->EnableAction('StartCharging');
							
							if(isset($result->chargerOpMode)) {
								$this->SetValueEx('Status', $result->chargerOpMode);
							}
							if(isset($result->voltage)) {
								$this->SetValueEx('Voltage', $result->voltage);
							}
							if(isset($result->outputCurrent)) {
								$this->SetValueEx('Current', $result->outputCurrent);
							}
							if(isset($result->lifetimeEnergy)) {
								$this->SetValueEx('TotalEnergi', $result->lifetimeEnergy);
							}

							break;
						case 'getproducts':
							break;
						case 'getchargerconfig':
							$this->EnableAction('LockCable');  
							$this->EnableAction('ProtectAccess'); 
							
							if(isset($result->lockCablePermanently)) {
								$this->SetValueEx('LockCable', $result->lockCablePermanently);
							}

							if(isset($result->authorizationRequired)) {
								$this->SetValueEx('ProtectAccess', $result->authorizationRequired);
							}
							break;
						case 'setchargerlockstate':
							$ident = 'LockCable';
						case 'setchargingstate':
							if(strlen($ident)==0) {
								$ident = 'StartCharging';
							}
							
							$commandId = -1;
							if(isset($result->commandId)) {
								$commandId =  $result->commandId;
							}

							$ticks = -1;
							if(isset($result->ticks)) {
								$ticks = $result->ticks;
							}

							if($commandId>=0 && $ticks>=0) {
								$value = ['CommandId'=>$commandId, 'Ticks'=>$ticks, 'Ident' => $ident, 'Count' => 0] ;
								$script = "IPS_RequestAction(" . (string)$this->InstanceID . " ,'GetCommandState', '" . json_encode($value) . "');";
																
								$this->RegisterOnceTimer('EaseeChargerGetCommandState' . (string)$this->InstanceID, $script); // Call GetCommandState in A new thread	
							} else {
								throw new Exception('Invalid data receieved from parent. Missing or invalid CommandId of Ticks');
							}

							break;
						case 'setchargeraccesslevel':
							$this->SendDebug(IPS_GetName($this->InstanceID), 'Quering for new charger status in 10s', 0);
							$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, 10000); // Do a extra refresh after a change in configuration
							
							break;
						case 'getcommandstate':
							$commandId = -1;
							if(isset($result->id)) {
								$commandId =  $result->id;
							}

							$ticks = -1;
							if(isset($result->ticks)) {
								$ticks = $result->ticks;
							}

							$resultCode = -1;
							if(isset($result->resultCode)) {
								$resultCode = $result->resultCode;
							}

							$ident = '';
							if(isset($data->Buffer->Ident)) {
								$ident = $data->Buffer->Ident;
							}

							$count = -1;
							if(isset($data->Buffer->Count)) {
								$count = $data->Buffer->Count;
							}

							if($commandId>=0 && $ticks>=0 && $resultCode>=0 && strlen($ident)>0 && $count>=0) {
								switch($resultCode) {
									case 2:
									case 3:
									case 4:
										$this->SendDebug(IPS_GetName($this->InstanceID), 'Quering for new charger status in 10s', 0);
										$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, 10000); 

										break;
									default:
										if($count<30) {
											$count++;

											$value = ['CommandId'=>$commandId, 'Ticks'=>$ticks, 'Ident'=> $data->Buffer->Ident, 'Count'=>$count];
											$script = "IPS_RequestAction(" . (string)$this->InstanceID . " ,'GetCommandState', '" . json_encode($value) . "');";

											$this->SendDebug(IPS_GetName($this->InstanceID), 'Waiting 1s to throttle down the queries', 0);
											sleep(1);
											
											$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Recalling GetCommandState. Count is: %d', $count), 0);

											$this->RegisterOnceTimer('EaseeChargerGetCommandState' . (string)$this->InstanceID, $script); 
										} else {
											$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('This was the last call to GetCommandState for now. Count is %d', $count), 0);
											$this->SendDebug(IPS_GetName($this->InstanceID), 'Quering for new charger status in 10s', 0);

											$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID,10000); 
										}

										break;
								}
							} else {
								throw new Exception('Invalid data receieved from parent. Missing or invalid CommandId, Ticks, ResultCode, Ident or Count');
							}

							break;
						default:
							throw new Exception(sprintf('Unknown function "%s()" receeived in repsponse from gateway', $function));
					}

					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Processed the result from %s(): %s...', $data->Buffer->Function, json_encode($result)), 0);
				} else {
					throw new Exception(sprintf('The gateway returned an error: %s',$result));
				}
				
			} catch(Exception $e) {
				$this->InitTimer();
				$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), 0);
			}
		}

		private function InitTimer(){
			$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000); 
		}

		private function PauseTimer(){
			$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, 0); 
		}

		private function Refresh(string $ChargerId, $Value=null) : array{
			if(strlen($ChargerId)>0) {
				if($Value!==null) {
					$jsonValue = json_decode($Value);

					$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerConfig','ChargerId'=>$ChargerId, 'Ident'=>$jsonValue->Ident];
				} else {
					$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerConfig','ChargerId'=>$ChargerId];
				}
				
				$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerState','ChargerId'=>$ChargerId];
					
				return $request;
			}
		}

		private function GetCommandStateReqest(string $ChargerId, string $Value) {
			$jsonValue = json_decode($Value);

			$request[] = ['ChildId'=>(string)$this->InstanceID,
						'Function'=>'GetCommandState',
						'ChargerId'=>$ChargerId,
						'CommandId'=>$jsonValue->CommandId,
						'Ticks'=>$jsonValue->Ticks,
						'Ident'=>$jsonValue->Ident,
						'Count'=>$jsonValue->Count 
					   ];

			return $request;
		}

		private function SetValueEx(string $Ident, $Value) {
			$oldValue = $this->GetValue($Ident);
			if($oldValue!=$Value) {
				$this->SetValue($Ident, $Value);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Modifed variable with Ident "%s". New value is  "%s"', $Ident, (string)$Value), 0);
			}
		}
	}