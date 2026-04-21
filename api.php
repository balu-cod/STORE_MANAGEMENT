<?php
// Suppress PHP HTML warnings from breaking JSON payloads
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
session_start();
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

date_default_timezone_set('Asia/Kolkata'); 
require_once 'db_config.php';
ob_clean();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    if ($action === 'get_inventory') {
        $result = $conn->query("SELECT * FROM inventory ORDER BY id DESC");
        $data = []; 
        if ($result) { while ($row = $result->fetch_assoc()) { $data[] = $row; } }
        echo json_encode($data); exit;
    }

    if ($action === 'get_unique_inventory') {
        $q = $conn->real_escape_string($_GET['q'] ?? '');
        $qty = $conn->real_escape_string($_GET['qty'] ?? '');
        $where = "WHERE loc != 'VIRTUAL' AND loc != 'UNASSIGNED'";
        if ($q !== '') { $where .= " AND (code LIKE '%$q%' OR loc LIKE '%$q%')"; }
        $sql = "SELECT MIN(id) as id, code, SUM(qty) as total_qty, MAX(excel_stock) as sap_stock, GROUP_CONCAT(loc SEPARATOR ', ') as locations 
                FROM inventory $where GROUP BY code";
        if ($qty !== '') { $sql .= " HAVING total_qty >= " . (int)$qty; }
        $sql .= " ORDER BY code ASC";
        $result = $conn->query($sql);
        $data = []; if($result) { while ($row = $result->fetch_assoc()) { $data[] = $row; } }
        echo json_encode($data); exit;
    }

    if ($action === 'get_sap_stock') {
        $code = substr($conn->real_escape_string(strtoupper(trim($_GET['code'] ?? ''))), 0, 15);
        if ($code === '') {
            echo json_encode(["status" => "error", "message" => "Code required"]); exit;
        }
        $sql = "SELECT code, MAX(excel_stock) as sap_stock FROM inventory WHERE code = '$code' GROUP BY code";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode(["status" => "success", "code" => $row['code'], "sap_stock" => $row['sap_stock']]);
        } else {
            echo json_encode(["status" => "not_found", "message" => "Code not found in SAP data."]);
        }
        exit;
    }

    if ($action === 'get_rack_data') {
        $sql = "SELECT code, loc, SUM(qty) as qty FROM inventory WHERE loc != 'UNASSIGNED' AND loc != 'VIRTUAL' AND qty > 0 GROUP BY code, loc";
        $result = $conn->query($sql);
        $data = []; 
        if ($result) { while ($row = $result->fetch_assoc()) { $data[] = $row; } }
        echo json_encode($data); exit;
    }

    if ($action === 'get_transactions') {
        $q = $conn->real_escape_string($_GET['q'] ?? '');
        $date = $conn->real_escape_string($_GET['date'] ?? '');
        $loc = $conn->real_escape_string($_GET['loc'] ?? '');
        
        $where = "WHERE 1=1";
        if ($q !== '') { $where .= " AND (code LIKE '%$q%' OR loc LIKE '%$q%')"; }
        if ($loc !== '') { $where .= " AND loc = '$loc'"; }
        if ($date !== '') {
            $dateParts = explode('-', $date);
            if(count($dateParts) == 3) {
                $formattedDate = $dateParts[2] . '/' . $dateParts[1] . '/' . $dateParts[0];
                $where .= " AND last_updated LIKE '$formattedDate%'";
            }
        }
        $limitClause = ($loc !== '') ? "" : "LIMIT 75";
        
        $sql = "SELECT id, last_user as user, last_activity as action_type, code as item_code, qty as txn_qty, loc, '' as work_order, last_updated as timestamp 
                FROM inventory $where ORDER BY id DESC $limitClause";
        $result = $conn->query($sql);
        $logs = []; 
        if ($result) { while ($row = $result->fetch_assoc()) { $logs[] = $row; } }
        echo json_encode($logs); exit;
    }

    if ($action === 'get_all_transactions') {
        $q = $conn->real_escape_string($_GET['q'] ?? '');
        $date = $conn->real_escape_string($_GET['date'] ?? '');
        
        $where = "WHERE 1=1";
        if ($q !== '') { $where .= " AND (code LIKE '%$q%' OR loc LIKE '%$q%')"; }
        if ($date !== '') {
            $dateParts = explode('-', $date);
            if(count($dateParts) == 3) {
                $formattedDate = $dateParts[2] . '/' . $dateParts[1] . '/' . $dateParts[0];
                $where .= " AND last_updated LIKE '$formattedDate%'";
            }
        }
        
        $sql = "SELECT id, last_user as user, last_activity as action_type, code as item_code, qty as txn_qty, loc, '' as work_order, last_updated as timestamp 
                FROM inventory $where ORDER BY id DESC";
        $result = $conn->query($sql);
        $logs = []; 
        if ($result) { while ($row = $result->fetch_assoc()) { $logs[] = $row; } }
        echo json_encode($logs); exit;
    }

    if ($action === 'check_locations') {
        $code = $conn->real_escape_string($_GET['code'] ?? '');
        $sql = "SELECT loc, qty FROM inventory WHERE code = '$code' AND qty > 0";
        $result = $conn->query($sql);
        $data = []; 
        if ($result) { while ($row = $result->fetch_assoc()) { $data[] = $row; } }
        echo json_encode($data); exit;
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) { echo json_encode(["status" => "error", "message" => "No input"]); exit; }

    $act = strtolower(trim($input['action'] ?? ''));
    $user = $conn->real_escape_string($input['user'] ?? 'Admin');
    $time = date("d/m/Y h:i A");

    $conn->begin_transaction();
    try {
        if ($act === 'add') {
            $loc = $conn->real_escape_string(strtoupper(trim($input['loc'])));
            $items = isset($input['items']) ? $input['items'] : [['code' => $input['code'], 'qty' => $input['qty']]];

            foreach ($items as $item) {
                $code = substr($conn->real_escape_string(strtoupper(trim($item['code']))), 0, 15);
                $qty = (int)$item['qty'];
                if (empty($code) || $qty <= 0) continue;

                $conn->query("INSERT INTO inventory (code, qty, loc, last_user, last_updated, last_activity) 
                             VALUES ('$code', $qty, '$loc', '$user', '$time', 'Entry') 
                             ON DUPLICATE KEY UPDATE qty = qty + $qty, last_user = '$user', last_updated = '$time', last_activity='Entry'");
                
                $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                             VALUES ('$user', 'Entry', '$code', $qty, '$loc', '$time')");
            }
            $conn->commit(); echo json_encode(["status" => "success"]);
        }
        
        elseif ($act === 'issue') {
            $code = substr($conn->real_escape_string(strtoupper(trim($input['code']))), 0, 15);
            $qty = (int)$input['qty'];
            $loc = $conn->real_escape_string(strtoupper(trim($input['loc'])));
            $wo = $conn->real_escape_string($input['work_order'] ?? '');
            
            $check = $conn->query("SELECT qty FROM inventory WHERE code='$code' AND loc='$loc' FOR UPDATE");
            if ($check && $check->num_rows > 0) {
                $row = $check->fetch_assoc();
                if ($row['qty'] >= $qty) {
                    $newQty = $row['qty'] - $qty;
                    
                    $conn->query("UPDATE inventory SET qty = $newQty, last_user = '$user', last_updated = '$time', last_activity='Issue' WHERE code = '$code' AND loc = '$loc'");
                    if ($newQty <= 0) { $conn->query("DELETE FROM inventory WHERE qty <= 0 AND excel_stock <= 0 AND code = '$code'"); }
                    
                    $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, work_order, timestamp) VALUES ('$user', 'Issue', '$code', $qty, '$loc', '$wo', '$time')");
                    
                    $conn->commit(); echo json_encode(["status" => "success"]);
                } else { throw new Exception("Insufficient Stock"); }
            } else { throw new Exception("Item Not Found"); }
        }
        
        elseif ($act === 'import_stock') {
            $items = $input['items'] ?? [];
            if (empty($items)) throw new Exception("No data to import");

            foreach ($items as $item) {
                $code = substr($conn->real_escape_string(strtoupper(trim($item['code']))), 0, 15);
                $loc = $conn->real_escape_string(strtoupper(trim($item['loc'] ?? 'UNASSIGNED')));
                $qty = (int)($item['qty'] ?? 0);
                
                if (empty($code)) continue;

                $check = $conn->query("SELECT id FROM inventory WHERE code = '$code' AND loc = '$loc'");
                if ($check && $check->num_rows > 0) {
                    continue; 
                } else {
                    $conn->query("INSERT INTO inventory (code, qty, loc, excel_stock, last_user, last_updated, last_activity) 
                                 VALUES ('$code', $qty, '$loc', 0, '$user', '$time', 'Stock Import')");
                }
            }
            
            $isFirstChunk = $input['isFirstChunk'] ?? true;
            if ($isFirstChunk) {
                $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                             VALUES ('$user', 'System Import', 'ALL', 0, 'SYSTEM', '$time')");
            }
            $conn->commit(); 
            echo json_encode(["status" => "success", "message" => "Database cleanly imported based on location rules."]);
        }

        elseif ($act === 'import_sap') {
            $items = $input['items'] ?? [];
            $isFirstChunk = $input['isFirstChunk'] ?? true; 
            if (empty($items)) throw new Exception("No SAP data to import");

            if ($isFirstChunk) {
                $conn->query("UPDATE inventory SET excel_stock = 0");
            }

            foreach ($items as $item) {
                $rawCode = strtoupper(trim($item['code']));
                $code = substr($conn->real_escape_string($rawCode), 0, 15);
                $cleanCode = str_replace(' ', '', $code); 
                $excel_stock = (int)($item['excel_stock'] ?? 0);
                
                if (empty($code) || $excel_stock <= 0) continue;

                $conn->query("UPDATE inventory SET excel_stock = $excel_stock WHERE code = '$code'");
                $matched = $conn->affected_rows > 0;

                if (!$matched && strlen($cleanCode) > 2) {
                    $firstTwo = substr($cleanCode, 0, 2); 
                    $remaining = substr($cleanCode, 2);   
                    $conn->query("UPDATE inventory SET excel_stock = $excel_stock WHERE REPLACE(code, ' ', '') LIKE '{$firstTwo}%{$remaining}'");
                    $matched = $conn->affected_rows > 0;
                }

                if (!$matched) {
                    $conn->query("INSERT INTO inventory (code, qty, loc, excel_stock, last_user, last_updated, last_activity) 
                                 VALUES ('$code', 0, 'UNASSIGNED', $excel_stock, '$user', '$time', 'SAP Import')");
                }
            }
            $conn->commit(); 
            echo json_encode(["status" => "success", "message" => "SAP stock chunk successfully updated."]);
        }

        elseif ($act === 'edit_code') {
            $old_code = substr($conn->real_escape_string(strtoupper(trim($input['old_code'] ?? ''))), 0, 15);
            $new_code = substr($conn->real_escape_string(strtoupper(trim($input['new_code'] ?? ''))), 0, 15);
            
            if (empty($old_code) || empty($new_code)) throw new Exception("Both old and new codes are required.");
            if ($old_code === $new_code) throw new Exception("New code is identical to the old code.");

            $conn->query("UPDATE IGNORE inventory SET code = '$new_code', last_updated = '$time', last_activity = 'Code Edit' WHERE code = '$old_code'");
            $conn->query("UPDATE activity_logs SET item_code = '$new_code' WHERE item_code = '$old_code'");
            $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                         VALUES ('$user', 'System Edit', '$new_code', 0, 'ALL LOCS', '$time')");
            $conn->commit(); 
            echo json_encode(["status" => "success", "message" => "Code successfully updated across the database."]);
        }

        elseif ($act === 'delete_item') {
            $code = substr($conn->real_escape_string(strtoupper(trim($input['code']))), 0, 15);
            $loc = $conn->real_escape_string(strtoupper(trim($input['loc'])));
            
            if (empty($code) || empty($loc)) throw new Exception("Code and Location required.");

            $conn->query("DELETE FROM inventory WHERE code = '$code' AND loc = '$loc'");
            $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                         VALUES ('$user', 'Delete Record', '$code', 0, '$loc', '$time')");
            $conn->commit(); 
            echo json_encode(["status" => "success", "message" => "Item permanently deleted."]);
        }

        elseif ($act === 'delete_by_code') {
            $code = substr($conn->real_escape_string(strtoupper(trim($input['code']))), 0, 15);
            if (empty($code)) throw new Exception("Code is required to delete.");
            $conn->query("DELETE FROM inventory WHERE code = '$code'");
            $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                         VALUES ('$user', 'Delete Record', '$code', 0, 'ALL LOCS', '$time')");
            $conn->commit(); 
            echo json_encode(["status" => "success", "message" => "Item completely deleted from database."]);
        }

        elseif ($act === 'delete_log_bulk') {
            $ids = $input['ids'] ?? [];
            foreach ($ids as $id) {
                $id = (int)$id;
                $logRes = $conn->query("SELECT code, loc FROM inventory WHERE id = $id");
                if ($logRow = $logRes->fetch_assoc()) {
                    $c = $logRow['code']; 
                    $l = $logRow['loc'];
                    
                    $conn->query("DELETE FROM inventory WHERE id = $id");
                    
                    $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                                 VALUES ('$user', 'Delete Record', '$c', 0, '$l', '$time')");
                }
            }
            $conn->commit(); echo json_encode(["status" => "success"]);
        }

        // Original: Handles clearing ONE specific location strictly matching the string
        elseif ($act === 'clear_location') {
            $loc = $conn->real_escape_string(strtoupper(trim($input['loc'])));
            
            if (empty($loc)) throw new Exception("Location is required to clear.");

            $conn->query("DELETE FROM inventory WHERE loc = '$loc'");
            $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                         VALUES ('$user', 'Clear Location', 'ALL', 0, '$loc', '$time')");
            
            $conn->commit(); 
            echo json_encode(["status" => "success", "message" => "Location completely cleared."]);
        }

        // NEW: Handles clearing a full Rack (all bins starting with that letter)
        elseif ($act === 'clear_rack') {
            $rack_prefix = $conn->real_escape_string(strtoupper(trim($input['rack'])));
            
            if (empty($rack_prefix)) throw new Exception("Rack prefix is required to clear.");

            // Notice the LIKE operator and the '%' wildcard
            $conn->query("DELETE FROM inventory WHERE loc LIKE '$rack_prefix%'");
            $conn->query("INSERT INTO activity_logs (user, action_type, item_code, qty, loc, timestamp) 
                         VALUES ('$user', 'Clear Rack', 'ALL', 0, 'RACK $rack_prefix', '$time')");
            
            $conn->commit(); 
            echo json_encode(["status" => "success", "message" => "Rack $rack_prefix completely cleared."]);
        }

    } catch (Exception $e) { 
        $conn->rollback(); 
        echo json_encode(["status" => "error", "message" => $e->getMessage()]); 
    }
    exit;
}
?>
