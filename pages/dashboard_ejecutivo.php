<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
$auth = new Auth();
$auth->require_auth();

// Obtener estadísticas para el dashboard
$estadisticas = [
    'total_gestiones' => 0,
    'gestiones_hoy' => 0,
    'gestiones_semana' => 0,
    'usuarios_activos' => 0,
    'mejor_ejecutivo' => ['nombre_completo' => 'N/A', 'total_gestiones' => 0],
    'gestiones_por_usuario' => [],
    'gestiones_por_tipo' => [],
    'contactos_por_tipo' => [],
    'resultados_efectivos' => [],
    'metricas_contacto' => []
];

try {
    $database = new Database();
    $conn = $database->connect();
    
    if ($conn !== false) {
        // Total de gestiones
        $sql_total = "SELECT COUNT(*) as total FROM [dbo].[Gestiones_Diarias]";
        $stmt_total = $database->secure_query($sql_total);
        if ($stmt_total !== false) {
            $row = sqlsrv_fetch_array($stmt_total, SQLSRV_FETCH_ASSOC);
            $estadisticas['total_gestiones'] = $row['total'] ?? 0;
            sqlsrv_free_stmt($stmt_total);
        }
        
        // Gestiones de hoy
        $sql_hoy = "SELECT COUNT(*) as total FROM [dbo].[Gestiones_Diarias] WHERE CAST(fecha_carga AS DATE) = CAST(GETDATE() AS DATE)";
        $stmt_hoy = $database->secure_query($sql_hoy);
        if ($stmt_hoy !== false) {
            $row = sqlsrv_fetch_array($stmt_hoy, SQLSRV_FETCH_ASSOC);
            $estadisticas['gestiones_hoy'] = $row['total'] ?? 0;
            sqlsrv_free_stmt($stmt_hoy);
        }
        
        // Gestiones de la semana
        $sql_semana = "SELECT COUNT(*) as total FROM [dbo].[Gestiones_Diarias] WHERE fecha_carga >= DATEADD(day, -7, GETDATE())";
        $stmt_semana = $database->secure_query($sql_semana);
        if ($stmt_semana !== false) {
            $row = sqlsrv_fetch_array($stmt_semana, SQLSRV_FETCH_ASSOC);
            $estadisticas['gestiones_semana'] = $row['total'] ?? 0;
            sqlsrv_free_stmt($stmt_semana);
        }
        
        // Usuarios activos
        $sql_usuarios = "SELECT COUNT(*) as total FROM [dbo].[usuarios] WHERE activo = 1";
        $stmt_usuarios = $database->secure_query($sql_usuarios);
        if ($stmt_usuarios !== false) {
            $row = sqlsrv_fetch_array($stmt_usuarios, SQLSRV_FETCH_ASSOC);
            $estadisticas['usuarios_activos'] = $row['total'] ?? 0;
            sqlsrv_free_stmt($stmt_usuarios);
        }
        
        // Gestiones por usuario (top 5)
        $sql_por_usuario = "
            SELECT TOP 5 
                ISNULL(ug.nombre_completo, g.accidnam) as nombre_completo,
                COUNT(*) as total_gestiones
            FROM [dbo].[Gestiones_Diarias] g
            LEFT JOIN [dbo].[Usuario_Gestor] ug ON g.accidnam = ug.codigo_gestion
            WHERE g.accidnam IS NOT NULL AND g.accidnam NOT IN ('exmabdis', 'exmabrem')
            GROUP BY ISNULL(ug.nombre_completo, g.accidnam)
            ORDER BY total_gestiones DESC
        ";
        
        $stmt_por_usuario = $database->secure_query($sql_por_usuario);
        if ($stmt_por_usuario !== false) {
            while ($row = sqlsrv_fetch_array($stmt_por_usuario, SQLSRV_FETCH_ASSOC)) {
                $estadisticas['gestiones_por_usuario'][] = $row;
            }
            sqlsrv_free_stmt($stmt_por_usuario);
        }
        
        // Mejor ejecutivo
        if (!empty($estadisticas['gestiones_por_usuario'])) {
            $estadisticas['mejor_ejecutivo'] = $estadisticas['gestiones_por_usuario'][0];
        }
        
        // Gestiones por tipo (acaccode)
        $sql_por_tipo = "
            SELECT TOP 6 
                acaccode as tipo_gestion,
                COUNT(*) as total
            FROM [dbo].[Gestiones_Diarias] 
            WHERE acaccode IS NOT NULL
            GROUP BY acaccode
            ORDER BY total DESC
        ";
        
        $stmt_por_tipo = $database->secure_query($sql_por_tipo);
        if ($stmt_por_tipo !== false) {
            while ($row = sqlsrv_fetch_array($stmt_por_tipo, SQLSRV_FETCH_ASSOC)) {
                $estadisticas['gestiones_por_tipo'][] = $row;
            }
            sqlsrv_free_stmt($stmt_por_tipo);
        }
        
        // ANÁLISIS CUALITATIVO - Tipos de Contacto usando la tabla de códigos
        $sql_contactos = "
            SELECT 
                c.[TIPO CONTACTO] as tipo_contacto,
                COUNT(*) as total,
                CASE 
                    WHEN c.[TIPO CONTACTO] = 'CD' THEN 'Contacto Directo'
                    WHEN c.[TIPO CONTACTO] = 'CI' THEN 'Contacto Indirecto' 
                    WHEN c.[TIPO CONTACTO] = 'SC' THEN 'Sin Contacto'
                    WHEN c.[TIPO CONTACTO] = 'REMOTO' THEN 'Remoto'
                    ELSE 'Otros'
                END as descripcion
            FROM [dbo].[Gestiones_Diarias] g
            INNER JOIN [dbo].[Codigos_de_Gestion_cyber] c ON g.acaccode = c.[CODIGO RESULTADO]
            WHERE c.[TIPO CONTACTO] IS NOT NULL
            GROUP BY c.[TIPO CONTACTO]
            ORDER BY total DESC
        ";
        
        $stmt_contactos = $database->secure_query($sql_contactos);
        if ($stmt_contactos !== false) {
            while ($row = sqlsrv_fetch_array($stmt_contactos, SQLSRV_FETCH_ASSOC)) {
                $estadisticas['contactos_por_tipo'][] = $row;
            }
            sqlsrv_free_stmt($stmt_contactos);
        }
        
        // ANÁLISIS CUALITATIVO - Resultados más efectivos con tipo de contacto
        $sql_resultados = "
            SELECT TOP 10 
                g.acaccode as codigo_resultado,
                c.[RESULTADO] as descripcion_resultado,
                COUNT(*) as total,
                SUM(CASE WHEN c.[TIPO CONTACTO] = 'CD' THEN 1 ELSE 0 END) as contactos_directos,
                SUM(CASE WHEN c.[TIPO CONTACTO] = 'CI' THEN 1 ELSE 0 END) as contactos_indirectos,
                SUM(CASE WHEN c.[TIPO CONTACTO] = 'SC' THEN 1 ELSE 0 END) as sin_contacto,
                SUM(CASE WHEN c.[TIPO CONTACTO] = 'REMOTO' THEN 1 ELSE 0 END) as remoto
            FROM [dbo].[Gestiones_Diarias] g
            INNER JOIN [dbo].[Codigos_de_Gestion_cyber] c ON g.acaccode = c.[CODIGO RESULTADO]
            WHERE g.acaccode IS NOT NULL AND g.acaccode != ''
            GROUP BY g.acaccode, c.[RESULTADO]
            HAVING COUNT(*) > 5
            ORDER BY total DESC
        ";
        
        $stmt_resultados = $database->secure_query($sql_resultados);
        if ($stmt_resultados !== false) {
            while ($row = sqlsrv_fetch_array($stmt_resultados, SQLSRV_FETCH_ASSOC)) {
                $row['porcentaje_contacto_directo'] = ($row['total'] > 0) ? ($row['contactos_directos'] / $row['total']) * 100 : 0;
                $row['porcentaje_contacto_efectivo'] = ($row['total'] > 0) ? (($row['contactos_directos'] + $row['contactos_indirectos']) / $row['total']) * 100 : 0;
                $estadisticas['resultados_efectivos'][] = $row;
            }
            sqlsrv_free_stmt($stmt_resultados);
        }
        
        // Métricas de Contacto Efectivo usando la tabla de códigos
        $sql_metricas_contacto = "
            SELECT 
                c.[TIPO CONTACTO],
                COUNT(*) as total
            FROM [dbo].[Gestiones_Diarias] g
            INNER JOIN [dbo].[Codigos_de_Gestion_cyber] c ON g.acaccode = c.[CODIGO RESULTADO]
            WHERE c.[TIPO CONTACTO] IS NOT NULL
            GROUP BY c.[TIPO CONTACTO]
        ";
        
        $stmt_metricas = $database->secure_query($sql_metricas_contacto);
        $contactos_directos = 0;
        $contactos_indirectos = 0;
        $sin_contacto = 0;
        $remoto = 0;
        $total_gestiones_con_tipo = 0;
        
        if ($stmt_metricas !== false) {
            while ($row = sqlsrv_fetch_array($stmt_metricas, SQLSRV_FETCH_ASSOC)) {
                $total_gestiones_con_tipo += $row['total'];
                switch ($row['TIPO CONTACTO']) {
                    case 'CD': $contactos_directos = $row['total']; break;
                    case 'CI': $contactos_indirectos = $row['total']; break;
                    case 'SC': $sin_contacto = $row['total']; break;
                    case 'REMOTO': $remoto = $row['total']; break;
                }
            }
            sqlsrv_free_stmt($stmt_metricas);
        }
        
        $total_gestiones_con_tipo = $total_gestiones_con_tipo > 0 ? $total_gestiones_con_tipo : 1;
        
        $estadisticas['metricas_contacto'] = [
            'contactos_directos' => $contactos_directos,
            'contactos_indirectos' => $contactos_indirectos,
            'sin_contacto' => $sin_contacto,
            'remoto' => $remoto,
            'total_con_tipo' => $total_gestiones_con_tipo,
            'porcentaje_directos' => ($contactos_directos / $total_gestiones_con_tipo) * 100,
            'porcentaje_indirectos' => ($contactos_indirectos / $total_gestiones_con_tipo) * 100,
            'porcentaje_sin_contacto' => ($sin_contacto / $total_gestiones_con_tipo) * 100,
            'porcentaje_remoto' => ($remoto / $total_gestiones_con_tipo) * 100,
            'tasa_contacto_efectivo' => (($contactos_directos + $contactos_indirectos) / $total_gestiones_con_tipo) * 100
        ];
        
    }
} catch (Exception $e) {
    // Error silencioso para estadísticas
    error_log("Error en dashboard: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor Documento - Dashboard Ejecutivo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .sidebar {
            background-color: #212529;
            min-height: 100vh;
        }
        .sidebar .nav-link {
            color: #adb5bd;
        }
        .sidebar .nav-link:hover {
            color: #fff;
            background-color: #495057;
        }
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }
        .stat-card {
            transition: all 0.3s ease;
            border-left: 4px solid;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0;
        }
        .stat-icon {
            font-size: 3rem;
            opacity: 0.7;
        }
        .chart-container {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            height: 100%;
        }
        .progress {
            height: 25px;
            margin-bottom: 10px;
        }
        .ranking-item {
            border-left: 3px solid;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .mejor-ejecutivo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .metric-card {
            background: rgba(255,255,255,0.03);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .badge-contacto {
            font-size: 0.7rem;
            padding: 4px 8px;
        }
        .table-analitica {
            font-size: 0.85rem;
        }
        .table-analitica th {
            background: rgba(255,255,255,0.1);
        }
        .contacto-badge-CD { background-color: #28a745; }
        .contacto-badge-CI { background-color: #17a2b8; }
        .contacto-badge-SC { background-color: #ffc107; color: #000; }
        .contacto-badge-REMOTO { background-color: #6f42c1; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h5 class="text-white">Gestor Documento</h5>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="dashboard_ejecutivo.php">
                                <i class="bi bi-graph-up me-2"></i>
                                Dashboard Ejecutivo
                            </a>
                        </li>
                        <?php if ($_SESSION['perfil'] === 'administrador'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="usuarios/crud.php">
                                <i class="bi bi-people me-2"></i>
                                Gestión de Usuarios
                            </a>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="procesos/">
                                <i class="bi bi-gear me-2"></i>
                                Procesos
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-graph-up me-2"></i>Dashboard Ejecutivo - Análisis Avanzado
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-3">
                            <span class="navbar-text">
                                <i class="bi bi-person-circle me-1"></i>
                                <?php echo htmlspecialchars($_SESSION['nombre_completo']); ?>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($_SESSION['perfil']); ?></span>
                            </span>
                        </div>
                        <a href="../includes/logout.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                        </a>
                    </div>
                </div>

                <!-- Estadísticas Principales -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-primary">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <div class="text-primary font-weight-bold">Total Gestiones</div>
                                        <div class="stat-number text-primary"><?php echo number_format($estadisticas['total_gestiones'], 0, ',', '.'); ?></div>
                                        <small><?php echo number_format($estadisticas['metricas_contacto']['total_con_tipo'], 0, ',', '.'); ?> con tipo de contacto</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-chat-dots stat-icon text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-success">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <div class="text-success font-weight-bold">Contactos Directos</div>
                                        <div class="stat-number text-success"><?php echo number_format($estadisticas['metricas_contacto']['contactos_directos'], 0, ',', '.'); ?></div>
                                        <small><?php echo number_format($estadisticas['metricas_contacto']['porcentaje_directos'], 1); ?>% efectivos</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-telephone-outbound stat-icon text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-warning">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <div class="text-warning font-weight-bold">Tasa Contacto Efectivo</div>
                                        <div class="stat-number text-warning"><?php echo number_format($estadisticas['metricas_contacto']['tasa_contacto_efectivo'], 1); ?>%</div>
                                        <small>CD + CI / Total</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-check-circle stat-icon text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-info">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <div class="text-info font-weight-bold">Sin Contacto</div>
                                        <div class="stat-number text-info"><?php echo number_format($estadisticas['metricas_contacto']['sin_contacto'], 0, ',', '.'); ?></div>
                                        <small><?php echo number_format($estadisticas['metricas_contacto']['porcentaje_sin_contacto'], 1); ?>% del total</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="bi bi-telephone-x stat-icon text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Gráfico de Gestiones por Usuario -->
                    <div class="col-lg-8 mb-4">
                        <div class="chart-container">
                            <h5 class="mb-3">
                                <i class="bi bi-trophy me-2"></i>Top Ejecutivos - Gestiones Realizadas
                            </h5>
                            <?php if (!empty($estadisticas['gestiones_por_usuario'])): ?>
                                <canvas id="gestionesChart" height="250"></canvas>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-bar-chart display-4"></i>
                                    <p>No hay datos de ejecutivos disponibles</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Columna Derecha: Mejor Ejecutivo + Tipos de Contacto -->
                    <div class="col-lg-4 mb-4">
                        <!-- Mejor Ejecutivo -->
                        <div class="card mejor-ejecutivo text-white mb-4">
                            <div class="card-body text-center">
                                <i class="bi bi-trophy-fill display-4 mb-3"></i>
                                <h4>Mejor Ejecutivo</h4>
                                <h3 class="mb-2"><?php echo htmlspecialchars($estadisticas['mejor_ejecutivo']['nombre_completo']); ?></h3>
                                <div class="h1"><?php echo number_format($estadisticas['mejor_ejecutivo']['total_gestiones'], 0, ',', '.'); ?></div>
                                <p class="mb-0">gestiones realizadas</p>
                            </div>
                        </div>

                        <!-- Tipos de Contacto -->
                        <div class="chart-container">
                            <h5 class="mb-3">
                                <i class="bi bi-pie-chart me-2"></i>Distribución de Contactos
                            </h5>
                            <?php if (!empty($estadisticas['contactos_por_tipo'])): ?>
                                <canvas id="contactosChart" height="200"></canvas>
                            <?php else: ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-pie-chart display-4"></i>
                                    <p>No hay datos de contactos</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Análisis Cualitativo - Resultados más Comunes -->
                <div class="row mt-4">
                    <div class="col-lg-6 mb-4">
                        <div class="chart-container">
                            <h5 class="mb-3">
                                <i class="bi bi-bar-chart me-2"></i>Resultados Más Frecuentes
                            </h5>
                            <?php if (!empty($estadisticas['resultados_efectivos'])): ?>
                                <canvas id="resultadosChart" height="250"></canvas>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-bar-chart display-4"></i>
                                    <p>No hay datos de resultados</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="chart-container">
                            <h5 class="mb-3">
                                <i class="bi bi-table me-2"></i>Top 10 Códigos de Resultado
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-analitica table-hover">
                                    <thead>
                                        <tr>
                                            <th>Código</th>
                                            <th>Descripción</th>
                                            <th>Total</th>
                                            <th>% Contacto Directo</th>
                                            <th>Efectividad</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($estadisticas['resultados_efectivos'])): ?>
                                            <?php foreach ($estadisticas['resultados_efectivos'] as $resultado): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($resultado['codigo_resultado']); ?></strong></td>
                                                <td><small><?php echo htmlspecialchars($resultado['descripcion_resultado'] ?? 'N/A'); ?></small></td>
                                                <td><?php echo number_format($resultado['total'], 0, ',', '.'); ?></td>
                                                <td>
                                                    <div class="progress" style="height: 15px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $resultado['porcentaje_contacto_directo']; ?>%">
                                                            <?php echo number_format($resultado['porcentaje_contacto_directo'], 1); ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($resultado['porcentaje_contacto_efectivo'] > 70): ?>
                                                        <span class="badge bg-success">Alta</span>
                                                    <?php elseif ($resultado['porcentaje_contacto_efectivo'] > 40): ?>
                                                        <span class="badge bg-warning">Media</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Baja</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">No hay datos de resultados disponibles</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ranking de Ejecutivos -->
                <div class="row mt-2">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-ol me-2"></i>Ranking de Ejecutivos
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($estadisticas['gestiones_por_usuario'])): ?>
                                    <div class="row">
                                        <?php foreach ($estadisticas['gestiones_por_usuario'] as $index => $usuario): ?>
                                        <div class="col-md-6 col-lg-4 mb-3">
                                            <div class="ranking-item" style="border-left-color: <?php echo getRankingColor($index); ?>">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div>
                                                        <strong>#<?php echo $index + 1; ?></strong> 
                                                        <?php echo htmlspecialchars($usuario['nombre_completo']); ?>
                                                    </div>
                                                    <span class="badge bg-dark"><?php echo number_format($usuario['total_gestiones'], 0, ',', '.'); ?></span>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar" style="width: <?php echo calcularPorcentaje($usuario['total_gestiones'], $estadisticas['gestiones_por_usuario'][0]['total_gestiones']); ?>%; background-color: <?php echo getRankingColor($index); ?>"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">No hay datos de gestiones disponibles</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Acciones Rápidas -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-lightning me-2"></i>Acciones Rápidas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <a href="procesos/consulta_deudor.php" class="btn btn-outline-primary w-100">
                                            <i class="bi bi-search me-2"></i>Consulta Deudor
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="procesos/consolidar_maestro.php" class="btn btn-outline-success w-100">
                                            <i class="bi bi-database-check me-2"></i>Exportar Maestro
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="procesos/gestiones.php" class="btn btn-outline-warning w-100">
                                            <i class="bi bi-chat-dots me-2"></i>Ver Gestiones
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="procesos/archivos.php" class="btn btn-outline-info w-100">
                                            <i class="bi bi-files me-2"></i>Archivos Subidos
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Gráfico de Gestiones por Usuario
        <?php if (!empty($estadisticas['gestiones_por_usuario'])): ?>
        const gestionesChart = new Chart(
            document.getElementById('gestionesChart'),
            {
                type: 'bar',
                data: {
                    labels: [<?php echo implode(',', array_map(function($u) { return "'" . addslashes($u['nombre_completo']) . "'"; }, $estadisticas['gestiones_por_usuario'])); ?>],
                    datasets: [{
                        label: 'Gestiones Realizadas',
                        data: [<?php echo implode(',', array_column($estadisticas['gestiones_por_usuario'], 'total_gestiones')); ?>],
                        backgroundColor: [
                            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Gestiones por Ejecutivo'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#adb5bd'
                            },
                            grid: {
                                color: 'rgba(255,255,255,0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#adb5bd'
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            }
        );
        <?php endif; ?>

        // Gráfico de Tipos de Contacto
        <?php if (!empty($estadisticas['contactos_por_tipo'])): ?>
        const contactosChart = new Chart(
            document.getElementById('contactosChart'),
            {
                type: 'doughnut',
                data: {
                    labels: [<?php echo implode(',', array_map(function($c) { return "'" . addslashes($c['descripcion']) . "'"; }, $estadisticas['contactos_por_tipo'])); ?>],
                    datasets: [{
                        data: [<?php echo implode(',', array_column($estadisticas['contactos_por_tipo'], 'total')); ?>],
                        backgroundColor: [
                            '#28a745', '#17a2b8', '#ffc107', '#6f42c1', '#6c757d'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#adb5bd',
                                boxWidth: 12,
                                font: {
                                    size: 10
                                }
                            }
                        }
                    }
                }
            }
        );
        <?php endif; ?>

        // Gráfico de Resultados
        <?php if (!empty($estadisticas['resultados_efectivos'])): ?>
        const resultadosChart = new Chart(
            document.getElementById('resultadosChart'),
            {
                type: 'bar',
                data: {
                    labels: [<?php echo implode(',', array_map(function($r) { return "'" . addslashes($r['codigo_resultado']) . "'"; }, $estadisticas['resultados_efectivos'])); ?>],
                    datasets: [{
                        label: 'Total de Gestiones',
                        data: [<?php echo implode(',', array_column($estadisticas['resultados_efectivos'], 'total')); ?>],
                        backgroundColor: '#36A2EB',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                color: '#adb5bd'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#adb5bd'
                            },
                            grid: {
                                color: 'rgba(255,255,255,0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#adb5bd'
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            }
        );
        <?php endif; ?>
    </script>
</body>
</html>

<?php
// Función para colores del ranking
function getRankingColor($index) {
    $colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'];
    return $colors[$index] ?? '#C9CBCF';
}

// Función para calcular porcentaje
function calcularPorcentaje($valor, $maximo) {
    if ($maximo == 0) return 0;
    return ($valor / $maximo) * 100;
}