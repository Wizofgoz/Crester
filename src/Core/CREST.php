<?php
namespace Crester\Core;
use \Crester\Core\CRESTBase as CRESTBase;
class CREST extends CRESTBase
{
	/*
	*	Code retrieved from SSO
	*/
	private $Authorization_Code;
	/*
	*	Token used to authenticate CREST calls
	*/
	private $Access_Token;
	/*
	*	Whether the authorization code has been verified
	*/
	private $Verified_Code = false;
	/*
	*	Route for API calls to follow
	*/
	private $APIRoute;
	/*
	*	Route used so far in call stack
	*/
	private $UsedRoute;
	/*
	*	Rate Limiter class makes sure API calls don't exceed limits
	*/
	private $RateLimiter;
	/*
	*
	*/
	protected $Cache;
	/*
	*	Token used to get a fresh access token
	*/
	private $RefreshToken;
	/*
	*	When the current access token needs to be refreshed
	*/
	private $RefreshTime;
	/*
	*	ClientID for Eve Server Authentication
	*/
	private $client_id;
	/*
	*	SecretKey for Eve Server Authentication
	*/
	private $secret_key;
	
	public function __construct($client_id, $secret_key, $auth_code, RateLimiter $limiter, CacheInterface $Cache)
	{
		$this->client_id = $client_id;
		$this->secret_key = $secret_key;
		$this->RateLimiter = $limiter;
		$this->Cache = $Cache;
		$this->setAuthCode($auth_code);
	}
	
	public function setAuthCode($Code)
	{
		$this->Authorization_Code = $Code;
		try{
			$this->verifyCode();
		}catch(CRESTAPIException $e){
			echo $e;
			exit;
		}
	}
	/*
	*	Returns whether the handler has a valid session running
	*/
	public function getStatus(){
		return $this->Verified_Code;
	}
	/*
	*	Returns current Bearer Token
	*/
	public function getToken(){
		return $this->Access_Token;
	}
	
	public function getExpiration()
	{
		return $this->RefreshTime;
	}
    /*
	*	Verifies Authorization code and sets Access Token
	*/
	public function verifyCode(){
		// if api call doesn't return an error, parse it and set values
		if($Result = $this->callAPI(CREST_LOGIN, "POST", AUTHORIZATION_BASIC, "grant_type=authorization_code&code=".$this->Authorization_Code))
		{
			$Result = json_decode($Result, true);
			if(isset($Result['access_token']) && $Result['access_token'] != "")
			{
				$this->Access_Token = $Result['access_token'];
				$this->Verified_Code = true;
				$this->RefreshToken = $Result['refresh_token'];
				//Access Tokens are valid for 20mins and must be refreshed after that
				$this->RefreshTime = time()+(60*20);
				$this->XML->setToken($this->Access_Token);
			}
			else
			{
				throw new CRESTAPIException('Error: invalid API response');
			}
		}
		// else, return false
		else
		{
			throw new CRESTAPIException('Error: cURL returned an error');
		}
	}
	/*
	*	Specialized call to get character info of logged in user
	*/
	public function getCharacterInfo(){
		// if api call doesn't return an error, parse it and set values
		if($Result = $this->callAPI(CREST_VERIFY, "GET", AUTHORIZATION_BEARER, ""))
		{
			return json_decode($Result, true);
		}
		// else, return false
		else
		{
			return false;
		}
	}
	
	/*
	*	Make a custom call to given URL with given Method (GET, POST, PUT, DELETE)
	*/
	public function customCall($URL, $Method){
		// if api call doesn't return an error, parse it and set values
		if($Result = $this->callAPI($URL, $Method, AUTHORIZATION_BEARER, ""))
		{
			return json_decode($Result, true);
		}
		// else, return false
		else
		{
			return false;
		}
	}
	
	/*
	*	Uses Refresh Token to get fresh Access Token
	*/
	private function refresh(){
		// check if access token is valid
		if(time() >= $this->RefreshTime)
		{
			// if call did not throw an error, parse result and set new AccessToken
			if($Result = $this->callAPI(CREST_LOGIN, "POST", AUTHORIZATION_BASIC, "grant_type=refresh_token&refresh_token=".$this->RefreshToken))
			{
				$Result = json_decode($Result, true);
				$this->Access_Token = $Result['access_token'];
				$this->RefreshToken = $Result['refresh_token'];
				//Access Tokens are valid for 20mins and must be refreshed after that
				$this->RefreshTime = time()+(60*20);
				return true;
			}
			else
			{
				return false;
			}
		}
		// if access token is valid, return true
		else
		{
			return true;
		}
	}
	/*
	*	Takes in path (SplQueue) and start recursive calls
	*/
	public function makeCall($Route, $Method, $Data = ""){
		// Check that the route is a valid queue
		if($Route->count() > 0)
		{
			$this->APIRoute = $Route;
			$this->UsedRoute = CREST_AUTH_ROOT;
			try{
				$LeafURL = $this->recursiveCall(CREST_AUTH_ROOT);
			}
			catch(CRESTAPIException $e){
				echo $e;
				exit;
			}
			// check cache
			if($Result = $this->Cache->crestCheck($LeafURL, $this->APIRoute->bottom()->Key.' '.$this->APIRoute->bottom()->Value))
			{
				return $Result;
			}
			// if nothing in cache, call API
			else
			{
				if($Result = $this->callAPI($LeafURL, $Method, AUTHORIZATION_BEARER, $Data))
				{
					$this->Cache->crestUpdate($this->UsedRoute, $this->APIRoute->bottom()->Key.' '.$this->APIRoute->bottom()->Value, $Result);
					return $Result;
				}
				else
				{
					return false;
				}
			}
		}
		else
		{
			return false;
		}
	}
	/*
	*	Runs recursive calls down the route
	*/
	private function recursiveCall($URL){
		// check cache
		if($Result = $this->Cache->crestCheck($this->UsedRoute, $this->APIRoute->bottom()->Key.' '.$this->APIRoute->bottom()->Value))
		{
			try{
				// search result for next part of path
				$NewURL = $this->search(json_decode($Result, true), $this->APIRoute->bottom());
				//var_dump($NewURL);
			}
			catch(CRESTAPIException $e){
				echo $e;
				exit;
			}
		}
		// if nothing in cache, call API
		else
		{
			if($Result = $this->callAPI($URL, "GET", $this->AUTHORIZATION_BEARER, ""))
			{
				$this->Cache->crestUpdate($this->UsedRoute, $this->APIRoute->bottom()->Key.' '.$this->APIRoute->bottom()->Value, $Result);
				try{
					$NewURL = $this->search(json_decode($Result, true), $this->APIRoute->bottom());
					//var_dump($NewURL);
				}
				catch(CRESTAPIException $e){
					echo $e;
					exit;
				}
			}
			else
			{
				return;
			}
		}
		// if search returned no results, search for pagination
		if(empty($NewURL))
		{
			try{
				$NextPage = $this->search(json_decode($Result, true), new \CRESTHandler\RouteNode("next"));
			}
			catch(CRESTAPIException $e){
				echo $e;
				exit;
			}
			// if no pagination found, throw exception
			if(empty($NextPage))
			{
				throw new CRESTAPIException($this->APIRoute->bottom()->Key." and pagination not found in API response", 100);
			}
			// else, get next page
			else
			{
				try{
					return $this->recursiveCall($NextPage[0]['href']);
				}
				catch(CRESTAPIException $e){
					echo $e;
					exit;
				}
				catch(\Exception $e){
					echo $e;
					exit;
				}
			}
		}
		else
		{
			// if the leaf of the route is the only part left, return it's URL
			if($this->APIRoute->count() <= 1)
			{
				return $NewURL[0]['href'];
			}
			// else, keep calling recursively
			else
			{
				// remove last part of route
				$this->UsedRoute .= "|".$this->APIRoute->dequeue()->Key."|";
				try{
					return $this->recursiveCall($NewURL[0]['href']);
				}
				catch(CRESTAPIException $e){
					echo $e;
					exit;
				}
			}
		}
	}
	/*
	*	Makes a call to the CREST API
	*/
	private function callAPI($URL, $Method, $AuthorizationType, $Data){
		//echo "<br>URL: '$URL' Method: '$Method' Auth Type: '$AuthorizationType' Data: '$Data'";
		// set common settings
		$Options = array(
			CURLOPT_URL => $URL,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "base64",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
		);
		// differentiate between authentication methods
		if($AuthorizationType === AUTHORIZATION_BASIC)
		{
			$encode=base64_encode($this->CLIENT_ID.":".$this->SECRET_KEY);
			$Host = explode("/", $URL);
			$Options[CURLOPT_HTTPHEADER] = array(
				"content-type: application/x-www-form-urlencoded",
				"host: ".$Host[2],
				"authorization: Basic ".$encode
			);
		}
		else
		{
			// check that access token is valid and refresh if needed
			if($this->refresh())
			{
				$Host = explode("/", $URL);
				$Options[CURLOPT_HTTPHEADER] = array(
					"content-type: application/x-www-form-urlencoded",
					"host: ".$Host[2],
					"authorization: Bearer ".$this->Access_Token
				);
			}
		}
		// differentiate between HTTP methods
		if($Method == "POST")
		{
			$Options[CURLOPT_POST] = true;
			$Options[CURLOPT_POSTFIELDS] = $Data;
		}
		elseif($Method == "GET")
		{
			$Options[CURLOPT_HTTPGET] = true;
		}
		elseif($Method == "PUT")
		{
			$Options[CURLOPT_PUT] = true;
		}
		elseif($Method == "DELETE")
		{
			$Options[CURLOPT_CUSTOMREQUEST]="DELETE";
		}
		// apply options and send request
		$this->RateLimiter->limit();
		$curl = curl_init();
		curl_setopt_array($curl, $Options);
		$response = curl_exec($curl);
		$err = curl_error($curl);
		curl_close($curl);
		if($err)
		{
			echo "cURL Error #:" . $err;
			return false;
		}
		else
		{
			return $response;
		}
	}
	/*
	*	Determines which search function to use and starts search
	*/
	private function search($array, $routenode){
		switch($routenode->Type)
		{
			// Search for key
			case 0:
				return $this->ksearch($array, $routenode->Key);
			// Search for key-value pair
			case 1:
				return $this->kvsearch($array, $routenode->Key, $routenode->Value);
			// Search for key path
			case 2:
				// search down the path and return last result
				for($i=0;$i<count($routenode->Key);$i++)
				{
					$result = $this->ksearch($array, $routenode->Key[$i]);
				}
				return $result;
			default:
				throw new CRESTAPIException("Unknown RouteNode type: ".$routenode->Type, 101);
		}
	}
	/*
	*	Starts Key-Value recursive search on multi-arrays
	*/
	private function kvsearch($array, $key, $value)
	{
		$results = array();
		$this->kvsearch_r($array, $key, $value, $results);
		return $results;
	}
	/*
	*	Does recursive search on multi-arrays to find key-value pairs
	*/
	private function kvsearch_r($array, $key, $value, &$results)
	{
		if (!is_array($array)) {
			return;
		}
	
		if (isset($array[$key]) && $array[$key] == $value) {
			$results[] = $array;
		}
	
		foreach ($array as $subarray) {
			$this->kvsearch_r($subarray, $key, $value, $results);
		}
	}
	/*
	*	starts recursive search on multi-arrays to find key
	*/
	private function ksearch($array, $key){
		// add new search option for key-value
		$results = array();
		$this->ksearch_r($array, $key, $results);
		return $results;
	}
	/*
	*	Does recursive search on multi-arrays to find key
	*/
	private function ksearch_r($array, $key, &$results){
		if (!is_array($array)) {
			return;
		}
	
		if (isset($array[$key])) {
			$results[] = $array[$key];
		}
	
		foreach ($array as $subarray) {
			$this->ksearch_r($subarray, $key, $results);
		}
	}
}