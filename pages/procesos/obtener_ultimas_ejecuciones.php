<?php
// Usar la misma configuración que inhibiciones.php
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->require_admin();

try {
    // Replicar la misma conexión que inhibiciones.php
    $db = new Database(); // Ajusta según tu implementación
    $db->connect();
    
    header('Content-Type: application/json');
    
    $sql = "SELECT TOP 10 
        accion, usuario, registros_afectados, estado,
        CONVERT(VARCHAR(16), fecha_ejecucion, 120) as fecha_ejecucion
    FROM Logs_Inhibiciones 
    ORDER BY id DESC";
    
    $result = $db->secure_query($sql, array());
    $ejecuciones = [];
    
    if ($result) {
        if (is_resource($result)) {
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $ejecuciones[] = $row;
            }
        } else if (is_array($result)) {
            $ejecuciones = $result;
        }
    }
    
    echo json_encode([
        'success' => true,
        'ejecuciones' => $ejecuciones
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'ejecuciones' => []
    ]);
}
?>