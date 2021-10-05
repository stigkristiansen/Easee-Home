<?php

declare(strict_types=1);


	class EaseeHomeDiscovery extends IPSModule
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

			$this->SetReceiveDataFilter('.*"ChildId":"' . (string)$this->InstanceID .'".*');
		}

		public function GetConfigurationForm() {
			$products = $this->DiscoverEaseeProducts();
			$instances = $this->GetEaseeInstances();
	
			$values = [];

			$this->SendDebug(IPS_GetName($this->InstanceID), 'Building Discovery form...', 0);
	
			// Add devices that are discovered
			if(count($products)>0)
				$this->SendDebug(IPS_GetName($this->InstanceID), 'Adding discovered products...', 0);
			else
				$this->SendDebug(IPS_GetName($this->InstanceID), 'No products discovered!', 0);

			foreach ($products as $productId => $product) {
				$value = [
					'ProductId'	=> $productId,
					'Type' => $product['Type'],
					'Name' => $product['Name'],
					'Site' => $product['Site'],
					'instanceID' => 0
				];

				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Added product with id "%s"', $productId), 0);
				
				// Check if discovered device has an instance that is created earlier. If found, set InstanceID
				$instanceId = array_search($productId, $instances);
				if ($instanceId !== false) {
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('The product (%s) already has an instance (%s). Adding InstanceId...', $productId, $instanceId), 0);
					unset($instances[$instanceId]); // Remove from list to avoid duplicates
					$value['instanceID'] = $instanceId;
				} 
				
				$value['create'] = [
					'moduleID'       => $product['Type']=='Charger'?'{B469F6F0-1DC2-04A4-F0BE-EB02323E319D}':'{E2C80DF2-CE2D-DC47-ABD8-5D969C54129A}',  
					'name'			 => $product['Name'],
					'configuration'	 => [
						'ProductId' 	 => $productId,
						'UpdateInterval' => 15,
						'Site' 			 => $product['Site']
					]
				];
			
				$values[] = $value;
			}

			// Add devices that are not discovered, but created earlier
			if(count($instances)>0) {
				$this->SendDebug(IPS_GetName($this->InstanceID), 'Adding instances that are not discovered...', 0);
			}
			foreach ($instances as $instanceId => $productId) {
				$values[] = [
					'ProductId'  => $productId, 
					'Type' 		 => IPS_GetInstance($instanceId)['ModuleInfo']['ModuleID']=='{B469F6F0-1DC2-04A4-F0BE-EB02323E319D}'?'Charger':'Equalizer',
					'Name' 		 => IPS_GetName($instanceId),
					'Site' 		 => json_decode(IPS_GetConfiguration($instanceId),true)['Site'],
					'instanceID' => $instanceId
				];

				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Adding instance "%s" with InstanceID "%s"', IPS_GetName($instanceId), $instanceId), 0);
			}

			$form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
			$form['actions'][0]['values'] = $values;

			$this->SendDebug(IPS_GetName($this->InstanceID), 'Building form completed', 0);

	
			return json_encode($form);
		}

		public function ReceiveData($JSONString) {
			try {
				$data = json_decode($JSONString);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from parent: %s', json_encode($data->Buffer)), 0);
			 
				$msg = '';
				if(!isset($data->Buffer->Function) ) {
					$msg = 'Missing "Function"';
				} 
				if(!isset($data->Buffer->Success) ) {
					if(strlen($msg)>0) {
						$msg += ', missing "Buffer"';
					} else {
						$msg = 'Missing "Buffer"';
					}
				} 
				if(!isset($data->Buffer->Result) ) {
					if(strlen($msg)>0) {
						$msg += ', missing "Result"';
					} else {
						$msg = 'Missing "Result"';
					}
				} 

				if(strlen($msg)>0) {
					throw new Exception('Invalid data receieved from parent. ' . $msg);
				}
				
				$success = $data->Buffer->Success;
				$result = $data->Buffer->Result;

				$products = [];
				if($success) {
					$function = strtolower($data->Buffer->Function);
					switch($function) {
						case 'getproducts':  
							foreach($result as $site) {
								if(!isset($site->circuits) || !isset($site->equalizers) || !isset($site->name)) {
									throw new Exception('Invalid data received from parent. Missing "Circuits", "Equalizers" and/or "Site Name"');
								}
								foreach($site->circuits as $circuit) {
									if(!isset($circuit->chargers)) {
										throw new Exception('Invalid data received from parent. Missing "Chargers"');
									}
									foreach($circuit->chargers as $charger) {
										if(!isset($charger->id) || !isset($charger->name)) {
											throw new Exception('Invalid data received from parent. Missing chargers "Name" and/or "Id"');
										}
										$products[$charger->id] = [
											'Name' => $charger->name,
											'Type' => 'Charger',
											'Site' => $site->name
										];
									}
								}
								foreach($site->equalizers as $equalizer) {
									if(!isset($equalizer->id) || !isset($equalizer->name)) {
										throw new Exception('Invalid data received from parent. Missing equalizers "Name" and/or "Id"');
									}
									$products[$equalizer->id] = [
										'Name' => $equalizer->name,
										'Type' => 'Equalizer',
										'Site' => $site->name
									];
								}
							}

							$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Got the following products from %s(): %s', $data->Buffer->Function, json_encode($products)), 0);	
							
							$this->AddProductsToBuffer($products);
							//$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Added products "%s" to the buffer', json_encode($products)), 0);
							
							break;
						default:
							throw new Exception(sprintf('Unknown function "%s()" receeived in repsponse from gateway', $function));
					}
					
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Processed the result from %s(): %s...', $data->Buffer->Function, json_encode($result)), 0);
				} else {
					throw new Exception(sprintf('The gateway returned an error: %s',$result));
				}
				
			} catch(Exception $e) {
				$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), 0);
			}
		}

		private function DiscoverEaseeProducts() : array {
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Discovering Easee products...', 0);
			$request = $this->Discover();

			if($request!=null) {
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Sending a request to the gateway: %s', json_encode($request)), 0);
				$this->SendDataToParent(json_encode(['DataID' => '{B62C0F65-7B59-0CD8-8C92-5DA32FBBD317}', 'Buffer' => $request]));
			}

			return $this->GetProductsFromBuffer();
		}

		private function GetEaseeInstances () : array {
			$instances = [];

			$this->SendDebug(IPS_GetName($this->InstanceID), 'Searching for existing instances of Easee modules...', 0);

			$instanceIds = array_merge(IPS_GetInstanceListByModuleID('{B469F6F0-1DC2-04A4-F0BE-EB02323E319D}'), IPS_GetInstanceListByModuleID('{E2C80DF2-CE2D-DC47-ABD8-5D969C54129A}'));
        	
        	foreach ($instanceIds as $instanceId) {
				$instances[$instanceId] = IPS_GetProperty($instanceId, 'ProductId');
			}

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Found %d instances of Easee modules', count($instances)), 0);
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Finished searching for Easee modules', 0);	

			return $instances;
		}

		private function Discover() : array {
			$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'GetProducts'];
				
			return $request;
		}

		private function GetProductsFromBuffer() : array{
			if($this->Lock('Products')) {
				$jsonProducts = $this->GetBuffer('Products');
				
				if(strlen($jsonProducts)==0) {
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Missing products in the buffer', $jsonProducts), 0);
					$this->Unlock('Products');
					return [];
				}
	
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Got the products "%s" from the buffer', $jsonProducts), 0);
				$this->Unlock('Products');
				
				return json_decode($jsonProducts, true);
			} 
	
			return [];
		}
	
		private function AddProductsToBuffer($Products) {
			if($this->Lock('Products')) {
				if($Products==null)
					$products = '';
				else
					$products = json_encode($Products);
				$this->SetBuffer('Products', $products);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Added products "%s" to the buffer', $products), 0);
				$this->Unlock('Products');
			}
		}

		private function Lock(string $Id) : bool {
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
						$this->SendDebug(IPS_GetName($this->InstanceID), 'Waiting for the Lock with "EaseeHomeDiscovery" to be released', 0);
					}
					IPS_Sleep(1);
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