<?php

declare(strict_types=1);
	class EaseeHomeCharger extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->RequireParent('{55B60EF1-A0FE-F43C-5CD2-1782E17ED9C6}');
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
		}

		public function Send()
		{
			$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}']));
		}

		public function ReceiveData($JSONString)
		{
			$data = json_decode($JSONString);
			IPS_LogMessage('Device RECV', utf8_decode($data->Buffer));
		}
	}