<?php

declare(strict_types=1);

include 'easee.php';

class EaseeHomeGateway extends IPSModule
{
	public function Create()
	{
		//Never delete this line!
		parent::Create();
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

	private function HandleAsyncRequest(string $Request) {
		$request = json_decode($Request);
		if(!isset($request->Function)||!isset($request->ChildId)) {
			throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Function" and/or "ChildId" is missing. The request was "%s"', $Request));
		}

		$function = strtolower($request->Function);
		$childId =  strtolower($request->ChildId);

		$username = $this->ReadPropertyString('Username') ;
		$password = $this->ReadPropertyString('Password') ;
		
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

	private function GetEqualizerState(string $EqualizerId, string $Username, string $Password) {
		$easee = new Easee($Username, $Password);
		$easee->DisableSSL();
		
		$products = $easee->GetEqualizerState($EqualizerId);
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetEqualizerState()', json_encode($products)), 0);
	}

	private function GetProducts(string $ChildId, string $Username, string $Password) {
		$easee = new Easee($Username, $Password);
		$easee->DisableSSL();
		
		$products = $easee->GetProducts();
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetProducts()', json_encode($products)), 0);
	}

	private function GetCharger(string $ChildId, $ChargerId, string $Username, string $Password) {
		$easee = new Easee($Username, $Password);
		$easee->DisableSSL();
		
		$products = $easee->GetCharger($ChargerId);
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Easee REST API returned "%s" for GetCharger()', json_encode($products)), 0);

	}
}