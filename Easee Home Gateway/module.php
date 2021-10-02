<?php

declare(strict_types=1);

include 'easee.php';

class EaseeHomeGateway extends IPSModule
{
	public function Create()
	{
		//Never delete this line!
		parent::Create();

		$this->RegisterPropertyString ('Username', '');
		$this->RegisterPropertyString ('Password', '');
		$this->RegisterPropertyBoolean('SkipSSLCheck', true);

		$this->RegisterTimer('EaseeHomeRefreshToken' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "RefreshToken", 0);'); 

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
            $this->InitEasee();
        }
	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

		if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
			$this->InitEasee();
		}
    }

	public function ForwardData($JSONString) {
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from a child. The data was "%s"', $JSONString), 0);

		$data = json_decode($JSONString);
		$instructions = json_encode($data->Buffer);
		$script = "IPS_RequestAction(" . (string)$this->InstanceID . ", 'Async', '" . $instructions . "');";

		$this->SendDebug(IPS_GetName($this->InstanceID), 'Calling RequestAction in a new thread...', 0);
				
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
				case 'refreshtoken':
					$this->RefreshToken();
					break;
				default:
					throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
			}
		} catch(Exception $e) {
			$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}

	private function RefreshToken() {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Refreshing the Easee Class...', 0);

		$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, 0); // Disable the timer

		$easee = null;
		
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');	

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		try {
			if($easee==null) {
				throw new Exception('Unable to refresh the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$easee->RefreshToken();

			$token = $easee->GetToken();

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Saving refreshed Token for later use: %s', json_encode($token)), 0);
			$this->AddTokenToBuffer($token);

			$expiresIn = ($token->ExpiresIn-5*60); // Set to 5 minutes before token timeout

			$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, $expiresIn*1000); 
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Token Refresh Timer set to %s second(s)', (string)$expiresIn), 0);
		} catch(Exception $e) {
			$this->AddTokenToBuffer(null);	
			throw new Exception(sprintf('RefreshToken() failed. The error was "%s"', $e->getMessage()));
		}
	}

	private function InitEasee() {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Initializing the Easee Class...', 0);

		$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, 0); // Disable the timer

		$username = $this->ReadPropertyString('Username');
		$password = $this->ReadPropertyString('Password');

		if(strlen($username)==0) {
			$this->LogMessage(sprintf('InitEasee(): Missing property "Username" in module "%s"', IPS_GetName($this->InstanceID)), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('InitEasee(): Missing property "Username" in module "%s"', IPS_GetName($this->InstanceID)), 0);
			
			return null;
		}

		$easee = new Easee($username, $password);
		
		if($this->ReadPropertyBoolean('SkipSSLCheck')) {
			$easee->DisableSSLCheck();
		}
		
		try {
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Connecting to Easee Cloud API...', 0);
			$easee->Connect();
			$token = $easee->GetToken();
			
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Saving Token for later use: %s', json_encode($token)), 0);
			$this->AddTokenToBuffer($token);
			
			$expiresIn = ($token->ExpiresIn-5*60); // Set to 5 minutes before token timeout

			$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, $expiresIn*1000); 
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Token Refresh Timer set to %s second(s)', (string)$expiresIn), 0);
		} catch(Exception $e) {
			$this->LogMessage(sprintf('Failed to connect to Easee Cloud API. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Failed to connec to Easee Cloud API. The error was "%s"', $e->getMessage()), 0);
			return null;
		}

		return $easee;
	}

	private function HandleAsyncRequest(string $Request) {
		$request = json_decode($Request);
		
		if(!isset($request->Function)||!isset($request->ChildId)) {
			throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Function", "ChildId" and/or "Parameter" is missing. The request was "%s"', $Request));
		}

		$function = strtolower($request->Function);
		$childId =  strtolower($request->ChildId);
		
		switch($function) {
			case 'getproducts':
				$this->GetProducts($childId);
				break;
			case 'getchargerstate':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}
				
				$this->GetChargerState($childId, $request->ChargerId);
				break;
			case 'getchargerconfig':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}
				
				$this->ExecuteEaseeRequest($childId, 'GetChargerConfig', array($request->ChargerId));

				//$this->GetChargerConfig($childId, $request->ChargerId);
				break;
			case 'setchargerlockstate':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}

				if(!(isset($request->State) && is_bool($request->State))) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "State" is missing or is a invalid type. The request was "%s"', $Request));
				}

				$this->SetChargerLockState($childId, $request->ChargerId, $request->State);
				break;
			case 'setchargeraccesslevel':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}

				if(!(isset($request->UseKey) && is_bool($request->UseKey))) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "UseKey" is missing or is a invalid type. The request was "%s"', $Request));
				}

				$this->SetChargerAccessLevel($childId, $request->ChargerId, $request->UseKey);
				break;
			case 'setchargingstate':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}

				if(!(isset($request->State) && is_bool($request->State))) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Status" is missing or is a invalid type. The request was "%s"', $Request));
				}

				$this->SetChargingState($childId, $request->ChargerId, $request->State);
				break;
			case 'getequalizerstate':
				if(!isset($request->EqualizerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "EqualizerId" is missing. The request was "%s"', $Request));
				}
				
				$this->GetEqualizerState($childId, $request->EqualizerId);
				break;
			default:
				throw new Exception(sprintf('HandleAsyncRequest failed. Unknown function "%s"', $function));
		}
	}

	private function GetProducts(string $ChildId) {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Getting all products...', 0);

		$easee = null;
		
		$token = $this->GetTokenFromBuffer();

		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');	

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = 'GetProducts';

		try {
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$result = $easee->GetProducts();
			
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetProducts()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Resetting token. GetEqualizerState() failed. The error was "%s"', $e->getMessage()), 0);
			$this->LogMessage(sprintf('Resetting token. GetEqualizerState() failed. The error was "%s"', $e->getMessage()), KL_ERROR);

			$this->AddTokenToBuffer(null);	
			
			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		$return['Success'] = true;
		$return['Result'] = $result;

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}

	private function GetEqualizerState(string $ChildId, string $EqualizerId) {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Getting equalizers state...', 0);

		$easee = null;

		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = 'GetEqualizerState';

		try {
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$result = $easee->GetEqualizerState($EqualizerId);
			
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetEqualizerState()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Resetting token. GetEqualizerState() failed. The error was "%s"', $e->getMessage()), 0);
			$this->LogMessage(sprintf('Resetting token. GetEqualizerState() failed. The error was "%s"', $e->getMessage()), KL_ERROR);

			$this->AddTokenToBuffer(null);	
			
			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		$return['Success'] = true;
		$return['Result'] = $result;

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}

	private function GetChargerState(string $ChildId, string $ChargerId) {
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Getting state for charger with id %s...', $ChargerId), 0);

		$easee = null;
		
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = 'GetChargerState';

		try{
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$result = $easee->GetChargerState($ChargerId);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetChargerState()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->AddTokenToBuffer(null);	
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('GetChargerState() failed. The error was "%s"', $e->getMessage()), 0);
			$this->LogMessage(sprintf('GetChargerState() failed. The error was "%s"', $e->getMessage()), KL_ERROR);

			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		$return['Success'] = true;
		$return['Result'] = $result;

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));

	}

	// $Args0 = ProductId ;
	// $Args1-$ArgsX = Extra arguments if necessary
	
	private function ExecuteEaseeRequest(string $ChildId, string $Function, array $Args) {
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Executing Easee::%s() for component with id %s...', $Function, $Args[0]), 0);

		$easee = null;
				
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = $Function;

		try{
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			//$result = $easee->GetChargerConfig($ChargerId);

			$result = call_user_func_array(array($easee, $Function), $Args);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetChargerState()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Resetting token. %s() failed. The error was "%s"', $Function, $e->getMessage()), 0);
			$this->LogMessage(sprintf('Resetting token. %s() failed. The error was "%s"', $Function, $e->getMessage()), KL_ERROR);
			
			$this->AddTokenToBuffer(null);	

			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		if(!isset($return['Success'])) {
			$return['Success'] = true;
			$return['Result'] = $result;
		}
		
		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}

	private function GetChargerConfig(string $ChildId, string $ChargerId) {
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Getting config for charger with id %s...', $ChargerId), 0);

		$easee = null;
		
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = 'GetChargerConfig';

		try{
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$result = $easee->GetChargerConfig($ChargerId);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetChargerState()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Resetting token. GetChargerConfig() failed. The error was "%s"', $e->getMessage()), 0);
			$this->LogMessage(sprintf('Resetting token. GetChargerConfig() failed. The error was "%s"', $e->getMessage()), KL_ERROR);
			
			$this->AddTokenToBuffer(null);	

			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		if(!isset($return['Success'])) {
			$return['Success'] = true;
			$return['Result'] = $result;
		}
		
		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}

	private function SetChargerLockState(string $ChildId, string $ChargerId, bool $State){
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Changing chargers cable lock state...', 0);

		$easee = null;
		
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = 'SetChargerLockState';

		try{
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$result = $easee->SetChargerLockState($ChargerId, $State);
		
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for SetChargerLockState()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Resetting token. SetChargerLockState() failed. The error was "%s"', $e->getMessage()), 0);
			$this->LogMessage(sprintf('Resetting token. SetChargerLockState() failed. The error was "%s"', $e->getMessage()), KL_ERROR);
			
			$this->AddTokenToBuffer(null);	
			
			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		if(!isset($return['Success'])) {
			$return['Success'] = true;
			$return['Result'] = $result;
		}

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}

	private function SetChargerAccessLevel(string $ChildId, string $ChargerId, bool $UseKey){
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Changing chargers access level...', 0);

		$easee = null;
		
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = 'SetChargerAccessLevel';

		try{
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$result = $easee->SetChargerAccessLevel($ChargerId, $UseKey);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for SetChargerAccessLevel()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Resetting token. SetChargerAccessLevel() failed. The error was "%s"', $e->getMessage()), 0);
			$this->LogMessage(sprintf('Resetting token. SetChargerAccessLevel() failed. The error was "%s"', $e->getMessage()), KL_ERROR);
			
			$this->AddTokenToBuffer(null);	

			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		if(!isset($return['Success'])) {
			$return['Success'] = true;
			$return['Result'] = $result;
		}

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}

	

	private function SetChargingState(string $ChildId, string $ChargerId, bool $State) {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Changing chargers Charge Status...', 0);
		$easee = null;
		
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');

			$easee = new Easee($username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
		}

		$return['Function'] = 'SetChargingState';

		try{
			if($easee==null) {
				throw new Exception('Unable to initialize the Easee class');
			}

			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}

			$result = $easee->SetChargingState($ChargerId, $State);
		
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for SetChargingState()', json_encode($result)), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Resetting token. SetChargingState() failed. The error was "%s"', $e->getMessage()), 0);
			$this->LogMessage(sprintf('Resetting token. SetChargingState() failed. The error was "%s"', $e->getMessage()), KL_ERROR);

			$this->AddTokenToBuffer(null);	
			
			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		if(!isset($return['Success'])) {
			$return['Success'] = true;
			$return['Result'] = $result;
		}

		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}


	private function GetTokenFromBuffer(){
		if($this->Lock('Token')) {
			$jsonToken = $this->GetBuffer('Token');
			
			if(strlen($jsonToken)==0) {
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Missing token in the buffer', $jsonToken), 0);
				$this->Unlock('Token');
				return null;
			}

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Got token "%s" from the buffer', $jsonToken), 0);
			$this->Unlock('Token');
			
			$token = json_decode($jsonToken);
			$expires = new DateTime($token->Expires->date, new DateTimeZone($token->Expires->timezone));
			$token->Expires = $expires; 
			
			return $token;
		} 

		return null;
	}

	private function AddTokenToBuffer($Token) {
		if($this->Lock('Token')) {
			if($Token==null)
				$token = '';
			else
				$token = json_encode($Token);
			$this->SetBuffer('Token', $token);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Added token "%s" to the buffer', $token), 0);
			$this->Unlock('Token');
		}
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