<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

$db = new Database();
$db->connect();

$mensaje = '';
$mensaje_tipo = '';

// Procesar ejecución de ETL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    $usuario = $_SESSION['username'];
    
    try {
        switch($accion) {
            case 'ejecutar_etl_direcciones':
                $resultado = ejecutarJobSSIS('Ejecutar_ETL_Direccion_Inhibiciones', $db);
                if ($resultado['estado'] === 'ÉXITO') {
                    $mensaje = "✅ ETL de Direcciones ejecutado correctamente";
                    $mensaje_tipo = 'success';
                } else {
                    throw new Exception("Error en ETL Direcciones: " . $resultado['mensaje']);
                }
                break;
                
            case 'ejecutar_etl_rut':
                $resultado = ejecutarJobSSIS('Ejecutar_ETL_Inhibicion_Rut', $db);
                if ($resultado['estado'] === 'ÉXITO') {
                    $mensaje = "✅ ETL de RUT ejecutado correctamente";
                    $mensaje_tipo = 'success';
                } else {
                    throw new Exception("Error en ETL RUT: " . $resultado['mensaje']);
                }
                break;
                
            case 'ejecutar_etl_correos':
                $resultado = ejecutarJobSSIS('Ejecutar_ETL_Inhibir_Correo', $db);
                if ($resultado['estado'] === 'ÉXITO') {
                    $mensaje = "✅ ETL de Correos ejecutado correctamente";
                    $mensaje_tipo = 'success';
                } else {
                    throw new Exception("Error en ETL Correos: " . $resultado['mensaje']);
                }
                break;
                
            // En el switch case, cambia esta parte:
            case 'ejecutar_etl_telefonos':
                $resultado = ejecutarJobSSIS('Ejecutar_ETL_Telefono_Inhibicion', $db);
                if ($resultado['estado'] === 'ÉXITO') {
                    $mensaje = "✅ ETL de Teléfonos ejecutado correctamente";
                    $mensaje_tipo = 'success';
                } else {
                    // Cambiamos esto para que no falle si está en ejecución
                    if ($resultado['estado'] === 'EJECUTANDO' || $resultado['estado'] === 'TIEMPO_EXCEDIDO') {
                        $mensaje = "⚠️ ETL de Teléfonos iniciado (verificando estado...)";
                        $mensaje_tipo = 'warning';
                    } else {
                        throw new Exception("Error en ETL Teléfonos: " . $resultado['mensaje']);
                    }
                }
                break;
                
            case 'ejecutar_sp_direcciones':
                $resultado = ejecutarStoredProcedureOutput('sp_inhibir_direcciones', $usuario, $db);
                $mensaje = "✅ " . $resultado['mensaje'];
                $mensaje_tipo = 'success';
                break;
                
            case 'ejecutar_sp_rut':
                $resultado = ejecutarStoredProcedure('sp_inhibir_rut', $usuario, $db);
                $mensaje = "✅ " . $resultado['mensaje'] . " - " . $resultado['registros_afectados'] . " registros afectados";
                $mensaje_tipo = 'success';
                break;
                
            case 'ejecutar_sp_correos':
                $resultado = ejecutarStoredProcedure('sp_inhibir_correos', $usuario, $db);
                $mensaje = "✅ " . $resultado['mensaje'] . " - " . $resultado['registros_afectados'] . " registros afectados";
                $mensaje_tipo = 'success';
                break;
                
            case 'ejecutar_sp_telefonos':
                $resultado = ejecutarStoredProcedure('sp_inhibir_telefonos', $usuario, $db);
                $mensaje = "✅ " . $resultado['mensaje'] . " - " . $resultado['registros_afectados'] . " registros afectados";
                $mensaje_tipo = 'success';
                break;
                
            default:
                throw new Exception("Acción no válida");
        }
        
        // Registrar en log
        guardarLogInhibicion($accion, $usuario, $resultado['registros_afectados'] ?? 0, 'COMPLETADO', $db);
        
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $mensaje_tipo = 'danger';
        guardarLogInhibicion($accion, $usuario, 0, 'ERROR', $db);
    }
}
// Función de debug temporal
function debugSP($spName, $usuario, $db) {
    echo "<div class='alert alert-warning mt-3'>";
    echo "<h6>Debug SP: $spName</h6>";
    
    try {
        $sql = "EXEC $spName @usuario = ?";
        $params = array($usuario);
        $result = $db->secure_query($sql, $params);
        
        echo "<p>Tipo de resultado: " . gettype($result) . "</p>";
        
        if (is_resource($result)) {
            echo "<p>Es un resource</p>";
            $rows = [];
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            echo "<pre>" . print_r($rows, true) . "</pre>";
        } else if (is_array($result)) {
            echo "<p>Es un array</p>";
            echo "<pre>" . print_r($result, true) . "</pre>";
        } else {
            echo "<p>Tipo desconocido: " . gettype($result) . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p>Error: " . $e->getMessage() . "</p>";
    }
    
    echo "</div>";
}

// Usar temporalmente para debug
// debugSP('sp_inhibir_direcciones', $_SESSION['username'], $db);

// Función mejorada para ejecutar Jobs de SSIS
// Función mejorada para ejecutar Jobs de SSIS
function ejecutarJobSSIS($nombreJob, $db) {
    // Iniciar el job
    $sql = "EXEC msdb.dbo.sp_start_job @job_name = ?";
    $params = array($nombreJob);
    $result = $db->secure_query($sql, $params);
    
    // Esperar y verificar estado con múltiples intentos
    $maxIntentos = 30;
    $intento = 0;
    
    while ($intento < $maxIntentos) {
        sleep(2);
        
        $sql = "SELECT 
            j.name as job_name,
            CASE 
                WHEN jh.run_status IS NULL THEN 'EJECUTANDO'
                WHEN jh.run_status = 1 THEN 'ÉXITO' 
                WHEN jh.run_status = 0 THEN 'FALLIDO'
                WHEN jh.run_status = 2 THEN 'REINTENTO'
                WHEN jh.run_status = 3 THEN 'CANCELADO'
                ELSE 'DESCONOCIDO'
            END as estado,
            ISNULL(jh.message, 'Completado') as mensaje
        FROM msdb.dbo.sysjobs j
        LEFT JOIN (
            SELECT job_id, run_status, message,
                ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY run_date DESC, instance_id DESC) as rn
            FROM msdb.dbo.sysjobhistory 
            WHERE step_name = '(Resultado del trabajo)'
        ) jh ON j.job_id = jh.job_id AND jh.rn = 1
        WHERE j.name = ?";
        
        $result = $db->secure_query($sql, array($nombreJob));
        
        // Convertir el resultado a array si es un resource
        $jobInfo = [];
        if (is_resource($result)) {
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $jobInfo[] = $row;
            }
        } else if (is_array($result)) {
            $jobInfo = $result;
        }
        
        if (!empty($jobInfo) && $jobInfo[0]['estado'] !== 'EJECUTANDO') {
            return [
                'estado' => $jobInfo[0]['estado'],
                'mensaje' => $jobInfo[0]['mensaje']
            ];
        }
        
        $intento++;
    }
    
    return [
        'estado' => 'TIEMPO_EXCEDIDO', 
        'mensaje' => 'El job está tomando más tiempo del esperado'
    ];
}

// Función para ejecutar Stored Procedures
// Función mejorada para ejecutar Stored Procedures
function ejecutarStoredProcedure($spName, $usuario, $db) {
    try {
        $sql = "EXEC " . $spName . " @usuario = ?";
        $params = array($usuario);
        
        // Ejecutar el SP
        $result = $db->secure_query($sql, $params);
        
        // Procesar el resultado dependiendo del tipo de retorno
        $output = [];
        
        if (is_resource($result)) {
            // Si es un resource, convertirlo a array
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $output[] = $row;
            }
        } else if (is_array($result)) {
            // Si ya es un array, usarlo directamente
            $output = $result;
        }
        
        if (!empty($output)) {
            return [
                'status' => $output[0]['status'] ?? 'SUCCESS',
                'mensaje' => $output[0]['mensaje'] ?? 'Ejecutado correctamente',
                'registros_afectados' => $output[0]['registros_afectados'] ?? 0
            ];
        } else {
            // Si no hay output, intentar obtener el número de filas afectadas
            $sql = "SELECT @@ROWCOUNT as registros_afectados";
            $rowCount = $db->secure_query($sql, array(), true);
            
            return [
                'status' => 'SUCCESS',
                'mensaje' => 'SP ejecutado pero no retornó datos específicos',
                'registros_afectados' => $rowCount[0]['registros_afectados'] ?? 0
            ];
        }
        
    } catch (Exception $e) {
        return [
            'status' => 'ERROR',
            'mensaje' => 'Error ejecutando SP: ' . $e->getMessage(),
            'registros_afectados' => 0
        ];
    }
}
// Función alternativa para ejecutar SPs con OUTPUT parameters
function ejecutarStoredProcedureOutput($spName, $usuario, $db) {
    try {
        // Usar OUTPUT parameters
        $sql = "
            DECLARE @status VARCHAR(20);
            DECLARE @mensaje VARCHAR(500);
            DECLARE @registros_afectados INT;
            
            EXEC $spName 
                @usuario = ?, 
                @status = @status OUTPUT, 
                @mensaje = @mensaje OUTPUT, 
                @registros_afectados = @registros_afectados OUTPUT;
                
            SELECT 
                @status as status, 
                @mensaje as mensaje, 
                @registros_afectados as registros_afectados;
        ";
        
        $params = array($usuario);
        $result = $db->secure_query($sql, $params);
        
        // Convertir resultado a array
        $output = [];
        if (is_resource($result)) {
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $output[] = $row;
            }
        } else if (is_array($result)) {
            $output = $result;
        }
        
        if (!empty($output)) {
            return [
                'status' => $output[0]['status'] ?? 'SUCCESS',
                'mensaje' => $output[0]['mensaje'] ?? 'Ejecutado correctamente',
                'registros_afectados' => $output[0]['registros_afectados'] ?? 0
            ];
        } else {
            return [
                'status' => 'ERROR',
                'mensaje' => 'No se pudo obtener resultado del SP',
                'registros_afectados' => 0
            ];
        }
        
    } catch (Exception $e) {
        return [
            'status' => 'ERROR',
            'mensaje' => 'Error ejecutando SP: ' . $e->getMessage(),
            'registros_afectados' => 0
        ];
    }
}

// Función para guardar logs
function guardarLogInhibicion($accion, $usuario, $registros_afectados, $estado, $db) {
    $sql = "INSERT INTO Logs_Inhibiciones (accion, usuario, registros_afectados, estado, fecha_ejecucion) 
            VALUES (?, ?, ?, ?, GETDATE())";
    $params = array($accion, $usuario, $registros_afectados, $estado);
    $db->secure_query($sql, $params);
}


// Obtener estadísticas para dashboard
function obtenerEstadisticasInhibiciones($db) {
    $sql = "SELECT 
        tipo_inhibicion,
        COUNT(*) as total,
        COUNT(DISTINCT rut) as ruts_unicos,
        MAX(fecha_procesamiento) as ultima_ejecucion
    FROM estadisticas_inhibiciones 
    WHERE fecha_procesamiento >= DATEADD(DAY, -30, GETDATE())
    GROUP BY tipo_inhibicion";
    
    return $db->secure_query($sql, array(), true);
}

$estadisticas = obtenerEstadisticasInhibiciones($db);
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Inhibiciones - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .process-card {
            border: 1px solid #444;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
        }
        .process-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .etl-section {
            border-left: 4px solid #0dcaf0;
            padding-left: 15px;
        }
        .sp-section {
            border-left: 4px solid #20c997;
            padding-left: 15px;
        }
        .stats-card {
            transition: transform 0.2s ease;
        }
        .stats-card:hover {
            transform: scale(1.02);
        }
        .btn-etl {
            background: linear-gradient(45deg, #0dcaf0, #0d6efd);
            border: none;
        }
        .btn-sp {
            background: linear-gradient(45deg, #20c997, #198754);
            border: none;
        }
        .progress-thin {
            height: 6px;
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
                            <a class="nav-link active" href="inhibiciones.php">
                                <i class="bi bi-shield-lock me-2"></i>Gestión Inhibiciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="consolidar_maestro.php">
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
                        <i class="bi bi-shield-lock me-2"></i>Gestión de Inhibiciones
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

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4 id="totalInhibiciones">0</h4>
                                        <span>Total Inhibiciones</span>
                                    </div>
                                    <i class="bi bi-shield-check display-6 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4 id="rutsUnicos">0</h4>
                                        <span>RUTs Únicos</span>
                                    </div>
                                    <i class="bi bi-person-badge display-6 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card bg-warning text-dark">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4 id="esteMes">0</h4>
                                        <span>Este Mes</span>
                                    </div>
                                    <i class="bi bi-calendar-month display-6 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4 id="hoy">0</h4>
                                        <span>Hoy</span>
                                    </div>
                                    <i class="bi bi-arrow-clockwise display-6 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Panel de Ejecución ETL -->
                    <div class="col-lg-6">
                        <div class="card process-card">
                            <div class="card-header etl-section">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-gear-fill me-2"></i>Ejecución ETL - Carga de Datos
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">Ejecuta los procesos ETL para cargar datos de inhibiciones desde las fuentes externas</p>
                                
                                <!-- Inhibir Direcciones -->
                                <div class="d-grid gap-2 mb-3">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_etl_direcciones">
                                        <button type="submit" class="btn btn-etl text-white w-100 text-start">
                                            <i class="bi bi-house-door me-2"></i>Inhibir Direcciones
                                            <small class="float-end">ETL: direccion_inhibiciones</small>
                                        </button>
                                    </form>
                                </div>

                                <!-- Inhibir RUT -->
                                <div class="d-grid gap-2 mb-3">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_etl_rut">
                                        <button type="submit" class="btn btn-etl text-white w-100 text-start">
                                            <i class="bi bi-person-badge me-2"></i>Inhibir RUT
                                            <small class="float-end">ETL: Inhibicion_rut</small>
                                        </button>
                                    </form>
                                </div>

                                <!-- Inhibir Correos -->
                                <div class="d-grid gap-2 mb-3">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_etl_correos">
                                        <button type="submit" class="btn btn-etl text-white w-100 text-start">
                                            <i class="bi bi-envelope me-2"></i>Inhibir Correos
                                            <small class="float-end">ETL: Inhibir_correo</small>
                                        </button>
                                    </form>
                                </div>

                                <!-- Inhibir Teléfonos -->
                                <div class="d-grid gap-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_etl_telefonos">
                                        <button type="submit" class="btn btn-etl text-white w-100 text-start">
                                            <i class="bi bi-telephone me-2"></i>Inhibir Teléfonos
                                            <small class="float-end">ETL: telefono_inhibicion</small>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel de Procesamiento SP -->
                    <div class="col-lg-6">
                        <div class="card process-card">
                            <div class="card-header sp-section">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-database-fill me-2"></i>Procesamiento - Aplicar Inhibiciones
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">Ejecuta los stored procedures para aplicar las inhibiciones al maestro</p>
                                
                                <!-- SP Direcciones -->
                                <div class="d-grid gap-2 mb-3">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_sp_direcciones">
                                        <button type="submit" class="btn btn-sp text-white w-100 text-start">
                                            <i class="bi bi-house-door me-2"></i>Aplicar Inhibición Direcciones
                                            <small class="float-end">SP: sp_inhibir_direcciones</small>
                                        </button>
                                    </form>
                                </div>

                                <!-- SP RUT -->
                                <div class="d-grid gap-2 mb-3">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_sp_rut">
                                        <button type="submit" class="btn btn-sp text-white w-100 text-start">
                                            <i class="bi bi-person-badge me-2"></i>Aplicar Inhibición RUT
                                            <small class="float-end">SP: sp_inhibir_rut</small>
                                        </button>
                                    </form>
                                </div>

                                <!-- SP Correos -->
                                <div class="d-grid gap-2 mb-3">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_sp_correos">
                                        <button type="submit" class="btn btn-sp text-white w-100 text-start">
                                            <i class="bi bi-envelope me-2"></i>Aplicar Inhibición Correos
                                            <small class="float-end">SP: sp_inhibir_correos</small>
                                        </button>
                                    </form>
                                </div>

                                <!-- SP Teléfonos -->
                                <div class="d-grid gap-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_sp_telefonos">
                                        <button type="submit" class="btn btn-sp text-white w-100 text-start">
                                            <i class="bi bi-telephone me-2"></i>Aplicar Inhibición Teléfonos
                                            <small class="float-end">SP: sp_inhibir_telefonos</small>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detalles de Estadísticas -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Estadísticas Detalladas por Tipo
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row" id="estadisticasDetalladas">
                                    <!-- Las estadísticas se cargarán aquí via JavaScript -->
                                    <div class="col-12 text-center py-3">
                                        <div class="spinner-border" role="status"></div>
                                        <p class="mt-2 text-muted">Cargando estadísticas...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Últimas Ejecuciones -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history me-2"></i>Últimas Ejecuciones
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Acción</th>
                                                <th>Usuario</th>
                                                <th>Registros</th>
                                                <th>Estado</th>
                                                <th>Fecha</th>
                                            </tr>
                                        </thead>
                                        <tbody id="ultimasEjecuciones">
                                            <tr>
                                                <td colspan="5" class="text-center py-3">
                                                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                                    Cargando...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
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
        // Función para cargar estadísticas
        // Función para cargar estadísticas - MEJORADA CON DEBUG
async function cargarEstadisticas() {
    try {
        console.log('Cargando estadísticas...');
        const response = await fetch('obtener_estadisticas_inhibiciones.php');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('Datos recibidos:', data);
        
        if (data.success) {
            // Actualizar cards principales
            document.getElementById('totalInhibiciones').textContent = 
                data.totales.total_inhibiciones?.toLocaleString() || '0';
            document.getElementById('rutsUnicos').textContent = 
                data.totales.ruts_unicos?.toLocaleString() || '0';
            document.getElementById('esteMes').textContent = 
                data.totales.este_mes?.toLocaleString() || '0';
            document.getElementById('hoy').textContent = 
                data.totales.hoy?.toLocaleString() || '0';
            
            // Actualizar estadísticas detalladas
            const container = document.getElementById('estadisticasDetalladas');
            
            if (data.detalladas && data.detalladas.length > 0) {
                container.innerHTML = '';
                data.detalladas.forEach(estadistica => {
                    const col = document.createElement('div');
                    col.className = 'col-md-3 mb-3';
                    col.innerHTML = `
                        <div class="border rounded p-3 stats-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="mb-1">${estadistica.total?.toLocaleString() || '0'}</h5>
                                    <small class="text-muted">${estadistica.tipo_inhibicion || 'N/A'}</small>
                                </div>
                                <span class="badge bg-secondary">${estadistica.ruts_unicos?.toLocaleString() || '0'} RUTs</span>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">Última: ${estadistica.ultima_ejecucion || 'N/A'}</small>
                            </div>
                        </div>
                    `;
                    container.appendChild(col);
                });
            } else {
                container.innerHTML = `
                    <div class="col-12 text-center py-3">
                        <i class="bi bi-database-x display-4 text-muted"></i>
                        <p class="text-muted mt-2">No hay estadísticas disponibles</p>
                    </div>
                `;
            }
        } else {
            console.error('Error en respuesta:', data.error);
            mostrarErrorEstadisticas('Error: ' + (data.error || 'Desconocido'));
        }
    } catch (error) {
        console.error('Error cargando estadísticas:', error);
        mostrarErrorEstadisticas('Error de conexión: ' + error.message);
    }
}

// Función para cargar últimas ejecuciones - MEJORADA
        async function cargarUltimasEjecuciones() {
            try {
                console.log('Cargando últimas ejecuciones...');
                const response = await fetch('obtener_ultimas_ejecuciones.php');
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Ejecuciones recibidas:', data);
                
                const tbody = document.getElementById('ultimasEjecuciones');
                
                if (data.success && data.ejecuciones && data.ejecuciones.length > 0) {
                    tbody.innerHTML = '';
                    data.ejecuciones.forEach(ejecucion => {
                        const tr = document.createElement('tr');
                        
                        // Determinar color del badge según el estado
                        let badgeClass = 'bg-secondary';
                        if (ejecucion.estado === 'COMPLETADO') badgeClass = 'bg-success';
                        else if (ejecucion.estado === 'ERROR') badgeClass = 'bg-danger';
                        
                        tr.innerHTML = `
                            <td>
                                <span class="badge bg-primary">${ejecucion.accion || 'N/A'}</span>
                            </td>
                            <td>${ejecucion.usuario || 'N/A'}</td>
                            <td>
                                <span class="badge ${ejecucion.registros_afectados > 0 ? 'bg-success' : 'bg-secondary'}">
                                    ${ejecucion.registros_afectados || 0}
                                </span>
                            </td>
                            <td>
                                <span class="badge ${badgeClass}">
                                    ${ejecucion.estado || 'DESCONOCIDO'}
                                </span>
                            </td>
                            <td>
                                <small class="text-muted">${ejecucion.fecha_ejecucion || 'N/A'}</small>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="5" class="text-center py-4">
                                <i class="bi bi-clock-history display-4 text-muted"></i>
                                <p class="text-muted mt-2">No hay ejecuciones registradas</p>
                            </td>
                        </tr>
                    `;
                }
            } catch (error) {
                console.error('Error cargando ejecuciones:', error);
                document.getElementById('ultimasEjecuciones').innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center py-3 text-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            Error cargando datos: ${error.message}
                        </td>
                    </tr>
                `;
            }
        }

        function mostrarErrorEstadisticas(mensaje) {
            document.getElementById('totalInhibiciones').textContent = '0';
            document.getElementById('rutsUnicos').textContent = '0';
            document.getElementById('esteMes').textContent = '0';
            document.getElementById('hoy').textContent = '0';
            
            const container = document.getElementById('estadisticasDetalladas');
            container.innerHTML = `
                <div class="col-12 text-center py-3">
                    <i class="bi bi-exclamation-triangle display-4 text-danger"></i>
                    <p class="text-danger mt-2">${mensaje}</p>
                </div>
            `;
        }

        // Función para cargar últimas ejecuciones
        async function cargarUltimasEjecuciones() {
            try {
                const response = await fetch('obtener_ultimas_ejecuciones.php');
                const data = await response.json();
                
                const tbody = document.getElementById('ultimasEjecuciones');
                
                if (data.success && data.ejecuciones.length > 0) {
                    tbody.innerHTML = '';
                    data.ejecuciones.forEach(ejecucion => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>
                                <span class="badge bg-primary">${ejecucion.accion}</span>
                            </td>
                            <td>${ejecucion.usuario}</td>
                            <td>
                                <span class="badge bg-${ejecucion.registros_afectados > 0 ? 'success' : 'secondary'}">
                                    ${ejecucion.registros_afectados}
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-${ejecucion.estado === 'COMPLETADO' ? 'success' : 'danger'}">
                                    ${ejecucion.estado}
                                </span>
                            </td>
                            <td>
                                <small class="text-muted">${ejecucion.fecha_ejecucion}</small>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">No hay ejecuciones recientes</td></tr>';
                }
            } catch (error) {
                console.error('Error cargando ejecuciones:', error);
                document.getElementById('ultimasEjecuciones').innerHTML = 
                    '<tr><td colspan="5" class="text-center py-3 text-danger">Error cargando datos</td></tr>';
            }
        }

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            cargarEstadisticas();
            cargarUltimasEjecuciones();
            
            // Actualizar cada 30 segundos
            setInterval(() => {
                cargarEstadisticas();
                cargarUltimasEjecuciones();
            }, 30000);
            
            // Manejar envío de formularios con confirmación
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const button = this.querySelector('button[type="submit"]');
                    const originalText = button.innerHTML;
                    
                    button.disabled = true;
                    button.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Procesando...';
                    
                    // Restaurar después de 10 segundos por si hay error
                    setTimeout(() => {
                        button.disabled = false;
                        button.innerHTML = originalText;
                    }, 10000);
                });
            });
        });
    </script>
</body>
</html>