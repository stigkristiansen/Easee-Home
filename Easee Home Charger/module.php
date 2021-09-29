<?php

declare(strict_types=1);
	class EaseeHomeCharger extends IPSModule
	{
		public function Create(){
			//Never delete this line!
			parent::Create();

			$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');

			$this->RegisterPropertyInteger('UpdateInterval', 5);
			$this->RegisterPropertyString('ChargerId', '');

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

		public function ReceiveData($JSONString){
			$data = json_decode($JSONString);
			
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from parent: %s', json_encode($data->Buffer)), 0);
		}

		public function RequestAction($Ident, $Value) {
			try {
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, $Value), 0);
	
				switch (strtolower($Ident)) {
					case 'refresh':
						$this->Refresh();
						break;
					default:
						throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
				}
			} catch(Exception $e) {
				$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
			}
		}

		private function InitTimer(){
			$this->SetTimerInterval('EaseeChargerRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000); 
		}

		private function Refresh(){
			$chargerId = $this->ReadPropertyString('ChargerId');
			
			if(strlen($chargerId)>0) {
				$data = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetChargerState','ChargerId'=>$chargerId];
				$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $data]));
			}
		}
	}