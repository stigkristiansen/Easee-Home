<?php

declare(strict_types=1);

include 'easee.php';

class EaseeHomeGateway extends IPSModule
{
	private $easee;

	public function Create()
	{
		//Never delete this line!
		parent::Create();

		$this->RegisterPropertyString ('Username', '');
		$this->RegisterPropertyString ('Password', '');

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

		if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->InitEasse();
        }
	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
			$this->InitEasse();
		}
            
    }

	public function ForwardData($JSONString) {
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from a child. The data was "%s"', $JSONString), 0);

		$data = json_decode($JSONString);
		$instructions = json_encode($data->Buffer, JSON_HEX_QUOT);
		$script = "IPS_RequestAction(" . (string)$this->InstanceID . ", 'Async', '" . $instructions . "');";

		$this->SendDebug(IPS_GetName($this->InstanceID), 'Calling IPS_RunScriptText...', 0);
				
		// Call RequestAction in another thread
		IPS_RunScriptText($script);

		return true;
	
	}

	public function RequestAction($Ident, $Value) {
		try {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, $Value), 0);

			switch (strtolower($Ident)) {
				case 'async':
					$this->HandleAsyncRequest($Value);
					break;
				default:
					throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
			}
		} catch(Exception $e) {
			$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}

	private function InitEasse() {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Initializing Easee Class...', 0);

		$username = $this->ReadPropertyString('Username');
		$password = $this->ReadPropertyString('Password');

		$this->easee = new Easee($username, $password);
		$this->easee->DisableSSLCheck();
		
		try {
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Connection to Easee Cloud API...', 0);
			$this->easee->Connect();
			$token = $this->easee->GetToken();
			
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Saving Token for later use: %s', json_encode($token)), 0);
			$this->SetBuffer('Token', json_encode($token));
		
		} catch(Exception $e) {
			$this->LogMessage(sprintf('Failed to connec to Easee Cloud API. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Failed to connec to Easee Cloud API. The error was "%s"', $e->getMessage()), 0);
		}
	}

	private function HandleAsyncRequest(string $Request) {
		$request = json_decode($Request);
		
		if(!isset($request->Function)||!isset($request->ChildId)) {
			throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Function" and/or "ChildId" is missing. The request was "%s"', $Request));
		}

		$function = strtolower($request->Function);
		$childId =  strtolower($request->ChildId);

		$username = $this->ReadPropertyString('Username');
		$password = $this->ReadPropertyString('Password');

		if(strlen($username)==0) {
			throw new Exception(sprintf('HandleAsyncRequest: Missing "Username" in module "%s"', IPS_GetName($this->InstanceID)));
		}
		
		switch($function) {
			case 'getproducts':
				$this->GetProducts($childId, $username, $password);
				break;
			case 'getcharger':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}
				
				$this->GetCharger($childId, $request->ChargerId, $username, $password);
				break;
			case 'getequalizerstate':
				if(!isset($request->EqualizerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "EqualizerId" is missing. The request was "%s"', $Request));
				}
				
				$this->GetEqualizerState($childId, $request->EqualizerId, $username, $password);
				break;
			default:
				throw new Exception(sprintf('HandleAsyncRequest failed. Unknown function "%s"', $function));
		}
	}

	private function GetProducts(string $ChildId, string $Username, string $Password) {
		$JSONToken = $this->GetBuffer('Token');
		if(strlen($JSONToken)>0) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Token fetch from buffer is "%s"', $JSONToken), 0);
			$token = json_decode($JSONToken);
			$date = new DateTime($token->Expires->date, new DateTimeZone($token->Expires->timezone));
			$easee = new Easee($Username, $Password, $token->AccessToken, $token->RefreshToken, $date);
		} else {
			$easee = new Easee($Username, $Password);
		}

		$easee->DisableSSLCheck();
		
		$result = $easee->GetProducts();
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetProducts()', json_encode($result)), 0);
		
		// To do
		// Format $products to only include products and neccessary properties from $result
		$products = $result;

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $products]));
	}

	private function GetEqualizerState(string $ChildId, string $EqualizerId, string $Username, string $Password) {
		$JSONToken = $this->GetBuffer('Token');
		if(strlen($JSONToken)>0) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Token fetch from buffer is "%s"', $JSONToken), 0);
			$token = json_decode($JSONToken);
			$date = new DateTime($token->Expires->date, new DateTimeZone($token->Expires->timezone));
			$easee = new Easee($Username, $Password, $token->AccessToken, $token->RefreshToken, $date);
		} else {
			$easee = new Easee($Username, $Password);
		}
		
		$easee->DisableSSLCheck();
		
		$result = $easee->GetEqualizerState($EqualizerId);
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetEqualizerState()', json_encode($result)), 0);

		// To do
		// Format $product to only include neccessary properties
		$product = $result;

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $product]));
	}

	private function GetCharger(string $ChildId, $ChargerId, string $Username, string $Password) {
		$JSONToken = $this->GetBuffer('Token');
		if(strlen($JSONToken)>0) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Token fetch from buffer is "%s"', $JSONToken), 0);
			$token = json_decode($JSONToken);
			$date = new DateTime($token->Expires->date, new DateTimeZone($token->Expires->timezone));
			$easee = new Easee($Username, $Password, $token->AccessToken, $token->RefreshToken, $date);
		} else {
			$easee = new Easee($Username, $Password);
		}

		$easee->DisableSSLCheck();
		
		$result = $easee->GetCharger($ChargerId);
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetCharger()', json_encode($result)), 0);

		// To do
		// Format $product to only include neccessary properties
		$product = $result;

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $product]));
	}
}