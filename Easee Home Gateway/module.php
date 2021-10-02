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
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received a request a child. The request was "%s"', $JSONString), 0);

		$data = json_decode($JSONString);
		$instructions = json_encode($data->Buffer);
		$script = "IPS_RequestAction(" . (string)$this->InstanceID . ", 'Async', '" . $instructions . "');";

		$this->SendDebug(IPS_GetName($this->InstanceID), 'Executing the request in a new thread...', 0);
				
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
			throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Function" and/or "ChildId" is missing. The request was "%s"', $Request));
		}

		$function = strtolower($request->Function);
		$childId =  strtolower($request->ChildId);
		
		switch($function) {
			case 'getproducts':
				$this->ExecuteEaseeRequest($childId, 'GetProducts');
				break;
			case 'getchargerstate':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}
				
				$this->ExecuteEaseeRequest($childId, 'GetChargerState', array($request->ChargerId));
				break;
			case 'getchargerconfig':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}
				
				$this->ExecuteEaseeRequest($childId, 'GetChargerConfig', array($request->ChargerId));
				break;
			case 'setchargerlockstate':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}

				if(!(isset($request->State) && is_bool($request->State))) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "State" is missing or is a invalid type. The request was "%s"', $Request));
				}

				$this->ExecuteEaseeRequest($childId, 'SetChargerLockState', array($request->ChargerId, $request->State));
				break;
			case 'setchargeraccesslevel':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}

				if(!(isset($request->UseKey) && is_bool($request->UseKey))) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "UseKey" is missing or is a invalid type. The request was "%s"', $Request));
				}

				$this->ExecuteEaseeRequest($childId, 'SetChargerAccessLevel', array($request->ChargerId, $request->UseKey));
				break;
			case 'setchargingstate':
				if(!isset($request->ChargerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $Request));
				}

				if(!(isset($request->State) && is_bool($request->State))) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Status" is missing or is a invalid type. The request was "%s"', $Request));
				}

				$this->ExecuteEaseeRequest($childId, 'SetChargingState', array($request->ChargerId, $request->State));
				break;
			case 'getequalizerstate':
				if(!isset($request->EqualizerId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "EqualizerId" is missing. The request was "%s"', $Request));
				}
				
				$this->ExecuteEaseeRequest($childId, 'GetEqualizerState', array($request->EqualizerId));
				break;
			default:
				throw new Exception(sprintf('HandleAsyncRequest failed. Unknown function "%s"', $function));
		}
	}

	private function ExecuteEaseeRequest(string $ChildId, string $Function, array $Args=null) {
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

			if($Args == null) {
				$result = call_user_func(array($easee, $Function));
			} else {
				$result = call_user_func_array(array($easee, $Function), $Args);
			}

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee Cloud API returned "%s" for %s()', json_encode($result), $Function), 0);
		} catch(Exception $e) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ExecuteEaseeRequest() failed for function %s(). The error was "%s"', $Function, $e->getMessage()), 0);
			$this->LogMessage(sprintf('ExecuteEaseeRequest() failed for function %s(). The error was "%s"', $Function, $e->getMessage()), KL_ERROR);
			
			$this->AddTokenToBuffer(null);	

			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		if(!isset($return['Success'])) {
			$return['Success'] = true;
			$return['Result'] = $result;
		}
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Sending the result back to the child with Id %s', (string)$ChildId), 0);
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