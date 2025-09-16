<?php
// api/reports.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'production_summary':
            $date_from = $_GET['date_from'] ?? date('Y-m-01');
            $date_to   = $_GET['date_to']   ?? date('Y-m-t');

            $query = "
                SELECT 
                    p.product_name,
                    COUNT(pj.job_id) as total_jobs,
                    SUM(pj.quantity_planned) as total_planned,
                    SUM(pj.quantity_produced) as total_produced,
                    ROUND(AVG(pj.quantity_produced / pj.quantity_planned * 100), 2) as avg_efficiency,
                    ROUND(AVG(DATEDIFF(pj.updated_at, pj.created_at)), 1) as avg_days
                FROM production_jobs pj
                JOIN products p ON pj.product_id = p.product_id
                WHERE pj.created_at BETWEEN ? AND ?
                  AND pj.status = 'completed'
                GROUP BY pj.product_id
                ORDER BY total_produced DESC
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$date_from, $date_to]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'material_usage':
            $date_from = $_GET['date_from'] ?? date('Y-m-01');
            $date_to   = $_GET['date_to']   ?? date('Y-m-t');

            $query = "
                SELECT 
                    m.part_code,
                    m.material_name,
                    SUM(CASE WHEN it.transaction_type = 'out' THEN it.quantity ELSE 0 END) as total_used,
                    SUM(CASE WHEN it.transaction_type = 'in' THEN it.quantity ELSE 0 END) as total_received,
                    m.current_stock
                FROM inventory_transactions it
                JOIN materials m ON it.material_id = m.material_id
                WHERE it.transaction_date BETWEEN ? AND ?
                GROUP BY it.material_id
                ORDER BY total_used DESC
                LIMIT 20
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$date_from, $date_to]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'kpi_dashboard':
            $month = $_GET['month'] ?? date('Y-m');
            $kpis = [];

            // 1) Production Efficiency
            $stmt = $db->prepare("
                SELECT ROUND(AVG(quantity_produced / quantity_planned * 100), 2)
                FROM production_jobs 
                WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
                  AND status = 'completed'
                  AND quantity_planned > 0
            ");
            $stmt->execute([$month]);
            $kpis['production_efficiency'] = $stmt->fetchColumn() ?? 0;

            // 2) On-time Delivery
            $stmt = $db->prepare("
                SELECT ROUND(
                    SUM(CASE WHEN updated_at <= end_date THEN 1 ELSE 0 END) / COUNT(*) * 100, 2
                )
                FROM production_jobs 
                WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
                  AND status = 'completed'
            ");
            $stmt->execute([$month]);
            $kpis['ontime_delivery'] = $stmt->fetchColumn() ?? 0;

            // 3) Material Availability
            $stmt = $db->prepare("
                SELECT ROUND(
                    SUM(CASE WHEN current_stock > min_stock THEN 1 ELSE 0 END) / COUNT(*) * 100, 2
                )
                FROM materials 
                WHERE status = 'active'
            ");
            $stmt->execute();
            $kpis['material_availability'] = $stmt->fetchColumn() ?? 0;

            // 4) Worker Utilization
            $stmt = $db->prepare("
                SELECT ROUND(
                    COUNT(DISTINCT assigned_to) / (
                        SELECT COUNT(*) FROM users WHERE role = 'production' AND status = 'active'
                    ) * 100, 2
                )
                FROM production_jobs 
                WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
                  AND status IN ('pending','in_progress','completed')
            ");
            $stmt->execute([$month]);
            $kpis['worker_utilization'] = $stmt->fetchColumn() ?? 0;

            echo json_encode(['success' => true, 'kpis' => $kpis]);
            break;

        case 'export':
            checkRole(['admin', 'management', 'planning']);
            
            $type     = $_GET['type']   ?? 'production';
            $format   = $_GET['format'] ?? 'excel';
            $date_from = $_GET['date_from'] ?? date('Y-m-01');
            $date_to   = $_GET['date_to']   ?? date('Y-m-t');

            $data = generateReportData($db, $type, $date_from, $date_to);

            if ($format === 'excel') {
                exportToExcel($data, $type);
            } else {
                // กัน error ไว้ก่อน
                echo json_encode(['success' => false, 'message' => 'ยังไม่ได้ implement exportToPDF']);
            }
            break;

        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ===== Functions =====
function generateReportData($db, $type, $date_from, $date_to) {
    if ($type === 'production') {
        $query = "
            SELECT pj.job_number, p.product_name, pj.quantity_planned,
                   pj.quantity_produced, pj.start_date, pj.end_date,
                   pj.status, u.full_name as assigned_to
            FROM production_jobs pj
            JOIN products p ON pj.product_id = p.product_id
            LEFT JOIN users u ON pj.assigned_to = u.user_id
            WHERE pj.created_at BETWEEN ? AND ?
            ORDER BY pj.created_at DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$date_from, $date_to]);
    } elseif ($type === 'inventory') {
        $query = "
            SELECT m.part_code, m.material_name, m.current_stock,
                   m.min_stock, m.max_stock, m.location,
                   CASE 
                       WHEN m.current_stock <= m.min_stock THEN 'ต่ำ'
                       WHEN m.current_stock > m.max_stock THEN 'เกิน'
                       ELSE 'ปกติ'
                   END as stock_status
            FROM materials m
            WHERE m.status = 'active'
            ORDER BY m.part_code
        ";
        $stmt = $db->prepare($query);
        $stmt->execute(); // ไม่มี parameter
    } else {
        return [];
    }
    return $stmt->fetchAll();
}

function exportToExcel($data, $type) {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    echo '<table border="1">';
    if (!empty($data)) {
        echo '<tr>';
        foreach (array_keys($data[0]) as $column) {
            echo '<th>' . htmlspecialchars($column) . '</th>';
        }
        echo '</tr>';
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell) . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</table>';
    exit();
}
