<?php
	
	namespace App\Http\Controllers;
	
	use App\Http\Requests\UssdRequest;
	use Carbon\Carbon;
	use Exception;
	use GuzzleHttp\Client;
	use Illuminate\Support\Facades\Cache;
	use libphonenumber\PhoneNumberFormat;
	use libphonenumber\PhoneNumberUtil;
	
	class JitengeRegistrationAndEvaluationUssdController extends Controller
	{
		const END_POINT = "https://c4c_api.mhealthkenya.org/api/exposures/covid/new/";
		
		//const END_POINT = "http://127.0.0.1:3000/api/";
		
		protected $client;
		
		public function __construct()
		{
			$this->client = new Client();
		}
		
		private function getSession($sessionId)
		{
			return Cache::get($sessionId);
		}
		
		private function setSession(array $session)
		{
			$sessionDurationMinutes = 15;
			
			Cache::put($session["sessionId"], $session, $sessionDurationMinutes);
		}
		
		private function deleteSession($session)
		{
			if (empty($session))
				return $session;
			
			if (!isset($session['sessionId']))
				return null;
			
			return Cache::pull($session["sessionId"]);
		}
		
		private function getDaysCounter($phoneNumber)
		{
			return Cache::get($phoneNumber);
		}
		
		private function startDaysCounter($phoneNumber)
		{
			$sessionDurationMinutes = 21600;//15 days
			
			Cache::put("" . $phoneNumber, Carbon::now(), $sessionDurationMinutes);
		}
		
		public function startRegistrationAndEvaluation(UssdRequest $request)
		{
			$sessionId = request("sessionId");
			$phoneNumber = request("phoneNumber");
			$input = trim(request("text"));
			$session = $this->getSession($sessionId);
			
			if ($input == "") {
				
				$response = "CON EARS\nEnter your country code e.g 254";
				
			} else {
				
				$parts = array_filter(explode('*', $input));
				
				$arraySize = count($parts);
				
				if (empty($session) && $arraySize > 1) {
					
					$response = "END You have entered an invalid country code";
					
				} else if (isset($session['token'])) {
					
					$response = $this->proceedWithEvaluation($session, $parts);
					
				} else {
					
					switch (count($parts)) {
						
						case 1:
							
							$countryCode = ltrim($parts[0], '+');
							
							$client = new Client();
							
							$response = $client->get(self::END_POINT . "countries");
							
							$countries = json_decode($response->getBody());
							
							if (empty($countries)) {
								
								$response = "END An error occurred. Please try again later";
								
								$this->deleteSession($session);
								
							} else {
								
								$countries = collect($countries);
								
								$country = $countries->first(function ($country, $key) use ($countryCode) {
									
									return $country->phone_code == (int)$countryCode;
									
								});
								
								if (empty($country)) {
									
									$response = "END You have entered an invalid country code";
									
									$this->deleteSession($session);
									
								} else {
									
									$session["sessionId"] = $sessionId;
									
									$session["country"] = $country;
									
									$this->setSession($session);
									
									$response = "CON EARS\nEnter your phone number";
									
								}
								
								
							}
							
							break;
						
						case 2:
							/*$country = (array)$session['country'];
							$isValidNumber = false;
							$phoneNumberObject = null;
							$phoneNumberUtil = PhoneNumberUtil::getInstance();
							if ($phoneNumberUtil->isPossibleNumber($parts[1], strtoupper($country['iso']))) {
								$phoneNumberObject = $phoneNumberUtil->parse($parts[1], strtoupper($country['iso']));
								$isValidNumber = $phoneNumberUtil->isValidNumberForRegion($phoneNumberObject, strtoupper($country['iso']));
							}
							
							if (!$isValidNumber || $phoneNumberObject == null) {
								
								$response = "END EARS\nYou have entered an invalid phone number for your country. Try again.";
								
								$this->deleteSession($session);
								
							} else {
								
								$session["phone_number"] = trimSpace($phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::INTERNATIONAL));
								
								$this->setSession($session);
								
								$response = "CON EARS\nEnter your first name";
							}
							*/
							
							$country = (array)$session['country'];
							
							$session["phone_number"] = $country['phone_code'] . ltrim($parts[1], '0');
							
							$this->setSession($session);
							
							$client = new Client();
							
							$response = $client->post(self::END_POINT . 'login', [
											'form_params' => [
													'phone_no' => $session["phone_number"],
													'passport_no' => $session["phone_number"],
											],
											'cookies' => false
									]
							);
							
							$response = json_decode($response->getBody());
							
							if (!empty($response) && $response->success === true) {
								
								$session['token'] = $response->message;
								
								$session['client_id'] = $response->client_id;
								
								$this->setSession($session);
								
								$response = $this->proceedWithEvaluation($session, $parts);
								
							} else {
								
								$response = "CON EARS\nEnter your first name";
								
							}
							
							break;
						
						case 3:
							
							$session["first_name"] = $parts[2];
							
							$this->setSession($session);
							
							$response = "CON EARS\nEnter your last name";
							
							break;
						
						case 4:
							
							$session["last_name"] = $parts[3];
							
							$this->setSession($session);
							
							$response = "CON EARS\nEnter your ID\Passport number";
							
							break;
						
						case 5:
							
							$session["passport_number"] = $parts[4];
							
							$this->setSession($session);
							
							$response = "CON EARS\nEnter your email address";
							
							break;
						
						case 6:
							
							$session["email_address"] = $parts[5];
							
							$this->setSession($session);
							
							$response = "CON EARS\nSelect your gender\n1. Female\n2. Male";
							
							break;
						
						case 7:
							
							$session["sex"] = $parts[6] == "1" ? "FEMALE" : "MALE";
							
							$this->setSession($session);
							
							$response = "CON EARS\nEnter your date of birth DDMMYYYY";
							
							break;
						case 8:
							
							if (strlen($parts[7]) != 8) {
								
								unset($session[7]);
								
								$response = "CON EARS\nEnter your date of birth DDMMYYYY";
								
							} else {
								
								try {
									
									$session['dob'] = Carbon::createFromFormat('dmY', $parts[7])->format('Y-m-d');
									
									$this->setSession($session);
									
									$response = "CON EARS\nEnter your place of diagnosis";
									
								} catch (Exception $exception) {
									
									unset($session[7]);
									
									$response = "CON EARS\nEnter your date of birth DDMMYYY";
									
								}
							}
							
							break;
						case 9:
							
							$session["place_of_diagnosis"] = $parts[8];
							
							$this->setSession($session);
							
							$response = "CON EARS\nEnter your date of contact DDMMYYYY";
							
							break;
						case 10:
							
							if (strlen($parts[9]) != 8) {
								
								unset($session[9]);
								
								$response = "CON EARS\nEnter your date of contact DDMMYYYY";
								
							} else {
								
								try {
									
									$session['date_of_contact'] = Carbon::createFromFormat('dmY', $parts[9])->format('Y-m-d');
									
									$session['county_id'] = $session['subcounty_id'] = $session['ward_id'] = 2620;
									
									$session['origin_country'] = $session['country']->name;
									
									$this->setSession($session);
									
									unset($session['sessionId']);
									
									unset($session['country']);
									
									$client = new Client();
									
									$response = $client->post(self::END_POINT . 'register', [
													'form_params' => $session,
													'cookies' => false
											]
									);
									
									$response = json_decode($response->getBody());
									
									if (!empty($response) && $response->success === true) {
										
										$response = "END EARS\nRegistration Successful. Start the process again to self evaluate";
										
									} else {
										
										$response = "END EARS\n" . $response->message . " Start the process again to self evaluate";
										
									}
									
									$this->deleteSession($session);
									
								} catch (Exception $exception) {
									
									unset($session[9]);
									
									$response = "CON EARS\nEnter your date of contact DDMMYYYY";
									
								}
							}
							
							break;
						
						default:
							
							$response = "END An error occurred. Please try again later";
							
							$this->deleteSession($session);
							
							break;
						
					}
					
				}
				
			}
			
			return response($response, 200)->header("Content-Type", "text/plain");
		}
		
		private function proceedWithEvaluation($session, $parts)
		{
			
			switch (count($parts)) {
				
				case 2:
					
					$response = "CON EARS\nDo you have a thermal gun\n1. Yes\n2. No";
					
					break;
				
				case 3:
					
					$session["thermal_gun"] = $parts[2] == "1" ? "YES" : "NO";
					
					$this->setSession($session);
					
					$response = "CON EARS\nWhat's your body temperature (Degrees Celsius)?";
					
					break;
				
				case 4:
					
					$session["body_temp"] = $parts[3];
					
					$this->setSession($session);
					
					$response = "CON EARS\nHave you developed fever?\n1. Yes\n2. No";
					
					break;
				
				case 5:
					
					$session["fever"] = $parts[4] == "1" ? "YES" : "NO";
					
					$this->setSession($session);
					
					$response = "CON EARS\nHave you developed a cough?\n1. Yes\n2. No";
					
					break;
				
				case 6:
					
					$session["cough"] = $parts[5] == "1" ? "YES" : "NO";
					
					$this->setSession($session);
					
					$response = "CON EARS\nDo you have difficulty in breathing?\n1. Yes\n2. No";
					
					break;
				
				case 7:
					
					$session["difficult_breathing"] = $parts[6] == "1" ? "YES" : "NO";
					
					$this->setSession($session);
					
					$response = "CON EARS\nPlease provide any additional comments";
					
					break;
				case 8:
					
					$this->deleteSession($session);
					
					$session["comment"] = $parts[7];
					
					$time = $this->getDaysCounter($session['phone_number']);
					
					if (empty($time)) {
						
						$time = Carbon::now();
						
						$this->startDaysCounter($session['phone_number']);
						
					}
					
					$session["day"] = $time->diffInDays(Carbon::now()) + 1;
					
					unset($session['sessionId']);
					
					unset($session['country']);
					
					unset($session['phone_number']);
					
					$client = new Client();
					
					$response = $client->post(self::END_POINT . 'response', [
									'form_params' => $session,
									'headers' => ['Authorization' => 'Bearer ' . $session['token']],
									'cookies' => false
							]
					);
					
					$response = json_decode($response->getBody());
					
					$response = "END EARS\n" . $response->message;
					
					break;
				
				
			}
			
			return $response;
			
		}
		
	}
