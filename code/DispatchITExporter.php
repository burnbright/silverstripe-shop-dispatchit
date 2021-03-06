<?php

/**
 * Export addresses for printing
 */
class DispatchITExporter extends Controller implements PermissionProvider{

	public static $url_segment = 'dispatchitexporter';

	public function init(){
		parent::init();
		BasicAuth::requireLogin("Address exporting.", "Show_Address_Export", false);
	}
	
	public function Link($action = null, $id = null){
		return Controller::join_links(self::$url_segment, $action, $id);
	}

	public function index(){
		return $this->export();
	}

	public function export(){
		$dryRun = (boolean)$this->request->getVar("dryrun");
		$noFile = (boolean)$this->request->getVar("nofile");
		$orders = $this->unShippedOrders();	
		$output = "";
		if($orders->exists()){
			$body = $this->createDispatchBody($orders);
			try {
				if (!$dryRun && !$noFile) {
					$this->writeExportFile($body);
				}
				if (!$dryRun) {
					foreach($orders as $order){
						$order->SentToDispatchIT = true;
						$order->write();
					}
				}
				$output = $body;
			} catch (Exception $e) {
				SS_Log::log($e->getMessage(), SS_Log::ERR);
				$this->httpError(500, "Failed to save file");
				return;
			}
		}else{
			$this->httpError(204, "No new orders found");
			return;
		}
		header('Content-type: text/plain');
		// TODO: make sure responses aren't cached
		echo $output;
		die();
	}

	protected function unShippedOrders() {
		return Order::get()
			->innerJoin("Address", "\"Order\".\"ShippingAddressID\" = \"Address\".\"ID\"")
			->filter("SentToDispatchIT", 0)
			->filter("Status", Order::config()->placed_status)
			->filter("Address.Country", "NZ")
			->sort(array(
				"Placed" => "ASC",
				"Created" => "ASC"
			));
	}

	protected function createDispatchITLine($order) {
		$address = $order->getShippingAddress();
		$name = $address->Company; //TODO: company
		if(!$name || $name == ""){
			$name = $order->Name;
		}
		return array(
			$order->ID,			//ConsignmentNumber	char(12)	Yes	Tracking number.  Must be unique.
			$order->MemberID, 	//CustomerID			char(20)	Blank if ommitted
			$name,				//CompanyName			char(40)	Yes
			$address->Address,	//Address1			char(40)	Yes
			$address->AddressLine2,	//Address2		char(40)	Yes
			$address->Suburb,	//Address3				char(40)	Blank if ommitted
			$address->State,	//Address4				char(40)	Blank if ommitted
			$address->City,		//Address5			char(40)	Must be valid suburb/city/town.
			"",					//CustOrderRef			char(30)	Blank if ommitted
			"0",				//Carrier				smallint	Yes	0 = NZ Couriers
			"1",				//CostCentre			smallint	Yes	1 = Primary cost centre.
			"",					//PhoneSTD				char(4)	Blank if ommitted
			$address->Phone,	//PhoneNumber			char(20)	Blank if ommitted
			"",					//FaxSTD					char(4)	Blank if ommitted
			$order->Fax,		//FaxNumber				char(20)	Blank if ommitted
			$order->Email,		//EmailAddress			char(255)Blank if ommitted
			"1",				//EmailAddressSend	bit		TRUE or FALSE
			"",					//EmailAddress2		char(255)Blank if ommitted
			"0",				//EmailAddress2Send	bit		TRUE or FALSE
			"",					//EmailAddress3		char(255)Blank if ommitted
			"0"					//EmailAddress3Send	bit		TRUE or FALSE;
		);
	}

	protected function createDispatchBody($orders) {
		$output = "";
		foreach($orders as $order){
			$line = $this->createDispatchITLine($order);
			$output .= implode("\t", $line)."\n";
		}
		return $output;
	}

	/**
	 * Store output in a local file, then output the contents, or a link to the file
	 * name format: DDcxxxxxxx.TXT
	 */
	protected function writeExportFile($body) {
		$filename = "DDc".uniqid()."_".date('YmdHis').".txt";
		$exportpath = ASSETS_PATH."/_dispatchitexports";
		if(!is_dir($exportpath) && mkdir($exportpath) === false) {
			throw new Exception("Couldn't make $exportpath directory");
		}
		$filePath = $exportpath."/$filename";
		if(file_put_contents($filePath, $body) === false){
			throw new Exception("Could not write file to $filePath");
		}
		return $filePath;
	}

	public function providePermissions() {
		return array(
			"Show_Address_Export" => array(
				'name' => 'Access to address export API',
				'category' => 'Shop'
			)
		);
	}

}