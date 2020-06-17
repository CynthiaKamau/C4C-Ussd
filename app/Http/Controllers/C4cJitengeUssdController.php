<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Requests\UssdRequest;
use GuzzleHttp\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
global $apiResponse;
class C4cJitengeUssdController extends Controller
{
    const END_POINT = "http://c4c_api.localhost/api/exposures/covid/new";
    
    private $sessionOpeningTag = "CON C4C";

    private $sessionClosingTag = "END C4C";

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

    protected $client;

    public function __construct()
		{
			$this->client = new Client([
                'verify' => false
            ]);
		}

    public function handleRequest(UssdRequest $request)
		{
			$sessionId = request("sessionId");
			$phoneNumber = request("phoneNumber");
			$input = trim(request("text"));
            $session = $this->getSession($sessionId);
        
			if ($input == "") {
				
				$response = $this->sessionOpeningTag . "\nEnter your phone number";
				
			} else {
				
				$parts = array_filter(explode('*', $input));
				$apiResponse;
                $arraySize = count($parts);	
                					
					switch ($arraySize) {
						
                        case 1:   
                                   							
							$isValidNumber = false;
							$phoneNumberObject = null;
							$phoneNumberUtil = PhoneNumberUtil::getInstance();
							if ($phoneNumberUtil->isPossibleNumber($parts[0], "KE")) {
								$phoneNumberObject = $phoneNumberUtil->parse($parts[0], "KE");
								$isValidNumber = $phoneNumberUtil->isValidNumberForRegion($phoneNumberObject, "KE");
                            }                           
							
							if (!$isValidNumber || $phoneNumberObject == null) {
								
								$response = "$this->sessionClosingTag\nYou have entered an invalid phone number. Try again.";
								
								$this->deleteSession($session);
								
							}else{

                                $session['sessionId'] = $sessionId;
								
								$session["phone_number"] = preg_replace('/\s+/', '', trim($phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::INTERNATIONAL)));
                                
                                $this->setSession($session);

                                $response = "$this->sessionOpeningTag\nEnter your password";
                            }                        
														
                            break;
                            
                        case 2: 

                            $password = $parts[1];                                
							$apiResponse = $this->client->post('http://c4c_api.mhealthkenya.org/api/auth/login', [
									'form_params' => [
                                        'msisdn' => trim(ltrim($session["phone_number"], "+")),
                                        'password' => $password
									],
									'cookies' => false
                                    ]
                                    
                            );                               
								
                            $apiResponse = json_decode($apiResponse->getBody());
                           
                            if (!empty($apiResponse->access_token) && $apiResponse->success === true) {
                                                                                                        
                                $session['token'] = $apiResponse->access_token;
                                    
                                $session['client_id'] = $apiResponse->user->id;

                                    
                                $this->setSession($session);

                                
                                switch (count($parts)) {

                                

                                    case 2:
                                                    
                                        $response = "CON C4C\nHave you had contact with a COVID 19 patient?\n1. Yes\n2. No";
                                    
                                        break;
                                
                                    case 3:
                                    
                                        $session["contact_person"] = $parts[2] == "1" ? "YES" : "NO";
                                    
                                        // $contact_person = $this->$session;
                                    
                                    
                                        $this->setSession($session);
                                    
                                        $response = "CON C4C\nWhen did you get into contact with someone with COVID 19? DDMMYYYY"; 
                                    
                                        break;
                                    
                                    case 4:
                                                    
                                        if (strlen($parts[3]) != 8) {
                                                                    
                                            unset($session[3]);
                                                            
                                            $response = "CON C4C\nWhen did you get into contact with someone with COVID 19 DDMMYYYY";
                                                        
                                        } else {
                                                        
                                            try {
                                                            
                                                $session['date_of_contact'] = Carbon::createFromFormat('dmY', $parts[3])->format('Y-m-d');
                                                                                    
                                                $response = "CON C4C\nDid you receive IPC training?\n1. Yes\n2. No";
                                                            
                                            } catch (Exception $exception) {
                                                            
                                                unset($session[3]);
                                                            
                                                $response = "CON C4C\nWhen did you get into contact with someone with COVID 19? DDMMYYYY";
                                                            
                                            }
                                        }
                                    
                                        break;
                                    
                                    case 5:
                                    
                                        if ($parts[4] == "1" || $parts[4] == "2") {
                                    
                                            $session["ipc_training"] = $parts[4] == "1" ? "YES" : "NO";
                                    
                                            $response = "CON C4C\nWhich of these symptoms are you experiencing any of these symptoms?\n1. Fever\n2. Cough\n3. Difficulty in breathing\n4. Fatigue\n5. Sneezing\n6. Sore throat\n7. None";
                                    
                                                
                                        } else {
                                    
                                            $response = "CON C4C\nYou have entered an invalid response. Try again";
                                    
                                            $this->deleteSession($session);
                                        }
                                    
                                        break;
                                    
                                    case 6:
                                    
                                        if ($parts[5] == "1" || $parts[5] == "2" || $parts[5] == "3" || $parts[5] == "4" || $parts[5] == "5" || $parts[5] == "6" || $parts[5] == "7") {
                                    
                                            $session["symptoms"] = $parts[5];
                                    
                                            $response = "CON C4C\nWhat are the results of your PCR Test?\n1. Positive\n2. Negative\n3. Not applicable";
                                    
                                        } else {
                                    
                                            $response = "CON C4C\nYou have entered an invalid response. Try again";
                                    
                                            $this->deleteSession($session);
                                        }
                                    
                                        break;
                                    
                                    case 7:
                                    
                                        if ($parts[6] == "1" || $parts[6] == "2" || $parts[6] == "3") {
                                    
                                            $session["pcr_test"] = $parts[6];
                                    
                                            $response = "CON C4C\nDuring interaction with a COVID-19 patient, were you wear personal protective equipment (PPE)?\n1. Yes \n2. No";
                                                    
                                        } else {
                                    
                                            $response = "CON C4C\nYou have entered an invalid response. Try again.";
                                    
                                            $this->deleteSession($session);
                                    
                                        }
                                    
                                        break;
                                    
                                    case 8:
                                    
                                        if ($parts[7] == "1" ||$parts[7] == "2" ) {
                                    
                                            $session["ppe_worn"] = $parts[7] == "1" ? "YES" : "NO";
                                    
                                                if ($parts[7] == "1"){
                                    
                                                        $response = "CON C4C\nWhich of these personal protective equipment (PPE) were you wearing?\n1. Single Gloves\n2. N95 mask (or equivalent)\n3. Face shield or goggles/protective glasses\n4. Disposable gown\n5. Waterproof apron\n6. None";
                                                    
                                                } else if($parts[7] == "2") {
                                    
                                                    //$response = "END Thank you for reporting a COVID 19 exposure. Your responses have been recorded";
                                    
                                                    dd($session);       
                                                        
                                                    //$response = json_decode($response->getBody());
                                                            
                                                    $response = "$this->sessionClosingTag\n" . $response->message;
                                                            
                                                    $this->deleteSession($session);
                                    
                                    
                                                }
                                        }
                                    
                                        break;
                                    
                                    default:
                                    
                                        $response = "END Thank you for reporting an exposure. Your responses have been recorded";
                                    
                                        break;                            

                                }

                            } else if($apiResponse->success === false) {

                                $response = "CON C4C\nInvalid credentials, check your phone number or password ";

                                $this->deleteSession($session);

                            } else if($apiResponse->success === true && $apiResponse->user->profile_complete === 0 ){

                                $response = "CON C4C\nDownload the C4C App and complete your profile";

                                $this->deleteSession($session);

                            } else {
                                $response = "an error occured";
                                $this->deleteSession($session);
                            }

                        break;

                        case 3:
                                    
                            $response = "CON C4C\nEnter your ID Number";                         

                            break;
                        
                        case 4:
                                    
                            $session["id_no"] = $parts[3];;                        
                        
                            $this->setSession($session);
                        
                            $response = "CON C4C\nWhen did you get into contact with someone with COVID 19? DD/MM/YYYY"; 
                         

                        break;

                        case 5:

                            if (strlen($parts[4]) != 8) {
								
                                unset($session[4]);
                                
                                $response = "CON C4C\nWhen did you get into contact with someone with COVID 19 DD/MM/YYYY";
                                
                            } else {
                                
                                try {
                                    
                                    $session['date_of_contact'] = Carbon::createFromFormat('d/m/Y', $parts[4])->format('Y-m-d');
                                                            
                                    $response = "CON C4C\nDid you receive IPC training?\n1. Yes\n2. No";
                                    
                                } catch (Exception $exception) {
                                    
                                    unset($session[4]);
                                    
                                    $response = "CON C4C\nWhen did you get into contact with someone with COVID 19? DDMMYYYY";
                                    
                                }
                            }            


                        break;

                        case 6:

                            if ($parts[5] == "1" || $parts[5] == "2") {

                                $session["ipc_training"] = $parts[5] == "1" ? "YES" : "NO";
            
                                $response = "CON C4C\nWhich of these symptoms are you experiencing any of these symptoms?\n1. Fever\n2. Cough\n3. Difficulty in breathing\n4. Fatigue\n5. Sneezing\n6. Sore throat\n7. None";
            
                        
                            } else {
            
                            $response = "CON C4C\nYou have entered an invalid response. Try again";
            
                            $this->deleteSession($session);
                        }            


                        break;

                        case 7:

                            if ($parts[6] == "1" || $parts[6] == "2" || $parts[6] == "3" || $parts[6] == "4" || $parts[6] == "5" || $parts[6] == "6" || $parts[6] == "7") {

                                $session["symptoms"] = $parts[6];

                                $response = "CON C4C\nWhen did you begin self isolation? DD/MM/YYYY";;
                        
                            } else {
            
                            $response = "CON C4C\nYou have entered an invalid response. Try again";
            
                            $this->deleteSession($session);
                        }            


                        break;

                        case 8:

                            if (strlen($parts[7]) != 8) {
								
                                unset($session[7]);
                                
                                $response = "CON C4C\nWhen did you begin self isolation? DD/MM/YYYY";
                                
                            } else {
                                
                                try {
                                    
                                    $session['isolation_start_date'] = Carbon::createFromFormat('d/m/Y', $parts[7])->format('Y-m-d');
                                                            
                                    $response = "CON C4C\nWhat are the results of your PCR Test?\n1. Positive\n2. Negative\n3. Not applicable";
                                    
                                } catch (Exception $exception) {
                                    
                                    unset($session[3]);
                                    
                                    $response = "CON C4C\nCON C4C\nWhen did you begin self isolation? DD/MM/YYYY";
                                    
                                }
                            }            


                        break;
                        

                        case 9:

                            if ($parts[8] == "1" || $parts[8] == "2" || $parts[8] == "3") {

                                $session["pcr_test"] = $parts[8];
            
                                $response = "CON C4C\nDuring interaction with a COVID-19 patient, were you wear personal protective equipment (PPE)?\n1. Yes \n2. No";
                         
                            } else {
            
                                $response = "CON C4C\nYou have entered an invalid response. Try again.";
            
                                $this->deleteSession($session);
            
                            }            


                        break;

                        case 10:

                            if ($parts[7] == "1" ||$parts[7] == "2" ) {

                                $session["ppe_worn"] = $parts[7] == "1" ? "YES" : "NO";
            
                                if ($parts[7] == "1") {
            
                                    $response = "CON C4C\nWhich of these personal protective equipment (PPE) were you wearing?\n1. Single Gloves\n2. N95 mask (or equivalent)\n3. Face shield or goggles/protective glasses\n4. Disposable gown\n5. Waterproof apron\n7. None";
                         
                                } else if($parts[7] == "2") {
            
                                    $response = "END Thank you for reporting a COVID 19 exposure. Your responses have been recorded ";
            
                                }

                                    dd($session);
            
                                    $client = new Client();
                                
                                        $response = $client->post('http://c4c_api.localhost/api/exposures/covid/new', [
                                                'form_params' => $session,
                                                'headers' => ['Authorization' => 'Bearer ' . $session['token']],
                                                'cookies' => false
                                        ]
                                );
                                
                                $response = json_decode($response->getBody());
                                
                                $response = "$this->sessionClosingTag\n" . $response->message;
            
                            }
            


                        break;
                        
    
                        default:

                        $response = "Ndani";

                        // $response = "END Thank you for reporting an exposure. Your responses have been recorded";

                        break;
                    }
                
                   	                   
				
			
			}
			
			return response($response, 200)->header("Content-Type", "text/plain");
        }
        
}  
    
