<?php

declare(strict_types=1);

include __DIR__ . "/../libs/easee.php";
include __DIR__ . "/../libs/traits.php";


class EaseeHomeGateway extends IPSModule {
	use Buffer;

	public function Create() {
		//Never delete this line!
		parent::Create();

		$this->RegisterPropertyString ('Username', '');
		$this->RegisterPropertyString ('Password', '');
		$this->RegisterPropertyString ('APIKey', '');
		$this->RegisterPropertyBoolean('SkipSSLCheck', true);

		$this->RegisterTimer('EaseeHomeRefreshToken' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "RefreshToken", 0);'); 

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);

		$this->RequireParent('{D68FD31F-0E90-7019-F16C-1949BD3079EF}');
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

		$this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

		if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->InitEasee();
        }
	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

		if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
			$this->InitEasee();
			return;
		}

		$this->HandleParentMessages($TimeStamp, $SenderID, $Message, $Data);
		
    }

	private function HandleParentMessages($TimeStamp, $SenderID, $Message, $Data) {
		$this->SendDebug(__FUNCTION__, sprintf('Instance %d sendt message %d: %s', $SenderID, $Message, json_encode($Data)), 0);
		
		switch ($Message) {
			case FM_CONNECT:
				$localConfig = IPS_GetInstance($this->InstanceID);
				$parentConfig = IPS_GetInstance($localConfig['ConnectionID']);
				if($parentConfig['ModuleInfo']['ModuleID']!='{D68FD31F-0E90-7019-F16C-1949BD3079EF}') {
					$this->SendDebug(__FUNCTION__, 'The gateway can only connect to a WebSocket client instance', 0);
					IPS_DisconnectInstance($this->InstanceID);
					return;
				} 
			case IM_CHANGESTATUS:
				$this->StartSignalR();
		}		
	}

	private function RegisterParentMessages(bool $Enable) {
		$parent = $this->GetConnectionId();

		if($Enable) {
			$this->SendDebug(__FUNCTION__, 'Registering for receiving parent instance messages', 0);	
			
			$this->RegisterMessage($parent, IM_CHANGESETTINGS);
			$this->RegisterMessage($parent, IM_CHANGESTATUS);
			$this->RegisterMessage($parent, IM_DISCONNECT);
			$this->RegisterMessage($parent, IM_CONNECT);
		} else {
			$this->SendDebug(__FUNCTION__, 'Unregistering for receiving parent instance messages', 0);	
			
			$this->UnregisterMessage($parent, IM_CHANGESETTINGS);
			$this->UnregisterMessage($parent, IM_CHANGESTATUS);
			$this->UnregisterMessage($parent, IM_DISCONNECT);
			$this->UnregisterMessage($parent, IM_CONNECT);
		}
		
	}

	public function GetConfigurationForParent() {
		$config['Type'] = 0;
		$config['VerifyCertificate'] = !$this->ReadPropertyBoolean('SkipSSLCheck');
		
		$config['URL'] = SignalR::BuildWebSocketUrl();
	
		return json_encode($config);
	}

	private function StartSignalR($Token=null) {
		if($Token==null) {
			$token = $this->GetTokenFromBuffer();
		} else {
			$token = $Token;
		}
				
		if($token!=null) {
			$this->SendDebug(__FUNCTION__, 'Preparing WebSockets for communication through SignalR...', 0);
			$config = json_decode($this->GetConfigurationForParent(), true);

			$headers[] = ['Name' => 'Authorization', 'Value' => 'Bearer ' . $token->AccessToken];	
			$headers[] = ['Name' => 'User-Agent', 'Value' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36'];
    	    $headers[] = ['Name' => 'Accept-Encoding', 'Value' => 'gzip, deflate, br, zstd'];
        	$headers[] = ['Name' => 'Accept-Language', 'Value' => 'en-US,en;q=0.9,nb;q=0.8,en-GB;q=0.7,no;q=0.6'];
		
			$config['Active'] = true;
        	$config['Headers'] = json_encode($headers);

			$this->RegisterParentMessages(false);
			
			$parent = $this->GetConnectionId();
        	IPS_SetConfiguration($parent, json_encode($config));
        	IPS_ApplyChanges($parent);

			$hasActiveParent = false;
			for($i=1;$i<=100;$i++) {
				if($this->HasActiveParent()) {
					$hasActiveParent = true;
					$this->SendDebug(__FUNCTION__, 'Websocket I/O instance is set active. Sending handshake...', 0);
					break;
				}
				IPS_Sleep(100);
			}

			if(!$hasActiveParent) {
				$this->SendDebug(__FUNCTION__, 'Timed out waiting for active WebSocket client!', 0);
				return;
			}

			$this->SendDebug(__FUNCTION__, 'Sending ' . SignalR::Handshake() . '...', 0);
			
			$this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => SignalR::Handshake()]));

			$this->RegisterParentMessages(true);
		}
    }

	private function SubscribeToSignalR(string $Serial, bool $WithCurrentStage) {
		$this->SendDebug(__FUNCTION__, 'Sending ' . SignalR::Subscribe($Serial, $WithCurrentStage) . '...', 0);
		
		$this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => SignalR::Subscribe($Serial, $WithCurrentStage)]));
	}

	private function GetConnectionId() {
		$config = IPS_GetInstance($this->InstanceID);
		return $config['ConnectionID'];
	}

	public function ReceiveData($JSONString) {
		$this->SendDebug(__FUNCTION__, sprintf('Received data from Easee Cloud. The data was "%s"', $JSONString), 0);

		$buffer = json_decode($JSONString, true)['Buffer'];
		$data = explode(chr(0x1E), $buffer);
		unset($data[sizeof($data)-1]);

		$this->SendDebug(__FUNCTION__, sprintf('Received command(s) are: %s', json_encode($data)), 0);

		foreach($data as $info) {
			
			$decodedInfo = json_decode($info, true);
			
			if(isset($decodedInfo['type'])) {
				switch($decodedInfo['type']) {
					case 1:
						$forwardingData =[
							'Function' => $decodedInfo['target'],
							'Success' => true
						];

						foreach($decodedInfo['arguments'] as $argument) {
							switch(strtolower($decodedInfo['target'])) {
								case 'productupdate':
									$forwardingData['SerialNumber'] = $argument['mid'];
									break;
								case 'commandresponse':
									$forwardingData['SerialNumber'] = $argument['serialNumber'];
									break;
							}

							$forwardingData['Result'] = $argument;

							$this->SendDebug(__FUNCTION__, sprintf('Sending to children: %s', json_encode($forwardingData)), 0);
							
							$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "Buffer" => $forwardingData]));
						}

						break;
					case 6: // Ping
						$this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => SignalR::Ping()]));
						break;
				}
			}

			if($decodedInfo==[]) {
				$this->SendDebug(__FUNCTION__, 'The handshake was successful, sending instructions to all children to start a subscription for SignalR data', 0);

				$instruction = [
					'Function' => 'Subscribe',
					'Success' => true,
					'Result' => ''
				];
			
				$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => '##AllChildren##', "Buffer" => $instruction]));
			}
		}
	}

	public function ForwardData($JSONString) {
		$this->SendDebug(__FUNCTION__, sprintf('Received a request from a child. The request was "%s"', $JSONString), 0);

		$data = json_decode($JSONString);
		$requests = json_encode($data->Buffer);

		$script = "IPS_RequestAction(" . (string)$this->InstanceID . ", 'Async', '" . $requests . "');";

		$this->SendDebug(__FUNCTION__, 'Executing the request(s) in a new thread...', 0);
				
		// Call RequestAction in another thread
		IPS_RunScriptText($script);

		return true;
	
	}

	public function RequestAction($Ident, $Value) {
		try {
			$this->SendDebug(__FUNCTION__, sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, $Value), 0);

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
			$this->SendDebug(__FUNCTION__, sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}

	private function RefreshToken() {
		$this->SendDebug(__FUNCTION__, 'Refreshing the Easee Class...', 0);

		$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, 0); // Disable the timer

		$easee = null;
		
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');
			$apiKey = $this->ReadPropertyString('APIKey');	

			$easee = new Easee($username, $password, $apiKey, $token->AccessToken, $token->RefreshToken, $token->Expires);
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

			$this->SendDebug(__FUNCTION__, sprintf('Saving refreshed Token for later use: %s', json_encode($token)), 0);
			$this->AddTokenToBuffer($token);

			$expiresIn = ($token->ExpiresIn-5*60); // Set to 5 minutes before token timeout

			$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, $expiresIn*1000); 
			$this->SendDebug(__FUNCTION__, sprintf('Token Refresh Timer set to %s second(s)', (string)$expiresIn), 0);
		} catch(Exception $e) {
			$this->AddTokenToBuffer(null);	
			throw new Exception(sprintf('RefreshToken() failed. The error was "%s"', $e->getMessage()));
		}
	}

	private function InitEasee() : object {
		$this->SendDebug(__FUNCTION__, 'Initializing the Easee Class...', 0);

		$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, 0); // Disable the timer

		$username = $this->ReadPropertyString('Username');
		$password = $this->ReadPropertyString('Password');
		$apiKey = $this->ReadPropertyString('APIKey');

		if(strlen($username)==0 || strlen($apiKey)==0) {
			$this->LogMessage(sprintf('InitEasee(): Missing property "Username" and/or "API Key" in module "%s"', __FUNCTION__), KL_ERROR);
			$this->SendDebug(__FUNCTION__, sprintf('InitEasee(): Missing property "Username" in module "%s"', IPS_GetName($this->InstanceID)), 0);
			
			return null;
		}

		$easee = new Easee($username, $password, $apiKey);
		
		if($this->ReadPropertyBoolean('SkipSSLCheck')) {
			$easee->DisableSSLCheck();
		}
		
		try {
			$this->SendDebug(__FUNCTION__, 'Connecting to Easee Cloud API...', 0);
			$easee->Connect();
			$token = $easee->GetToken();
			
			$this->SendDebug(__FUNCTION__, sprintf('Saving Token for later use: %s', json_encode($token)), 0);
			$this->AddTokenToBuffer($token);
			
			$expiresIn = ($token->ExpiresIn-5*60); // Set to 5 minutes before token timeout

			$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, $expiresIn*1000); 
			$this->SendDebug(__FUNCTION__, sprintf('Token Refresh Timer set to %s second(s)', (string)$expiresIn), 0);

			$this->StartSignalR($token);

		} catch(Exception $e) {
			$this->LogMessage(sprintf('Failed to connect to Easee Cloud API. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(__FUNCTION__, sprintf('Failed to connec to Easee Cloud API. The error was "%s"', $e->getMessage()), 0);
			
			return null;
		}

		return $easee;
	}

	private function HandleAsyncRequest(string $Requests) {
		$requests = json_decode($Requests);

		foreach($requests as $request) {
		
			if(!isset($request->Function)||!isset($request->ChildId)) {
				throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Function" and/or "ChildId" is missing. The request was "%s"', $request));
			}

			$function = strtolower($request->Function);
			$childId =  strtolower($request->ChildId);
			
			switch($function) {
				case 'subscribe':
					if(!isset($request->ChargerId)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $request));
					}
					
					if(!isset($request->WithCurrentState)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "WithCurrentState" is missing. The request was "%s"', $request));
					}

					$this->SendDebug(__FUNCTION__, sprintf('Sending a SignalR Subscribe request to the Easee Cloud for device with product id %s', $request->ChargerId), 0);

					$this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => SignalR::Subscribe($request->ChargerId, $request->WithCurrentState)]));
					break;
				case 'getproducts':
					$this->ExecuteEaseeRequest($childId, 'GetProducts');
					break;
				case 'getcommandstate':
					if(!isset($request->ChargerId)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $request));
					}

					if(!(isset($request->CommandId) && is_integer($request->CommandId))) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "CommandId" is missing or is a invalid type. The request was "%s"', $request));
					}

					if(!(isset($request->Ticks) && is_integer($request->Ticks))) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Tiks" is missing or is a invalid type. The request was "%s"', $request));
					}

					if(!(isset($request->Ident) && is_string($request->Ident))) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Ident" is missing or is a invalid type. The request was "%s"', $request));
					}

					if(!(isset($request->Count) && is_integer($request->Count))) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Count" is missing or is a invalid type. The request was "%s"', $request));
					}

					$this->ExecuteEaseeRequest($childId, 'GetCommandState', array($request->ChargerId, $request->CommandId, $request->Ticks), $request->Ident, $request->Count);
					break;
				case 'getchargerstate':
					if(!isset($request->ChargerId) || !isset($request->ObserationIds)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" and/or "ObservationIds" is missing. The request was "%s"', $request));
					}
					
					$this->ExecuteEaseeRequest($childId, 'GetChargerState', array($request->ChargerId, $request->ObserationIds));
					break;
				case 'getchargerconfig':
					if(!isset($request->ChargerId)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" is missing. The request was "%s"', $request));
					}

					if(isset($request->Ident) && is_string($request->Ident)) {
						$this->ExecuteEaseeRequest($childId, 'GetChargerConfig', array($request->ChargerId), $request->Ident);
					} else {
						$this->ExecuteEaseeRequest($childId, 'GetChargerConfig', array($request->ChargerId));
					}
					
					break;
				case 'setchargerlockstate':
					if(!isset($request->ChargerId) || !isset($request->Ident)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" and/or "Ident" is missing. The request was "%s"', $request));
					}

					if(!(isset($request->State) && is_bool($request->State))) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "State" is missing or is a invalid type. The request was "%s"', $request));
					}

					$this->ExecuteEaseeRequest($childId, 'SetChargerLockState', array($request->ChargerId, $request->State), $request->Ident);
					break;
				case 'setchargeraccesslevel':
					if(!isset($request->ChargerId) || !isset($request->Ident)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" and/or "Ident" is missing. The request was "%s"', $request));
					}

					if(!(isset($request->UseKey) && is_bool($request->UseKey))) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "UseKey" is missing or is a invalid type. The request was "%s"', $request));
					}

					$this->ExecuteEaseeRequest($childId, 'SetChargerAccessLevel', array($request->ChargerId, $request->UseKey), $request->Ident);
					break;
				case 'setchargingstate':
					if(!isset($request->ChargerId) || !isset($request->Ident)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "ChargerId" and/or "Ident" is missing. The request was "%s"', $request));
					}

					if(!(isset($request->State) && is_bool($request->State))) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Status" is missing or is a invalid type. The request was "%s"', $request));
					}

					$this->ExecuteEaseeRequest($childId, 'SetChargingState', array($request->ChargerId, $request->State), $request->Ident);
					break;
				case 'getequalizerstate':
					if(!isset($request->EqualizerId)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "EqualizerId" is missing. The request was "%s"', $request));
					}
					
					$this->ExecuteEaseeRequest($childId, 'GetEqualizerState', array($request->EqualizerId));
					break;
				default:
					throw new Exception(sprintf('HandleAsyncRequest failed. Unknown function "%s"', $function));
			}
		}
	}

	private function ExecuteEaseeRequest(string $ChildId, string $Function, array $Args=null, string $Ident=null, int $Count=null) {
		
		$this->SendDebug(__FUNCTION__, sprintf('Executing Easee::%s() for component with id %s...', $Function, isset($Args[0])?$Args[0]:'N/A'), 0);

		$easee = [];
				
		$token = $this->GetTokenFromBuffer();
		if($token==null) {
			$easee = $this->InitEasee();
		} else {
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');
			$apiKey = $this->ReadPropertyString('APIKey');

			$easee = new Easee($username, $password, $apiKey, $token->AccessToken, $token->RefreshToken, $token->Expires);
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
			
			$this->SendDebug(__FUNCTION__, sprintf('Easee Cloud API returned "%s" for %s()', json_encode($result), $Function), 0);
			
		} catch(Exception $e) {
			$this->SendDebug(__FUNCTION__, sprintf('ExecuteEaseeRequest() failed for function %s(). The error was "%s:%d"', $Function, $e->getMessage(), $e->getCode()), 0);
			$this->LogMessage(sprintf('ExecuteEaseeRequest() failed for function %s(). The error was "%s"', $Function, $e->getMessage()), KL_ERROR);
			
			// No need to reset token if it is just rate limited
			if($e->getCode()!=429) {
				$this->AddTokenToBuffer(null);	
			}

			if($Ident!==null) {
				$return['Ident'] = $Ident;
			}
			
			if($Count!==null) {
				$return['Count'] = $Count;
			}
			
			$return['Success'] = false;
			$return['Result'] = $e->getMessage();
		}

		if(!isset($return['Success'])) {
			if($Ident!==null) {
				$return['Ident'] = $Ident;
			}
			
			if($Count!==null) {
				$return['Count'] = $Count;
			}
			
			$return['Success'] = true;
			$return['Result'] = $result;
		}
		
		$this->SendDebug(__FUNCTION__, sprintf('Sending the result from function %s back to the child with Id %s', $Function, (string)$ChildId), 0);
		$this->SendDataToChildren(json_encode(["DataID" => "{47508B62-3B4E-67BE-0F29-0B82A2C62B58}", "ChildId" => $ChildId, "Buffer" => $return]));
	}

	private function GetTokenFromBuffer() {
		if($this->Lock('Token')) {
			$jsonToken = $this->GetBuffer('Token');
			
			if(strlen($jsonToken)==0) {
				$this->SendDebug(__FUNCTION__, sprintf('Missing token in the buffer', $jsonToken), 0);
				$this->Unlock('Token');
				return null;
			}

			$this->SendDebug(__FUNCTION__, sprintf('Got token "%s" from the buffer', $jsonToken), 0);
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
			$this->SendDebug(__FUNCTION__, sprintf('Added token "%s" to the buffer', $token), 0);
			$this->Unlock('Token');
		}
	}

}