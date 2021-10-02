<?php

declare(strict_types=1);
	class EaseeHomeCharger extends IPSModule
	{
		public function Create(){
			//Never delete this line!
			parent::Create();

			$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');

			$this->RegisterPropertyInteger('UpdateInterval', 15);
			$this->RegisterPropertyString('ChargerId', '');

			$this->RegisterVariableBoolean('LockCable', 'Lock Cable', '~Switch', 1);
			$this->EnableAction('LockCable');
			
			$this->RegisterVariableBoolean('ProtectAccess', 'Protect Access', '~Switch', 2);
			$this->EnableAction('ProtectAccess');

			$this->RegisterTimer('EaseeChargerRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

			$this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy(){
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges(){
			//Never delete this line!
			parent::ApplyChanges();

			$this->SetReceiveDataFilter('.*"ChildId":"'. (string)$this->InstanceID .'".*');

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

		public function Send(bool $State){
			//$data = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerState','ChargerId'=>'EHTWHEX7'];
			//$data = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerLockState','ChargerId'=>'EHTWHEX7', 'State' => $State];
			//$data = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerAccessLevel','ChargerId'=>'EHTWHEX7', 'UseKey' => $State];
			$data = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargingState','ChargerId'=>'EHTWHEX7', 'State' => $State];
			
			$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $data]));
		}

		public function RequestAction($Ident, $Value) {
			try {
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);
	
				$chargerId = $this->ReadPropertyString('ChargerId');

				switch (strtolower($Ident)) {
					case 'refresh':
						$request = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerConfig','ChargerId'=>$chargerId];
						$this->InitTimer(); // Reset timer back to configured interval
						break;
					case 'lockcable':
						$this->SetValue($Ident, $Value);
						$request = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerLockState','ChargerId'=>$chargerId, 'State' => $Value];
						break;
					case 'protectaccess':
						$this->SetValue($Ident, $Value);
						$request = ['ChildId'=>(string)$this->InstanceID,'Function'=>'SetChargerAccessLevel','ChargerId'=>$chargerId, 'UseKey' => $Value];
						break;
					default:
						throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
				}

				$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $request]));

			} catch(Exception $e) {
				$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
			}
		}

		public function ReceiveData($JSONString){
			try {
				$data = json_decode($JSONString);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from parent: %s', json_encode($data->Buffer)), 0);
			 
				if(!isset($data->Buffer->Function) ) {
					throw new Exception('Invalid data receieved from parent');
				} 
				if(!isset($data->Buffer->Success) ) {
					throw new Exception('Invalid data receieved from parent');
				} 
				if(!isset($data->Buffer->Result) ) {
					throw new Exception('Invalid data receieved from parent');
				} 
				
				$success = $data->Buffer->Success;
				$result = $data->Buffer->Result;

				if($success) {
					$function = strtolower($data->Buffer->Function);
					switch($function) {
						case 'getchargerstate':
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
						case 'setchargeraccesslevel':
						case 'setchargingstate':
							$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, 5000); // Do a extra refresh after a change
							break;
						default:
							throw new Exception(sprintf('Unknown function "%s" receeived in repsponse from parent', $function));
					}
					
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Processed the result from %s(): %s...', $data->Buffer->Function, json_encode($result)), 0);
				} else {
					throw new Exception(sprintf('The parent gateway returned an error: %s',$result));
				}
				
			} catch(Exception $e) {
				$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
			}
			
		}



		private function InitTimer(){
			$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000); 
		}

		private function Refresh(string $ChargerId){
			if(strlen($ChargerId)>0) {
				$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, 0); 

				$request = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerConfig','ChargerId'=>$ChargerId];
				$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $request]));

				$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000); 
			}
		}

		private function SetValueEx(string $Ident, $Value) {
			$oldValue = $this->GetValue($Ident);
			if($oldValue!=$Value) {
				$this->SetValue($Ident, $Value);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Modifed variable with Ident "%s". New value is  "%s"', $Ident, (string)$Value), 0);
			}
		}
	}