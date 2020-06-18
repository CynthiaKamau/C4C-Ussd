<?php

namespace App\Http\Controllers;

use App\Http\Requests\UssdRequest;
use Carbon\Carbon;
use GuzzleHttp\Client;
use http\Exception;
use Illuminate\Support\Facades\Cache;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class C4cJitengeUssdController extends Controller
{
    private $sessionOpeningTag = "CON C4\n";

    private $sessionClosingTag = "END C4C\n";

    private function getSession($sessionId)
    {
        return Cache::get($sessionId);
    }

    private function setSession(array $session)
    {
        $sessionDurationMinutes = 15;

        Cache::put($session["sessionId"], $session, now()->addMinutes($sessionDurationMinutes));
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

            $response = $this->sessionOpeningTag . "Enter your phone number";

        } else {

            $parts = array_filter(explode('*', $input));

            $arraySize = count($parts);

            if (empty($session) && $arraySize > 1) {

                $response = "END You have entered an invalid phone number";

            } else {

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

                        } else {

                            $session['sessionId'] = $sessionId;

                            $session["phone_number"] = preg_replace('/\s+/', '', trim($phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::INTERNATIONAL)));

                            $this->setSession($session);

                            $response = "$this->sessionOpeningTag\nEnter your password";
                        }

                        break;

                    case 2:

                        $apiResponse = $this->client->post('http://c4c_api.mhealthkenya.org/api/auth/login', [
                                'form_params' => [
                                    'msisdn' => trim(ltrim($session["phone_number"], "+")),
                                    'password' => $parts[1]
                                ],
                                'cookies' => false
                            ]

                        );

                        $apiResponse = json_decode($apiResponse->getBody());

                        if (!empty($apiResponse->access_token) && $apiResponse->success === true) {

                            if ($apiResponse->user->profile_complete == 1) {

                                $session['token'] = $apiResponse->access_token;

                                $session['client_id'] = $apiResponse->user->id;

                                $this->setSession($session);

                                $response = $this->sessionOpeningTag . "Enter your ID Number";

                            } else {

                                $response = $this->sessionClosingTag . "Download the C4C app and complete your profile";

                                $this->deleteSession($session);
                            }


                        } else {

                            $response = $this->sessionClosingTag . "You have entered an invalid password";

                            $this->deleteSession($session);

                        }

                        break;

                    case 3:

                        $session["id_no"] = $parts[2];

                        $this->setSession($session);

                        $response = $this->sessionOpeningTag . "When did you get into contact with someone with COVID 19? DDMMYYYY";

                        break;

                    case 4:

                        try {

                            $session['date_of_contact'] = Carbon::createFromFormat('dmY', $parts[3])->format('Y-m-d');

                            $this->setSession($session);

                            $response = $this->sessionOpeningTag . "What is the source of exposure?\n1 Patient\n2 Colleague\n3 Community\n4 Home\n5 Unknown";


                        } catch (Exception $exception) {

                            $response = $this->sessionClosingTag . "You have entered an invalid date";

                            $this->deleteSession($session);

                        }

                        break;
                    case 5:

                        $index = ((int)$parts[4] - 1);

                        $exposureSources = [
                            'Patient',
                            'Colleague',
                            'Community',
                            'Home',
                            'Unknown',
                        ];

                        if (isset($exposureSources[$index])) {

                            $session["contact_with"] = $exposureSources[$index];

                            $this->setSession($session);

                            $response = $this->sessionOpeningTag . "Have you received IPC training\n1 Yes\n2 No";

                        } else {

                            $response = $this->sessionClosingTag . "You have entered an invalid answer";

                            $this->deleteSession($session);

                        }

                        break;
                    case 6:

                        $index = ((int)$parts[5] - 1);

                        $hasBeenTrained = [
                            'Yes',
                            'No',
                        ];

                        if (isset($hasBeenTrained[$index])) {

                            $session["ipc_training"] = $index;

                            $this->setSession($session);

                            $response = $this->sessionOpeningTag . "Which of these symptoms are you experiencing?\n1. Fever\n2. Cough\n3. Difficulty in breathing\n4. Fatigue\n5. Sneezing\n6. Sore throat\n7. None";

                        } else {

                            $response = $this->sessionClosingTag . "You have entered an invalid answer";

                            $this->deleteSession($session);

                        }


                        break;
                    case 7:

                        $index = ((int)$parts[6] - 1);

                        $exposureSources = [
                            'Fever',
                            'Cough',
                            'Difficulty in breathing',
                            'Fatigue',
                            'Sneezing',
                            'Sore throat',
                            'None',
                        ];

                        if (isset($exposureSources[$index])) {

                            $session["symptoms"] = $exposureSources[$index];

                            $this->setSession($session);

                            $response = $this->sessionOpeningTag . "What are the results of your PCR test?\n1 Positive\n2 Negative\n3 Not applicable";

                        } else {

                            $response = $this->sessionClosingTag . "You have entered an invalid answer";

                            $this->deleteSession($session);

                        }

                        break;
                    case 8:

                        $index = ((int)$parts[7] - 1);

                        $pcrTestResults = [
                            'Positive',
                            'Negative',
                            'Not applicable',
                        ];

                        if (isset($pcrTestResults[$index])) {

                            $session["pcr_test"] = $pcrTestResults[$index];

                            $this->setSession($session);

                            $response = $this->sessionOpeningTag . "Were you wearing Personal Protective Equipment(PPE)\n1 Yes\n2 No";

                        } else {

                            $response = $this->sessionClosingTag . "You have entered an invalid answer";

                            $this->deleteSession($session);

                        }

                        break;
                    case 9:

                        $index = ((int)$parts[8] - 1);

                        $ppeWorn = [
                            'Yes',
                            'No',
                        ];

                        if (isset($ppeWorn[$index])) {

                            $session["ppe_worn"] = $index;

                            $this->setSession($session);

                            if ($parts[8] == "1" || $parts[8] == "2") {

                                if ($parts[8] == "1") {

                                    $response = $this->sessionOpeningTag . "Which of these personal protective equipment (PPE) were you wearing?\n1. Single Gloves\n2. N95 mask (or equivalent)\n3. Face shield or goggles/protective glasses\n4. Disposable gown\n5. Waterproof apron\n7. None";

                                } else {

                                    $response = $this->postData($session);

                                }

                            }
                        } else {

                            $response = $this->sessionClosingTag . "You have entered an invalid answer";

                            $this->deleteSession($session);

                        }

                        break;

                    case 10:

                        $index = ((int)$parts[9] - 1);

                        $ppeWorn = [
                            'Single Gloves',
                            'N95 mask (or equivalent)',
                            'Face shield or goggles/protective glasses',
                            'Disposable gown',
                            'Waterproof apron',
                            'None',
                        ];

                        if (isset($ppeWorn[$index])) {

                            $session["ppes"] = $ppeWorn[$index];

                            $this->setSession($session);

                            $response = $this->postData($session);

                        } else {

                            $response = $this->sessionClosingTag . "You have entered an invalid answer";

                            $this->deleteSession($session);

                        }

                        break;

                }


            }

        }

        return response($response, 200)->header("Content-Type", "text/plain");
    }

    private function postData($session)
    {
        unset($session['phone_number']);

        unset($session['sessionId']);

        //unset($session['token']);

        $session['management'] = 'N/A';
        $session['place_of_diagnosis'] = 'N/A';
        $session['county'] = 0;
        $session['subcounty'] = 0;
        $session['ward_id'] = 0;

        $response = $this->client->post("http://c4c_api.mhealthkenya.org/api/exposures/covid/new/ussd", [
                'form_params' => $session,
                'headers' => ['Authorization' => 'Bearer ' . $session['token']],
                'cookies' => false
            ]
        );

        $response = json_decode($response->getBody());

       return [$response];
        //$response = "END Thank you for reporting a COVID 19 exposure. Your responses have been recorded ";

        return $session;
    }

}
    
