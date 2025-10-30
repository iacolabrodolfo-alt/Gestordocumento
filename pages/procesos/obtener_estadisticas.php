<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

header('Content-Type: application/json');

try {
    // Total de archivos
    $sql = "SELECT COUNT(*) as total FROM Logs_Carga_Excel";
    $stmt = $db->secure_query($sql);
    $total_archivos = 0;
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $total_archivos = $row['total'];
    }
    
    // Total de registros
    $sql = "SELECT SUM(registros_procesados) as total FROM Logs_Carga_Excel WHERE estado = 'COMPLETADO'";
    $stmt = $db->secure_query($sql);
    $total_registros = 0;
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $total_registros = $row['total'] ?? 0;
    }
    
    // Cargas exitosas
    $sql = "SELECT COUNT(*) as total FROM Logs_Carga_Excel WHERE estado = 'COMPLETADO'";
    $stmt = $db->secure_query($sql);
    $cargas_exitosas = 0;
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $cargas_exitosas = $row['total'];
    }
    
    // Cargas con errores
    $sql = "SELECT COUNT(*) as total FROM Logs_Carga_Excel WHERE estado = 'ERROR'";
    $stmt = $db->secure_query($sql);
    $cargas_con_errores = 0;
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $cargas_con_errores = $row['total'];
    }
    
    echo json_encode([
        'success' => true,
        'total_archivos' => $total_archivos,
        'total_registros' => $total_registros,
        'cargas_exitosas' => $cargas_exitosas,
        'cargas_con_errores' => $cargas_con_errores
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}