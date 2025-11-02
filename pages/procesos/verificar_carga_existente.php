<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

header('Content-Type: application/json');

function extraerPeriodoDelNombre($nombre_archivo) {
    // Para archivos tipo "Asignacion 202510 - MAB.xlsx"
    if (preg_match('/(\d{6})/', $nombre_archivo, $matches)) {
        return $matches[1];
    }
    return date('Ym'); // Por defecto, mes actual
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_archivo = $_POST['tipo_archivo'] ?? '';
    $nombre_archivo = $_POST['nombre_archivo'] ?? '';
    
    if (empty($tipo_archivo) || empty($nombre_archivo)) {
        echo json_encode(['existe' => false]);
        exit;
    }
    
    $periodo = extraerPeriodoDelNombre($nombre_archivo);
    
    // Verificar en Control_Cargas_Mensuales si ya existe una carga para este período
    $sql = "SELECT TOP 1 
                tipo_archivo,
                archivo_origen,
                usuario_carga,
                registros_procesados,
                CONVERT(VARCHAR, fecha_carga, 120) as fecha_carga,
                periodo,
                estado
            FROM Control_Cargas_Mensuales 
            WHERE tipo_archivo = ? 
            AND periodo = ? 
            AND estado = 'COMPLETADO'
            ORDER BY fecha_carga DESC";
    
    $params = [$tipo_archivo, $periodo];
    $stmt = $db->secure_query($sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $carga_existente = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        
        echo json_encode([
            'existe' => true,
            'detalles' => [
                'fecha_carga' => $carga_existente['fecha_carga'],
                'usuario_carga' => $carga_existente['usuario_carga'],
                'registros_procesados' => $carga_existente['registros_procesados'],
                'archivo_origen' => $carga_existente['archivo_origen'],
                'periodo' => $carga_existente['periodo']
            ]
        ]);
    } else {
        echo json_encode(['existe' => false]);
    }
} else {
    echo json_encode(['existe' => false]);
}
?>