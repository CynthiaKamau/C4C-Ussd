<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\UssdRequest;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class C4cJitengeUssdController extends Controller
{
    const END_POINT = "https://c4c_api.mhealthkenya.org/api/exposures/covid/new";

    private $sessionOpeningTag = "CON C4C";

    private $sessionClosingTag = "END C4C";

    protected $client;

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

            $arraySize = count($parts);

            if (empty($session) && $arraySize > 1) {

                $response = "END Unregistered, Download the C4C App and create a new account";

            }
            else {

                $response = "END Please login to C4C App and complete your profile";

            }
        }

        switch ($arraySize) {
            case 1: 
                $isValidNumber = false;
                $phoneNumberObject = null;
                // $phoneNumberUtil = PhoneNumberUtil::getInstance();
                //     if ($phoneNumberUtil->isPossibleNumber($parts[0], "KE")) {
                //     $phoneNumberObject = $phoneNumberUtil->parse($parts[0], "KE");
                //     $isValidNumber = $phoneNumberUtil->isValidNumberFOrRegion($phoneNumberObject, "KE");
                // }

                if (!$isValidNumber || $phoneNumberObject == null) {

                    $response = "$this->sessionClosingTag\nYou have entered an invalid phone number, please try again.";

                    $this->deleteSession($session);
                
                } else {

                    $session['sessionId'] = $sessionId;

                    $session["phone_number"] = trimSpace($phoneNumberUtil->format($phoneNumberObject, PhoneNumber::INTERNATIONAL));

                    $response = $this->client->post(self::END_POINT . 'login', [
                        'form_params' => [
                            'phone_no' => trim(ltrim($session["phone_number"], "+"))
                        ],
                        'cookies' => false
                    ]
                    );

                    $response = json_decode($response->getBody());
                } 

        }
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
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

    private function showPhoneNumberSearchInput($session)
    {
        return $this->sessionOpeningTag . "\nEnter phone number of Health Care Worker";
    }

    private function getListedPhoneNumbers($session)
    {
        $response = $this->client->post(self::END_POINT . 'hcw', [
            'form_params' => [
                'phone_no' => trim(ltrim($session["phone_number"], "+")),
            ],
            'headers' => ['Authorization' => 'Bearer' . $session['token']],
            'cookies' => false
            ]
        );
        
        return $this->listClients($response, $session);

    }

    private function performEvaluation($arraySize, array $parts, $session)
    {
        switch ($arraySize) {
            case 2:
                $choiceIndex = ((int)$parts[1]) - 1;

                if (isset(session['clients']) && isset($session['clients'][$choiceIndex])) {

                    $session["client_id"] = $session['clients'][$choiceIndex]->id;

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . "Have you had contact with a COVID 19 patient?\n1. Yes\n2. No";
                
                } else {

                    $response = "$this->sessionClosingTag\nYou have entered an invalid  response. Try again.";

                    $this->deleteSession($session);
                }

            break;

            case 3:
                if ($parts[2] == "1" || $parts[2] == "2") {

                    $session["contact_person"] = $parts[2] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    if ($parts[2] == "1" || $parts[2] == "2" ) {

                        $response = $this->sessionOpeningTag . '\nWhen did you get into contact with someone with COVID 19? (Date dd/mm/yyyy)';
                    
                    } else {

                        $response = $this->sessionOpeningTag . "\nYou have entered an invalid response. Try again later.";

                        $this->deleteSession($session);

                    }
                }     

                break;

            case 4:
                
                $session["exposure_date"] = $parts[3];

                if ($session['exposure_date'] = Carbon::createFromFormat('dmY', $parts[3])->format('Y-m-d')) {

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . "\nDid you receive IPC training?\n1. Yes\n2. No";
               
                } else {

                    $response = "$this->sessionClosingTag\nYou have entered an nvalid date. Try again";

                    $this->deleteSession($session);
                } 

            break;

            case 5:

                if ($parts[4] == "1" || $parts[4] == "2") {

                    $session["ipc_training"] = $parts[4] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . "\nDuring interaction with a COVID-19 patient, were you wear personal protective equipment (PPE)?\n1. Yes \n2. No";
             
                } else {

                    $response = "$this->sessionClosingTag\nYou have entered an invalid response. Try again.";

                    $this->deleteSession($session);

                }

            break;

            case 6:

                if ($parts[5] == "1" || $parts[5] == "2") {

                    $session["ppe_worn"] = $parts[5] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    if ($parts[5] == "1")

                        $response = $this->sessionOpeningTag . "\nDuring interaction with a COVID-19 patient, did you wear personal protective equipment (PPE)?\n1. Single Gloves\n2. N95 mask (or equivalent)\n3. Face shield or goggles/protective glasses\n4. Disposable gown\n5. Waterproof apron\n6. None";
             
                    } else {

                        $response = "$this->sessionClosingTag\nYou have entered an invalid response. Try again.";

                         $this->deleteSession($session);

                    }

            break;

            case 7:

                if ($parts[6] == "1" || $parts[6] == "2") {

                    $session["ppe_worn"] = $parts[6] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    $response = $this->sessionOpeningTag . "\nWhich of these symptoms are you experiencing any of these symptoms?\n1. Fever\n2. Cough\n3. Difficulty in breathing\n4. Fatigue\n5. Sneezing\n6. Sore throat\n7. None";

            
                } else {

                $response = "$this->sessionClosingTag\nYou have entered an invalid response. Try again";

                $this->deleteSession($session);
            }

            break;


    }
  }
}

