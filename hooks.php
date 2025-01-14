<?php

// Register the widget
add_hook('AdminHomeWidgets', 1, function() {
    logActivity("Midtrans Widget Hook Called");
    return new MidtransReportWidget();
});

class MidtransReportWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Midtrans BCA VA Settlement';
    protected $description = 'Shows BCA VA settlement transactions';
    protected $weight = 150;
    protected $columns = 2;
    protected $cache = true;
    protected $cacheExpiry = 300;

    public function getData() {
        logActivity("Widget getData() Called");
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $data = getWidgetSettlementData($yesterday, $yesterday);
        logActivity("Widget Data Retrieved: " . count($data) . " records");
        return $data;
    }

    public function generateOutput($data) {
        $html = '<div class="widget-content-padded">';
        
        if (empty($data)) {
            $html .= '<p>No settlement transactions found yesterday</p>';
            return $html . '</div>';
        }

        $html .= '<table class="table table-bordered">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Amount</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>';

        $totalAmount = 0;
        foreach ($data as $trans) {
            $totalAmount += $trans['amount'];
            $html .= '<tr>
                <td>'.$trans['order_id'].'</td>
                <td>IDR '.number_format($trans['amount'], 2).'</td>
                <td>'.date('H:i', strtotime($trans['transaction_time'])).'</td>
            </tr>';
        }

        $html .= '</tbody>
            <tfoot>
                <tr>
                    <th colspan="1">Total</th>
                    <th colspan="3">IDR '.number_format($totalAmount, 2).'</th>
                </tr>
            </tfoot>
        </table>';

        $html .= '<div class="text-right">
            <a href="addonmodules.php?module=midtrans_report" class="btn btn-default btn-sm">
                View Full Report
            </a>
        </div>';

        return $html . '</div>';
    }
}

if (!function_exists('WHMCS\Module\Widget\MidtransReport\registerWidget')) {
    function registerWidget() {
        return new MidtransReportWidget();
    }
}

add_hook('AdminHomeWidgets', 1, 'WHMCS\Module\Widget\MidtransReport\registerWidget');

function getWidgetSettlementData($startDate, $endDate) {
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
// Daily Email Report Hook
add_hook('DailyCronJob', 1, function() {
    $moduleParams = getModuleConfigParams('midtrans_report');
    $recipientEmail = $moduleParams['email_recipient'];
    
    if (!$recipientEmail) {
        return;
    }

    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $transactions = getWidgetSettlementData($yesterday, $yesterday);
    
    if (!empty($transactions)) {
        $totalAmount = 0;
        $emailContent = "Daily BCA VA Settlement Report for {$yesterday}\n\n";
        
        foreach ($transactions as $trans) {
            $totalAmount += $trans['amount'];
            $emailContent .= "Order ID: {$trans['order_id']}\n";
            $emailContent .= "Amount: IDR " . number_format($trans['amount'], 2) . "\n";
            $emailContent .= "Transaction Time: {$trans['transaction_time']}\n";
            $emailContent .= "------------------------\n";
        }
        
        $emailContent .= "\nTotal Amount: IDR " . number_format($totalAmount, 2);
        
        sendAdminNotification(
            $recipientEmail,
            "Daily BCA VA Settlement Report - {$yesterday}",
            $emailContent
        );
    }
});

function getModuleConfigParams($module) {
    $result = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', $module)
        ->pluck('value', 'setting')
        ->toArray();
    
    return $result;
}
