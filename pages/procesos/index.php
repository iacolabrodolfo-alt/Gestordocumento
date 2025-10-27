<?php
require_once '../../includes/auth.php';
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

// Procesar subida de archivos
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

// Procesar generación de archivo 200
if ($_POST['action'] ?? '' === 'generar_archivo_200') {
    // Aquí luego integraremos la llamada al SP
    $mensaje = "Función de generar Archivo 200 - En desarrollo (SP por implementar)";
    $mensaje_tipo = 'info';
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesos - Gestor Documento</title>
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
        .btn-generar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: bold;
        }
        .btn-generar:hover {
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
                            <a class="nav-link active" href="index.php">
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
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-gear me-2"></i>Procesos Automatizados
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

                    <!-- Panel de Generación de Archivos -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-file-earmark-arrow-down me-2"></i>Generar Archivo 200
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">
                                    Genera el archivo 200 procesando los datos cargados en el sistema.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="action" value="generar_archivo_200">
                                    <button type="submit" class="btn btn-generar w-100">
                                        <i class="bi bi-gear-fill me-2"></i>Generar Archivo 200
                                    </button>
                                </form>
                                
                                <div class="mt-4">
                                    <h6><i class="bi bi-clock-history me-2"></i>Últimas ejecuciones:</h6>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item bg-transparent text-muted">
                                            <small>No hay registros de ejecución</small>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Estadísticas Rápidas -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Estadísticas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2">
                                            <i class="bi bi-folder text-primary display-6"></i>
                                            <h5 class="mt-2"><?php echo count($directorios_permitidos); ?></h5>
                                            <small>Directorios Activos</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2">
                                            <i class="bi bi-person-check text-success display-6"></i>
                                            <h5 class="mt-2"><?php echo $_SESSION['perfil'] === 'administrador' ? 'Admin' : 'Ejec'; ?></h5>
                                            <small>Tu Perfil</small>
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