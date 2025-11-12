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

// Función para formatear valores para display
function formatValue($value) {
    if ($value === null || $value === '') {
        return 'N/A';
    }
    
    if ($value instanceof DateTime) {
        return $value->format('d/m/Y');
    }
    
    return htmlspecialchars(strval($value));
}

// Función para formatear números
function formatNumber($value, $decimals = 0) {
    if ($value === null || $value === '') {
        return 'N/A';
    }
    
    $number = floatval($value);
    return number_format($number, $decimals, ',', '.');
}

// Variables para la consulta
$mensaje = '';
$mensaje_tipo = '';
$deudor = null;
$opciones_pago = [];
$gestiones = [];

// Procesar búsqueda por RUT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rut'])) {
    $rut = trim($_POST['rut']);
    
    // Limpiar el RUT: quitar puntos, guión y espacios
    $rut_limpio = preg_replace('/[^0-9kK]/', '', $rut);
    
    if (empty($rut_limpio)) {
        $mensaje = "Error: Debe ingresar un RUT válido";
        $mensaje_tipo = 'danger';
    } else {
        try {
            $database = new Database();
            $conn = $database->connect();
            
            if ($conn === false) {
                $mensaje = "Error de conexión a la base de datos";
                $mensaje_tipo = 'danger';
            } else {
                // Consulta para obtener datos del deudor usando el RUT limpio
                $sql = "
                    SELECT 
                        rut, dv, contrato, nombre, paterno, materno,
                        saldo_en_campana, descuento, fecha_asignacion,
                        correo1, tipo_cartera, telefono1,
                        direccion, numeracion_dir, resto, region, comuna, ciudad
                    FROM [dbo].[Asignacion_Stock] 
                    WHERE rut = ? AND activo = 1
                ";
                
                $stmt = $database->secure_query($sql, [$rut_limpio]);
                
                if ($stmt !== false) {
                    // Obtener los datos
                    $deudor = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                    
                    if ($deudor) {
                        $mensaje = "Deudor encontrado exitosamente";
                        $mensaje_tipo = 'success';
                        
                        // Convertir objetos DateTime a string para evitar errores
                        foreach ($deudor as $key => $value) {
                            if ($value instanceof DateTime) {
                                $deudor[$key] = $value->format('Y-m-d');
                            }
                        }
                        
                        // Calcular opciones de pago
                        $saldo = floatval($deudor['saldo_en_campana']);
                        $descuento = floatval($deudor['descuento']);
                        
                        // Opción 1: Pago Contado con Descuento
                        $opciones_pago[1] = [
                            'nombre' => 'PAGO CONTADO CON DESCUENTO',
                            'descuento' => $descuento,
                            'monto_pagar' => $saldo - ($saldo * $descuento / 100)
                        ];
                        
                        // Opción 2: Pago con Descuento + Cuotas
                        $porcentaje_inicial = 30;
                        $monto_inicial = $saldo * ($porcentaje_inicial / 100);
                        $saldo_restante = $saldo - $monto_inicial;
                        $num_cuotas = ($saldo > 2000000) ? 6 : 3;
                        $monto_cuota = $saldo_restante / $num_cuotas;
                        
                        $opciones_pago[2] = [
                            'nombre' => 'PAGO EN CUOTAS CON DESCUENTO',
                            'porcentaje_inicial' => $porcentaje_inicial,
                            'monto_inicial' => $monto_inicial,
                            'num_cuotas' => $num_cuotas,
                            'monto_cuota' => $monto_cuota
                        ];
                        
                        // Opción 3: Pago sin Descuento
                        $cuota_inicial = 270000;
                        $saldo_deuda = $saldo - $cuota_inicial;
                        $num_cuotas_sin_desc = 18;
                        $monto_cuota_sin_desc = $saldo_deuda / $num_cuotas_sin_desc;
                        
                        $opciones_pago[3] = [
                            'nombre' => 'PAGO SIN DESCUENTO',
                            'cuota_inicial' => $cuota_inicial,
                            'saldo_deuda' => $saldo_deuda,
                            'num_cuotas' => $num_cuotas_sin_desc,
                            'monto_cuota' => $monto_cuota_sin_desc
                        ];
                        
                        // Consultar las gestiones del deudor por contrato
                        $sql_gestiones = "
                            SELECT 
                                id, code, acacct, acactdte, acseqnum, acaccode, 
                                acrccode, aclccode, accidnam, acphone, accomm,
                                fecha_carga, fecha_procesamiento, estado,
                                archivo_origen, usuario_carga
                            FROM [dbo].[Gestiones_Diarias] 
                            WHERE acacct = ?
                            ORDER BY fecha_procesamiento DESC, fecha_carga DESC
                        ";
                        
                        $stmt_gestiones = $database->secure_query($sql_gestiones, [$deudor['contrato']]);
                        
                        if ($stmt_gestiones !== false) {
                            while ($gestion = sqlsrv_fetch_array($stmt_gestiones, SQLSRV_FETCH_ASSOC)) {
                                // Convertir objetos DateTime
                                foreach ($gestion as $key => $value) {
                                    if ($value instanceof DateTime) {
                                        $gestion[$key] = $value->format('Y-m-d H:i:s');
                                    }
                                }
                                $gestiones[] = $gestion;
                            }
                            sqlsrv_free_stmt($stmt_gestiones);
                        }
                        
                    } else {
                        $mensaje = "No se encontró deudor con el RUT proporcionado";
                        $mensaje_tipo = 'warning';
                    }
                    
                    sqlsrv_free_stmt($stmt);
                } else {
                    $mensaje = "Error en la consulta a la base de datos";
                    $mensaje_tipo = 'danger';
                }
            }
            
        } catch (Exception $e) {
            $mensaje = "Error: " . $e->getMessage();
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
    <title>Consulta Deudor - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .search-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
        }
        .deudor-card {
            border-left: 4px solid #0d6efd;
        }
        .opcion-pago {
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .opcion-pago:hover {
            border-color: #0d6efd;
            transform: translateY(-2px);
        }
        .opcion-1 { border-left-color: #198754; }
        .opcion-2 { border-left-color: #ffc107; }
        .opcion-3 { border-left-color: #dc3545; }
        .monto-destacado {
            font-size: 1.5rem;
            font-weight: bold;
            color: #198754;
        }
        .badge-opcion {
            font-size: 0.8rem;
        }
        .rut-input {
            text-transform: uppercase;
        }
        .valor-nulo {
            color: #6c757d;
            font-style: italic;
        }
        .gestion-card {
            border-left: 4px solid #6f42c1;
        }
        .gestion-item {
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 1rem 0;
        }
        .gestion-item:last-child {
            border-bottom: none;
        }
        .badge-estado {
            font-size: 0.7rem;
        }
        .comentario-gestion {
            background: rgba(255,255,255,0.05);
            border-radius: 5px;
            padding: 0.75rem;
            margin-top: 0.5rem;
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
                            <a class="nav-link active" href="consulta_deudor.php">
                                <i class="bi bi-search me-2"></i>Consulta Deudor
                            </a>
                        </li>
                                                <li class="nav-item">
                            <a class="nav-link" href="index.php">
                                <i class="bi bi-gear me-2"></i>Procesos
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-search me-2"></i>Consulta Deudor
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

                <!-- Panel de Búsqueda -->
                <div class="card search-card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-person-badge me-2"></i>Buscar Deudor por RUT
                        </h5>
                        <form method="POST" class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label text-white">RUT del Deudor</label>
                                <input type="text" class="form-control rut-input" name="rut" 
                                       placeholder="Ingrese RUT (sin puntos ni guión, ej: 123456789)" 
                                       value="<?php echo $_POST['rut'] ?? ''; ?>" 
                                       pattern="[0-9kK]{7,9}" 
                                       title="Ingrese el RUT sin puntos ni guión (mínimo 7 dígitos)"
                                       required>
                                <small class="form-text text-white-50">Solo números y la letra K (ej: 12345678 o 12345678k)</small>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-light w-100">
                                    <i class="bi bi-search me-2"></i>Buscar Deudor
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($deudor): ?>
                <!-- Información del Deudor -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card deudor-card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-person-circle me-2"></i>Información del Deudor
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>RUT:</strong> <?php echo formatValue($deudor['rut']); ?>-<?php echo formatValue($deudor['dv']); ?><br>
                                        <strong>Nombre:</strong> <?php echo formatValue($deudor['nombre'] . ' ' . $deudor['paterno'] . ' ' . $deudor['materno']); ?><br>
                                        <strong>Contrato:</strong> <?php echo formatValue($deudor['contrato']); ?><br>
                                        <strong>Saldo:</strong> <span class="text-warning">$<?php echo formatNumber($deudor['saldo_en_campana']); ?></span>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Descuento Banco:</strong> 
                                        <?php if ($deudor['descuento']): ?>
                                            <?php echo formatNumber($deudor['descuento'], 2); ?>%
                                        <?php else: ?>
                                            <span class="valor-nulo">No asignado</span>
                                        <?php endif; ?>
                                        <br>
                                        <strong>Asignación:</strong> 
                                        <?php if ($deudor['fecha_asignacion']): ?>
                                            <?php echo formatValue($deudor['fecha_asignacion']); ?>
                                        <?php else: ?>
                                            <span class="valor-nulo">No asignada</span>
                                        <?php endif; ?>
                                        <br>
                                        <strong>Teléfono:</strong> <?php echo formatValue($deudor['telefono1']); ?><br>
                                        <strong>Correo:</strong> <?php echo formatValue($deudor['correo1']); ?>
                                    </div>
                                </div>
                                <?php if ($deudor['direccion']): ?>
                                <hr>
                                <strong>Dirección:</strong> 
                                <?php echo formatValue($deudor['direccion'] . ' ' . $deudor['numeracion_dir'] . ' ' . $deudor['resto']); ?><br>
                                <strong>Comuna:</strong> <?php echo formatValue($deudor['comuna']); ?> - 
                                <strong>Ciudad:</strong> <?php echo formatValue($deudor['ciudad']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card bg-primary text-white mb-4">
                            <div class="card-body text-center">
                                <h4 class="card-title">Saldo Actual</h4>
                                <div class="monto-destacado">
                                    $<?php echo formatNumber($deudor['saldo_en_campana']); ?>
                                </div>
                                <p class="mb-0">Disponible para negociación</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Opciones de Pago -->
                <div class="row">
                    <!-- Opción 1: Pago Contado -->
                    <div class="col-lg-4 mb-4">
                        <div class="card opcion-pago opcion-1 h-100">
                            <div class="card-header bg-success text-white">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-currency-dollar me-2"></i>OPCIÓN DE PAGO 1
                                </h5>
                            </div>
                            <div class="card-body">
                                <h6 class="card-subtitle mb-3 text-success">PAGO CONTADO CON DESCUENTO</h6>
                                
                                <div class="mb-3">
                                    <strong>Descuento:</strong>
                                    <span class="badge bg-success badge-opcion ms-2">
                                        <?php echo formatNumber($opciones_pago[1]['descuento'], 2); ?>%
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Monto a Pagar:</strong><br>
                                    <span class="text-success fw-bold fs-5">
                                        $<?php echo formatNumber($opciones_pago[1]['monto_pagar']); ?>
                                    </span>
                                </div>
                                
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Se ofrece descuento, ideal empezar con algo más bajo a lo que ofrece el banco.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Opción 2: Pago con Cuotas -->
                    <div class="col-lg-4 mb-4">
                        <div class="card opcion-pago opcion-2 h-100">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-credit-card me-2"></i>OPCIÓN DE PAGO 2
                                </h5>
                            </div>
                            <div class="card-body">
                                <h6 class="card-subtitle mb-3 text-warning">PAGO EN CUOTAS CON DESCUENTO</h6>
                                
                                <div class="mb-2">
                                    <strong>% Pago Inicial:</strong>
                                    <span class="badge bg-warning text-dark badge-opcion ms-2">
                                        <?php echo htmlspecialchars($opciones_pago[2]['porcentaje_inicial']); ?>%
                                    </span>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Monto Pago Inicial:</strong><br>
                                    <span class="text-warning fw-bold">
                                        $<?php echo formatNumber($opciones_pago[2]['monto_inicial']); ?>
                                    </span>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Nº Cuotas:</strong>
                                    <span class="badge bg-warning text-dark badge-opcion ms-2">
                                        <?php echo htmlspecialchars($opciones_pago[2]['num_cuotas']); ?>
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Monto Cuota:</strong><br>
                                    <span class="text-warning fw-bold">
                                        $<?php echo formatNumber($opciones_pago[2]['monto_cuota']); ?>
                                    </span>
                                </div>
                                
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Mantiene el descuento de la opción 1, pagable en cuotas con 30% inicial.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Opción 3: Pago sin Descuento -->
                    <div class="col-lg-4 mb-4">
                        <div class="card opcion-pago opcion-3 h-100">
                            <div class="card-header bg-danger text-white">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-calendar-month me-2"></i>OPCIÓN DE PAGO 3
                                </h5>
                            </div>
                            <div class="card-body">
                                <h6 class="card-subtitle mb-3 text-danger">PAGO SIN DESCUENTO</h6>
                                
                                <div class="mb-2">
                                    <strong>Cuota Inicial:</strong><br>
                                    <span class="text-danger fw-bold">
                                        $<?php echo formatNumber($opciones_pago[3]['cuota_inicial']); ?>
                                    </span>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Saldo Deuda:</strong><br>
                                    <span class="text-danger fw-bold">
                                        $<?php echo formatNumber($opciones_pago[3]['saldo_deuda']); ?>
                                    </span>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Nº Cuotas:</strong>
                                    <span class="badge bg-danger badge-opcion ms-2">
                                        <?php echo htmlspecialchars($opciones_pago[3]['num_cuotas']); ?>
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Monto de la Cuota:</strong><br>
                                    <span class="text-danger fw-bold">
                                        $<?php echo formatNumber($opciones_pago[3]['monto_cuota']); ?>
                                    </span>
                                </div>
                                
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Máximo 48 cuotas. No aplica descuento. Se debe solicitar pago inicial.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Historial de Gestiones -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card gestion-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history me-2"></i>Historial de Gestiones
                                    <span class="badge bg-primary ms-2"><?php echo count($gestiones); ?> gestiones</span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($gestiones)): ?>
                                    <div class="gestiones-list">
                                        <?php foreach ($gestiones as $index => $gestion): ?>
                                        <div class="gestion-item <?php echo $index > 0 ? 'pt-3' : ''; ?>">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <strong>
                                                                <i class="bi bi-calendar-event me-1"></i>
                                                                <?php echo formatValue($gestion['fecha_procesamiento'] ?: $gestion['fecha_carga']); ?>
                                                            </strong>
                                                            <?php if ($gestion['accomm']): ?>
                                                                <div class="comentario-gestion mt-2">
                                                                    <strong>Comentario:</strong> <?php echo formatValue($gestion['accomm']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="text-end">
                                                        <?php if ($gestion['estado']): ?>
                                                            <span class="badge <?php echo getEstadoBadgeClass($gestion['estado']); ?> badge-estado">
                                                                <?php echo formatValue($gestion['estado']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            Código: <?php echo formatValue($gestion['acaccode']); ?>
                                                            <?php if ($gestion['acrccode']): ?>
                                                                | RC: <?php echo formatValue($gestion['acrccode']); ?>
                                                            <?php endif; ?>
                                                            <?php if ($gestion['aclccode']): ?>
                                                                | LC: <?php echo formatValue($gestion['aclccode']); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                        <br>
                                                        <small class="text-muted">
                                                            Tel: <?php echo formatValue($gestion['acphone']); ?>
                                                        </small>
                                                        <br>
                                                        <small class="text-muted">
                                                            Usuario: <?php echo formatValue($gestion['usuario_carga']); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-inbox display-4 text-muted"></i>
                                        <h5 class="mt-3 text-muted">No hay gestiones registradas</h5>
                                        <p class="text-muted">No se encontraron gestiones para este contrato.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

                <!-- Información Adicional -->
                <?php if (!$deudor && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <i class="bi bi-person-x display-4 text-muted"></i>
                        <h4 class="mt-3">No se encontraron resultados</h4>
                        <p class="text-muted">Verifique que el RUT sea correcto o intente con otro criterio de búsqueda.</p>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Limpiar el RUT al ingresar (quitar puntos, guiones y espacios)
        document.querySelector('input[name="rut"]').addEventListener('input', function(e) {
            // Convertir a mayúsculas y quitar caracteres no deseados
            let value = e.target.value.toUpperCase().replace(/[^0-9K]/g, '');
            e.target.value = value;
        });

        // Validar antes de enviar el formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            const rutInput = document.querySelector('input[name="rut"]');
            const rutValue = rutInput.value.trim();
            
            if (rutValue.length < 7) {
                e.preventDefault();
                alert('El RUT debe tener al menos 7 dígitos');
                rutInput.focus();
                return false;
            }
        });
    </script>
</body>
</html>

<?php
// Función para determinar la clase del badge según el estado
function getEstadoBadgeClass($estado) {
    $estado = strtolower($estado);
    switch ($estado) {
        case 'completado':
        case 'exitosa':
        case 'finalizado':
            return 'bg-success';
        case 'pendiente':
        case 'en proceso':
            return 'bg-warning';
        case 'fallido':
        case 'rechazado':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}
?>