<?php

declare(strict_types=1);
	class EaseeHomeEqualizer extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->ConnectParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');
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

			$this->SetReceiveDataFilter('.*"ChildId":"'. (string)$this->InstanceID .'".*');
		}

		public function Send()
		{
			$data = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetEqualizerState','EqualizerId'=>'QH041442'];
			$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $data]));
		}

		public function ReceiveData($JSONString)
		{
			$data = json_decode($JSONString);
			
			IPS_LogMessage('Equalizer recieved', json_encode($data->Buffer));
		}
	}