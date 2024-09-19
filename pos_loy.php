<?php
// 應用密鑰從 Loyverse 開發者平台獲取
$LOYVERSE_SECRET = 'your code'; 

// 確保立即回應 200 狀態碼
http_response_code(200);
echo 'Webhook received';

// 關閉輸出緩衝
if (ob_get_length()) {
    ob_end_flush();
}
flush();

// 日誌記錄函數
function log_to_file($message) {
    $logfile = 'poslog.log';
    file_put_contents($logfile, date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, FILE_APPEND);
}
// Discord Webhook URL
$webhookurl = 'discord webhook url';

// 函數：發送訊息到 Discord Webhook
function sendToDiscord($message) {
    global $webhookurl;

    $json_data = json_encode([
        "content" => $message,
        "username" => "交易紀錄",  // 可自定義用戶名
        "avatar_url" => "https://i.imgur.com/your.jpg",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookurl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// 紀錄收到的數據
$input = file_get_contents('php://input');
$data = json_decode($input, true);  // 將 JSON 字符串解碼為 PHP 關聯數組
// log_to_file("Received Data: " . json_encode($data)); 

$receipt = $data['receipts'][0];
$orderItems = $receipt['line_items'];
$payments = $receipt['payments'];
$totalAmount = $receipt['total_money'];
$totalTax = $receipt['total_tax'];
$refund_for = $receipt['refund_for'];
$cancelled_at = $receipt['cancelled_at'];


// 判斷載具類型
$CarrierNum = null;
$CarrierType = null;
foreach ($orderItems as $index => $item) {
    if ($item['item_name'] == "@發票載具") {
        $CarrierNum = $item['line_note'];
        $CarrierType = 0;
        unset($orderItems[$index]);
    } elseif ($item['item_name'] == "@ezpay載具") {
        $CarrierNum = $item['line_note'];
        $CarrierType = 2;
        unset($orderItems[$index]);
    }
}

//當確認"cancelled_at"、"refund_for"資料欄位為空，才開立發票
if ($refund_for !== null || $cancelled_at !== null ) {
    log_to_file($receipt['receipt_number'].'-此訂單為取消、或退款交易，不開發票');
    exit;
}

// 當載具為 null 時，發送訊息到 Discord 並記錄日誌
if ($CarrierNum === null) {
    $logMessage = '💼 '.date('Y-m-d H:i:s　').'B2B待開發票'."\n訂單編號".$receipt['receipt_number'].
	'　付款方式：'.$payments[0]['name'];
    
    // 記錄到日誌
    log_to_file($logMessage);
    
    // 發送訊息到 Discord
    sendToDiscord($logMessage);
	exit;
}




// 構建發票資料
$invoiceData = [
    'RespondType' => 'JSON',
    'Version' => '1.5',
    'TimeStamp' => time(),
    'MerchantOrderNo' => str_replace('-', '_', $receipt['receipt_number']),
    'Status' => '1',
    'Category' => 'B2C',
    'BuyerName' => 'Customer',
    'BuyerEmail' => $CarrierType == 2 ? $CarrierNum : null,
    'CarrierNum' => rawurlencode($CarrierNum),
    'CarrierType' => $CarrierType,
    'PrintFlag' => 'N',
    'TaxType' => '1',
    'TaxRate' => '5',
    'Amt' => $totalAmount - $totalTax,
    'TaxAmt' => $totalTax,
    'TotalAmt' => $totalAmount,
    'ItemName' => implode('|', array_column($orderItems, 'item_name')),
    'ItemCount' => implode('|', array_column($orderItems, 'quantity')),
    'ItemUnit' => implode('|', array_fill(0, count($orderItems), '個')), 
    'ItemPrice' => implode('|', array_column($orderItems, 'price')),
    'ItemAmt' => implode('|', array_column($orderItems, 'total_money')),
	'Comment' =>  '付款方式:'.$payments[0]['name']
];

// 填充函數，確保數據塊符合 AES 加密的塊大小需求
function addPadding($string, $blocksize = 32) {
    $len = strlen($string);
    $pad = $blocksize - ($len % $blocksize);
    $string .= str_repeat(chr($pad), $pad);
    return $string;
}

// ezPay 提供的 curl 發送函數
function curl_work($url = '', $parameter = '')
{
    $curl_options = array(
        CURLOPT_URL => $url,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'ezPay',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_POST => '1',
        CURLOPT_POSTFIELDS => $parameter
    );

    $ch = curl_init();
    curl_setopt_array($ch, $curl_options);
    $result = curl_exec($ch);
    $retcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_errno($ch);
    curl_close($ch);
    $return_info = array(
        'url' => $url,
        'sent_parameter' => $parameter,
        'http_status' => $retcode,
        'curl_error_no' => $curl_error,
        'web_info' => $result
    );
    return $return_info;
}

// 將發票數據轉換為查詢字符串
$postDataStr = http_build_query($invoiceData);

// 加密與發送到 ezPay
$SECRET_KEY = 'your key';
$IV = 'your IV';

$post_data = trim(bin2hex(openssl_encrypt(addPadding($postDataStr), 
'AES-256-CBC', $SECRET_KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $IV)));

// 發送至 ezPay
$url = 'https://cinv.ezpay.com.tw/Api/invoice_issue';
$transactionData = array(
    'MerchantID_' => '12345678',
    'PostData_' => $post_data
);

$transactionDataStr = http_build_query($transactionData);
$result = curl_work($url, $transactionDataStr);

// 檢查結果並記錄日誌
if ($result['curl_error_no'] !== 0) {
    log_to_file('Error in sending request to ezPay: ' . $result['curl_error_no']);
} else {
    log_to_file('ezPay response: ' . json_encode($result['web_info']));  // 記錄完整的 ezPay 回應
}


//解碼外層的json
$responseData = json_decode($result['web_info'], true);
$message = $responseData['Message'];
// 解碼 Result 內的嵌套 JSON
$resultData = json_decode($responseData['Result'], true);


// 檢查結果並記錄日誌以及發送 Discord 訊息
if ($responseData['Status'] === 'SUCCESS') {
    // 發票開立成功
    log_to_file(json_encode($responseData));  // 記錄成功的回應
    $successMessage = "✅ 發票開立成功！".date('Y-m-d H:i:s　')."\n訂單編號：".$receipt['receipt_number']."　發票號碼：".$resultData['InvoiceNumber'] 
	. "\n- 總金額：NT$".$totalAmount."　付款方式：" . $payments[0]['name']."\n".$item['item_name'];
    sendToDiscord($successMessage);
	
} else {
    // 發票開立失敗，根據錯誤代碼回應
    $errorCode = '❌ 發票開立失敗！'.$message."\n訂單編號：".$receipt['receipt_number'];
	sendToDiscord($errorCode);
}


?>
