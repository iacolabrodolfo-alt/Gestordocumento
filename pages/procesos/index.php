<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
$auth = new Auth();
$auth->require_auth();

// Configuración de directorios permitidos por perfil
$directorios_admin = [
    'asignacion' => 'Archivo de Asignación',
    'convenio' => 'Archivo de Convenio', 
    'gestionesdiarias' => 'Gestiones Diarias',
    'pagos' => 'Archivo de Pagos'
];

$directorios_ejecutivo = [
    'gestionesdiarias' => 'Gestiones Diarias',
    'pagos' => 'Archivo de Pagos'
];

$directorios_permitidos = ($_SESSION['perfil'] === 'administrador') ? $directorios_admin : $directorios_ejecutivo;

// Procesar exportación a Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exportar_excel'])) {
    try {
        $database = new Database();
        $conn = $database->connect();
        
        if ($conn !== false) {
            // Consulta para obtener todos los datos de la tabla Maestro
            $sql = "SELECT * FROM [dbo].[Maestro] WHERE activo = 1";
            $stmt = $database->secure_query($sql);
            
            if ($stmt !== false) {
                // Configurar headers para descarga de Excel
                header('Content-Type: application/vnd.ms-excel');
                header('Content-Disposition: attachment; filename="maestro_completo_' . date('Y-m-d_His') . '.xls"');
                header('Pragma: no-cache');
                header('Expires: 0');
                
                // BOM para UTF-8
                echo "\xEF\xBB\xBF";
                
                // Crear contenido Excel
                echo "<table border='1'>";
                echo "<tr>";
                echo "<th>ID</th>";
                echo "<th>PERIODO PROCESO</th>";
                echo "<th>FECHA PROCESO</th>";
                echo "<th>PERIODO CASTIGO</th>";
                echo "<th>HOMOLOGACION</th>";
                echo "<th>MOTIVO ESTADO</th>";
                echo "<th>SUB ETAPA PROCESAL</th>";
                echo "<th>PERFIL</th>";
                echo "<th>RUT</th>";
                echo "<th>DV</th>";
                echo "<th>CONTRATO</th>";
                echo "<th>NOMBRE</th>";
                echo "<th>PATERNO</th>";
                echo "<th>MATERNO</th>";
                echo "<th>FECHA CASTIGO</th>";
                echo "<th>SALDO GENERADO</th>";
                echo "<th>CLASIFICACION BIENES</th>";
                echo "<th>CANAL</th>";
                echo "<th>CLASIFICACION</th>";
                echo "<th>DIRECCION</th>";
                echo "<th>NUMERACION DIR</th>";
                echo "<th>RESTO</th>";
                echo "<th>REGION</th>";
                echo "<th>COMUNA</th>";
                echo "<th>CIUDAD</th>";
                echo "<th>ABOGADO</th>";
                echo "<th>ZONA</th>";
                echo "<th>CORREO1</th>";
                echo "<th>CORREO2</th>";
                echo "<th>CORREO3</th>";
                echo "<th>CORREO4</th>";
                echo "<th>CORREO5</th>";
                echo "<th>TELEFONO1</th>";
                echo "<th>TELEFONO2</th>";
                echo "<th>TELEFONO3</th>";
                echo "<th>TELEFONO4</th>";
                echo "<th>TELEFONO5</th>";
                echo "<th>FECHA PAGO</th>";
                echo "<th>MONTO PAGO</th>";
                echo "<th>FECHA VENCIMIENTO</th>";
                echo "<th>DIAS MORA</th>";
                echo "<th>FECHA SUSC</th>";
                echo "<th>TIPO CAMPAÑA</th>";
                echo "<th>DESCUENTO</th>";
                echo "<th>MONTO A PAGAR</th>";
                echo "<th>SALDO EN CAMPAÑA</th>";
                echo "<th>FECHA ASIGNACION</th>";
                echo "<th>TIPO CARTERA</th>";
                echo "<th>FECHA PROCESO</th>";
                echo "<th>ESTADO MAESTRO</th>";
                echo "<th>FECHA U GESTION</th>";
                echo "<th>MEJOR GESTION</th>";
                echo "<th>GESTION</th>";
                echo "<th>FECHA CARGA</th>";
                echo "<th>ARCHIVO ORIGEN</th>";
                echo "<th>USUARIO CARGA</th>";
                echo "</tr>";
                
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    echo "<tr>";
                    foreach ($row as $key => $value) {
                        if ($value instanceof DateTime) {
                            $value = $value->format('Y-m-d H:i:s');
                        } elseif ($value === null) {
                            $value = '';
                        }
                        echo "<td>" . htmlspecialchars($value) . "</td>";
                    }
                    echo "</tr>";
                }
                
                echo "</table>";
                exit;
            }
        }
    } catch (Exception $e) {
        $mensaje = "Error al exportar: " . $e->getMessage();
        $mensaje_tipo = 'danger';
    }
}

// Procesar subida de archivos (código existente)
$mensaje = '';
$mensaje_tipo = ''; // success, danger, warning

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $directorio = $_POST['directorio'] ?? '';
    $archivo = $_FILES['archivo'];
    
    // Validar directorio permitido
    if (!array_key_exists($directorio, $directorios_permitidos)) {
        $mensaje = "Error: Directorio no permitido";
        $mensaje_tipo = 'danger';
    } 
    // Validar que se subió un archivo
    elseif ($archivo['error'] !== UPLOAD_ERR_OK) {
        $mensaje = "Error al subir el archivo: " . $archivo['error'];
        $mensaje_tipo = 'danger';
    }
    // Validar tipo de archivo
    elseif (!in_array(pathinfo($archivo['name'], PATHINFO_EXTENSION), ['csv', 'xlsx', 'xls'])) {
        $mensaje = "Error: Solo se permiten archivos CSV o Excel (xlsx, xls)";
        $mensaje_tipo = 'danger';
    }
    // Validar tamaño (máximo 10MB)
    elseif ($archivo['size'] > 10 * 1024 * 1024) {
        $mensaje = "Error: El archivo es demasiado grande (máximo 10MB)";
        $mensaje_tipo = 'danger';
    }
    else {
        // Crear directorio si no existe
        $ruta_directorio = "../../files/{$directorio}";
        if (!is_dir($ruta_directorio)) {
            mkdir($ruta_directorio, 0777, true);
        }
        
        // Generar nombre único para el archivo
        $extension = pathinfo($archivo['name'], PATHINFO_EXTENSION);
        $nombre_archivo = date('Y-m-d_His') . '_' . uniqid() . '.' . $extension;
        $ruta_completa = $ruta_directorio . '/' . $nombre_archivo;
        
        // Mover archivo
        if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
            $mensaje = "Archivo subido exitosamente a: " . $directorios_permitidos[$directorio];
            $mensaje_tipo = 'success';
        } else {
            $mensaje = "Error al guardar el archivo";
            $mensaje_tipo = 'danger';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidación Maestro - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
        }
        .upload-area:hover {
            border-color: #0d6efd;
            background: rgba(13, 110, 253, 0.1);
        }
        .upload-area.dragover {
            border-color: #198754;
            background: rgba(25, 135, 84, 0.1);
        }
        .file-info {
            background: rgba(255,255,255,0.1);
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
        }
        .btn-exportar {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-exportar:hover {
            background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
            color: white;
            transform: translateY(-2px);
        }
        .export-card {
            border-left: 4px solid #28a745;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
                            <a class="nav-link" href="consulta_deudor.php">
                                <i class="bi bi-search me-2"></i>Consulta Deudor
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
                            <a class="nav-link active" href="consolidar_maestro.php">
                                <i class="bi bi-database-check me-2"></i>Consolidación Maestro
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
                        <i class="bi bi-database-check me-2"></i>Consolidación Maestro
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
                    <!-- Panel de Exportación -->
                    <div class="col-lg-4">
                        <div class="card export-card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-file-earmark-excel me-2"></i>Exportar Base Completa
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">
                                    Exporta toda la base de datos Maestro a un archivo Excel con todos los registros activos.
                                </p>
                                
                                <form method="POST" class="mb-3">
                                    <input type="hidden" name="exportar_excel" value="1">
                                    <button type="submit" class="btn btn-exportar w-100">
                                        <i class="bi bi-download me-2"></i>Exportar a Excel
                                    </button>
                                </form>
                                
                                <div class="mt-4">
                                    <h6><i class="bi bi-info-circle me-2"></i>Información del Export:</h6>
                                    <ul class="list-group list-group-flush small">
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            Formato
                                            <span class="badge bg-primary">Excel (.xls)</span>
                                        </li>
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            Codificación
                                            <span class="badge bg-primary">UTF-8</span>
                                        </li>
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            Registros
                                            <span class="badge bg-success">Todos los activos</span>
                                        </li>
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            Columnas
                                            <span class="badge bg-info">Todas las disponibles</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Estadísticas Rápidas -->
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <h5 class="card-title">
                                    <i class="bi bi-database me-2"></i>Base Maestro
                                </h5>
                                <div class="display-6 fw-bold">100%</div>
                                <p class="mb-0">Completa y Consolidada</p>
                                <small>Última actualización: <?php echo date('d/m/Y H:i'); ?></small>
                            </div>
                        </div>
                    </div>

                    <!-- Panel de Subida de Archivos -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-cloud-upload me-2"></i>Subir Archivos
                                </h5>
                            </div>
                            <div class="card-body">
                                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Seleccionar Directorio *</label>
                                                <select class="form-select" name="directorio" required id="directorioSelect">
                                                    <option value="">-- Seleccionar destino --</option>
                                                    <?php foreach ($directorios_permitidos as $key => $value): ?>
                                                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Seleccionar Archivo *</label>
                                                <input type="file" class="form-control" name="archivo" 
                                                       accept=".csv,.xlsx,.xls" required id="fileInput">
                                                <small class="form-text text-muted">Formatos permitidos: CSV, XLSX, XLS (Máx. 10MB)</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Área de Drag & Drop -->
                                    <div class="upload-area mt-3" id="dropArea">
                                        <i class="bi bi-cloud-arrow-up display-4 text-muted"></i>
                                        <h5 class="mt-2">Arrastra y suelta tu archivo aquí</h5>
                                        <p class="text-muted">o haz clic para seleccionar</p>
                                        <div id="fileInfo" class="file-info" style="display: none;">
                                            <strong>Archivo seleccionado:</strong> 
                                            <span id="fileName"></span>
                                            <span id="fileSize" class="badge bg-secondary ms-2"></span>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100 mt-3">
                                        <i class="bi bi-upload me-2"></i>Subir Archivo
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Información de Directorios -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-info-circle me-2"></i>Información de Directorios
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($directorios_permitidos as $key => $value): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-folder me-3 text-warning"></i>
                                            <div>
                                                <strong><?php echo $value; ?></strong><br>
                                                <small class="text-muted">/files/<?php echo $key; ?>/</small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Información Adicional -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-lightbulb me-2"></i>Recomendaciones
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <i class="bi bi-check-circle text-success me-3 fs-4"></i>
                                            <div>
                                                <h6>Exportación Completa</h6>
                                                <small class="text-muted">Incluye todos los campos de la tabla Maestro</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <i class="bi bi-clock text-warning me-3 fs-4"></i>
                                            <div>
                                                <h6>Actualización en Tiempo Real</h6>
                                                <small class="text-muted">Datos actualizados al momento de la exportación</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <i class="bi bi-shield-check text-primary me-3 fs-4"></i>
                                            <div>
                                                <h6>Solo Registros Activos</h6>
                                                <small class="text-muted">Filtrado automático por estado activo</small>
                                            </div>
                                        </div>
                                    </div>
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
        // Drag & Drop functionality
        const dropArea = document.getElementById('dropArea');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');

        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        // Highlight drop area when item is dragged over it
        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });

        // Handle dropped files
        dropArea.addEventListener('drop', handleDrop, false);

        // Click to select file
        dropArea.addEventListener('click', () => {
            fileInput.click();
        });

        // Handle file selection
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
            const directorio = document.getElementById('directorioSelect').value;
            const archivo = document.getElementById('fileInput').files[0];
            
            if (!directorio) {
                e.preventDefault();
                alert('Por favor selecciona un directorio destino');
                return false;
            }
            
            if (!archivo) {
                e.preventDefault();
                alert('Por favor selecciona un archivo');
                return false;
            }
        });
    </script>
</body>
</html>