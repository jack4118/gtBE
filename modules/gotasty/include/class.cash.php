<?php

    /**
     * Cash Class:
     * Used for retrieving and calculating client's credit balance in the system
     */
    
    class Cash{
        
        //Commented on 15/11/2017 - removed last param
        public static $creatorID = 0;
        public static $creatorType = "System";
        public static $paymentCredit;
        public static $mainCredit;
        function __construct() {
            
            
        }
        
        public function setCreator($creatorID, $creatorType) {
            self::$creatorID = $creatorID;
            self::$creatorType = $creatorType;
        }

        public function setPaymentCredit(){
            $db = MysqliDb::getInstance();
            
            $db->orderBy('priority', 'ASC');
            $result = $db->get("credit", NULL , "name,type");
            foreach($result AS $key => $value){
                self::$paymentCredit[$value['name']][] = $value['name']; 
                if($value['name'] != $value['type']){
                    self::$paymentCredit[$value['type']][] = $value['name']; 
                    self::$mainCredit[$value['name']] = $value['type']; 
                }
            }

        }

        public function walletDisplaySetting($clientID, $displayMainWallet){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            if(!$clientID){
                return array('status' => "error", 'code' => 1, 'statusMsg' => "Client Not Found", 'data' => "Client Error");
            }

            $isPurchaseCredit  = $db->subQuery();
            $isPurchaseCredit->where('name', "isPurchaseCredit");
            $isPurchaseCredit->where('value', 1);
            $isPurchaseCredit->where('member', 1);
            $isPurchaseCredit->get('credit_setting', null, 'credit_id');
            $db->where('id',$isPurchaseCredit,'IN');
            $isPurchaseCreditAry = $db->getValue('credit','id',null);

            $isWallet  = $db->subQuery();
            $isWallet->where('name', "isWallet");
            $isWallet->where('value', 1);
            $isWallet->where('member', 1);
            $isWallet->get('credit_setting', null, 'credit_id');
            $db->where('b.id', $isWallet, 'IN');
            $db->join('credit_setting a','a.credit_id = b.id');
            $db->orderBy("b.priority", "ASC");
            $result = $db->get('credit b', null, 'b.id, b.translation_code, b.admin_translation_code, b.type, b.code, b.dcm AS `decimal`, b.rate, a.name as setting,a.value,a.member');

            if(empty($result)){
                return array('status' => "error", 'code' => 1, 'statusMsg' => "Error", 'data' => "");
            }

            $settArr = array("convertTo");
            foreach($result as $value) {
                // unset($row);
                $value[$value['setting']] = $value['member'];
                if(in_array($value["setting"], $settArr)) $value[$value['setting']] = $value['value'];

                if($walletList[$value['type']]){
                    $walletList[$value['type']][$value['setting']] = $value['member'];
                    if(in_array($value["setting"], $settArr)) $walletList[$value['type']][$value['setting']] = $value['value'];
                } else {
                    $walletList[$value['type']] = $value;
                }

                $walletList[$value['type']]["creditDisplay"] = $translations[$value["translation_code"]][$language];
                $walletList[$value['type']]["isPurchaseCredit"] = in_array($value['id'],$isPurchaseCreditAry) ? 1 : 0;
            }

            foreach ($walletList as $index => $row) {
                if($row['isDisplayOnTransaction'] == "1")
                {
                    $db->where('client_id', $clientID);
                    $db->where('type', $row['type']);
                    $transaction = $db->get('credit_transaction', NULL, 'to_id');
                    if(empty($transaction)){
                        unset($walletList[$row["type"]]);
                    }
                }
            }

            return $walletList;
        }

        // isSpecial - for hot deal fresh deal
        //7,1010400, bonusDef, 500, Adjustment In, 1010609, batchID = 1010610, 1010400, remarks
        public function insertTAccount($accountID, $receiverID, $creditType, $amount, $subject, $belongID, $referenceID, $transactionDate, $batchID, $clientID, $remark="",$portfolioID=0, $data , $transactionID,$rate, $isSpecial, $paymentCredit) {
            $db = MysqliDb::getInstance();

            $isMember = $creditType;
            if($isMember == 'memberDef')
            {
                $isMember = '1';
                $creditType = 'bonusDef';
            }

            // Check for negative amount
            if ($amount < 0) {
                return false;
            }

            if(!trim($transactionDate)) {
                $transactionDate = date("Y-m-d H:i:s");
            }
            $tblDate = date('Ymd', strtotime($transactionDate));

            $db->where("id", $accountID);
            $mainIDRow = $db->getOne("client", "type, main_id");
            if($mainIDRow["type"] == "Client" && $mainIDRow["main_id"] > 0){
                $accountID = $mainIDRow["main_id"];
            }

            unset($mainIDRow);
            $db->where("id", $receiverID);
            $mainIDRow = $db->getOne("client", "type, main_id");
            if($mainIDRow["type"] == "Client" && $mainIDRow["main_id"] > 0){
                $receiverID = $mainIDRow["main_id"];
            }
            
            unset($mainIDRow);
            $db->where("id", $clientID);
            $mainIDRow = $db->getOne("client", "type, main_id");
            if($mainIDRow["type"] == "Client" && $mainIDRow["main_id"] > 0){
                $clientID = $mainIDRow["main_id"];
            }

            if(!$transactionID) $transactionID = $db->getGroupID();

            if($paymentCredit){
                $paymentCreditType = $paymentCredit;
            }else{
                $credit = self::$paymentCredit; // get all credit
                $paymentCreditType = $credit[$creditType]; // if paymentCreditType is subwallet will get subwallet array else get array of single array of that credit    
            }
            
            $mainCredit = self::$mainCredit;

            if($isSpecial){
                $db->where("name", "isHotDealFreshDeal");
                $db->where("value", "1");
                $creditIDAry = $db->map("credit_id")->get("credit_setting", null, " credit_id");

                $db->where("id", $creditIDAry, "IN");
                $result = $db->get("credit", NULL , "name,type");
                foreach($result AS $key => $value){
                    $paymentCreditTypeTmp[$value['name']][] = $value['name']; 
                    if($value['name'] != $value['type']){
                        $paymentCreditTypeTmp[$value['type']][] = $value['name']; 
                    }
                }

                $paymentCreditType = $paymentCreditTypeTmp[$creditType];
            }

            $isExistSubWallet = 0;
            $subWalletCount = 0;
            $totalSubWallet = count($paymentCreditType);
            if($totalSubWallet > 1) {
                $isExistSubWallet = 1;
            }

            $db->where("name", $paymentCreditType, "IN");
            $creditIDAry = $db->getValue("credit","id", null);
            if($creditIDAry){
                $db->where("credit_id", $creditIDAry, "IN");
                $db->where("name","allowNegativeBalance");
                $db->where("value",1);
                $allowNegativeBalance = $db->getValue("credit_setting", "value");
            }

            if($allowNegativeBalance){
                $shiftedType = array_shift($paymentCreditType);
                $paymentCreditType[] = $shiftedType;
            }

            foreach($paymentCreditType AS $type){
                if($amount <= 0) continue; //if amount less than 0 continue
                
                $subWalletCount++;

                if($accountID > 50){
                    $creditBalance = Self::getBalance($accountID,$type,"",false); // get balance for checking 

                    if($creditBalance < $amount && $isExistSubWallet && ($subWalletCount != $totalSubWallet)) {
                        $calculatedAmount = $creditBalance;
                    }else{
                        $calculatedAmount = $amount;
                    }

                    /*
                    if($creditBalance <= 0) continue; //if no balance continue 
                    $balanceCheck = $amount - $creditBalance; // check balance 
                    $calculatedAmount = ($balanceCheck > 0 ? $creditBalance : $amount); //if got balance use creditBalance else amount
                    */
                }else{
                    $calculatedAmount = $amount;
                }

                $calculatedAmount = Setting::setDecimal($calculatedAmount);
                if($calculatedAmount <= 0) continue; //if no balance continue 

                // $accountID - From
                // $receiverID - To
                // $type - name of currency
                // $amount
                // $subject - transaction subject
                // $belongID - to link to another account besides the credit and debit ID
                // $referenceID - additional ID to keep track when needed.
                // $transactionDate - enter in this format --> date("Y-m-d H:i:s")
                // $batchID - an ID when perform a task so that we can remove or edit in a batch when needed
                // $remark - Remark for credit_transaction
                
                $db->where("type",$type);
                $db->where("name","allowNegativeBalance");
                $allowNegativeBalanceFlag = $db->getValue("credit_setting", "value");

                $result = $db->rawQuery("CREATE TABLE IF NOT EXISTS acc_credit_".$db->escape($tblDate)." LIKE acc_credit");
                
                
                // $decimalPlaces = Setting::getSystemDecimalPlaces();
                
                // Format amount to according to decimal places
                // $calculatedAmount = number_format($calculatedAmount, $decimalPlaces, ".", "");
                // Check whether accountID is an internal account, and what type of account it is
                // Expenses (Allow negative balance)
                // Suspense (Intermediate accounts)
                // Earnings (Always positive balance)
                $db->where('id', $accountID);
                $accountData = $db->getOne("client", "type, description");
                
                // Generate debit/credit ID
                // $debitID = $db->getNewID();
                // $creditID = $db->getNewID();
                
                // Get balance from acc_closing & acc_credit_%
                $accountBalance = Self::getBalance($accountID, $type, "", false);
                
                if($allowNegativeBalanceFlag)
                {
                    $accountBalance = bcsub((string)$accountBalance,(string)$calculatedAmount,"8"); // Debit - minus
                }else{
                    if ($accountData['type'] == "Internal" && in_array($accountData['description'], array("Expenses"))) {
                        // Do nothing here
                    }
                    else {
                        // Check if balance is negative after deducting amount
                        $accountBalance = bcsub((string)$accountBalance,(string)$calculatedAmount,"8"); // Debit - minus
                        if($accountBalance < 0) {
                            return false;
                        }    
                    }
                }
                
                // Set fields for acc_credit table
                $arrayData = array( 
                                    "subject"      => $subject,
                                    "type"         => $type,
                                    "account_id"   => $accountID,
                                    "receiver_id"  => $receiverID,
                                    "credit"       =>  0,
                                    "debit"        => $calculatedAmount,
                                    "balance"      => $accountBalance,
                                    "belong_id"    => $belongID,
                                    "reference_id" => $referenceID,
                                    "batch_id"     => $batchID,
                                    "deleted"      => 0,
                                    "created_at"   => $transactionDate,
                                    "portfolio_id" => $portfolioID,
                                );
                $debitRes = $db->insert("acc_credit_".$tblDate, $arrayData);
                if(!$debitRes)
                    return false;
                
                if ($accountData['type'] == "Client") {
                    // 2nd checking on balance > 0 after insert debit, pass the flag as false so that it won't update the cache balance first
                    $accountBalance = Self::getBalance($accountID, $type, "", false);
                    
                    // Check if balance is negative after deducting amount
                    if($accountBalance < 0 && !$allowNegativeBalanceFlag) {
                        $data2 = array('deleted' => 1);
                        $db->where('id', $debitID);
                        $result = $db->update('acc_credit_'.$tblDate, $data2);
                        return false; // Stop here after updating the debit row in acc_credit
                    }else{
                        // Update cache balance
                        Self::updateClientCacheBalance($accountID,$type,$accountBalance);
                    }
                }

                // Get latest balance and update cache balance
                $receiverBalance = Self::getBalance($receiverID, $type, "", false);

                $receiverBalance = bcadd((string)$receiverBalance,(string)$calculatedAmount,"8"); // Credit - plus
               
                // 1st checking on balance > 0 before insert credit
                //$receiverBalance = Self::getBalance($receiverID, $type);
                //$receiverBalance = $db->escape($receiverBalance);
                //$receiverBalance += $amount; // Credit - plus
                //if($receiverBalance < 0)
                //    return false;
                $arrayData = array( 
                                    "subject"      => $subject,
                                    "type"         => $type,
                                    "account_id"   => $receiverID,
                                    "receiver_id"  => $accountID,
                                    "credit"       => $calculatedAmount,
                                    "debit"        => 0,
                                    "balance"      => $receiverBalance,
                                    "belong_id"    => $belongID,
                                    "reference_id" => $referenceID,
                                    "batch_id"     => $batchID,
                                    "deleted"      => 0,
                                    "created_at"   => $transactionDate,
                                    "portfolio_id" => $portfolioID,
                                );
                $creditRes = $db->insert("acc_credit_".$tblDate, $arrayData);
                if(!$creditRes)
                    return false;

                // Update cache balance
                Self::updateClientCacheBalance($receiverID,$type,$receiverBalance);

                if(!empty($isMember))
                {
                    $creditTransactionRes = Self::insertCreditTransaction($accountID, $receiverID, 'memberDef', $calculatedAmount, $subject, $belongID, $referenceID, $transactionDate, $batchID, $clientID, $remark,$portfolioID, $data, $transactionID,$rate);
                }
                else
                {
                    $creditTransactionRes = Self::insertCreditTransaction($accountID, $receiverID, $type, $calculatedAmount, $subject, $belongID, $referenceID, $transactionDate, $batchID, $clientID, $remark,$portfolioID, $data, $transactionID,$rate);
                }
                if(!$creditTransactionRes)
                    return false;

                // link transaction & acc_credit
                unset($updateAry);
                $updateAry = array('transaction_id' => $creditTransactionRes);
                $db->where('id', array($creditRes, $debitRes), 'IN');
                $db->update('acc_credit_'.$tblDate, $updateAry);

                $amount = bcsub((string)$amount, (string)$calculatedAmount,"8");
            }

            // Convert Sub Credit to Main Credit
            if($mainCredit[$creditType]) $creditType = $mainCredit[$creditType];

            // Update main credit balance
            if($accountID > 50) Self::getBalance($accountID, $creditType);
            else Self::getBalance($receiverID, $creditType);

            return true;
        }

        public function getBalance($clientID, $type, $date, $updateCache=true, $portfolioID=0) {
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getSystemDecimalPlaces();
            
            $creditArr = self::$paymentCredit;
            $creditType = $creditArr[$type];

            $db->where("id",$clientID);
            $db->where("type","Client");
            $mainID = $db->getValue("client","main_id");
            if($mainID > 0){
                $clientID = $mainID;
            }

            $realDate = $date;

            if(!strtotime($date)){
                $db->orderBy("created_at", "DESC");
                $date = $db->getValue("credit_transaction", "created_at");
                $realDate = "";
            }

            // Handle for no data in credit transaction
            if(!$date){
                return 0;
            }

            $balance = 0;
            $latestDate = "";
            $tsCondition = strtotime($date);

            $db->where("name","isAccClosingPortfolio");
            $db->where("value",1);
            $isAccClosingPortfolio = $db->getValue("credit_setting","type",null);

            // Get the latest acc closing date
            $db->where("completed","1");
            $db->orderBy("id","DESC");
            $latestAccClosingDate = $db->getValue("acc_closing_batch","closing_date");

            //Get latest closing date from client_setting
            $db->where("client_id",$clientID);
            $db->where("name",$creditType,"IN");
            $db->orderBy("name","ASC");
            $clientStgRes = $db->map("name")->get("client_setting",null,"name,reference");

            $checkSubWallet = 0;

            foreach($clientStgRes as $credit => $closingDate){
                $db->where("client_id",$clientID);
                $db->where("type",$credit);
                $db->orderBy("id","DESC");
                if($closingDate){
                    if($realDate){
                        $db->where("date",date("Y-m-d",strtotime($realDate)),"<");
                    }else{
                        $db->where("date",$closingDate,"<=");
                    }
                    if($portfolioID){
                        $db->where("portfolio_id", $portfolioID);
                    }
                    if(in_array($credit,$isAccClosingPortfolio) && !$portfolioID){
                        // Special handle for closing by portfolio id
                        $db->groupBy("portfolio_id");
                        $accClosingResult = $db->get("acc_closing",null,"MAX(id) as id,portfolio_id");
                        unset($accClosingMaxIDAry);
                        foreach($accClosingResult as $accClosingRow){
                            $accClosingMaxIDAry[$accClosingRow["id"]] = $accClosingRow["id"];
                        }
                        if($accClosingMaxIDAry){
                            $db->where("id",$accClosingMaxIDAry,"IN");
                            $accClosingResult2 = $db->get("acc_closing",null,"MAX(date) as date,SUM(balance) as balance");
                            unset($accClosingRes);
                            foreach($accClosingResult2 as $accClosingRow2){
                                $accClosingRes["date"] = $accClosingRow2["date"];
                                $accClosingRes["balance"] = $accClosingRow2["balance"];
                            }
                        }
                    }else{
                        $accClosingRes = $db->getOne("acc_closing","date,balance");
                    }
                    $balance += $accClosingRes["balance"];
                    if((strtotime($accClosingRes["date"]) > strtotime($realClosingDate))){
                        $checkSubWallet = 1;
                        $realClosingDate = $accClosingRes["date"];
                        $latestDate = date("Y-m-d",strtotime("+1 days ".$accClosingRes["date"]));
                    }
                }else{
                    if($realDate){
                        $db->where("date",date("Y-m-d",strtotime($realDate)),"<");
                    }else{
                        $db->where("date",$latestAccClosingDate,"<");
                    }
                    if($portfolioID){
                        $db->where("portfolio_id",$portfolioID);
                    }
                    $copyDB = $db->copy();
                    $balanceCount = $db->getValue("acc_closing","count(*)");
                    if($balanceCount > 0){
                        $balance += $copyDB->getValue("acc_closing","balance");
                    }else{
                        if(!$checkSubWallet){
                            $db->where("client_id",$clientID);
                            $db->where("type",$credit);
                            $db->orderBy("created_at","ASC");
                            $creditFirstDate = $db->getValue("credit_transaction","created_at");
                            if($creditFirstDate){
                                if(!$latestDate){
                                    $latestDate = $creditFirstDate;
                                }else{
                                    if((strtotime($creditFirstDate) <= strtotime($latestDate))){
                                        $latestDate = $creditFirstDate;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            unset($checkLatestDate);
            $checkLatestDate = date("Y-m-d",strtotime($date));
            if((strtotime($checkLatestDate) == strtotime($realClosingDate))){
                $balance = number_format($balance,$decimalPlaces,".","");
            }else{
                if(!$latestDate && !$realDate){
                    $latestDate = $latestAccClosingDate;
                }elseif(!$latestDate){
                    $latestDate = $realDate;
                }

                $tsLatest = strtotime($latestDate);
                $totalCredit = 0;
                $totalDebit = 0;
                
                $db->where("id",$clientID);
                $registerDate = $db->getValue("client","DATE_FORMAT(created_at,'%Y-%m-%d')");

                /*if($portfolioID){
                    $latestDate = $registerDate;
                }*/

                if(strtotime($latestDate) < strtotime($registerDate)){
                    $latestDate = $registerDate;
                }

                if(strtotime($latestDate) > strtotime($date)){
                    $loopDate = $date;
                    $endDate = $latestDate;
                }else{
                    $loopDate = $latestDate;
                    $endDate = $date;
                }

                // Get all acc_credit_% tables
                while(strtotime($loopDate) <= strtotime($endDate)){
                    $dateCredit = date("Ymd",strtotime($loopDate));

                    $db->where("account_id",$clientID);
                    $db->where("type",$creditType,"IN");
                    $db->where("deleted",0);
                    // if($tsCondition){
                    //     $db->where("created_at", date("Y-m-d H:i:s", $tsCondition), "<=");
                    // }

                    if($portfolioID){
                        $db->where("portfolio_id", $portfolioID);
                    }
                    try
                    {
                        $creditRes = $db->getOne("acc_credit_".$dateCredit, "SUM(credit) AS credit, SUM(debit) AS debit");
                    }
                    catch(Exception $e)
                    {
                        // means no table exist, which is no balance
                        $creditRes['credit'] = 0;
                        $creditRes['debit'] = 0;
                    }

                    $totalCredit += $creditRes["credit"];
                    $totalDebit += $creditRes["debit"];

                    $loopDate = date("Y-m-d", strtotime("+1 days ".$loopDate));

                }

                $balance = number_format(($balance + $totalCredit - $totalDebit), $decimalPlaces, ".", "");
            }
            
            if($updateCache && !$realDate) {
                // Update cache balance for the clientID
                self::updateClientCacheBalance($clientID, $type, $balance);
            }
            //return array("code" => 0, "status" => "ok", "statusMsg" => '' , 'data' => $balance);
             return $balance;
        }

        // Get balance from acc_closing & acc_credit_%
        public function getBalanceOld($clientID, $type, $date='', $updateCache=true,$portfolioID=0) {
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getSystemDecimalPlaces();
                
            if(is_array($type)){
                $creditType = $type;
            }
            else{
                $creditArr = self::$paymentCredit;
                $creditType = $creditArr[$type];
            }

            $db->where("id", $clientID);
            $db->where("type","Client");
            $mainID = $db->getValue("client", "main_id");
            if($mainID > 0){
                $clientID = $mainID;
            }

            $db->where('client_id', $clientID);
            $db->where('type', $creditType , "IN");
            
            if ($date) {
                // If date is passed in as argument, we only want to select the range up till the given date
                $db->where('date', $date, '<=');
                $tsCondition = strtotime($date);
            }
            $count = $db->getValue('acc_closing', 'count(id)');
            
            // 0 means no rows exist in the acc_closing for this client
            if($count == 0) {
                $balance = 0;
                $latestDate = '';
            }
            else {
                
                // Get the latest acc_closing date for this client
                if($portfolioID) $db->where('portfolio_id',$portfolioID);

                $db->where('client_id', $clientID);
                $db->where('type', $creditType , "IN");

                $db->groupBy("date");
                // $accClosingResults = $db->getOne('acc_closing', null, 'balance, date');
                $accClosingResults = $db->getOne('acc_closing','SUM(balance) AS balance, date');

                $latestDate = $accClosingResults["date"];
                $balance = $accClosingResults["balance"];
                
            }
            
            if($date && $latestDate >= $date){
                $balance = 0;
                $latestDate = '';
            }

            $tsLatest = strtotime($latestDate);
            $totalCredit = 0;
            $totalDebit = 0;
            
            $db->where("id", $clientID);
            $registerDate = $db->getValue('client', "DATE_FORMAT(created_at, '%Y-%m-%d')");

            // Get all acc_credit_% tables
            $result = $db->rawQuery('SHOW TABLES LIKE "acc_credit_%"');
            foreach ($result as $array) {
                foreach ($array as $key=>$val) {
                    $val = explode('_', $val);
                    $dateCredit = $val[2];
                    $tsCredit = strtotime($dateCredit);
                    if($tsCredit < strtotime($registerDate)) continue;
                    // Compare the date with the latest acc_closing date
                    // For eg. there exist tables
                    // acc_credit_20170801, acc_credit_20170802, acc_credit_20170803, acc_credit_20170804
                    // Condition 1: If acc_closing on 20170802,
                    // This 'if' part will sum up acc_credit_20170803 and acc_credit_20170804 debit & credit
                    // Condition 2: If acc_closing on 20170804,
                    // This 'if' part won't run
                    if($tsCredit > $tsLatest) {
                        if ($tsCondition && $tsCredit > $tsCondition) {
                            // If it exceeds the time of the date argument, breka from the loop
                            break;
                        }
                        if($portfolioID) $db->where('portfolio_id',$portfolioID);

                        $db->where('account_id', $clientID);
                        if($tsCondition) $db->where('created_at', date("Y-m-d H:i:s", $tsCondition), '<=');
                        $db->where('type', $creditType , "IN");

                        $db->where('deleted', 0);
                        $creditRes = $db->getOne('acc_credit_'.$dateCredit, 'SUM(credit) AS credit, SUM(debit) AS debit');
                        $totalCredit += $creditRes['credit'];
                        $totalDebit += $creditRes['debit'];
                    }
                }
            }

            // this part to fix -0.00 error
            // $totalCredit = number_format($totalCredit, $decimalPlaces, '.', '');
            // $totalDebit = number_format($totalDebit, $decimalPlaces, '.', '');
            
            $balance = number_format(($balance + $totalCredit - $totalDebit), $decimalPlaces, '.', '');
   
            if ($updateCache && !$date) {
                // Update cache balance for the clientID
                self::updateClientCacheBalance($clientID, $type, $balance);
            }
            
            return $balance;
        }
        
        // Get balance from acc_closing & acc_credit_%
        public function getAllClientBalanceOld($clientIDAry, $typeAry, $date, $groupByPortfolio) {
            $db = MysqliDb::getInstance();
            
            $decimalPlaces = Setting::getSystemDecimalPlaces();
            $totalCredit = $totalDebit =0;

            if($date) $tsCondition = strtotime($date);
            else $tsCondition = TIME();

            // Get all acc_credit_% tables
            $result = $db->rawQuery('SHOW TABLES LIKE "acc_credit_%"');
            foreach ($result as $array) {
                foreach ($array as $key=>$val) {
                    $val = explode('_', $val);
                    $dateCredit = $val[2];
                    $tsCredit = strtotime($dateCredit);

                    if ($tsCondition && $tsCredit > $tsCondition) {
                        // If it exceeds the time of the date argument, break from the loop
                        break;
                    }

                    if($clientIDAry) $db->where('account_id', $clientIDAry, "IN");
                    else $db->where('account_id', 50, ">");
                    if($tsCondition) $db->where('created_at', date("Y-m-d H:i:s", $tsCondition), '<=');
                    if($typeAry) $db->where('type', $typeAry, "IN");
                    $db->where('deleted', 0);
                    $db->groupBy('account_id');
                    $db->groupBy('type');
                    if($groupByPortfolio) $db->groupBy("portfolio_id");
                    $balanceRes = $db->get('acc_credit_'.$dateCredit, null,'SUM(credit - debit) AS balance, account_id, type, portfolio_id');
                    foreach($balanceRes AS $balanceRow){
                        if($balanceRow['type'] == 'maxCap' && $groupByPortfolio){
                            $clientBalanceAry[$balanceRow['account_id']][$balanceRow['type']][$balanceRow['portfolio_id']] = bcadd($clientBalanceAry[$balanceRow['account_id']][$balanceRow['type']][$balanceRow['portfolio_id']], $balanceRow['balance'], $decimalPlaces); 
                        }else{
                            $clientBalanceAry[$balanceRow['account_id']][$balanceRow['type']] = bcadd($clientBalanceAry[$balanceRow['account_id']][$balanceRow['type']], $balanceRow['balance'], $decimalPlaces);   
                        }
                    }
                }
            }
            return $clientBalanceAry;
        }

        public function getAllClientBalance($clientIDAry, $typeAry, $date, $groupByPortfolio) {
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getSystemDecimalPlaces();

            if(!$clientIDAry){
                $db->where("type","Client");
                $db->where("main_id","0");
                $clientIDAry = $db->getValue("client","id",null);
            }

            if(!strtotime($date)){
                $db->orderBy("created_at","DESC");
                $date = $db->getValue("credit_transaction","created_at");
                $tsCondition = strtotime($date);
            }else{
                $tsCondition = strtotime($date);
            }

            $db->where("completed","1");
            $db->orderBy("id","DESC");
            $latestAccClosingDate = $db->getValue("acc_closing_batch","closing_date");

            if($latestAccClosingDate){
                if($clientIDAry){
                    $db->where("client_id",$clientIDAry,"IN");
                }
                if($typeAry){
                    $db->where("type",$typeAry,"IN");
                }
                if($tsCondition){
                    $db->where("date",date("Y-m-d",$tsCondition),"<");
                }
                $db->where("date",$latestAccClosingDate,"<=");
                $db->groupBy("client_id");
                $db->groupBy("date");
                $db->groupBy("type");
                $db->orderBy("date","ASC");
                $accClosingResults = $db->get("acc_closing",null,"client_id,type,SUM(balance) as balance,date");
                foreach($accClosingResults as $accClosingRow){
                    $clientBalanceAry[$accClosingRow["client_id"]][$accClosingRow["type"]] = $accClosingRow["balance"];
                }
                $latestDate = date("Y-m-d", strtotime("+1 days ".$latestAccClosingDate));
                $tsLatest = strtotime($latestDate);
            }
            
            if(!$latestDate){
                $db->orderBy("created_at","ASC");
                $latestDate = $db->getValue("credit_transaction","created_at");
            }
            
            if(strtotime($latestDate) > strtotime($date)){
                $loopDate = $date;
                $endDate = $latestDate;
            }else{
                $loopDate = $latestDate;
                $endDate = $date;
            }

            while(strtotime($loopDate) <= strtotime($endDate)){
                $dateCredit = date("Ymd",strtotime($loopDate));

                if($clientIDAry){
                    $db->where("account_id",$clientIDAry,"IN");
                }
                if($typeAry){
                    $db->where("type",$typeAry,"IN");
                }
                if($tsCondition){
                    $db->where("created_at",date("Y-m-d H:i:s",$tsCondition),"<=");
                }
                $db->where("deleted",0);
                $db->groupBy("account_id");
                $db->groupBy("type");
                if($groupByPortfolio){
                    $db->groupBy("portfolio_id");
                }
                $balanceRes = $db->get("acc_credit_".$dateCredit,null,"SUM(credit - debit) AS balance,account_id,type,portfolio_id");
                foreach($balanceRes AS $balanceRow){
                    if(($balanceRow["type"] == "maxCap" && $groupByPortfolio) || ($balanceRow["type"] == "capitalDef" && $groupByPortfolio)){
                        $clientBalanceAry[$balanceRow["account_id"]][$balanceRow["type"]][$balanceRow["portfolio_id"]] = bcadd($clientBalanceAry[$balanceRow["account_id"]][$balanceRow["type"]][$balanceRow["portfolio_id"]],$balanceRow["balance"],$decimalPlaces); 
                    }else{
                        $clientBalanceAry[$balanceRow["account_id"]][$balanceRow["type"]] = bcadd($clientBalanceAry[$balanceRow["account_id"]][$balanceRow["type"]],$balanceRow["balance"],$decimalPlaces);   
                    }
                }

                $loopDate = date("Y-m-d",strtotime("+1 days ".$loopDate));
            }

            return $clientBalanceAry;
        }

        public function getClientCacheBalance($clientID, $creditType) {
            $db = MysqliDb::getInstance();
            
            $decimalPlaces = Setting::getSystemDecimalPlaces();
            
            $db->where('client_id', $clientID);
            $db->where('name', $creditType);
            $db->where('type', 'Credit Balance');
            $result = $db->getOne('client_setting', 'value');
            
            return $result['value']? number_format($result['value'], $decimalPlaces, '.', '') : 0;
        }
        
        public function updateClientCacheBalance($clientID, $creditType, $balance) {
            $db = MysqliDb::getInstance();
            
            $db->where('client_id', $clientID);
            $db->where('name', $creditType);
            $db->where('type', 'Credit Balance');
            $count = $db->getValue("client_setting", "count(client_id)");
            
            if ($count == 0) {
                // Insert new record
                $fields = array('name', 'value', 'type', 'reference', 'client_id');
                $values = array($creditType, $balance, 'Credit Balance', '', $clientID);
                //$values = array($rowID, $creditType, $balance, 'Credit Balance', '', $clientID);
                $arrayData = array_combine($fields, $values);
                $db->insert("client_setting", $arrayData);
            }
            else {
                $data = array('value' => $balance);
                $db->where('client_id', $clientID);
                $db->where('name', $creditType);
                $db->where('type', 'Credit Balance');
                $db->update("client_setting", $data);
            }
        }
        
        /**
         * Accountings closing function
         * Used for calculating the total day's balance for each client and carry forward to the next date
         */
        public function closing($closingDate) {
            $db = MysqliDb::getInstance();
            // Message:: = Self::message;
            
            Log::write(date("Y-m-d H:i:s")." Deleting closing date $closingDate onwards.\n");
            
            if (Self::deleteClosing($closingDate)) {
                Log::write(date("Y-m-d H:i:s")." Successfully deleted closing from $closingDate onwards.\n");
            }
            
            // Convert to timestamp for comparison
            $closingTimestamp = strtotime($closingDate);
            
            // Select all client accounts and internal accounts
            $clientFields = array('id', 'username', 'DATE(created_at) AS created_at', 'description');
            $clientRes = $db->get('client', null, $clientFields);
            
            foreach ($clientRes as $clientRow) {
                if ($clientRow["description"] == "Expenses") {
                    // Expenses accounts means they will always be negative balance
                    $expensesArray[] = $clientRow["id"];
                }
                $clientArray[] = $clientRow;
            }
            unset($clientRes);
            //print_r($clientArray);
            
            // Select all existing currencies
            $creditRes = $db->get('credit', null, array('name'));
            foreach ($creditRes as $creditRow) {
                
                $creditType = $creditRow["name"];
                
                Log::write(date("Y-m-d H:i:s")." Closing $creditType now.\n");
                
                foreach ($clientArray as $clientRow) {
                    
                    $db->where('client_id', $clientRow["id"]);
                    $db->where('`type`', $creditType);
                    $db->orderBy('`date`', "DESC");
                    $accClosingResults = $db->getOne('acc_closing');
                    
                    $lastClosingDate = $accClosingResults["date"];
                    $lastBalance = $accClosingResults["balance"]? $accClosingResults["balance"] : 0;
                    
                    //echo "Last closing date from DB: $lastClosingDate [".$clientRow["id"]."]\n";
                    
                    if ($lastClosingDate) {
                        // Increment by 1 day from the last closing date
                        $lastClosingDate = date("Y-m-d", strtotime("+1 day", strtotime($lastClosingDate)));
                    }
                    else {
                        // Set to client joined date if did not perform closing previously
                        $lastClosingDate = $clientRow["created_at"];
                    }
                    
                    Log::write(date("Y-m-d H:i:s")." Last closing date for client ".$clientRow["username"]." is $lastClosingDate.\n");
                    
                    // Convert to timestamp for comparison
                    $lastClosingTimestamp = strtotime($lastClosingDate);
                    
                    while ($lastClosingTimestamp <= $closingTimestamp) {
                        
                        $lastBalance = Self::closeClientAccount($clientRow["id"], $clientRow["username"], $lastClosingDate, $lastBalance, $creditType);
                        
                        // Increment by 1 day for next iteration
                        $lastClosingDate = date("Y-m-d", strtotime("+1 day", strtotime($lastClosingDate)));
                        $lastClosingTimestamp = strtotime($lastClosingDate);
                        
                    }
                    
                    // Update client's latest cache balance for current currency
                    $balance = Self::getBalance($clientRow["id"], $creditType);
                    
                    Log::write(date("Y-m-d H:i:s")." Finish closing $creditType for ".$clientRow["username"]."[".$clientRow["id"]."]. Balance: ".$balance."\n");
                    
                }
                
                // Audit the credit type (total issued - total spending = balance on all accounts)
                $db->where('client_id', $expensesArray, 'IN');
                $db->where('type', $creditType);
                $db->where('date', $closingDate);
                $expensesBalance = $db->getValue('acc_closing', 'SUM(balance)');
                
                $db->where('client_id', $expensesArray, 'NOT IN');
                $db->where('type', $creditType);
                $db->where('date', $closingDate);
                $incomeBalance = $db->getValue('acc_closing', 'SUM(balance)');
                
                $companyBalance = $incomeBalance + $expensesBalance;
                
                Log::write(date("Y-m-d H:i:s")." Finish closing for $creditType. Total issued: $incomeBalance + Total spending: $expensesBalance = $companyBalance\n");
                
                if ($companyBalance != 0) {
                    // If company balance is less than 0, means there might be a problem
                    $notTallyArray[] = $creditType." balance is not tally. Amount: $companyBalance\n";
                }
                
            }
            
            if (count($notTallyArray) > 0) {
                $content = "Closing result on $closingDate\n\n";
                $content .= implode("\n\n", $notTallyArray);
                // 10005 => balance not tally
                Message::createMessageOut(10005, $content);
            }
        }
        
        private function closeClientAccount($clientID, $clientUsername, $closingDate, $previousBalance=0, $creditType) {
            $db = MysqliDb::getInstance();
            
            $decimalPlaces = Setting::getSystemDecimalPlaces();
            
            // Create the acc_credit daily table if not exists
            $db->rawQuery("CREATE TABLE IF NOT EXISTS acc_credit_".date("Ymd", strtotime($closingDate))." LIKE acc_credit");
            
            $db->where('account_id', $clientID);
            $db->where('type', $creditType);
            $db->where('deleted', 0);
            $accRes = $db->getOne('acc_credit_'.date("Ymd", strtotime($closingDate)), 'SUM(debit) AS debit, SUM(credit) AS credit');
            
            Log::write(date("Y-m-d H:i:s")." Last query: ".$db->getLastQuery()."\n");
            
            $credit = $accRes["credit"]? $accRes["credit"] : 0;
            $debit = $accRes["debit"]? $accRes["debit"] : 0;
            $total = number_format(($credit - $debit), $decimalPlaces, ".", "");
            $balance = number_format(($previousBalance + $total), $decimalPlaces, ".", "");
            
            Log::write(date("Y-m-d H:i:s")." PreviousBalance: $previousBalance, Debit: $debit, Credit: $credit, Total: $total, Balance: $balance\n");
            
            // Insert client's closing record into acc_closing
            $fields = array("id", "client_id", "type", "date", "total", "balance", "created_at");
            $values = array($db->getNewID(), $clientID, $creditType, $closingDate, $total, $balance, date("Y-m-d H:i:s"));
            $arrayData = array_combine($fields, $values);
            $db->insert('acc_closing', $arrayData);
            
            return $balance; // Return the latest balance
        }

        public function deleteClosing($closingDate) {
            $db = MysqliDb::getInstance();
            
            $db->where('date', $closingDate, " >= ");
            $db->delete('acc_closing');
            // Optmize the table after deletion
            $db->optimize('acc_closing');
        }


        public function memberPaymentTransaction($params,$clientID){
            $db = MysqliDb::getInstance();

            $downlineID = trim($params["downlineID"]);
            $amount = trim($params["amount"]);
            $paymentType = trim($params["paymentType"]);
            $creditType = trim($params["creditType"]);

            if(strlen($creditType) == 0)
                $creditType = "cash";

            if(empty($downlineID))
                return array("status"=>"error","code"=>"1","statusMsg"=>"Downline id is empty.","data"=>"");

            if(empty($amount))
                return array("status"=>"ok","code"=>"1","statusMsg"=>"Please enter amount.","data"=>"");
            
            if(!is_numeric($amount))
                return array("status"=>"ok","code"=>"1","statusMsg"=>"Please enter a valid amount.","data"=>"");

            if(empty($paymentType))
                return array("status"=>"ok","code"=>"1","statusMsg"=>"Please select your payment option.","data"=>"");

            if($paymentType != "pay" && $paymentType != "receive")
                return array("status"=>"ok","code"=>"1","statusMsg"=>"Invalid payment option.","data"=>"");

            //get downlineName
            $db->where("id",$downlineID);
            $downline = $db->get("client",1,"name");
            if(!empty($downline))
                $downlineName = $downline[0]["name"];

            $db->where("type","Internal");
            $db->where("name","payout");
            $payoutRes = $db->get("client",1,"id");
            if(!empty($payoutRes))
                $payoutID = $payoutRes[0]["id"];

            $fields = array("id","subject","type","from_id","to_id","client_id","amount","remark","belong_id","reference_id","batch_id","deleted","creator_id","creator_type","created_at");
            if($paymentType == "pay")
            {
                $belong = $db->getNewID();
                // insert upline pay to downline
                // Self::insertTAccount($clientID,$payoutID,$creditType,$amount,"Payout to downline",$belong,"",date("Y-m-d H:i:s"),$belong,$clientID);
                // insert downline receive payment from upline
                Self::insertTAccount($payoutID,$downlineID,$creditType,$amount,"Receive payment from upline",$belong,"",date("Y-m-d H:i:s"),$belong,$downlineID);
            }
            else if($paymentType == "receive")
            {
                $belong = $db->getNewID();
                // receive payment from downline
                Self::insertTAccount($downlineID,$payoutID,$creditType,$amount,"Payout to upline",$belong,"",date("Y-m-d H:i:s"),$belong,$downlineID);
                // downline pay to upline
                // Self::insertTAccount($payoutID,$clientID,$creditType,$amount,"Receive payment from downline",$belong,"",date("Y-m-d H:i:s"),$belong,$clientID);
            }

            //get client balance
            $balance = Self::getClientCacheBalance($downlineID,$creditType);

            $db->where("deleted","0");
            $db->where("client_id",$downlineID);
            // $db->where ("(from_id = ? or to_id = ?)", Array($clientID,$clientID));
            $getRes = $db->get("credit_transaction",null,"id,created_at,subject,amount");

            if(!empty($getRes))
            {
                foreach($getRes as $value)
                {
                   
                    if($value["subject"] == "Receive payment from upline" || $value["subject"] == "Payout to upline")
                    {
                        $id[] = $value["id"];
                        $transDate[] = $value["created_at"];

                        $tempSub = $value["subject"];
                        if($tempSub == "Payout to upline")
                        {
                            $subject[] = "Receive payment from $downlineName";
                        }
                        else{
                            $subject[] = "Payout to $downlineName";
                        }

                        $transAmount[] = $value["amount"];
                    }
                }
                $output["id"] = $id;
                $output["date"] = $transDate;
                $output["subject"] = $subject;
                $output["payout"] = $transAmount;
                $data["paymentList"] = $output;
                $data["balance"] = $balance;
            }
            else{
                return array("status"=>"error","code"=>1,"statusMsg"=>"No payment found.","data"=>"");
            }

            return array("status"=>"ok", "code"=>"0","statusMsg"=>"Add Payment successfull.","data"=>$data);
        }

        private function insertCreditTransaction($accountID, $receiverID, $type, $amount, $subject, $belongID, $referenceID, $transactionDate, $batchID, $clientID, $remark,$portfolioID=0, $data, $transactionID,$rate){
            $db = MysqliDb::getInstance();
            
            $creatorID = self::$creatorID;
            $creatorType = self::$creatorType;

            $fields = array("subject", "type", "from_id", "to_id", "client_id", "amount", "remark", "belong_id", "reference_id", "batch_id", "deleted", "creator_id", "creator_type", "created_at","portfolio_id", "data", "group_id","coin_rate");

            // $id = $db->getNewID();
            if($type == 'memberDef')
            {
                $values = array($subject, 'bonusDef', $accountID, $receiverID, $clientID, $amount, $remark, $belongID, $referenceID, $batchID, "0", $creatorID, 'System', $transactionDate,$portfolioID, $data, $transactionID,$rate);
            }
            else
            {
                $values = array($subject, $type, $accountID, $receiverID, $clientID, $amount, $remark, $belongID, $referenceID, $batchID, "0", $creatorID, $creatorType, $transactionDate,$portfolioID, $data, $transactionID,$rate);
            }

            $arrayData = array_combine($fields,$values);
            
            $result = $db->insert("credit_transaction",$arrayData);
            if($result) return $result;

            return false;
        }

        function insertRedeemPoints($params) {
            
            $db = MysqliDb::getInstance();

            $clientID=$params['clientID'];
            $bonusValue=$params['bonusValue'];
            $belongID=$params['belongID'];
            $batchID=$params['batchID'];
            $portfolioID=$params['portfolioID'];

            $db->where('name', 'redeemPoints');
            $db->where('type', 'Internal');
            $redeemPointsID = $db->getValue('client', 'id');

            $promoCreditArray=array('redeem1Credit','redeem2Credit');
            $db->where('credit.name', $promoCreditArray,'IN');
            $db->join('credit_setting', "credit.id = credit_setting.credit_id");
            $db->where('credit_setting.name', 'credit2Points');

            //maps creditName to redeemPoints amount
            $creditSettingAry = $db->map('creditName')->arrayBuilder()->get("credit", null, "`credit`.`name` AS creditName,`credit_setting`.`value`");
            
            $subject="Redeem Point";
            $convertSubject="Convert Point";
            $prizeCredit="pointCredit";
            /*
            redeem1Credit for self
            redeem2Credit for direct upline
            */

            // $db->where('client_id',$clientID);
            // $uplineID=$db->getValue('tree_sponsor','upline_id');

            $creditType='redeem1Credit';
            Self::insertTAccount($redeemPointsID, $clientID, $creditType, $bonusValue, $subject, $belongID, "", $db->now(), $batchID, $clientID, "Investment",$portfolioID);
            // $clientBalance=Self::getBalance($clientID,$creditType);

            // $convertedPoints=floor($clientBalance/$creditSettingAry[$creditType]);

            // if ($convertedPoints){
            //     Self::insertTAccount($clientID, $redeemPointsID, $creditType, $convertedPoints*$creditSettingAry[$creditType], $convertSubject, $belongID, "", $db->now(), $batchID, $clientID, "Investment");
            //     Self::insertTAccount($redeemPointsID, $clientID, $prizeCredit, $convertedPoints, $convertSubject, $belongID, "", $db->now(), $batchID, $clientID, "Investment");
            // }

            // if($uplineID){
                
            //     $creditType='redeem2Credit';

            //     Self::insertTAccount($redeemPointsID, $uplineID, $creditType, $bonusValue, $subject, $belongID, "", $db->now(), $batchID, $clientID, "Sponsor");
            //     // $uplineBalance=Self::getBalance($uplineID,$creditType);

            //     // $convertedPoints=floor($uplineBalance/$creditSettingAry[$creditType]);

            //     // if ($convertedPoints){
            //     //     Self::insertTAccount($uplineID, $redeemPointsID, $creditType, $convertedPoints*$creditSettingAry[$creditType], $convertSubject, $belongID, "", $db->now(), $batchID, $uplineID,"Sponsor");
            //     //     Self::insertTAccount($redeemPointsID, $uplineID, $prizeCredit, $convertedPoints, $convertSubject, $belongID, "", $db->now(), $batchID, $uplineID,"Sponsor");
                    
            //     // }
            // }
        }

        // isSpecial - for hot deal freah deal
        function getPaymentDetail($clientID, $registerType, $price, $productID, $dateTime, $isSet, $type, $isSpecial){          
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getInternalDecimalFormat();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            if(!$dateTime) $dateTime = date("Y-m-d H:i:s");

            if(!$isSpecial){
                $walletList = Self::walletDisplaySetting($clientID);
                foreach ($walletList as $creditType => $walletData) {
                    $validCreditType[$creditType] = $creditType;
                    $creditDisplay[$creditType] = $walletData["translation_code"];
                }    
            } else {
                
                $db->where("name", "isHotDealFreshDeal");
                $db->where("value", "1");
                $creditIDAry = $db->map("credit_id")->get("credit_setting", null, " credit_id");

                $db->where("id", $creditIDAry, "IN");
                $walletList = $db->map("name")->get("credit", null, "name, type, translation_code");

                foreach ($walletList as $creditType => $walletData) {
                    $validCreditType[$walletData['type']][] = $creditType;
                    $creditDisplay[$walletData['type']] = $walletData["translation_code"];
                }
            }

            $db->where("product_id", $productID);
            $db->where("type","Credit Setting");
            $productRes = $db->get("mlm_product_setting", null, "name, value, reference");
            foreach($productRes as $productRow){
                $chargesRateAry[$productRow["name"]] = $productRow;
            }
            $db->where("status","Active");
            $db->where("payment_type",$registerType);
            $res = $db->get("mlm_payment_method", null, "credit_type AS creditType,min_percentage AS minPercentage,max_percentage AS maxPercentage, group_type AS groupType");
            foreach($res AS $row){

                if($validCreditType[$row["creditType"]] || $registerType == "Bonus Package Reentry"){

                    $row['creditDisplay'] = $translations[$creditDisplay[$row['creditType']]][$language];

                    if(is_array($validCreditType[$row["creditType"]])){
                        foreach ($validCreditType[$row["creditType"]] as $value) {
                            $row['balance'] += self::getBalance($clientID,$value);
                        }
                    }else{
                        $row['balance'] = self::getBalance($clientID,$row['creditType']);
                    }
                    $row['minPrice'] = number_format($row['minPercentage'] * $price / 100, $decimalPlaces, ".", "");
                    $row['maxPrice'] = number_format($row['maxPercentage'] * $price / 100, $decimalPlaces, ".", "");
                    
                    if($chargesRateAry[$row["creditType"]]["value"] && $chargesRateAry[$row["creditType"]]["value"] < 1){
                        //lock coin rate
                        if($isSet){
                            $coinRate = self::updateLockCoinRate($clientID, $row["creditType"],$type);
                        }else{
                            $coinRate = self::getLockCoinRate($clientID, $row["creditType"],$type);
                        }

                        $row["rate"] = Setting::setDecimal(($coinRate * $chargesRateAry[$row["creditType"]]["value"]),$row["creditType"]);

                    }else{
                        $row["rate"] = $chargesRateAry[$row["creditType"]]["value"] ? $chargesRateAry[$row["creditType"]]["value"] : 1;
                    }

                    $row["formula"] = $chargesRateAry[$row["creditType"]]["reference"] ? $chargesRateAry[$row["creditType"]]["reference"] : "multiply" ;
                    if(!$row['groupType']){
                        $paymentData[$row['creditType']] = $row;
                    }else{
                        $paymentData[$row['groupType']][$row['creditType']] = $row;
                    } 
                }
            }
            $data['paymentData'] = $paymentData;
            return array('status' => "ok", 'code' => 0, 'statusMsg' => "", 'data' => $data);
        }

        function paymentVerification($clientID,$registerType,$paymentDetail,$productID,$price,$dateTime, $type, $isSpecial){
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getInternalDecimalFormat();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $check = self::getPaymentDetail($clientID, $registerType, $price, $productID, $dateTime,"", $type, $isSpecial);
            if($check['status'] != "ok") return $check;
            $paymentData = $check['data']["paymentData"];
            // if(!$paymentDetail) return array('status' => "error", 'code' => 1,  'statusMsg' => "paymentDetail not found", 'data' => "");

            $totalPrice = $price;
            $percentage = 0;
            $totalSpend = 0;

            foreach($paymentData as $creditType => $val){ 
                $amount = $paymentDetail[$creditType]['amount'];
                if(!is_numeric($amount) && $amount != 0 ){
                    $errorFieldArr[] = array(
                                                    // 'id' => $creditType."Error",
                                                    'id' => "totalAmountError",
                                                    'msg' => "Amount number only.",
                                                );
                }

                if($val['maxPrice'] > 0 && $amount > $val['maxPrice']){
                    $errorMsg = str_replace("%%wallet%%", $translations[$val["creditDisplay"]][$language], $translations["E00508"][$language]);
                    $errorFieldArr[] = array(
                                                    // 'id' => $creditType."Error",
                                                    'id' => "totalAmountError",
                                                    'msg' => $errorMsg,
                                                );
                }

                if($val['minPrice'] > 0 && $amount < $val['minPrice']){
                    $errorMsg = str_replace("%%wallet%%", $translations[$val["creditDisplay"]][$language], $translations["E00507"][$language]);
                    $errorFieldArr[] = array(
                                                    // 'id' => $creditType."Error",
                                                    'id' => "totalAmountError",
                                                    'msg' => $errorMsg,
                                                );
                }

                if($val["formula"] == "divide"){
                    $payableAmount = Setting::setDecimal(($amount / $val["rate"]),$creditType);
                }else if($val["formula"] == "multiply"){
                    $payableAmount = Setting::setDecimal(($amount * $val["rate"]),$creditType);
                }else{
                    $payableAmount = $amount;
                }

                if($payableAmount > $val['balance']){
                    $errorFieldArr[] = array(
                                                    // 'id' => $creditType."Error",
                                                    'id' => "totalAmountError",
                                                    'msg' => $translations["E00266"][$language],
                                                );
                }
                if($amount > 0){
                    $invoiceSpendData[$creditType]["display"] = $val["creditDisplay"];
                    $invoiceSpendData[$creditType]["amount"] = $amount;
                    $invoiceSpendData[$creditType]["rate"] = $val["rate"];
                    $invoiceSpendData[$creditType]["paymentAmount"] = $payableAmount;
                }

                $totalSpend += $amount;
            }
            if($totalPrice > $totalSpend){
                $errorFieldArr[] = array(
                                                    'id' => "totalAmountError",
                                                    'msg' => $translations["E00824"][$language],
                                                );
            }

            if($totalPrice != $totalSpend){
                $errorFieldArr[] = array(
                                                    'id' => "totalAmountError",
                                                    'msg' => $translations["E00410"][$language],
                                                );
            }

            if($errorFieldArr){
                $data['field'] = $errorFieldArr;
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E00130"][$language] /* Data does not meet requirements. */, 'data'=> $data);
            }

            $data["invoiceSpendData"] = $invoiceSpendData;

            return array('status' => "ok", 'code' => 0, 'statusMsg' => "", 'data' => $data);           
        }

        // isSpecial - for hot deal freah deal
        function paymentConfirmation($clientID,$paymentType,$paymentDetail,$productID,$portfolioID,$price,$dateTime, $batchID,$belongID, $isSpecial){
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getInternalDecimalFormat();

            if(!$dateTime) $dateTime = date("Y-m-d H:i:s");
            /*$check = Self::paymentVerification($clientID,$paymentType,$paymentDetail,$productID,$price,$dateTime);
            if($check['status'] != "ok") return $check;*/

            $db->where("username","creditSales");
            $internalID = $db->getValue("client","id");

            if(!$belongID) $belongID = $db->getNewID();
            $subject = $paymentType;

            foreach($paymentDetail AS $creditType => $val){ 
                $amount = $val['paymentAmount'];
                if($amount > 0){
                    Self::insertTAccount($clientID, $internalID, $creditType, $amount, $subject, $belongID, '', $dateTime, $batchID, $clientID, $remark,$portfolioID, '', $transactionID, $val["rate"], $isSpecial);
                }
            }

            return true;
        }

        function updateLockCoinRate($clientID, $creditType, $type){
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getInternalDecimalFormat();

            if(!$clientID || !$creditType || !$type){
                return false;
            }
            $db->where("client_id",$clientID);
            $db->where("coin_type",$creditType);
            $db->where("type",$type);
            $lockID = $db->getValue("mlm_lock_coin_rate","id");

            $db->where("type",$creditType);
            $db->orderBy("id","DESC");
            $coinRate = $db->getValue("mlm_coin_rate","rate");
            $coinRate = $coinRate > 0 ? $coinRate : 1;
            if(!$lockID){
                $insertData = array(
                                        "client_id" => $clientID,
                                        "coin_type" => $creditType,
                                        "type" => $type,
                                        "rate" => $coinRate,
                                        "created_on" => date("Y-m-d H:i:s"),
                                    );
                $db->insert("mlm_lock_coin_rate", $insertData);
            }else{
                $updateData = array(
                                        "rate" => $coinRate,
                                    );
                $db->where("id", $lockID);
                $db->update("mlm_lock_coin_rate", $updateData);
            }

            return $coinRate;
        }

        function getLockCoinRate($clientID, $creditType, $type){
            $db = MysqliDb::getInstance();
            $decimalPlaces = Setting::getInternalDecimalFormat();

            if(!$clientID || !$creditType || !$type){
                return false;
            }

            $db->where("client_id",$clientID);
            $db->where("coin_type",$creditType);
            $db->where("type",$type);
            $coinRate = $db->getValue("mlm_lock_coin_rate","rate");

            return $coinRate;
        }

        function checkCreditLimit($clientID,$creditType,$amount){
        	$db = MysqliDb::getInstance();
        		
        	$sq = $db->subQuery();
        	$sq->where("name",$creditType);
        	$sq->getOne("credit","id");
        	$db->where("credit_id",$sq);
        	$db->where("name","convertCap");
        	$maxCap = $db->getValue("credit_setting","value");

        	$db->where("name","convertCap");
			$db->where("client_id",$clientID);
			$db->where("type",$creditType);
			$personalCap = $db->getValue("client_setting","value");
			
			if($personalCap > 0) $maxCap = $personalCap;

        	if($maxCap <= 0) return $amount;

        	$balance = Cash::getBalance($clientID,$creditType);
        	$total += $balance;

        	$db->where("client_id",$clientID);
        	$db->where("credit_type",$creditType);
        	$db->where("status","Completed","!=");
        	$res = $db->get("trd_transaction",NULL,"type,total_amount,actual_amount,admin_charge");
        	foreach($res AS $row){
        		$total += ($row['total_amount'] - $row['actual_amount']);
        		if($row['type'] == "buy") $total += $row['admin_charge'];
        	}	

        	$remainAmount = $maxCap - $total;

        	if($remainAmount >= $amount ){
        		return $amount;
        	}else{
        		if($remainAmount <= 0 ) return 0;
        		return $remainAmount;
        	}
        }

        public function checkFPXStatus($dataIn)
        {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $fpxChecksumUrl = Setting::$systemSetting['fpx_checksum_url'];

            //get some default value from database which did not get from fpx callback
            $db->where('purchase_id', $dataIn['fpx_sellerExOrderNo']);
            $getPurchaseDetails = $db->getOne('payment_gateway_details', 'type, buyer_email');

            if(!empty($getPurchaseDetails))
            {
                //get url for check fpx status
                $db->where('type', 'topup');
                $db->where('name', $getPurchaseDetails['type']);
                $db->where('deleted', 0);
                $db->where('disabled', 0);
                $getProvider = $db->getOne('provider', 'id, url1');

                //get exchange id 15/10/2019
                $db->where('provider_id', $getProvider['id']);
                $db->where('name', 'exchangeID');
                $getExchangeID = $db->getValue('provider_setting', 'value');

                //get default seller bank code 10/10/2019
                $db->where('provider_id', $getProvider['id']);
                $db->where('name', 'sellerBankCode');
                $getSellerBankCode = $db->getValue('provider_setting', 'value');

                //get default version 10/10/2019
                $db->where('provider_id', $getProvider['id']);
                $db->where('name', 'version');
                $getVersion = $db->getValue('provider_setting', 'value');

                //get product description 10/10/2019
                $db->where('id', $dataIn['fpx_sellerExOrderNo']);
                $getPackageId = $db->getValue('sale_order', 'package_id');


                if(!empty($getPackageId))
                {
                    $db->where('id', $getPackageId);
                    $db->where('disabled', 0);
                    $getPackage = $db->getValue('sms_package', 'package_lang');

                    if(!empty($getPackage))
                    {
                        $packageName = $translations[$getPackage]["english"];
                    }
                }

            }   
            
            //data needed for checking fpx payment status
            $buildCheckSum['fpx_msgType'] = 'AE';
            $buildCheckSum['fpx_msgToken'] = $dataIn['fpx_msgToken'];
            $buildCheckSum['fpx_sellerExId'] = $dataIn['fpx_sellerExId'];
            $buildCheckSum['fpx_sellerExOrderNo'] = $dataIn['fpx_sellerExOrderNo'];
            $buildCheckSum['fpx_sellerTxnTime'] = $dataIn['fpx_sellerTxnTime'];
            $buildCheckSum['fpx_sellerOrderNo'] = $dataIn['fpx_sellerOrderNo'];
            $buildCheckSum['fpx_sellerId'] = $dataIn['fpx_sellerId'];
            $buildCheckSum['fpx_sellerBankCode'] = $getSellerBankCode;
            $buildCheckSum['fpx_txnCurrency'] = $dataIn['fpx_txnCurrency'];
            $buildCheckSum['fpx_txnAmount'] = $dataIn['fpx_txnAmount'];
            $buildCheckSum['fpx_buyerEmail'] = $getPurchaseDetails['buyer_email'];
            
            $buildCheckSum['fpx_buyerName'] = '';
            $buildCheckSum['fpx_buyerBankId'] = $dataIn['fpx_buyerBankId'];
            $buildCheckSum['fpx_buyerBankBranch'] = '';
            $buildCheckSum['fpx_buyerAccNo'] = '';
            $buildCheckSum['fpx_buyerId'] = '';
            $buildCheckSum['fpx_makerName'] = '';
            $buildCheckSum['fpx_buyerIban'] = '';
            // $buildCheckSum['fpx_productDesc'] = $packageName;
            $buildCheckSum['fpx_productDesc'] = 'Package A';

            // $packageName
            $buildCheckSum['fpx_version'] = $getVersion;

            $checkSum['fpx_checkSum'] = $buildCheckSum['fpx_buyerAccNo']."|".$buildCheckSum['fpx_buyerBankBranch']."|".$buildCheckSum['fpx_buyerBankId']."|".$buildCheckSum['fpx_buyerEmail']."|".$buildCheckSum['fpx_buyerIban']."|".$buildCheckSum['fpx_buyerId']."|".$buildCheckSum['fpx_buyerName']."|".$buildCheckSum['fpx_makerName']."|".$buildCheckSum['fpx_msgToken']."|".$buildCheckSum['fpx_msgType']."|".$buildCheckSum['fpx_productDesc']."|".$buildCheckSum['fpx_sellerBankCode']."|".$buildCheckSum['fpx_sellerExId']."|".$buildCheckSum['fpx_sellerExOrderNo']."|".$buildCheckSum['fpx_sellerId']."|".$buildCheckSum['fpx_sellerOrderNo']."|".$buildCheckSum['fpx_sellerTxnTime']."|".$buildCheckSum['fpx_txnAmount']."|".$buildCheckSum['fpx_txnCurrency']."|".$buildCheckSum['fpx_version'];


            //to build check sum for check fpx status
            // $priv_key = file_get_contents(__DIR__.'/../MbbFpxKey/EX00010011.key');
            // $priv_key = file_get_contents(__DIR__.$getExchangeID);
            $priv_key = file_get_contents($getExchangeID);

            // $priv_key = file_get_contents(__DIR__.'/../MbbFpxKey/EX00010010.key');
            $pkeyid = openssl_get_privatekey($priv_key);

            openssl_sign($checkSum['fpx_checkSum'], $binary_signature, $pkeyid, OPENSSL_ALGO_SHA1);
            $buildCheckSum['fpx_checkSum'] = strtoupper(bin2hex( $binary_signature ) );

            // $URL = $getProvider['url1'];
            $URL = $fpxChecksumUrl;

            // $URL = "https://uat.mepsfpx.com.my/FPXMain/sellerNVPTxnStatus.jsp";
            $finalURL = $URL."?".http_build_query($buildCheckSum);
            // return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => '', 'data' => $finalURL);

            $curl = curl_init($finalURL);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_VERBOSE, 0);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT ,120);
            curl_setopt($curl, CURLOPT_TIMEOUT, 120); // timeout in seconds
            $response = curl_exec($curl);
            
            $curlErrorNo = curl_errno($curl);
            $curlErrorDesc = curl_error($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            
            return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["E00001"][$language], 'data' => $response); //completed successfully


        }
        
        public function getBankDetails($params)
        {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $paymentMethod = $params['paymentMethod'];

            if($paymentMethod == 'fpx')
            {
                $db->where('send_type', $paymentMethod);
                $db->where('disabled', 0);
                $db->where('deleted', 0);
                $getProvider = $db->getValue('provider_test', 'id');
                if(!empty($getProvider))
                {
                    $db->orderBy("display_name", "Asc");
                    $db->where('provider_id', $getProvider);
                    $db->where('disabled', 0);
                    $db->where('deleted', 0);
                    $getFPXBankLists = $db->get('fpx_bank_lists', NULL);

                    if(!empty($getFPXBankLists))
                    {
                        foreach($getFPXBankLists as $key => $value)
                        {
                            $bankLists['bank_id']           = $value['bank_id'];
                            $bankLists['bank_name']         = $value['bank_name'];
                            $bankLists['display_name']      = $value['display_name'];
                            
                            $bankInfo[$value['bank_id']] = $bankLists;
                        }

                        $data['bankInfo'] = $bankInfo;

                        return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["E00001"][$language], 'data' => $data); //completed successfully
                    }
                    else
                    {
                        return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00001"][$language], 'data' => ''); //Not record found
                    }
                }
                else
                {
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00001"][$language], 'data' => ''); //Not record found
                }
            }
            else
            {
                return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00001"][$language], 'data' => ''); //Not record found
            }

        }

        function pdfProformaInvoiceContent($dataIn){ // use language file to store html
            $setting = $this->setting;
            $general = $this->general;
            $language = General::$currentLanguage;
            $translations = General::$translations;

            include_once("mpdf-7.0/mpdf.php");
  
            $test = 1;
            
            if(str_replace(',', '',$dataIn["purchase_amount"]) >= 450 && time() < strtotime(date("2020-09-16 00:00:00"))){
              $pdfProformaInvoiceContent = "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\"> 
  
          <head>
            <style>
                * {
                  box-sizing: border-box;
                }
  
                /* Create two equal columns that floats next to each other */
                .column {
                  float: left;
                  padding: 10px;
                }
  
                /* Clear floats after the columns */
                .row:after {
                  content: \"\";
                  display: table;
                  clear: both;
                }
  
                .vl {
                  border-left: 80px solid #89000e;
                }
  
            </style>
  
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
            <link rel=\"stylesheet\" type=\"text/css\" href=\"%%websiteURL%%/css/loggedIn.css?t=1527496770\"/>
          </head>
          <body bgcolor=\"#53565a\" style=\"font-family:Arial;\">
                <div style=\"background-color: white;top:0;bottom:0; right:0;position:fixed;overflow-y:scroll;overflow-x:hidden;margin:0 auto;\" >
                  <div style=\"height: 60px;width:1000px;vertical-align: middle;display: table; padding:50px 0px 0px 20px;\" align = \"center\">     
                          <img id=\"\" src=\"%%websiteURL%%/images/logo.png\" width = \"300\" style = \"padding:15px 0px 15px 0px;\">
                          <br/>
                          %%display_gstID%%
                  </div>
  
                  <div style=\"width:1000px; padding:60px 0px 0px 20px;\" align=\"left\">
                      <div class=\"bold\" style= \"font-size:30px;font-weight:900;color:#89000e;\">PROFORMA INVOICE</div>
                      <div style=\"height: 60px;width:1000px; padding:20px 0px 0px 0px;\" align=\"left\">  
                        <table class=\"\" style=\"width:50%;padding:0px 0px 0px 0px;\" border=0 cellspacing=0 align = \"left\">
                          <tr>
                            <td style=\"height:25px;width:250px;\">
                              <div style=\"font-size:14px;font-weight:900;width:150px;%%display_proforma_invoice_date%%\"><b>Proforma Invoice Date</b></div>
                          </td>
                          <td>
                              <div class=\"semibold\" style=\"font-size:14px;%%display_proforma_invoice_date%%\"> : %%proforma_invoice_date%%</div>
                          </td>
                          </tr>
                          <tr>
                          <td style=\"height:25px;width:250px;\">
                              <div  style=\"font-size:14px;font-weight:900;width:150px;%%display_proforma_invoice_id%%\"><b>Proforma Invoice No</b></div>
                          </td>
                          <td>
                              <div class=\"semibold\" style=\"font-size:14px;%%display_proforma_invoice_id%%\"> : %%proforma_invoice_id%%</div>
                          </td>
                          </tr>
                        </table>
                      </div>
                      <br/>
                        <br/>
                      
                  </div>
                  <div style=\"width:1000px;vertical-align: middle;display: table; padding:10px 0px 0px 20px;\" align=left> 
                      <hr>
                      <div class=\"row\" style = \"width:100%;\">
                        <div class = \"column\" style = \"width:45%;\">
                            <div class=\"semibold\" style=\"font-size:20px;color:#89000e;%%display_name%%\">%%first_name%% %%last_name%%</div>
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_company%%\">%%client_company%%</div>
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_address1%%\">%%client_address1%%</div>
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_address2%%\">%%client_address2%%</div>
                            <br/>              
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_phone%%\">[T] %%client_phone%%</div>
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_email%%\">[E] %%client_email%%</div>
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_website%%\">[W] %%client_website%%</div>
                            <br>
                            <br>
                            <br>
                            <br>
                        </div>
                        <div class = \"column\" style = \"width:50%;\">
                          <div class=\"semibold\" style=\"font-size:14px;color:#89000e;padding-left:60px;%%display_payment_method%%\">Payment Method&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; %%payment_method%%</div>
                            <br>
                            <table class=\"\" style=\"width:100%;padding-left:60px;\" border=0 cellspacing=0 cellpadding=0>
                              %%accountName%%
                              %%sysBankName%%
                              %%sysBankCountry%%
                              %%swiftCode%%
                              %%sysBankAcc%%
                              
                              %%display_purchaseID%%
                              %%display_transactionID%%
                              %%display_BuyerID%%
                              %%display_FPXTime%%
                              %%display_FPXStatus%%
                              <tr>
                                  <td style=\"height:25px;width:150px;\">
                                    <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_status%%\">Payment Status </div>
                                  </td>
                                  <td style=\"height:25px;\">
                                    <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_status%%\"> %%status%%</div>
                                  </td>
                              </tr>
                            </table>
                        </div>
                      </div>
                      <hr>
                    </div>
                    <br> 
                    <div style=\"width:1000px;vertical-align: middle;display: table; padding:40px 0px 0px 20px;\" align=left> 
                      <br>            
                      <table class=\"\" style=\"width:900px\" border=0 cellspacing=0 cellpadding=0>
                        <tr>
                            <th style=\"font-size:15px;padding:0px 0px 0px 20px;height:40px;width:200px;border-top:1px solid black;border-bottom:1px solid black;\" align=\"left\">Description</th>
                            <th style=\"font-size:15px;height:40px;width:175px;border-top:1px solid black;border-bottom:1px solid black;\" align=\"center\">Quantity</th>
                            <th style=\"font-size:15px;height:40px;width:175px;border-top:1px solid black;border-bottom:1px solid black;\" align=\"right\">Price</th>
                            <th style=\"font-size:15px;height:40px;width:150px;border-top:1px solid black;border-bottom:1px solid black;padding:0px 20px 0px 0px;\" align=\"right\" >Total</th>
                        </tr>
                        <tbody>
                            <tr class=\"\">
                              <td style=\"font-size:14px;padding:0px 0px 0px 20px;height:36px;\">%%desc%%</td>
                              <td style=\"font-size:14px;height:36px;\" align=\"center\">%%quantity%%</td>
                              <td style=\"font-size:14px;height:36px;\" align=\"right\">%%symbol%% %%release_amount%%</td>
                              <td style=\"font-size:14px;height:36px;padding:0px 20px 0px 0px;\" align=\"right\">%%symbol%% %%release_amount%%</td>
                            </tr>
                            <!-- mooncake -->
                            <tr class=\"\">
                              <td style=\"font-size:14px;padding:0px 0px 0px 20px;height:36px;\">Mooncake Box 2020 (4 pcs)</td>
                              <td style=\"font-size:14px;height:36px;\" align=\"center\">1</td>
                              <td style=\"font-size:14px;height:36px;\" align=\"right\">F.O.C.</td>
                              <td style=\"font-size:14px;height:36px;padding:0px 20px 0px 0px;\" align=\"right\">F.O.C.</td>
                            </tr>
                            <tr>
                              <td height=\"100px\"></td>
                            </tr>
                            <tr style=\"background-color:#f0f8f3;\">
                              <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">Subtotal</td>
                              <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%release_amount%%</td>
                            </tr>
                            %%display_payment_fee%%
                            %%display_gst%%
                            %%displayDiscount%%
                            %%displayRedeemReward%%
                            <tr style=\"background-color:#f0f8f3;\">
                              <td colspan=3 style=\"font-size:14px;padding:0px 20px 0px 20px;height:30px;border-bottom:1px solid black;\">Total</td>
                              <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:30px;border-bottom:1px solid black;\" align=\"right\">%%symbol%% %%purchase_amount%%</td>
                            </tr>
                        </tbody>
                      </table>
                      <div style=\"width:1000px;font-size:12px;padding:10px 0px 20px 0px;color:grey;\">All bank and payment gateway fees and charges are to be covered by the customer. 
                      <br>
                      In case of any such fees and charges, the credits added to your account will depend on the exact amount of money received by SMS123.</div>
                  </div>
  
                  <div style=\"width:1000px;padding:120px 0px 20px 20px;font-size:12px;\">
                    <b>Question?</b><br/>
                    <span>Email us at </span><strong>support@sms123.net</strong><br/>
                    <span>or call us at </span><strong>+6018-2460000</strong><br/>
                    <span>[Fax] </span><strong>+603 8211 8434</strong><br/>
                  
                      <hr style = \"height:3px;border:none;color:#89000e;background-color:#89000e;\">
  
                      <div align = \"center\" style = \"padding:0px 20px 0px 0px;font-weight:800;color:gray\">
                        <strong style=\"font-size:12px;\">%%companyName%%, %%companyAddress1%%, %%companyAddress2%%, %%companyAddress3%% | SMS123.NET</strong>
                      </div>
                  </div>
  
                </div>
          </body>
          </html>";
          }
          else{
            $pdfProformaInvoiceContent = $translations["S01268"][$language];
            }
            
             
              $pdfProformaInvoiceContent = str_replace('%%websiteURL%%',$dataIn["websiteURL"],$pdfProformaInvoiceContent);
  
              $display_gstID = "<span class=\"semibold\" style=\"font-size:14px;color:gray;\">(GST ID No:%%gstID%%)</span>";
              
              if($dataIn["gstID"] !== "" && $dataIn["gstEnable"] == "1"){
                $pdfProformaInvoiceContent = str_replace('%%display_gstID%%',$display_gstID,$pdfProformaInvoiceContent);
                $pdfProformaInvoiceContent = str_replace('%%gstID%%',$dataIn["gstID"],$pdfProformaInvoiceContent);
  
              }else{
                $pdfProformaInvoiceContent = str_replace('%%display_gstID%%',"",$pdfProformaInvoiceContent);
              }
  
              $pdfProformaInvoiceContent = str_replace('%%companyName%%',$dataIn["companyName"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%companyAddress1%%',$dataIn["companyAddress1"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%companyAddress2%%',$dataIn["companyAddress2"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%companyAddress3%%',$dataIn["companyAddress3"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%companyTel%%',$dataIn["companyTel"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%companyFax%%',$dataIn["companyFax"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%companyEmail%%',$dataIn["companyEmail"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%websiteName%%',$dataIn["websiteName"],$pdfProformaInvoiceContent);
              
              $pdfProformaInvoiceContent = str_replace(array("%%first_name%%", "%%last_name%%"),array($dataIn["first_name"],$dataIn["last_name"]),$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%client_company%%',$dataIn["client_company"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%client_address1%%',$dataIn["client_address1"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%client_address2%%',$dataIn["client_address2"],$pdfProformaInvoiceContent);
              
              $mobileNumberDetails = $general->mobileNumberInfo($dataIn["client_phone"], $dataIn["client_region_code"]);
                $pdfProformaInvoiceContent = str_replace('%%client_phone%%',$mobileNumberDetails["mobileNumberFormatted"],$pdfProformaInvoiceContent);
              
              $pdfProformaInvoiceContent = str_replace('%%client_email%%',$dataIn["client_email"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%client_website%%',$dataIn["client_website"],$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%proforma_invoice_date%%',date("d/m/Y",$dataIn["proforma_invoice_date"]),$pdfProformaInvoiceContent);
  
              $pdfProformaInvoiceContent = str_replace('%%proforma_invoice_id%%',$dataIn["proforma_invoice_id"],$pdfProformaInvoiceContent);
  
  
              $pdfProformaInvoiceContent = str_replace('%%payment_method%%',$dataIn["payment_method"],$pdfProformaInvoiceContent);
  
  
              if($dataIn["getPaymentMethod"] == "fpx")
              {
                  
  
                  if($dataIn["fpx_sellerOrderNo"] != "")
                  {
                        
                      $purchase_id = "<tr>
                     <td style=\"height:25px;width:150px;\">
                        <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_purchaseID%%\">Seller Order Number </div>
                     </td>
                     <td style=\"height:25px;\">
                        <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_purchaseID%%\"> : ".$dataIn["fpx_sellerOrderNo"]."</div>
                     </td>
                    </tr>";
                  }
                  else
                  {   
                      
                      $purchase_id = "";
                  }
  
                  if($dataIn["fpx_fpxTxnId"] != ""){
                   $fpx_fpxTxnId = "<tr>
                     <td style=\"height:25px;width:150px;\">
                        <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_transactionID%%\">FPX Transaction ID </div>
                     </td>
                     <td style=\"height:25px;\">
                        <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_transactionID%%\"> : ".$dataIn["fpx_fpxTxnId"]."</div>
                     </td>
                    </tr>";
                  }
                  else
                  {
                      $fpx_fpxTxnId = "";
                  }
  
                  if($dataIn["fpx_buyerBankId"] != ""){
                       $fpx_buyerBankId = "<tr>
                         <td style=\"height:25px;width:150px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_BuyerID%%\">Buyer Bank Name </div>
                         </td>
                         <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_BuyerID%%\"> : ".$dataIn["fpx_buyerBankId"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $fpx_buyerBankId = "";
                  }
  
                  if($dataIn["fpx_fpxTxnTime"] != ""){
                       $fpx_fpxTxnTime = "<tr>
                         <td style=\"height:25px;width:200px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_FPXTime%%\">Transaction Date and Time </div>
                         </td>
                         <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_FPXTime%%\"> : ".$dataIn["fpx_fpxTxnTime"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $fpx_fpxTxnTime = "";
                  }
  
                  if($dataIn["fpx_debitAuthCode"] != ""){
  
  
                       $fpx_debitAuthCode = "<tr>
                         <td style=\"height:25px;width:150px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_FPXStatus%%\">Transaction Status </div>
                         </td>
                          <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_FPXStatus%%\"> : ".$dataIn["fpx_debitAuthCode"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $fpx_debitAuthCode = "";
                  }
  
                  $pdfProformaInvoiceContent = str_replace('%%display_purchaseID%%',$purchase_id,$pdfProformaInvoiceContent);
                  $pdfProformaInvoiceContent = str_replace('%%display_transactionID%%',$fpx_fpxTxnId,$pdfProformaInvoiceContent);
                  $pdfProformaInvoiceContent = str_replace('%%display_BuyerID%%',$fpx_buyerBankId,$pdfProformaInvoiceContent);
                  $pdfProformaInvoiceContent = str_replace('%%display_FPXTime%%',$fpx_fpxTxnTime,$pdfProformaInvoiceContent);
                  $pdfProformaInvoiceContent = str_replace('%%display_FPXStatus%%',$fpx_debitAuthCode,$pdfProformaInvoiceContent);
  
                  $display_paymentTerms = "display:none; !important";
                    $pdfProformaInvoiceContent = str_replace('%%display_paymentTerms%%',$display_paymentTerms,$pdfProformaInvoiceContent) ;
  
              }
              else
              {
                  
                  if(strtolower($dataIn['status']) != "void"){
  
                      
                    $pdfProformaInvoiceContent = str_replace('%%paymentDuePeriod%%',$setting->getPaymentDuePeriod(),$pdfProformaInvoiceContent) ;
                  }else{
                      
                    $display_paymentTerms = "display:none; !important";
                    $pdfProformaInvoiceContent = str_replace('%%display_paymentTerms%%',$display_paymentTerms,$pdfProformaInvoiceContent) ;
                  }
  
  
                  if($dataIn["accountName"] != ""){
  
  
                       $accountName = "<tr>
                         <td style=\"height:25px;width:150px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%accountName%%\">Beneficiary </div>
                         </td>
                          <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%accountName%%\"> : ".$dataIn["accountName"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $accountName = "";
                  }
  
  
                  if($dataIn["sysBankName"] != ""){
  
  
                       $sysBankName = "<tr>
                         <td style=\"height:25px;width:150px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%sysBankName%%\">Bank Name </div>
                         </td>
                          <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%sysBankName%%\"> : ".$dataIn["sysBankName"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $sysBankName = "";
                  }
  
                  if($dataIn["sysBankCountry"] != ""){
  
  
                       $sysBankCountry = "<tr>
                         <td style=\"height:25px;width:150px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%sysBankCountry%%\">Bank Country </div>
                         </td>
                          <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%sysBankCountry%%\"> : ".$dataIn["sysBankCountry"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $sysBankCountry = "";
                  }
  
                  if($dataIn["swiftCode"] != ""){
  
  
                       $swiftCode = "<tr>
                         <td style=\"height:25px;width:150px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%swiftCode%%\">BIC/SWIFT </div>
                         </td>
                          <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%swiftCode%%\"> : ".$dataIn["swiftCode"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $swiftCode = "";
                  }
  
                  if($dataIn["sysBankAcc"] != ""){
  
  
                       $sysBankAcc = "<tr>
                         <td style=\"height:25px;width:150px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%sysBankAcc%%\">Account Number </div>
                         </td>
                          <td style=\"height:25px;\">
                            <div class=\"semibold\" style=\"font-size:14px;color:gray;%%sysBankAcc%%\"> : ".$dataIn["sysBankAcc"]."</div>
                         </td>
                        </tr>";
                  }
                  else
                  {
                      $sysBankAcc = "";
                  }
  
  
                  $pdfProformaInvoiceContent = str_replace('%%accountName%%',$accountName,$pdfProformaInvoiceContent);
                  $pdfProformaInvoiceContent = str_replace('%%sysBankName%%',$sysBankName,$pdfProformaInvoiceContent);
  
                  $pdfProformaInvoiceContent = str_replace('%%sysBankCountry%%',$sysBankCountry,$pdfProformaInvoiceContent);
  
                  $pdfProformaInvoiceContent = str_replace('%%swiftCode%%',$swiftCode,$pdfProformaInvoiceContent);
  
                  $pdfProformaInvoiceContent = str_replace('%%sysBankAcc%%',$sysBankAcc,$pdfProformaInvoiceContent);
  
  
              }
  
  
              $pdfProformaInvoiceContent = str_replace('%%status%%',$dataIn["status"],$pdfProformaInvoiceContent);
  
          
              // if($dataIn["purchase_amount"] > 0){
              //          if($dataIn["refund"] != 1){
              //              if($dataIn["currency"]=="CREDIT"){
              //                  $desc = $dataIn["release_amount"]." SMS Credits";
              //              }
              //              else{
              //                  $desc = "Top Up SMS Credits";
              //              }
              //              $quantity = 1;
              //              $amount = $dataIn["purchase_amount"];
              //              $release_amount = $dataIn["release_amount"];
              //          }
              //          else {
              //              $desc = $dataIn["remark"];
              //              $quantity = $dataIn["purchase amount"];
              //              $amount = 0;
              //              $dataIn["release_amount"] = 0;
              //          }
              //      }
              if($dataIn['release_currency'] == 'CREDIT')
              {
                  $desc = $dataIn['displayReleaseAmount']." ".$translations["M01255"][$language];
              }
              else
              {
                  $desc = $translations["M01255"][$language];
              }
  
              $quantity = 1;
              $amount = $dataIn["purchase_amount"];
              $release_amount = $dataIn["release_amount"];
  
              $pdfProformaInvoiceContent = str_replace('%%desc%%',$desc,$pdfProformaInvoiceContent) ;
  
              $pdfProformaInvoiceContent = str_replace('%%quantity%%',$quantity,$pdfProformaInvoiceContent) ;
  
              $pdfProformaInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfProformaInvoiceContent) ;
  
              $pdfProformaInvoiceContent = str_replace('%%release_amount%%',$dataIn["release_amount"],$pdfProformaInvoiceContent) ;
  
    
              $display_payment_fee = "<tr style=\"background-color:#f0f8f3;\">
                    <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">Payment Fee</td>
                    <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%payment_fee%%</td>
                 </tr>";
  
              if($dataIn["payment_fee"] > 0){
                  $pdfProformaInvoiceContent = str_replace('%%display_payment_fee%%',$display_payment_fee,$pdfProformaInvoiceContent);
                $pdfProformaInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfProformaInvoiceContent) ;
                   $pdfProformaInvoiceContent = str_replace('%%payment_fee%%',$dataIn["payment_fee"],$pdfProformaInvoiceContent);
  
              } else{
                $pdfProformaInvoiceContent = str_replace('%%display_payment_fee%%',"",$pdfProformaInvoiceContent) ;
              } 
  
             $display_gst = "<tr style=\"background-color:#f0f8f3;\">
                    <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;%%display_gst%%\">GST (%%gst%%%)</td>
                    <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black; align=\"right\";%%display_gst%%\">%%symbol%% %%payment_tax%%</td>
                 </tr>";
  
              if($dataIn["payment_tax"] !== "" && $dataIn["gstEnable"] == "1"){
                $pdfProformaInvoiceContent = str_replace('%%display_gst%%',$display_gst,$pdfProformaInvoiceContent);
                $pdfProformaInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfProformaInvoiceContent) ;
                $pdfProformaInvoiceContent = str_replace('%%gst%%',$setting->getGST(),$pdfProformaInvoiceContent);
                $pdfProformaInvoiceContent = str_replace('%%payment_tax%%',$dataIn["payment_tax"],$pdfProformaInvoiceContent);
  
              }else{
                  $pdfProformaInvoiceContent = str_replace('%%display_gst%%',"",$pdfProformaInvoiceContent) ; 
              }
  
               $displayDiscount = "<tr style=\"background-color:#f0f8f3;\">
                    <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">Discount (".$dataIn['promoCode'].")</td>
                    <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%discountAmount%%</td>
                 </tr>";
  
              if($dataIn["discountAmount"] > 0 && $dataIn["isPromo"] == 1){
                  $pdfProformaInvoiceContent = str_replace('%%displayDiscount%%',$displayDiscount,$pdfProformaInvoiceContent);
                  $pdfProformaInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfProformaInvoiceContent) ;
                  $pdfProformaInvoiceContent = str_replace('%%discountAmount%%',$dataIn["discountAmount"],$pdfProformaInvoiceContent);
  
              } else{
                  $pdfProformaInvoiceContent = str_replace('%%displayDiscount%%',"",$pdfProformaInvoiceContent) ;
              } 
  
  
               $displayRedeemReward = "<tr style=\"background-color:#f0f8f3;\">
                    <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">TheNux Reward Redeem</td>
                    <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%redeemAmount%%</td>
                 </tr>";
  
              if($dataIn["redeemAmount"] > 0 && $dataIn["isRedeemReward"] == 1){
                  $pdfProformaInvoiceContent = str_replace('%%displayRedeemReward%%',$displayRedeemReward,$pdfProformaInvoiceContent);
                  $pdfProformaInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfProformaInvoiceContent) ;
                  $pdfProformaInvoiceContent = str_replace('%%redeemAmount%%',$dataIn["redeemAmount"],$pdfProformaInvoiceContent);
  
              } else{
                  $pdfProformaInvoiceContent = str_replace('%%displayRedeemReward%%',"",$pdfProformaInvoiceContent) ;
              } 
  
  
          $pdfProformaInvoiceContent = str_replace('%%purchase_amount%%',$dataIn["purchase_amount"],$pdfProformaInvoiceContent);
  
          if(strtolower($dataIn['clientSendType']) != "shortcode"){
            $pdfProformaInvoiceContent = str_replace('%%display_shortCode_remarks%%','display:none;',$pdfProformaInvoiceContent);          
          }
  
              $filename = tempnam(sys_get_temp_dir(), time());
              $mpdf = new mPDF();
              $mpdf->autoScriptToLang = true;
              $mpdf->autoLangToFont = true;
              $mpdf->SetDisplayMode('fullpage');
              $mpdf->WriteHTML($pdfProformaInvoiceContent);
              $mpdf->Output($filename, 'F');
              
              
  
              return array("path" => $filename, "content" => $pdfProformaInvoiceContent, 'dataIn' => $dataIn, 'test' => $test);
  
        }

        public function FPXBackendVerify($dataIn)
        {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $browserInfo    = General::getBrowserInfo();
            $ip = $browserInfo["ip"]?$browserInfo["ip"]:"Unknown";
            $ipInfo = General::ip_info($ip);

            $backendChecking['clientID']              = $dataIn['client_id'];
            $backendChecking['fpx_creditAuthNo']      = $dataIn['fpx_creditAuthNo'];
            $backendChecking['fpx_msgToken']          = $dataIn['fpx_msgToken'];
            $backendChecking['fpx_txnCurrency']       = $dataIn['fpx_txnCurrency'];
            $backendChecking['fpx_sellerOrderNo']     = $dataIn['fpx_sellerOrderNo'];
            $backendChecking['fpx_creditAuthCode']    = $dataIn['fpx_creditAuthCode'];
            $backendChecking['fpx_fpxTxnTime']        = $dataIn['fpx_fpxTxnTime'];
            $backendChecking['fpx_makerName']         = $dataIn['fpx_makerName'];
            $backendChecking['fpx_debitAuthNo']       = $dataIn['fpx_debitAuthNo'];
            $backendChecking['fpx_txnAmount']         = $dataIn['fpx_txnAmount'];
            $backendChecking['fpx_sellerExId']        = $dataIn['fpx_sellerExId'];
            $backendChecking['fpx_buyerBankId']       = $dataIn['fpx_buyerBankId'];
            $backendChecking['fpx_msgType']           = $dataIn['fpx_msgType'];
            $backendChecking['fpx_checkSum']          = $dataIn['fpx_checkSum'];
            $backendChecking['fpx_sellerExOrderNo']   = $dataIn['fpx_sellerExOrderNo'];
            $backendChecking['fpx_buyerName']         = $dataIn['fpx_buyerName'];
            $backendChecking['fpx_sellerTxnTime']     = $dataIn['fpx_sellerTxnTime'];
            $backendChecking['fpx_sellerId']          = $dataIn['fpx_sellerId'];
            $backendChecking['fpx_buyerIban']         = $dataIn['fpx_buyerIban'];
            $backendChecking['fpx_debitAuthCode']     = $dataIn['fpx_debitAuthCode'];
            $backendChecking['fpx_buyerId']           = $dataIn['fpx_buyerId'];
            $backendChecking['fpx_buyerBankBranch']   = $dataIn['fpx_buyerBankBranch'];
            $backendChecking['fpx_fpxTxnId']          = $dataIn['fpx_fpxTxnId'];
            $backendChecking['callBack']              = $dataIn['orgCallback'];
                       
            //doublce check fpx status after payment made
            $confirmFPXPayment = Self::checkFPXStatus($backendChecking);

            //use & to separate the data 10/10/2019
            $fpxCode = $confirmFPXPayment['data'];
            $fpxStatus = (explode("&",$fpxCode));

            //get provider id for set release credit 
            $db->where('name','fpx');
            $db->where('disabled','0');
            $providerID = $db->getValue('provider','id');

            //get auto release credit
            $db->where('provider_id',$providerID);
            $db->where('name','autoRelease');
            $autoReleaseSwitch = $db->getValue('provider_setting','value');

            if($backendChecking['fpx_debitAuthCode'] != "" && $backendChecking['callBack'] != "")
            {
                $db->where("purchase_id", $backendChecking['fpx_sellerOrderNo']);
                $db->where("type", "fpx");
                $result = $db->getOne('payment_gateway_details','id, purchase_id, call_back');

                if(!empty($result))
                { 
                    if($result['call_back'] != ""){
                        $callback = json_decode($result['call_back'],1);
                    }

                    $callback['AR'] = $backendChecking['callBack'];
                    $callback["AE"] = $confirmFPXPayment['data'];

                    unset($updateData);
                    $updateData = array(
                            "payment_type" => 'FPX',
                            "payment_date" => date("Y-m-d H:i:s"),
                            "payment_status" => $fpxStatus[19] == 'fpx_debitAuthCode=00' ? "Success" : "Failed",
                            "transaction_id" => $backendChecking['fpx_fpxTxnId'],
                            "call_back" => json_encode($callback),
                            "updated_at" => date("Y-m-d H:i:s"),
                            );
                            
                    $db->where('purchase_id',$backendChecking['fpx_sellerOrderNo']);
                    $updatePaymentGateway =  $db->update('payment_gateway_details',$updateData);

                        //$requeryChecking = $this->Requery($backendChecking, $isBackendChecking);
                        // print_r("fpxStatus:".json_encode($fpxStatus)."\n");

                        if($fpxStatus[19] == 'fpx_debitAuthCode=00' && $fpxStatus[4] == "fpx_creditAuthCode=00"){
                            $verifyRes = Self::requestVerifyPayment(array('purchase_id' => $backendChecking['fpx_sellerOrderNo']));

                            if($autoReleaseSwitch == 1){
                                $autoReleaseParams["purchase_id"] = $backendChecking['fpx_sellerOrderNo'];
                                $autoReleaseParams['actionBy'] = "System"; 

                                $autoReleaseParams["fpx_fpxTxnTime"] = $backendChecking['fpx_fpxTxnTime'];
                                $autoReleaseParams["fpx_txnAmount"] = $backendChecking['fpx_txnAmount'];
                                $autoReleaseParams["fpx_fpxTxnId"] = $backendChecking['fpx_fpxTxnId'];
                                $autoReleaseParams["fpx_buyerBankId"] = $backendChecking['fpx_buyerBankBranch'];
                                $autoReleaseParams["fpx_debitAuthCode"] = $backendChecking['fpx_debitAuthCode'];

                                $db->where('id', $dataIn['fpx_sellerOrderNo']);
                                $clientID  = $db->getValue("sale_order", "client_id");

                                $db->where('id', $clientID);
                                $clientDetails  = $db->getOne("client");

                                $find = array("%%name%%","%%username%%", "%%status%%", "%%ip%%", "%%location%%", "%%time%%");
                                $replace = array($clientDetails['name'], $clientDetails['username'], 'Payment Success', $ip,$ipInfo['country'],  date("Y-m-d H:i:s"));
                                $outputArray = Client::sendTelegramMessage('10024', NULL, NULL, $find, $replace,"","","telegramAdminGroup");

                                Self::verifyPayment($autoReleaseParams);
                                Self::soRelease($autoReleaseParams);
                            }
                        }else{
                                //get client id and client details 
                            $db->where('id', $backendChecking['fpx_sellerOrderNo']);
                            $getPurchaseDetails = $db->getOne('sale_order');

                            if($getPurchaseDetails)
                            {
                                $payment_method   = $getPurchaseDetails["payment_method"];
                            }
                            if(!empty($getPurchaseDetails))
                            {
                                $details['client_id'] = $getPurchaseDetails['client_id'];
                            }
                            
                            $db->where('id',$getPurchaseDetails['client_id']);
                            $client_info = $db->get('client',NULL, '');

                            // $client_info = client::getClientDetail($details['client_id']);
                            // $client_balance = $cash->getBalance($client_id,$client_info["currency"],"",false);

                            if($client_info[0]['activated'] != 1) {
                                return array ('status' => 'error1212' ,'code' => 1, 'statusMsg' => $translations["E00081"][$language], 'data' => ""); //Account not active yet. Please contact our support.
                            }

                            //client info
                            $client_phone         = $client_info[0]['phone'];
                            $client_email         = $client_info[0]['email'];
                            // $first_name           = $client_info["first_name"];
                            $last_name            = $client_info[0]["name"];
                            // $accountManager_id      = $client_info["accountManager_id"];
                            $sponsor_id           = $client_info[0]["sponsor_id"];
                            // $client_company         = $client_info["company"];
                            // $client_region_code = $client_info["regionCode"];
                            //release_currency = customer currency (to)
                            //purchase_currency = customer purchase currency (from)
                            $payment_currency    = "MYR"; // From
                            $release_currency    = "MYR"; // To

                                //get provider details
                                $db->where('name','FPX');
                                $db->where('type','topup');
                                $db->where('currency',$payment_currency);
                                $providerID = $db->getValue('provider','id');
                                
                                if($providerID)
                                {
                                    $provider_id = $providerID["provider_id"];
                                }

                                $db->where('id',$provider_id);
                                $getProvider = $db->getOne("provider");
                                
                                if($getProvider)
                                {
                                    $accountName = $getProvider["username"];
                                    $account = $getProvider["password"];
                                }

                                //display fpx bank name 
                                // $db->where('bank_id', $backendChecking['fpx_buyerBankId']);
                                // $db->where('disabled', 0);
                                // $db->where('deleted', 0);
                                // $db->where('provider_id', $providerID);
                                // $getBankName = $db->getValue('sms_fpx_bank_lists', 'bank_name');

                                // fpx details for invoice
                                $fpx_fpxTxnTime  = date("d-M-Y h:i:s A", strtotime($backendChecking['fpx_fpxTxnTime']));
                                $fpx_txnAmount  = $backendChecking['fpx_txnAmount'];
                                $fpx_fpxTxnId  = $backendChecking['fpx_fpxTxnId'];
                                $fpx_buyerBankId  = $backendChecking['fpx_buyerBankBranch'];
                                $fpx_debitAuthCode  = $backendChecking['fpx_debitAuthCode'];

                                if($fpx_debitAuthCode == 51)
                                {
                                    $fpx_debitAuthCode = 'Insufficient Fund';
                                }
                                else
                                {
                                    $fpx_debitAuthCode = 'Payment failed';
                                }

                                //get payment method
                                $db->where('purchase_id', $backendChecking['fpx_sellerOrderNo']);
                                $getPaymentMethod = $db->getValue('payment_gateway_details', 'type');

                                unset($updateData);
                                $updateData = array(
                                        // "status" => "Cancelled",
                                        "status" => "Failed",
                                        "remark" => $fpx_debitAuthCode,
                                        "updated_at" => date("Y-m-d H:i:s"),
                                        );

                                $db->where('id',$backendChecking['fpx_sellerOrderNo']);
                                $db->update('sale_order',$updateData);

                            // $getCurrency = $this->getCurrencyDetail();
                            // $getProviderDetails = $this->getProviderSetting();

                            $db->where('type', 'paymentMethodOption');
                            $paymentMethodLists = $db->get('enumerators', null, 'name, translation_code');

                            foreach($paymentMethodLists as $value)
                            {
                                // $payment_method = $value['name'];
                                $displayPaymentMethod[$value['name']] = $translations[$value['translation_code']]["english"];
                            }

                            $proforma_invoice_id = Self::newProformaInvoiceID();

                            $proforma_invoice_date = date("Y-m-d H:i:s");

                            $fpx_fpxTxnTime  = date("d-M-Y h:i:s A", strtotime($dataIn['fpx_fpxTxnTime']));

                            $payment_fee = 0;
                            $payment_tax = 0;
                        
                            $profomaSubtotal = $backendChecking['fpx_txnAmount'] - $payment_fee - $payment_tax; // for display purpose

                            // $ContentData = array (
                            //             "proforma_invoice_id" => $proforma_invoice_id,
                            //             "proforma_invoice_date" => strtotime($proforma_invoice_date),
                            //             "first_name" => $first_name,
                            //             "last_name" => $last_name,
                            //             "client_region_code" => $client_region_code,
                            //             "client_company" => $client_company,
                            //             "client_email" => $client_email,
                            //             "client_website" => $client_website,
                            //             "client_address1" => $client_address1,
                            //             "client_address2" => $client_address2,
                            //             "client_phone" => $client_phone,
                            //             "payment_method" => $displayPaymentMethod[$payment_method],
                            //             "accountName" => $accountName,
                            //             "companyName" => $setting->getCompanyDetails("companyName"),
                            //             "websiteName" => $setting->getCompanyDetails("websiteName"),
                            //             "companyAddress1" => $setting->getCompanyDetails("companyAddress1"),
                            //             "companyAddress2" => $setting->getCompanyDetails("companyAddress2"),
                            //             "companyAddress3" => $setting->getCompanyDetails("companyAddress3"),
                            //             "companyTel" =>  $setting->getCompanyDetails("companyTel"),
                            //             "companyFax" => $setting->getCompanyDetails("companyFax"),
                            //             "companyEmail" => $setting->getCompanyDetails("companyEmail"), 
                            //             "currency" => $getCurrency[$payment_currency]["symbol"],
                            //             "purchase_amount" => number_format($backendChecking['fpx_txnAmount'] ,$setting->systemSetting['displayDecimalPlaces'], ".", ","),
                            //             "payment_fee" => number_format($payment_fee,$setting->systemSetting['displayDecimalPlaces'], ".", ","),
                            //             "payment_tax" => number_format($payment_tax,$setting->systemSetting['displayDecimalPlaces'], ".", ","),
                            //             "release_amount" => number_format($profomaSubtotal,$setting->systemSetting['displayDecimalPlaces'], ".", ","),
                            //             "displayReleaseAmount" => number_format($release_amount),
                            //             "sysBankName" => $getProviderDetails[$provider_id]["displayName"],
                            //             "sysBankCountry" => $getProviderDetails[$provider_id]["accountCountry"],
                            //             "sysBankAcc" => $account,
                            //             "swiftCode" => $getProviderDetails[$provider_id]["swiftCode"],
                            //             "websiteURL" => $setting->getCompanyDetails("websiteURL"), 
                            //             "gstEnable" => $setting->getGstEnable(),
                            //             "remark" => '',
                            //             "refund" => '',
                            //             "gstID" => $setting->getCompanyDetails("gstID"),
                            //             "status" => 'Failed',
                            //             "systemDisplayName" => $setting->getCompanyDetails("systemDisplayName"),
                            //             "clientSendType" => $client_info['sendType'],
                            //             "release_currency" => $release_currency,
                            //             "fpx_fpxTxnTime"  => $fpx_fpxTxnTime,
                            //             "fpx_fpxTxnId"  => $fpx_fpxTxnId,
                            //             "fpx_buyerBankId"  => $fpx_buyerBankId,
                            //             "fpx_debitAuthCode"  => $fpx_debitAuthCode,
                            //             "getPaymentMethod" => $getPaymentMethod,
                            //             "fpx_sellerOrderNo" => $backendChecking['fpx_sellerOrderNo'],
                            //             );
                            
                            // // pdf
                            // $pdfFile = $this->pdfProformaInvoiceContent($ContentData);
                            // $proformaInvoiceRawFile = file_get_contents($pdfFile["path"]);

                            //insert uploads
                            $field = array("data","type","created_at",
                                    "file_type","file_name","deleted");
                            $value = array($proformaInvoiceRawFile,"purchasePdfProfomaInvoice",date("Y-m-d H:i:s"),
                                    "application/pdf",$proforma_invoice_id.".pdf","0");
                            $arrayData = array_combine($field, $value);  
                            $upload_id = $db->insert("uploads",$arrayData);            
                            unset($field,$value,$arrayData);
                            
                            //update sale table
                            unset($updatePurchaseData);
                            $updatePurchaseData = array(
                                    "proforma_invoice_id" => $proforma_invoice_id,
                                    "proforma_invoice_date" => $proforma_invoice_date,
                                    "proforma_invoice"      => $upload_id,
                                    "updated_at"        => date("Y-m-d H:i:s"),
                                    );

                            $db->where("id", $backendChecking['fpx_sellerOrderNo']);
                            $updateColumnAssignedDetails = $db->update('sale_order', $updatePurchaseData);     
            
                            $db->where('id',$upload_id);
                            $updateData = array('reference_id' => $backendChecking['fpx_sellerOrderNo']);
                            $updateUploadID = $db->update('uploads',$updateData);
            
                            unset($updateData);
                            $ContentData['upload_id'] = $upload_id;
                            // Email to client
                            // $emailToClient = Self::profomaInvoiceEmailContent($ContentData);

                            $db->where('id', $dataIn['fpx_sellerOrderNo']);
                            $clientID  = $db->getValue("sale_order", "client_id");

                            $db->where('id', $clientID);
                            $clientDetails  = $db->getOne("client");

                            $db->where("sale_id", $dataIn['fpx_sellerOrderNo']);
                            $db->where("client_id", $clientID);
                            $db->where("item_name", 'Redeemed Points');
                            $pointDetail = $db->getOne('sale_order_detail');

                            if($pointDetail){
                                unset($dataIn);
                                $dataIn['id']               = $clientID;
                                $dataIn['creditType']       = 'bonusDef';
                                $dataIn['adjustmentType']   = 'Adjustment In';
                                $dataIn['adjustmentAmount'] = $pointDetail['quantity'];
                                $dataIn['remark']           = 'Refund Points';
                                // $dataIn['isMember']         = '1';
                                $creditOutput = Wallet::creditAdjustment($dataIn);
                            }

                            $find = array("%%name%%","%%username%%", "%%status%%", "%%ip%%", "%%location%%", "%%time%%");
                            $replace = array($clientDetails['name'], $clientDetails['username'], 'Payment Failed', $ip,$ipInfo['country'],  date("Y-m-d H:i:s"));
                            $outputArray = Client::sendTelegramMessage('10024', NULL, NULL, $find, $replace,"","","telegramAdminGroup");

                            return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $dataIn['errorMessage'], 'data' => $pdfFile); 
                        }
                }
                return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["E00001"][$language], 'data' => '');
            }
            else
            {
                return array ('status' => 'error' ,'code' => 1, 'test' => '4','statusMsg' => $translations["BE00013"][$language], 'data' => '');
            }
        }

        public function requestVerifyPayment($dataIn){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $userType = $db->userType;
  
            $purchase_id = trim((string)$dataIn["purchase_id"]);
            
  
            $isFromAllClient = $dataIn['isFromAllClient'] !== "" ? $dataIn['isFromAllClient'] : 0 ;
  
            $notification = (string)$dataIn["notification"] !== "" ? (string)$dataIn["notification"] : 0;
  
            // $payslip = $dataIn["payslip"];
            // if($payslip == ""){
            //   return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["M00009"][$language], 'data'=>""); //Please upload payslip for our team to verify your payment.
            // }else{
  
                // $payslipRawFile = file_get_contents($payslip);
  
                $db->where('id', $purchase_id);
                $status = $db->getValue('sale_order','status');
                
                if($status == 'Pending Payment Approve'){
                    $status = "Payment Verifying";
                    $fields = array("request_verify_at","updated_at","status","seen",);
                    $value = array(date("Y-m-d H:i:s"),date("Y-m-d H:i:s"),$status, "0",); //Payment Verifying
                    $arrayData = array_combine($fields, $value);
                    $db->where('id', $purchase_id);
                    $res = $db->update("sale_order", $arrayData);
                    unset($res);
                }
  
            //   if($userType !== "Admin" || $isFromAllClient){
            //     $listingData = $this->getPurchaseHistoryList($dataIn["listingData"]);
            //   }else{
            //     $listingData = $this->getClientPurchaseList($dataIn["listingData"]);
            //   }
             
            return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["B00523"][$language], 'data' => ""); //Completed successfully.
            
        }

        function verifyPayment($dataIn){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $userType = $db->userType;

            $adminID = $dataIn['adminID'] != "" ? $dataIn['adminID'] : $db->userID;
            $actionByTemp = $dataIn['actionBy'] != "" ? $dataIn['actionBy'] : "";
    
            $purchase_id = (string)$dataIn['purchase_id'];  
            $payment_fee = (string)$dataIn['payment_fee'] != "" ? (string)$dataIn['payment_fee']  : 0; // *paypal use
            $paypal_details_id = (string)$dataIn['paypal_details_id'] != "" ? (string)$dataIn['paypal_details_id']  : 0; // *paypal use
            $paypal_payment_currency = (string)$dataIn['payment_currency']; // paypal use
            
            $notification = (string)$dataIn['notification'];
            // $notification = 1;
            $reason = (string)$dataIn['reason']; // paypal use
            $isFromAllClient = $dataIn['isFromAllClient'] !== "" ? $dataIn['isFromAllClient'] : 0 ;
                
            $db->where("id",$purchase_id);
            $res = $db->getOne("sale_order");

            if($res){
                $package_id           = $res["package_id"];
                $payment_currency     = $res["payment_currency"];
                $release_currency     = $res["release_currency"];
                $status               = $res["status"];
                $payment_amount       = $res["payment_amount"];
                $release_amount       = $res["release_amount"];
                $client_id            = $res["client_id"];
                $payment_method       = $res["payment_method"];
                $released             = $res["released"];
                $proformaInvoiceID    = $res["proforma_invoice_id"];
    
                if($status == "Paid")
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00087"][$language], 'data' => ""); //Credits already released.
                  else if ($status == "Payment Verify Failed")
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00088"][$language], 'data' => ""); //Payment verification failed.
                  else if ($status == "Cancelled")
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00089"][$language], 'data' => ""); //Payment cancelled.
                  else if ($status == "Wating for Payment")
                     return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00319"][$language], 'data' => ""); //The payment is not made. Not allow to verify.
                if($paypal_payment_currency != "" && ($payment_currency != $paypal_payment_currency || $release_currency != $paypal_payment_currency)) return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00097"][$language], 'data' => ""); //Payment currency not match.
    
                //update purchase details
                // $fields = array("invoice_id", "invoice_date", "updated_at",
                // "status", "credit_release_at", "invoice", "seen", "released");
                //  $values = array($invoice_id,$invoice_date,date("Y-m-d H:i:s"),
                //              $status,date("Y-m-d H:i:s"),$upload_id,"0",1);
                //  $arrayData = array_combine($fields, $values);
                //  $db->where("id",$purchase_id);
                //  $res = $db->update("sale_order", $arrayData);

                if($status == "Payment Verifying"){
                  $status = "Payment Verified";
    
                  if($released == 1) $status = "Paid";
    
                  $fields = array("verified", "status", "paypal_details_id","payment_verified_at");
                  $values = array("1",$status, $paypal_details_id, date('Y-m-d H:i:s')); //Payment Verified
                  $arrayData = array_combine($fields, $values);
                  $db->where("id",$purchase_id);
                  $db->where("status",'Payment Verifying');
                  $result = $db->update("sale_order",$arrayData);
    
                  unset($result,$arrayData);
                  if($paypal_details_id !== ""){
                  $fields = array("reason",);
                  $values = array($reason,);
                  $arrayData = array_combine($fields, $values);
                  $db->where('id',$paypal_details_id);
                  $result = $db->update("payment_gateway_details",$arrayData);

                }
                
              }
    
                // if($userType !== "Admin" || $isFromAllClient){
                //   $listingData = $this->getPurchaseHistoryList($dataIn["listingData"]);
                // }else{
                //   $listingData = $this->getClientPurchaseList($dataIn["listingData"]);
                // }
                
                return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["B00523"][$language], 'data' => ''); //Completed successfully.
            }else{
                   return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["B00524"][$language], 'data' => ""); // Payment not found.
                }
        }

        function soRelease($dataIn){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $userType = $db->userType;
    
            $purchase_id  = $dataIn['purchase_id'];
            $ticket_id    = $dataIn['ticket_id'];

            //get payment type 
            $db->where('purchase_id', $purchase_id);
            $getPaymentType = $db->getValue('payment_gateway_details', 'type');
    
            // fpx details for invoice
            $fpx_fpxTxnTime  = date("d-M-Y h:i:s A", strtotime($dataIn['fpx_fpxTxnTime']));
            $fpx_txnAmount  = $dataIn['fpx_txnAmount'];
            $fpx_fpxTxnId  = $dataIn['fpx_fpxTxnId'];
            $fpx_buyerBankId  = $dataIn['fpx_buyerBankId'];
            $fpx_debitAuthCode  = $dataIn['fpx_debitAuthCode'];
    
            //assign status 
            if($fpx_debitAuthCode == 00)
            {
                $fpx_debitAuthCode = 'Successful';
            }
            
            $notification = $dataIn['notification'];
            $isFromAllClient = $dataIn['isFromAllClient'] !== "" ? $dataIn['isFromAllClient'] : 0 ; 
    
            $adminID = $dataIn['adminID'] != "" ? $dataIn['adminID'] : $db->userID ;
            $actionByTemp = $dataIn['actionBy'] != "" ? $dataIn['actionBy'] : "";  
    
            $db->where("id",$purchase_id);
            $db->where("status", "Processing", "!=");
            $res = $db->getOne("sale_order");
            
            if($res){
                $totalAmount = $res["release_amount"];
                $description = 'Shopping ('.$res["payment_currency"].' '.$res["payment_amount"].')';
    
                $release_currency = $res["release_currency"];
        
                $payment_currency = $res["payment_currency"];
                $cryptocurrency   = $res["cryptocurrency"];
                $cryptocurrency_amount = $res["cryptocurrency_amount"];
                $release_amount   = $res["release_amount"];
                $payment_method   = $res["payment_method"];
                $invoice_id       = $res["invoice_id"];
                $invoice_date     = date("Y-m-d H:i:s");
                $payment_amount   = $res["payment_amount"];
                $payment_amount_ori   = $res["payment_amount"];
                $payment_fee      = $res["payment_fee"];
                $payment_tax      = $res["payment_tax"];
                $status           = $res["status"];
                $verified         = $res["verified"];
                $refund           = $res["refund"];
                $remark           = $res["remark"];
                $package_id       = $res["package_id"];    
                $released         = $res["released"];
                $proformaInvoiceID  = $res['proforma_invoice_id'];
                $discountAmount   = $res["discount_amount"];
                $isPromo          = $res['promotion'] == 1 ? 1 : 0;
                $promoCode        = $res['promotion_code'];
                $redeemAmount     = $res['redeem_amount'];
                $isRedeemReward   = 0;
        
                if($res['redeem_amount'] > 0){
                    $isRedeemReward = 1;
                }
        
                #### PROMOTION #####
        
                $isPromo      = $res['promotion'] == 1 ? 1 : 0;
                $promoCode    = $res['promotion_code'] != "" ? $res['promotion_code'] : "";
                $getCurrency      = "MYR";
        
                $client_id          = $res["client_id"];

                $db->where('id',$client_id);
                $client_info = $db->getOne("client");

                // $first_name         = $client_info["first_name"];
                $last_name          = $client_info["name"];
                $client_email       = $client_info["email"];
                // $client_website     = $client_info["companyWebsite"];
                $client_address1    = $client_info["address"];
                // $client_address2    = $client_info["address2"];
                $sponsor_id         = $client_info["sponsor_id"];
                // $client_company     = $client_info["company"];
                // $client_region_code = $client_info["regionCode"];
                $client_phone       = $client_info["phone"];
        
                $db->where('name',"paymentMethod");
                $db->where('value',$payment_method);
                $db->where('type',"MYR");
                $provider_id = $db->getValue("provider_setting","provider_id");
                
                $db->where('id',$provider_id);
                $getProvider = $db->getOne("provider");
                if($getProvider){
                    $accountName = $getProvider["username"];
                    $account = $getProvider["password"];
                } 
    
                $getProviderDetails = Self::getProviderSetting();
                
                $db->where('type', 'paymentMethodOption');
                $paymentMethodLists = $db->get('enumerators', NULL, 'name, translation_code');
    
                if(!empty($paymentMethodLists))
                {
    
                  foreach($paymentMethodLists as $value)
                  {
                      // $payment_method = $value['name'];
                      $displayPaymentMethod[$value['name']] = $translations[$value['translation_code']]["english"];
                  }
                }

              if($refund == 1){
                $res['listingData'] = $dataIn['listingData'];
                $refundRes = $this->refundPayment($res,$isFromAllClient);
    
                return $refundRes;
    
              }else{
                if($status == "Paid")
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00087"][$language], 'data' => ""); //Credits already released.
                else if ($status == "Payment Verify Failed")
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00088"][$language], 'data' => ""); //Payment verification failed.
                else if ($status == "Cancelled")
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00089"][$language], 'data' => ""); //Payment cancelled.
                else if ($status == "Payment Verifying")
                    return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00105"][$language], 'data' => ""); //Payment not verified.
                else if ($status == "Payment Verified" && $verified == "1" && $released == 0){
                    if($invoice_id == "" && !$cryptocurrency) $invoice_id = Self::newInvoiceID();
                    
                    $tempUpdateStatus = array("status" => "Processing");
                    $db->where("id",$purchase_id);
                    $db->update("sale_order", $tempUpdateStatus);

                    unset($tempUpdateStatus);
                    $status = "Paid";

                    if($payment_method == "ipay88" && $payment_amount <= 0){
                        $sysBankName = "-";
                        $account = "-";
                        $swiftCode = "-";
                        $accountName = "-";
                        $sysBankCountry = "-";
                    }else{
                        $sysBankName = $getProviderDetails[$provider_id]["displayName"];
                        $swiftCode = $getProviderDetails[$provider_id]["swiftCode"];
                        $sysBankCountry = $getProviderDetails[$provider_id]["accountCountry"];
                    }
                    
                    $format = 2;
                    $Invoicesubtotal = $payment_amount - $payment_fee - $payment_tax + $discountAmount + $redeemAmount; // for display purpose
                    
                    $ContentData = array (
                        "invoice_id" => $invoice_id,
                        "invoice_date" => strtotime($invoice_date),
                        "payment_method" => $displayPaymentMethod[$payment_method],
                        "first_name" => $first_name,
                        "last_name" => $last_name,
                        "client_address1" => $client_address1,
                        "client_address2" => $client_address2,
                        "client_email" => $client_email,
                        "client_website" => $client_website,
                        "client_company" => $client_company,
                        "client_phone" => $client_phone,
                        "client_region_code" => $client_region_code,
                        // "systemDisplayName" => $setting->getCompanyDetails("systemDisplayName"),
                        // "companyName" => $setting->getCompanyDetails("companyName"),
                        // "websiteName" => $setting->getCompanyDetails("websiteName"),
                        // "companyAddress1" => $setting->getCompanyDetails("companyAddress1"),
                        // "companyAddress2" => $setting->getCompanyDetails("companyAddress2"),
                        // "companyAddress3" => $setting->getCompanyDetails("companyAddress3"),
                        // "companyTel" =>  $setting->getCompanyDetails("companyTel"),
                        // "companyFax" => $setting->getCompanyDetails("companyFax"),
                        // "companyEmail" => $setting->getCompanyDetails("companyEmail"), 
                        "currency" => "MYR",
                        "client_currency" => $payment_currency,
                        "purchase_amount" => number_format($payment_amount,$format),
                        "payment_tax" => number_format($payment_tax,$format),
                        "payment_fee" => number_format($payment_fee,$format),
                        "release_amount" => number_format($Invoicesubtotal,$format),
                        "displayReleaseAmount" => number_format($release_amount, 2),
                        // "websiteURL" => $setting->getCompanyDetails("websiteURL"), 
                        // "gstEnable" => $setting->getGstEnable(),
                        "remark" => $remark,
                        "refund" => $refund,
                        // "gstID" => $setting->getCompanyDetails("gstID"),
                        "status" => $status,
                        "sysBankName" => $sysBankName,
                        "sysBankAcc" => $account,
                        "swiftCode" => $swiftCode,
                        "accountName" =>  $accountName,
                        "sysBankCountry" => $sysBankCountry,
                        "release_currency" => $release_currency,
                        "getPaymentType" => $getPaymentType,
                        "fpx_fpxTxnTime" => $fpx_fpxTxnTime,
                        "fpx_fpxTxnId" => $fpx_fpxTxnId,
                        "fpx_buyerBankId" => $fpx_buyerBankId,
                        "fpx_debitAuthCode" => $fpx_debitAuthCode,
                        "purchase_id"      => $purchase_id,
                        "isPromo" => $isPromo,
                        "promoCode" => $promoCode,
                        "discountAmount" => number_format($discountAmount,$format),
                        "isRedeemReward" => $isRedeemReward,
                        "redeemAmount" => number_format($redeemAmount,$format),
                    );

                    // $pdfFile = Self::pdfInvoiceContent($ContentData);
                    // $invoiceRawFile = file_get_contents($pdfFile["path"]);

                    //insert into upload
                    $fields = array("data","type","created_at",
                                "reference_id","file_type","file_name","deleted");
                    $values = array($invoiceRawFile,"purchasePdfInvoice",date("Y-m-d H:i:s"),
                                    $purchase_id,"application/pdf",$invoice_id.".pdf","0");
                    $arrayData = array_combine($fields, $values);  
                    $upload_id = $db->insert("uploads",$arrayData);

                    //update purchase details
                    $fields = array("invoice_id", "invoice_date", "updated_at",
                                "status", "credit_release_at", "invoice", "seen", "released");
                    $values = array($invoice_id,$invoice_date,date("Y-m-d H:i:s"),
                                $status,date("Y-m-d H:i:s"),$upload_id,"0",1);
                    $arrayData = array_combine($fields, $values);
                    $db->where("id",$purchase_id);
                    $res = $db->update("sale_order", $arrayData);

                    unset($fields,$values,$arrayData,$res);

                    $db->where('id',$adminID);
                    $adminName = $db->getValue('admin','email');

                    $actionBy = trim($actionByTemp) != "" ? $actionByTemp : $adminName;

                    $accStatus = "Active";
                    if ($client_info['disabled']){
                        $accStatus = "Disabled";
                    }else if ($client_info['suspended']){
                        $accStatus = "Suspended";
                    }else if ($client_info['freezed']){
                        $accStatus = "Freezed";
                    }

                    $accSendType = "";
                    $isSensitiveAccountXun = "No";
                    if($client_info['isSensitiveAccount'] == 1){
                    $isSensitiveAccountXun = "Yes";
                    }

                    if(isset($client_info['sendType']) && $client_info['sendType'] != ""){
                    $accSendType = ucfirst(strtolower($client_info['sendType']));
                    }else{
                    $accSendType = "Default";
                    }

                    $clientName = $client_info['first_name']." ".$client_info['last_name'];
                    // $clientBalance = number_format($general->standardUnit($cash->getBalance($client_id,$client_info['currency'],"",false)),$setting->systemSetting['displayDecimalPlaces']);

                    if($cryptocurrency){
                    $payment_amount = $payment_amount_ori;
                    }

                    if($redeemAmount > 0){
                    $paymentDeductedFee = $payment_amount - $payment_fee + $redeemAmount;
                    }
                    else{
                    $paymentDeductedFee = $payment_amount - $payment_fee;
                    }

                    $paymentDeductedFee = number_format($paymentDeductedFee,$setting->systemSetting['displayDecimalPlaces']);
                    $payment_amount = number_format($payment_amount,$setting->systemSetting['displayDecimalPlaces']);
                    $release_amount = number_format($release_amount,$setting->systemSetting['displayDecimalPlaces']);
                
    
                    // $db->where("id",$purchase_id);
                    // $db->where("remark", 'Special Offer');
                    // $specialOfferChecked = $db->getOne("sms_purchase", 'client_id');
    
                    // if(!empty($specialOfferChecked))
                    // {
                    //     $xunTitle = "Special Offer";
                    // }
                    // else
                    // {
                    //     $xunTitle = NULL;
                    // }
    
                    $tempUpdateStatus = array("disabled" => 1);
            
                    $db->where("client_id",$client_id);
                    $result = $db->update("shopping_cart", $tempUpdateStatus);
    
                    $test = 0;
                    //insert new record into sms_reward when client purchase reward package
                    $db->where('name', 'reward_package_id');
                    $getRewardPackageId = $db->getValue('system_settings', 'value');
    
                    if(!empty($getRewardPackageId))
                    {
                        $test = 1;
                        $db->where('name', 'reward_package');
                        $getRewardPackage = $db->getValue('system_settings', 'value');
    
                        $db->where('name', 'reward_amount');
                        $getRewardPackageAmount = $db->getValue('system_settings', 'value');
    
    
                        if($package_id == $getRewardPackageId)
                        {
                            $test = 2;
                            // $rewardAmount = ($payment_amount) * ($getRewardPackageAmount/100);
                            $rewardAmount = ($paymentDeductedFee) * ($getRewardPackageAmount/100);
                            //insert reward package details into sms_reward
                            $today = date('Y-m-d');
                            // $today = date("Y-m-d",strtotime("-1 days"));
    
                            ### CHECK THENUX USER START ###
                            $db->where('client_id', $client_id);
                            $db->where('name', 'theNuxUser');
                            $checkTheNuxUser = $db->getOne('client_setting', 'value, reference');
    
                            if(!empty($checkTheNuxUser)){
    
                                if($checkTheNuxUser['value'] == "1" && $checkTheNuxUser['reference'] == "register"){
                                    $totalReceivedRewardAmount = $rewardAmount;
                                    $updatedStatus = "Pending";
                                    $isOfflineReward = 0;
    
                                }else{
    
                                    if($checkTheNuxUser['value'] == "1"){
                                        //Convert Register TheNux Date to Y-m-d Format
                                        $registeredDate = $checkTheNuxUser['reference'];
                                        if(!empty($registeredDate)){
                                            $createDate = new DateTime($registeredDate);
                                            $convertRegisteredDate = $createDate->format('Y-m-d');
                                        }                  
                                    }
                                    
                                    ## THE NUX USER THAT REGISTER THE NEXT DAY
                                    if(($checkTheNuxUser['value'] == 1 && $convertRegisteredDate > $today) || $checkTheNuxUser['value'] == 0){
    
                                        //Get system maximum offline reward amount
                                        $db->where('name','maxOfflineReward');
                                        $maxOfflineReward = $db->getValue('system_settings','value');
                                                               
                                        $balance = $cash->getBalance($client_id,"sms123rewardsOffline","",false);
    
                                        if($balance < $maxOfflineReward){
                                            $amountQuotaLeft = $maxOfflineReward - $balance;
    
                                            if($amountQuotaLeft >= $rewardAmount)  {
                                                $totalReceivedRewardAmount = $rewardAmount;
                                            }else{
                                                $totalReceivedRewardAmount = $amountQuotaLeft;
                                            }
    
                                        }else{
                                            $totalReceivedRewardAmount = 0;
                                        }
    
                                        $updatedStatus = "TopUp Unregistered";
                                        $isOfflineReward = 1;
    
                                    }else{
                                        $totalReceivedRewardAmount = $rewardAmount;
                                        $updatedStatus = "Pending";
                                        $isOfflineReward = 0;
                                    }
                                }
                                
                            }else{
                                //Get system maximum offline reward amount
                                $db->where('name','maxOfflineReward');
                                $maxOfflineReward = $db->getValue('system_settings','value');
                                                       
                                $balance = $cash->getBalance($client_id,"sms123rewardsOffline","",false);
    
                                if($balance < $maxOfflineReward){
                                    $amountQuotaLeft = $maxOfflineReward - $balance;
    
                                    if($amountQuotaLeft >= $rewardAmount)  {
                                        $totalReceivedRewardAmount = $rewardAmount;
                                    }else{
                                        $totalReceivedRewardAmount = $amountQuotaLeft;
                                    }
    
                                }else{
                                    $totalReceivedRewardAmount = 0;
                                }
                                
                                $updatedStatus = "TopUp Unregistered";
                                $isOfflineReward = 1;
                            }
                            ### CHECK THENUX USER END ###
    
                            if($updatedStatus == "TopUp Unregistered"){
                                $test = 6;
    
                                //insert new record into sms_reward
                                unset($smsRewardData);
                                unset($insertSmsReward);
                                $batchID = $db->getNewID();
    
                                $smsRewardData = array (
                                                    "client_id" => $client_id,
                                                    "phone" => $client_phone,
                                                    "date" => $today,
                                                    "reward_amount" => $rewardAmount,
                                                    "received_reward_amount" => $totalReceivedRewardAmount,
                                                    "status" => $updatedStatus,
                                                    "is_offline_reward" => $isOfflineReward,
                                                    "batch_id" => $batchID,
                                                    "updated_at" => date("Y-m-d H:i:s"),                            
                                                    "created_at" => date("Y-m-d H:i:s"),                
                                                );  
                                $insertSmsReward = $db->insert('sms_reward', $smsRewardData);
    
                                //Get payout account ID
                                $db->where('username','payout');
                                $accountID = $db->getValue("client","id");
    
                                //InsertTAccount for offline reward
                                $currencyType = "sms123rewardsOffline";
                                
                                $belongID = $db->getNewID();
                                $cash->insertTAccount($accountID,$client_id,$currencyType,$totalReceivedRewardAmount,"Store Offline Reward",$belongID,"",date("Y-m-d H:i:s"),$batchID,$client_id);
                                $cash->getBalance($client_id,"sms123rewardsOffline","",true);
    
                            }else{ //$updatedStatus == "Pending"
    
                                $db->where('client_id', $client_id);
                                $db->where('date', $today);
                                $db->where('status', $updatedStatus);
                                $checkExistSmsReward = $db->getOne('sms_reward', 'id, reward_amount');
    
                                if(empty($checkExistSmsReward))
                                {
                                    $test = 3;
                                    //insert new record into sms_reward
                                    unset($smsRewardData);
                                    unset($insertSmsReward);
    
                                    $smsRewardData = array (
                                                        "client_id" => $client_id,
                                                        "phone" => $client_phone,
                                                        "date" => $today,
                                                        "reward_amount" => $rewardAmount,
                                                        "received_reward_amount" => $totalReceivedRewardAmount,
                                                        "status" => $updatedStatus,
                                                        "is_offline_reward" => $isOfflineReward, 
                                                        "updated_at" => date("Y-m-d H:i:s"),                            
                                                        "created_at" => date("Y-m-d H:i:s"),                
                                                    );  
                                    $insertSmsReward = $db->insert('sms_reward', $smsRewardData);
                                    $getLastQuery = $db->getLastQuery();
    
                                    if(!$insertSmsReward)
                                    {
                                        return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["E00001"][$language], 'data' => $listingData['data'], 'test' => $getLastQuery); //Completed successfully.
                                    }
    
                                }   
                                else
                                {
                                    $test = 4;
                                    #### UPDATE 
                                    unset($insertSmsReward);
                                    $db->rawQuery("UPDATE sms_reward SET reward_amount = reward_amount + ".$rewardAmount.", received_reward_amount = received_reward_amount + ".$totalReceivedRewardAmount.", updated_at = '".date('Y-m-d H:i:s')."' WHERE id='".$checkExistSmsReward['id']."'");
    
    
                                }
                            }
    
                            $xunTitle = "Reward Package";
                        }
                        else
                        {
                            $test = 5;    
                            $xunTitle = NULL;
                        }
                    }
                      
                     $ContentData['upload_id'] = $upload_id;
    
                     ##### email to client #####
                    //  $emailToClient = $this->invoiceEmailContent($ContentData);
                     ##### email to client #####
    
                    ############# Credit Usage ################
                    // $description = 'Top Up';
                    // $totalAmount = $res["release_amount"];
                    $usageReferenceID = $purchase_id;
                    $usageType = 'debit';
                    $usageCurrency = $client_info['currency'];
                    // $client->insertCreditUsage($client_id, $description, $totalAmount, $usageCurrency, $usageReferenceID, $usageType);
    
                    ########################################
    
                    
                    // if($userType !== "Admin" || $isFromAllClient){
                    //   $listingData = $this->getPurchaseHistoryList($dataIn["listingData"]);
                    // }else{
                    //   $listingData = $this->getClientPurchaseList($dataIn["listingData"]);
                    // }
                   
                    return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["E00001"][$language], 'data' => $listingData['data'], 'test' => $test); //Completed successfully.
                }
    
              }
            }else{
                   return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["E00084"][$language], 'data' => ""); // Payment not found.
                }
    
        }

        function iPay88_signature($dataIn){

            return hash('sha256', $dataIn);
        } 

        public function fpxPaymentVerify($dataIn){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $db->where('id', $dataIn['purchaseID']);
            $saleOrderDetail = $db->getOne('sale_order');

            $fpxDetails['client_phone'] = $dataIn['client_phone'];
            $fpxDetails['client_email'] = $dataIn['client_email'];
            $fpxDetails['first_name']   = $dataIn['first_name'];
            $fpxDetails['payment_amount'] = $dataIn['paymentAmount'];
            $fpxDetails['payment_currency'] = $dataIn['paymentCurrency'];
            $fpxDetails['purchaseID']   = $dataIn['purchaseID'];
            $fpxDetails['txnTime']   = $dataIn['txnTime'];

            $fpxDetails['paymentId']    = ""; 
            $fpxDetails['remark']       = ""; 
            $fpxDetails['Lang']         = "UTF-8";
            $fpxDetails['SignatureType']= "SHA256"; 
            $error = 0;
            $errorMessage = "Payment Failed";

            $clientID     = $dataIn['clientID'];
            // $clientDetail = $client->getClientDetail($clientID);

            // $db->where('currency', $dataIn['paymentCurrency']);
            // $db->where('type','topup');
            // $db->where('send_type','fpx');
            // $providerID = $db->getValue('provider','id');

            // unset($fpxList);
            // $db->where('provider_id', $providerID);
            // $fpxList = $db->get('provider_setting',null, 'name, value');

            if(!empty($fpxList))
            {
                foreach($fpxList as $key => $value)
                {
                    $fpxDetails[$value['name']] = $value['value'];
                }
            }
            $paymentAmountSignature = $fpxDetails['payment_amount'] * 100;
            // $paymentAmountSignature = 2 * 100;

            $signatureTemp = $fpxDetails['merchantKey'].$fpxDetails['merchantCode'].$fpxDetails['purchaseID'].$paymentAmountSignature.$fpxDetails['payment_currency'];

            $signature = Self::iPay88_signature($signatureTemp);
            $fpxDetails['payment_amount'] = number_format($fpxDetails['payment_amount'],2,".", ",");
            $fpxDetails['Signature']= $signature; 

            $data = $fpxDetails;
            $created_at = date('Y-m-d H:i:s');
            unset($insertData);
            $insertData = array(
                            "purchase_id" => $fpxDetails['purchaseID'],
                            "type" => "fpx",
                            "buyer_id" => $clientID,
                            "buyer_email" => $fpxDetails['client_email'],
                            "seller_email" => "",
                            "currency" => $fpxDetails['payment_currency'],
                            "purchase_amount" => $saleOrderDetail['payment_amount'],
                            "tax" => "0",
                            "transaction_id" => $signature,
                            "payment_fee" => 0,
                            "payment_type" => "FPX",
                            "payment_date" => "",
                            "payment_status" => "",
                            "paypal_verify_status" => "",
                            "reason" => $signatureTemp,
                            "call_back" => "",
                            "updated_at" => date('Y-m-d H:i:s'),
                            "created_at" => $fpxDetails['txnTime'],
                            );
                    
            $db->insert('payment_gateway_details',$insertData);

            $tempUpdateStatus = array("disabled" => 1);
            $db->where("sale_id", $fpxDetails['purchaseID']);
            $result = $db->update("shopping_cart", $tempUpdateStatus);

            return array('status' => "ok", 'code' => 0, 'statusMsg' => $translations["E00001"][$language], 'data' => $data, 'signature' => $signatureTemp, 'signatureKey' => $fpxDetails['merchantKey']); // Completed Successfully.
        }


	public function getFullAddress($address_id) {

            $db = MysqliDb::getInstance();

	    $db->where("a.id", $address_id);	
	    $db->join("state s", "s.id=a.state_id and s.country_id=a.country_id", "LEFT");
	    $db->join("country c", "c.id=a.country_id", "LEFT");
	    $addressResult = $db->getOne("address a", "a.name, a.phone, a.address, a.address_2, a.post_code, a.city, s.name as state_name, c.name as country_name, a.email as email, a.address_type as type, a.state_id");
	
	    $fullAdress = "";
	    $name = "";
	    $phone = "";
        $email = "";

	    if($addressResult) {
            $name = $addressResult["name"];
            $phone = $addressResult["phone"];
            $email = $addressResult["email"];
            $address = $addressResult["address"];
            $address2 = $addressResult['address_2'];
            $post_code = $addressResult["post_code"];
            $state_id = $addressResult["state_id"];
            $city = $addressResult["city"];
	    } 

	    return array("name"=>$name, "phone"=>$phone, "email"=>$email, "address" => $address, "address_2" => $address2, "post_code" => $post_code, "state_id" => $state_id, "city"=> $city);
    }

    public function concateAddress($ID, $type)
{
    $db = MysqliDb::getInstance();
    $fullAddress = '';

    if ($type == 'address') {
        $db->where("a.id", $ID);
        $db->join("state s", "s.id=a.state_id and s.country_id=a.country_id", "LEFT");
        $db->join("country c", "c.id=a.country_id", "LEFT");
        $addressResult = $db->getOne("address a", "a.name, a.phone, a.address, a.address_2, a.post_code, a.city, s.name as state_name, c.name as country_name, a.email as email, a.address_type as type, a.state_id");

        if ($addressResult) {
            $fullAddress = $addressResult["address"];

            if ($addressResult["address_2"]) {
                $fullAddress .= "\n" . $addressResult["address_2"];
            }

            if ($addressResult["post_code"] && $addressResult["city"]) {
                $fullAddress .= "\n" . $addressResult["post_code"] . " " . $addressResult["city"];
            }

            if ($addressResult["state_name"]) {
                $fullAddress .= "\n" . $addressResult["state_name"];
            }

            if ($addressResult["country_name"]) {
                $fullAddress .= "\n" . $addressResult["country_name"];
            }
        }
    } elseif ($type == 'sale_order_billing') {
        $db->where("so.id", $ID);
        $db->join("state s", "s.id=so.billing_state_id", "LEFT");
        $addressResult = $db->getOne("sale_order so", "so.billing_address, so.billing_address2, so.billing_post_code, so.billing_city, s.name as state_name");
        if ($addressResult) {
            $fullAddress = $addressResult["billing_address"];

            if ($addressResult["billing_address2"]) {
                $fullAddress .= "\n" . $addressResult["billing_address2"];
            }

            if ($addressResult["billing_post_code"] && $addressResult["billing_city"]) {
                $fullAddress .= "\n" . $addressResult["billing_post_code"] . " " . $addressResult["billing_city"];
            }

            if ($addressResult["state_name"]) {
                $fullAddress .= "\n" . $addressResult["state_name"];
            }
        }
    } elseif ($type == 'sale_order_shipping') {
        $db->where("so.id", $ID);
        $db->join("state s", "s.id=so.shipping_state_id", "LEFT");
        $addressResult = $db->getOne("sale_order so", "so.shipping_address, so.shipping_address2, so.shipping_post_code, so.shipping_city, s.name as state_name");
        if ($addressResult) {
            $fullAddress = $addressResult["shipping_address"];

            if ($addressResult["shipping_address2"]) {
                $fullAddress .= "\n" . $addressResult["shipping_address2"];
            }

            if ($addressResult["shipping_post_code"] && $addressResult["shipping_city"]) {
                $fullAddress .= "\n" . $addressResult["shipping_post_code"] . " " . $addressResult["shipping_city"];
            }

            if ($addressResult["state_name"]) {
                $fullAddress .= "\n" . $addressResult["state_name"];
            }
        }
    }

    return $fullAddress;
}




        public function addNewPayment($dataIn, $userID){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $deliveryMethod = trim($dataIn['deliveryMethod']);
            $bkendToken     = $dataIn['bkend_token'];
            $promoCode   = trim($dataIn['promo_code']);
            $redeemAmount = $dataIn['redeemAmount'];
            $billing_address = $dataIn['billing_address'];
            $shipping_address = $dataIn['shipping_address'];
            $is_gift = $dataIn['is_gift'] ?? 0;

            $payment_fee = 0;
            $payment_tax = 0;
            $release_amount = 0;
            $paypal_details_id = "";	

            $client_id = $userID;


            // get user shipping info 
            if(strtolower($deliveryMethod) != 'pickup')
            {
                if(empty($billing_address))
                {
                    return array('status' => "error", 'code' => 1, 'statusMsg' =>$translations["E01191"][$language] /* Please add new billing and shipping address before you proceed checkout */, 'data' => '');
                }
    
                if(empty($shipping_address))
                {
                    return array('status' => "error", 'code' => 1, 'statusMsg' =>$translations["E01191"][$language] /* Please add new billing and shipping address before you proceed checkout */, 'data' => '');
                }

                $shippingDetail = Cash::getFullAddress($shipping_address);  
                $shipname = $shippingDetail["name"];
                $shipphone = $shippingDetail["phone"];
                $shipAddress = $shippingDetail["address"] ?? "-";
                $shipAddress2 = $shippingDetail["address_2"] ?? "-";
                $shipPost_code = $shippingDetail["post_code"] ?? "-";
                $shipState_id = $shippingDetail["state_id"] ?? "-";
                $shipCity = $shippingDetail["city"] ?? "-";
                $shipemail = $shippingDetail["email"] ?? "-";
            }

            ## get shipping address info 
            $billingDetail = Cash::getFullAddress($billing_address);
            $billname = $billingDetail["name"] ?? "-";
            $billphone = $billingDetail["phone"] ?? "-";
            $billAddress = $billingDetail["address"] ?? "-";
            $billAddress2 = $billingDetail["address_2"] ?? "-";
            $billPost_code = $billingDetail["post_code"] ?? "-";
            $billState_id = $billingDetail["state_id"] ?? "-";
            $billCity = $billingDetail["city"] ?? "-";
            $billemail = $billingDetail["email"] ?? "-";

            # Get Shopping Cart
            unset($dataIn);
            $dataIn['bkend_token'] = $bkendToken;
            $dataIn['promo_code'] = $promoCode;
            $dataIn['redeemAmount'] = $redeemAmount;
            $dataIn['deliveryMethod'] = $deliveryMethod;
            $dataIn['postcode'] = $shipPost_code;
            $getCartDetails = Inventory::getShoppingCart2($dataIn);
            if($getCartDetails['status'] != 'ok')
            {
                return array('status' => "error", 'code' => 1, 'statusMsg' => $getCartDetails["statusMsg"] , 'data' => $getCartDetails , 'dataIn' => $dataIn);
            }

            ## delivery not available 
            if($getCartDetails['data']['deliveryAvailability'] == '0'){
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01284"][$language] /* Delivery is not available in your postcode area. */ , 'data' => $getCartDetails , 'dataIn' => $dataIn,"getCartDetails" => $getCartDetails);
            }

            ## invalid promo
            if($getCartDetails['data']['appliedPromoStatus'] == 'invalid'){
                return array('status' => "error", 'code' => 1, 'statusMsg' => $getCartDetails["data"]["appliedPromoStatusMsg"] , 'data' => $getCartDetails , 'dataIn' => $dataIn);
            }

            $db->where('id',$userID);
            $client_info = $db->getOne('client a', $limit, 'a.phone, a.email, a.name, a.username');

            //client info
            $client_phone 		   = $client_info["phone"];
            $client_email 		   = $client_info["email"];
            $first_name 	       = $client_info["name"];
            $last_name  	       = $client_info["username"];

            $payment_amount    = $getCartDetails['data']['totalSalePrice'];
            $payment_method    = '';
            $release_amount    = $getCartDetails['totalSalePrice'];
            $shipping_fee = $getCartDetails['data']['shippingFee'];

            if($payment_amount == "") return array ('status' => 'error' ,'code' => 1, 'statusMsg' => "Please enter amount", 'data' => ""); 
        
            // use token to get sale id
            $db->where('token', $bkendToken);
            $saleID = $db->getOne('guest_token', 'sale_id');
            if($saleID)
            {
                $saleID = $saleID['sale_id'];
            }

            $db->where('deleted', 0);
            $db->where("sale_id", $saleID);
            $order_detail = $db->getOne("sale_order_detail");

            foreach($order_detail as $detailChecking)
            {
                $db->where('product_id', $detailChecking['product_id']);
                $db->where('status', 'Active');
                $availableStock = $db->getValue('stock', 'count(*)') ?? 0;

                $db->where('sod.sale_id', $saleID);
                $db->where("so.status", array("Pending","Pending Payment Approve", "Paid"),"IN");
                $db->where("sod.deleted", 0);
                $db->where("sod.product_id", $product_id);
                $db->join("sale_order so", "sod.sale_id=so.id", "INNER");
                $lockStock = $db->getValue("sale_order_detail sod", "SUM(sod.quantity)") ?? 0;

                $result = intval($availableStock) - intval($quantity) - intval($lockStock);

                if(intval($result) < 0)
                {
                    return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["M03005"][$language] /* Out Of Stock */, 'data' => $dataOut['data']);
                }
            }

            $purchase_id = $saleID;
            $saleDetail_id = $order_detail['id'];
            $createdDate = date('Y-m-d H:i:s');

            $redeemAmt = $getCartDetails['data']['redeemAmount'];
            if($redeemAmt>"0"){
                $redeemPts = $redeemAmt * 200;
            }

            $updateData = array(
                'billing_name' => $billname,
                'billing_phone' => $billphone,
                'billing_address' => $billAddress,
                'billing_address2' => $billAddress2,
                'billing_post_code' => $billPost_code,
                'billing_state_id' => $billState_id,
                'billing_city' => $billCity,
                'shipping_name' => $shipname,
                'shipping_phone' => $shipphone,
                'shipping_address' => $shipAddress,
                'shipping_address2' => $shipAddress2,
                'shipping_post_code' => $shipPost_code,
                'shipping_state_id' => $shipState_id,
                'shipping_city' => $shipCity,
                'billing_email' => $billemail,
                'shipping_email' => $shipemail,
                'is_gift' => $is_gift,
                'payment_amount' => $payment_amount,
                'discount_amount' => floatval($getCartDetails['data']['promoApplyAmount']),
                'redeem_amount' => (floatval($redeemAmt)),
                'shipping_fee' => $shipping_fee,
                'updated_at' => date('Y-m-d H:i:s'),
            );
            $db->where('id', $purchase_id);
            $db->where('client_id', $client_id);
            $db->update('sale_order', $updateData);

            $data = array("purchase_id" => $purchase_id, 
                            "payment_amount" => $payment_amount, 
                            "clientID" => $client_id, 
                            'getTxnTime' => $createdDate, 
                            "release_amount" => $payment_amount,
                            "saleDetail_id" => $saleDetail_id, 
                            "shippingFee" => $shipping_fee,
                            "redeemAmount" => $redeemAmt
                    );

            return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => "Completed Successfully", 'data' => $data); //Completed Successfully
        }

        function newProformaInvoiceID(){
            $db = MysqliDb::getInstance();
            $proforma_invoice_id = 0;
            $db->where("name","proforma_invoice_id");
            $res = $db->getOne("system_settings");
          if($res){
            $proforma_invoice_id = $res["value"];
          }
          unset($res);
            $proforma_invoice_id++;
    
            $field = array("value");
            $value = array($proforma_invoice_id);
            $arrayData = array_combine($field, $value);
    
            $db->where("name","proforma_invoice_id");
            $res = $db->update("system_settings",$arrayData);
            $new_id = "INV".date("ymd")."-".$proforma_invoice_id;
            return $new_id;
        }

        function getProviderSetting($provider_id=""){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            if($provider_id != "") $db->where("provider_id", $provider_id);
            $res = $db->get("provider_setting",null,"name,value,provider_id");
            if($res){
              foreach ($res as $value) {
                if($value["name"] == "blockingCountry"){
                  $value["value"] = strtolower($value["value"]);
                  $data[$value["provider_id"]][$value["name"]][$value["value"]] = $value["value"];
                }else{
                  $data[$value["provider_id"]][$value["name"]] = $value["value"];
                }
              }
            }
            return $data;
        }

        function newInvoiceID(){
            $db = MysqliDb::getInstance();
            $invoiceID  = 0;
            $invoice_format = Setting::$systemSetting['invoice_format'];
            $invoice_id = Setting::$systemSetting['invoice_id'];
            $invoice_id++;
    
            $new_id = str_replace("%%invoice_id%%", $invoice_id, $invoice_format);
           
            $field = array("value");
            $value = array($invoice_id);
            $arrayData = array_combine($field, $value);
            $db->where("name","invoice_id");
            $res = $db->update("system_settings",$arrayData);
            
            return $new_id;
        }

        function pdfInvoiceContent($dataIn){
            $language = General::$currentLanguage;
            $translations = General::$translations;
    
            include_once("mpdf-7.0/mpdf.php");
            
            # remove if condition after the date
            if(str_replace(',', '',$dataIn["purchase_amount"]) >= 450 && time() < strtotime(date("2020-09-16 00:00:00"))){
    
                $pdfInvoiceContent = "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\"> 
    
            <head>
            <style>
                * {
                    box-sizing: border-box;
                }
            
                /* Create two equal columns that floats next to each other */
                .column {
                    float: left;
                    padding: 10px;
                }
            
                /* Clear floats after the columns */
                .row:after {
                    content: \"\";
                    display: table;
                    clear: both;
                }
            
                .vl {
                    border-left: 80px solid #89000e;
                }
            
            </style>
            
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
            <link rel=\"stylesheet\" type=\"text/css\" href=\"%%websiteURL%%/css/loggedIn.css?t=1527496770\"/>
            </head>
            <body bgcolor=\"#53565a\" style=\"font-family:Arial;\">
            <div style=\"background-color: white;top:0;bottom:0; right:0;position:fixed;overflow-y:scroll;overflow-x:hidden;margin:0 auto;\" >
             <div style=\"height: 60px;width:1000px;vertical-align: middle;display: table; padding:50px 0px 0px 20px;\" align = \"center\">     
                    <img id=\"\" src=\"%%websiteURL%%/images/logo.png\" width = \"300\" style = \"padding:15px 0px 15px 0px;\">
                    <br/>
                    %%display_gstID%%
             </div>
    
             <div style=\"width:1000px; padding:60px 0px 0px 20px;\" align=\"left\">
                <div class=\"bold\" style= \"font-size:30px;font-weight:900;color:#89000e;\">TAX INVOICE</div>
                <div style=\"height: 60px;width:1000px; padding:20px 0px 0px 0px;\" align=\"left\">  
                  <table class=\"\" style=\"width:50%;padding:0px 0px 0px 0px;\" border=0 cellspacing=0 align = \"left\">
                    <tr>
                      <td style=\"height:25px;width:150px;\">
                        <div style=\"font-size:14px;font-weight:900;width:150px;%%display_invoice_date%%\"><b>Invoice Issue Date</b></div>
                     </td>
                     <td>
                        <div class=\"semibold\" style=\"font-size:14px;%%display_invoice_date%%\"> : %%invoice_date%%</div>
                     </td>
                    </tr>
                    <tr>
                     <td style=\"height:25px;width:150px;\">
                        <div  style=\"font-size:14px;font-weight:900;width:150px;%%display_invoice_id%%\"><b>Invoice No</b></div>
                     </td>
                     <td>
                        <div class=\"semibold\" style=\"font-size:14px;%%display_invoice_id%%\"> : %%invoice_id%%</div>
                     </td>
                    </tr>
                  </table>
                </div>
                 <br/>
             </div>
             <div style=\"width:1000px;vertical-align: middle;display: table; padding:10px 0px 0px 20px;\" align=left> 
                <hr>
                <div class=\"row\" style = \"width:100%;\">
                  <div class = \"column\" style = \"width:45%;\">
                    <div class=\"semibold\" style=\"font-size:20px;color:#89000e;%%display_name%%\">%%first_name%% %%last_name%%</div>
                     <br/>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_company%%\">%%client_company%%</div>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_address1%%\">%%client_address1%%</div>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_address2%%\">%%client_address2%%</div>
                      <br/>              
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_phone%%\">[T] %%client_phone%%</div>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_email%%\">[E] %%client_email%%</div>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_client_website%%\">[W] %%client_website%%</div>
                      <br>
                      <br>
                  </div>
                  <div class = \"column\" style = \"width:50%;\">
                    <div class=\"semibold\" style=\"font-size:14px;color:#89000e;padding-left:60px;%%display_payment_method%%\">Payment Method&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; %%payment_method%%</div>
                      <br>
                      <table class=\"\" style=\"width:100%;padding-left:60px;\" border=0 cellspacing=0 cellpadding=0>
                        %%display_purchaseID%%
                         %%display_transactionID%%
                         %%display_BuyerID%%
                         %%display_FPXTime%%
                         %%display_FPXStatus%%
                         %%display_accountName%%
                         %%display_bankName%%
                         %%display_BankCountry%%
                         %%display_BIC%%
                         %%display_accountNumber%%
                         <tr>
                            <td style=\"height:25px;width:150px;\">
                               <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_status%%\">Payment Status </div>
                            </td>
                            <td style=\"height:25px;\">
                               <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_status%%\"> %%status%%</div>
                            </td>
                         </tr>
                      </table>
                  </div>
                </div>
                <hr>
              </div>
              <br> 
              <div style=\"width:1000px;vertical-align: middle;display: table; padding:40px 0px 0px 20px;\" align=left> 
                <br>            
                <table class=\"\" style=\"width:900px\" border=0 cellspacing=0 cellpadding=0>
                   <tr>
                      <th style=\"font-size:15px;padding:0px 0px 0px 20px;height:40px;width:200px;border-top:1px solid black;border-bottom:1px solid black;\" align=\"left\">Description</th>
                      <th style=\"font-size:15px;height:40px;width:175px;border-top:1px solid black;border-bottom:1px solid black;\" align=\"center\">Quantity</th>
                      <th style=\"font-size:15px;height:40px;width:175px;border-top:1px solid black;border-bottom:1px solid black;\" align=\"right\">Price</th>
                      <th style=\"font-size:15px;height:40px;width:150px;border-top:1px solid black;border-bottom:1px solid black;padding:0px 20px 0px 0px;\" align=\"right\" >Total</th>
                   </tr>
                   <tbody>
                      <tr class=\"\">
                         <td style=\"font-size:14px;padding:0px 0px 0px 20px;height:36px;\">%%desc%%</td>
                         <td style=\"font-size:14px;height:36px;\" align=\"center\">%%quantity%%</td>
                         <td style=\"font-size:14px;height:36px;\" align=\"right\">%%symbol%% %%release_amount%%</td>
                         <td style=\"font-size:14px;height:36px;padding:0px 20px 0px 0px;\" align=\"right\">%%symbol%% %%release_amount%%</td>
                      </tr>
                      <!-- mooncake -->
                      <tr class=\"\">
                         <td style=\"font-size:14px;padding:0px 0px 0px 20px;height:36px;\">Mooncake Box 2020 (4 pcs)</td>
                         <td style=\"font-size:14px;height:36px;\" align=\"center\">1</td>
                         <td style=\"font-size:14px;height:36px;\" align=\"right\">F.O.C.</td>
                         <td style=\"font-size:14px;height:36px;padding:0px 20px 0px 0px;\" align=\"right\">F.O.C.</td>
                      </tr>
                      <tr>
                         <td height=\"100px\"></td>
                      </tr>
                      <tr style=\"background-color:#f0f8f3;\">
                         <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">Subtotal</td>
                         <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%release_amount%%</td>
                      </tr>
                      
    
                      %%display_payment_fee%%
                      %%display_gst%%
                      %%displayDiscount%%
                      %%displayRedeemReward%%
                       <tr style=\"background-color:#f0f8f3;\">
                         <td colspan=3 style=\"font-size:14px;padding:0px 20px 0px 20px;height:30px;border-bottom:1px solid black;\">Total</td>
                         <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:30px;border-bottom:1px solid black;\" align=\"right\">%%symbol%% %%purchase_amount%%</td>
                      </tr>
    
                      
                      
                      
                      
                   </tbody>
                </table>
                <div style=\"width:1000px;font-size:12px;padding:10px 0px 20px 0px;color:grey;\">All bank and payment gateway fees and charges are to be covered by the customer. 
                <br>
                In case of any such fees and charges, the credits added to your account will depend on the exact amount of money received by SMS123.</div>
             </div>
    
             <div style=\"width:1000px;padding:120px 0px 20px 20px;font-size:12px;\">
              <b>Question?</b><br/>
              <span>Email us at </span><strong>support@sms123.net</strong><br/>
              <span>or call us at </span><strong>+6018-2460000</strong><br/>
              <span>[Fax] </span><strong>+603 8211 8434</strong><br/>
             
                <hr style = \"height:3px;border:none;color:#89000e;background-color:#89000e;\">
    
                <div align = \"center\" style = \"padding:0px 20px 0px 0px;font-weight:800;color:gray\">
                  <strong style=\"font-size:12px;\">%%companyName%%, %%companyAddress1%%, %%companyAddress2%%, %%companyAddress3%% | SMS123.NET</strong>
                   
                </div>
             </div>
    
                </div>
                </body>
                </html>";
                    }else{
                        $pdfInvoiceContent =  $translations['S01269'][$language];
            
                    }
            
            $pdfInvoiceContent = str_replace('%%websiteURL%%',$dataIn["websiteURL"],$pdfInvoiceContent);
                        
            $display_gstID = "<span class=\"semibold\" style=\"font-size:14px;color:gray;\">(GST ID No:%%gstID%%)</span>";
                
                if($dataIn["gstID"] !== "" && $dataIn["gstEnable"] == "1"){
                  $pdfInvoiceContent = str_replace('%%display_gstID%%',$display_gstID,$pdfInvoiceContent);
                  $pdfInvoiceContent = str_replace('%%gstID%%',$dataIn["gstID"],$pdfInvoiceContent);
    
                }else{
                  $pdfInvoiceContent = str_replace('%%display_gstID%%',"",$pdfInvoiceContent);
                }
    
            $pdfInvoiceContent = str_replace('%%companyName%%',$dataIn["companyName"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%companyAddress1%%',$dataIn["companyAddress1"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%companyAddress2%%',$dataIn["companyAddress2"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%companyAddress3%%',$dataIn["companyAddress3"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%companyTel%%',$dataIn["companyTel"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%companyFax%%',$dataIn["companyFax"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%companyEmail%%',$dataIn["companyEmail"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%websiteName%%',$dataIn["websiteName"],$pdfInvoiceContent);
            
            $pdfInvoiceContent = str_replace(array("%%first_name%%", "%%last_name%%"),array($dataIn["first_name"],$dataIn["last_name"]),$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%client_company%%',$dataIn["client_company"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%client_address1%%',$dataIn["client_address1"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%client_address2%%',$dataIn["client_address2"],$pdfInvoiceContent);
    
            // $mobileNumberDetails = $general->mobileNumberInfo($dataIn["client_phone"], $dataIn["client_region_code"]);
            $pdfInvoiceContent = str_replace('%%client_phone%%',$mobileNumberDetails["mobileNumberFormatted"],$pdfInvoiceContent);
            
            $pdfInvoiceContent = str_replace('%%client_email%%',$dataIn["client_email"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%client_website%%',$dataIn["client_website"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%invoice_date%%',date("d/m/Y",$dataIn["invoice_date"]),$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%invoice_id%%',$dataIn["invoice_id"],$pdfInvoiceContent);
    
            $pdfInvoiceContent = str_replace('%%payment_method%%',$dataIn["payment_method"],$pdfInvoiceContent);
    
    
            if($dataIn['getPaymentType'] == 'fpx')
            {
                
                if($dataIn["purchase_id"] != ""){
                     $purchase_id = "<tr>
                       <td style=\"height:25px;width:150px;\">
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_purchaseID%%\">Seller Order Number : </div>
                       </td>
                       <td>
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_purchaseID%%\">".$dataIn["purchase_id"]."</div>
                       </td>
                      </tr>";
                }
                else
                {
                    $purchase_id = "";
                }
    
                if($dataIn["fpx_fpxTxnId"] != ""){
                     $fpx_fpxTxnId = "<tr>
                       <td style=\"height:25px;width:150px;\">
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_transactionID%%\">FPX Transaction ID : </div>
                       </td>
                       <td>
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_transactionID%%\">".$dataIn["fpx_fpxTxnId"]."</div>
                       </td>
                      </tr>";
                }
                else
                {
                    $fpx_fpxTxnId = "";
                }
    
                if($dataIn["fpx_buyerBankId"] != ""){
                     $fpx_buyerBankId = "<tr>
                       <td style=\"height:25px;width:150px;\">
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_BuyerID%%\">Buyer Bank Name : </div>
                       </td>
                       <td>
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_BuyerID%%\">".$dataIn["fpx_buyerBankId"]."</div>
                       </td>
                      </tr>";
                }
                else
                {
                    $fpx_buyerBankId = "";
                }
    
                if($dataIn["fpx_fpxTxnTime"] != ""){
                     $fpx_fpxTxnTime = "<tr>
                       <td style=\"height:25px;width:200px;\">
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_FPXTime%%\">Transaction Date and Time : </div>
                       </td>
                       <td>
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_FPXTime%%\">".$dataIn["fpx_fpxTxnTime"]."</div>
                       </td>
                      </tr>";
                }
                else
                {
                    $fpx_fpxTxnTime = "";
                }
    
                if($dataIn["fpx_debitAuthCode"] != ""){
    
    
                     $fpx_debitAuthCode = "<tr>
                       <td style=\"height:25px;width:150px;\">
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_FPXStatus%%\">Transaction Status : </div>
                       </td>
                       <td>
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_FPXStatus%%\">".$dataIn["fpx_debitAuthCode"]."</div>
                       </td>
                      </tr>";
                }
                else
                {
                    $fpx_debitAuthCode = "";
                }
    
                $pdfInvoiceContent = str_replace('%%display_purchaseID%%',$purchase_id,$pdfInvoiceContent);
                $pdfInvoiceContent = str_replace('%%display_transactionID%%',$fpx_fpxTxnId,$pdfInvoiceContent);
                $pdfInvoiceContent = str_replace('%%display_BuyerID%%',$fpx_buyerBankId,$pdfInvoiceContent);
                $pdfInvoiceContent = str_replace('%%display_FPXTime%%',$fpx_fpxTxnTime,$pdfInvoiceContent);
                $pdfInvoiceContent = str_replace('%%display_FPXStatus%%',$fpx_debitAuthCode,$pdfInvoiceContent);
    
            }
    
    
            if($dataIn["payment_method"] == "Bank Transfer / TT" || $dataIn["payment_method"] == "FPX"){
                if($dataIn["accountName"] != ""){
                     $accountName = "<tr>
                       <td style=\"height:25px;width:150px;\">
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_accountName%%\">Beneficiary</div>
                       </td>
                       <td>
                          <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_accountName%%\">".$dataIn["accountName"]."</div>
                       </td>
                      </tr>";
                }
                else
                {
                    $accountName = "";
                }
             
               if($dataIn["sysBankName"] != ""){
                  $sysBankName =  "<tr>
                   <td style=\"height:25px;width:150px;\">
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_bankName%%\">Bank Name</div>
                   </td>
                   <td>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_bankName%%\">".$dataIn["sysBankName"]."</div>
                   </td>
                </tr>";
                }else{
                  $sysBankName = "";
                }
    
                if($dataIn["sysBankCountry"] != ""){
                  $sysBankCountry =  "<tr>
                   <td style=\"height:25px;width:150px;\">
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_BankCountry%%\">Bank Country</div>
                   </td>
                   <td>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_BankCountry%%\">".$dataIn["sysBankCountry"]."</div>
                   </td>
                </tr>";
                }else{
                  $sysBankCountry = "";
                }
    
                if($dataIn["swiftCode"] != ""){
                  $swiftCode =  "<tr>
                   <td style=\"height:25px;width:150px;\">
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_BIC%%\">BIC/SWIFT</div>
                   </td>
                   <td>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_BIC%%\">".$dataIn["swiftCode"]."</div>
                   </td>
                </tr>";
                }else{
                  $swiftCode = "";
                }
    
    
                if($dataIn["sysBankAcc"] != ""){
                  $sysBankAcc =  "<tr>
                   <td style=\"height:25px;width:150px;\">
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;width:150px;%%display_accountNumber%%\">Account Number</div>
                   </td>
                   <td>
                      <div class=\"semibold\" style=\"font-size:14px;color:gray;%%display_accountNumber%%\">".$dataIn["sysBankAcc"]."</div>
                   </td>
                </tr>";
                }else{
                  $sysBankAcc = "";
                }
               
              $pdfInvoiceContent = str_replace('%%display_accountName%%',$accountName,$pdfInvoiceContent);
    
              $pdfInvoiceContent = str_replace('%%display_bankName%%',$sysBankName,$pdfInvoiceContent);
    
              $pdfInvoiceContent = str_replace('%%display_BankCountry%%',$sysBankCountry,$pdfInvoiceContent);
    
              $pdfInvoiceContent = str_replace('%%display_BIC%%',$swiftCode,$pdfInvoiceContent);
            
              $pdfInvoiceContent = str_replace('%%display_accountNumber%%',$sysBankAcc,$pdfInvoiceContent);
              
            }
            
            else
            {
              $pdfInvoiceContent = str_replace('%%display_accountName%%','',$pdfInvoiceContent);
    
              $pdfInvoiceContent = str_replace('%%display_bankName%%','',$pdfInvoiceContent);
    
              $pdfInvoiceContent = str_replace('%%display_accountNumber%%','',$pdfInvoiceContent);
            }
            
    
            $pdfInvoiceContent = str_replace('%%status%%',$dataIn["status"],$pdfInvoiceContent);
    
            // $pdfInvoiceContent = str_replace('%%paymentDuePeriod%%',$setting->getPaymentDuePeriod(),$pdfInvoiceContent) ;
    
            if($dataIn['release_currency'] == 'CREDIT')
            {
                $desc = $dataIn['displayReleaseAmount']." ".$translations["M01255"][$language];
            }
            else
            {
                $desc = $translations["M01255"][$language];
            }
            
            $quantity = 1; 
            $amount = $dataIn["purchase_amount"];
            $release_amount = $dataIn["release_amount"];
    
            $pdfInvoiceContent = str_replace('%%desc%%',$desc,$pdfInvoiceContent) ;
    
            $pdfInvoiceContent = str_replace('%%quantity%%',$quantity,$pdfInvoiceContent) ;
    
            $pdfInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfInvoiceContent) ;
    
            $pdfInvoiceContent = str_replace('%%release_amount%%',$dataIn["release_amount"],$pdfInvoiceContent) ;
                    
    
             $display_payment_fee = "<tr style=\"background-color:#f0f8f3;\">
                      <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">Payment Fee</td>
                      <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%payment_fee%%</td>
                   </tr>";
    
    
    
             $display_gst = "<tr style=\"background-color:#f0f8f3;\">
                      <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;%%display_gst%%\">GST (%%gst%%%)</td>
                      <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black; align=\"right\";%%display_gst%%\">%%symbol%% %%payment_tax%%</td>
                   </tr>";
    
            if($dataIn["payment_fee"] > 0) {
              $pdfInvoiceContent = str_replace('%%display_payment_fee%%',$display_payment_fee,$pdfInvoiceContent);
              $pdfInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfInvoiceContent);
              $pdfInvoiceContent = str_replace('%%payment_fee%%',$dataIn["payment_fee"],$pdfInvoiceContent);
    
            } else{
              $pdfInvoiceContent = str_replace('%%display_payment_fee%%',"",$pdfInvoiceContent) ;
            } 
    
            if($dataIn["payment_tax"] !== "" && $dataIn["gstEnable"] == "1"){
              $pdfInvoiceContent = str_replace('%%display_gst%%',$display_gst,$pdfInvoiceContent);
              $pdfInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfInvoiceContent);
              $pdfInvoiceContent = str_replace('%%gst%%',$setting->getGST(),$pdfInvoiceContent);
              $pdfInvoiceContent = str_replace('%%payment_tax%%',$dataIn["payment_tax"],$pdfInvoiceContent);
    
            }else{
            $pdfInvoiceContent = str_replace('%%display_gst%%',"",$pdfInvoiceContent) ; 
            }
    
            $displayDiscount = "<tr style=\"background-color:#f0f8f3;\">
                      <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">Discount (".$dataIn['promoCode'].")</td>
                      <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%discountAmount%%</td>
                   </tr>";
            if($dataIn["discountAmount"] > 0 && $dataIn['isPromo'] == 1) {
                $pdfInvoiceContent = str_replace('%%displayDiscount%%',$displayDiscount,$pdfInvoiceContent);
                $pdfInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfInvoiceContent);
                $pdfInvoiceContent = str_replace('%%discountAmount%%',$dataIn["discountAmount"],$pdfInvoiceContent);
    
            } else{
                $pdfInvoiceContent = str_replace('%%displayDiscount%%',"",$pdfInvoiceContent) ;
            } 
    
            $displayRedeemReward = "<tr style=\"background-color:#f0f8f3;\">
                      <td colspan=\"3\" style=\"font-size:14px;padding:0px 20px 0px 20px;height:36px;border-top:1px solid black;\">TheNux Reward Redeem</td>
                      <td style=\"font-size:14px;padding:0px 20px 0px 0px;height:36px;border-top:1px solid black;\" align=\"right\">%%symbol%% %%redeemAmount%%</td>
                   </tr>";
                if($dataIn["redeemAmount"] > 0 && $dataIn['isRedeemReward'] == 1) {
                    $pdfInvoiceContent = str_replace('%%displayRedeemReward%%',$displayRedeemReward,$pdfInvoiceContent);
                    $pdfInvoiceContent = str_replace('%%symbol%%',$dataIn["currency"],$pdfInvoiceContent);
                    $pdfInvoiceContent = str_replace('%%redeemAmount%%',$dataIn["redeemAmount"],$pdfInvoiceContent);
        
                } else{
                    $pdfInvoiceContent = str_replace('%%displayRedeemReward%%',"",$pdfInvoiceContent) ;
                } 
    
                $pdfInvoiceContent = str_replace('%%purchase_amount%%',$dataIn["purchase_amount"],$pdfInvoiceContent);
            
                $pdfInvoiceContent = str_replace('%%system_display_name%%',$dataIn["systemDisplayName"],$pdfInvoiceContent) ;
            
              $filename = tempnam(sys_get_temp_dir(), time());
              $mpdf = new mPDF();
              $mpdf->autoScriptToLang = true;
              $mpdf->autoLangToFont = true;
              $mpdf->SetDisplayMode('fullpage');
              $mpdf->WriteHTML($pdfInvoiceContent);
              $mpdf->Output($filename, 'F');
    
              return array("path" => $filename, "content" => $pdfInvoiceContent);
        }

        public function getProviderSettingFPX($params)
        {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $db->where('type', 'topup');
            $db->where('name', 'FPX');
            $db->where('deleted', 0);
            $db->where('disabled', 0);
            $getProvider = $db->getOne('provider', 'id');

            //get exchange id 15/10/2019
            $db->where('provider_id', $getProvider['id']);
            $result = $db->get("provider_setting",null,"name,value,provider_id");

            if(!$result)
            {
                return array('status' => 'ok', 'code' => 0, 'statusMsg' => 'No result found', 'data' => '');
            }

            return array('status' => 'ok', 'code' => 0, 'statusMsg' => '', 'data' => $result);
        }

        public function updateSaleOrder($dataIn){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $userType = $db->userType;
            $redeemPointRate = Setting::$systemSetting['redeemPoint'];
            $dateTime = date('Y-m-d H:i:s');

            $adminID = $dataIn['adminID'] != "" ? $dataIn['adminID'] : $db->userID;
            $actionByTemp = $dataIn['actionBy'] != "" ? $dataIn['actionBy'] : "";
    
            // $saleOrderID = (string)$dataIn['saleID'];  
            $paymentMethod = (string)$dataIn['paymentMethod'];  
            $paymentDelivery = (string)$dataIn['deliveryMethod'];  
            $paymentAmount = (string)$dataIn['total_price'];  
            $createdDate = date("Y-m-d H:i:s");
            $promoCode   = trim($dataIn['promo_code']);
            $redeemAmount = trim($dataIn['redeemAmount']);
            $clientID = $db->userID;
            $browserInfo = General::getBrowserInfo();
            $ip = $browserInfo["ip"]?$browserInfo["ip"]:"Unknown";
            $ipInfo = General::ip_info($ip);
            $bkendToken = $dataIn['bkend_token'];
            $adminSOStatus = $dataIn['adminSOStatus'];
            $adminResetClientID = $dataIn['adminResetClientID'];

            $dataIn['adminResetClientID'] = $adminResetClientID;

            ## should change to getShoppingCart to get info. 
            $cartInfo = Inventory::getShoppingCart2($dataIn);

            $payment_amount = 0;
            $discount_amount = 0;
            $promo_amount = 0;
            $shippingFee = $cartInfo['data']['deliveryFee'];

            ## delivery not available 
            if($cartInfo['data']['deliveryAvailability'] == '0' && !$adminResetClientID){
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01284"][$language] /* Delivery is not available in your postcode area. */ , 'data' => $getCartDetails , 'dataIn' => $dataIn);
            }

            $db->where('token', $bkendToken);
            $db->where('disabled', 0);
            $getProductListArray = $db->get('shopping_cart', null, 'product_id');

            foreach($getProductListArray as $key => $value){
                $db->where('id', $value['product_id']);
                $setMethodforDelivery = $db->getValue('product', 'delivery_method');

                $db->where('id', $setMethodforDelivery);
                $getDeliveryMethodName = $db->getValue('gotasty_delivery_method', 'name');

                $setMethodforDeliveryArry[] = $getDeliveryMethodName;
            }

            if ($setMethodforDeliveryArry) {
                foreach ($setMethodforDeliveryArry as $value) {
                    if ($value == 'Delivery Charges') {
                        $deliveryCharges++;
                    } else if ($value == 'Dry Delivery Charges') {
                        $dryDelivery++;
                    } else if ($value == 'Self Pickup') {
                        $pickUp++;
                    }
                }
            }

            if($bkendToken)
            {
                // validate token
                $db->where('token', $bkendToken);
                $tokenValid = $db->getOne('guest_token');
            }

            if(empty($bkendToken) || !$tokenValid)
            {
                if(!empty($clientID))
                {
                    $db->orderBy('id', 'desc');
                    $db->where('client_id',$clientID);
                    $tokenExist = $db->getOne('guest_token');
                    if($tokenExist)
                    {
                        $bkendToken = $tokenExist['token'];
                    }
                }
            }

            $db->where('token', $bkendToken);
            $saleOrderID = $db->getOne('guest_token');

            if($saleOrderID)
            {
                $saleID = $saleOrderID['sale_id'];
            }
            else
            {
                return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["B00524"][$language], 'data' => $saleID); // Payment not found.
            }
            
            $db->where("id",$saleID);
            $res = $db->getOne("sale_order");
       
            if (!$paymentMethod && !$adminSOStatus){
                return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["B00525"][$language], 'data' => ""); // Payment method not found.
            }

            if (!$res){
                return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["B00524"][$language], 'data' => ""); // Payment not found.
            }

            // check redeemAmount is enough or not
            if(intval($redeemAmount) != 0 || !empty($redeemAmount))
            {
                $creditBalance = Cash::getBalance($clientID, 'gotastyCredit');
                $redeemAmount = number_format($redeemAmount, 2, '.', '');
                if($creditBalance < floatval($redeemAmount))
                {
                    return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01201"][$language] /* Insufficient Point Balance */, 'data' => $creditBalance, 'redeemAmount' => $redeemAmount);
                }
            }

            $shipping_name = $res["shipping_name"];
            $shipping_phone = $res["shipping_phone"];
            $shipping_address = $res["shipping_address"];

            // check stock status
            $db->where('sale_id', $saleID);
            $db->where('deleted', 0);
            $db->where('product_id', 0, '!=');
            $db->where('product_id', null, 'IS NOT');
            $saleOrderDetails = $db->get('sale_order_detail');

            $updateData = array(
                'deleted' => '1',
                'updated_at' => $dateTime
            );
            $db->where('sale_id', $saleID);
            $db->where('deleted', '0');
            $db->update('sale_order_detail', $updateData);

            foreach($saleOrderDetails as $checkStock)
            {
                unset($dataIn);
                $dataInDetail['product_id'] = $checkStock['product_id'];
                $dataInDetail['quantity'] = $checkStock['quantity'];
                $dataIn['purchaseProduct'][] = $dataInDetail;
            }
            $dataOut = Inventory::checkStockQuantity($dataIn);
            if($dataOut['status'] != 'ok')
            {
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["M03005"][$language] /* Out Of Stock */, 'data' => $dataOut);
            }

            $db->where('id',$res['client_id']);
            $client_info = $db->getOne('client a', $limit, 'a.phone, a.email, a.name, a.username');

            // Deduct point for member
            if(intval($redeemAmount) != 0 || !empty($redeemAmount))
            {
                $db->where("name", "creditAdjustment");
                $result     = $db->getValue ("client", "id");
                $accountID  = $clientID;
                $receiverID = $result;
                $subject = 'Redeem Reward'; 
                $activityCode = 'L00005';
                $titleCode = 'T00005';

                $adjustAmount =  $redeemAmount;
                $batchID        = $db->getNewID();
                $belongID       = $db->getNewID();
                try
                {
                    //Wallet::creditAdjustment($dataIn);
                    Cash::insertTAccount($accountID, $receiverID, 'bonusDef', $adjustAmount, $subject, $belongID, $purchase_id, $db->now(), $batchID, $clientID, $remark);
                    // Cash::insertTAccount(8,$clientID, 'bonusDef', $adjustAmount, 'Redeem Reward', $batchID, "", $db->now(), $batchID, $clientID, $remark);
                    unset($dataIn);
                    $dataIn['delivery_method'] = $paymentDelivery;
                    $dataIn['bkend_token'] = $bkendToken;
                    $dataIn['redeemAmouht'] = $redeemAmount;


                    if($redeemAmount>0){
                        $redeemPts = $redeemAmount * $redeemPointRate;
                        $insertData = array(
                            'client_id' => $res['client_id'],
                            'product_id' => '',
                            'product_template_id' => '',
                            'item_name' => 'Redeemed Points',
                            'item_price' => $redeemPointRate,
                            'quantity' => $redeemAmount,
                            'subtotal' => $cartInfo['data']['redeemAmount'],
                            'sale_id' => $saleID,
                            'type' => 'redeem_point',
                            'deleted' => '0',
                            'created_at' => date("Y-m-d H:i:s")
                        );
                        $insertRedeemPoint = $db->insert("sale_order_detail",$insertData);
                    }
                }
                catch(Exception $e)
                {
                    return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00131"][$language] /* Update failed. */, 'data' => "");
                }
            }

            ## update sale_order_details to latest 
            foreach($cartInfo["data"]["cartList"] as $cartData)
            {
                $db->where('id', $cartData['product_id']);
                $db->where('deleted', 0);
                $productType = $db->getOne('product');

                if($cartData["promo_type"] == "pricelist"){
                    $itemPrice = $cartData["latestPrice"];
                }
                else
                {
                    $itemPrice = $cartData["price"];
                }

                unset($Total);
                unset($latestTotal);

                if($cartData['promo_type'] == 'pricelist')
                {
                    $latestTotal = $cartData['latestTotal'];
                    $Total = $cartData['latestTotal'];
                    $payment_amount = $payment_amount + $latestTotal;
                }
                else
                {
                    $Total = $cartData['total'];
                    $payment_amount = $payment_amount + $Total;
                }


                    $insertData = array(
                    'client_id'     => $res['client_id'],
                    'product_id'    => $cartData["product_id"],
                    'product_template_id'    => '',
                    'item_name'    => $cartData["productName"],
                    'item_price'    => $itemPrice,
                    'price_reduce' => $cartData['latestPrice'],
                    'quantity'    => $cartData['quantity'],
                    'subtotal'    => $Total,
                    'price_reduce_subtotal' => $latestTotal,
                    'sale_id'    => $saleID,
                    'type'    => $productType['product_type'],
                    'deleted'    => '0',
                    'created_at'    => date('Y-m-d H:i:s')
                );
                $db->insert("sale_order_detail",$insertData);
            }

            if(isset($cartInfo["data"]["appliedPromo"])){
                foreach($cartInfo["data"]["appliedPromo"] as $promoInfo){

                    if((strtolower($adminSOStatus) == 'paid' && $adminResetClientID) || (!$adminSOStatus && !$adminResetClientID))
                    {
                        $insertServiceData = array(
                            'client_id'     => $res['client_id'],
                            'sale_id'       => $saleID,
                            'promo_code_id' => $promoInfo["id"],
                            'amount'        => $promoInfo['totalDiscount'],
                            'type'          => $promoInfo['type'],
                            'disabled'      => '0',
                            'created_at'    => date('Y-m-d H:i:s'),
                        );
                        $db->insert('so_service', $insertServiceData);
                    }

                    if(strtolower($promoInfo['type']) == 'billdiscount')
                    {
                        $promoDiscountType = 'Bill Discount (Promo Code Discount)';
                    }
                    else if(strtolower($promoInfo['type']) == 'freeshipping')
                    {
                        $promoDiscountType = 'Free Shipping (Promo Code Discount)';
                    }
                    else if(strtolower($promoInfo['type']) == 'pwp')
                    {
                        $promoDiscountType = 'PWP (Promo Code Discount)';
                    }else{
                        $promoDiscountType = $promoInfo['name'];
                    }
                    $insertData = array(
                        'client_id'     => $res['client_id'],
                        'product_id'    => '',
                        'product_template_id'    => '',
                        'item_name'    => $promoDiscountType,
                        'item_price'    => $promoInfo['totalDiscount'],
                        'quantity'    => '1',
                        'subtotal'    => $promoInfo['totalDiscount'],
                        'sale_id'    => $saleID,
                        'type'    => 'promo_code',
                        'deleted'    => '',
                        'created_at'    => date('Y-m-d H:i:s')
                    );
                    $promo_amount = $promo_amount + $promoInfo['totalDiscount'];

                    $db->insert("sale_order_detail",$insertData);

                    // insert used_amount in mlm_promo_code table
                    $db->where('id', $promoInfo["id"]);
                    $promoUsedAmount = $db->getOne('mlm_promo_code', 'used_amount');
                    
                    if ($promoUsedAmount) {
                        $newUsedAmount = intval($promoUsedAmount['used_amount']) + 1;
                        $updateData = array('used_amount' => $newUsedAmount);
                        $db->where('id', $promoInfo["id"]);
                        $db->update('mlm_promo_code', $updateData);
                    }

                }
            }

            // Update Shipping Fee
            if(strtolower($paymentDelivery) == 'pickup') {
               
                $db->where("a.name", "Go Tasty Address");
                $db->where("a.disabled", 0);
                $db->join("state s", "s.id=a.state_id and s.country_id=a.country_id", "LEFT");
                $db->join("country c", "c.id=a.country_id", "LEFT");
                $pickupAddress = $db->getOne("address a", "a.address, a.post_code, a.city, s.name as state_name, c.name as country_name, a.phone as phone_number");
            
                $shipping_address = $pickupAddress["address"]."\n".$pickupAddress["post_code"]." ".$pickupAddress["city"]."\n".$pickupAddress["state_name"]."\n".$pickupAddress["country_name"];
                $shipping_phone = $pickupAddress['phone_number'];
                // $shipping_name = "";
                // $shipping_phone = "";
 
                $db->where('product_id', 0, '=');
                $db->where('sale_id', $saleID);
                $db->where('deleted', 0);
                $existDeliveryCharge = $db->get('sale_order_detail');
                if($existDeliveryCharge)
                {
                    $updateArray = array(
                        'deleted' => 1,
                        'updated_at' => $dateTime,
                    );
                    $db->where('product_id', 0, '=');
                    $db->where('type', 'shipping_fee');
                    $db->where('sale_id', $saleID);
                    $db->where('deleted', 0);
                    $db->update('sale_order_detail', $updateArray);
                }

                $shipping_fee = $cartInfo['data']['deliveryFee'];
                // insert into sale_order_detail
                $insertData = array(
                    'client_id'              => $res['client_id'],
                    'product_id'             => 0,
                    'product_template_id'    => 0,
                    'item_name'              => 'Self Pickup',
                    'item_price'             => 0.00,
                    'discount'               => 0.00000000,
                    'price_reduce'           => 0.00000000,
                    'quantity'               => 1,
                    'subtotal'               => 0.0000,
                    'sale_id'                => $saleID,
                    'type'                   => 'shipping_fee',
                    'deleted'                => 0,
                    'created_at'             => date('Y-m-d H:i:s'),
                );

                $db->insert('sale_order_detail', $insertData);
            }
            else{
                $db->where('product_id', 0, '=');
                $db->where('sale_id', $saleID);
                $db->where('deleted', 0);
                $existDeliveryCharge = $db->get('sale_order_detail');

                if($existDeliveryCharge)
                {
                    $updateArray = array(
                        'deleted' => 1,
                        'updated_at' => $dateTime,
                    );
                    $db->where('product_id', 0, '=');
                    $db->where('type', 'shipping_fee');
                    $db->where('sale_id', $saleID);
                    $db->where('deleted', 0);
                    $db->update('sale_order_detail', $updateArray);
                }

                //$params['sale_id'] = $saleID;
                //$params['delivery_method'] = 'delivery';
                //$delivery = Cash::freeDeliveryCondition2($params);
                // $shipping_fee = $delivery['data']['delivery_fee'];
                $shipping_fee = $cartInfo['data']['deliveryFee'];
                // insert into sale_order_detail
                $insertData = array(
                    'client_id'              => $res['client_id'],
                    'product_id'             => 0,
                    'product_template_id'    => 0,
                    'item_name'              => $cartInfo['data']['delivery_method'],
                    'item_price'             => $shipping_fee,
                    'discount'               => 0.00000000,
                    'price_reduce'           => 0.00000000,
                    'quantity'               => 1,
                    'subtotal'               => $shipping_fee,
                    'sale_id'                => $saleID,
                    'type'                   => 'shipping_fee',
                    'deleted'                => 0,
                    'created_at'             => date('Y-m-d H:i:s'),
                );
                $db->insert('sale_order_detail', $insertData);
            }

            //client info
            $client_phone 		   = $client_info["phone"];
            $client_email 		   = $client_info["email"];
            $first_name 	       = $client_info["name"];
            $last_name  	       = $client_info["username"];

            if($adminSOStatus)
            {
                if(strtolower($adminSOStatus) == 'paid')
                {
                    // use token to do clear cart action.
                    if(!empty($bkendToken))
                    {
                        $updateData = array(
                            'disabled'   => '1',
                            'updated_at' => date('Y-m-d H:i:s'),
                        );
                        $db->where('token', $bkendToken);
                        $db->update('shopping_cart', $updateData);
                    }
                    else
                    {
                        $updateData = array(
                            'disabled'   => '1',
                            'updated_at' => date('Y-m-d H:i:s'),
                        );
                        $db->where('client_id', $clientID);
                        $db->update('shopping_cart', $updateData);
                    }
                }
            }
            else
            {
                // use token to do clear cart action.
                if(!empty($bkendToken))
                {
                    $updateData = array(
                        'disabled'   => '1',
                        'updated_at' => date('Y-m-d H:i:s'),
                    );
                    $db->where('token', $bkendToken);
                    $db->update('shopping_cart', $updateData);
                }
                else
                {
                    $updateData = array(
                        'disabled'   => '1',
                        'updated_at' => date('Y-m-d H:i:s'),
                    );
                    $db->where('client_id', $clientID);
                    $db->update('shopping_cart', $updateData);
                }
            }

            $paymentAmount = $payment_amount;
            $totalDiscount = $promo_amount + $discount_amount;
            $releaseAmount = $payment_amount - $totalDiscount - ($cartInfo['data']['redeemAmount']) + $shipping_fee;

            if(!$promoCode)
            {
                $promoCode = '';
            }
            if(strtolower($paymentMethod) == "fpx")
            {
                $tempUpdateStatus = array("delivery_method" => $paymentDelivery, 
				    "shipping_name" => $shipping_name,
				    "shipping_phone" => $shipping_phone,
				    "shipping_address" => $shipping_address,
                    "payment_method" => $paymentMethod,
                    "status" => 'Pending Payment Approve',
                    'promotion_code' => $promoCode,
                    "shipping_fee" =>  $shippingFee,
                    "payment_amount" => $paymentAmount,
                    "discount_amount" => $totalDiscount,
                    "release_amount" => $releaseAmount,
                    "updated_at"    => $createdDate,
                    );

                $db->where("id",$saleID);
                $result = $db->update("sale_order", $tempUpdateStatus);

                $db->where("id",$clientID);
                $getUserInfo = $db->getOne("client");

                $db->where("id",$saleID);                    
                $soNo = $db->getValue("sale_order", 'so_no');

                $find = array("%%name%%", "%%username%%", "%%soNum%%", "%%paymentMethod%%", "%%ip%%", "%%location%%", "%%time%%");
                $replace = array($getUserInfo['name'],$getUserInfo['username'], $soNo,$paymentMethod,$ip,$ipInfo['country'], $createdDate);
                $outputArray = Client::sendTelegramMessage('10023', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
                
                $fpxParams['purchaseID'] = $saleID;
                $fpxParams['paymentAmount'] = $res['payment_amount'];
                $fpxParams['paymentCurrency'] = "MYR";
                $fpxParams['client_phone'] = $client_phone;
                $fpxParams['client_email'] = $client_email;
                $fpxParams['first_name']   = $first_name;
                $fpxParams['clientID'] = $res['client_id'];
                $fpxParams['txnTime'] = $createdDate;

                $verifyFPX = Self::fpxPaymentVerify($fpxParams);

                $try = Cash::retrieveValueForEmail($saleID, $clientID);
                // return array('status' => "error", 'code' => 1, 'statusMsg' => "See SEE", 'data' => $try);

                return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["B00523"][$language] /* Completed successfully */, 'data' => $verifyFPX); 
            }
            else if((strtolower($paymentMethod) == "") && $adminSOStatus)
            {
                $tempUpdateStatus = array("delivery_method" => $paymentDelivery, 
				    "shipping_name" => $shipping_name,
				    "shipping_phone" => $shipping_phone,
				    "shipping_address" => $shipping_address,
                    "payment_method" => $paymentMethod,
                    "status" => $adminSOStatus,
                    'promotion_code' => $promoCode,
                    "shipping_fee" =>  $shippingFee,
                    "payment_amount" => $paymentAmount,
                    "discount_amount" => $totalDiscount,
                    "release_amount" => $releaseAmount,
                    "updated_at"    => $createdDate,
                    );

                $db->where("id",$saleID);
                $result = $db->update("sale_order", $tempUpdateStatus);
            }
            else{
                $tempUpdateStatus = array("delivery_method" => $paymentDelivery, 
				    "shipping_name" => $shipping_name,
				    "shipping_phone" => $shipping_phone,
				    "shipping_address" => $shipping_address,
                    "payment_method" => $paymentMethod,
                    "payment_amount" => $payment_amount,
                    "discount_amount" => $totalDiscount,
                    "shipping_fee" => $shippingFee, 
                    "release_amount" => $releaseAmount,
                    'promotion_code' => $promoCode,
                    "updated_at"    => $createdDate,
                    );
            
                $db->where("id",$saleID);
                $result = $db->update("sale_order", $tempUpdateStatus);

                if(!$adminResetClientID)
                {
                    $db->where("id",$clientID);
                    $getUserInfo = $db->getOne("client");
                }
                else
                {
                    $db->where("id",$adminResetClientID);
                    $getUserInfo = $db->getOne("client");
                }

                $db->where("id",$saleID);                    
                $soNo = $db->getValue("sale_order", 'so_no');

                $find = array("%%name%%", "%%username%%", "%%soNum%%", "%%paymentMethod%%", "%%ip%%", "%%location%%", "%%time%%");
                $replace = array($getUserInfo['name'],$getUserInfo['username'], $soNo,$paymentMethod,$ip,$ipInfo['country'], $createdDate);
                $outputArray = Client::sendTelegramMessage('10023', NULL, NULL, $find, $replace,"","","telegramAdminGroup");
                
                $try = Cash::retrieveValueForEmail($saleID, $clientID);

                $data['sale_id'] = $saleID;
                $data['so_no'] = $soNo;
            }
            return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => '', 'data' => $data); 
        }

        public function promoCodeCalculation($params){
            $db              = MysqliDb::getInstance();
            $language        = General::$currentLanguage;
            $translations    = General::$translations;
            $dateTime        = date("Y-m-d H:i:s");
            $deliveryFee    = Setting::$systemSetting['deliveryFee'];

            $promoCode       = $params['promo_code'];
            $saleID          = $params['sale_id'];

            $db->where('code', $promoCode);
            $db->where('disabled', '0');
            $db->where('status', 'Active');
            $promoCodeResult = $db->getOne('mlm_promo_code');

            if(!$promoCodeResult)
            {
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01229"][$language] /* Promo Code not found, please check again */, 'data'=> "");
            }

            $db->where('promo_code_id', $promoCodeResult['id']);
            $db->where('disabled', '0');
            $promoCodeDetail = $db->get('promo_code_detail');

            if(!$promoCodeDetail)
            {
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01229"][$language] /* Promo Code not found, please check again */, 'data'=> "");
            }

            // get sale order
            unset($saleOrderDetail);
            $db->where('sale_id', $saleID);
            $db->where('deleted', '0');
            $db->where('type', array('package', 'product'), "IN");
            $saleOrderDetail = $db->get('sale_order_detail', null, 'subtotal, price_reduce, item_price, quantity');

            foreach ($saleOrderDetail as $order) {

                if(floatval($order['price_reduce']) <= 0)
                {
                    $subtotal = $order['subtotal'];
                    $totalSubtotal += floatval($subtotal);
                }
            }

            if($promoCodeResult['type'] == 'billDiscount')
            {
                if($promoCodeResult['discount_type'] == 'percentage')
                {
                    $promoDiscount = (floatval($totalSubtotal)) * (floatval($promoCodeResult['discount']) / 100);
                }
                else if($promoCodeResult['discount_type'] == 'amount')
                {
                    $promoDiscount = floatval($promoCodeResult['discount']);
                }
            }
            else if($promoCodeResult['type'] == 'freeShipping')
            {
                // get current shipping fee from sale_order table
                $db->where('id', $saleID);
                $saleOrderShippingFee = $db->getOne('sale_order', 'shipping_fee');

                if($promoCodeResult['discount_type'] == 'percentage')
                {
                    if(empty($promoCodeResult['discount']))
                    {
                        $promoDiscount = floatval($saleOrderShippingFee['shipping_fee']);
                    }
                    else
                    {
                        $promoDiscount = (floatval($promoCodeResult['discount']) / 100) * floatval($deliveryFee);
                    }
                }
                else if($promoCodeResult['discount_type'] == 'amount')
                {
                    if(empty($promoCodeResult['discount']))
                    {
                        $promoDiscount = floatval($saleOrderShippingFee['shipping_fee']);
                    }
                    else
                    {
                        $promoDiscount = floatval($promoCodeResult['discount']);
                    }
                }
            }/*else if($promoCodeResult['type'] == 'firstTimePurchase')
            {
                if($promoCodeResult['discount_type'] == 'percentage')
                {
                    if(empty($promoCodeResult['discount']))
                    {
                        $promoDiscount = floatval($saleOrderShippingFee['shipping_fee']);
                    }
                    else
                    {
                        $promoDiscount = (floatval($promoCodeResult['discount']) / 100) * floatval($deliveryFee);
                    }
                }
                else if($promoCodeResult['discount_type'] == 'amount')
                {
                    if(empty($promoCodeResult['discount']))
                    {
                        $promoDiscount = floatval($saleOrderShippingFee['shipping_fee']);
                    }
                    else
                    {
                        $promoDiscount = floatval($promoCodeResult['discount']);
                    }
                }
            }*/
            $promoDiscount = number_format($promoDiscount, 2);
            return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => $translations["B00523"][$language] /* Completed successfully */, 'data' => $promoDiscount); 
        }

        public function getPaymentDeliveryOptions(){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $userType = $db->userType;
            $createdDate = date("Y-m-d H:i:s");
            
            $db->where("type",'pamentMethod');
            $pamentMethod = $db->get("system_settings",null,"name,value");

            $data['pamentMethod'] = $pamentMethod;

            return array ('status' => 'ok' ,'code' => 0, 'statusMsg' => '', 'data' => $data); 
        }

        public function uploadReceipt($ReceiptParams) {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $clientID = $db->userID;

            $saleOrderID = trim($ReceiptParams['saleID']);
            $bkendToken  = trim($ReceiptParams['bkend_token']);
            $redeemAmount  = trim($ReceiptParams['redeemAmount']);

            if(!$clientID){
                $clientID = $ReceiptParams['clientID'];
                $fromPage = $ReceiptParams['fromPage'];
            }

            // get sale id from sale order detail table
            $db->where('deleted', 0);
            $db->where('sale_id', $saleOrderID);
            $saleID = $db->getOne('sale_order_detail');
            if($saleID)
            {
                $saleID = $saleID['sale_id'];
            }
            else
            {
                return array ('status' => 'error' ,'code' => 1, 'statusMsg' => $translations["B00524"][$language], 'data' => ""); // Payment not found.
            }
            // if(empty($ReceiptParams['data']) || empty($ReceiptParams['type']) || empty($ReceiptParams['fileName'])){
            //     return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["B00536"][$language], 'data'=>"");
            // }
            if(!$ReceiptParams['uploadImage'])
            {
                return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["B00536"][$language], 'data'=>"");
            }

            $refund    = $dataIn['refund']!= ""  ? $dataIn['refund'] : 0;
            $discountAmount = 0;
            $redeemAmount = strval($dataIn['redeemAmount']);

            // check redeemAmount is enough or not
            if(intval($redeemAmount) != 0 || !empty($redeemAmount))
            {
                $creditBalance = Cash::getBalance($clientID, 'gotastyCredit');
                $redeemAmount = number_format($redeemAmount, 2, '.', '');
                if($creditBalance < floatval($redeemAmount))
                {
                    return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01201"][$language] /* Insufficient Point Balance */, 'data' => $creditBalance, 'redeemAmount' => $redeemAmount);
                }
            }

            // $file        = $ReceiptParams['fileName'];
            // $fileArray   = explode('.', $file);
            // $fileName    = $fileArray[0];
            // $fileExt     = $fileArray[1];
            // $newFileName = $fileName.".".$fileExt;

            // $fields = array("data", "type", "created_at", "reference_id", "file_type" ,"file_name", "deleted");
            // $values = array($ReceiptParams['data'], $ReceiptParams['type'], date("Y-m-d H:i:s"), $saleID, $fileExt, $ReceiptParams['fileName'], 0);
            // $arrayData = array_combine($fields, $values);

            // $uploadId = $db->insert("uploads", $arrayData);

            $uploadImage = $ReceiptParams['uploadImage'];
            foreach($uploadImage as $key => $val) {
            if (strpos($val['imgData'], 'https') !== false) {
                $imageUrl = $val['imgData'];
                $fileType = pathinfo($val['imgName'], PATHINFO_EXTENSION);
                $insertReceipt = array(
                    "file_name" => $val['imgName'],
                    "type" => "receipt",
                    "data" => $imageUrl,
                    "reference_id" => $saleID,
                    "created_at"   => date("Y-m-d H:i:s"),
                    "file_type" => $fileType,
                    "deleted"   => "0"
                );
                $insertReceipt = $db->insert('uploads', $insertReceipt);
   
            }
        }

            if(!$insertReceipt){
                return array('status' => "error", 'code' => 1, 'statusMsg' => 'error upload.', 'data'=>"");
            }

            // insert into payment_gateway_details table
            unset($insertData);
            // get client email
            $db->where('id',$clientID);
            $clientDetail = $db->getOne('client');

            //get Sale order table details
            $db->where('id', $saleID);
            $saleOrderDetail = $db->getOne('sale_order');

            $insertData = array(
                            "purchase_id" => $saleID,
                            "type" => $saleOrderDetail['payment_method'],
                            "buyer_id" => $clientID,
                            "buyer_email" => $clientDetail['email'],
                            "seller_email" => "",
                            "currency" => "MYR",
                            "purchase_amount" => $saleOrderDetail['payment_amount'],
                            "tax" => "0",
                            "transaction_id" => "",
                            "payment_fee" => 0,
                            "payment_type" => $getSystemSetting['name'],
                            "payment_date" => $saleOrderDetail['created_at'],
                            "payment_status" => "",
                            "paypal_verify_status" => "",
                            "reason" => "",
                            "call_back" => "",
                            "updated_at" => date('Y-m-d H:i:s'),
                            "created_at" => $saleOrderDetail['created_at'],
                            );

            // check if the payment gateway details is existing in table or not
            $db->where('purchase_id', $saleID);
            $result = $db->get('payment_gateway_details');
            if(empty($result))
            {
                // insert into payment gateway details
                $db->insert('payment_gateway_details',$insertData);
            }
            else
            {
                $db->where('purchase_id', $saleID);
                $db->update('payment_gateway_details',$insertData);
            }

            if(!$fromPage){
                // $tempUpdateStatus = array("disabled" => 1);
                // $db->where("sale_id", $saleID);
                // $result = $db->update("shopping_cart", $tempUpdateStatus);
    
                unset($tempUpdateStatus);
                $tempUpdateStatus = array("status" => 'Pending Payment Approve');
                $db->where("id",$saleID);
                $db->where("status",'Draft');
                $result = $db->update("sale_order", $tempUpdateStatus);
                // $try = Cash::retrieveValueForEmail($saleID, $clientID);
                // return array('status' => "error", 'code' => 1, 'statusMsg' => "See SlsmdamEE", 'data' => $try);
            }

            // Deduct point for member
            if(intval($redeemAmount) != 0 || !empty($redeemAmount))
            {
                $db->where("name", "creditAdjustment");
                $result     = $db->getValue ("client", "id");
                $accountID  = $clientID;
                $receiverID = $result;
                $subject = 'Redeem Reward'; 
                $activityCode = 'L00005';
                $titleCode = 'T00005';

                $adjustAmount =  $redeemAmount;
                $batchID        = $db->getNewID();
                $belongID       = $db->getNewID();
                try
                {
                    //Wallet::creditAdjustment($dataIn);
                    Cash::insertTAccount($accountID, $receiverID, 'bonusDef', $adjustAmount, $subject, $belongID, $purchase_id, $db->now(), $batchID, $client_id, $remark);
                    // Cash::insertTAccount(8,$clientID, 'bonusDef', $adjustAmount, 'Redeem Reward', $batchID, "", $db->now(), $batchID, $clientID, $remark);
                }
                catch(Exception $e)
                {
                    return array('status' => "error", 'code' => 2, 'statusMsg' => $translations["E00131"][$language] /* Update failed. */, 'data' => "");
                }
            }

            // if(!$fromPage){
            //     // use token to do clear cart action.
            //     if(!empty($bkendToken))
            //     {
            //         $updateData = array(
            //             'disabled'   => '1',
            //             'updated_at' => date('Y-m-d H:i:s'),
            //         );
            //         $db->where('token', $bkendToken);
            //         $db->update('shopping_cart', $updateData);
            //     }
            //     else
            //     {
            //         $updateData = array(
            //             'disabled'   => '1',
            //             'updated_at' => date('Y-m-d H:i:s'),
            //         );
            //         $db->where('client_id', $clientID);
            //         $db->update('shopping_cart', $updateData);
            //     }
            // }

            $data['sale_id'] = $saleID;
            return array('status' => "ok", 'code' => 0, 'statusMsg' => '', 'data' => $data);
        }

        public function getReceipt($params) {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $saleID = (string)$params['saleID'];  

            if (empty($saleID)){
                return array ('status' => 'error' ,'code' => 2, 'statusMsg' => 'Sale ID not found.', 'data' => ""); 
            }

            if ($saleID > 0) {
                $db->where('type', 'receipt');
                $db->where('reference_id', $saleID);
                $res = $db->getOne("uploads");

                if ($res) {
                    $data["data"] = $res['data'];
                    $data["file_name"] = $res['file_name'];
                }
                unset($res);
            }
            return array('status' => "ok", 'code' => 0, 'statusMsg' => '', 'data' => $data);
        }

        public function getDeliveryMethod($params) {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $bkendToken = $params['bkend_token'];

            $db->where('token', $bkendToken);
            $saleId = $db->getValue('guest_token', 'sale_id');
            
            if($saleId){
                $params['sale_id'] = $saleId;
                $params['delivery_method'] = 'delivery';
                $delivery = Cash::freeDeliveryCondition2($params);
            }else{
                return array ('status' => 'error' ,'code' => 2, 'statusMsg' => 'Sale ID not found.', 'data' => ""); 
            }

            $db->where('name', 'Go Tasty Address');
            $db->where('remarks', 'go tasty self pickup address');
            $selfPickUpAddress = $db->getOne('address', 'address, post_code, city, state_id, country_id');
            $selfPickUpAddress = $selfPickUpAddress['address'] . ', ' . $selfPickUpAddress['post_code'] . ', ' . $selfPickUpAddress['city'];

            
            $deliveryMethodDetails[0]['name'] = 'Self Pickup';
            $deliveryMethodDetails[0]['fees'] = 'FREE';
            $deliveryMethodDetails[0]['address'] = $selfPickUpAddress;

            $deliveryMethodDetails[1]['name'] = 'Delivery Charges';
            if($delivery['data']['delivery_fee']== 0){
                $deliveryMethodDetails[1]['fees'] = 'FREE';
            }else{
                $deliveryMethodDetails[1]['fees'] = $delivery['data']['delivery_fee'];
            }
            $deliveryMethodDetails[1]['address'] = '';
            $deliveryMethodDetails[1]['deliveryFees'] = $delivery['data']['delivery_fee'];

            
            $data = $deliveryMethodDetails;
            
            return array('status' => "ok", 'code' => 0, 'statusMsg' => '', 'data' => $data);
        }

        public function CheckOutCalculation($params,$userID)
        {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $deliveryFee = Setting::$systemSetting['deliveryFee'];
            
            $deliveryMethod = trim($params['deliveryMethod']);
            $bkendToken     = $params['bkend_token'];
            $redeemAmount   = $params['redeemAmount'];
            $promoCode      = trim($params['promo_code']);

            $clientID = $db->userID;
            if($clientID == null)
            {
                $clientID = $userID;
            }

            if($clientID == null)
            {
                $clientID = $params['userID'];
            }

            if($deliveryMethod != 'Pickup')
            {
                $shippingFee = $deliveryFee; 
            }
            else
            {
                $shippingFee = 0;
            }


            // $db->where('token', $bkendToken);
            // $db->where('disabled', '0');
            // $shoppingCart = $db->get('shopping_cart',null, 'product_id, quantity');

            // use token to get sale id
            $db->where('token', $bkendToken);
            $saleID = $db->getOne('guest_token', 'sale_id');
            if($saleID)
            {
                $saleID = $saleID['sale_id'];

                // use the sale id get all product
                $db->where('sale_id', $saleID);
                $db->where('deleted', '0');
                $shoppingCart = $db->get('sale_order_detail');
            }
            else
            {
                return array("code" => 1, "status" => "error", "statusMsg" => $translations["M03402"][$language] /* Your cart is empty. */);
            }

            if($shoppingCart)
            {
                foreach($shoppingCart as $row)
                {
                    $db->where('id',$row['product_id']);
                    $cartDetails = $db->get('product',null,'sale_price');
                    foreach($cartDetails as $row2)
                    {
                        $cartDetail['salePrice'] = $row2['sale_price'] * $row['quantity'];
                        $cartTotal[] = $cartDetail;
                    }
                }
                // Loop through each item in the array and sum the salePrice values
                foreach ($cartTotal as $item) {
                    if (isset($item["salePrice"])) {
                        $total += $item["salePrice"];
                    }
                }

                // add shipping fee
                if ($total >= 280){
                    $deliveryFee = 'Free';
                    $shippingFee = 0;
                }

                // reward point discount, 200 points to RM1 is 0.005 calculation
                $discount = round(intval($redeemAmount) * 0.005, 2);
                $total = $total - $discount;

                // promo code discount
                if(!empty($promoCode))
                {
                    unset($dataIn);
                    $dataIn['promo_code'] = $promoCode;
                    $dataIn['sale_id']    = $saleID;
                    $promoCalculation = Cash::promoCodeCalculation($dataIn);
                    if($promoCalculation['status'] == 'error')
                    {
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01229"][$language] /* Promo Code not found, please check again */, 'data'=> "");
                    }
                }
                if(empty($promoCalculation['data']))
                {
                    $total = $total + $totalSubtotal + $shippingFee;
                    $total = round(floatval($total), 2);
                }
                else
                {
                    $total = $total + $totalSubtotal + $shippingFee - $promoCalculation['data'];
                    $total = round(floatval($total), 2);
                }

                $data['cartTotal'] = $total;
                $data['cartDetail'] = $cartTotal;
                $data['shippingFee'] = $shippingFee;
                $data['deliveryFee'] = $deliveryFee;
                $data['redeemAmount'] = $discount;
                if(!empty($promoCalculation['data']))
                {
                    $data['promoDiscount'] = $promoCalculation['data'];
                }
                else
                {
                    $data['promoDiscount'] = 0;
                }
                // Output the total sum
                return array("code" => 0, "status" => "ok", "data" => $data);
            }
            else
            {
                return array("code" => 1, "status" => "error", "statusMsg" => $translations["M03402"][$language] /* Your cart is empty. */);
            }
        }

        public function CartTotalAmountCalculation($params,$userID)
        {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $dateTime       = date("Y-m-d H:i:s");
            
            $deliveryMethod = trim($params['deliveryMethod']);
            $bkendToken     = $params['bkend_token'];
            $redeemAmount   = $params['redeemAmount'];
            $promoCode      = $params['promo_code'];
            $deliveryCharges = 0;
            $dryDelivery = 0;

            $clientID = $db->userID;
            if($clientID == null)
            {
                $clientID = $userID;
            }

            if($clientID == null)
            {
                $clientID = $params['userID'];
            }

            $db->where('token', $bkendToken);
            $db->where('disabled', 0);
            $getProductListArray = $db->get('shopping_cart', null, 'product_id');

            foreach($getProductListArray as $key => $value){
                $db->where('id', $value['product_id']);
                $setMethodforDelivery = $db->getValue('product', 'delivery_method');

                $db->where('id', $setMethodforDelivery);
                $getDeliveryMethodName = $db->getValue('gotasty_delivery_method', 'name');

                $setMethodforDeliveryArry[] = $getDeliveryMethodName;
            }

            if ($setMethodforDeliveryArry) {
                foreach ($setMethodforDeliveryArry as $value) {
                    if ($value == 'Delivery Charges') {
                        $deliveryCharges++;
                    } else if ($value == 'Dry Delivery Charges') {
                        $dryDelivery++;
                    } else if ($value == 'Self Pickup') {
                        $pickUp++;
                    }
                }
            }
            // return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01201"][$language] /* Insufficient Point Balance */, 'data' => $deliveryCharges, 'redeemAmount' => $getProductListArray);


            if($deliveryCharges != 0 && $dryDelivery != 0){
                $deliveryFee = Setting::$systemSetting['deliveryFee'];
            }
            else if($deliveryCharges != 0 && $dryDelivery == 0){
                $deliveryFee = Setting::$systemSetting['deliveryFee'];
            }
            else if($deliveryCharges == 0 && $dryDelivery != 0){
                $deliveryFee = Setting::$systemSetting['dryDeliveryFee'];
            }
            else{
                $deliveryFee = Setting::$systemSetting['deliveryFee'];
            }

            if($deliveryMethod != 'Pickup')
            {
                $shippingFee = $deliveryFee; 
            }
            else
            {
                $shippingFee = 0;
            }

            if($bkendToken)
            {
                // validate token
                $db->where('token', $bkendToken);
                $tokenValid = $db->getOne('guest_token');
            }

            if(empty($bkendToken) || !$tokenValid)
            {
                if(!empty($clientID))
                {
                    $db->orderBy('id', 'desc');
                    $db->where('client_id',$clientID);
                    $tokenExist = $db->getOne('guest_token');
                    if($tokenExist)
                    {
                        $bkendToken = $tokenExist['token'];
                    }
                }
            }

            $db->where("client_id",$clientID);
            $db->where("item_name","Redeemed Points");
            $redemptionPoint = $db->getOne("sale_order_detail");
            if(!$redemptionPoint){
                // check redeemAmount is enough or not
                if(intval($redeemAmount) != 0 || !empty($redeemAmount))
                {
                    $creditBalance = Cash::getBalance($clientID, 'gotastyCredit');
                    $redeemAmount = number_format($redeemAmount, 2, '.', '');
                    if($creditBalance < floatval($redeemAmount))
                    {
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01201"][$language] /* Insufficient Point Balance */, 'data' => $creditBalance, 'redeemAmount' => $redeemAmount);
                    }
                }
            }

            // $db->where('token', $bkendToken);
            // $db->where('disabled', '0');
            // $shoppingCart = $db->get('shopping_cart',null, 'product_id, quantity');

            // use token to get sale id
            $db->where('token', $bkendToken);
            $saleID = $db->getOne('guest_token', 'sale_id');
            if($saleID)
            {
                $saleID = $saleID['sale_id'];

                // use the sale id get all product
                $db->where('sale_id', $saleID);
                $db->where('deleted', '0');
                $shoppingCart = $db->get('sale_order_detail');
            }
            else
            {
                $data['cartTotal'] = 0;
                $data['cartDetail'] = 0;
                $data['shippingFee'] = 0;
                $data['deliveryFee'] = 0;
                $data['redeemAmount'] = 0;
                $data['promoDiscount'] = 0;
                return array("code" => 0, "status" => "ok", "data" => $data);
                // return array("code" => 1, "status" => "error", "statusMsg" => $translations["M03402"][$language] /* Your cart is empty. */);
            }

            // check auto apply promo code valid
            $db->where('disabled', '0');
            $db->where('apply_type', 'autoApply');
            $autoApplyPromoCodeList = $db->get('mlm_promo_code');

            if($autoApplyPromoCodeList)
            {
                foreach($autoApplyPromoCodeList as $key => $checkAutoApply)
                {
                    unset($paramIn);
                    $paramIn['promo_code'] = $checkAutoApply['code'];
                    $checkPromoCodeValidDate  = Inventory::checkPromoCodeValidDate($paramIn);
                    if(!$checkPromoCodeValidDate || $checkPromoCodeValidDate['status'] == 'ok')
                    {
                        $db->where('id', $checkAutoApply['id']);
                        $db->where('disabled', '0');
                        $db->where('status', 'Active');
                        $promoCodeResult = $db->getOne('mlm_promo_code');
    
                        if(($promoCodeResult['type'] == 'firstTimePurchase') && ($promoCodeResult['is_first_time_purchase'] == 1))
                        {
                            $db->where('client_id', $clientID);
                            $db->where('disabled', '0');
                            $db->where('promo_code_id', $promoCodeResult['id']);
                            $applyStatus = $db->getOne('so_service');
                            if($applyStatus)
                            {
                                unset($autoApplyPromoCodeList[$key]);
                            }
                        }
                    }
                }
            }

            $db->where('name', 'percentage');
            $db->where('type', 'marginPercen');
            $margin_percen = $db->getOne('system_settings','value');
            $margin_percen = $margin_percen['value'];
            if($shoppingCart)
            {
                foreach($shoppingCart as $row)
                {
                    if(!empty($promoCode))
                    {
                        // get promo code id
                        $db->where('code', $promoCode);
                        $db->where('status', 'Active');
                        $db->where('disabled', '0');
                        $promoCodeId = $db->getOne('mlm_promo_code');

                        if(!$promoCodeId)
                        {
                            return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01233"][$language] /* Invalid Promo Code */, 'data' => "");
                        }

                        $db->where('disabled', '0');
                        $db->where('promo_code_id', $promoCodeId['id']);
                        $db->where('product_id', $row['product_id']);
                        $getPromoPrice = $db->getOne('promo_code_product');
                    }
                    
                    $db->where('id',$row['product_id']);
                    $cartDetails = $db->get('product',null,'sale_price');
                    foreach($cartDetails as $row2)
                    {
                        $db->where('disabled', '0');
                        // $db->where("(start_date <= ? AND end_date >= ?)", [$dateTime, $dateTime]);
                        $db->where(" '".$dateTime."' BETWEEN start_date AND end_date " );
                        $db->where('product_id', $row['product_id']);
                        $pricelist_detail = $db->getOne('pricelist_detail');

                        if($pricelist_detail)
                        {
                            // get product price
                            $db->where('id', $row['product_id']);
                            $productInventoryDetail = $db->getOne('product');

                            if(strtolower($pricelist_detail['discount_type']) == 'percentage')
                            {
                                $latestPrice = floatval($productInventoryDetail['sale_price']) - (floatval($productInventoryDetail['sale_price']) * (floatval($pricelist_detail['discount']) / 100));
                            }
                            if(strtolower($pricelist_detail['discount_type']) == 'fixed')
                            {
                                $latestPrice = floatval($productInventoryDetail['sale_price']) - floatval($pricelist_detail['discount']);
                            }
                            $row2['sale_price'] = $latestPrice;
                        }

                        if($getPromoPrice)
                        {
                            $cartDetail['salePrice'] = bcmul(number_format($getPromoPrice['sale_price'],2),$row['quantity'],2);
                        }
                        else
                        {
                            $cartDetail['salePrice'] = number_format($row2['sale_price'], 2) * $row['quantity'];
                        }
                        
                        if($autoApplyPromoCodeList)
                        {
                            foreach($autoApplyPromoCodeList as $key => $detailPromoChecking)
                            {
                                $autoApplyDiscount = 0;
                                $db->where('pcd.disabled', '0');
                                $db->where('pcd.promo_code_id', $detailPromoChecking['id']);
                                $db->where('pcd.product_id', $row['product_id']);
                                $db->join('product p', 'p.id = pcd.product_id', 'LEFT');
                                $getAutoPromoPrice = $db->getOne('promo_code_detail pcd');

                                if($detailPromoChecking['type'] != 'PWP2')
                                {
                                    if($getAutoPromoPrice)
                                    {
                                        unset($latestSalePrice);
                                        unset($latestTotal);
                                        $db->where('id', $getAutoPromoPrice['promo_code_id']);
                                        $db->where('disabled', '0');
                                        $db->where('status', 'Active');
                                        $promoCodeDetail = $db->getOne('mlm_promo_code');
            
                                        if(($getAutoPromoPrice['product_id'] == $cartRow['product_id']) && ($getAutoPromoPrice['quantity'] <= $cartRow['quantity']))
                                        {
                                            if($promoCodeDetail['discount_type'] == 'percentage')
                                            {
                                                $discountPrice = (floatval($getAutoPromoPrice['sale_price']) * floatval($promoCodeDetail['discount'] / 100));
                                                $multipleDiscountPrice = $discountPrice * intval($row['quantity']);
                                                if(!empty($promoCodeDetail['max_discount_amount']))
                                                {
                                                    if($multipleDiscountPrice >= floatval($promoCodeDetail['max_discount_amount']))
                                                    {
                                                        $multipleDiscountPrice = floatval($promoCodeDetail['max_discount_amount']);
                                                        $autoApplyDiscount = $multipleDiscountPrice + $autoApplyDiscount;
                                                    }
                                                    else
                                                    {
                                                        $autoApplyDiscount = $autoApplyDiscount + $multipleDiscountPrice;
                                                        $multipleDiscountPrice = 0;
                                                    }
                                                }
                                                $latestSalePrice = floatval($getAutoPromoPrice['sale_price']) - $discountPrice;
                                                if($multipleDiscountPrice > 0)
                                                {
                                                    $cartDetail['salePrice'] = bcmul(number_format($getAutoPromoPrice['sale_price'], 2), $row['quantity'], 2) - $multipleDiscountPrice;
                                                }
                                                else
                                                {
                                                    $cartDetail['salePrice'] = bcmul(number_format($latestSalePrice, 2), $row['quantity'], 2);
                                                }
                                            }
                                            else
                                            {
                                                $latestSalePrice = number_format($getAutoPromoPrice['sale_price'], 2) - $promoCodeDetail['discount'];
                                                $autoApplyDiscount = $autoApplyDiscount + $promoCodeDetail['discount'];
                                                $cartDetail['salePrice'] = bcmul(number_format($latestSalePrice, 2), $row['quantity'], 2);
                                            }
                                        }
                                    }
                                }
                                else
                                {
                                    $db->where('pcp.disabled', '0');
                                    $db->where('pcp.promo_code_id', $detailPromoChecking['id']);
                                    // $db->where('pcp.product_id', $cartRow['product_id']);   
                                    $db->join('product p', 'p.id = pcp.product_id', 'LEFT');
                                    $getAutoPromoProductPrice = $db->getOne('promo_code_product pcp');

                                    unset($paramIn);
                                    $paramIn['promo_code'] = $detailPromoChecking['code'];
                                    $checkPromoCodeValidDate  = Inventory::checkPromoCodeValidDate($paramIn);

                                    if(!$checkPromoCodeValidDate || $checkPromoCodeValidDate['status'] != 'ok')
                                    {
                                        return array('status' => "error", 'code' => 1, 'statusMsg' => $checkPromoCodeValidDate['statusMsg'], 'data'=> $paramIn);
                                    }

                                    $db->where('promo_code_id', $detailPromoChecking['id']);
                                    $db->where('disabled', '0');
                                    $promoCodeDetail = $db->get('promo_code_detail');

                                    if(!$promoCodeDetail)
                                    {
                                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01229"][$language] /* Promo Code not found, please check again */, 'data'=> "");
                                    }

                                    $cartProductIds = array_column($shoppingCart, 'product_id');
                                    $promoProductIds = array_column($promoCodeDetail, 'product_id');
                                    if (count(array_diff($promoProductIds, $cartProductIds)) <= 0) {
                                        // return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01233"][$language] /* Invalid Promo Code */, 'data' => "", 'promoProductIds' => $promoProductIds, 'cartProductIds' => $cartProductIds);
                                        $matchingDetail = null;
                                        foreach ($promoCodeDetail as $codeDetail) {
                                            if ($codeDetail['product_id'] == $row['product_id']) {
                                                $matchingDetail = $codeDetail;
                                                break;
                                            }
                                        }
                                        if ($matchingDetail !== null && $matchingDetail['quantity'] == $row['quantity']) {
                                            // return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01233"][$language] /* Invalid Promo Code */, 'data' => "");
                                            $db->where('pcp.promo_code_id', $detailPromoChecking['id']);
                                            $db->where('pcp.disabled', '0');
                                            $db->where('mpc.type', 'PWP2');
                                            $db->join('mlm_promo_code mpc', 'mpc.id = pcp.promo_code_id');
                                            $promoCodeProduct = $db->get('promo_code_product pcp');
                                            if($promoCodeProduct)
                                            {
                                                foreach($promoCodeProduct as $promoProduct)
                                                {
                                                    $db->where('product_id', $promoProduct['product_id']);
                                                    $db->where('status', 'Active');
                                                    $stockAvailable = $db->get('stock');
                                                    if(!$stockAvailable)
                                                    {
                                                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01233"][$language] /* Invalid Promo Code */, 'data' => "");
                                                    }
                                                    $db->where('id', $detailPromoChecking['id']);
                                                    $db->where('status', 'Active');
                                                    $db->where('disabled', '0');
                                                    $promoCodeId = $db->getOne('mlm_promo_code');
                                                }
                                            }
                                        }
            
                                    }
                                    foreach($promoCodeProduct as $pwpDetailProduct)
                                    {
                                        if($row['product_id'] == $pwpDetailProduct['product_id'])
                                        {
                                            if($row['quantity'] <= $pwpDetailProduct['max_quantity'])
                                            {
                                                $originalTotal = number_format($row2['sale_price'], 2) * $row['quantity'];
                                                $Total           = bcmul(number_format($cart['price'],2),$promoCodeId['max_quantity'],2);
                                                $cartDetail['salePrice']   = bcmul(number_format($pwpDetailProduct['sale_price'],2),$row['quantity'],2);
                                                $autoApplyDiscount = bcsub($originalTotal, $cartDetail['salePrice'], 2);
                                            }
                                            else
                                            {
                                                $originalTotal = number_format($row2['sale_price'], 2) * $row['quantity'];
                                                $temporaryTotal = bcmul(number_format($pwpDetailProduct['sale_price'],2),$promoCodeId['max_quantity'],2);
                                                $currentQuantity = $row['quantity'] - $promoCodeId['max_quantity'];
                                                $Total = number_format($row2['sale_price'], 2) * $currentQuantity;
                                                $cartDetail['salePrice']   = $Total + floatval($temporaryTotal);
                                                $autoApplyDiscount = bcsub($originalTotal, $cartDetail['salePrice'], 2);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $cartTotal[] = $cartDetail;
                    }
                }
                // Loop through each item in the array and sum the salePrice values
                foreach ($cartTotal as $item) {
                    if (isset($item["salePrice"])) {
                        $total += $item["salePrice"];
                    }
                }

                // add shipping fee
                if ($total >= 280){
                    $deliveryFee = 'Free';
                    $shippingFee = 0;
                }

                // reward point discount, 200 points to RM1 is 0.005 calculation
                $discount = round(intval($redeemAmount) * 0.005, 2);
                $total = $total - $discount;

                // promo code discount
                if(!empty($promoCode))
                {
                    unset($dataIn);
                    $dataIn['promo_code'] = $promoCode;
                    $dataIn['sale_id']    = $saleID;
                    $promoCalculation = Cash::promoCodeCalculation($dataIn);
                    if($promoCalculation['status'] == 'error')
                    {
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01229"][$language] /* Promo Code not found, please check again */, 'data'=> "");
                    }
                }

                if($promoCalculation)
                {
                    $total = $total + $shippingFee - $promoCalculation['data'];
                }
                else
                {
                    $total = $total + $shippingFee;
                }
                $data['cartTotal'] = $total;
                $data['cartDetail'] = $cartTotal;
                $data['shippingFee'] = $shippingFee;
                $data['deliveryFee'] = $deliveryFee;
                $data['redeemAmount'] = $discount;
                if($promoCalculation)
                {
                    $promoDiscount = round(floatval($promoCalculation['data']), 2) + $autoApplyDiscount;
                    $data['promoDiscount'] = $promoDiscount;
                }
                else
                {
                    $data['promoDiscount'] = 0 + $autoApplyDiscount;
                }
                // Output the total sum
                return array("code" => 0, "status" => "ok", "data" => $data);
            }
            else
            {
                $data['cartTotal'] = 0;
                $data['cartDetail'] = 0;
                $data['shippingFee'] = 0;
                $data['deliveryFee'] = 0;
                $data['redeemAmount'] = 0;
                $data['promoDiscount'] = 0;
                return array("code" => 0, "status" => "ok", "data" => $data);
                // return array("code" => 1, "status" => "error", "statusMsg" => $translations["M03402"][$language] /* Your cart is empty. */);
            }
        }

        public function CartTotalAmountCalculationMember($params,$userID)
        {
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $deliveryFee = Setting::$systemSetting['deliveryFee'];
            
            $deliveryMethod = trim($params['deliveryMethod']);
            $redeemAmount   = $params['redeemAmount'];
            $promoCode      = $params['promo_code'];

            $clientID = $db->userID;

            if(!$clientID)
            {
                $clientID = $userID;
            }

            if(!$clientID)
            {
                $clientID = $params['userID'];
            }

            if($deliveryMethod != 'Pickup')
            {
                $shippingFee = $deliveryFee; 
            }
            else
            {
                $shippingFee = 0;
            }

            if(!empty($clientID))
            {
                $db->orderBy('id', 'desc');
                $db->where('client_id',$clientID);
                $tokenExist = $db->getOne('guest_token');
                if($tokenExist)
                {
                    $bkendToken = $tokenExist['token'];
                }
            }

            $db->where("client_id",$clientID);
            $db->where("item_name","Redeemed Points");
            $redemptionPoint = $db->getOne("sale_order_detail");
            if(!$redemptionPoint){
                // check redeemAmount is enough or not
                if(intval($redeemAmount) != 0 || !empty($redeemAmount))
                {
                    $creditBalance = Cash::getBalance($clientID, 'gotastyCredit');
                    $redeemAmount = number_format($redeemAmount, 2, '.', '');
                    if($creditBalance < floatval($redeemAmount))
                    {
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01201"][$language] /* Insufficient Point Balance */, 'data' => $creditBalance, 'redeemAmount' => $redeemAmount);
                    }
                }
            }

            // $db->where('token', $bkendToken);
            // $db->where('disabled', '0');
            // $shoppingCart = $db->get('shopping_cart',null, 'product_id, quantity');

            // use token to get sale id
            if(!empty($bkendToken))
            {
                $db->where('token', $bkendToken);
                $saleID = $db->getOne('guest_token', 'sale_id');
                $saleID = $saleID['sale_id'];
            }
            else
            {
                $db->where('client_id', $clientID);
                $db->where('disabled', 0);
                $saleID = $db->getValue('shopping_cart', 'sale_id');
            }

            if($saleID)
            {
                // $saleID = $saleID['sale_id'];

                // use the sale id get all product
                $db->where('sale_id', $saleID);
                $db->where('deleted', '0');
                $shoppingCart = $db->get('sale_order_detail');
            }
            else
            {
                $data['cartTotal'] = 0;
                $data['cartDetail'] = 0;
                $data['shippingFee'] = 0;
                $data['deliveryFee'] = 0;
                $data['redeemAmount'] = 0;
                $data['promoDiscount'] = 0;
                return array("code" => 0, "status" => "ok", "data" => $data);
                // return array("code" => 1, "status" => "error", "statusMsg" => $translations["M03402"][$language] /* Your cart is empty. */);
            }

            $db->where('name', 'percentage');
            $db->where('type', 'marginPercen');
            $margin_percen = $db->getOne('system_settings','value');
            $margin_percen = $margin_percen['value'];
            if($shoppingCart)
            {
                foreach($shoppingCart as $row)
                {
                    $db->where('id',$row['product_id']);
                    $cartDetails = $db->get('product',null,'sale_price');
                    foreach($cartDetails as $row2)
                    {
                        $cartDetail['salePrice'] = $row2['sale_price'] * $row['quantity'];
                        $cartTotal[] = $cartDetail;
                    }
                }
                // Loop through each item in the array and sum the salePrice values
                foreach ($cartTotal as $item) {
                    if (isset($item["salePrice"])) {
                        $total += $item["salePrice"];
                    }
                }

                // add shipping fee
                if ($total >= 280){
                    $deliveryFee = 'Free';
                    $shippingFee = 0;
                }

                // reward point discount, 200 points to RM1 is 0.005 calculation
                $discount = round(intval($redeemAmount) * 0.005, 2);
                $total = $total - $discount;

                // promo code discount
                if(!empty($promoCode))
                {
                    unset($dataIn);
                    $dataIn['promo_code'] = $promoCode;
                    $dataIn['sale_id']    = $saleID;
                    $promoCalculation = Cash::promoCodeCalculation($dataIn);
                    if($promoCalculation['status'] == 'error')
                    {
                        return array('status' => "error", 'code' => 1, 'statusMsg' => $translations["E01229"][$language] /* Promo Code not found, please check again */, 'data'=> "");
                    }
                }

                if($promoCalculation)
                {
                    $total = $total + $shippingFee - floatval($promoCalculation['data']);
                }
                else
                {
                    $total = $total + $shippingFee;
                }

                // $total = $total + $shippingFee;
                $data['cartTotal'] = $total;
                $data['cartDetail'] = $cartTotal;
                $data['shippingFee'] = $shippingFee;
                $data['deliveryFee'] = $deliveryFee;
                $data['redeemAmount'] = $discount;
                if($promoCalculation)
                {
                    $promoDiscount = round(floatval($promoCalculation['data']), 2);
                    $data['promoDiscount'] = $promoDiscount;
                }
                else
                {
                    $data['promoDiscount'] = 0;
                }
                // Output the total sum
                return array("code" => 0, "status" => "ok", "data" => $data);
            }
            else
            {
                $data['cartTotal'] = 0;
                $data['cartDetail'] = 0;
                $data['shippingFee'] = 0;
                $data['deliveryFee'] = 0;
                $data['redeemAmount'] = 0;
                $data['promoDiscount'] = 0;
                return array("code" => 0, "status" => "ok", "data" => $data);
                // return array("code" => 1, "status" => "error", "statusMsg" => $translations["M03402"][$language] /* Your cart is empty. */);
            }
        }

        public function retrieveValueForEmail($saleID, $clientID){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;
            $db->where("id",$saleID);                    
            $soDetail = $db->getOne("sale_order");

            $db->where("id",$clientID);
            $getUserInfo = $db->getOne("client");


            if (strtolower($soDetail['delivery_method']) == 'delivery') {
                $delivery = 'Deliver to:';
                $emailName = $soDetail['shipping_name'];
                $emailAddress = Cash::concateAddress($saleID, 'sale_order_shipping');
            } else {
                $delivery = 'Pick up at:';
                $emailName = 'Go Tasty Sdn. Bhd.';
                $emailAddress = $soDetail['shipping_address'];
            }
            
            $emailPhone = $soDetail['shipping_phone'];
            $emailAddress = str_replace("\n", '<br>', $emailAddress);
            $emailAddress = rtrim($emailAddress, ',<br>'); // Remove trailing comma and <br>
            
            $searchTerm = "Go Tasty Sdn. Bhd.";
            
            $pos = strpos($emailAddress, $searchTerm);
            if ($pos !== false) {
                $emailAddress = trim(substr($emailAddress, $pos + strlen($searchTerm)));
            }

            $db->where("sale_id", $saleID);
            $db->where("deleted", 0);
            $productsInfo = $db->get("sale_order_detail",null, "item_name, item_price, discount, price_reduce, quantity, subtotal, type");

            foreach ($productsInfo as $product) {
                //if($product['type'] != 'shipping_fee' && $product['item_name'] != 'Delivery Charges Free Shipping' ){
                $productname = $product['item_name'];
                $quantity = $product['quantity'];
                $price = number_format($product['item_price'], 2);
                $subtotal_product = number_format($product['subtotal'], 2);
                
                $content .= "
                <tr class=\"tableContent\">
                    <td>$productname</td>
                    <td>$quantity</td>
                    <td>RM$price</td>
                    <td>RM$subtotal_product</td>
                </tr>";
                //}
            }

            $db->where("name", 'Order Status URL');
            $URL = $db->getOne("system_settings");

            $link = $URL['value'] . "SONO=" . $soDetail['so_no'];;

            $shipping_fee_email = $soDetail['shipping_fee'];
            $total = number_format($soDetail['payment_amount'], 2);
            $emailFind = array("%%orderNumber%%", "%%delivery%%", "%%name%%", "%%address%%", "%%phone%%", "%%content%%","%%shippingFee%%", "%%total%%", "%%link%%");
            $emailReplace = array($soDetail['so_no'], $delivery, $emailName, $emailAddress, $emailPhone, $content, $shipping_fee_email, $total, $link);
            $outputEmail = Client::createEmailNotification('10027', $emailFind, $emailReplace, $getUserInfo['email']);
        }

        public function freeDeliveryCondition2($params){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $deliveryMethod = $params['delivery_method'];
            $saleID         = $params['sale_id'];
            $totalAmount    = $params['totalSaleAmount'];
            $postcode    = $params['postcode'];
            $deliveryMethod1 = $deliveryMethod;

            if (strtolower($deliveryMethod) == 'delivery' || $deliveryMethod == null)
            {


                ## get all delivery method
                $db->where('deleted', '0');
                $deliveryMethod = $db->get('gotasty_delivery_method');

                foreach($deliveryMethod as $tempData){
                    $deliveryMethodAry[$tempData["id"]] = $tempData;
                }


                $db->where('sod.sale_id',$saleID );
                $db->where('sod.deleted', 0);
                $db->where('sod.deleted', 0);
                $db->join('product p', 'p.id = sod.product_id', 'LEFT');
                $getProductListArray = $db->get('sale_order_detail sod', null , 'sod.id as id, sod.quantity as quantity, sod.product_id, sod.subtotal as subtotal , sod.price_reduce as priceReduce, p.delivery_method as deliveryMethodID');

         
                $db->where('deleted', '0');
                $focItem = $db->getOne('delivery_method_detail');

                # check if SO contain frozen food
                $existFrozen = array();

                # if delivery method = 3 (Dry Delivery Charges), and hit the total quantity requirement, should be free delivery charge
                # calculate total quantity
                $existDryQuantity = array();
                $otherQuantity = array();
                $focItemList = array();

                foreach($getProductListArray as $detailProduct)
                {
                    if($detailProduct['deliveryMethodID'] == '2')
                    {
                        $existFrozen[] = $detailProduct;
                        
                    }
                    else if($detailProduct['deliveryMethodID'] == '3')
                    {
                        $existDryQuantity[] = $detailProduct;
                    }
                    else
                    {
                        $otherQuantity[] = $detailProduct;
                    }
                    $itemQty[$detailProduct['deliveryMethodID']] +=  $detailProduct["quantity"];

                }

                if(count($existFrozen)){
                    $selectedDeliveryID = 2;
                    $selectedProductList = $existFrozen;
                }else{
                    $selectedDeliveryID = 3;
                    $selectedProductList = $existDryQuantity;
                }


                ## get postcode delivery fee ##
                if($postcode){
                    $db->where("delivery_method_id",$selectedDeliveryID);
                    $db->where(" ".$postcode." BETWEEN from_range AND to_range " );
                    $db->where("disable","0");
                    $db->orderBy("shipping_fee","DESC");
                    $deliveryMethodPostcode = $db->getOne('delivery_method_postcode');
                }


                

                $db->where('deleted', '0');
                $db->where('delivery_method_id', $selectedDeliveryID);
                $focItem = $db->get('delivery_method_detail');
                foreach($focItem as $tempData){
                    if($tempData["product_id"] == "")$tempData["product_id"] = 0;
                    $focItemList[$tempData["product_id"]] = $tempData; 
                }

                foreach($selectedProductList as $tempData){
                    if(isset($focItemList[$tempData["product_id"]])){

                        // check if free delivery having quantity constrain 
                        if($focItemList[$tempData["product_id"]]["quantity"] != 0){
                            if($focItemList[$tempData["product_id"]]["apply_condition"] == "single_item"){
                                if($tempData["quantity"] >= $focItemList[$tempData["product_id"]]["quantity"]){
                                    $is_foc = 1;
                                    break;
                                }
                            }else if($focItemList[$tempData["product_id"]]["apply_condition"] == "sum_total"){
                                $totalQty = 0;
                                // reloop to get all sum_total product item quantity
                                foreach($selectedProductList as $tempData2){
                                    if(isset($focItemList[$tempData2["product_id"]]) && $focItemList[$tempData2["product_id"]]["apply_condition"] == "sum_total"){
                                        $totalQty +=  $tempData2["quantity"];
                                    }
                                }

                                if($totalQty >= $focItemList[$tempData["product_id"]]["quantity"]){
                                    $is_foc = 1;
                                    break;
                                }
                            }
                        }else{ ##free without condition
                            $is_foc = 1;
                            break;
                        }
                    }
                }            

                if($totalAmount >= $focItemList[0]["amount"] && $focItemList[0]["amount"] != 0){
                    $is_foc = 1;
                }

                if($is_foc){
                    $deliveryMethodPostcode["shipping_fee"] = 0;
                    if(!$postcode)$delivery['delivery_fee'] = 0;
                    else $delivery['delivery_fee'] = $deliveryMethodPostcode["surcharge"];
                }else{
                    if(!$postcode)$delivery['delivery_fee'] = $deliveryMethodAry[$selectedDeliveryID]['price'];
                    else $delivery['delivery_fee'] = floatval($deliveryMethodPostcode["shipping_fee"]) + floatval($deliveryMethodPostcode["surcharge"]);
                }

                $delivery['delivery_method'] = $deliveryMethodAry[$selectedDeliveryID]['name'];
                $delivery['shipping_fee'] = !isset($deliveryMethodPostcode["shipping_fee"]) ?$deliveryMethodAry[$selectedDeliveryID]['price']: $deliveryMethodPostcode["shipping_fee"];
                $delivery['surcharge'] = $deliveryMethodPostcode["surcharge"];
                $delivery['deliveryAvailability'] = 1;

                ## not support ##
                if(!$deliveryMethodPostcode["from_range"] && $postcode){
                    $delivery['delivery_fee'] = 0;
                    $delivery['delivery_method'] = $deliveryMethodAry[$selectedDeliveryID]['name'];
                    $delivery['deliveryAvailability'] = 0;
                }
            }
            else if (strtolower($deliveryMethod) == 'pickup')
            {
                $db->where('id', '1');
                $db->where('deleted', '0');
                $deliveryMethodInfo = $db->getOne('gotasty_delivery_method');
                $delivery['delivery_fee'] = 0.00;
                $delivery['delivery_method'] = $deliveryMethodInfo['name'];
                $delivery['deliveryAvailability'] = 1;
            }
            $delivery['delivery_fee'] = number_format($delivery['delivery_fee'], 2, '.', '');
            return array("code" => 0, "status" => "ok", "statusMsg" =>  "Delivery fee", "data"=> $delivery,"deliveryMethod1" => $deliveryMethod1);
        }

        public function freeDeliveryCondition($params){
            $db = MysqliDb::getInstance();
            $language = General::$currentLanguage;
            $translations = General::$translations;

            $saleID = $params['sale_id'];
            $deliveryMethod = $params['delivery_method'];


            if (strtolower($deliveryMethod) == 'delivery' || $deliveryMethod == null) {
                $db->where('sod.sale_id',$saleID );
                $db->where('sod.deleted', 0);
                $db->join('product p', 'p.id = sod.product_id', 'LEFT');
                $getProductListArray = $db->get('sale_order_detail sod', null , 'sod.id as id, sod.quantity as quantity, sod.subtotal as subtotal , sod.price_reduce as priceReduce, p.delivery_method as deliveryMethodID');
                // return array("code" => 1, "status" => "error", "statusMsg" =>  "Li Heng Testing", "data"=> $getProductListArray);
                //decides which delivery method for this sales order
                $deliveryMethodID = 0;
                $deliveryFee = null;
                $itemCount = 0;
                $total = 0;
                foreach($getProductListArray as $row){
                    if($row['deliveryMethodID'] != null){
                        if($deliveryMethodID != $row['deliveryMethodID']){
                            $db->where('id',$row['deliveryMethodID'] );
                            $newDeliveryFee = $db->getValue('gotasty_delivery_method', 'price');

                            // if got one free delivery, then all will be free
                            if($newDeliveryFee == 0){
                                $deliveryFee = 0;
                                break;
                            }
                            if($newDeliveryFee > $deliveryFee || $deliveryFee == null){
                                //set new delivery Method when delivery method is higher
                                $deliveryFee = $newDeliveryFee;
                                $deliveryMethodID = $row['deliveryMethodID'];
                                if($itemCount == 0){
                                    $itemCount = $row['quantity'];
                                }else{
                                    $itemCount = 0;
                                }
                            }
                        }else{
                            //count quantity if using same delivery method
                            $itemCount = $itemCount + $row['quantity'];
                        }

                        if($row['priceReduce'] > 0){
                            $total = $total + ($row['priceReduce'] * $row['quantity']);
                        }else{
                            $total = $total + $row['subtotal'];
                        }
                    }
                }



                $db->where('dm.id', $deliveryMethodID);
                $db->where('dm.deleted', '0');
                $db->join('gotasty_delivery_method dm','dm.id = dmd.delivery_method_id', 'LEFT');
                $deliveryMethodPromo = $db->getOne('delivery_method_detail dmd', null, 'dmd.amount as amount, dmd.quantity as quantity, dm.id as id');


                if($deliveryMethodPromo['amount'] != null){
                    if ($total >= $deliveryMethodPromo['amount']){
                        $deliveryFee = 0;
                    }
                }
                if($deliveryMethodPromo['quantity'] != null){
                    if($itemCount >= $deliveryMethodPromo['quantity']){
                        $deliveryFee = 0;
                    }
                }
            }elseif(strtolower($deliveryMethod) == 'pickup'){
                $deliveryFee = 0;
                $deliveryMethodID = 1;
            }else{
                return array("code" => 1, "status" => "error", "statusMsg" => "Please provide delivery method");
            }

            $delivery['delivery_fee'] = number_format($deliveryFee,2);
            $db->where('id', $deliveryMethodID);
            $delivery['delivery_method'] = $db->getValue('gotasty_delivery_method', 'name');

            return array("code" => 0, "status" => "ok", "statusMsg" =>  "Delivery fee", "data"=> $delivery);
        }

        public function getSO_NO($params){
            $db = MysqliDb::getInstance();
            $soID = $params['id'];
            $db->where('id', $soID);
            $so_no = $db->getValue('sale_order', 'so_no');


            return array("code" => 0, "status" => "ok", "statusMsg" =>  "so number", "data"=> $so_no);

        }
        
    }
?>
