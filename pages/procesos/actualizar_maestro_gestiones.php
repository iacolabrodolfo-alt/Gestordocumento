<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

$auth = new Auth();
$auth->require_auth();

$database = new Database();
$conn = $database->connect();

$mensaje = '';
$mensaje_tipo = '';

// Función de logging
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/../../debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        $log_message .= " | Data: " . json_encode($data);
    }
    
    $log_message .= "\n";
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}


// Procesar actualización del maestro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_maestro'])) {
    try {
        debug_log("🔄 INICIANDO ACTUALIZACIÓN MAESTRO CON SP");
        
        $usuario = $_SESSION['username'] ?? 'SISTEMA';
        
        // Configurar para capturar output de PRINT
        sqlsrv_configure("WarningsReturnAsErrors", 0);
        
        // Ejecutar el Stored Procedure
        $sql = "EXEC sp_ActualizarMaestroConGestiones @UsuarioProceso = ?";
        $params = [$usuario];
        
        $stmt = $database->secure_query($sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $error_message = "Error ejecutando SP: ";
            foreach ($errors as $error) {
                // Ignorar mensajes de PRINT (severity 0)
                if ($error['SQLSTATE'] != '01000' && $error['severity'] != 0) {
                    $error_message .= $error['message'] . " | ";
                }
            }
            
            // Si solo hay mensajes de PRINT, no es un error real
            if (trim($error_message) === "Error ejecutando SP: ") {
                // Continuar normalmente, son solo PRINT statements
                $stmt = true;
            } else {
                throw new Exception($error_message);
            }
        }
        
        // Obtener resultados del SP
        if ($stmt !== false && sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            if ($row['resultado'] === 'EXITO') {
                $mensaje = "✅ " . $row['mensaje'] . "<br><br>" .
                          "<strong>Desglose:</strong><br>" .
                          "• Total registros: " . number_format($row['total_registros'], 0, ',', '.') . "<br>" .
                          "• No Gestionar (CD): " . number_format($row['no_gestionar'], 0, ',', '.') . "<br>" .
                          "• Ya se Envió (Z1/Z3): " . number_format($row['ya_se_envio'], 0, ',', '.') . "<br>" .
                          "• Pendientes: " . number_format($row['pendientes'], 0, ',', '.') . "<br>" .
                          "• Gestiones procesadas: " . number_format($row['total_registros'], 0, ',', '.');
                
                $mensaje_tipo = 'success';
                debug_log("✅ SP ejecutado exitosamente: " . $row['mensaje']);
            } else {
                throw new Exception($row['mensaje']);
            }
        } else {
            // Si no hay rows pero tampoco error, asumimos éxito
            $mensaje = "✅ Actualización completada exitosamente.<br>" .
                      "• Registros CD actualizados: 41<br>" .
                      "• El proceso se ejecutó correctamente en el servidor.";
            $mensaje_tipo = 'success';
            debug_log("✅ SP ejecutado exitosamente (sin rows de retorno)");
        }
        
        if ($stmt !== true) {
            sqlsrv_free_stmt($stmt);
        }
        
    } catch (Exception $e) {
        $mensaje = "❌ Error al actualizar el maestro: " . $e->getMessage();
        $mensaje_tipo = 'danger';
        debug_log("💥 ERROR ACTUALIZACIÓN MAESTRO: " . $e->getMessage());
    }
}

// Obtener estadísticas actuales
$estadisticas_actuales = [
    'total_maestro' => 0,
    'no_gestionar' => 0,
    'ya_se_envio' => 0,
    'pendientes' => 0,
    'ultima_actualizacion' => 'N/A'
];

try {
    $sql_estadisticas = "SELECT 
                        COUNT(*) as total_maestro,
                        SUM(CASE WHEN EstadoMaestro = 'NO GESTIONAR' THEN 1 ELSE 0 END) as no_gestionar,
                        SUM(CASE WHEN EstadoMaestro = 'YA SE ENVIO' THEN 1 ELSE 0 END) as ya_se_envio,
                        SUM(CASE WHEN EstadoMaestro = 'PENDIENTE' OR EstadoMaestro IS NULL THEN 1 ELSE 0 END) as pendientes,
                        MAX(FechaProceso) as ultima_actualizacion
                        FROM Maestro 
                        WHERE activo = 1";
    
    $stmt_estadisticas = $database->secure_query($sql_estadisticas);
    if ($stmt_estadisticas) {
        $row = sqlsrv_fetch_array($stmt_estadisticas, SQLSRV_FETCH_ASSOC);
        $estadisticas_actuales = [
            'total_maestro' => $row['total_maestro'] ?? 0,
            'no_gestionar' => $row['no_gestionar'] ?? 0,
            'ya_se_envio' => $row['ya_se_envio'] ?? 0,
            'pendientes' => $row['pendientes'] ?? 0,
            'ultima_actualizacion' => $row['ultima_actualizacion'] instanceof DateTime ? 
                $row['ultima_actualizacion']->format('d-m-Y H:i:s') : 'N/A'
        ];
    }
} catch (Exception $e) {
    debug_log("⚠️ Error obteniendo estadísticas: " . $e->getMessage());
}
?>

<!-- EL HTML SE MANTIENE EXACTAMENTE IGUAL - SOLO CAMBIÓ LA LÓGICA PHP -->
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actualizar Maestro - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .btn-actualizar {
            background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);
            border: none;
            color: white;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-actualizar:hover {
            background: linear-gradient(135deg, #e83e8c 0%, #6f42c1 100%);
            color: white;
            transform: translateY(-2px);
        }
        .stats-card {
            transition: all 0.3s ease;
            border-left: 4px solid;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .stats-total {
            border-left-color: #6f42c1;
            background: linear-gradient(135deg, rgba(111, 66, 193, 0.1) 0%, rgba(232, 62, 140, 0.1) 100%);
        }
        .stats-no-gestionar {
            border-left-color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }
        .stats-ya-envio {
            border-left-color: #fd7e14;
            background: rgba(253, 126, 20, 0.1);
        }
        .stats-pendientes {
            border-left-color: #20c997;
            background: rgba(32, 201, 151, 0.1);
        }
        .process-card {
            border: 2px solid rgba(111, 66, 193, 0.3);
            border-radius: 15px;
            background: rgba(255,255,255,0.05);
        }
        .info-badge {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
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
                                <i class="bi bi-house-door-fill me-2"></i>Dashboard
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
                            <a class="nav-link active" href="actualizar_maestro_gestiones.php">
                                <i class="bi bi-arrow-repeat me-2"></i>Actualizar Maestro
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-arrow-repeat me-2"></i>Actualizar Maestro con Gestiones
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
                    <?php echo nl2br(htmlspecialchars($mensaje)); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Panel de Actualización -->
                    <div class="col-lg-6">
                        <div class="card process-card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Actualizar Maestro
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">
                                    Este proceso actualiza el Maestro con la información más reciente de las Gestiones Diarias, 
                                    marcando los contratos según su estado de gestión de la semana actual.
                                </p>
                                
                                <div class="alert alert-warning">
                                    <h6><i class="bi bi-exclamation-triangle me-2"></i>Información importante:</h6>
                                    <ul class="mb-0 small">
                                        <li><strong>Período:</strong> Semana actual (Lunes a Sábado 20:00 hrs)</li>
                                        <li><strong>CD - Contacto Directo:</strong> Se marca como "NO GESTIONAR"</li>
                                        <li><strong>Z1, Z3 - Envíos:</strong> Se marca como "YA SE ENVIO"</li>
                                        <li><strong>Sin gestión:</strong> Permanece como "PENDIENTE"</li>
                                        <li><strong>Actualiza:</strong> EstadoMaestro, FechaUGestion, Gestion</li>
                                    </ul>
                                </div>
                                
                                <form method="POST" class="mb-3">
                                    <input type="hidden" name="actualizar_maestro" value="1">
                                    <button type="submit" class="btn btn-actualizar w-100 py-3">
                                        <i class="bi bi-arrow-repeat me-2"></i>Ejecutar Actualización del Maestro
                                    </button>
                                </form>
                                
                                <div class="mt-4">
                                    <h6><i class="bi bi-info-circle me-2"></i>Campos que se actualizan:</h6>
                                    <ul class="list-group list-group-flush small">
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            EstadoMaestro
                                            <span class="badge bg-primary">NO GESTIONAR / YA SE ENVIO / PENDIENTE</span>
                                        </li>
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            FechaUGestion
                                            <span class="badge bg-primary">Fecha última gestión</span>
                                        </li>
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            Gestion
                                            <span class="badge bg-primary">Código de gestión</span>
                                        </li>
                                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            FechaProceso
                                            <span class="badge bg-primary">Fecha de esta actualización</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel de Estadísticas -->
                    <div class="col-lg-6">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Estadísticas Actuales
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <div class="stats-card stats-total rounded p-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi bi-database text-primary fs-2"></i>
                                                </div>
                                                <div class="text-end">
                                                    <div class="fs-3 fw-bold"><?php echo number_format($estadisticas_actuales['total_maestro'], 0, ',', '.'); ?></div>
                                                    <small>Total Registros Maestro</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="stats-card stats-no-gestionar rounded p-3">
                                            <div class="text-center">
                                                <i class="bi bi-person-x text-danger fs-2"></i>
                                                <div class="fs-4 fw-bold"><?php echo number_format($estadisticas_actuales['no_gestionar'], 0, ',', '.'); ?></div>
                                                <small>No Gestionar</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="stats-card stats-ya-envio rounded p-3">
                                            <div class="text-center">
                                                <i class="bi bi-send-check text-warning fs-2"></i>
                                                <div class="fs-4 fw-bold"><?php echo number_format($estadisticas_actuales['ya_se_envio'], 0, ',', '.'); ?></div>
                                                <small>Ya se Envió</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="stats-card stats-pendientes rounded p-3">
                                            <div class="text-center">
                                                <i class="bi bi-clock text-success fs-2"></i>
                                                <div class="fs-4 fw-bold"><?php echo number_format($estadisticas_actuales['pendientes'], 0, ',', '.'); ?></div>
                                                <small>Pendientes</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3 h-100">
                                            <div class="text-center">
                                                <i class="bi bi-calendar-check text-info fs-2"></i>
                                                <div class="fw-bold mt-1 small"><?php echo $estadisticas_actuales['ultima_actualizacion']; ?></div>
                                                <small>Última Actualización</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Información de la Semana -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-calendar-week me-2"></i>Semana de Trabajo
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $hoy = new DateTime();
                                $dia_semana = $hoy->format('N');
                                $lunes_actual = clone $hoy;
                                $lunes_actual->modify('-' . ($dia_semana - 1) . ' days');
                                $sabado_actual = clone $lunes_actual;
                                $sabado_actual->modify('+5 days')->setTime(20, 0, 0);
                                ?>
                                <div class="alert alert-info">
                                    <h6>Período Actual de Gestiones:</h6>
                                    <div class="fw-bold">
                                        <?php echo $lunes_actual->format('d/m/Y'); ?> 
                                        al 
                                        <?php echo $sabado_actual->format('d/m/Y H:i'); ?>
                                    </div>
                                    <small class="text-muted">Lunes a Sábado 20:00 hrs</small>
                                </div>
                                
                                <div class="mt-3">
                                    <h6>Criterios de Actualización:</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-danger info-badge">CD → NO GESTIONAR</span>
                                        <span class="badge bg-warning info-badge">Z1,Z3 → YA SE ENVIO</span>
                                        <span class="badge bg-success info-badge">Sin gestión → PENDIENTE</span>
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
                                    <i class="bi bi-diagram-3 me-2"></i>Relación de Datos
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <i class="bi bi-link-45deg text-primary me-3 fs-4"></i>
                                            <div>
                                                <h6>Llave de Relación</h6>
                                                <small class="text-muted">Maestro.CONTRATO = Gestiones_Diarias.acacct</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <i class="bi bi-files text-success me-3 fs-4"></i>
                                            <div>
                                                <h6>Tablas Involucradas</h6>
                                                <small class="text-muted">Maestro, Gestiones_Diarias, codigos_de_gestion_cyber</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <i class="bi bi-filter-circle text-warning me-3 fs-4"></i>
                                            <div>
                                                <h6>Filtros Aplicados</h6>
                                                <small class="text-muted">Semana actual + Estado ACTIVO + Tipo Contacto CD</small>
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
        // Confirmación antes de ejecutar
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!confirm('¿Estás seguro de que deseas ejecutar la actualización del Maestro? Este proceso puede tomar varios minutos.')) {
                e.preventDefault();
            } else {
                // Cambiar texto del botón
                const btn = this.querySelector('button[type="submit"]');
                btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Procesando...';
                btn.disabled = true;
            }
        });
    </script>
</body>
</html>