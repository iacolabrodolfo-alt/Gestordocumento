<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

header('Content-Type: text/plain');

echo "=== DEBUG ESPECÍFICO CONTROL_CARGAS_MENSUALES ===\n";

// Simular exactamente lo que hace procesar_excel.php
$tipo_archivo = 'ASIGNACION_STOCK';
$periodo = '202510';
$archivo_origen = 'Asignación 202510 - MAB - copia.xlsx';
$usuario_carga = 'admin';
$registros_procesados = 2984;
$estado = 'COMPLETADO';

echo "1. Probando inserción con parámetros idénticos a procesar_excel.php...\n";

// Método 1: Con secure_query (como en procesar_excel.php)
try {
    $sql1 = "INSERT INTO Control_Cargas_Mensuales 
            (tipo_archivo, periodo, archivo_origen, usuario_carga, registros_procesados, estado, fecha_carga, fecha_creacion) 
            VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())";
    
    $params = [$tipo_archivo, $periodo, $archivo_origen, $usuario_carga, $registros_procesados, $estado];
    
    echo "   SQL: $sql1\n";
    echo "   Parámetros: " . implode(', ', $params) . "\n";
    
    $stmt1 = $db->secure_query($sql1, $params);
    
    if ($stmt1) {
        echo "   ✅ MÉTODO 1 (secure_query): EXITOSO\n";
    } else {
        $errors = sqlsrv_errors();
        if ($errors) {
            echo "   ❌ MÉTODO 1 (secure_query): ERROR - " . $errors[0]['message'] . "\n";
        } else {
            echo "   ❌ MÉTODO 1 (secure_query): ERROR desconocido\n";
        }
    }
} catch (Exception $e) {
    echo "   ❌ MÉTODO 1 (secure_query): EXCEPCIÓN - " . $e->getMessage() . "\n";
}

echo "2. Probando inserción con query directa...\n";

// Método 2: Con query directa
try {
    $sql2 = "INSERT INTO Control_Cargas_Mensuales 
            (tipo_archivo, periodo, archivo_origen, usuario_carga, registros_procesados, estado, fecha_carga, fecha_creacion) 
            VALUES ('$tipo_archivo', '$periodo', '$archivo_origen', '$usuario_carga', $registros_procesados, '$estado', GETDATE(), GETDATE())";
    
    echo "   SQL: $sql2\n";
    
    $stmt2 = $db->query($sql2);
    
    if ($stmt2) {
        echo "   ✅ MÉTODO 2 (query directa): EXITOSO\n";
    } else {
        $errors = sqlsrv_errors();
        if ($errors) {
            echo "   ❌ MÉTODO 2 (query directa): ERROR - " . $errors[0]['message'] . "\n";
        } else {
            echo "   ❌ MÉTODO 2 (query directa): ERROR desconocido\n";
        }
    }
} catch (Exception $e) {
    echo "   ❌ MÉTODO 2 (query directa): EXCEPCIÓN - " . $e->getMessage() . "\n";
}

echo "3. Verificando estructura de la tabla...\n";

try {
    $sql3 = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'Control_Cargas_Mensuales' 
            ORDER BY ORDINAL_POSITION";
    
    $stmt3 = $db->secure_query($sql3);
    
    if ($stmt3) {
        echo "   Estructura de la tabla:\n";
        while ($row = sqlsrv_fetch_array($stmt3, SQLSRV_FETCH_ASSOC)) {
            echo "   - " . $row['COLUMN_NAME'] . " (" . $row['DATA_TYPE'] . ") - Nullable: " . $row['IS_NULLABLE'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "   ❌ Error obteniendo estructura: " . $e->getMessage() . "\n";
}

echo "=== FIN DEBUG ===\n";
?>