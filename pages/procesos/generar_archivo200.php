<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_auth();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

$mensaje = '';
$mensaje_tipo = '';
$resultado = null;

// Obtener fechas disponibles con gestiones
function obtenerFechasDisponibles($db) {
    $fechas = [];
    try {
        $sql = "SELECT DISTINCT fecha_procesamiento 
                FROM Gestiones_Diarias 
                WHERE estado = 'ACTIVO' 
                ORDER BY fecha_procesamiento DESC";
        $stmt = $db->secure_query($sql);
        
        if ($stmt) {
            while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $fecha = $fila['fecha_procesamiento'];
                if ($fecha instanceof DateTime) {
                    $fechas[] = $fecha->format('Y-m-d');
                } else {
                    $fechas[] = date('Y-m-d', strtotime($fecha));
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error obteniendo fechas disponibles: " . $e->getMessage());
    }
    return $fechas;
}

$fechas_disponibles = obtenerFechasDisponibles($db);


// Procesar generación del archivo
if ($_POST['action'] ?? '' === 'generar_archivo200') {
    $fecha_procesamiento = $_POST['fecha_procesamiento'] ?? '';
    $usuario = $_SESSION['username'];
    
    // Validar fecha
    if (empty($fecha_procesamiento)) {
        $mensaje = "Error: Debe seleccionar una fecha";
        $mensaje_tipo = 'danger';
    } else {
        try {
            // Llamar al stored procedure
            $sql = "EXEC sp_GenerarArchivo200 @FechaProcesamiento = ?, @Usuario = ?";
            $params = array($fecha_procesamiento, $usuario);
            
            $stmt = $db->secure_query($sql, $params);
            
            if ($stmt) {
                $resultado = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                
                // CORRECCIÓN AQUÍ: Usar los nombres correctos de campos
                if ($resultado && $resultado['resultado'] === 'EXITO') {
                    $mensaje = $resultado['mensaje'];
                    $mensaje_tipo = 'success';
                    
                    // Generar archivo físico .txt
                    $archivo_generado = generarArchivoTXT($fecha_procesamiento, $db);
                    
                    if ($archivo_generado) {
                        $mensaje .= " | Archivo: " . $archivo_generado['nombre_archivo'] . 
                                " (" . $archivo_generado['lineas_generadas'] . " líneas)";
                    }
                    
                    // CORRECCIÓN: Usar los nombres correctos de campos del SP
                    $mensaje .= "<br><small class='text-muted'>";
                    $mensaje .= "Casos 013+MG: " . ($resultado['casos_013_mg'] ?? 0) . " | ";
                    $mensaje .= "13MG: " . ($resultado['casos_13mg'] ?? 0) . " | ";
                    $mensaje .= "13F5: " . ($resultado['casos_13f5'] ?? 0) . " | ";
                    $mensaje .= "Excluidos CD: " . ($resultado['registros_ya_enviados'] ?? 0); // CAMBIO AQUÍ
                    $mensaje .= "</small>";
                    
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
}
// Función para generar archivo .txt físico - CORREGIDA
function generarArchivoTXT($fecha, $db) {
    $ruta_archivos = "../../files/archivo200/";
    
    // Crear directorio si no existe
    if (!is_dir($ruta_archivos)) {
        mkdir($ruta_archivos, 0775, true);
    }
    
    // Formatear fecha para el nombre del archivo (DDMMYYYY)
    $fecha_nombre = date('dmY', strtotime($fecha));
    $nombre_archivo = "MAB_200_{$fecha_nombre}.txt";
    $ruta_completa = $ruta_archivos . $nombre_archivo;
    
    // CORRECCIÓN: Obtener datos de Archivo_200_Temporal en lugar de Archivo_200
    $sql = "SELECT 
        campo_1_3,
        campo_4,
        campo_5_29,
        campo_30_46,
        campo_47_49,
        campo_50_51,
        campo_52_53,
        campo_54_55,
        campo_56_63,
        campo_64_119
    FROM Archivo_200_Temporal  -- CAMBIO: Usar la tabla temporal
    ORDER BY campo_5_29, campo_47_49";
    
    $stmt = $db->secure_query($sql);  // NOTA: Sin parámetros ya que no filtramos por fecha
    
    if ($stmt) {
        $archivo = fopen($ruta_completa, 'w');
        $lineas_generadas = 0;
        
        if ($archivo) {
            while ($fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Construir cada campo con longitud EXACTA
                $campo_1_3   = str_pad($fila['campo_1_3'] ?? '200', 3, ' ', STR_PAD_RIGHT);
                $campo_4     = str_pad($fila['campo_4'] ?? '6', 1, ' ', STR_PAD_RIGHT);
                $campo_5_29  = str_pad($fila['campo_5_29'] ?? '', 25, ' ', STR_PAD_RIGHT);
                $campo_30_46 = str_pad($fila['campo_30_46'] ?? date('dmY H:i:s'), 17, ' ', STR_PAD_RIGHT);
                
                // Campo 47-49: numérico, formatear a 3 dígitos
                $campo_47_49_valor = $fila['campo_47_49'] ?? 1;
                $campo_47_49 = str_pad((string)$campo_47_49_valor, 3, '0', STR_PAD_LEFT);
                
                $campo_50_51 = str_pad($fila['campo_50_51'] ?? '3N', 2, ' ', STR_PAD_RIGHT);
                $campo_52_53 = str_pad($fila['campo_52_53'] ?? '1', 2, ' ', STR_PAD_RIGHT);
                $campo_54_55 = str_pad($fila['campo_54_55'] ?? '  ', 2, ' ', STR_PAD_RIGHT);
                $campo_56_63 = str_pad($fila['campo_56_63'] ?? 'exmabdis', 8, ' ', STR_PAD_RIGHT);
                $campo_64_119 = str_pad($fila['campo_64_119'] ?? '', 56, ' ', STR_PAD_RIGHT);
                
                // Unir todos los campos
                $linea = $campo_1_3 . $campo_4 . $campo_5_29 . $campo_30_46 . 
                         $campo_47_49 . $campo_50_51 . $campo_52_53 . $campo_54_55 . 
                         $campo_56_63 . $campo_64_119;
                
                // Ajustar a 119 caracteres exactos
                $linea = str_pad($linea, 119, ' ', STR_PAD_RIGHT);
                
                fwrite($archivo, $linea . PHP_EOL);
                $lineas_generadas++;
            }
            
            fclose($archivo);
            
            return [
                'nombre_archivo' => $nombre_archivo,
                'lineas_generadas' => $lineas_generadas,
                'ruta_completa' => $ruta_completa
            ];
            
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
                                <i class="bi bi-house-door-fill me-2"></i>Inicio
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
                            <a class="nav-link active" href="generar_archivo200.php">
                                <i class="bi bi-file-earmark-arrow-down me-2"></i>Archivo 200
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="carga_excel.php">
                                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Carga Excel
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="gestiones.php">
                                <i class="bi bi-chat-dots me-2"></i>Gestiones Diarias
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
                                                <label class="form-label">Fecha de Procesamiento *</label>
                                                <select class="form-select" name="fecha_procesamiento" required>
                                                    <option value="">-- Seleccionar fecha --</option>
                                                    <?php foreach ($fechas_disponibles as $fecha): 
                                                        $fecha_formateada = date('d/m/Y', strtotime($fecha));
                                                        $selected = ($fecha == date('Y-m-d')) ? 'selected' : '';
                                                    ?>
                                                        <option value="<?php echo $fecha; ?>" <?php echo $selected; ?>>
                                                            <?php echo $fecha_formateada; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="form-text text-muted">
                                                    Fechas disponibles con gestiones cargadas
                                                </small>
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
                                            <li>Seleccione la fecha para la cual generar el Archivo 200</li>
                                            <li>Solo se muestran fechas que tienen gestiones cargadas</li>
                                            <li>El archivo se generará con el nombre: <strong>MAB_200_DDMMYYYY.txt</strong></li>
                                            <li>Los archivos se guardan en: <strong>/files/archivo200/</strong></li>
                                            <li>El archivo contendrá los datos reales de las gestiones diarias</li>
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
                                    <?php
                                    // Obtener últimas ejecuciones
                                    try {
                                        $sql_ultimas = "SELECT TOP 5 fecha_procesamiento, usuario_creacion, 
                                                       (SELECT COUNT(*) FROM Archivo_200 a2 
                                                        WHERE a2.fecha_procesamiento = a.fecha_procesamiento 
                                                        AND a2.estado = 'PROCESADO') as registros
                                                FROM Archivo_200 a
                                                WHERE estado = 'PROCESADO'
                                                GROUP BY fecha_procesamiento, usuario_creacion
                                                ORDER BY fecha_procesamiento DESC";
                                        $stmt_ultimas = $db->secure_query($sql_ultimas);
                                        
                                        if ($stmt_ultimas && sqlsrv_has_rows($stmt_ultimas)) {
                                            while ($fila = sqlsrv_fetch_array($stmt_ultimas, SQLSRV_FETCH_ASSOC)) {
                                                $fecha_ejec = $fila['fecha_procesamiento'];
                                                if ($fecha_ejec instanceof DateTime) {
                                                    $fecha_formateada = $fecha_ejec->format('d/m/Y');
                                                } else {
                                                    $fecha_formateada = date('d/m/Y', strtotime($fecha_ejec));
                                                }
                                                echo '<div class="list-group-item bg-transparent">';
                                                echo '<div class="d-flex w-100 justify-content-between">';
                                                echo '<small class="text-primary">' . $fecha_formateada . '</small>';
                                                echo '<span class="badge bg-success">' . $fila['registros'] . ' reg.</span>';
                                                echo '</div>';
                                                echo '<small class="text-muted">' . htmlspecialchars($fila['usuario_creacion']) . '</small>';
                                                echo '</div>';
                                            }
                                        } else {
                                            echo '<div class="list-group-item bg-transparent text-center">';
                                            echo '<small class="text-muted">No hay ejecuciones recientes</small>';
                                            echo '</div>';
                                        }
                                    } catch (Exception $e) {
                                        echo '<div class="list-group-item bg-transparent text-center">';
                                        echo '<small class="text-muted">Error cargando historial</small>';
                                        echo '</div>';
                                    }
                                    ?>
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
                                    <strong>Estructura (119 caracteres):</strong><br>
                                    1-3: '200'<br>
                                    4: Grupo (3/6)<br>
                                    5-29: No. Caso (25 chars)<br>
                                    30-46: Fecha DDMMYYYY HH:MM:SS<br>
                                    47-49: Secuencia (3 dígitos)<br>
                                    50-51: Cód. Acción<br>
                                    52-53: Cód. Resultado<br>
                                    54-55: Cód. Carta<br>
                                    56-63: Gestor (8 chars)<br>
                                    64-119: Teléfono + Comentario (56 chars)
                                </small>
                            </div>
                        </div>

                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-info-circle me-2"></i>Fechas Disponibles
                                </h5>
                            </div>
                            <div class="card-body">
                                <small>
                                    <strong>Gestiones cargadas para:</strong><br>
                                    <?php if (!empty($fechas_disponibles)): ?>
                                        <?php foreach (array_slice($fechas_disponibles, 0, 5) as $fecha): ?>
                                            • <?php echo date('d/m/Y', strtotime($fecha)); ?><br>
                                        <?php endforeach; ?>
                                        <?php if (count($fechas_disponibles) > 5): ?>
                                            <em>... y <?php echo count($fechas_disponibles) - 5; ?> más</em>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <em>No hay gestiones cargadas</em>
                                    <?php endif; ?>
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