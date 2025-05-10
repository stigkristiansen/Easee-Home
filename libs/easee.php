<?php

declare(strict_types=1);

class SignalR {
    const ENDPOINT = 'https://streams.easee.com/hubs/chargers';

    private $connectionId;
    private $connectionToken;
    private $availableTransports;
    private $negotiateVersion;

    const PUT = 'PUT';
    const GET = 'GET';
    const POST = 'POST';

    const PROTOCOL_NONE = 0;
    const PROTOCOL_WEBSOCKETS = 1;
    const PROTOCOL_SSE = 2;
    const PROTOCOL_ANY = 3;

    private $accessToken;
    private $forcedProtocol;
    private $verifyTLS;

    public function __construct(string $AccessToken, bool $VerifyTLS=true, int $ForcedProtocol = self::PROTOCOL_WEBSOCKETS ) {
        $this->accessToken = $AccessToken;
        $this->forcedProtocol = $ForcedProtocol;
        $this->verifyTLS = $VerifyTLS;
    }

    static function BuildWebSocketUrl() {
        $search = 'https';
        $replace = 'wss';
        
        return str_replace($search, $replace, self::ENDPOINT);
    }

    public function Negotiate() {
        $url = sprintf('%s/negotiate?negotiateVersion=1', self::ENDPOINT);
        
        $result = self::HttpRequest(self::POST, $url);

        if($result['error']===false) {
            if(is_array($result['result'])) {
                if(isset($result['result']['error'])) {
                    throw new Exception(sprintf('Negotiate failed. %s',$result['result']['error']));        
                }

                $this->connectionId = $result['result']['connectionId'];
                $this->connectionToken = $result['result']['connectionToken'];
                $this->availableTransports = $result['result']['availableTransports'];
                $this->negotiateVersion = $result['result']['negotiateVersion'];

                if($this->negotiateVersion!=1) {
                    throw new Exception(sprintf('Negotiate failed. It does not support version 1. Version supported is: %d',$this->negotiateVersion));    
                }
                
                foreach($this->availableTransports as $transport) {
                    switch(strtolower($transport['transport'])) {
                        case 'websockets':
                            $this->selectedProtocol = self::PROTOCOL_WEBSOCKETS;
                            break;
                        case 'serversentevents':
                            $this->selectedProtocol = self::PROTOCOL_SSE;
                            break;
                        default:
                            $this->selectedProtocol = self::PROTOCOL_NONE;
                    }

                    if($this->forcedProtocol==$this->selectedProtocol) {
                        break;
                    }

                    if($this->forcedProtocol==self::PROTOCOL_ANY && $this->selectedProtocol!=self::PROTOCOL_NONE) {
                        break;
                    }
                }

                if($this->selectedProtocol==self::PROTOCOL_NONE) {
                    throw new Exception('Negotiate failed. Service does not support WebSockets or SSE.');
                }
            } else {
                throw new Exception(sprintf('Negotiate failed. Unknown data: %s',$result['result']));        
            }
        } else {
            throw new Exception(sprintf('Negotiate failed%s. %s', $result['httpcode']>0?' ('.(string)$result['httpcode'].')':'' , $result['errortext']));
        }
    }

    public function Handshake() {
         return sprintf('{"protocol":"json","version":1}%s', chr(0x1E));
    }

    public function Subscribe(string $Serial) {
         return sprintf('{"arguments":["%s",true],"invocationId":"1","target":"SubscribeWithCurrentState","type":1}%s', $Serial, chr(0x1E));
    }

    private function HttpRequest($Type, $Url, $Body=null) {
		$ch = curl_init();

        switch($Type) {
			case self::PUT:
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
				break;
			case self::POST:
				curl_setopt($ch, CURLOPT_POST, 1 );
				break;
			case self::GET:
				// Get is default for cURL
				break;
		}

        $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36';
        $headers[] = 'Accept: application/*+json';
        $headers[] = 'Content-Type: application/json;charset=UTF-8';
        $headers[] = 'Accept-Encoding: gzip, deflate, br, zstd';
        $headers[] = 'Accept-Language: en-US,en;q=0.9,nb;q=0.8,en-GB;q=0.7,no;q=0.6';
        $headers[] = 'Authorization: Bearer ' . $this->accessToken;
    
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if(!$this->verifyTLS) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        if($Body!=NULL) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($Body));
        }
		
		curl_setopt($ch, CURLOPT_URL, $Url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
		
		$result = curl_exec($ch);

        $response['httpcode'] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        
        if($result===false) {
            $response['error'] = true;
            $response['errortext'] = curl_error($ch);
                    
            return $response;
        } else {
            $response['error'] = false;
                    
            $json = json_decode($result, true);
            
            if($json!==null) {
                $response['result'] = $json;
            } else {
                $response['result'] = $result;
            }
                        
            return  $response;
	    }
    }

}

class Easee {
    private $username;
    private $password;
    private $apiKey;
    private $accessToken;
    private $refresToken; 
    private $expires;
    private $ExpiresIn;
    private $disableSSL;
    private $userProfile;

    const ENDPOINT = 'https://api.easee.cloud';
    
    public function __construct(String $Username='', string $Password='', string $ApiKey = '', string $AccessToken='', string $RefreshToken='', DateTime $Expires = null) {
        $this->username = $Username;
        $this->password = $Password;
        $this->apiKey = $ApiKey;
        $this->accessToken =$AccessToken;
        $this->refreshToken = $RefreshToken;
        if($Expires==null)
            $this->expires = new DateTime('now');
        else
            $this->expires = $Expires;
        $this->userProfile = null;
        $this->disableSSL = false;
    }

    public function EnableSSLCheck() {
        $this->disableSSL = false;
    }

    public function DisableSSLCheck() {
        $this->disableSSL = true;
    }

    public function GetUserId() {
        if(isset($this->userProfile))
            return $this->userProfile->userId;
        else
            return null;
    }

    public function GetToken(){
        $token = array('AccessToken' => $this->accessToken);
        $token['RefreshToken'] = $this->refreshToken;
        $token['Expires'] = $this->expires;
        $token['ExpiresIn'] = $this->expiresIn;

        return (object)$token;
    }

    public function RefreshToken() {
        if(strlen($this->refreshToken)>0) {
            $url = self::ENDPOINT . '/api/accounts/refresh_token';
            $body = array('accessToken' => $this->accessToken); 
            $body['refreshToken'] = $this->refreshToken;
        } else {
            throw new Exception('Error: Missing refresh token');
        }

        try {
            $now = new DateTime('now');

            $result = self::request('post', $url, $body);
   
            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->result->title));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode));
            } else {
                $this->accessToken = $result->result->accessToken;
                $this->refreshToken = $result->result->refreshToken; 
                $this->expires = $now; //new DateTime('now');
                $this->expires->add(new DateInterval('PT'.(string)$result->result->expiresIn.'S')); // adds expiresIn to "now"
                $this->expiresIn = $result->result->expiresIn;
            }    
        } catch(Exception $e) {
            // report error
            throw new Exception($e->getMessage());
        }
    }

    public function Connect() {
        if (strlen($this->accessToken) == 0) {
            if(strlen($this->username)>0 && strlen($this->password)>0) {
                $url = self::ENDPOINT . '/api/accounts/login';
                $body = array('userName' => $this->username); 
                $body['password'] = $this->password;
            } else {
                throw new Exception('Error: Missing username and/or password');
            }
        } else {
            if($this->expires < new DateTime('now')) {
                if(strlen($this->username)>0 && strlen($this->password)>0) {
                    $url = self::ENDPOINT . '/api/accounts/token';
                    $body = array('userName' => $this->username); 
                    $body['password'] = $this->password;
                } else {
                    throw new Exception('Error: Expirered access token and missing username and/or password');
                }
            } else {
                // Use existing token
                return;
            }
        }

        try {
            $now = new DateTime('now');

            $result = self::request('post', $url, $body);
            
            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->result->title));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode));
            } else {
                $this->accessToken = $result->result->accessToken;
                $this->refreshToken = $result->result->refreshToken; 
                $this->expires = $now; 
                $this->expires->add(new DateInterval('PT'.(string)$result->result->expiresIn.'S')); // adds expiresIn to "now"
                $this->expiresIn = $result->result->expiresIn;
                
                //IPS_LogMessage('Connect','AccessToken: '.$this->accessToken);
                //IPS_LogMessage('Connect','RefreshToken: '.$this->refreshToken);
            }    
        } catch(Exception $e) {
			// report error
            throw new Exception($e->getMessage());
		}
        
    }

    private function GetUserProfile() {
        
        $url = self::ENDPOINT . '/api/accounts/profile';

        try{
            $this->Connect();
            //$return = self::request('get', $url);
            //IPS_LogMessage('GetUserProfile()','Return: '.json_encode($return));
            
            $result = self::EvaluateResult(self::request('get', $url), $url);
            
            //IPS_LogMessage('GetUserProfile()','Evaluated: '.json_encode($result));

            $this->userProfile = $result;
            
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function GetProducts() {
        
        try{
            if(!isset($this->userProfile))
                $this->GetUserProfile();

            $this->Connect();
            
            $url = self::ENDPOINT . '/api/accounts/products?userId=' . (string)$this->userProfile->userId;
            $result = self::EvaluateResult(self::request('get', $url), $url);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function GetCommandState(string $ChargerId, int $CommandId, int $Ticks) {
        try {
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/commands/' . $ChargerId .'/' . (string)$CommandId . '/' . (string)$Ticks;
            $result = self::EvaluateResult(self::request('get', $url), $url);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    public function GetChargerState(string $ChargerId) {
        
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/state';
            $result = self::EvaluateResult(self::request('get', $url), $url);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    public function SetChargerAccessLevel(string $ChargerId, bool $UseKey) {
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/access';
            $data = $UseKey?2:1;
            //$result = self::request('put', $url, $data);
            $result = self::EvaluateResult(self::request('put', $url, $data), $url);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function SetChargerLockState(string $ChargerId, bool $State) {
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/commands/lock_state';
            $data = ['State' => $State];
            $result = self::EvaluateResult(self::request('post', $url, $data), $url);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function SetChargerConfig(string $ChargerId, array $Config) {
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/settings';
            $result = self::EvaluateResult(self::request('post', $url, $Config), $url);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }


    public function SetChargingState(string $ChargerId, bool $State) {
        try{
            $this->Connect();
            
            if($State) {
                $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/commands/start_charging';
            } else {
                $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/commands/stop_charging';
            }

            $result = self::EvaluateResult(self::request('post', $url), $url);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function GetChargerConfig(string $ChargerId) {
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/config';
            $result = self::EvaluateResult(self::request('get', $url), $url);

            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    public function GetEqualizerState(string $EqualizerId) {
        
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/equalizers/' .  $EqualizerId . '/state';
            $result = self::EvaluateResult(self::request('get', $url), $url);

            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    private function EvaluateResult($Result, string $Url) {
        //IPS_LogMessage('Result from request '.$Url, json_encode($Result));

        if($Result->httpcode==429) {
            throw new Exception(sprintf('Easee Cloud API call to "%s" is rate limited', $Url), 429);
        }

        if($Result->error) {
            throw new Exception(sprintf('%s failed. Error: %s', $Url, $Result->errortext));
        } 
        
        if(isset($Result->result->status) && $Result->result->status != 200) {
            throw new Exception(sprintf('%s failed. Error: "%s"', $Url, isset($Result->result->title)?$Result->result->title:(string)$Result->result->status));
        } 
        
        if($Result->httpcode!=200 && $Result->httpcode!=202) {
            throw new Exception(sprintf('%s returned http status code %d', $Url, $Result->httpcode)); 
        } 
        
        return $Result->result;
    }

    private function request($Type, $Url, $Data=NULL) {
		$ch = curl_init();
		
		switch(strtolower($Type)) {
			case "put":
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
				break;
			case "post":
				curl_setopt($ch, CURLOPT_POST, 1 );
				break;
			case "get":
				// Get is default for cURL
				break;
		}

        $headers = array(
            'User-Agent: Symcon',
            'Content-Type: application/json;charset=UTF-8'
            
            );

        if(strlen($this->accessToken)>0 && $this->expires > new DateTime('now')) {
            $headers[] = 'Accept: application/json';
            $headers[] = 'Authorization: Bearer '. $this->accessToken;
        } else {
            $headers[] = 'Accept: application/*+json';
            $headers[] = 'Authorization: Bearer '. $this->apiKey;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if($this->disableSSL) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
		
		curl_setopt($ch, CURLOPT_URL, $Url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
		
		if($Data!=NULL)
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($Data)); 

        //IPS_LogMessage('Data for curl ', json_encode($Data));
		
		$result = curl_exec($ch);

        if($result===false) {
            $response = array('error' => true);
            $response['errortext'] = curl_error($ch);
            $response['httpcode'] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            
            return (object) $response;
        } else
            $response = array('error' => false);
            $response['httpcode'] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $response['result'] = (object) null ;
            
            $return = (object) $response;
            $return->result = json_decode($result); 
            
            //var_dump($return);

            return  $return;
	}
}

