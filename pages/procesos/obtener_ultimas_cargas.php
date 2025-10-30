<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

header('Content-Type: application/json');

try {
    $sql = "SELECT TOP 5 
                tipo_archivo, 
                nombre_original, 
                estado, 
                registros_procesados,
                CONVERT(VARCHAR, fecha_carga, 120) as fecha_carga
            FROM Logs_Carga_Excel 
            ORDER BY fecha_carga DESC";
    
    $stmt = $db->secure_query($sql);
    $cargas = [];
    
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cargas[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'cargas' => $cargas
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}