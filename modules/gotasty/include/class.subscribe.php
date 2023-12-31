<?php

    class Subscribe {
    	function __construct($client, $mall) {
            // Self::validation = $client->validation;
            // Self::client = $client;
            // Self::cash = $client->validation->bonus->cash;
            // Self::tree = $client->validation->bonus->tree;
            // Self::invoice = $client->validation->invoice;
            // Self::bonus = $client->validation->bonus;
            // Self::mall = $mall;
        }

        public function reentryVerification($params, $upgradeType){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $checkKYCFlag = Setting::$systemSetting['checkKYCFlag'];
            // $client = Self::client;
            // $cash = Self::cash;

            $clientID = $db->userID;
            $site = $db->userType;

            $type = $params["type"];
            $step = $params["step"];
            $packageID = $params["packageID"];
            $creditUnit = $params["creditUnit"];
            $tPassword = $params["tPassword"];
            $spendCredit = $params["spendCredit"];
            $productID = $params["productID"];
            $pinCode = $params["pinCode"];

            $isSet = 1;

            if($site != "Member"){
                $clientID = $params["clientID"];
            }

            if(!$clientID){
                return array('status' => "error", 'code' => 2, 'statusMsg' => 'Invalid Member.', 'data' => "");
            }

            $db->where("id", $clientID);
            $clientRow = $db->getOne("client", "id, main_id, username, sponsor_id");
            if (empty($clientRow)) {
                return array('status' => 'error', 'code' => 1, 'statusMsg' => $translations["E00119"][$language] /* Client not found. */, 'data' => '');
            }

            if($checkKYCFlag == 1 && $site == "Member"){
            	$db->where("client_id",$clientID);
	            $db->where("status","Approved");
	            $db->orderBy("created_at","DESC");
	            $kycRes = $db->getValue("mlm_kyc","status");
	            if(!$kycRes) return array('status' => 'error', 'code' => 1, 'statusMsg' => $translations["E00778"][$language] /* Your Kyc Is not ready. */, 'data' => '');
            }

            $clientData['clientID'] = $clientRow['id'];
            $clientData['username'] = $clientRow['username'];
            $sponsorID = $clientRow["sponsor_id"];
            $sponsorUsername = $clientRow['username'];
            
            if(!$type){
                return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00427"][$language], 'data' => "");
            }

            if(!$step){
                $step = 2;
            }
            
            switch ($type) {
                case 'credit':
                    $registerType = "Credit Reentry";

                    if (strpos($creditUnit, ".") !== false) {
                        $errorFieldArr[] = array(
                            'id' => 'creditUnitError',
                            'msg' => $translations["E00861"][$language],
                        );
                    }

                    if ($creditUnit <= 0 || !preg_match("/^[1-9][0-9]*$/",$creditUnit)) {
                        $errorFieldArr[] = array(
                            'id' => 'creditUnitError',
                            'msg' => $translations["E00262"][$language],
                        );
                    }
                    
                    $db->where("category",$type);
                    $productID = $db->getValue("mlm_product","id");

                    if($productID){
                        $db->where("product_id",$productID);
                        $db->where("type","Purchase Setting");
                        $productSettingRes = $db->get("mlm_product_setting", null, "name, value");
                        foreach($productSettingRes as $productSettingRow){
                            $productSettingAry[$productSettingRow["name"]] = $productSettingRow["value"];
                        }

                    }else{
                        return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00427"][$language], 'data' => "");
                    }

                    $firstMinPrice = $productSettingAry["1stMinPrice"];
                    $reentryMultiplier = $productSettingAry['reentryMultiplier'] ? $productSettingAry['reentryMultiplier'] : 1;

                    if($firstMinPrice > 0){
                        $db->where("client_id",$clientID);
                        $portfolioCount = $db->getValue("mlm_client_portfolio","count(id)");

                        if($portfolioCount <= 0){
                            // check for 1st time reentry
                            if($creditUnit < $firstMinPrice){
                                $errorMessage = str_replace("%%min1stTimePurchase%%", $firstMinPrice, $translations["E00813"][$language]);
                                $errorFieldArr[] = array(
                                                            'id' => 'creditUnitError',
                                                            'msg' => $errorMessage,
                                                        );
                            }
                        }

                    }

                    if (fmod($creditUnit,$reentryMultiplier) != 0){
                        $errorMessage = str_replace("%%number%%", $reentryMultiplier, $translations["E00823"][$language]);
                        $errorFieldArr[] = array(
                                                    'id' => 'creditUnitError',
                                                    'msg' => $errorMessage,
                                                );
                    }

                    if ($errorFieldArr) {
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }

                    if($step == 2){
                        $isSet = 0;
                    }

                    $bonusValue = $creditUnit;

                    $paymentSetting = Cash::getPaymentDetail($clientID, $registerType, $creditUnit, $productID, "", $isSet);
                    $paymentMethod = $paymentSetting['data']["paymentData"];

                    if($step == 2){
                        //check credit payment
                        $validateCredit  = Cash::paymentVerification($clientID, $registerType, $spendCredit, $productID, $creditUnit);
                        if(strtolower($validateCredit["status"]) != "ok"){
                            return $validateCredit;
                        }

                        $invoiceSpendData = $validateCredit["data"]["invoiceSpendData"];

                        if($site == "Member"){
                             $tPasswordReturn = Client::verifyTransactionPassword($clientID, $tPassword);
                            if($tPasswordReturn["status"] != "ok"){
                                return $tPasswordReturn;
                            }
                        }
                    }
                    $dataOut["creditUnit"] = $creditUnit;
                    $dataOut["paymentCredit"] = $paymentMethod;
                    $dataOut["invoiceSpendData"] = $invoiceSpendData;

                    break;

                case 'package': 
                    $registerType = "Package Reentry";

                    if(!$productID){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }

                    $productData = Product::getProductData($productID);    
                    if(empty($productData)){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }

                    // if($productData["setting"]["Daily Bonus Cap Setting"]){
                    //     foreach ($productData["setting"]["Daily Bonus Cap Setting"] as $purchaseRow) {
                    //         if($purchaseRow["value"] <= 0) continue;
                    //         if($purchaseRow["name"] == "pairingDailyCap"){
                    //             $productRow["pairingDailyCap"] = $purchaseRow["value"];
                    //         }
                    //     }
                    // }

                    $highestProductID = 0;
                    // if($upgradeType == "upgrade"){
                    	$db->where("status","Active");
		            	// $db->where("id",$params['portfolioID']);
		            // }else{
		            	$db->where("client_id", $clientID);
		            	$db->orderBy("id","DESC");
		            // }

                    $res = $db->getOne("mlm_client_portfolio", "id, product_id,product_price");
                    $lastPortfolioID = $res["id"];
		            $highestProductID = $res["product_id"];
		            $priceDeduct = $res["product_price"];

		            if($upgradeType == "upgrade" && !$highestProductID) return array('status' => "error", 'code' => 2, 'statusMsg' => 'Invalid portfolio.', 'data' => "");
                    else if($upgradeType != "upgrade"){
                        if($lastPortfolioID){
                            return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00880"][$language], 'data' => "");
                        }
                    }
                    
                    $highestPriority = 0;
                    if($highestProductID){
                        $db->where("id", $highestProductID);
                        $highestPriority = $db->getValue("mlm_product", "priority");
                    }

                    if($productData["priority"] <= $highestPriority && $upgradeType == "upgrade"){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }
                    if($upgradeType == "upgrade"){
                    	$productData["price"] -= $priceDeduct;
                    }
                    $productRow["price"] = Setting::setDecimal($productData["price"],"");
                    $productRow["languageCode"] = $productData["translation_code"];
                    $productRow["code"] = $productData["code"];
                    $productRow["bonusValue"] = Setting::setDecimal($productData["bonusValue"],"");
                    $bonusValue = $productRow["bonusValue"];
                    $price = $productData["price"];
                    /*if($productData["setting"]["Purchase Setting"]){
                        foreach ($productData["setting"]["Purchase Setting"] as $purchaseRow) {
                            if($purchaseRow["value"] <= 0) continue;

                            $payAmount = 0;

                            if($purchaseRow["reference"]){
                                $db->where("client_id",$clientID);
                                $payAmount = $db->getValue($purchaseRow["reference"],"SUM(payable_amount)");

                                if($payAmount <= $purchaseRow["value"]){
                                    return array('status' => "error", 'code' => 2, 'statusMsg' => "You are not valid to buy this product.", 'data'=> "");
                                }
                            }
                        }
                    }*/

                    $paymentSetting = Cash::getPaymentDetail($clientID, $registerType, $price, $productID, "");
                    $paymentMethod = $paymentSetting['data']["paymentData"];

                    // for skip payment page
                    if($type != "free" && count($paymentMethod) == 1){
                        foreach ($paymentMethod as $creditType => $rowValue) {
                            $spendCredit[$creditType]["amount"] = $price;
                        }

                        $dataOut["spendCredit"] = $spendCredit;
                    }

                    if($step == 2){
                        //check credit payment
                        $validateCredit  = Cash::paymentVerification($clientID, $registerType, $spendCredit, $productID, $price);
                        if(strtolower($validateCredit["status"]) != "ok"){
                            return $validateCredit;
                        }

                        $invoiceSpendData = $validateCredit["data"]["invoiceSpendData"];

                        if($site == "Member"){
                             $tPasswordReturn = Client::verifyTransactionPassword($clientID, $tPassword);
                            if($tPasswordReturn["status"] != "ok"){
                                return $tPasswordReturn;
                            }
                        }
                    }
                    $dataOut["productData"] = $productRow;
                    $dataOut["paymentCredit"] = $paymentMethod;
                    $dataOut["invoiceSpendData"] = $invoiceSpendData;
                    $dataOut["totalPrice"] = $price;
                    break;

                case 'pin': 

                    $registerType = "Pin Reentry";

                    if(!$pinCode){
                        $errorFieldArr[] = array(
                                                    'id' => 'pinCodeError',
                                                    'msg' => $translations["E00842"][$language],
                                                );
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }

                    $db->where("code",$pinCode);
                    $db->where("status", "New");
                    $pinRow = $db->getOne("mlm_pin", "id, product_id, buyer_id, bonus_value, price, belong_id, batch_id, pin_type, owner_id");

                    if(empty($pinRow)){
                        $errorFieldArr[] = array(
                                                    'id' => 'pinCodeError',
                                                    'msg' => $translations["E00401"][$language],
                                                );
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }

                    $productID = $pinRow["product_id"];
                    $buyerID = $pinRow["buyer_id"];
                    $pinType = $pinRow["pin_type"];
                    $pinID = $pinRow["id"];
                    $belongID = $pinRow["belong_id"];

                    $registerType = $pinType == "Normal" ? $registerType : $pinType." ".$registerType;

                    //check is downline or not
                    $db->where("client_id", $clientID);
                    $db->where("trace_key", "%".$buyerID."%","LIKE");
                    $isDownlines = $db->getValue("tree_sponsor", "count(id)");
                    if(!$isDownlines){
                        $errorFieldArr[] = array(
                                                    'id' => 'pinCodeError',
                                                    'msg' => $translations["E00401"][$language],
                                                );
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }

                    $productData = Product::getProductData($productID);
                    if(empty($productData)){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }

                    if($productData["setting"]["Daily Bonus Cap Setting"]){
                        foreach ($productData["setting"]["Daily Bonus Cap Setting"] as $purchaseRow) {
                            if($purchaseRow["value"] <= 0) continue;
                            if($purchaseRow["name"] == "pairingDailyCap"){
                                $productRow["pairingDailyCap"] = $purchaseRow["value"];
                            }
                        }
                    }

                    $highestProductID = 0;

                    $db->where("client_id", $clientID);
                    $db->orderBy("id","DESC");
                    $highestProductID = $db->getValue("mlm_client_portfolio", "product_id");
                    
                    $highestPriority = 0;
                    if($highestProductID){
                        $db->where("id", $highestProductID);
                        $highestPriority = $db->getValue("mlm_product", "priority");
                    }

                    if($productData["priority"] <= $highestPriority){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }

                    $productRow["price"] = Setting::setDecimal($pinRow["price"],"");
                    $productRow["languageCode"] = $productData["translation_code"];
                    $productRow["code"] = $productData["code"];
                    $productRow["bonusValue"] = Setting::setDecimal($pinRow["bonus_value"],"");
                    $tierValue = $pinType == "Normal" ? 0 : Setting::setDecimal($productData["bonusValue"],"");
                    $bonusValue = $productRow["bonusValue"];
                    $price = $productRow["price"];
                    /*if($productData["setting"]["Purchase Setting"]){
                        foreach ($productData["setting"]["Purchase Setting"] as $purchaseRow) {
                            if($purchaseRow["value"] <= 0) continue;

                            $payAmount = 0;

                            if($purchaseRow["reference"]){
                                $db->where("client_id",$clientID);
                                $payAmount = $db->getValue($purchaseRow["reference"],"SUM(payable_amount)");

                                if($payAmount <= $purchaseRow["value"]){
                                    return array('status' => "error", 'code' => 2, 'statusMsg' => "You are not valid to buy this product.", 'data'=> "");
                                }
                            }
                        }
                    }*/

                    if($step == 2){
                        //check credit payment
                        if($site == "Member"){
                             $tPasswordReturn = Client::verifyTransactionPassword($clientID, $tPassword);
                            if($tPasswordReturn["status"] != "ok"){
                                return $tPasswordReturn;
                            }
                        }
                    }

                    $dataOut["productData"] = $productRow;
                    $dataOut["totalPrice"] = $price;
                    $dataOut["pinBelong"] = $belongID;
                    $dataOut["pinID"] = $pinID;

                    break;

                default: 
                    return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00427"][$language], 'data' => "");
                    break;
               
            }
            $dataOut["lastPortfolioID"] = $lastPortfolioID;
            $dataOut["sponsorName"] = $sponsorUsername;
            $dataOut["sponsorID"] = $sponsorID;
            $dataOut["tierValue"] = $tierValue;
            $dataOut["bonusValue"] = $bonusValue;
            $dataOut["productID"] = $productID;
            $dataOut["client"] = $clientData;
            $dataOut["registerType"] = $registerType;
            $dataOut["priceDeduct"] = $priceDeduct;

            return array('status' => "ok", 'code' => 0, 'statusMsg' => '', 'data' => $dataOut);
        }

        public function reentryConfirmation($params, $upgradeType){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $clientID = $db->userID;
            $site = $db->userType;
            $dateTime = date("Y-m-d H:i:s");
            if($upgradeType == "bonusCreate"){
                $dateTime = $params["dateTime"];
            }

            $type = $params["type"];
            $packageID = $params["packageID"];
            $creditUnit = $params["creditUnit"];
            $tPassword = $params["tPassword"];
            $spendCredit = $params["spendCredit"];
            $upgradeClientID = $params["upgradeClientID"];
            $params["step"] = 2;

            if($site != "Member"){
                $clientID = $params["clientID"];
            }

            $verificationReturn = self::reentryVerification($params, $upgradeType);
            if($verificationReturn["status"] != "ok"){
                return $verificationReturn;
            }

            $productID = $verificationReturn["data"]["productID"];
            $bonusValue = $verificationReturn["data"]["bonusValue"];
            $tierValue = $verificationReturn["data"]["tierValue"];
            $registerType = $verificationReturn["data"]["registerType"];
            $creditUnit = $verificationReturn["data"]["creditUnit"];
            $price = $verificationReturn["data"]["totalPrice"];
            $productData = $verificationReturn["data"]["productData"];
            $clientData = $verificationReturn["data"]["clientData"];
            $paymentCredit = $verificationReturn["data"]["paymentCredit"];
            $invoiceSpendData = $verificationReturn["data"]["invoiceSpendData"];
            $pinBelong = $verificationReturn["data"]["pinBelong"];
            $pinID = $verificationReturn["data"]["pinID"];
            $sponsorID = $verificationReturn["data"]["sponsorID"];
            $priceDeduct = $verificationReturn["data"]["priceDeduct"];
            $lastPortfolioID = $verificationReturn["data"]["lastPortfolioID"];

            if($upgradeType == "upgrade") $price += $priceDeduct;

            $payerID = $clientID;

            $unitPrice = General::getLatestUnitPrice();

            $batchID = $db->getNewID();
            $portfolioID = $db->getNewID();
            switch ($type) {
                case 'credit':
                    //deduct payment
                    $paymentResult = Cash::paymentConfirmation($payerID, $registerType, $invoiceSpendData, $productID, $portfolioID, $creditUnit, $dateTime, $batchID);

                    // insert invoice
                    $invoiceData['productId']          = $productID;
                    $invoiceData['bonusValue']         = $bonusValue;
                    $invoiceData['productPrice']       = $creditUnit;
                    $invoiceData['unitPrice']          = $unitPrice;
                    $invoiceData['belongId']           = $batchID;
                    $invoiceData['portfolioId']        = $portfolioID;

                    $invoiceDataArr[] = $invoiceData;
                    $invoiceResult = Invoice::insertFullInvoice($payerID, $creditUnit, $invoiceDataArr, $invoiceSpendData, 'mlm', $batchID);

                    // Failed to insert invoice
                    if (!$invoiceResult)
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00435"][$language] /* Failed to re-entry package. */, 'data' => "");

                    break;

                case 'package':
                    //deduct payment
                    $paymentResult = Cash::paymentConfirmation($payerID, $registerType, $invoiceSpendData, $productID, $portfolioID, $price, $dateTime, $batchID);

                    // insert invoice
                    $invoiceData['productId']          = $productID;
                    $invoiceData['bonusValue']         = $bonusValue;
                    $invoiceData['productPrice']       = $price;
                    $invoiceData['unitPrice']          = $unitPrice;
                    $invoiceData['belongId']           = $batchID;
                    $invoiceData['portfolioId']        = $portfolioID;

                    $invoiceDataArr[] = $invoiceData;
                    $invoiceResult = Invoice::insertFullInvoice($payerID, $price, $invoiceDataArr, $invoiceSpendData, 'mlm', $batchID);

                    // Failed to insert invoice
                    if (!$invoiceResult)
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00435"][$language] /* Failed to re-entry package. */, 'data' => "");

                    break;

                case 'pin':
                    //update pin 

                    $belongID = $pinBelong;
                    $reference = $pinID;

                    $updatePinData = array(
                                                "client_id" => $clientID,
                                                "status" => "Used",
                                                "used_at" => $dateTime,
                                            );

                    $db->where("id", $pinID);
                    $db->update("mlm_pin", $updatePinData);
                    break;

                default: 
                    return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00427"][$language], 'data' => "");
                    break;

            }

            if($upgradeType == "upgrade"){
            	$upgradeParams["lastPortfolioID"] = $lastPortfolioID;
            	// getLastportfolio belong
            	$db->where("id",$lastPortfolioID);
            	$res = $db->getOne("mlm_client_portfolio","belong_id,bonus_value");
            	$belongID = $res["belong_id"];
            	$lastBv = $res["bonus_value"];
            }

            $belongID = $belongID ? $belongID : $batchID;
            //insert client portfolio
            $db->where("name","maturityDays");
            $db->where("product_id",$productID);
            $maturityDays = $db->getValue("mlm_product_setting","value");
            $expiredDate = $maturityDays ? date("Y-m-d H:i:s", strtotime($maturityDays)) : "";
            $insertData = array(    
                                    "portfolioID"  => $portfolioID,
                                    "clientID"     => $clientID,
                                    "productID"    => $productID,
                                    "price"        => $price,
                                    "bonusValue"   => $bonusValue,
                                    "tierValue"    => $tierValue,
                                    "type"         => $registerType,
                                    "belongID"     => $belongID,
                                    "referenceID"  => $reference,
                                    "batchID"      => $batchID,
                                    "status"       => "Active",
                                    "purchaseAt"   => $dateTime,
                                    "expire_at"    => $expiredDate, 
                                    "pairingCap"   => $productData["pairingDailyCap"],
            );
            $portfolioId = self::insertClientPortfolio($insertData,$upgradeParams);

            // remove previous bv
            if($upgradeType == "upgrade") $bonusValue -= $lastBv;
            //insert bonus value
            $bonusInData['clientID']    = $clientID;
            $bonusInData['mainID']      = $payerID;
            $bonusInData['type']        = $registerType;
            $bonusInData['productID']   = $productID;
            $bonusInData['belongID']    = $belongID;
            $bonusInData['batchID']     = $batchID;
            $bonusInData['bonusValue']  = $bonusValue;
            $bonusInData['dateTime']    = $dateTime;
            $bonusInData['processed']   = 0;
            $insertBonusResult = Bonus::insertBonusValue($bonusInData);

            //insert Credit Sources
            $db->where("name", "isMaxCapWallet");
            $db->where("value", "1");
            $maxCapIDAry = $db->getValue("credit_setting", "credit_id", null);
            if($maxCapIDAry){
                $db->where("id", $maxCapIDAry, "IN");
                $maxCapCreditAry = $db->map("name")->get("credit", null, "name");
            }
            $db->where('username', "creditSales");
            $db->where('type', "Internal");
            $internalID = $db->getValue('client', 'id');
            $db->where("product_id", $productID);
            $db->where("type","Credit Sources");
            $creditSourcesRes = $db->get("mlm_product_setting", null, "name, value");
            foreach($creditSourcesRes as $creditSourcesRow){
                $creditAmount = Setting::setDecimal($creditSourcesRow["value"], $creditSourcesRow["name"]);
                if($creditAmount > 0){
                    Cash::insertTAccount($internalID, $payerID, $creditSourcesRow["name"], $creditAmount, $registerType, $db->getNewID(), "", $dateTime, $batchID,  $payerID, "", $portfolioID);
                }
                if($maxCapCreditAry[$creditSourcesRow["name"]]){
                    $maxCapAmount += $creditAmount;
                }
            }

            if($maxCapAmount > 0){
                $db->where("id", $portfolioID);
                $db->update("mlm_client_portfolio", array("max_cap" => $maxCapAmount));
            }
            //calculate rank and insert maxCap
            // $clientRankData = self::upgradeClientRank($clientID, $bonusValue, $dateTime, $portfolioID, $batchID, $registerType);
            self::updateMemberSalesData($clientID, "reentry", $bonusValue);
            Custom::upgradeSponsorRank($clientID,$dateTime);
            Custom::upgradeSponsorRank($sponsorID,$dateTime);

            //get Username
            $db->where('id',$clientID);
            $clientUsername = $db->getValue('client','username');

            //insert activity
            $activityData = array('user' => $clientUsername,'portfolioID' => $portfolioID, "bonusValue" => $bonusValue);
            $activityRes = Activity::insertActivity('Reentry', 'T00012', 'L00012', $activityData, $clientID);
            // Failed to insert activity
            if(!$activityRes)
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00144"][$language] /* Failed to insert activity. */, 'data' => "");

            return array('status' => "ok", 'code' => 0, 'statusMsg' => $translations["M02074"][$language] /* Purchased Successfully. */, 'data' => $dataOut);

        }

        function insertClientPortfolio($params,$upgradeParams) {
            $db = MysqliDb::getInstance();
            $tableName  = "mlm_client_portfolio";
            $referenceNo = Self::generateReferenceNo();

            $db->where('username', "payout");
            $db->where('type', "Internal");
            $internalID = $db->getValue('client', 'id');

            if($upgradeParams){
            	
            	// terminate last portfolio
            	$db->where("id",$upgradeParams["lastPortfolioID"]);
            	$db->update($tableName,array("status"=>"Terminated","expire_at"=>date("Y-m-d H:i:s")));
            }

            $insertData = array(
                "id"                    => $params["portfolioID"],
                "client_id"             => $params['clientID'],
                "product_id"            => $params['productID'],
                "product_price"         => $params['price'],
                "reference_no"          => $referenceNo,
                "bonus_value"           => $params['bonusValue'],
                "tier_value"            => $params['tierValue'],
                "portfolio_type"        => $params['type'],
                "belong_id"             => $params['belongID'],
                "reference_id"          => $params['referenceID'],
                "batch_id"              => $params['batchID'],
                "status"                => $params['status'],
                "expire_at"             => $params['expire_at'],
                "unit_price"            => $params['unitPrice'],
                "creator_id"            => Cash::$creatorID,
                "creator_type"          => Cash::$creatorType,
                "created_at"            => $params['purchaseAt'] ? $params['purchaseAt'] : $db->now(),
                "pairing_cap"           => $params["pairingCap"],
            );

            $portfolioID = $db->insert($tableName, $insertData);
            if (!$portfolioID)
                return false;

            return $portfolioID;
        }

        function generateReferenceNo() {
            $db = MysqliDb::getInstance();
            $tableName  = 'mlm_client_portfolio';
            
            // Get the length setting
            $referenceNoLength = Setting::$systemSetting['referenceNumberLength']?:8;

            $min = "1"; $max = "9";
            for($i=1;$i<$referenceNoLength;$i++) $max .= "9";

            while (1) {
                $referenceNo = sprintf("%0".$referenceNoLength."s", mt_rand((int)$min, (int)$max));
                
                $db->where('reference_no', $referenceNo);
                $count = $db->getValue($tableName, 'count(*)');
                if ($count == 0) break;
                // If exists, continue to generate again
            }

            return $referenceNo;
        }

        public function generateMemberID(){
            $db = MysqliDb::getInstance();
            $db->where('name','memberIDLength');
            $memberIDLength= $db->getOne('system_settings','value');
            $min = 1; $max = 9; 
            $memberIDLength['value'] -= 1;
            for($i=1;$i<(int)$memberIDLength['value'];$i++) $max .= "9";
            while(1){ 
            	$firstDigit = mt_rand(1, 9);
                $memberID = $firstDigit.sprintf("%0".$memberIDLength['value']."s", mt_rand((int)$min, (int)$max));
                $db->where('member_id',$memberID);
                $check = $db->getOne('client','COUNT(id)');
                if($check['COUNT(id)'] == 0) break;
            }
            return $memberID;
        }

        function insertClientSettingByProductSetting($params) {
            
            $db = MysqliDb::getInstance();
            $language           = General::$currentLanguage;
            $translations       = General::$translations;
            
            $productID          = $params['productID'];
            $productBelongID    = $params['productBelongID'];
            $productBatchID     = $params['productBatchID'];
            $remark             = $params['remark'];
            $subject            = $params['subject'];
            $clientID           = $params['clientID'];
            
            // get internal accounts
            $db->where("username", "creditSales");
            $accountID = $db->getValue("client", "id");
            
            // select bonus from mlm_bonus table
            $db->where("allow_rank_maintain", "1");
            $db->where("disabled", "0");
            $bonuses = $db->get("mlm_bonus",null, "name");

            foreach ($bonuses as $bonus)
                $bonusList[] = $bonus['name'];
            
            // Overall rankID
            $bonusList[] = 'rankID';
            
            // select credit from credit table
            $credits = $db->get("credit", null, "name");

            foreach ($credits as $credit)
                $creditList[] = $credit['name'];

            $mergedArray = array_merge($bonusList,$creditList);

            // get product bonuses
            $db->where("product_id", $productID);
            $db->where("name", $mergedArray, "IN");
            $bonusRankList = $db->get("mlm_product_setting", null, "name, value, type");
            
            //check client setting table, update if exists else insert, cant use mysql on duplicate update because table doesn't have any unique column
            foreach($bonusRankList as $newRank){

                $db->where("name", $newRank['name']);
                $db->where("client_id", $clientID);
                $previousRank = $db->get("client_setting", null, "value");

                if (in_array($newRank['name'], $bonusList)){

                    if (empty($previousRank)) {

                        $insertData = array(

                            "name"      => $newRank['name'],
                            "value"     => $newRank['value'],
                            "type"      => $newRank['type'],
                            "client_id" => $clientID
                        );
                        // Insert bonus rank
                        $insertRankResult = $db->insert("client_setting", $insertData);

                        if (empty($insertRankResult))
                            return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00439"][$language] /* Failed to insert client rank. */, 'data' => "");
                        
                        $db->where('name', $newRank['name']);
                        $db->where('rank_id', $newRank['value']);
                        $rankSetting = $db->getOne('rank_setting', null, 'value, type');
                        if($rankSetting) {
                            $rankValue['type'] = $rankSetting['type'];
                            $rankValue['value'] = $rankSetting['value'];
                        }
                        
                        $insertData = array(

                            "name"      => $newRank['name'],
                            "value"     => $rankValue['value']?:'',
                            "type"      => $rankValue['type']?:'',
                            "client_id" => $clientID
                        );
                        // Insert bonus percentage
                        $insertRankValueResult = $db->insert("client_setting", $insertData);
                        
                        unset($rankValue);
                        
                        if (empty($insertRankValueResult))
                            return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00439"][$language] /* Failed to insert client rank. */, 'data' => "");

                    } else {

                        //check previous value whether it is greater than the new one if so remain same value
                        if ($previousRank['value'] < $newRank['value']) {
                            
                            $updateData = array(
                                "value" => $newRank['value']
                            );
                            // Update bonus rank
                            $db->where('type', $newRank['type']);
                            $db->where("name", $newRank['name']);
                            $db->where("client_id", $clientID);
                            $updateRankResult = $db->update("client_setting", $updateData);
                            if (!$updateRankResult)
                                return array('status' => "error", 'code' => 1, 'statusMsg' =>$translations["E00439"][$language] /* Failed to insert client rank. */, 'data' => "");

                            $db->where('name', $newRank['name']);
                            $db->where('rank_id', $newRank['value']);
                            $rankSetting = $db->getOne('rank_setting', null, 'value, type');
                            if($rankSetting) {
                                $rankValue['type'] = $rankSetting['type'];
                                $rankValue['value'] = $rankSetting['value'];
                            }
                            
                            $updateData = array(
                                "value"     => $rankValue['value']?:''
                            );
                            // Update bonus percentage
                            $db->where('type', $rankValue['type']);
                            $db->where("name", $newRank['name']);
                            $db->where("client_id", $clientID);
                            $updateRankValueResult = $db->update("client_setting", $updateData);
                            
                            unset($rankValue);
                            if (empty($updateRankValueResult))
                                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00439"][$language] /* Failed to insert client rank. */, 'data' => "");

                        }
                    }
                }
                else if (in_array($newRank['name'], $creditList)){
                    $insertTAccountResult = Cash::insertTAccount($accountID, $clientID, $newRank['name'], $newRank['value'], $subject, $productBelongID, "", $db->now(), $productBatchID, $clientID, $remark);
                    if(!$insertTAccountResult)
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00440"][$language] /* Failed to insert data */, 'data' => "");
                }
            }
            
            return array('status' => "ok", 'code' => 0, 'statusMsg' => "", 'data' => "");
        }

        public function memberRegistration($params) {
            $db = MysqliDb::getInstance();
            $language           = General::$currentLanguage;
            $translations       = General::$translations;

            $batchRegister      = trim($params['batchRegister']); 
            // personal information
            $dialingArea        = trim($params['dialingArea']);
            $phone              = trim($params['phone']); 
            $password           = trim($params['password']);
            $checkPassword      = trim($params['checkPassword']);

            $step               = trim($params['step']);
            $type               = trim($params['registerType']);
            $registerMethod     = trim($params['registerMethod']); // default username  
            $sponsorID          = trim($params['sponsorId']); 

            //Placement Option
            $site = $db->userType;
            $payerID = $db->userID;

            if ($site == "Admin") {
                $payerID = $params['clientID'];
            }

            $passwordEncryption  = Setting::getMemberPasswordEncryption();

			$maxFName = Setting::$systemSetting['maxFullnameLength'];
			$minFName = Setting::$systemSetting['minFullnameLength'];
			$maxUName = Setting::$systemSetting['maxUsernameLength'];
			$minUName = Setting::$systemSetting['minUsernameLength'];
			$maxPass  = Setting::$systemSetting['maxPasswordLength'];
			$minPass  = Setting::$systemSetting['minPasswordLength'];
			$maxTPass = Setting::$systemSetting['maxTransactionPasswordLength'];
			$minTPass = Setting::$systemSetting['minTransactionPasswordLength'];
            $maxAccPP = Setting::$systemSetting['maxAccPerPhone'];
			$otpCodeVerify         = Setting::$systemSetting["otpCodeVerify"];
			$isSponsorCodeRegister = Setting::$systemSetting["isSponsorCodeRegister"];
            $martialStatusArr = array("single","married","widowed","divorced","separated");
            $genderArr = array("male", "female");

            if(!$step){
                $step = 1;
            }

            if (empty($type)) {
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01024"][$language], 'data' => '');
            }

            if(!$registerMethod) $registerMethod = "username";
                $registerMethodArray = array('phone','email','username');
                if (!in_array($registerMethod,$registerMethodArray)) {
                    return array('status'=>'error','code'=>'1','statusMsg'=>$translations["E01025"][$language],'data'=>array('field'=>'registerMethod'));
            }

            // return array('status'=>'error','code'=>'1','statusMsg'=>$translations["E01025"][$language],'data'=>$phone);

            if($step >= 1){

                // Validate phone
                if (empty($dialingArea) || empty($phone)) {
                    $errorFieldArr[] = array(
                        'id' => 'phoneError',
                        'msg' => $translations["E00305"][$language] /* Please fill in mobile number */
                    );
                } 
                else if ($dialingArea && $phone) {
                    $phone = $dialingArea . $phone;

                    if($phone){
                        $mobileNumberCheck = General::mobileNumberInfo($phone, "MY");
                        if($mobileNumberCheck['isValid'] != 1){
                            // return array('status' => 'error', 'code' => 1, 'statusMsg' => $translations["E01235"][$language] /* Invalid phone or password. Please try again. */, 'data' => $mobileNumberCheck);
                            $errorFieldArr[] = array(
                                'id' => 'phoneError',
                                'msg' => $translations["E01093"][$language] /* Invalid mobile number format */
                            );
                        }
                        $phone = $mobileNumberCheck['phone'];
                    }
                }
                else {
                    if (!preg_match('/^[0-9]*$/', $phone, $matches)) {
                        $errorFieldArr[] = array(
                            'id' => 'phoneError',
                            'msg' => $translations["E00858"][$language] /* Only number is allowed */
                        );
                    }

                    // check max account per phone
                    $db->where("dial_code", $dialingArea);
                    $db->where("phone", $phone);
                    $totalAccThisPhone = $db->getValue("client", "COUNT(*)");
                }

                 // Validate password
                if (empty($password)) {
                    $errorFieldArr[] = array(
                        'id' => 'passwordError',
                        'msg' => $translations["E00306"][$language] /* Please fill in password */
                    );
                } 
                // elseif (!preg_match("#[0-9]+#", $password)) {
                //     $errorFieldArr[] = array(
                //         'id' => 'passwordError',
                //         'msg' => $translations["E00810"][$language] /* Your password must be between 8 and 20 characters long and must contain at least one letter and one number */
                //     );

                // } elseif (!preg_match("#[a-zA-z]+#", $password)) {
                //     $errorFieldArr[] = array(
                //         'id' => 'passwordError',
                //         'msg' => $translations["E00810"][$language] /* Your password must be between 8 and 20 characters long and must contain at least one letter and one number */
                //     );

                // } 
                else {
                    if (strlen($password) < $minPass || strlen($password) > $maxPass) {
                        $errorFieldArr[] = array(
                            'id' => 'passwordError',
                            'msg' => str_replace(array("%%minPass%%", "%%maxPass%%"), array($minPass, $maxPass), $translations["E00810"][$language]) /* Your password must be between 8 and 20 characters long */,
                        );
                    }
                }
                if(!empty($sponsorID))
                {
                    if (!preg_match("#[0-9]+#", $sponsorID)) 
                    {
                        $errorFieldArr[] = array(
                            'id' => 'referralError',
                            'msg' => $translations["E01179"][$language] /* Invalid referral mobile number */
                        );
                    }
                }

                //checking re-type password
                if (empty($checkPassword)) {
                    $errorFieldArr[] = array(
                        'id' => 'checkPasswordError',
                        'msg' => $translations["E01247"][$language] /* Please fill in confirm new password */
                    );
                } else {
                    if ($checkPassword != $password) {
                        $errorFieldArr[] = array(
                            'id' => 'checkPasswordError',
                            'msg' => $translations["E00309"][$language] /* Password does not match with confirm password */
                        );
                    }
                }
            } 

            if ($step >= 2) {


                if(is_numeric($country) && $country) {
                    $db->where("id",$country);
                    $countryRes = $db->getOne("country","name,translation_code");
                    if(!$countryRes){
                        $errorFieldArr[] = array(
                            "id"  => "countryIDError",
                            "msg" => $translations["E01029"][$language]
                        );
                    }
                }

                if (is_numeric($state) && $state){
                    $db->where("id",$state);
                    $db->where("country_id",$country);
                    $db->where("disabled",0);
                    $stateRes = $db->getOne("state","name,translation_code");
                    if(!$stateRes){
                        $errorFieldArr[] = array(
                            "id"  => "stateError",
                            "msg" => $translations["E01029"][$language]
                        );
                    }
                }

                if (is_numeric($city) && $city){
                    $db->where("id",$city);
                    $db->where("state_id",$state);
                    $db->where("country_id",$country);
                    $db->where("disabled",0);
                    $cityRes = $db->getOne("city","name,translation_code");
                    if(!$cityRes){
                        $errorFieldArr[] = array(
                            "id"  => "cityError",
                            "msg" => $translations["E01029"][$language]
                        );
                    }
                }

                if (is_numeric($district) && $district){
                    $db->where("id",$district);
                    $db->where("city_id",$city);
                    $db->where("country_id",$country);
                    $db->where("disabled",0);
                    $districtRes = $db->getOne("county","name,translation_code");
                    if(!$districtRes){
                        $errorFieldArr[] = array(
                            "id"  => "districtErrror",
                            "msg" => $translations["E01113"][$language]
                        );
                    }
                }

                if (is_numeric($subDistrict) && $subDistrict){
                    $db->where("id",$subDistrict);
                    $db->where("county_id",$district);
                    $db->where("country_id",$country);
                    $db->where("disabled",0);
                    $subDistrictRes = $db->getOne("sub_county","name,translation_code");
                    if(!$subDistrictRes){
                        $errorFieldArr[] = array(
                            "id"  => "subDistrictError",
                            "msg" => $translations["E01028"][$language]
                        );
                    }
                }

                if (is_numeric($postalCode) && $postalCode){
                    $db->where("id",$postalCode);
                    $db->where("sub_county_id",$subDistrict);
                    $db->where("country_id",$country);
                    $db->where("disabled",0);
                    $postalCodeRes = $db->getOne("zip_code","name,translation_code");
                    if(!$postalCodeRes){
                        $errorFieldArr[] = array(
                            "id"  => "postalCodeError",
                            "msg" => $translations["E01029"][$language]
                        );
                    }
                }
            }

            if ($step >= 3 && $bankOptional) { 

                if (empty($bankID)) {
                    $errorFieldArr[] = array(
                        'id'  => "bankTypeError",
                        'msg' => $translations["E01031"][$language] /* Please Select A Bank. */
                    );
                }

                if (empty($branch)) {
                    $errorFieldArr[] = array(
                        'id'  => "branchError",
                        'msg' => $translations["E01032"][$language] /* Please Insert Branch */
                    );
                }

                if (empty($bankCity)) {
                    $errorFieldArr[] = array(
                        'id'  => "bankCityError",
                        'msg' => $translations["E01033"][$language] /* Please Insert Bank City */
                    );
                }

                if (empty($accountHolder)) {
                    $errorFieldArr[] = array(
                        'id'  => "accountHolderError",
                        'msg' => $translations["E01034"][$language] /* Please Insert Account Holder's Name */
                    );

                }else{
                    if($accountHolder != $fullName){
                        $errorFieldArr[] = array(
                            "id" => "accountHolderError",
                            "msg" => $translations["E01106"][$language]
                        );
                    }
                }

                if (empty($accountNo)) {
                    $errorFieldArr[] = array(
                        'id'  => "accountNoError",
                        'msg' => $translations["E01035"][$language] /* Please Insert Account Number */
                    );
                }

            }

            if ($step >= 4) {

                if($childNumber > 0 && !$batchRegister){
                    $childAgeOption = explode('#', Setting::$systemSetting['childAgeOption']);
                    // childAge
                    if(!is_array($childAge)){
                        $errorFieldArr[] = array(
                            'id' => 'childAgeError',
                            'msg' => $translations["E01111"][$language] /* Invalid Age. */
                        );
                    }else if(count($childAge) != $childNumber){
                        $errorFieldArr[] = array(
                            'id' => 'childAgeError',
                            'msg' => $translations["E01112"][$language] /* Total count of age not match. */
                        );
                    }else{
                        foreach ($childAge as $childAgeRow) {
                            if(!$childAgeOption[$childAgeRow]){
                                $errorFieldArr[] = array(
                                    'id' => 'childAgeError',
                                    'msg' => $translations["E01111"][$language] /* Invalid Age. */
                                );
                                break;
                            }
                        }
                    }
                }
            }

            if($errorFieldArr){
                $data['field'] = $errorFieldArr;
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
            }

            switch ($type) {
                case 'free':
                    $registerType = 'Free Register';

                    if($site != 'Admin' && $otpCodeVerify){
                        $verifyCode = Otp::verifyOTPCode($clientID,$otpType,"register",$otpCode,$dialingArea.$phone);
                    
                        if($verifyCode["status"] != "ok"){
                            $errorFieldArr[] = array(
                                                        'id'  => 'otpCodeError',
                                                        'msg' => $verifyCode['statusMsg']
                                                    );
                        }else{
                            $otpID = $verifyCode['data'];
                        }
                    }
                    break;

                case 'credit':
                    $registerType = "Credit Register";

                    if(!$payerID){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => 'Invalid Member.', 'data'=>'');
                    }
                    if(!is_numeric($creditUnit) || $creditUnit <= 0){
                        $errorFieldArr[] = array(
                                                    'id' => 'creditUnitError',
                                                    'msg' => $translations["E00428"][$language],
                                                );
                    }

                    $db->where("category",$type);
                    $productID = $db->getValue("mlm_product","id");
                    if($productID){
                        $db->where("product_id",$productID);
                        $db->where("type","Purchase Setting");
                        $productSettingRes = $db->get("mlm_product_setting", null, "name, value");
                        foreach($productSettingRes as $productSettingRow){
                            $productSettingAry[$productSettingRow["name"]] = $productSettingRow["value"];
                        }
                    }else{
                        return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00427"][$language], 'data' => "");
                    }
                    
                    $firstMinPrice = $productSettingAry["1stMinPrice"];
                    $reentryMultiplier = $productSettingAry['reentryMultiplier'] ? $productSettingAry['reentryMultiplier'] : 1;

                    //register must be 1000
                    if($creditUnit < $firstMinPrice){
                        $errorMessage = str_replace("%%min1stTimePurchase%%", $firstMinPrice, $translations["E00813"][$language]);
                        $errorFieldArr[] = array(
                                                    'id' => 'creditUnitError',
                                                    'msg' => $errorMessage,
                                                );
                    }

                    if (fmod($creditUnit,$reentryMultiplier) != 0){
                        $errorMessage = str_replace("%%number%%", $reentryMultiplier, $translations["E00823"][$language]);
                        $errorFieldArr[] = array(
                                                    'id' => 'creditUnitError',
                                                    'msg' => $errorMessage,
                                                );
                    }

                    if ($errorFieldArr) {
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }
                    $isSet = 1;
                    if($step == 2){
                        $isSet = 0;
                    }

                    $paymentSetting = Cash::getPaymentDetail($payerID, $registerType, $creditUnit, $productID, "", $isSet, "register");
                    $paymentMethod = $paymentSetting['data']["paymentData"];
                    if($step == 2){
                        //check credit payment
                        $validateCredit  = Cash::paymentVerification($payerID, $registerType, $spendCredit, $productID, $creditUnit,"", "register");
                        if(strtolower($validateCredit["status"]) != "ok"){
                            return $validateCredit;
                        }

                        $invoiceSpendData = $validateCredit["data"]["invoiceSpendData"];

                        if($site == "Member"){
                             $tPasswordReturn = client::verifyTransactionPassword($payerID, $payerTPassword);
                            if($tPasswordReturn["status"] != "ok"){
                                return $tPasswordReturn;
                            }
                        }
                    }
                    $dataOut["creditUnit"] = $creditUnit;
                    $dataOut["paymentCredit"] = $paymentMethod;
                    $dataOut["invoiceSpendData"] = $invoiceSpendData;

                    break;

                case 'package': 

                    $registerType = "Package Register";

                    if(!$productID){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }

                    $productData = Product::getProductData($productID);                    
                    if(empty($productData)){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }
                    
                    if(!$productData["isRegisterPackage"] && !$productData["isBundlePackage"]){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }

                    $productRow["price"] = Setting::setDecimal($productData["price"],"");
                    $productRow["languageCode"] = $productData["translation_code"];
                    $productRow["code"] = $productData["code"];
                    $productRow["bonusValue"] = Setting::setDecimal($productData["bonusValue"],"");
                    $bonusValue = $productRow["bonusValue"];
                    $price = $productData["price"];
                    if($productData["setting"]["Daily Bonus Cap Setting"]){
                        foreach ($productData["setting"]["Daily Bonus Cap Setting"] as $purchaseRow) {
                            if($purchaseRow["value"] <= 0) continue;
                            if($purchaseRow["name"] == "pairingDailyCap"){
                                $productRow["pairingDailyCap"] = $purchaseRow["value"];
                            }
                        }
                    }

                    $paymentSetting = Cash::getPaymentDetail($payerID, $registerType, $price, $productID, "");
                    $paymentMethod = $paymentSetting['data']["paymentData"];

                    // for skip payment page
                    if($type != "free" && count($paymentMethod) == 1){
                        foreach ($paymentMethod as $creditType => $rowValue) {
                            $spendCredit[$creditType]["amount"] = $price;
                        }

                        $dataOut["spendCredit"] = $spendCredit;
                    }

                    if($step == 2){
                        //check credit payment
                        $validateCredit  = Cash::paymentVerification($payerID, $registerType, $spendCredit, $productID, $price);
                        if(strtolower($validateCredit["status"]) != "ok"){
                            return $validateCredit;
                        }

                        $invoiceSpendData = $validateCredit["data"]["invoiceSpendData"];

                        if($site == "Member"){
                             $tPasswordReturn = Client::verifyTransactionPassword($payerID, $payerTPassword);
                            if($tPasswordReturn["status"] != "ok"){
                                return $tPasswordReturn;
                            }
                        }
                    }
                    $dataOut["productData"] = $productRow;
                    $dataOut["paymentCredit"] = $paymentMethod;
                    $dataOut["invoiceSpendData"] = $invoiceSpendData;
                    $dataOut["totalPrice"] = $price;
                    $dataOut["isBundlePackage"] = $productData["isBundlePackage"] ? $productData["isBundlePackage"] : 0;
                    break;

                case 'pin': 

                    $registerType = "Pin Register";

                    if(!$pinCode){
                        $errorFieldArr[] = array(
                                                    'id' => 'pinCodeError',
                                                    'msg' => $translations["E00842"][$language],
                                                );
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }

                    $db->where("code",$pinCode);
                    $db->where("status", "New");
                    $pinRow = $db->getOne("mlm_pin", "id, product_id, buyer_id, bonus_value, price, belong_id, batch_id, pin_type, owner_id");

                    if(empty($pinRow)){
                        $errorFieldArr[] = array(
                                                    'id' => 'pinCodeError',
                                                    'msg' => $translations["E00401"][$language],
                                                );
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }

                    $productID = $pinRow["product_id"];
                    $buyerID = $pinRow["buyer_id"];
                    $pinType = $pinRow["pin_type"];
                    $pinID = $pinRow["id"];
                    $belongID = $pinRow["belong_id"];
                    $packageType = $pinType;
                    $registerType = $pinType == "Normal" ? $registerType : $pinType." ".$registerType;

                    //check is downline or not
                    $db->where("client_id", $payerID);
                    $db->where("trace_key", "%".$buyerID."%","LIKE");
                    $isDownlines = $db->getValue("tree_sponsor", "count(id)");
                    if(!$isDownlines){
                        $errorFieldArr[] = array(
                                                    'id' => 'pinCodeError',
                                                    'msg' => $translations["E00401"][$language],
                                                );
                        $data['field'] = $errorFieldArr;
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
                    }

                    $productData = Product::getProductData($productID);                    
                    if(empty($productData)){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Package.", 'data'=> "");
                    }
                    
                    if(!$productData["isRegisterPackage"] && !$productData["isBundlePackage"]){
                        return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00843"][$language], 'data'=> "");
                    }

                    $productRow["price"] = Setting::setDecimal($pinRow["price"],"");
                    $productRow["languageCode"] = $productData["translation_code"];
                    $productRow["code"] = $productData["code"];
                    $productRow["bonusValue"] = Setting::setDecimal($pinRow["bonus_value"],"");
                    $bonusValue = $productRow["bonusValue"];

                    if($productData["setting"]["Daily Bonus Cap Setting"]){
                        foreach ($productData["setting"]["Daily Bonus Cap Setting"] as $purchaseRow) {
                            if($purchaseRow["value"] <= 0) continue;
                            if($purchaseRow["name"] == "pairingDailyCap"){
                                $productRow["pairingDailyCap"] = $purchaseRow["value"];
                            }
                        }
                    }

                    if($step == 2){
                        if($site == "Member"){
                             $tPasswordReturn = client::verifyTransactionPassword($payerID, $payerTPassword);
                            if($tPasswordReturn["status"] != "ok"){
                                return $tPasswordReturn;
                            }
                        }
                    }

                    $price = $productData["price"];
                    $tierValue = $pinType == "Normal" ? 0 : Setting::setDecimal($productData["bonusValue"],"");
                    $dataOut["productData"] = $productRow;
                    $dataOut["totalPrice"] = $price;
                    $dataOut["isBundlePackage"] = $productData["isBundlePackage"] ? $productData["isBundlePackage"] : 0;
                    $dataOut["pinBelong"] = $belongID;
                    $dataOut["pinID"] = $pinID;

                    break;

                default: 
                    return array('status' => "error", 'code' => 2, 'statusMsg' => "Invalid Register Type.", 'data' => '');
                    break;
            }

            if ($errorFieldArr) {
                $data['field'] = $errorFieldArr;
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=>$data);
            }

            $dateOfBirth = date("d/m/Y", $dateOfBirth);

            $dataOut["dialingArea"] = $dialingArea;
            $dataOut["phone"] = $phone;

            return array('status' => "ok", 'code' => 0, 'statusMsg' => "", 'data' => $dataOut);
        }

        public function memberRegistrationConfirmation($msgpackData) {
            $db = MysqliDb::getInstance();
            $language           = General::$currentLanguage;
            $translations       = General::$translations;

            $params = $msgpackData['params'];
            // personal information
            $fullName           = trim($params['fullName']); 
            // $username           = trim($params['username']); 
            // $email              = trim($params['email']);
            $dialingArea        = trim($params['dialingArea']);
            $phone              = trim($params['phone']); 
            // $dateOfBirth        = trim($params["dateOfBirth"]); 
            // $gender             = trim($params["gender"]); 
            $password           = trim($params['password']);
            $checkPassword      = trim($params['checkPassword']);
            //Placement Option
            // $placementPosition    = trim($params["placementPosition"]);  

            // billing address and delivery address
            $address            = trim($params['address']);
            $addressType        = trim($params['addressType']); // billing or delivery
            $district           = trim($params['district']);
            $subDistrict        = trim($params['subDistrict']);
            $postalCode         = trim($params['postalCode']);
            $city               = trim($params['city']);
            $state              = trim($params['state']);
            $country            = trim($params['country']);
            $remarks            = trim($params['remarks']);

            // bank info
            $bankOptional       = trim($params['bankOptional']); // 1 need check, 0 no need
            $bankID             = trim($params['bankID']);
            $branch             = trim($params['branch']);
            $bankCity           = trim($params['bankCity']);
            $accountHolder      = trim($params['accountHolder']);
            $accountNo          = trim($params['accountNo']);
            // $uploadData         = $params['uploadData']; // imageSize, imageType, imageName, imageFlag
            // additional info
            $martialStatus      = trim($params['martialStatus']); // single, married, widowed, divorced, separated
            $childNumber        = trim($params['childNumber']);
            $taxNumber          = trim($params['taxNumber']);

            $identityType       = trim($params['identityType']); // nric or passport
            $identityNumber     = trim($params['identityNumber']); // ktp number
            $passport           = trim($params['passport']); // passport
            // $ktpImage           = $params['ktpImage']; // imageSize, imageType, imageName, imageFlag

            $type               = trim($params['registerType']);
            $verificationCode    = trim($params['otpCode']);
            $type1               = trim($params['type']);
            $registerMethod     = trim($params['registerMethod']); // default username    
            $dateTime           = date("Y-m-d H:i:s");
            $date               = date("Y-m-d");
            $step               = $params["step"];
            $sponsorID        = trim($params['sponsorId']); 
            $bkend_token   = $params['bkend_token'];
            $browserInfo = General::getBrowserInfo();
            $ip = $browserInfo["ip"]?$browserInfo["ip"]:"Unknown";
            $returnData = General::ip_info($ip);
            // $ip = $db->$ip;
            $ip = $msgpackData['ip'];

            if($sponsorID)
            {
                $sponsorID = intval($sponsorID);
            }
            // return array("code" => 1, "status" => "error", "statusMsg" => "Data not meet requirement", "data" => $params);

            $validationResult = self::memberRegistration($params);
            if($validationResult['status'] == 'error')
            {
                $data = $validationResult['data'];
                $fields = $data['field'];

                // for ($i = 0; $i < count($fields); $i++) {

                    foreach ($fields as $key => $value) {
                        $newKey = $key + 1;
                        $newErrorArr[$newKey] = $newKey . '.' . $value['msg'];
                    }
    
                    $errorString = implode("\n", $newErrorArr);

                    $find = array("%%phoneNumber%%", "%%ip%%", "%%country%%", "%%errorMessage%%", "%%dateTime%%");
                    $replace = array($dialingArea.$phone, $ip,$returnData['country'], $errorString, $dateTime);
                    $outputArray = Client::sendTelegramMessage('10013', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
                    // $content = '*Register Issue* '."\n\n".'Error Type: '.$fields[$i]['id']."\n".'Error Message: '.$fields[$i]['msg']."\n".'Type: '.$type."\n".'Phone Number: '.$dialingArea.$phone."\n".'Date: '.date('Y-m-d')."\n".'Time: '.date('H:i:s');
                    // Client::sendTelegramNotification($content);
                // }
                if($validationResult['data']){
                    $test =  $validationResult['data'];
                    if(!$verificationCode){
                        $test['field'][] = array(
                            'id' => 'otpError',
                            'msg' => $translations["M01050"][$language] /* Please insert otp code */
                        );
                    }
                } 
                return array("code" => 1, "status" => "error", "statusMsg" => $translations["E00130"][$language], "data" => $test);
            }

            $db->where('dial_code',$dialingArea);
            $db->where('phone',$phone);
            $db->where('type','Client');
            $userExist = $db->get('client',null);
            
            if($userExist)
            {

                $test['field'][] = array(
                    'id' => 'phoneError',
                    'msg' => $translations["E01279"][$language] /* Mobile number already associate with a registered account. Please sign in instead. */
                );

                $find = array("%%phoneNumber%%", "%%ip%%", "%%country%%", "%%errorMessage%%", "%%dateTime%%");
                $replace = array($dialingArea.$phone, $ip,$returnData['country'], $translations["E01252"][$language], $dateTime);
                $outputArray = Client::sendTelegramMessage('10013', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
                   
                return array("code" => 1, "status" => "error", $translations["E00130"][$language] /* Data does not meet requirements. */, "data" => $test);
            }

            // Check the referral code is valid or not
            if(!empty($sponsorID))
            {
                $db->where('concat(dial_code, phone)',$sponsorID);
                $validSponsorID = $db->getOne('client');

                if($sponsorID == $dialingArea.$phone)
                {
                    // return array("code" => 1, "status" => "error", "statusMsg" => $translations["E01179"][$language] /* Invalid Referral ID. */ );
                    $test['field'][] = array(
                        'id' => 'referralError',
                        'msg' => $translations["E01246"][$language] /* Guest cannot referral himself ? */
                    );
                    $find = array("%%phoneNumber%%", "%%ip%%", "%%country%%", "%%errorMessage%%", "%%dateTime%%");
                    $replace = array($dialingArea.$phone, $ip,$returnData['country'], $translations["E01246"][$language], $dateTime);
                    $outputArray = Client::sendTelegramMessage('10013', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
                     
                    return array("code" => 1, "status" => "error", $translations["E00130"][$language] /* Data does not meet requirements. */, "data" => $test);
                }

                if(!$validSponsorID)
                {
                    // return array("code" => 1, "status" => "error", "statusMsg" => $translations["E01179"][$language] /* Invalid Referral ID. */ );
                    $test['field'][] = array(
                        'id' => 'referralError',
                        'msg' => $translations["E01179"][$language] /* Invalid referral mobile number */
                    );
                    $find = array("%%phoneNumber%%", "%%ip%%", "%%country%%", "%%errorMessage%%", "%%dateTime%%");
                    $replace = array($dialingArea.$phone, $ip,$returnData['country'], $translations["E01179"][$language], $dateTime);
                    $outputArray = Client::sendTelegramMessage('10013', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
                     
                    return array("code" => 1, "status" => "error", "statusMsg" => $translations["E00130"][$language] /* Data does not meet requirements. */, "data" => $test);
                }
            }

            // verify user is Guest or Client
            $db->where('concat(dial_code, phone)', $dialingArea.$phone);
            $db->where('type','Guest');
            $GuestAcc = $db->getOne('client');
            if($GuestAcc)
            {
                $oriPassword = $password;
                $password = Setting::getEncryptedPassword($password);
                $updateData = array(
                    'name'          => $GuestAcc['name'],
                    'password'      => $password,
                    'type'          => 'Client',
                    'activated'     => '1',
                    'fail_login'    => '0',
                    'sponsor_id'    => $sponsorID,
                    'updated_at'    => date("Y-m-d H:i:s"),
                );
    
                //update Guest to Client Account
                $db->where('concat(dial_code, phone)',$dialingArea.$phone);
                $db->where('type','Guest');
                $result = $db->update('client',$updateData);
    
                if($result)
                {
                    // Get Referral Name
                    if($sponsorID){
                        $db->where('concat(dial_code, phone)',$sponsorID);
                        $sponsorDetails = $db->getOne('client',null,'name, dial_code, phone');
                        $sponsorName = $sponsorDetails['name'];
                        $sponsorPhone = $sponsorDetails['dial_code'].$sponsorDetails['phone'];
                    }
                    
                    $find = array("%%phoneNumber%%" , "%%name%%" , "%%sponsorPhone%%" ,"%%sponsorName%%" , "%%ip%%", "%%country%%", "%%dateTime%%");
                    $replace = array($dialingArea.$phone, $GuestAcc['name'], $sponsorPhone, $sponsorName, $ip, $returnData['country'], $dateTime);
                    $outputArray = Client::sendTelegramMessage('10014', NULL, NULL, $find, $replace,"","","telegramAdminGroup");


                    unset($msgpackData);
                    unset($params);
                    $params['id'] = '';
                    $params['username'] = $dialingArea.$phone;
                    $params['loginBy'] = 'phone';
                    $params['password'] = $oriPassword;
                    $params['bkend_token'] = $bkend_token;

                    $msgpackData['params'] = $params;
                    $msgpackData['ip'] = $ip;
                    $loginAction = Client::memberLogin($msgpackData);
                    if ($loginAction)
                    {
                        return $loginAction;
                    }
                    //return array('status' => 'ok', 'code' => 0, 'statusMsg' => $translations["B00168"][$language] /* Update successful. */, 'data' => $result);
                }
                else
                {
                    return array('status' => 'error', 'code' => 1, 'statusMsg' => $translations["E00131"][$language] /* Update failed. */, 'data' => '');
                }
            }

            //Check is the user Exist or not
            $db->where('dial_code',$dialingArea);
            $db->where('phone',$phone);
            $db->where('type','Client');
            $userExist = $db->get('client',null);
            
            if($userExist)
            {
                $test['field'][] = array(
                    'id' => 'phoneError',
                    'msg' => $translations["E01279"][$language] /* Mobile number already associate with a registered account. Please sign in instead. */
                );
                $find = array("%%phoneNumber%%", "%%ip%%", "%%country%%", "%%errorMessage%%", "%%dateTime%%");
                $replace = array($dialingArea.$phone, $ip,$returnData['country'], $translations["E01279"][$language], $dateTime);
                $outputArray = Client::sendTelegramMessage('10013', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
                 
                return array("code" => 1, "status" => "error", $translations["E00130"][$language] /* Data does not meet requirements. */, "data" => $test);
                // return array("code" => 1, "status" => "error", "statusMsg" => $translations["E01102"][$language] /* Sorry, we are unable to process your request at the moment. Please try again later or contact customer support for further assistance */ , 'data' => '');
            }

            $site = $db->userType;
            $payerID = $db->userID;
            if($step != 1)
            {
                // Remove leading zeros
                $phone = ltrim($phone, '0');

                // Check if country code is present
                if (substr($phone, 0, strlen($dialingArea)) === $dialingArea) {
                    // Remove country code
                    $phone = substr($phone, strlen($dialingArea));
                }
                $verifyCode = Otp::verifyOTPCode($clientID,'phone',$type1,$verificationCode,$dialingArea.$phone);
            }
            if($verifyCode['status'] == 'error')
            {
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01176"][$language] /* Invalid OTP Code. */, 'data' => '');
            }
            else
            {
                if($fullName == '' || $fullName == null)
                {
                    return array("code" => 1, "status" => "error", "statusMsg" => $translations["E00107"][$language] /* Please Enter Username. */ );
                }
                $db->where('phone_number',$dialingArea.$phone);
                $db->where('status','sent');
                $db->where('msg_type','OTP Code');
                $db->where('verification_type','register##phone');
                $db->where('code',$verificationCode);
                $fields = array("status");
                $values = array("Verified");
                $arrayData = array_combine($fields, $values);
                $row = $db->update("sms_integration", $arrayData);
            }
            
            if(strtolower($validationResult['status']) != 'ok'){
                return $validationResult;
            }

            // $placementID = $validationResult['data']['placementID'];
            // $placementPosition = $validationResult['data']['placementPosition'];
            $productID = $validationResult['data']['productID'];
            $registerType = $validationResult["data"]["registerType"];
            $creditUnit = $validationResult["data"]["creditUnit"];
            $paymentCredit = $validationResult["data"]["paymentCredit"];
            $invoiceSpendData = $validationResult["data"]["invoiceSpendData"];
            $bonusValue = $validationResult["data"]["bonusValue"];
            $tierValue = $validationResult["data"]["tierValue"];
            $price = $validationResult["data"]["totalPrice"];
            $isBundlePackage = $validationResult["data"]["isBundlePackage"];
            $pinBelong = $validationResult["data"]["pinBelong"];
            $pinID = $validationResult["data"]["pinID"];
            $productData = $validationResult["data"]["productData"];
            $otpID = $validationResult["data"]["otpID"];
            $introducerID = $validationResult["data"]["introducerID"];

            $childAge = $validationResult["data"]["childAge"];

            if($site == "Admin"){
                $payerID = $sponsorID;
            }
            
            $unitPrice = General::getLatestUnitPrice();

            $dialingArea = str_replace("+", "", $dialingArea);
            $db->where("country_code", $dialingArea);
            $countryID = $db->getValue("country", "id");


            $clientID     = $db->getNewID();
            $batchID      = $db->getNewID();
            $belongID     = $db->getNewID();

            if($type != "free") $portfolioID  = $db->getNewID();

            switch ($type) {
                case 'credit':

                    //deduct payment
                    $paymentResult = Cash::paymentConfirmation($payerID, $registerType, $invoiceSpendData, $productID, $portfolioID, $creditUnit, $dateTime, $batchID);

                    $belongID = $batchID;
                    // $bonusValue = $creditUnit;
                    // $tierValue = 0;
                    
                    // insert invoice
                    $invoiceData['productId']          = $productID;
                    $invoiceData['bonusValue']         = $bonusValue;
                    $invoiceData['productPrice']       = $creditUnit;
                    $invoiceData['unitPrice']          = $unitPrice;
                    $invoiceData['belongId']           = $batchID;
                    $invoiceData['portfolioId']        = $portfolioID;

                    $invoiceDataArr[] = $invoiceData;
                    $invoiceResult = Invoice::insertFullInvoice($payerID, $creditUnit, $invoiceDataArr, $invoiceSpendData, 'mlm', $batchID);

                    // Failed to insert invoice
                    if (!$invoiceResult)
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00435"][$language] /* Failed to re-entry package. */, 'data' => "");

                    $price = $creditUnit;
                    break;

                case 'package':
                    //deduct payment
                    $paymentResult = Cash::paymentConfirmation($payerID, $registerType, $invoiceSpendData, $productID, $portfolioID, $price, $dateTime, $batchID);

                    $belongID = $batchID;
                    
                    // insert invoice
                    $invoiceData['productId']          = $productID;
                    $invoiceData['bonusValue']         = $bonusValue;
                    $invoiceData['productPrice']       = $price;
                    $invoiceData['unitPrice']          = $unitPrice;
                    $invoiceData['belongId']           = $batchID;
                    $invoiceData['portfolioId']        = $portfolioID;
                    $invoiceDataArr[] = $invoiceData;
                    $invoiceResult = Invoice::insertFullInvoice($payerID, $price, $invoiceDataArr, $invoiceSpendData, 'mlm', $batchID);
                    
                    // Failed to insert invoice
                    if (!$invoiceResult)
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00435"][$language] /* Failed to re-entry package. */, 'data' => "");

                    break;

                case 'pin':
                    //update pin 

                    $belongID = $pinBelong;
                    $reference = $pinID;
                    
                    $updatePinData = array(
                                                "client_id" => $clientID,
                                                "status" => "Used",
                                                "used_at" => $dateTime,
                                            );

                    $db->where("id", $pinID);
                    $db->update("mlm_pin", $updatePinData);
                    break;

                case 'free':
                    break;

                    default: 
                    return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E01177"][$language] /* Invalid Register Type. */, 'data' => '');
                    break;
            }
            $passwordLogin = $password;
            $password = Setting::getEncryptedPassword($password);
            // $tPassword = Setting::getEncryptedPassword($tPassword);
            $sponsorCode = General::generateSponsorCode();

            $memberID = self::generateMemberID();

            $dateOfBirth = date("Y-m-d H:i:s", $dateOfBirth);
            // insert into client table -----------
            $insertClientData = array(
                // "id" => $clientID,
                "member_id" => $memberID,
                "name" => $fullName,
                "username" => $dialingArea.$phone, 
                // "email" => $email,
                "dial_code" => $dialingArea,
                "phone" => $phone,
                // "dob" => $dateOfBirth,
                "password" => $password,
                // "sponsor_code" => $sponsorName,
                "address" => $address,
                "state_id" => $state,
                "country_id" => $country,
                "type" => "Client",
                "activated" => '1',
                "disabled" => '0',
                "sponsor_id" => $sponsorID,
                // "placement_id" => $placementID,
                // "placement_position" => $placementPosition,
                "created_at" => $dateTime,
                // "identity_number" => $identityNumber,
                // "passport" => $passport,
                "register_method" => $registerMethod,
            );
    
            $insertClientResult  = $db->insert('client', $insertClientData);
            // $lq = $db->getLastQuery();
            // return array("code" => 110, "status" => "ok", "statusMsg" => $lq);
            // Failed to insert client account
            if (!$insertClientResult)
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00334"][$language] /* Failed to register member. */, 'data' => "1");

            // insert into client_detail table -----------
            $insertClientDetailData = array(
                "client_id" => $clientID,
                'member_id' => $memberID,
                "gender" => $gender,
                "martial_status" => $martialStatus,
                "num_of_child" => $childNumber,
                "child_age" => $childNumber>0?$childAge:'',
                "tax_number" => $taxNumber,
                // "image_upload_name" => $ktpImage[0]['imageName'],
                // "image_upload_type" => $ktpImage[0]['imageType'],
            );

            $insertClientDetailResult  = $db->insert('client_detail', $insertClientDetailData);

            // Failed to insert client detail table
            if (!$insertClientResult)
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00334"][$language]  /* Failed to register member. */, 'data' => "2");

            if($bankOptional){
                // insert into mlm_client_bank table -----------
                $insertClientBankDetail = array(
                    "client_id" => $clientID,
                    "bank_id" => $bankID,
                    "account_no" => $accountNo,
                    "account_holder" => $accountHolder,
                    "created_at" => $dateTime,
                    "status" => "Active",
                    "branch" => $branch,
                    "bank_city" => $bankCity,
                    // "upload_name" => $uploadData[0]['imageName'],
                    // "upload_type" => $uploadData[0]['imageType'],
                );

                $insertClientBankResult  = $db->insert('mlm_client_bank', $insertClientBankDetail);

                // Failed to insert mlm_client_bank table
                if (!$insertClientBankResult)
                    return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00334"][$language]  /* Failed to register member. */, 'data' => "3");
            }

            // get the client id
            $db->where('concat(dial_code,phone)', $dialingArea.$phone);
            $clientID = $db->getOne('client','id');
            $clientID = $clientID['id'];

            


            if($addressType == 'delivery'){
                 // insert into address table for delivery row
                $insertClientDeliveryAddress = array(
                    "client_id" => $clientID,
                    "name" => $fullName,
                    "phone" => $phone,
                    "address" => $address,
                    "district_id" => $district,
                    "sub_district_id" => $subDistrict,
                    "post_code" => $postalCode,
                    "city" => $city,
                    "state_id" => $state,
                    "country_id" => $country,
                    "address_type" => "delivery",
                    // "remarks" => $remarks,
                    "created_at" => $dateTime,
                );

                $insertClientDeliveryAddressResult  = $db->insert('address', $insertClientDeliveryAddress);

                // Failed to insert address table
                if (!$insertClientDeliveryAddressResult)
                    return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00334"][$language]  /* Failed to register member. */, 'data' => "5");
            }

            
            
            //email verified On/Off
            if(Setting::$systemSetting['disabledEmailVerified'] || $isBatchRegister == 1){
                $db->where("id",$clientID);
                $db->update("client",array("activated" => 1));
            
            } else {


                
            }

            if($otpID){
                $db->where('id',$otpID,'IN');
                $db->update('sms_integration',array('expired_at'=>$db->now()));
            }
            // insert/update total downline

            // Insert 
            unset($insertData);
            $insertData = array(
                "client_id" => $clientID,
                "name" => "awardCycleDate",
                "value" => $date,// Cycle Start Date
                "type" => 0, // Director Rank Entitle count
                "reference" => 0, // Unicorn Rank Entitle count
            );
            $db->insert('client_setting',$insertData);

            // Insert for Leadership Cash Rewrad & Yearly acc status checking
            unset($insertData);
            $insertData = array(
                "client_id" => $clientID,
                "name" => "yearlyStartDate",
                "value" => $date,
                "type" => 0, 
                "reference" => 0, 
            );
            $db->insert('client_setting',$insertData);


            $activityData = array('user' => $fullName);
            $activityRes = Activity::insertActivity('Registration', 'T00001', 'L00001', $activityData, $clientID);
            // Failed to insert activity
            if(!$activityRes)
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00144"][$language] /* Failed to insert activity. */, 'data' => "");

            
            $db->where('phone_number',$dialingArea.$phone);
            $db->where('status','Verified');
            $db->where('msg_type','OTP Code');
            $db->where('verification_type','register##phone');
            $db->where('code',$verificationCode);
            $fields = array("status");
            $values = array("Success");
            $arrayData = array_combine($fields, $values);
            $row = $db->update("sms_integration", $arrayData);

            // Get Referral Name
            if($sponsorID){
                $db->where('concat(dial_code, phone)',$sponsorID);
                $sponsorDetails = $db->getOne('client',null,'name, dial_code, phone');
                $sponsorName = $sponsorDetails['name'];
                $sponsorPhone = $sponsorDetails['dial_code'].$sponsorDetails['phone'];
            }
            
            $find = array("%%phoneNumber%%" , "%%name%%" , "%%sponsorPhone%%" ,"%%sponsorName%%" , "%%ip%%", "%%country%%", "%%dateTime%%");
            $replace = array($dialingArea.$phone, $fullName, $sponsorPhone, $sponsorName, $ip, $returnData['country'], $dateTime);
            $outputArray = Client::sendTelegramMessage('10014', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
            // $content = '*Register Message* '."\n\n".'Member ID: '.$memberID."\n".'Type: Client'."\n".'Phone Number: +'.$dialingArea.$phone."\n".'Referral ID: '.$sponsorID."\n".'Referral Name: '.$sponsorName."\n".'Referral Phone No: +'.$sponsorPhone."\n".'Date: '.date('Y-m-d')."\n".'Time: '.date('H:i:s');
            // Client::sendTelegramNotification($content);

            unset($msgpackData);
            unset($params);
            $params['id'] = '';
            $params['username'] = $dialingArea.$phone;
            $params['loginBy'] = 'phone';
            $params['password'] = $passwordLogin;
            $params['bkend_token'] = $bkend_token;

            $msgpackData['params'] = $params;
            // $msgpackData['ip'] = '127.0.0.1';
            $msgpackData['ip'] = $ip;
            $loginAction = Client::memberLogin($msgpackData);
            if ($loginAction)
            {
                return $loginAction;
            }
        }

        public function insertProfileDetails($params,$userID = 0) {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $username = trim($params['username']);
            $countryID = trim($params['countryID']);
            $transactionPassword = trim($params['transactionPassword']);
            $confirmTransactionPassword = trim($params['confirmTransactionPassword']);
            $sponsorUsername = trim($params['sponsorUsername']);
            $avatar = trim($params['avatar']);

            $maxTPass = Setting::$systemSetting['maxTransactionPasswordLength'];
            $minTPass = Setting::$systemSetting['minTransactionPasswordLength'];

            $db->where('id',$userID);
            $isValidUser = $db->has('client');
            if (!$isValidUser)
                return array('status'=>'error','code'=>'1','statusMsg'=>'Invalid user.','data'=>array('field'=>'user'));

            $db->where('id',$userID);
            $isCompleted = $db->getValue('client','username');
            if ($isCompleted) {
                $errorFieldArr[] = array(
                    'id' => 'userError',
                    'msg' => 'User profile already completed.'
                );
            }

            if (!$username) {
                $errorFieldArr[] = array(
                    'id' => 'usernameError',
                    'msg' => $translations['E00323'][$language]
                );
            }

            if (!$countryID) {
                $errorFieldArr[] = array(
                    'id' => 'countryError',
                    'msg' => $translations['E00568'][$language]
                );
            } else {
                $db->where('id',$countryID);
                $db->where('status','Active');
                $isValidCountry = $db->has('country');
                if (!$isValidCountry) {
                    $errorFieldArr[] = array(
                        'id' => 'countryError',
                        'msg' => $translations['E00568'][$language]
                    );
                }
            }

            if (empty($sponsorUsername)) {
                $errorFieldArr[] = array(
                    'id' => 'sponsorUsernameError',
                    'msg' => $translations['E00320'][$language]
                );
            } else {
                $db->where("username", $sponsorUsername);
                $sponsorID = $db->getValue("client", "id");

                $sponsorDownlineAry = Tree::getSponsorTreeDownlines($sponsorID);
                if (!$sponsorID) {
                    $errorFieldArr[] = array(
                        'id' => 'sponsorUsernameError',
                        'msg' => $translations['E00321'][$language]
                    );
                } else if (!in_array($sponsorID, $sponsorDownlineAry)) {
                    $errorFieldArr[] = array(
                        'id' => 'sponsorUsernameError',
                        'msg' => $translations['E00820'][$language]
                    );
                }
            }

            if (!$transactionPassword) {
                $errorFieldArr[] = array(
                    'id' => 'transactionPasswordError',
                    'msg' => $translations['E00919'][$language]
                );
            } elseif (!preg_match("#[0-9]+#", $transactionPassword)) {
                $errorFieldArr[] = array(
                    'id' => 'transactionPasswordError',
                    'msg' => $translations['E00919'][$language]
                );
            } elseif (!preg_match("#[a-zA-z]+#", $transactionPassword)) {
                $errorFieldArr[] = array(
                    'id' => 'transactionPasswordError',
                    'msg' => $translations['E00919'][$language]
                );
            } else {
                if (strlen($transactionPassword) < $minTPass || strlen($transactionPassword) > $maxTPass) {
                    $errorFieldArr[] = array(
                        'id' => 'transactionPasswordError',
                        'msg' => $translations['E00919'][$language]
                    );
                }
            }

            if (!$confirmTransactionPassword) {
                $errorFieldArr[] = array(
                    'id' => 'confirmTransactionPasswordError',
                    'msg' => $translations['E00919'][$language]
                );
            } elseif ($transactionPassword != $confirmTransactionPassword) {
                $errorFieldArr[] = array(
                    'id' => 'confirmTransactionPasswordError',
                    'msg' => $translations['E00313'][$language]
                );
            }

            if (!$avatar) {
                $errorFieldArr[] = array(
                    'id' => 'avatarError',
                    'msg' => $translations['E00920'][$language]
                );
            }

            if ($errorFieldArr) {
                $data['field'] = $errorFieldArr;

                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=>$data);
            }

            $transactionPassword = Setting::getEncryptedPassword($transactionPassword);

            $updateData = array(
                'username' => $username,
                'transaction_password' => $transactionPassword,
                'country_id' => $countryID,
                'avatar' => $avatar,
                'sponsor_id' => $sponsorID
            );
            $db->where('id',$userID);
            $db->update('client',$updateData);

            $sponsorTree = Tree::insertSponsorTree($userID, $sponsorID);
            if (!$sponsorTree)
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00334"][$language] /* Failed to register member. */, 'data' => "7");

            return array('status'=>'ok','code'=>'1','statusMsg'=>$translations['E00696'][$language],'data'=>'');
        }

        public function upgradeClientRank($clientID, $currentBonusValue, $dateTime, $portfolioID, $batchID, $registerType){
            $db = MysqliDb::getInstance();      
            $language = General::$currentLanguage;
            $translations = General::$translations;   
            $rankType = "Bonus Tier";
            
            if(!$clientID){
                return false;
            }

            $db->where("client_id", $clientID);
            $db->where("status","Active");
            $totalBV = $db->getValue("mlm_client_portfolio", "sum(bonus_value + tier_value)");

            //get all rank setting
            $db->where("type", $rankType);
            $db->orderBy("priority","DESC");
            $rankIDAry = $db->map("id")->get("rank",null, "id, name");

            $rankSettingRes = $db->get("rank_setting", null, "rank_id, name, value, type, reference");
            foreach($rankSettingRes as $rankSettingRow){
                if($rankSettingRow["type"] == "percentage"){
                    if($rankSettingRow["name"] == "goldmineBonusPercentage"){
                        $rankSettingRow["value"] = "";
                    }
                    $rankSettingAry[$rankSettingRow["rank_id"]][$rankSettingRow["name"]] = $rankSettingRow["value"];
                }else if($rankSettingRow["type"] == "purchase"){
                    $minRankQualification[$rankSettingRow["rank_id"]][$rankSettingRow["name"]] = $rankSettingRow["value"];
                }

                if($rankSettingRow["reference"] == "Income Cap"){
                    $rankMaxCapAry[$rankSettingRow["rank_id"]][$rankSettingRow["name"]] = $rankSettingRow["value"];
                }
            }

            foreach ($rankIDAry as $rankID => $rankName) {
                $minTotalBV = $minRankQualification[$rankID]["minRankQualification"];
                if($totalBV >= $minTotalBV){
                    $clientRankID = $rankID;
                    $clientRankData = $rankSettingAry[$rankID];
                    $clientRankIncomeCap = $rankMaxCapAry[$rankID];
                    break;
                }
            }
            
            $db->where("client_id", $clientID);
            $db->where("rank_type",$rankType);
            $db->where("type","System");
            $db->orderBy("created_at","ASC");
            $clientRankRes = $db->get("client_rank", null, "name, value, rank_id");
            foreach($clientRankRes as $clientRankRow){
                $prevRankAry[$clientRankRow["name"]] = $clientRankRow;
            }
            if(empty($prevRankAry)){
                //insert
                $insertClientRank = array(
                    'client_id'  => $clientID,
                    'name'       => "rankDisplay", // rank_setting (name) 
                    'rank_id'    => $clientRankID,
                    'value'      => "", // rank_setting (value)  
                    'rank_type'  => $rankType,
                    'type'       => 'System', // rank_setting (type) 
                    'created_at' => $db->now(),
                );
                $db->insert('client_rank', $insertClientRank);

                foreach($clientRankData as $dataName => $dataPercentage){
                    $insertClientRank = array(
                        'client_id'  => $clientID,
                        'name'       => $dataName, // rank_setting (name) 
                        'rank_id'    => $clientRankID,
                        'value'      => $dataPercentage, // rank_setting (value)  
                        'rank_type'  => $rankType,
                        'type'       => 'System', // rank_setting (type) 
                        'created_at' => $db->now(),
                    );
                    $db->insert('client_rank', $insertClientRank); 
                }
            }else{

                if($prevRankAry["rankDisplay"]["rank_id"] != $clientRankID){
                    $insertClientRank = array(
                        'client_id'  => $clientID,
                        'name'       => "rankDisplay", // rank_setting (name) 
                        'rank_id'    => $clientRankID,
                        'value'      => "", // rank_setting (value)  
                        'rank_type'  => $rankType,
                        'type'       => 'System', // rank_setting (type) 
                        'created_at' => $db->now(),
                    );
                    $db->insert('client_rank', $insertClientRank);
                }

                foreach($clientRankData as $dataName => $dataPercentage){

                    if($prevRankAry[$dataName]["rank_id"] != $clientRankID){
                        //insert new
                        $insertClientRank = array(
                            'client_id'  => $clientID,
                            'name'       => $dataName, // rank_setting (name) 
                            'rank_id'    => $clientRankID,
                            'value'      => $dataPercentage, // rank_setting (value)  
                            'rank_type'  => $rankType,
                            'type'       => 'System', // rank_setting (type) 
                            'created_at' => $db->now(),
                        );
                        $db->insert('client_rank', $insertClientRank); 
                    }
                }

            }


            if($currentBonusValue > 0){
                //maxCap
                $db->where("client_id",$clientID);
                $db->where("rank_type",$rankType);
                $db->where("name","maxCap");
                $db->where("type","Admin");
                $db->orderBy("created_at","ASC");
                $adminSetMaxCapRes = $db->get("client_rank",null, "id, name, value");
                foreach ($adminSetMaxCapRes as $adminSetMaxCapRow) {
                    $adminSetIncomeCap[$adminSetMaxCapRow["name"]] = $adminSetMaxCapRow["value"];
                }

                if($adminSetIncomeCap["maxCap"] > $clientRankIncomeCap["maxCap"]){
                    $clientRankIncomeCap = $adminSetIncomeCap;
                }

                //insert income cap
                $db->where('username', "creditSales");
                $db->where('type', "Internal");
                $internalID = $db->getValue('client', 'id');

                foreach ($clientRankIncomeCap as $creditType => $percentage) {
                    $maxCapAmount = Setting::setDecimal(($currentBonusValue * $percentage/100), $creditType);
                    if($maxCapAmount > 0){
                        Cash::insertTAccount($internalID, $clientID, $creditType, $maxCapAmount, $registerType, $db->getNewID(), "", $dateTime, $batchID,  $clientID, "", $portfolioID);
                    }
                    if($maxCapType == 'maxCap'){
                        $maxCapValue = $maxCapAmount;
                    }
                }

                $updateMaxCap = array('max_cap' => $maxCapValue);
                $db->where('id', $portfolioID);
                $db->update('mlm_client_portfolio', $updateMaxCap);
            }
            

            return array('status' => "ok", 'code' => 0, 'statusMsg' => "", 'data' => "");
        }

        public function adminBatchRegistration($params, $site) {
            $db = MysqliDb::getInstance();
            $language     = General::$currentLanguage;
            $translations = General::$translations;

            $fileDataBase64 = base64_decode((string)$params['base64']);
            $tmp_handle = tempnam(sys_get_temp_dir(), 'adminBatchRegistration');

            $handle = fopen($tmp_handle, 'r+');
            fwrite($handle, $fileDataBase64);
            rewind($handle);

            $fileType = PHPExcel_IOFactory::identify($tmp_handle);
            $objReader = PHPExcel_IOFactory::createReader($fileType);
            
            $excelObj = $objReader->load($tmp_handle);
            $worksheet = $excelObj->getSheet(0);
            $lastRow = $worksheet->getHighestRow();
            $lastCol = $worksheet->getHighestColumn();
            $lastCol++;

            if($lastRow <= 1)
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00355"][$language] /* The selected file cannot be empty */, 'data' => "");

            if($worksheet->getCell('B1')->getValue() != "Full Name")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");
    
            if($worksheet->getCell('C1')->getValue() != "Email Address")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('D1')->getValue() != "Mobile Number")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");
            
            if($worksheet->getCell('E1')->getValue() != "Date Of Birth")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('F1')->getValue() != "Gender")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('G1')->getValue() != "Login Password")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");
            // check password

            if($worksheet->getCell('H1')->getValue() != "Sponsor Name")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('I1')->getValue() != "Address")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('J1')->getValue() != "District")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('K1')->getValue() != "Sub District")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('L1')->getValue() != "City")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('M1')->getValue() != "Postal Code")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('N1')->getValue() != "State")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('O1')->getValue() != "Country")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('P1')->getValue() != "Billing Address")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('Q1')->getValue() != "Bank")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('R1')->getValue() != "Branch")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('S1')->getValue() != "Bank City")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('T1')->getValue() != "Account Holder")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('U1')->getValue() != "Account Number")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");
            
            if($worksheet->getCell('V1')->getValue() != "Marital Status")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('W1')->getValue() != "Child Number")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('X1')->getValue() != "Tax Number")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('Y1')->getValue() != "Identity Type")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('Z1')->getValue() != "Identity Number")
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if($worksheet->getCell('AA1')->getValue() != "Placement Position") 
                return array('status' => "error", 'code' => 1, 'statusMSg' => $translations["E00356"][$language] /* The selected file is in the wrong format */, 'data' => "");

            if(
                $worksheet->getCell('B2')->getValue() == "" 
                || $worksheet->getCell('C2')->getValue() == "" 
                || $worksheet->getCell('D2')->getValue() == "" 
                || $worksheet->getCell('E2')->getValue() == "" 
                || $worksheet->getCell('F2')->getValue() == "" 
                || $worksheet->getCell('G2')->getValue() == "" 
                || $worksheet->getCell('H2')->getValue() == "" 
                || $worksheet->getCell('I2')->getValue() == "" 
                || $worksheet->getCell('J2')->getValue() == ""
                || $worksheet->getCell('K2')->getValue() == ""
                || $worksheet->getCell('L2')->getValue() == ""
                || $worksheet->getCell('M2')->getValue() == ""
                || $worksheet->getCell('N2')->getValue() == ""
                || $worksheet->getCell('O2')->getValue() == ""
                || $worksheet->getCell('P2')->getValue() == ""
                || $worksheet->getCell('Q2')->getValue() == ""
                || $worksheet->getCell('R2')->getValue() == ""
                || $worksheet->getCell('S2')->getValue() == ""
                || $worksheet->getCell('T2')->getValue() == ""
                || $worksheet->getCell('U2')->getValue() == ""
                || $worksheet->getCell('V2')->getValue() == ""
                // || $worksheet->getCell('W2')->getValue() == "" //child number
                || $worksheet->getCell('X2')->getValue() == ""
                || $worksheet->getCell('Y2')->getValue() == ""
                || $worksheet->getCell('Z2')->getValue() == ""
                || $worksheet->getCell('AA2')->getValue() == ""
            )
                return array('status' => "error", 'code' => 1, 'statusMsg' => 'Empty row detected', 'data' => "");            

            $dataInsert = array (
                                    'data'       => $params['base64'],
                                    'type'       => $params['type'],
                                    'created_at' => $db->now()
                                );
            $uploadID = $db->insert('uploads', $dataInsert);

            if(empty($uploadID))
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00357"][$language] , 'data' => "");


            $dataInsert = array (
                                    'type'              => 'adminBatchRegistration',
                                    'attachment_id'     => $uploadID,
                                    'attachment_name'   => $params['name'],
                                    'creator_id'        => $params['clientID'],
                                    'creator_type'      => $site,
                                    'created_at'        => $db->now()
                                );
            $importID = $db->insert('mlm_import_data', $dataInsert);

            if(empty($importID))
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00357"][$language] /* Failed to mass change password */, 'data' => "");

            $recordCount = 0; $processedCount = 0; $failedCount = 0;


            for($row=2; $row<=$lastRow; $row++) {

                // $recordCount++;

                $fullName = $worksheet->getCell('B'.$row)->getValue();
                $email = $worksheet->getCell('C'.$row)->getValue();
                $phone = $worksheet->getCell('D'.$row)->getValue();
                $dateOfBirth = $worksheet->getCell('E'.$row)->getValue();
                $gender = strtolower($worksheet->getCell('F'.$row)->getValue());
                $password = $worksheet->getCell('G'.$row)->getValue();

                $sponsorName = $worksheet->getCell('H'.$row)->getValue();
                $address = $worksheet->getCell('I'.$row)->getValue();
                $district = $worksheet->getCell('J'.$row)->getValue();
                $subDistrict = $worksheet->getCell('K'.$row)->getValue();
                $city = $worksheet->getCell('L'.$row)->getValue();
                $postalCode = $worksheet->getCell('M'.$row)->getValue();
                $state = $worksheet->getCell('N'.$row)->getValue();
                $country = $worksheet->getCell('O'.$row)->getValue();
                $addressType = $worksheet->getCell('P'.$row)->getValue();
                $bankName = $worksheet->getCell('Q'.$row)->getValue();
                $branch = $worksheet->getCell('R'.$row)->getValue();
                $bankCity = $worksheet->getCell('S'.$row)->getValue();
                $accountHolder = $worksheet->getCell('T'.$row)->getValue();
                $accountNo = $worksheet->getCell('U'.$row)->getValue();
                $martialStatus = strtolower($worksheet->getCell('V'.$row)->getValue());
                $childNumber = $worksheet->getCell('W'.$row)->getValue() ? : 0;
                $taxNumber = $worksheet->getCell('X'.$row)->getValue();
                $identityType = $worksheet->getCell('Y'.$row)->getValue();
                $identityNumber = $worksheet->getCell('Z'.$row)->getValue();
                $placementPosition = $worksheet->getCell('AA'.$row)->getValue();
                
                unset($checkPassword);
                unset($icNumber);
                unset($passport);

                $checkPassword = $password;

                if($identityType == 'KTP') $identityType = 'nric';
                // nric or passport
                if($identityType == 'nric') {
                    $icNumber = $identityNumber;
                }else{
                    $passport = $identityNumber;
                }

                $errorMessage = "";

                if (!$fullName){
                    $emptyRowCount++;
                    if ($emptyRowCount>=5){
                        break;//IF too many consecutive empty rows, break out of the loop
                    }
                    continue;
                }
                $emptyRowCount=0;

                $recordCount++;

                $db->where("name", $country);
                $copyDb = $db->copy();
                $countryID = $db->getValue("country", "id");
                $dialingArea = $copyDb->getValue("country","country_code");

                if(!$countryID || !$dialingArea){
                    $errorMessage = "Insert Wrong Country Name.";
                }

                $db->where("name", $state);
                $stateID = $db->getValue("state", "id");

                if(!$stateID){
                    $errorMessage = "Insert Wrong State Name.";
                }

                $db->where("name",$city);
                $db->where("state_id",$stateID);
                $cityID = $db->getValue("city","id");

                if(!$cityID){
                    $errorMessage = "Insert Wrong City Name.";
                }

                $db->where("name",$district);
                $db->where("city_id",$cityID);
                $districtID = $db->getValue("county","id");

                if(!$districtID){
                    $errorMessage = "Insert Wrong District Name.";
                }

                $db->where("name",$subDistrict);
                $db->where("county_id",$districtID);
                $subDistrictID = $db->getValue("sub_county","id");

                if(!$subDistrictID){
                    $errorMessage = "Insert Wrong Sub District Name.";
                }

                $db->where("name",$postalCode);
                $db->where("sub_county_id",$subDistrictID);
                $postalCodeID = $db->getValue("zip_code","id");

                if(!$postalCodeID){
                    $errorMessage = "Insert Wrong Postal Code.";
                }

                $db->where('status', 'Active');
                $db->where("name", $bankName);
                $bankID = $db->getValue("mlm_bank", "id");

                if(!$bankID){
                    $errorMessage = "Insert Wrong Bank Name.";
                }

                $db->where("name", $sponsorName);
                $sponsorMemberID = $db->getValue("client", "member_id");

                if(!$sponsorMemberID){
                    $errorMessage = "Insert Wrong Sponsor Name.";
                }

                $dateOfBirth = strtotime($dateOfBirth);

                if (strtolower($placementPosition) != "left" && strtolower($placementPosition) != "right" || $placementPosition == "" ){
                    $errorMessage = "Insert Wrong Placement Position";
                }

                if(strtolower($placementPosition) == "left"){
                    $placementPosition = 1;
                } else{
                    $placementPosition = 2;
                }

                $registerParams = array(
                    "batchRegister" => '1',
                    // personal information
                    "fullName" => $fullName,
                    "email" => $email,
                    "dialingArea" => $dialingArea,
                    "phone" => $phone,
                    "dateOfBirth" => $dateOfBirth,
                    "gender" => $gender,
                    "password" => $password,
                    "checkPassword" => $checkPassword,
                    "sponsorName" => $sponsorMemberID,
                    "placementPosition" => $placementPosition,

                    // billing address and delivery address
                    "address" => $address,
                    "addressType" => $addressType,
                    "district" => $districtID,
                    "subDistrict" => $subDistrictID,
                    "city" => $cityID,
                    "postalCode" => $postalCodeID,
                    "state" => $stateID,
                    "country" => $countryID,
                    "remarks" => $remarks,

                    // bank info
                    "bankID" => $bankID,
                    "branch" => $branch,
                    "bankCity" => $bankCity,
                    "accountHolder" => $accountHolder,
                    "accountNo" => $accountNo,

                    // additional info
                    "martialStatus" => $martialStatus,
                    "childNumber" => $childNumber,
                    "taxNumber" => $taxNumber,
                    "identityType" => $identityType,
                    "identityNumber" => $icNumber,
                    "passport" => $passport,

                    "registerType" => "free",
                );

                if(empty($errorMessage)){

                    $result = Self::memberRegistrationConfirmation($registerParams);

                    if($result["status"] == "ok"){
                        $status = "Success";
                        $processedCount++;
                        $errorMessage = "";
                    }else{
                        $status = "Failed";
                        $failedCount++;
                        $errorMessage = $result["data"]["field"][0]["msg"];
                    }
                }else{
                    $status = "Failed";
                    $failedCount++;
                }

                $json = json_encode($registerParams);

                $dataInsert = array (
                                        'mlm_import_data_id' => $importID,
                                        'data'               => $json,
                                        'processed'          => "1",
                                        'status'             => $status,
                                        'error_message'      => $errorMessage
                                    );
                $ID = $db->insert('mlm_import_data_details', $dataInsert);

                if(empty($ID))
                    return array('status' => "error", 'code' => 1, 'statusMsg' => "", 'data' => "");

            }

            $dataUpdate = array (
                                    'total_records'     => $recordCount,
                                    'total_processed'   => $processedCount,
                                    'total_failed'      => $failedCount
                                );
            $db->where('id', $importID);
            $db->update('mlm_import_data', $dataUpdate);

            $handle = fclose($handle);

            return array('status' => "ok", 'code' => 0, 'statusMsg' => "", 'data' => "");
        }

        function updateMemberSalesData($clientID, $type, $bonusValue){
            $db = MysqliDb::getInstance();

            if(!$clientID){
                return false;
            }

            if(!$type){
                return false;
            }

            if($bonusValue <= 0){
                return false;
            }
            $db->where("name", "ownSales");
            $db->where("client_id",$clientID);
            $bonusValueID = $db->getValue("client_setting","id");
            if(!$bonusValueID){
                $insertData = array(    
                                        "client_id" => $clientID,
                                        "name" => "ownSales",
                                        "value" => $bonusValue,
                                    );
                $db->insert("client_setting", $insertData);
            }else{
                $db->where("name", "ownSales");
                $db->where("client_id",$clientID);
                switch ($type) {
                    case 'terminated':
                        $db->update("client_setting",array("value" => $db->dec($bonusValue)));
                        break;
                    
                    default:
                        $db->update("client_setting",array("value" => $db->inc($bonusValue)));
                        break;
                }
            }

            unset($insertData);

            $uplineIDAry = Tree::getSponsorUplineByClientID($clientID);
            foreach ($uplineIDAry as $uplineID) {
                unset($groupSalesID);

                $db->where("name", "groupSales");
                $db->where("client_id",$uplineID);
                $groupSalesID = $db->getValue("client_setting","id");

                if(!$groupSalesID){
                    $insertData = array(
                                            "client_id" => $uplineID,
                                            "name" => "groupSales",
                                            "value" => $bonusValue,
                                        );
                    $db->insert("client_setting", $insertData);

                }else{
                    $db->where("name", "groupSales");
                    $db->where("client_id",$uplineID);
                    switch ($type) {
                        case 'terminated':
                            $db->update("client_setting",array("value" => $db->dec($bonusValue)));
                            break;
                        
                        default:
                            $db->update("client_setting",array("value" => $db->inc($bonusValue)));
                            break;
                    }
                }
            }

            return true;
        }

        public function getReentryData($params, $type){
            $db = MysqliDb::getInstance();
            $language     = General::$currentLanguage;
            $translations = General::$translations;

            $site = $db->userType;
            $clientID = $params["clientID"];
            $registerType = $params["registerType"] ? $params["registerType"] : "Package Reentry";
            if(!$clientID){
                $clientID = $db->userID;
            }

            if($site == "Admin"){
                $clientID = $params["clientID"];
            }

            if(!$clientID){
                return array('status' => "error", 'code' => 2, 'statusMsg' => 'Invalid Member.', 'data' => "");
            }

            if($type == "upgrade" && !$params['portfolioID']) return array('status' => "error", 'code' => 2, 'statusMsg' => 'Invalid portfolioID.', 'data' => "");

            $db->where("id",$clientID);
            $clientRow = $db->getOne("client", "id, username, main_id, sponsor_id");
            $mainID = $clientRow["main_id"];
            $sponsorID = $clientRow["sponsor_id"];
            if(!$sponsorID){
                $db->where("id",$mainID);
                $clientRow = $db->getOne("client", "id, username, main_id, sponsor_id");
                $sponsorID = $clientRow["sponsor_id"];
            }
            
            $db->where("id",$sponsorID);
            $sponsorRow = $db->getOne("client","id, username");

            $dataOut["sponsorUsername"] = $sponsorRow["username"];

            $highestProductID = 0;
            if($type == "upgrade"){
            	$db->where("id",$params['portfolioID']);
            }else{
            	$db->where("client_id", $clientID);
            	$db->orderBy("id","DESC");
            }
            
            $res = $db->getOne("mlm_client_portfolio", "product_id,product_price");
            $highestProductID = $res["product_id"];
            $currPrice = $res["product_price"];

            if($type == "upgrade" && !$highestProductID) return array('status' => "error", 'code' => 2, 'statusMsg' => 'Invalid portfolio.', 'data' => "");

            $highestPriority = 0;
            if($highestProductID){
                $db->where("id", $highestProductID);
                $highestPriority = $db->getValue("mlm_product", "priority");
            }
            $dataOut["highestPriority"] = $highestPriority;
            $productReturn = Product::getProductList("", "package");
            $productData = $productReturn["data"];
            foreach ($productData as $productID => $productRow) {
                unset($validClientAry);

                $productRow["bonusValue"] = $productRow["bonusValue"]["value"];

                if($productRow["priority"] <= $highestPriority){
                    $productRow["isDisabled"] = 1;
                }
                if($type == "upgrade"){
                	$productRow["price"] -= $currPrice;
                }
                $price = $productRow["price"];
                $paymentSetting = Cash::getPaymentDetail($clientID, $registerType, $price, $productID, "");
                $productRow["paymentMethod"] = $paymentSetting['data']["paymentData"];
                $validProductList[] = $productRow;
            }
            $dataOut["pairingAmount"] = $pairingAmount;
            $dataOut["productList"] = $validProductList;
            return array('status' => "ok", 'code' => 0, 'statusMsg' => "", 'data' => $dataOut);
        }

        public function updateSponsorGroupSales($clientID,$type,$downlineIDArray = array()) {
            $db = MysqliDb::getInstance();

            if (!$clientID)
                return array('status'=>'error','code'=>'1','statusMsg'=>'Invalid user failed to update sponsor group sales.','data'=>'');

            $typeArray = array('increase','decrease');
            if (!$type || !in_array($type,$typeArray))
                return array('status'=>'error','code'=>'1','statusMsg'=>'Invalid update type failed to update sponsor group sales.','data'=>'');

            $downlineIDArray[$clientID] = $clientID;
            $db->where('client_id',$downlineIDArray,'IN');
            // $db->where('status','Active');
            $totalBV = $db->getValue('mlm_client_portfolio','SUM(bonus_value)');

            $uplineIDArray = Tree::getSponsorUplineByClientID($clientID,false);
            
            if ($totalBV > 0) {
                foreach ($uplineIDArray as $uplineID) {
                    $db->where('client_id',$uplineID);
                    $db->where("name", "groupSales");
                    $hasRecord = $db->getValue('client_setting','COUNT(*)');

                    if ($hasRecord) {
                        $db->where('client_id',$uplineID);
                        $db->where("name", "groupSales");
                        if ($type == 'increase')
                            $db->update("client_setting",array("value" => $db->inc($totalBV)));
                        elseif ($type == 'decrease')
                            $db->update("client_setting",array("value" => $db->dec($totalBV)));
                    } else {
                        if ($type == 'increase') {
                            $insertData = array(
                                'name' => 'groupSales',
                                'value' => $db->inc($totalBV),
                                'client_id' => $uplineID,
                            );
                            $db->insert('client_setting',$insertData);
                        }
                    }
                }
            }

            return array('status'=>'ok','code'=>'1','statusMsg'=>'','data'=>'');
        }

        public function downlineRegistrationSendNotice($clientID, $sponsorID){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $memberSite = Setting::$configArray['memberSite'];
            $companyInfo = Setting::$systemSetting['companyInfo'];

            $socialDetail = json_decode($companyInfo, true);

            $db->where('id', $clientID);
            $sendDetails = $db->getOne('client',null,'member_id, name');

            $newMemberID = $sendDetails['member_id'];
            $newMemberName = $sendDetails['name'];

            $db->where('id',$sponsorID);
            $senderEmail = $db->getOne('client','email');

            $recipient = $senderEmail['email'];;//recipient is email destination
            $sendType = 'email';

            if($sendDetails){
                $subject = $translations['B00486'][$language]; //Rekrutmen Tim
                $content = '
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>'.$subject.'</title>
                        <style>
                            .loginBlock {
                                display: block;
                                width: 600px;
                                padding: 3rem 3rem;
                                max-width: 100%;
                                background-color: #f4f7fa;
                                background-size: cover;
                                background-repeat: no-repeat;
                                background-position: right 15%;
                                color: #141414;
                                font-family: Arial, Helvetica, sans-serif;
                            }

                             img.companyLogo {
                                 display: block;
                                 margin: 0 auto;
                             }

                            .companyMsgBox {
                                background-color: #fff;
                                border-radius: 8px;
                                margin-top: 2rem;
                                padding: 2rem;
                                box-shadow: 0 0 20px -10px #ccc;
                            }

                            .companyEmailIcon {
                                display: block;
                                margin: 1.5rem auto;
                            }

                            .longLine {
                                display: block;
                                width: 100%;
                                height: 2px;
                                background-color: #e7e7e7;
                                clear: both;
                                margin: 2rem auto;
                            }

                            .companyTxt1 {
                                font-size: 18px;
                                color: #48545c;
                                text-align: center;
                            }

                            .companyTxt2 {
                                font-size: 17px;
                            }

                            .companyTxt3 {
                                font-size: 14px;
                                padding: 0 1rem;
                                margin: 20px 0 15px 0;
                            }

                            .companyTxt4 {
                                font-size: 23px;
                                font-weight: 600;
                                padding: 0 1rem;
                                margin: 20px 0 15px 0;
                            }

                            a.companyLinkBtn {
                                display: block;
                                width: 100%;
                                background-color: #29abe2;
                                color: #fff;
                                text-decoration: none;
                                padding: 8px;
                                border-radius: 4px;
                                text-transform: uppercase;
                            }

                            a.companyLinkBtn:hover {
                                text-decoration: underline;
                            }

                            .shortLine {
                                display: block;
                                width: 40px;
                                height: 2px;
                                background-color: #e7e7e7;
                                clear: both;
                                margin: 1.5rem auto;
                            }

                            .companySmallTxt {
                                font-size: 12px;
                                font-style: italic;
                                color: #929191;
                                text-align: center;
                            }
                        </style>
                    </head>
                    <body>
                    ';

                    $content .= '
                        <div class="loginBlock">
                            <div class="companyMsgBox">
                                <img class="companyEmailIcon" src="'.$memberSite.'/images/project/companyLogo2.png" width="70px" alt="">
                                <h3 class="companyTxt1">'.$translations['B00486'][$language].'</h3> 
                                <div class="longLine"></div>
                                <p class="companyTxt3">'.$translations['B00487'][$language].' '.$newMemberID.' - '.$newMemberName.'</p>
                                <p class="companyTxt3">'.$translations['B00488'][$language].'</p>
                                <p class="companyTxt3">'.$translations['B00474'][$language].'</p>
                                <div class="shortLine"></div>
                                <p class="companySmallTxt">'.$translations['B00489'][$language].'</p>
                            </div>
         
                            </div>
                        </body>
                        </html>
                ';

                $result = Message::createCustomizeMessageOut($recipient,$subject,$content,$sendType,'','','','',1, $attachmentFlag);
            }
            return array("status" => "ok", "code" => 0, "statusMsg" => "Email Sent Successfully", "data" => "");
        }
    }
?>
