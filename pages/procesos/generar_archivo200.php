<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_auth();

$db = new Database();
$db->connect();

$mensaje = '';
$mensaje_tipo = '';
$resultado = null;

// Procesar generación del archivo
if ($_POST['action'] ?? '' === 'generar_archivo200') {
    $fecha_procesamiento = $_POST['fecha_procesamiento'] ?? date('Y-m-d');
    $usuario = $_SESSION['username'];
    
    try {
        // Llamar al stored procedure
        $sql = "EXEC sp_GenerarArchivo200 @FechaProcesamiento = ?, @Usuario = ?";
        $params = array($fecha_procesamiento, $usuario);
        
        $stmt = $db->secure_query($sql, $params);
        
        if ($stmt) {
            $resultado = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            if ($resultado && $resultado['resultado'] === 'EXITO') {
                $mensaje = $resultado['mensaje'] . " - Registros: " . $resultado['registros_generados'];
                $mensaje_tipo = 'success';
                
                // Aquí podríamos generar el archivo físico .txt
                generarArchivoTXT($fecha_procesamiento, $db);
                
            } else {
                $mensaje = $resultado['mensaje'] ?? 'Error desconocido al generar archivo';
                $mensaje_tipo = 'danger';
            }
        } else {
            $mensaje = "Error al ejecutar el stored procedure";
            $mensaje_tipo = 'danger';
        }
        
    } catch (Exception $e) {
        $mensaje = "Excepción: " . $e->getMessage();
        $mensaje_tipo = 'danger';
    }
}

// Función para generar archivo .txt físico
// Función para generar archivo .txt físico CON FORMATO EXACTO
function generarArchivoTXT($fecha, $db) {
    $ruta_archivos = "../../files/archivo200/";
    
    // Crear directorio si no existe
    if (!is_dir($ruta_archivos)) {
        mkdir($ruta_archivos, 0777, true);
    }
    
    // Formatear fecha para el nombre del archivo (DDMMYYYY)
    $fecha_nombre = date('dmY', strtotime($fecha));
    $nombre_archivo = "MAB_200_{$fecha_nombre}.txt";
    $ruta_completa = $ruta_archivos . $nombre_archivo;
    
    // Obtener datos del archivo 200
    $sql = "SELECT 
        campo_1_3,
        campo_4,
        campo_5_29,
        campo_30_46, -- Ahora es VARCHAR(17) con formato 'DDMMYYYY HH:MM:SS'
        RIGHT('000' + CAST(campo_47_49 AS VARCHAR(3)), 3) as campo_47_49,
        campo_50_51,
        campo_52_53,
        campo_54_55,
        campo_56_63,
        campo_64_119
    FROM Archivo_200 
    WHERE fecha_procesamiento = ? 
    AND estado = 'PROCESADO'
    ORDER BY campo_5_29, campo_47_49";
    
    $stmt = $db->secure_query($sql, array($fecha));
    
    if ($stmt) {
        $archivo = fopen($ruta_completa, 'w');
        $lineas_generadas = 0;
        
        if ($archivo) {
            while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Construir cada campo con longitud EXACTA según el formato real
                $campo_1_3   = str_pad($fila['campo_1_3'] ?? '200', 3, ' ', STR_PAD_RIGHT);                    // 1-3
                $campo_4     = str_pad($fila['campo_4'] ?? '6', 1, ' ', STR_PAD_RIGHT);                        // 4
                $campo_5_29  = str_pad($fila['campo_5_29'] ?? '', 25, ' ', STR_PAD_RIGHT);                     // 5-29
                $campo_30_46 = str_pad($fila['campo_30_46'] ?? date('dmY H:i:s'), 17, ' ', STR_PAD_RIGHT);     // 30-46 (¡FORMATO DDMMYYYY HH:MM:SS!)
                $campo_47_49 = str_pad($fila['campo_47_49'] ?? '001', 3, '0', STR_PAD_LEFT);                   // 47-49
                $campo_50_51 = str_pad($fila['campo_50_51'] ?? '3N', 2, ' ', STR_PAD_RIGHT);                   // 50-51
                $campo_52_53 = str_pad($fila['campo_52_53'] ?? '1', 2, ' ', STR_PAD_RIGHT);                    // 52-53
                $campo_54_55 = str_pad($fila['campo_54_55'] ?? '  ', 2, ' ', STR_PAD_RIGHT);                   // 54-55 (normalmente espacios)
                $campo_56_63 = str_pad($fila['campo_56_63'] ?? 'exmabdis', 8, ' ', STR_PAD_RIGHT);             // 56-63
                $campo_64_119 = str_pad($fila['campo_64_119'] ?? '', 56, ' ', STR_PAD_RIGHT);                  // 64-119
                
                // Unir todos los campos
                $linea = $campo_1_3 . $campo_4 . $campo_5_29 . $campo_30_46 . 
                         $campo_47_49 . $campo_50_51 . $campo_52_53 . $campo_54_55 . 
                         $campo_56_63 . $campo_64_119;
                
                // Verificar longitud (debe ser exactamente 119 caracteres)
                $longitud = strlen($linea);
                if ($longitud !== 119) {
                    error_log("ADVERTENCIA: Línea con longitud {$longitud}, ajustando a 119");
                    $linea = str_pad($linea, 119, ' ', STR_PAD_RIGHT);
                }
                
                fwrite($archivo, $linea . PHP_EOL);
                $lineas_generadas++;
            }
            
            fclose($archivo);
            
            $GLOBALS['mensaje'] .= " | Archivo: {$nombre_archivo} ({$lineas_generadas} líneas)";
            return $nombre_archivo;
            
        } else {
            throw new Exception("No se pudo crear el archivo físico");
        }
    }
    
    return null;
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Archivo 200 - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h5 class="text-white">Gestor Documento</h5>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="../dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>Dashboard
                            </a>
                        </li>
                        <?php if ($_SESSION['perfil'] === 'administrador'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="../usuarios/crud.php">
                                <i class="bi bi-people me-2"></i>Gestión de Usuarios
                            </a>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">
                                <i class="bi bi-gear me-2"></i>Procesos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="archivos.php">
                                <i class="bi bi-files me-2"></i>Archivos Subidos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="generar_archivo200.php">
                                <i class="bi bi-file-earmark-arrow-down me-2"></i>Archivo 200
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="carga_excel.php">
                                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Carga Excel
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-file-earmark-arrow-down me-2"></i>Generar Archivo 200
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="me-3">
                            <i class="bi bi-person-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['nombre_completo']); ?>
                        </span>
                        <a href="../../includes/logout.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                        </a>
                    </div>
                </div>

                <!-- Mensajes -->
                <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-gear-fill me-2"></i>Configuración de Generación
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="generar_archivo200">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Fecha de Procesamiento</label>
                                                <input type="date" class="form-control" name="fecha_procesamiento" 
                                                       value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                                                <small class="form-text text-muted">Selecciona la fecha para la cual generar el archivo</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Usuario Ejecutor</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['nombre_completo']); ?>" readonly>
                                                <small class="form-text text-muted">Usuario que genera el archivo</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="alert alert-info">
                                        <h6><i class="bi bi-info-circle me-2"></i>Información importante:</h6>
                                        <ul class="mb-0">
                                            <li>El archivo se generará en formato TXT según especificación</li>
                                            <li>Los archivos deben subirse antes de las 21:30 hrs</li>
                                            <li>Se eliminarán tildes y caracteres especiales automáticamente</li>
                                            <li>El archivo se guardará en /files/archivo200/</li>
                                        </ul>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100" 
                                            onclick="return confirm('¿Generar Archivo 200 para la fecha seleccionada?')">
                                        <i class="bi bi-gear-fill me-2"></i>Generar Archivo 200
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history me-2"></i>Últimas Ejecuciones
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item bg-transparent">
                                        <small class="text-muted">No hay ejecuciones recientes</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-file-text me-2"></i>Especificación Archivo 200
                                </h5>
                            </div>
                            <div class="card-body">
                                <small>
                                    <strong>Estructura:</strong><br>
                                    1-3: '200'<br>
                                    4: Grupo (3/6)<br>
                                    5-29: No. Caso<br>
                                    30-46: Fecha<br>
                                    47-49: Secuencia<br>
                                    50-51: Cód. Acción<br>
                                    52-53: Cód. Resultado<br>
                                    54-55: Cód. Carta<br>
                                    56-63: Gestor<br>
                                    64-119: Comentario
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>