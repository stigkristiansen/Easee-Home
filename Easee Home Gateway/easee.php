<?php

declare(strict_types=1);

class Easee {
    private $username;
    private $password;
    private $accessToken;
    private $refresToken; 
    private $expires;
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
        $userProfile = null;
        $this->DisableSSL = false;
    }

    public function EnableSSL() {
        $this->disableSSL = false;
    }

    public function DisableSSL() {
        $this->disableSSL = true;
    }

    public function GetUserId() {
        if(isset($this->userProfile))
            return $this->userProfile->userId;
        else
            return null;
    }

    public function GetToken(){
        $token = array('accessToken' => $this->accessToken);
        $token['refreshToken'] = $this->refreshToken;
        $token['expires'] = $this->expires;

        return (object)$token;
    }
    
    private function Connect() {
        if (strlen($this->accessToken) == 0) {
            if(strlen($this->username)>0 && strlen($this->password)>0) {
                //var_dump('Get new token');
                $url = self::ENDPOINT . '/api/accounts/token';
                $body = array('username' => $this->username); 
                $body['password'] = $this->password;
            } else {
                throw new Exception('Error: Missing username and/or password');
            }
        } else {
            if($this->expires < new DateTime('now')) {
                //var_dump('Token expired. Get new token');
                if(strlen($this->username)>0 && strlen($this->password)>0) {
                    $url = self::ENDPOINT . '/api/accounts/token';
                    $body = array('username' => $this->username); 
                    $body['password'] = $this->password;
                } else {
                    throw new Exception('Error: Expirered access token and missing username and/or password');
                }
            } else {
                //var_dump('Refresh token');
                if(strlen($this->refreshToken)>0) {
                    $url = self::ENDPOINT . '/api/accounts/refresh_token';
                    $body = array('accessToken' => $this->accessToken); 
                    $body['refreshToken'] = $this->refreshToken;
                } else {
                    throw new Exception('Error: Missing refresh token');
                }
            }
        }

        try {
            $now = new DateTime('now');

            $result = self::request('post', $url, $body);

            //var_dump($result);
            
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

            //var_dump($result);

            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->result->title));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                $this->userProfile = $result->result;
            }
            
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }

        //var_dump($this->userId);

    }

    public function GetProducts() {
        
        try{
            if(!isset($this->userProfile))
                $this->GetUserProfile();

                //throw new Exception('Error: User Profile must be retrieved before calling GetDevices()');

            $this->Connect();
            
            $url = self::ENDPOINT . '/api/accounts/products?userId=' . (string)$this->userProfile->userId;
            $result = self::request('get', $url);

            //var_dump($result);

            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->result->title));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function GetCharger(string $ChargerId) {
        
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/chargers/' . $ChargerId;
            $result = self::request('get', $url);

            //var_dump($result);

            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->result->title));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function GetEqualizerState(string $EqualizerId) {
        
        try{
            $this->Connect();
            
            $url = self::ENDPOINT . '/api/equalizers/' .  $EqualizerId . '/state';
            $result = self::request('get', $url);

            //var_dump($result);

            if($result->error) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->errortext));
            } else if(isset($result->result->status) && $result->result->status != 200) {
                throw new Exception(sprintf('%s failed. The error was "%s"', $url, $result->result->title));
            } else if($result->httpcode!=200) {
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode)); 
            } else {
                return $result->result;
            }

        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
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

