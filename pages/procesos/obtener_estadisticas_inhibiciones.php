<?php
// Usar la misma configuración que inhibiciones.php
require_once '../../includes/auth.php';

// Inicializar la base de datos de la misma forma que inhibiciones.php
$auth = new Auth();
$auth->require_admin();

// Si Database está definido en auth.php o incluido allí, usamos eso
// Si no, necesitamos incluir el archivo correcto

// Intentar con la conexión existente
try {
    // Si $db ya está disponible (como en inhibiciones.php)
    if (isset($db)) {
        // Usar la conexión existente
    } else {
        // Crear nueva conexión usando el mismo método que inhibiciones.php
        // Buscar en inhibiciones.php cómo se conecta y replicarlo aquí
        $db = new Database(); // o como se llame tu clase
        $db->connect();
    }
    
    header('Content-Type: application/json');
    
    // Consulta de totales
    $sql_totales = "SELECT 
        COUNT(*) as total_inhibiciones,
        COUNT(DISTINCT rut) as ruts_unicos,
        ISNULL(SUM(CASE WHEN CAST(fecha_procesamiento as DATE) = CAST(GETDATE() as DATE) THEN 1 ELSE 0 END), 0) as hoy,
        ISNULL(SUM(CASE WHEN fecha_procesamiento >= DATEADD(MONTH, -1, GETDATE()) THEN 1 ELSE 0 END), 0) as este_mes
    FROM estadisticas_inhibiciones";
    
    $result_totales = $db->secure_query($sql_totales, array());
    $totales = ['total_inhibiciones' => 0, 'ruts_unicos' => 0, 'hoy' => 0, 'este_mes' => 0];
    
    if ($result_totales) {
        if (is_resource($result_totales)) {
            if ($row = sqlsrv_fetch_array($result_totales, SQLSRV_FETCH_ASSOC)) {
                $totales = $row;
            }
        } else if (is_array($result_totales) && !empty($result_totales)) {
            $totales = $result_totales[0];
        }
    }
    
    // Consulta de detalladas
    $sql_detalladas = "SELECT 
        tipo_inhibicion,
        COUNT(*) as total,
        COUNT(DISTINCT rut) as ruts_unicos,
        CONVERT(VARCHAR(10), MAX(fecha_procesamiento), 120) as ultima_ejecucion
    FROM estadisticas_inhibiciones 
    GROUP BY tipo_inhibicion
    ORDER BY total DESC";
    
    $result_detalladas = $db->secure_query($sql_detalladas, array());
    $detalladas = [];
    
    if ($result_detalladas) {
        if (is_resource($result_detalladas)) {
            while ($row = sqlsrv_fetch_array($result_detalladas, SQLSRV_FETCH_ASSOC)) {
                $detalladas[] = $row;
            }
        } else if (is_array($result_detalladas)) {
            $detalladas = $result_detalladas;
        }
    }
    
    echo json_encode([
        'success' => true,
        'totales' => $totales,
        'detalladas' => $detalladas
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'totales' => ['total_inhibiciones' => 0, 'ruts_unicos' => 0, 'hoy' => 0, 'este_mes' => 0],
        'detalladas' => []
    ]);
}
?>