<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_auth();

// Directorios permitidos
$directorios_admin = ['asignacion', 'convenio', 'gestionesdiarias', 'pagos'];
$directorios_ejecutivo = ['gestionesdiarias', 'pagos'];
$directorios_permitidos = ($_SESSION['perfil'] === 'administrador') ? $directorios_admin : $directorios_ejecutivo;

// Obtener directorio actual
$directorio_actual = $_GET['directorio'] ?? ($directorios_permitidos[0] ?? '');

// Validar que el directorio esté permitido
if (!in_array($directorio_actual, $directorios_permitidos)) {
    $directorio_actual = $directorios_permitidos[0];
}

// Procesar eliminación de archivo
if ($_GET['action'] === 'eliminar' && isset($_GET['archivo']) && isset($_GET['directorio'])) {
    $archivo_eliminar = $_GET['archivo'];
    $directorio_eliminar = $_GET['directorio'];
    
    // Validar seguridad
    if (in_array($directorio_eliminar, $directorios_permitidos)) {
        $ruta_archivo = "../../files/{$directorio_eliminar}/{$archivo_eliminar}";
        
        if (file_exists($ruta_archivo) && is_file($ruta_archivo)) {
            if (unlink($ruta_archivo)) {
                $mensaje = "✅ Archivo eliminado exitosamente";
                $mensaje_tipo = "success";
            } else {
                $mensaje = "❌ Error al eliminar el archivo";
                $mensaje_tipo = "danger";
            }
        } else {
            $mensaje = "❌ El archivo no existe";
            $mensaje_tipo = "danger";
        }
    } else {
        $mensaje = "❌ Directorio no permitido";
        $mensaje_tipo = "danger";
    }
}

// Obtener archivos del directorio
$ruta_directorio = "../../files/{$directorio_actual}";
$archivos = [];

if (is_dir($ruta_directorio)) {
    $items = scandir($ruta_directorio);
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            $ruta_completa = $ruta_directorio . '/' . $item;
            if (is_file($ruta_completa)) {
                $archivos[] = [
                    'nombre' => $item,
                    'ruta' => $ruta_completa,
                    'tamaño' => filesize($ruta_completa),
                    'modificado' => filemtime($ruta_completa),
                    'extension' => strtolower(pathinfo($item, PATHINFO_EXTENSION))
                ];
            }
        }
    }
    
    // Ordenar por fecha de modificación (más reciente primero)
    usort($archivos, function($a, $b) {
        return $b['modificado'] - $a['modificado'];
    });
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archivos - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .file-icon {
            font-size: 1.2em;
            margin-right: 8px;
        }
        .csv-file { color: #198754; }
        .excel-file { color: #0d6efd; }
        .other-file { color: #6c757d; }
        .table-hover tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.075);
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
                            <a class="nav-link active" href="archivos.php">
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
                        <i class="bi bi-files me-2"></i>Archivos Subidos
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
                <?php if (isset($mensaje)): ?>
                <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Selector de Directorio -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <label class="form-label"><strong>Seleccionar Directorio:</strong></label>
                                <select class="form-select" id="directorioSelect" onchange="cambiarDirectorio(this.value)">
                                    <?php foreach ($directorios_permitidos as $dir): ?>
                                        <option value="<?php echo $dir; ?>" <?php echo $directorio_actual === $dir ? 'selected' : ''; ?>>
                                            /files/<?php echo $dir; ?>/
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 text-end">
                                <a href="index.php" class="btn btn-primary">
                                    <i class="bi bi-cloud-upload me-2"></i>Subir Nuevo Archivo
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas Rápidas -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <i class="bi bi-file-earmark-text display-6"></i>
                                <h4 class="mt-2"><?php echo count($archivos); ?></h4>
                                <p class="mb-0">Archivos Totales</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <i class="bi bi-filetype-csv display-6"></i>
                                <h4 class="mt-2"><?php echo count(array_filter($archivos, fn($a) => $a['extension'] === 'csv')); ?></h4>
                                <p class="mb-0">Archivos CSV</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <i class="bi bi-file-earmark-spreadsheet display-6"></i>
                                <h4 class="mt-2"><?php echo count(array_filter($archivos, fn($a) => in_array($a['extension'], ['xlsx', 'xls']))); ?></h4>
                                <p class="mb-0">Archivos Excel</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body text-center">
                                <i class="bi bi-folder display-6"></i>
                                <h4 class="mt-2">/<?php echo $directorio_actual; ?>/</h4>
                                <p class="mb-0">Directorio Actual</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista de Archivos -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-folder2-open me-2"></i>
                            Archivos en: /files/<?php echo $directorio_actual; ?>/
                        </h5>
                        <span class="badge bg-primary"><?php echo count($archivos); ?> archivos</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($archivos)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-folder-x display-1 text-muted"></i>
                                <h4 class="mt-3 text-muted">No hay archivos</h4>
                                <p class="text-muted">Este directorio está vacío</p>
                                <a href="index.php" class="btn btn-primary">
                                    <i class="bi bi-cloud-upload me-2"></i>Subir Primer Archivo
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Archivo</th>
                                            <th>Tamaño</th>
                                            <th>Fecha de Modificación</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($archivos as $archivo): ?>
                                            <tr>
                                                <td>
                                                    <?php 
                                                    $icon_class = '';
                                                    if ($archivo['extension'] === 'csv') {
                                                        $icon_class = 'csv-file';
                                                        $icon = 'bi-file-earmark-text';
                                                    } elseif (in_array($archivo['extension'], ['xlsx', 'xls'])) {
                                                        $icon_class = 'excel-file';
                                                        $icon = 'bi-file-earmark-spreadsheet';
                                                    } else {
                                                        $icon_class = 'other-file';
                                                        $icon = 'bi-file-earmark';
                                                    }
                                                    ?>
                                                    <i class="file-icon <?php echo $icon_class; ?> <?php echo $icon; ?>"></i>
                                                    <strong><?php echo htmlspecialchars($archivo['nombre']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo strtoupper($archivo['extension']); ?> file</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php 
                                                        $tamaño = $archivo['tamaño'];
                                                        if ($tamaño >= 1048576) {
                                                            echo round($tamaño / 1048576, 2) . ' MB';
                                                        } elseif ($tamaño >= 1024) {
                                                            echo round($tamaño / 1024, 2) . ' KB';
                                                        } else {
                                                            echo $tamaño . ' bytes';
                                                        }
                                                        ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo date('d/m/Y H:i:s', $archivo['modificado']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="../../files/<?php echo $directorio_actual . '/' . urlencode($archivo['nombre']); ?>" 
                                                           class="btn btn-outline-success" 
                                                           download
                                                           title="Descargar">
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                        <button class="btn btn-outline-danger" 
                                                                onclick="confirmarEliminacion('<?php echo htmlspecialchars($archivo['nombre']); ?>', '<?php echo $directorio_actual; ?>')"
                                                                title="Eliminar">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function cambiarDirectorio(directorio) {
            window.location.href = 'archivos.php?directorio=' + directorio;
        }

        function confirmarEliminacion(nombreArchivo, directorio) {
            if (confirm(`¿Estás seguro de eliminar el archivo "${nombreArchivo}"?\n\nEsta acción no se puede deshacer.`)) {
                window.location.href = `archivos.php?action=eliminar&archivo=${encodeURIComponent(nombreArchivo)}&directorio=${directorio}`;
            }
        }

        // Auto-refresh cada 30 segundos para ver archivos nuevos
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>