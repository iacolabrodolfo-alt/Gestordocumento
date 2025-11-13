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

// Obtener estadísticas
$estadisticas = [
    'total_registros' => 0,
    'ultima_actualizacion' => 'N/A',
    'ultimas_gestiones' => 'N/A',
    'ultimo_archivo200' => 'N/A'
];

try {
    $database = new Database();
    $conn = $database->connect();
    
    if ($conn !== false) {
        // Total de registros en Maestro
        $sql_total = "SELECT COUNT(*) as total FROM [dbo].[Maestro] WHERE activo = 1";
        $stmt_total = $database->secure_query($sql_total);
        if ($stmt_total !== false) {
            $row = sqlsrv_fetch_array($stmt_total, SQLSRV_FETCH_ASSOC);
            $estadisticas['total_registros'] = $row['total'] ?? 0;
            sqlsrv_free_stmt($stmt_total);
        }
        
        // Última actualización de Maestro
        $sql_ultima = "SELECT MAX(fecha_carga) as ultima FROM [dbo].[Maestro]";
        $stmt_ultima = $database->secure_query($sql_ultima);
        if ($stmt_ultima !== false) {
            $row = sqlsrv_fetch_array($stmt_ultima, SQLSRV_FETCH_ASSOC);
            if ($row['ultima'] instanceof DateTime) {
                $estadisticas['ultima_actualizacion'] = $row['ultima']->format('d-m-Y H:i');
            }
            sqlsrv_free_stmt($stmt_ultima);
        }
        
        // Última carga de gestiones
        $sql_gestiones = "SELECT MAX(fecha_carga) as ultima FROM [dbo].[Gestiones_Diarias]";
        $stmt_gestiones = $database->secure_query($sql_gestiones);
        if ($stmt_gestiones !== false) {
            $row = sqlsrv_fetch_array($stmt_gestiones, SQLSRV_FETCH_ASSOC);
            if ($row['ultima'] instanceof DateTime) {
                $estadisticas['ultimas_gestiones'] = $row['ultima']->format('d-m-Y H:i');
            }
            sqlsrv_free_stmt($stmt_gestiones);
        }
        
        // Buscar último archivo 200
        $sql_archivo200 = "SELECT MAX(fecha_carga) as ultimo FROM [dbo].[Maestro] WHERE archivo_origen LIKE '%200%'";
        $stmt_archivo200 = $database->secure_query($sql_archivo200);
        if ($stmt_archivo200 !== false) {
            $row = sqlsrv_fetch_array($stmt_archivo200, SQLSRV_FETCH_ASSOC);
            if ($row['ultimo'] instanceof DateTime) {
                $estadisticas['ultimo_archivo200'] = $row['ultimo']->format('d-m-Y H:i');
            }
            sqlsrv_free_stmt($stmt_archivo200);
        }
    }
} catch (Exception $e) {
    // Error silencioso para estadísticas
}

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
                        
                        // Formatear campos específicos
                        if ($key === 'CONTRATO') {
                            // Forzar contrato como texto para mantener ceros a la izquierda
                            echo "<td style=\"mso-number-format:'\\@';\">" . htmlspecialchars($value) . "</td>";
                        } elseif (in_array($key, ['SALDO_GENERADO', 'MONTO_PAGO', 'MONTO_A_PAGAR', 'SALDO_EN_CAMPAÑA'])) {
                            // Formatear números sin decimales
                            if (is_numeric($value)) {
                                echo "<td>" . number_format(floatval($value), 0, '', '') . "</td>";
                            } else {
                                echo "<td>" . htmlspecialchars($value) . "</td>";
                            }
                        } elseif ($key === 'DESCUENTO') {
                            // Mantener descuento con decimales
                            echo "<td>" . htmlspecialchars($value) . "</td>";
                        } else {
                            echo "<td>" . htmlspecialchars($value) . "</td>";
                        }
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

$mensaje = '';
$mensaje_tipo = '';
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
            transition: all 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
            margin-bottom: 1rem;
        }
        .info-card {
            border-left: 4px solid #6f42c1;
        }
        .dashboard-section {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .process-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.8;
        }
        .btn-outline-purple {
            border-color: #6f42c1;
            color: #6f42c1;
        }
        .btn-outline-purple:hover {
            background-color: #6f42c1;
            color: white;
        }
        .text-purple {
            color: #6f42c1 !important;
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
                            <a class="nav-link active" href="index.php">
                                <i class="bi bi-gear me-2"></i>Procesos
                            </a>

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
                            <a class="nav-link" href="consolidar_maestro.php">
                                <i class="bi bi-database-check me-2"></i>Consolidación Maestro
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="gestiones.php">
                                <i class="bi bi-chat-dots me-2"></i>Gestiones Diarias
                            </a>
                        </li>
                                                <li class="nav-item">
                            <a class="nav-link" href="inhibiciones.php">
                                <i class="bi bi-shield-lock me-2"></i>Inhibiciones
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
                    <!-- Panel de Exportación -->
                    <div class="col-lg-4">
                        <div class="card export-card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-file-earmark-excel me-2"></i>Exportar Maestro Completo
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
                                    <h6><i class="bi bi-info-circle me-2"></i>Características del Export:</h6>
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
                                            <span class="badge bg-success"><?php echo number_format($estadisticas['total_registros'], 0, ',', '.'); ?></span>
                                        </li>
                                        <li class="list-group-item bg-transparent">
                                            <small class="text-muted">✓ Contratos con formato texto</small><br>
                                            <small class="text-muted">✓ Saldos sin decimales</small><br>
                                            <small class="text-muted">✓ Todos los campos incluidos</small>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Panel de Estadísticas -->
                        <div class="card info-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Estadísticas del Sistema
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-12 mb-3">
                                        <div class="stats-card rounded p-3">
                                            <i class="bi bi-database stat-icon"></i>
                                            <div class="stat-number"><?php echo number_format($estadisticas['total_registros'], 0, ',', '.'); ?></div>
                                            <small>Registros en Maestro</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2">
                                            <i class="bi bi-calendar-check text-warning fs-4"></i>
                                            <div class="fw-bold mt-1"><?php echo $estadisticas['ultima_actualizacion']; ?></div>
                                            <small>Última Act. Maestro</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-2">
                                            <i class="bi bi-chat-dots text-info fs-4"></i>
                                            <div class="fw-bold mt-1"><?php echo $estadisticas['ultimas_gestiones']; ?></div>
                                            <small>Últimas Gestiones</small>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="border rounded p-2">
                                            <i class="bi bi-file-earmark-arrow-down text-success fs-4"></i>
                                            <div class="fw-bold mt-1"><?php echo $estadisticas['ultimo_archivo200']; ?></div>
                                            <small>Último Archivo 200</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel de Procesos Disponibles -->
                    <div class="col-lg-8">
                        <div class="dashboard-section">
                            <div class="text-center mb-4">
                                <i class="bi bi-gear-fill process-icon text-primary"></i>
                                <h3>Procesos Disponibles</h3>
                                <p class="text-muted">Selecciona el proceso que deseas ejecutar</p>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <i class="bi bi-file-earmark-arrow-down text-primary fs-1 mb-3"></i>
                                            <h5>Generar Archivo 200</h5>
                                            <p class="text-muted small">Genera el archivo 200 procesando los datos cargados</p>
                                            <a href="generar_archivo200.php" class="btn btn-outline-primary btn-sm">Ejecutar</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <i class="bi bi-database-check text-success fs-1 mb-3"></i>
                                            <h5>Consolidar Maestro</h5>
                                            <p class="text-muted small">Consolida y actualiza la base maestra de datos</p>
                                            <a href="consolidar_maestro.php" class="btn btn-outline-success btn-sm">Ejecutar</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <i class="bi bi-arrow-repeat text-purple fs-1 mb-3"></i>
                                            <h5>Actualizar Maestro</h5>
                                            <p class="text-muted small">Actualiza el Maestro con las gestiones diarias de la semana</p>
                                            <a href="actualizar_maestro_gestiones.php" class="btn btn-outline-purple btn-sm">Ejecutar</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <i class="bi bi-file-earmark-spreadsheet text-warning fs-1 mb-3"></i>
                                            <h5>Carga Masiva Excel</h5>
                                            <p class="text-muted small">Carga archivos Excel para procesamiento</p>
                                            <a href="carga_excel.php" class="btn btn-outline-warning btn-sm">Ejecutar</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <i class="bi bi-chat-dots text-info fs-1 mb-3"></i>
                                            <h5>Gestiones Diarias</h5>
                                            <p class="text-muted small">Procesa y consolida las gestiones diarias</p>
                                            <a href="gestiones.php" class="btn btn-outline-info btn-sm">Ejecutar</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <!-- Ícono personalizado -->
                                            <i class="bi bi-shield-lock text-danger fs-1 mb-3"></i>
                                            <h5>Inhibiciones</h5>
                                            <p class="text-muted small">Procesa y aplica inhibiciones</p>
                                            <!-- Botón con color rojo suave -->
                                            <a href="inhibiciones.php" class="btn btn-outline-danger btn-sm">Ejecutar</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Información del Sistema -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-info-circle me-2"></i>Información del Sistema
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-server me-3 text-primary"></i>
                                            <div>
                                                <strong>Base de Datos</strong><br>
                                                <small class="text-muted">SQL Server - Gestor Documento</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-person-check me-3 text-success"></i>
                                            <div>
                                                <strong>Usuario Activo</strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($_SESSION['nombre_completo']); ?></small>
                                            </div>
                                        </div>
                                    </div>
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
</body>
</html>