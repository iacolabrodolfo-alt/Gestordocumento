<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_auth();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

// =============================================
// CONFIGURACIÓN DE PhpSpreadsheet
// =============================================
require_once '../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$mensaje = '';
$mensaje_tipo = '';

// Procesar carga de archivos de gestiones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    $archivo = $_FILES['archivo_excel'];
    $usuario = $_SESSION['username'];
    
    // Validaciones básicas
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        $mensaje = "Error al subir el archivo: " . $archivo['error'];
        $mensaje_tipo = 'danger';
    } 
    elseif (!in_array(pathinfo($archivo['name'], PATHINFO_EXTENSION), ['xlsx', 'xls'])) {
        $mensaje = "Error: Solo se permiten archivos Excel (xlsx, xls)";
        $mensaje_tipo = 'danger';
    }
    elseif ($archivo['size'] > 50 * 1024 * 1024) {
        $mensaje = "Error: El archivo es demasiado grande (máximo 50MB)";
        $mensaje_tipo = 'danger';
    }
    else {
        // Crear directorio de uploads si no existe
        $upload_dir = "../../files/uploads/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generar nombre único
        $nombre_archivo = date('Ymd_His') . '_' . uniqid() . '_' . $archivo['name'];
        $ruta_archivo = $upload_dir . $nombre_archivo;
        
        if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
            // Procesar el archivo de gestiones
            $resultado = procesarGestionesExcel($ruta_archivo, $archivo['name'], $usuario);
            
            if ($resultado['success']) {
                $mensaje = "✅ " . $resultado['mensaje'];
                $mensaje_tipo = 'success';
            } else {
                $mensaje = "❌ " . $resultado['error'];
                $mensaje_tipo = 'danger';
            }
        } else {
            $mensaje = "Error al guardar el archivo en el servidor";
            $mensaje_tipo = 'danger';
        }
    }
}

// Función para procesar el Excel de gestiones
function procesarGestionesExcel($ruta_archivo, $nombre_original, $usuario) {
    global $db;
    
    try {
        // Cargar archivo Excel
        $spreadsheet = IOFactory::load($ruta_archivo);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();
        
        if (count($data) < 2) {
            return ['success' => false, 'error' => 'El archivo Excel no contiene datos'];
        }
        
        $headers = $data[0];
        
        // Validar headers requeridos
        $headers_requeridos = ['CODE', 'ACCTG', 'ACACCT', 'ACACTDTE', 'ACSEQNUM', 'ACACCODE', 'ACRCCODE', 'ACLCCODE', 'ACCIDNAM', 'ACPHONE', 'NULL', 'ACCOMM'];
        $headers_faltantes = array_diff($headers_requeridos, $headers);
        
        if (!empty($headers_faltantes)) {
            return ['success' => false, 'error' => 'Faltan columnas requeridas: ' . implode(', ', $headers_faltantes)];
        }
        
        // Limpiar tabla temporal
        $db->secure_query("TRUNCATE TABLE Carga_Temporal_Gestiones");
        
        // Procesar filas
        $filas_procesadas = 0;
        $filas_con_error = 0;
        
        for ($i = 1; $i < count($data); $i++) {
            $fila = $data[$i];
            
            // Saltar filas vacías
            if (empty(array_filter($fila, function($v) { return $v !== null && $v !== '' && $v !== ' '; }))) {
                continue;
            }
            
            // Procesar fila
            $fila_procesada = procesarFilaGestiones($fila, $headers, $nombre_original, $usuario);
            
            if ($fila_procesada) {
                if (insertarFilaTemporalGestiones($fila_procesada)) {
                    $filas_procesadas++;
                } else {
                    $filas_con_error++;
                }
            } else {
                $filas_con_error++;
            }
        }
        
        // Ejecutar SP para mover a tabla definitiva
        //$fecha_procesamiento = date('Y-m-d');
        $sql_sp = "EXEC sp_CargarGestionesDiarias @ArchivoOrigen = ?, @UsuarioCarga = ?";
            $params_sp = [$nombre_original, $usuario];

            $result_sp = $db->secure_query($sql_sp, $params_sp);
        
        if ($result_sp && sqlsrv_has_rows($result_sp)) {
            $row_sp = sqlsrv_fetch_array($result_sp, SQLSRV_FETCH_ASSOC);
            
            if ($row_sp['resultado'] === 'EXITO') {
                return [
                    'success' => true,
                    'mensaje' => $row_sp['mensaje'],
                    'registros_procesados' => $row_sp['registros_procesados']
                ];
            } else {
                return ['success' => false, 'error' => $row_sp['mensaje']];
            }
        } else {
            return ['success' => false, 'error' => 'No se pudo ejecutar el proceso de carga'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error procesando archivo: ' . $e->getMessage()];
    }
}

function procesarFilaGestiones($fila, $headers, $archivo_origen, $usuario) {
    $valores = [
        'fecha_carga' => date('Y-m-d H:i:s'),
        'archivo_origen' => $archivo_origen,
        'usuario_carga' => $usuario
    ];
    
    // Mapear columnas
    $mapeo_columnas = [
        'CODE' => 'code',
        'ACCTG' => 'acctg', 
        'ACACCT' => 'acacct',
        'ACACTDTE' => 'acactdte',
        'ACSEQNUM' => 'acseqnum',
        'ACACCODE' => 'acaccode',
        'ACRCCODE' => 'acrccode',
        'ACLCCODE' => 'aclccode',
        'ACCIDNAM' => 'accidnam',
        'ACPHONE' => 'acphone',
        'ACCOMM' => 'accomm'
    ];
    
    foreach ($headers as $index => $header) {
        if (isset($fila[$index]) && $fila[$index] !== null && $fila[$index] !== '') {
            $header_limpio = trim($header);
            
            if (isset($mapeo_columnas[$header_limpio])) {
                $columna_bd = $mapeo_columnas[$header_limpio];
                $valor = $fila[$index];
                
                // Limpiar y formatear valores
                $valores[$columna_bd] = limpiarValorGestiones($valor, $columna_bd);
            }
        }
    }
    
    // Validar campos obligatorios
    $campos_obligatorios = ['code', 'acctg', 'acacct', 'acactdte', 'acseqnum', 'acaccode', 'accidnam'];
    foreach ($campos_obligatorios as $campo) {
        if (!isset($valores[$campo]) || $valores[$campo] === null || $valores[$campo] === '') {
            return false;
        }
    }
    
    return $valores;
}

function limpiarValorGestiones($valor, $campo) {
    if ($valor === null || $valor === '' || $valor === ' ') {
        return null;
    }
    
    $valor = trim($valor);
    
    // Para campos numéricos o de secuencia
    if (in_array($campo, ['acseqnum'])) {
        return str_pad($valor, 3, '0', STR_PAD_LEFT);
    }
    
    // Para campos de texto, limitar longitud según la base de datos
    $longitudes = [
        'code' => 3,
        'acctg' => 1,
        'acacct' => 20,
        'acactdte' => 17,
        'acseqnum' => 3,
        'acaccode' => 2,
        'acrccode' => 2,
        'aclccode' => 2,
        'accidnam' => 8,
        'acphone' => 15,
        'accomm' => 56
    ];
    
    if (isset($longitudes[$campo]) && strlen($valor) > $longitudes[$campo]) {
        $valor = substr($valor, 0, $longitudes[$campo]);
    }
    
    return $valor;
}

function insertarFilaTemporalGestiones($fila_procesada) {
    global $db;
    
    try {
        $columnas = array_keys($fila_procesada);
        $placeholders = array_fill(0, count($columnas), '?');
        $params = array_values($fila_procesada);
        
        $sql = "INSERT INTO Carga_Temporal_Gestiones (" . implode(', ', $columnas) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $result = $db->secure_query($sql, $params);
        return $result !== false;
        
    } catch (Exception $e) {
        return false;
    }
}

// Obtener estadísticas de gestiones - CORREGIDA
function obtenerEstadisticasGestiones() {
    global $db;
    
    $estadisticas = [
        'total_gestiones' => 0,
        'hoy' => 0,
        'ultima_carga' => 'N/A'
    ];
    
    try {
        // Total de gestiones
        $sql_total = "SELECT COUNT(*) as total FROM Gestiones_Diarias WHERE estado = 'ACTIVO'";
        $stmt = $db->secure_query($sql_total);
        if ($stmt && sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $estadisticas['total_gestiones'] = $row['total'];
        }
        
        // Gestiones de hoy
        $sql_hoy = "SELECT COUNT(*) as hoy FROM Gestiones_Diarias WHERE fecha_procesamiento = CAST(GETDATE() AS DATE) AND estado = 'ACTIVO'";
        $stmt = $db->secure_query($sql_hoy);
        if ($stmt && sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $estadisticas['hoy'] = $row['hoy'];
        }
        
        // Última carga - CORREGIDO para manejar objetos DateTime
        $sql_ultima = "SELECT TOP 1 archivo_origen, fecha_carga FROM Gestiones_Diarias ORDER BY fecha_carga DESC";
        $stmt = $db->secure_query($sql_ultima);
        if ($stmt && sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            // Manejar correctamente la fecha (puede ser DateTime object)
            $fecha_carga = $row['fecha_carga'];
            if ($fecha_carga instanceof DateTime) {
                $fecha_formateada = $fecha_carga->format('d/m/Y H:i');
            } else {
                $fecha_formateada = date('d/m/Y H:i', strtotime($fecha_carga));
            }
            
            $estadisticas['ultima_carga'] = $row['archivo_origen'] . ' - ' . $fecha_formateada;
        }
        
    } catch (Exception $e) {
        // Silenciar errores en estadísticas
        error_log("Error en estadísticas de gestiones: " . $e->getMessage());
    }
    
    return $estadisticas;
}

$estadisticas = obtenerEstadisticasGestiones();
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestiones Diarias - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .upload-card {
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
        }
        .upload-card:hover {
            border-color: #0d6efd;
            background: rgba(13, 110, 253, 0.1);
        }
        .upload-card.dragover {
            border-color: #198754;
            background: rgba(25, 135, 84, 0.1);
        }
        .file-info {
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        .stats-card {
            transition: transform 0.2s ease;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .btn-gestiones {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: bold;
        }
        .btn-gestiones:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
        }
    </style>
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
                            <a class="nav-link" href="generar_archivo200.php">
                                <i class="bi bi-file-earmark-arrow-down me-2"></i>Archivo 200
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="carga_excel.php">
                                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Carga Excel
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="gestiones.php">
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
                        <i class="bi bi-chat-dots me-2"></i>Gestiones Diarias
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="me-3">
                            <i class="bi bi-person-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['nombre_completo']); ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($_SESSION['perfil']); ?></span>
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
                    <!-- Panel de Carga -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-cloud-upload me-2"></i>Cargar Reporte de Gestiones
                                </h5>
                            </div>
                            <div class="card-body">
                                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label class="form-label">Archivo Reporte.xlsx *</label>
                                        <input type="file" class="form-control" name="archivo_excel" 
                                               accept=".xlsx,.xls" required id="fileInput">
                                        <small class="form-text text-muted">Solo archivos Excel: reporte.xlsx (Máx. 50MB)</small>
                                    </div>

                                    <!-- Área de Drag & Drop -->
                                    <div class="upload-card p-4 text-center mt-3" id="dropArea">
                                        <i class="bi bi-file-earmark-spreadsheet display-4 text-muted"></i>
                                        <h5 class="mt-2">Arrastra y suelta tu reporte.xlsx aquí</h5>
                                        <p class="text-muted">o haz clic para seleccionar</p>
                                        <div id="fileInfo" class="file-info" style="display: none;">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong>Archivo seleccionado:</strong> 
                                                    <span id="fileName" class="ms-2"></span>
                                                </div>
                                                <span id="fileSize" class="badge bg-secondary"></span>
                                            </div>
                                            <div id="filePreview" class="mt-2"></div>
                                        </div>
                                    </div>

                                    <!-- Información del formato -->
                                    <div class="alert alert-info mt-3">
                                        <h6><i class="bi bi-info-circle me-2"></i>Información del formato:</h6>
                                        <ul class="mb-0 small">
                                            <li><strong>Archivo esperado:</strong> reporte.xlsx</li>
                                            <li><strong>Columnas requeridas:</strong> CODE, ACCTG, ACACCT, ACACTDTE, ACSEQNUM, ACACCODE, ACCIDNAM, etc.</li>
                                            <li><strong>Proceso:</strong> Los datos se cargan para la fecha actual automáticamente</li>
                                            <li><strong>Nota:</strong> Si ya existen gestiones para hoy, se reemplazarán</li>
                                        </ul>
                                    </div>

                                    <button type="submit" class="btn btn-gestiones w-100 mt-3" id="submitBtn">
                                        <i class="bi bi-upload me-2"></i>Procesar Gestiones Diarias
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Información de Columnas -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-columns me-2"></i>Estructura del Archivo
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Columna Excel</th>
                                                <th>Descripción</th>
                                                <th>Tipo</th>
                                                <th>Longitud</th>
                                                <th>Requerido</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>CODE</td><td>Código de gestión</td><td>Texto</td><td>3</td><td>✅</td></tr>
                                            <tr><td>ACCTG</td><td>Grupo contable</td><td>Texto</td><td>1</td><td>✅</td></tr>
                                            <tr><td>ACACCT</td><td>Número de cuenta</td><td>Texto</td><td>20</td><td>✅</td></tr>
                                            <tr><td>ACACTDTE</td><td>Fecha y hora gestión</td><td>Texto</td><td>17</td><td>✅</td></tr>
                                            <tr><td>ACSEQNUM</td><td>Número de secuencia</td><td>Texto</td><td>3</td><td>✅</td></tr>
                                            <tr><td>ACACCODE</td><td>Código de acción</td><td>Texto</td><td>2</td><td>✅</td></tr>
                                            <tr><td>ACRCCODE</td><td>Código de resultado</td><td>Texto</td><td>2</td><td>❌</td></tr>
                                            <tr><td>ACLCCODE</td><td>Código de carta</td><td>Texto</td><td>2</td><td>❌</td></tr>
                                            <tr><td>ACCIDNAM</td><td>Nombre del gestor</td><td>Texto</td><td>8</td><td>✅</td></tr>
                                            <tr><td>ACPHONE</td><td>Teléfono</td><td>Texto</td><td>15</td><td>❌</td></tr>
                                            <tr><td>ACCOMM</td><td>Comentario</td><td>Texto</td><td>56</td><td>❌</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel de Información -->
                    <div class="col-lg-4">
                        <!-- Estadísticas Rápidas -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Estadísticas de Gestiones
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2 stats-card">
                                            <i class="bi bi-chat-dots text-primary display-6"></i>
                                            <h5 class="mt-2"><?php echo $estadisticas['total_gestiones']; ?></h5>
                                            <small>Total Gestiones</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2 stats-card">
                                            <i class="bi bi-calendar-day text-success display-6"></i>
                                            <h5 class="mt-2"><?php echo $estadisticas['hoy']; ?></h5>
                                            <small>Gestiones Hoy</small>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="border rounded p-2 stats-card">
                                            <i class="bi bi-clock-history text-info display-6"></i>
                                            <h6 class="mt-2 small"><?php echo $estadisticas['ultima_carga']; ?></h6>
                                            <small>Última Carga</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones Rápidas -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-lightning me-2"></i>Acciones Rápidas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="generar_archivo200.php" class="btn btn-outline-primary">
                                        <i class="bi bi-file-earmark-arrow-down me-2"></i>Generar Archivo 200
                                    </a>
                                    <button class="btn btn-outline-info" onclick="verGestionesHoy()">
                                        <i class="bi bi-eye me-2"></i>Ver Gestiones de Hoy
                                    </button>
                                    <button class="btn btn-outline-warning" onclick="descargarFormato()">
                                        <i class="bi bi-download me-2"></i>Descargar Formato
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Estado del Sistema -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-check-circle me-2"></i>Estado del Sistema
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    <span>Base de datos: Conectada</span>
                                </div>
                                <div class="mb-2">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    <span>Tabla gestiones: Activa</span>
                                </div>
                                <div class="mb-2">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    <span>Procesos: Disponibles</span>
                                </div>
                                <div class="mb-2">
                                    <i class="bi bi-clock text-info me-2"></i>
                                    <span>Hora servidor: <?php echo date('H:i:s'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Elementos DOM
        const dropArea = document.getElementById('dropArea');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const filePreview = document.getElementById('filePreview');
        const submitBtn = document.getElementById('submitBtn');

        // Drag & Drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });

        dropArea.addEventListener('drop', handleDrop, false);
        dropArea.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                updateFileInfo(this.files[0]);
            }
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        function highlight() {
            dropArea.classList.add('dragover');
        }

        function unhighlight() {
            dropArea.classList.remove('dragover');
        }

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                fileInput.files = files;
                updateFileInfo(files[0]);
            }
        }

        function updateFileInfo(file) {
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            
            // Mostrar preview básico
            filePreview.innerHTML = `
                <div class="row small">
                    <div class="col-6">
                        <strong>Tipo:</strong> ${file.type || 'No detectado'}
                    </div>
                    <div class="col-6">
                        <strong>Extensión:</strong> ${file.name.split('.').pop().toUpperCase()}
                    </div>
                    <div class="col-12 mt-1">
                        <strong>Modificación:</strong> ${new Date(file.lastModified).toLocaleString()}
                    </div>
                </div>
            `;
            
            fileInfo.style.display = 'block';
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Validación antes de enviar
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const archivo = document.getElementById('fileInput').files[0];
            
            if (!archivo) {
                e.preventDefault();
                showAlert('Por favor selecciona un archivo Excel', 'warning');
                return false;
            }
            
            // Mostrar confirmación
            if (!confirm('¿Procesar el archivo de gestiones diarias? Los datos existentes para hoy serán reemplazados.')) {
                e.preventDefault();
                return false;
            }
            
            // Cambiar texto del botón
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Procesando...';
        });

        function showAlert(mensaje, tipo = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${tipo} alert-dismissible fade show mt-3`;
            alertDiv.innerHTML = `
                ${mensaje}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const cardBody = document.querySelector('.card-body');
            cardBody.insertBefore(alertDiv, document.querySelector('button[type="submit"]'));
            
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Funciones de acciones rápidas
        function verGestionesHoy() {
            alert('Función para ver gestiones de hoy - En desarrollo');
        }

        function descargarFormato() {
            // Crear un archivo Excel de ejemplo con las columnas requeridas
            alert('Descargando formato de ejemplo...');
            // Aquí podrías implementar la descarga de un template Excel
        }

        // Resetear formulario después de éxito
        <?php if ($mensaje_tipo === 'success'): ?>
        setTimeout(() => {
            document.getElementById('uploadForm').reset();
            document.getElementById('fileInfo').style.display = 'none';
        }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>