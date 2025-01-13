<?php
// namespace WHMCS\Module\Addon\MidtransReport;
function midtrans_report_config() {
    return [
        'name' => 'Midtrans BCA VA Report',
        'description' => 'Dashboard for BCA VA Settlement Reports with Daily Email',
        'version' => '1.0',
        'author' => 'Rohmat',
        'fields' => [
            'email_recipient' => [
                'FriendlyName' => 'Email Recipient',
                'Type' => 'text',
                'Size' => '250',
                'Description' => 'Email address to receive daily reports',
            ]
        ],
        'links' => [
            'report' => [
                'label' => 'Report BCA VA Midtrans',
                'href' => 'addonmodules.php?module=midtrans_report',
                'primary' => true
            ]
        ],
        'hooks' => [
            'hooks.php'
        ]
    ];
}

function midtrans_report_activate() {
    return [
        'status' => 'success',
        'description' => 'Module activated successfully'
    ];
}

function midtrans_report_deactivate() {
    return [
        'status' => 'success',
        'description' => 'Module deactivated successfully'
    ];
}

function midtrans_report_output($vars) {
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    $transactions = getSettlementData($startDate, $endDate);
    
    echo '<h2>Midtrans BCA VA Settlement Report</h2>';
    
    echo '<form method="get" action="addonmodules.php">
        <input type="hidden" name="module" value="midtrans_report">
        <input type="date" name="start_date" value="'.$startDate.'">
        <input type="date" name="end_date" value="'.$endDate.'">
        <input type="submit" value="Filter" class="btn btn-primary">
    </form><br>';

    
    echo '<div class="table-responsive">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Amount</th>
                    <th>Transaction Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';
    
    $totalAmount = 0;
    foreach ($transactions as $trans) {
        $totalAmount += $trans['amount'];
        echo '<tr>
            <td>'.$trans['order_id'].'</td>
            <td>IDR '.number_format($trans['amount'], 2).'</td>
            <td>'.$trans['transaction_time'].'</td>
            <td><span class="label label-success">Settlement</span></td>
        </tr>';
    }
    
    echo '</tbody>
        <tfoot>
            <tr>
                <th colspan="1">Total</th>
                <th>IDR '.number_format($totalAmount, 2).'</th>
                <th colspan="3"></th>
            </tr>
        </tfoot>
    </table></div>';
}

function getSettlementData($startDate, $endDate) {
    $transactions = [];
    
    try {
        $results = WHMCS\Database\Capsule::table('tblgatewaylog')
            ->select('data')
            ->whereBetween('date', [
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59'
            ])
            ->where('gateway', 'like', '%Online Payment%')
            ->where('result', '=', 'settlement')
            ->get();
        
            // logActivity("Results: " . $results);
        
            foreach ($results as $result) {
                // Clean the data string
                $cleanData = str_replace('\n', '', $result->data);
                $cleanData = str_replace('Veritrans_Notification Object', '', $cleanData);
                $cleanData = str_replace('(', '', $cleanData);
                $cleanData = str_replace(')', '', $cleanData);
                $cleanData = str_replace('[response:Veritrans_Notification:private] => stdClass Object', '', $cleanData);
                
                // Extract bank info
                preg_match('/\[bank\] => ([^\n]+)/', $cleanData, $bankMatch);
                $bank = isset($bankMatch[1]) ? trim($bankMatch[1]) : '';
                
                // Extract other fields
                preg_match('/\[order_id\] => ([^\n]+)/', $cleanData, $orderMatch);
                preg_match('/\[gross_amount\] => ([^\n]+)/', $cleanData, $amountMatch);
                preg_match('/\[transaction_time\] => ([^\n]+)/', $cleanData, $timeMatch);
                
                // If bank is BCA, add to transactions array
                if ($bank === 'bca') {
                    $transactions[] = [
                        'order_id' => trim($orderMatch[1]),
                        'amount' => trim($amountMatch[1]),
                        'transaction_time' => trim($timeMatch[1])
                    ];
                }
            }            
                                    
            logActivity("Midtrans Debug: Found " . count($transactions) . " settlement transactions");
    } catch (Exception $e) {
        logActivity("Midtrans Report Error: " . $e->getMessage());
    }
    
    return $transactions;
}

