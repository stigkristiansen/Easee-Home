<?php



declare(strict_types=1);

	class EaseeHomeEqualizer extends IPSModule

		public function Create(){
			//Never delete this line!
			parent::Create();

			$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');

			$this->RegisterPropertyInteger('UpdateInterval', 15);
			$this->RegisterPropertyString('ProductId', '');

			$this->RegisterTimer('EaseeEqualizerRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

			$this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy(){
			
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
	
				$productId = $this->ReadPropertyString('ProductId');

				$request = null;
				switch (strtolower($Ident)) {
					case 'refresh':
						$request = $this->Refresh($productId);
						
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

				if($success) {
					$function = strtolower($data->Buffer->Function);
					switch($function) {
						case 'getequalizerstate':  
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

		private function InitTimer(){
			$this->SetTimerInterval('EaseeEqualizerRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000); 
		}

		private function Refresh(string $ProductId){
			if(strlen($ProductId)>0) {
				$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetEqualizerState','ProductId'=>$ProductId];
				
				return $request;
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