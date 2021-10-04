<?php

declare(strict_types=1);

class Easee {
    private $username;
    private $password;
    private $accessToken;
    private $refresToken; 
    private $expires;
    private $ExpiresIn;
    private $disableSSL;
    private $userProfile;

    const ENDPOINT = 'https://api.easee.cloud';

    public function __construct(String $Username='', string $Password='', string $AccessToken='', string $RefreshToken='', DateTime $Expires = null) {
        $this->username = $Username;
        $this->password = $Password;
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
                $url = self::ENDPOINT . '/api/accounts/token';
                $body = array('username' => $this->username); 
                $body['password'] = $this->password;
            } else {
                throw new Exception('Error: Missing username and/or password');
            }
        } else {
            if($this->expires < new DateTime('now')) {
                if(strlen($this->username)>0 && strlen($this->password)>0) {
                    $url = self::ENDPOINT . '/api/accounts/token';
                    $body = array('username' => $this->username); 
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

            $result = self::request('get', $url);

            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->result->title));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } else {
                $this->userProfile = $result->result;
            }
            
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
            $result = self::request('get', $url);

            if($result->error) {
                if($result->httpcode==429) {
                    throw new Exception(sprintf('Easee Cloud API call to "%s" is rate limited', $url), 429);
                }
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function GetChargerState(string $ChargerId) {
        
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/state';
            $result = self::EvaluateResult(self::request('get', $url), $url);
            
            /*$result = self::request('get', $url);

            if($result->error) {
                if($result->httpcode==429) {
                    throw new Exception(sprintf('Easee Cloud API call to "%s" is rate limited', $url), 429);
                }
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }
*/
            $return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    public function SetChargerAccessLevel(string $ChargerId, bool $UseKey) {
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/access';
            $data = $UseKey?2:1;
            $result = self::request('put', $url, $data);
            
            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function SetChargerLockState(string $ChargerId, bool $State) {
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/commands/lock_state';
            $data = ['State' => $State];
            $result = self::request('post', $url, $data);
            
            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } else if($result->httpcode!=200 && $result->httpcode!=202) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }

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

            $result = self::request('post', $url);
            
            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } else if($result->httpcode!=200 && $result->httpcode!=202) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function GetChargerConfig(string $ChargerId) {
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId .'/config';
            $result = self::EvaluateResult(self::request('get', $url), $url);

            /*IPS_LogMessage('Result from request '.$url, json_encode($result));

            if($result->httpcode==429) {
                throw new Exception(sprintf('Easee Cloud API call to "%s" is rate limited', $url), 429);
            }

            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } 
            
            if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } 
            
            if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } 
            */
            
            //return $result->result;

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
            
            /*$result = self::request('get', $url);

            if($result->error) {
                if($result->httpcode==429) {
                    throw new Exception(sprintf('Easee Cloud API call to "%s" is rate limited', $url), 429);
                }
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, isset($result->result->title)?$result->result->title:(string)$result->result->status));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }
*/
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    private function EvaluateResult($Result, string $Url) {
        IPS_LogMessage('Result from request '.$url, json_encode($Result));

        if($Result->httpcode==429) {
            throw new Exception(sprintf('Easee Cloud API call to "%s" is rate limited', $Url), 429);
        }

        if($Result->error) {
            throw new Exception(sprintf('%s failed. Error: %s', $Url, $Result->errortext));
        } 
        
        if(isset($Result->result->status) && $Result->result->status != 200) {
            throw new Exception(sprintf('%s failed. Error: "%s"', $url, isset($Result->result->title)?$Result->result->title:(string)$Result->result->status));
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
            'Content-Type: application/json;charset=UTF-8',
            'Accept: application/json'
            );

        if(strlen($this->accessToken)>0 && $this->expires > new DateTime('now')) {
            $headers[] = 'Authorization: Bearer '. $this->accessToken;
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

