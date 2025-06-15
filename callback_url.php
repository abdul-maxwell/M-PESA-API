<?php
header("Content-Type: application/json");

// Default response
$response = '{
    "ResultCode": 0, 
    "ResultDesc": "Confirmation Received Successfully"
}';

// Get the raw callback data
$mpesaResponse = file_get_contents('php://input');

if (empty($mpesaResponse)) {
    die(json_encode([
        "ResultCode" => 1,
        "ResultDesc" => "Empty callback data received"
    ]));
}

// Log the response
$logFile = "M_PESAConfirmationResponse.json";
file_put_contents($logFile, $mpesaResponse, FILE_APPEND);

// Decode the JSON response
$callbackContent = json_decode($mpesaResponse);

if (json_last_error() !== JSON_ERROR_NONE) {
    die(json_encode([
        "ResultCode" => 1,
        "ResultDesc" => "Invalid JSON received: " . json_last_error_msg()
    ]));
}

// Check if required data exists
if (!isset($callbackContent->Body->stkCallback)) {
    die(json_encode([
        "ResultCode" => 1,
        "ResultDesc" => "Invalid callback structure"
    ]));
}

$stkCallback = $callbackContent->Body->stkCallback;
$Resultcode = $stkCallback->ResultCode ?? null;
$CheckoutRequestID = $stkCallback->CheckoutRequestID ?? null;

// Only process successful payments
if ($Resultcode == 0 && isset($stkCallback->CallbackMetadata)) {
    $metadata = $stkCallback->CallbackMetadata;
    $items = $metadata->Item ?? [];
    
    // Extract values from metadata
    $values = [];
    foreach ($items as $item) {
        if (isset($item->Name) && isset($item->Value)) {
            $values[$item->Name] = $item->Value;
        }
    }
    
    $Amount = $values['Amount'] ?? null;
    $MpesaReceiptNumber = $values['MpesaReceiptNumber'] ?? null;
    $PhoneNumber = $values['PhoneNumber'] ?? null;

    // Check if mysqli extension is available
    if (!extension_loaded('mysqli')) {
        error_log("MySQLi extension not loaded");
    } else {
        try {
            // Connect to DB - replace with your credentials
            $conn = new mysqli("localhost", "root", "", "mpesa_stk");
            
            // Check connection
            if ($conn->connect_error) {
                error_log("Connection failed: " . $conn->connect_error);
            } else {
                // Prepare statement to prevent SQL injection
                $stmt = $conn->prepare("INSERT INTO tinypesa 
                    (CheckoutRequestID, ResultCode, amount, MpesaReceiptNumber, PhoneNumber) 
                    VALUES (?, ?, ?, ?, ?)");
                
                $stmt->bind_param("sisss", 
                    $CheckoutRequestID, 
                    $Resultcode, 
                    $Amount, 
                    $MpesaReceiptNumber, 
                    $PhoneNumber);
                
                if (!$stmt->execute()) {
                    error_log("Error inserting record: " . $stmt->error);
                }
                
                $stmt->close();
                $conn->close();
            }
        } catch (Exception $e) {
            error_log("Database error: " . $e->getMessage());
        }
    }
}

echo $response;
