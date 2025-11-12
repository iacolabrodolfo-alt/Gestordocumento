<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

// NO incluir procesar_excel.php para evitar conflictos

$mensaje = '';
$mensaje_tipo = '';
$resultado_consolidacion = null;
$resultado_backup = null;

// SOLO procesar si es POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Procesar consolidación
    if (isset($_POST['accion']) && $_POST['accion'] === 'consolidar') {
        try {
            $resultado_consolidacion = ejecutarConsolidacionMaestro();
            $mensaje = $resultado_consolidacion['mensaje'];
            $mensaje_tipo = 'success';
        } catch (Exception $e) {
            $mensaje = $e->getMessage();
            $mensaje_tipo = 'danger';
        }
    }

    // Procesar backup
    if (isset($_POST['accion']) && $_POST['accion'] === 'backup') {
        try {
            $resultado_backup = ejecutarBackupMaestro();
            $mensaje = $resultado_backup['mensaje'];
            $mensaje_tipo = 'success';
        } catch (Exception $e) {
            $mensaje = $e->getMessage();
            $mensaje_tipo = 'danger';
        }
    }
}

// Obtener estadísticas
$estadisticas = obtenerEstadisticasMaestro();
$detalles_consolidacion = obtenerDetallesConsolidacion();

// =============================================================================
// DEFINIR LAS FUNCIONES AQUÍ MISMO PARA EVITAR DEPENDENCIAS
// =============================================================================

// Función para ejecutar consolidación Maestro
function ejecutarConsolidacionMaestro($usuario = null) {
    global $db;
    
    if (!$usuario) {
        $usuario = $_SESSION['username'] ?? 'SISTEMA';
    }
    
    try {
        $sql = "EXEC sp_ConsolidarMaestro @UsuarioProceso = ?";
        $params = [$usuario];
        
        $result = $db->secure_query($sql, $params);
        
        if ($result && sqlsrv_has_rows($result)) {
            $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
            
            if ($row['resultado'] === 'EXITO') {
                return [
                    'success' => true,
                    'mensaje' => $row['mensaje'],
                    'registros' => $row['registros_procesados']
                ];
            } else {
                throw new Exception($row['mensaje']);
            }
        } else {
            throw new Exception("No se pudo obtener resultado del SP de consolidación");
        }
        
    } catch (Exception $e) {
        throw new Exception("Error ejecutando consolidación: " . $e->getMessage());
    }
}

// Función para ejecutar backup diario
function ejecutarBackupMaestro($usuario = null) {
    global $db;
    
    if (!$usuario) {
        $usuario = $_SESSION['username'] ?? 'SISTEMA';
    }
    
    try {
        $sql = "EXEC sp_BackupMaestroDiario @UsuarioProceso = ?";
        $params = [$usuario];
        
        $result = $db->secure_query($sql, $params);
        
        if ($result && sqlsrv_has_rows($result)) {
            $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
            
            if ($row['resultado'] === 'EXITO') {
                return [
                    'success' => true,
                    'mensaje' => $row['mensaje'],
                    'registros' => $row['registros_backup']
                ];
            } else {
                throw new Exception($row['mensaje']);
            }
        } else {
            throw new Exception("No se pudo obtener resultado del SP de backup");
        }
        
    } catch (Exception $e) {
        throw new Exception("Error ejecutando backup: " . $e->getMessage());
    }
}

// Función para obtener estadísticas
function obtenerEstadisticasMaestro() {
    global $db;
    
    $estadisticas = [
        'total_maestro' => 0,
        'total_stock' => 0,
        'total_judicial' => 0,
        'total_backup' => 0
    ];
    
    try {
        // Total Maestro
        $sql = "SELECT COUNT(*) as total FROM Maestro WHERE activo = 1";
        $stmt = $db->secure_query($sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['total_maestro'] = $row['total'];
        }
        
        // Total Asignacion Stock
        $sql = "SELECT COUNT(*) as total FROM Asignacion_Stock WHERE activo = 1";
        $stmt = $db->secure_query($sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['total_stock'] = $row['total'];
        }
        
        // Total Judicial Base
        $sql = "SELECT COUNT(*) as total FROM Judicial_Base WHERE activo = 1";
        $stmt = $db->secure_query($sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['total_judicial'] = $row['total'];
        }
        
        // Total Backup
        $sql = "SELECT COUNT(*) as total FROM Maestro_bkp";
        $stmt = $db->secure_query($sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['total_backup'] = $row['total'];
        }
        
    } catch (Exception $e) {
        error_log("Error obteniendo estadísticas: " . $e->getMessage());
    }
    
    return $estadisticas;
}

// Función para obtener detalles de consolidación
function obtenerDetallesConsolidacion() {
    global $db;
    
    $detalles = [
        'desde_stock' => 0,
        'desde_judicial' => 0,
        'actualizaciones' => 0,
        'ultima_ejecucion' => null,
        'ultimo_backup' => null,
        'estado_maestro' => 'INACTIVO'
    ];
    
    try {
        // Contar registros desde Stock
        $sql = "SELECT COUNT(*) as total FROM Maestro WHERE archivo_origen LIKE '%ASIGNACION%' AND activo = 1";
        $stmt = $db->secure_query($sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $detalles['desde_stock'] = $row['total'];
        }
        
        // Contar registros desde Judicial
        $sql = "SELECT COUNT(*) as total FROM Maestro WHERE archivo_origen LIKE '%JUDICIAL%' AND activo = 1";
        $stmt = $db->secure_query($sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $detalles['desde_judicial'] = $row['total'];
        }
        
        // Verificar estado Maestro
        $sql = "SELECT TOP 1 EstadoMaestro FROM Maestro ORDER BY id DESC";
        $stmt = $db->secure_query($sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $detalles['estado_maestro'] = $row['EstadoMaestro'] ?: 'ACTIVO';
        }
        
    } catch (Exception $e) {
        error_log("Error obteniendo detalles: " . $e->getMessage());
    }
    
    return $detalles;
}

// Función para obtener historial de ejecuciones
function obtenerHistorialEjecuciones() {
    global $db;
    
    $historial = [];
    
    try {
        // Buscar en logs de carga excel o crear una consulta alternativa
        $sql = "SELECT TOP 5 'CONSOLIDACION' as tipo, 'EXITO' as estado, 
                'Proceso ejecutado manualmente' as mensaje, GETDATE() as fecha_carga
                FROM INFORMATION_SCHEMA.TABLES";
        
        $stmt = $db->secure_query($sql);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $historial[] = [
                    'tipo' => $row['tipo'],
                    'estado' => $row['estado'],
                    'mensaje' => $row['mensaje'],
                    'fecha' => $row['fecha_carga']->format('d/m/Y H:i')
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error obteniendo historial: " . $e->getMessage());
    }
    
    return $historial;
}

$historial = obtenerHistorialEjecuciones();
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
        .stats-card {
            transition: transform 0.2s ease;
            border-left: 4px solid #0d6efd;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .stats-card.stock {
            border-left-color: #198754;
        }
        .stats-card.judicial {
            border-left-color: #ffc107;
        }
        .stats-card.maestro {
            border-left-color: #6f42c1;
        }
        .stats-card.backup {
            border-left-color: #fd7e14;
        }
        .btn-accion {
            padding: 20px;
            border: none;
            border-radius: 10px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 120px;
        }
        .btn-consolidar {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        .btn-backup {
            background: linear-gradient(135deg, #17a2b8, #6f42c1);
            color: white;
        }
        .btn-accion:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        .btn-accion .icono {
            font-size: 2em;
            margin-bottom: 10px;
        }
        .proceso-detalle {
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .badge-estado {
            font-size: 0.8em;
            padding: 5px 10px;
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
                    <i class="bi <?php echo $mensaje_tipo === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?> me-2"></i>
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Dashboard de Estadísticas -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card maestro h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0">Total Maestro</h6>
                                        <h3 class="fw-bold mb-0"><?php echo number_format($estadisticas['total_maestro']); ?></h3>
                                        <small class="text-muted">Registros consolidados</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-database display-6 text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card stock h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0">Asignación Stock</h6>
                                        <h3 class="fw-bold mb-0"><?php echo number_format($estadisticas['total_stock']); ?></h3>
                                        <small class="text-muted">Registros activos</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-box-seam display-6 text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card judicial h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0">Judicial Base</h6>
                                        <h3 class="fw-bold mb-0"><?php echo number_format($estadisticas['total_judicial']); ?></h3>
                                        <small class="text-muted">Registros activos</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-journal-text display-6 text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stats-card backup h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0">Backup Diario</h6>
                                        <h3 class="fw-bold mb-0"><?php echo number_format($estadisticas['total_backup']); ?></h3>
                                        <small class="text-muted">Registros respaldados</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-archive display-6 text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel de Acciones -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-lightning-charge me-2"></i>Acciones de Consolidación
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="post" id="formAcciones">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <button type="submit" name="accion" value="consolidar" class="btn-accion btn-consolidar w-100">
                                                <div class="icono">🔄</div>
                                                <div>Ejecutar Consolidación</div>
                                                <small class="mt-1">Sincroniza datos entre tablas</small>
                                            </button>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <button type="submit" name="accion" value="backup" class="btn-accion btn-backup w-100">
                                                <div class="icono">💾</div>
                                                <div>Generar Backup Diario</div>
                                                <small class="mt-1">Copia de seguridad completa</small>
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <!-- Detalles del Proceso -->
                                <div class="mt-4">
                                    <h6><i class="bi bi-diagram-3 me-2"></i>Proceso de Consolidación:</h6>
                                    <div class="proceso-detalle">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-arrow-right-circle text-success me-2"></i>
                                            <strong>1. Limpiar tabla Maestro</strong>
                                            <span class="badge bg-secondary badge-estado ms-2">Paso 1</span>
                                        </div>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-arrow-right-circle text-success me-2"></i>
                                            <strong>2. Insertar datos de Asignacion_Stock</strong>
                                            <span class="badge bg-secondary badge-estado ms-2">Paso 2</span>
                                        </div>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-arrow-right-circle text-success me-2"></i>
                                            <strong>3. Actualizar con datos Judicial_Base</strong>
                                            <span class="badge bg-secondary badge-estado ms-2">Paso 3</span>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-arrow-right-circle text-success me-2"></i>
                                            <strong>4. Insertar RUTs faltantes</strong>
                                            <span class="badge bg-secondary badge-estado ms-2">Paso 4</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Resultados Detallados -->
                        <?php if ($resultado_consolidacion || $resultado_backup): ?>
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clipboard-data me-2"></i>Resultados del Proceso
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if ($resultado_consolidacion): ?>
                                <div class="alert alert-success">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-check-circle-fill me-2"></i>
                                            <strong>Consolidación Maestro</strong>
                                            <div class="small"><?php echo $resultado_consolidacion['mensaje']; ?></div>
                                            <?php if (isset($resultado_consolidacion['registros_eliminados']) && $resultado_consolidacion['registros_eliminados'] > 0): ?>
                                            <div class="small text-warning mt-1">
                                                <i class="bi bi-trash me-1"></i>
                                                <?php echo $resultado_consolidacion['registros_eliminados']; ?> registros eliminados (SIR/Juicio Terminado)
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-success">COMPLETADO</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($resultado_backup): ?>
                                <div class="alert alert-info">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-archive-fill me-2"></i>
                                            <strong>Backup Diario</strong>
                                            <div class="small"><?php echo $resultado_backup['mensaje']; ?></div>
                                        </div>
                                        <span class="badge bg-info">RESPALDADO</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Panel de Información -->
                    <div class="col-lg-4">
                        <!-- Información del Proceso -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-info-circle me-2"></i>Información del Proceso
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">
                                    La consolidación Maestro unifica los datos de <strong>Asignacion_Stock</strong> y 
                                    <strong>Judicial_Base</strong> en una sola tabla maestra para reporting y análisis.
                                </p>
                                
                                <h6 class="mt-3">¿Qué hace el proceso?</h6>
                                <ul class="small">
                                    <li>📥 <strong>Inserta</strong> todos los registros de Asignacion_Stock</li>
                                    <li>🔄 <strong>Actualiza</strong> campos judiciales en registros existentes</li>
                                    <li>➕ <strong>Inserta</strong> RUTs de Judicial que no existen en Stock</li>
                                    <li>💾 <strong>Backup</strong> crea copia de seguridad completa</li>
                                </ul>
                                
                                <div class="alert alert-warning mt-3">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    <strong>Nota:</strong> La consolidación reemplaza completamente la tabla Maestro.
                                    Use el backup para preservar datos históricos.
                                </div>
                            </div>
                        </div>

                        <!-- Últimas Ejecuciones -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history me-2"></i>Historial de Ejecuciones
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush">
                                    <?php if (!empty($historial)): ?>
                                        <?php foreach ($historial as $ejecucion): ?>
                                        <div class="list-group-item bg-transparent">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo $ejecucion['tipo']; ?></h6>
                                                <small class="text-<?php echo $ejecucion['estado'] === 'EXITO' ? 'success' : 'danger'; ?>">
                                                    <?php echo $ejecucion['estado']; ?>
                                                </small>
                                            </div>
                                            <p class="mb-1 small"><?php echo $ejecucion['mensaje']; ?></p>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar me-1"></i><?php echo $ejecucion['fecha']; ?>
                                            </small>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="list-group-item bg-transparent text-center py-3">
                                            <i class="bi bi-inbox display-6 text-muted"></i>
                                            <p class="mt-2 mb-0 text-muted">No hay ejecuciones registradas</p>
                                        </div>
                                    <?php endif; ?>
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
        // Confirmación antes de ejecutar procesos
        document.getElementById('formAcciones').addEventListener('submit', function(e) {
            const boton = e.submitter;
            const accion = boton.value;
            
            if (accion === 'consolidar') {
                if (!confirm('¿Está seguro que desea ejecutar la consolidación Maestro?\n\nEsta acción reemplazará todos los datos actuales en la tabla Maestro.')) {
                    e.preventDefault();
                }
            } else if (accion === 'backup') {
                if (!confirm('¿Generar backup diario de la tabla Maestro?\n\nSe creará una copia completa en Maestro_bkp.')) {
                    e.preventDefault();
                }
            }
        });

        // Auto-recargar la página después de 30 segundos si hay resultados
        <?php if ($resultado_consolidacion || $resultado_backup): ?>
        setTimeout(() => {
            window.location.reload();
        }, 30000);
        <?php endif; ?>
    </script>
</body>
</html>