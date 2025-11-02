<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

header('Content-Type: text/plain');

echo "=== DEBUG CONTROL_CARGAS_MENSUALES ===\n";

// 1. Verificar conexión
echo "1. Probando conexión a BD... ";
try {
    $sql_test = "SELECT 1 as test";
    $stmt = $db->secure_query($sql_test);
    if ($stmt) {
        echo "✅ CONEXIÓN OK\n";
    } else {
        echo "❌ ERROR CONEXIÓN\n";
    }
} catch (Exception $e) {
    echo "❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
}

// 2. Verificar tabla Control_Cargas_Mensuales
echo "2. Verificando tabla Control_Cargas_Mensuales... ";
try {
    $sql_check = "SELECT COUNT(*) as total FROM Control_Cargas_Mensuales";
    $stmt = $db->secure_query($sql_check);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        echo "✅ TABLA EXISTE - Registros: " . $row['total'] . "\n";
    } else {
        echo "❌ ERROR AL ACCEDER A TABLA\n";
    }
} catch (Exception $e) {
    echo "❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
}

// 3. Insertar registro de prueba
echo "3. Insertando registro de prueba... ";
try {
    $sql_insert = "INSERT INTO Control_Cargas_Mensuales 
                  (tipo_archivo, periodo, archivo_origen, usuario_carga, registros_procesados, estado, fecha_carga, fecha_creacion) 
                  VALUES ('TEST', '202510', 'debug_test.xlsx', 'debug_user', 100, 'COMPLETADO', GETDATE(), GETDATE())";
    
    $stmt = $db->secure_query($sql_insert);
    if ($stmt) {
        echo "✅ INSERCIÓN EXITOSA\n";
    } else {
        echo "❌ ERROR EN INSERCIÓN\n";
    }
} catch (Exception $e) {
    echo "❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
}

// 4. Verificar inserción
echo "4. Verificando inserción... ";
try {
    $sql_verify = "SELECT * FROM Control_Cargas_Mensuales WHERE tipo_archivo = 'TEST'";
    $stmt = $db->secure_query($sql_verify);
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        echo "✅ REGISTRO ENCONTRADO - Estado: " . $row['estado'] . "\n";
    } else {
        echo "❌ NO SE ENCONTRÓ EL REGISTRO\n";
    }
} catch (Exception $e) {
    echo "❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
}

echo "=== FIN DEBUG ===\n";
?>