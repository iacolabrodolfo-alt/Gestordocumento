<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

$db = new Database();
$db->connect();

$mensaje = '';
$mensaje_tipo = '';

// Procesar carga de archivos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    $tipo_archivo = $_POST['tipo_archivo'];
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
    elseif ($archivo['size'] > 50 * 1024 * 1024) { // 50MB máximo
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
            // Aquí procesaremos el Excel (próximo paso)
            $mensaje = "Archivo subido exitosamente. Ruta: " . $nombre_archivo;
            $mensaje_tipo = 'success';
            
            // Guardar registro de carga
            guardarRegistroCarga($tipo_archivo, $nombre_archivo, $archivo['name'], $usuario, $db);
        } else {
            $mensaje = "Error al guardar el archivo en el servidor";
            $mensaje_tipo = 'danger';
        }
    }
}

// Función para guardar registro de carga
function guardarRegistroCarga($tipo, $nombre_archivo, $nombre_original, $usuario, $db) {
    $sql = "INSERT INTO Logs_Carga_Excel (tipo_archivo, nombre_archivo, nombre_original, usuario_carga, fecha_carga) 
            VALUES (?, ?, ?, ?, GETDATE())";
    $params = array($tipo, $nombre_archivo, $nombre_original, $usuario);
    $db->secure_query($sql, $params);
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carga de Archivos Excel - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .upload-card {
            border: 2px dashed #dee2e6;
            border-radius: 15px;
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
        .archivo-type {
            border-left: 4px solid #0d6efd;
            padding-left: 15px;
        }
        .archivo-type.stock {
            border-left-color: #198754;
        }
        .archivo-type.judicial {
            border-left-color: #ffc107;
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
                        <li class="nav-item">
                            <a class="nav-link" href="../usuarios/crud.php">
                                <i class="bi bi-people me-2"></i>Gestión de Usuarios
                            </a>
                        </li>
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
                            <a class="nav-link active" href="carga_excel.php">
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
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Carga de Archivos Excel
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
                                    <i class="bi bi-cloud-upload me-2"></i>Subir Archivo Excel
                                </h5>
                            </div>
                            <div class="card-body">
                                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tipo de Archivo *</label>
                                                <select class="form-select" name="tipo_archivo" required id="tipoArchivo">
                                                    <option value="">-- Seleccionar tipo --</option>
                                                    <option value="ASIGNACION_STOCK">Asignación Stock Mensual</option>
                                                    <option value="JUDICIAL_BASE">Judicial - Hoja BASE</option>
                                                    <option value="JUDICIAL_EXCLUIDOS">Judicial - Hoja EXCLUIDOS</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Archivo Excel *</label>
                                                <input type="file" class="form-control" name="archivo_excel" 
                                                       accept=".xlsx,.xls" required id="fileInput">
                                                <small class="form-text text-muted">Formatos: XLSX, XLS (Máx. 50MB)</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Área de Drag & Drop -->
                                    <div class="upload-card p-4 text-center mt-3" id="dropArea">
                                        <i class="bi bi-file-earmark-spreadsheet display-4 text-muted"></i>
                                        <h5 class="mt-2">Arrastra y suelta tu archivo Excel aquí</h5>
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

                                    <!-- Información según tipo de archivo -->
                                    <div id="infoAsignacion" class="archivo-type stock mt-3" style="display: none;">
                                        <h6><i class="bi bi-info-circle me-2"></i>Información Asignación Stock:</h6>
                                        <ul class="small mb-0">
                                            <li>Archivo: <strong>Asignacion 202510 - MAB.xlsx</strong> (formato mensual)</li>
                                            <li>Estructura: PERIODO_PROCESO, RUT, CONTRATO, NOMBRE, SALDO, etc.</li>
                                            <li>Periodo se detecta automáticamente del nombre del archivo</li>
                                        </ul>
                                    </div>

                                    <div id="infoJudicialBase" class="archivo-type judicial mt-3" style="display: none;">
                                        <h6><i class="bi bi-info-circle me-2"></i>Información Judicial BASE:</h6>
                                        <ul class="small mb-0">
                                            <li>Archivo: <strong>MAB - TCJ CAR PAGARE APERTURA 02-10-2025.xlsx</strong></li>
                                            <li>Hoja: <strong>Base</strong></li>
                                            <li>Estructura: periodo, juicio_id, contrato, rut, saldo, etc.</li>
                                        </ul>
                                    </div>

                                    <div id="infoJudicialExcluidos" class="archivo-type judicial mt-3" style="display: none;">
                                        <h6><i class="bi bi-info-circle me-2"></i>Información Judicial EXCLUIDOS:</h6>
                                        <ul class="small mb-0">
                                            <li>Archivo: <strong>MAB - TCJ CAR PAGARE APERTURA 02-10-2025.xlsx</strong></li>
                                            <li>Hoja: <strong>EXLUIDOS [MES]</strong> (mes cambia mensualmente)</li>
                                            <li>Estructura: periodo, juicio_id, contrato, rut, saldo, etc.</li>
                                        </ul>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100 mt-3" id="submitBtn">
                                        <i class="bi bi-upload me-2"></i>Procesar Archivo Excel
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Panel de Información -->
                    <div class="col-lg-4">
                        <!-- Estadísticas Rápidas -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Estadísticas de Carga
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2">
                                            <i class="bi bi-file-earmark-spreadsheet text-primary display-6"></i>
                                            <h5 class="mt-2" id="statsArchivos">0</h5>
                                            <small>Archivos Cargados</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2">
                                            <i class="bi bi-database text-success display-6"></i>
                                            <h5 class="mt-2" id="statsRegistros">0</h5>
                                            <small>Registros Totales</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Últimas Cargas -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history me-2"></i>Últimas Cargas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item bg-transparent">
                                        <small class="text-muted">No hay cargas recientes</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Formatos Soportados -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-file-text me-2"></i>Formatos Soportados
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <h6>Asignación Stock:</h6>
                                    <small class="text-muted">Asignacion YYYYMM - MAB.xlsx</small>
                                </div>
                                <div class="mb-3">
                                    <h6>Judicial:</h6>
                                    <small class="text-muted">MAB - TCJ CAR PAGARE APERTURA DD-MM-YYYY.xlsx</small>
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
        const tipoArchivo = document.getElementById('tipoArchivo');
        const submitBtn = document.getElementById('submitBtn');

        // Mostrar/ocultar información según tipo de archivo
        tipoArchivo.addEventListener('change', function() {
            // Ocultar todos los paneles de info
            document.getElementById('infoAsignacion').style.display = 'none';
            document.getElementById('infoJudicialBase').style.display = 'none';
            document.getElementById('infoJudicialExcluidos').style.display = 'none';
            
            // Mostrar el correspondiente
            if (this.value === 'ASIGNACION_STOCK') {
                document.getElementById('infoAsignacion').style.display = 'block';
            } else if (this.value === 'JUDICIAL_BASE') {
                document.getElementById('infoJudicialBase').style.display = 'block';
            } else if (this.value === 'JUDICIAL_EXCLUIDOS') {
                document.getElementById('infoJudicialExcluidos').style.display = 'block';
            }
        });

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
            const tipo = document.getElementById('tipoArchivo').value;
            const archivo = document.getElementById('fileInput').files[0];
            
            if (!tipo) {
                e.preventDefault();
                alert('Por favor selecciona el tipo de archivo');
                return false;
            }
            
            if (!archivo) {
                e.preventDefault();
                alert('Por favor selecciona un archivo Excel');
                return false;
            }
            
            // Mostrar loading
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Procesando...';
            submitBtn.disabled = true;
        });

        // Cargar estadísticas (simuladas por ahora)
        function cargarEstadisticas() {
            document.getElementById('statsArchivos').textContent = '0';
            document.getElementById('statsRegistros').textContent = '0';
        }

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            cargarEstadisticas();
        });
    </script>
</body>
</html>