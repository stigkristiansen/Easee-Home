<?php

declare(strict_types=1);


	class EaseeHomeDiscovery extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');

			$this->RegisterTimer('EaseeDiscoveryRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

			$this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
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
					
				$request = null;
				switch (strtolower($Ident)) {
					case 'refresh':
						$request = $this->Refresh();
						break;
					default:
						throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
				}

				if($request!=null) {
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

				$products = [];
				if($success) {
					$function = strtolower($data->Buffer->Function);
					switch($function) {
						case 'getproducts':  
							foreach($result as $site) {
								if(!isset($site->circuits) || !isset($site->equalizers)) {
									throw new Exception('Invalid data received from parent. Missing "Circuits" and/or "Equalizers"');
								}
								foreach($site->circuits as $circuit) {
									if(!isset($circuit->chargers)) {
										throw new Exception('Invalid data received from parent. Missing "Chargers"');
									}
									foreach($circuit->chargers as $charger) {
										if(!isset($charger->id) || !isset($charger->name)) {
											throw new Exception('Invalid data received from parent. Missing chargers "Name" and/or "Id"');
										}
										$products[$charger->id] = [
											'Name' => $charger->name,
											'Type' => "Charger"
										];
									}
								}
								foreach($site->equalizers as $equalizer) {
									if(!isset($equalizer->id) || !isset($equalizer->name)) {
										throw new Exception('Invalid data received from parent. Missing equalizers "Name" and/or "Id"');
									}
									$products[$equalizer->id] = [
										'Name' => $equalizer->name,
										'Type' => 'Equalizer'
									];
								}
							}
							$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Got the following products from %s()', $data->Buffer->Function, json_encode($products)), 0);							

							break;
						default:
							throw new Exception(sprintf('Unknown function "%s()" receeived in repsponse from gateway', $function));
					}
					
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Processed the result from %s(): %s...', $data->Buffer->Function, json_encode($result)), 0);
				} else {
					throw new Exception(sprintf('The gateway returned an error: %s',$result));
				}
				
			} catch(Exception $e) {
				$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), 0);
			}
		}

		private function GetEaseeInstances () : array {
			$devices = [];

			$this->SendDebug(IPS_GetName($this->InstanceID), 'Searching for existing instances of Easee modules...', 0);

			$instanceIds = array_merge(IPS_GetInstanceListByModuleID('{B469F6F0-1DC2-04A4-F0BE-EB02323E319D}'), IPS_GetInstanceListByModuleID('{E2C80DF2-CE2D-DC47-ABD8-5D969C54129A}'));
        	
        	foreach ($instanceIds as $instanceId) {
				$devices[$instanceId] = IPS_GetProperty($instanceId, 'ProductId');
			}

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Found %d instances of Easee modules', count($devices)), 0);
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Finished searching for Easee modules', 0);	

			return $devices;
		}

		private function InitTimer(){
			$this->SetTimerInterval('EaseeDiscoveryRefresh' . (string)$this->InstanceID, 15000); 
		}

		private function Refresh(){
			$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetProducts'];
				
			return $request;
			
		}

		private function Lock(string $Id){
			for ($i=0;$i<500;$i++){
				if (IPS_SemaphoreEnter("EaseeHome" . (string)$this->InstanceID . $Id, 1)){
					if($i==0) {
						$msg = sprintf('Created the Lock with id "%s"', $Id);
					} else {
						$msg = sprintf('Released and recreated the Lock with id "%s"', $Id);
					}
					$this->SendDebug(IPS_GetName($this->InstanceID), $msg, 0);
					return true;
				} else {
					if($i==0) {
						$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Waiting for the Lock with id "%s" to be released', $Id), 0);
					}
					IPS_Sleep(mt_rand(1, 5));
				}
			}
			
			$this->LogMessage(sprintf('Timedout waiting for the Lock with id "%s" to be released', $Id), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Timedout waiting for the Lock with id "%s" to be released', $Id), 0);
			
			return false;
		}
	
		private function Unlock(string $Id)
		{
			IPS_SemaphoreLeave("EaseeHome" . (string)$this->InstanceID . $Id);
	
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Removed the Lock with id "%s"', $Id), 0);
		}
	}