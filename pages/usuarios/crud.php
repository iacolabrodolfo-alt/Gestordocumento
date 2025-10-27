<?php
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

$db = new Database();
$db->connect();

// Inicializar variables
$mensaje = '';
$usuario_edicion = null;
$usuario_password = null;
$modo = 'normal';

// Procesar acciones GET primero (eliminar, reactivar, cambiar modo)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $id = $_GET['id'] ?? '';
    
    if ($action === 'editar' && $id) {
        $modo = 'editar';
        // Cargar usuario para edición
        $sql = "SELECT id, username, nombre_completo, perfil FROM usuarios WHERE id = ?";
        $stmt = $db->secure_query($sql, array($id));
        if ($stmt) {
            $usuario_edicion = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        }
    }
    elseif ($action === 'cambiar_password' && $id) {
        $modo = 'cambiar_password';
        // Cargar usuario para cambio de contraseña
        $sql = "SELECT id, username, nombre_completo FROM usuarios WHERE id = ?";
        $stmt = $db->secure_query($sql, array($id));
        if ($stmt) {
            $usuario_password = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        }
    }
    elseif ($action === 'eliminar' && $id) {
        // Prevenir que el admin se desactive a sí mismo
        if ($id == $_SESSION['user_id']) {
            $mensaje = "<div class='alert alert-danger'>No puedes desactivar tu propio usuario</div>";
        } else {
            $sql = "UPDATE usuarios SET activo = 0 WHERE id = ?";
            if ($db->secure_query($sql, array($id))) {
                $mensaje = "<div class='alert alert-success'>Usuario desactivado exitosamente</div>";
            } else {
                $mensaje = "<div class='alert alert-danger'>Error al desactivar usuario</div>";
            }
        }
    }
    elseif ($action === 'reactivar' && $id) {
        $sql = "UPDATE usuarios SET activo = 1 WHERE id = ?";
        if ($db->secure_query($sql, array($id))) {
            $mensaje = "<div class='alert alert-success'>Usuario reactivado exitosamente</div>";
        } else {
            $mensaje = "<div class='alert alert-danger'>Error al reactivar usuario</div>";
        }
    }
}

// Procesar formularios POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'crear') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $nombre_completo = trim($_POST['nombre_completo'] ?? '');
        $perfil = $_POST['perfil'] ?? '';
        
        if (!empty($username) && !empty($password)) {
            // Verificar si el usuario ya existe
            $sql_check = "SELECT id FROM usuarios WHERE username = ?";
            $stmt_check = $db->secure_query($sql_check, array($username));
            
            if ($stmt_check && sqlsrv_has_rows($stmt_check)) {
                $mensaje = "<div class='alert alert-warning'>El usuario ya existe</div>";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $sql = "INSERT INTO usuarios (username, password_hash, nombre_completo, perfil) 
                        VALUES (?, ?, ?, ?)";
                $params = array($username, $password_hash, $nombre_completo, $perfil);
                
                if ($db->secure_query($sql, $params)) {
                    $mensaje = "<div class='alert alert-success'>Usuario creado exitosamente</div>";
                } else {
                    $mensaje = "<div class='alert alert-danger'>Error al crear usuario</div>";
                }
            }
        }
    }
    elseif ($action === 'editar') {
        $id = $_POST['id'] ?? '';
        $nombre_completo = trim($_POST['nombre_completo'] ?? '');
        $perfil = $_POST['perfil'] ?? '';
        
        $sql = "UPDATE usuarios SET nombre_completo = ?, perfil = ? WHERE id = ?";
        $params = array($nombre_completo, $perfil, $id);
        
        if ($db->secure_query($sql, $params)) {
            $mensaje = "<div class='alert alert-success'>Usuario actualizado exitosamente</div>";
            $modo = 'normal'; // Volver al modo normal
        } else {
            $mensaje = "<div class='alert alert-danger'>Error al actualizar usuario</div>";
        }
    }
    elseif ($action === 'cambiar_password') {
        $id = $_POST['id'] ?? '';
        $nueva_password = $_POST['nueva_password'] ?? '';
        
        if (!empty($nueva_password)) {
            $password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
            $sql = "UPDATE usuarios SET password_hash = ? WHERE id = ?";
            $params = array($password_hash, $id);
            
            if ($db->secure_query($sql, $params)) {
                $mensaje = "<div class='alert alert-success'>Contraseña actualizada exitosamente</div>";
                $modo = 'normal'; // Volver al modo normal
            } else {
                $mensaje = "<div class='alert alert-danger'>Error al actualizar contraseña</div>";
            }
        }
    }
}

// Obtener lista de usuarios
$sql = "SELECT id, username, nombre_completo, perfil, activo, fecha_creacion 
        FROM usuarios 
        ORDER BY activo DESC, fecha_creacion DESC";
$stmt = $db->secure_query($sql);
$usuarios = array();
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $usuarios[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Gestor Documento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .table-actions {
            white-space: nowrap;
        }
        .badge-inactive {
            background-color: #6c757d;
        }
        .badge-active {
            background-color: #198754;
        }
        .badge-admin {
            background-color: #0d6efd;
        }
        .badge-ejecutivo {
            background-color: #6f42c1;
        }
        .form-control:read-only {
            background-color: #e9ecef;
            opacity: 1;
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
                            <a class="nav-link active" href="crud.php">
                                <i class="bi bi-people me-2"></i>Gestión de Usuarios
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../procesos/">
                                <i class="bi bi-gear me-2"></i>Procesos
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Usuarios</h1>
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

                <?php echo $mensaje; ?>

                <div class="row">
                    <!-- Formulario de Usuario -->
                    <div class="col-md-4">
                        <?php if ($modo === 'cambiar_password' && $usuario_password): ?>
                            <!-- Formulario de Cambio de Contraseña -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-key me-2"></i>
                                        Cambiar Contraseña
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="cambiar_password">
                                        <input type="hidden" name="id" value="<?php echo $usuario_password['id']; ?>">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Usuario</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($usuario_password['username']); ?>" readonly>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Nombre</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($usuario_password['nombre_completo']); ?>" readonly>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Nueva Contraseña *</label>
                                            <input type="password" class="form-control" name="nueva_password" required minlength="6">
                                            <small class="form-text text-muted">Mínimo 6 caracteres</small>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-warning w-100">
                                            <i class="bi bi-key me-2"></i>Cambiar Contraseña
                                        </button>
                                        
                                        <a href="crud.php" class="btn btn-secondary w-100 mt-2">
                                            <i class="bi bi-x-circle me-2"></i>Cancelar
                                        </a>
                                    </form>
                                </div>
                            </div>
                        <?php elseif ($modo === 'editar' && $usuario_edicion): ?>
                            <!-- Formulario de Edición -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-pencil-circle me-2"></i>
                                        Editar Usuario
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="editar">
                                        <input type="hidden" name="id" value="<?php echo $usuario_edicion['id']; ?>">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Usuario</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($usuario_edicion['username']); ?>" readonly>
                                            <small class="form-text text-muted">El usuario no se puede modificar</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Nombre Completo *</label>
                                            <input type="text" class="form-control" name="nombre_completo" 
                                                   value="<?php echo htmlspecialchars($usuario_edicion['nombre_completo']); ?>" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Perfil *</label>
                                            <select class="form-select" name="perfil" required>
                                                <option value="administrador" <?php echo ($usuario_edicion['perfil'] === 'administrador') ? 'selected' : ''; ?>>Administrador</option>
                                                <option value="ejecutivo" <?php echo ($usuario_edicion['perfil'] === 'ejecutivo') ? 'selected' : ''; ?>>Ejecutivo</option>
                                            </select>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-check-circle me-2"></i>
                                            Actualizar Usuario
                                        </button>
                                        
                                        <a href="crud.php" class="btn btn-secondary w-100 mt-2">
                                            <i class="bi bi-x-circle me-2"></i>Cancelar
                                        </a>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Formulario Normal de Nuevo Usuario -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-plus-circle me-2"></i>
                                        Nuevo Usuario
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="crear">
                                        <div class="mb-3">
                                            <label class="form-label">Usuario *</label>
                                            <input type="text" class="form-control" name="username" required pattern="[a-zA-Z0-9_]+" title="Solo letras, números y guiones bajos">
                                            <small class="form-text text-muted">Solo letras, números y _</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Contraseña *</label>
                                            <input type="password" class="form-control" name="password" required minlength="6">
                                            <small class="form-text text-muted">Mínimo 6 caracteres</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Nombre Completo *</label>
                                            <input type="text" class="form-control" name="nombre_completo" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Perfil *</label>
                                            <select class="form-select" name="perfil" required>
                                                <option value="administrador">Administrador</option>
                                                <option value="ejecutivo">Ejecutivo</option>
                                            </select>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-plus-circle me-2"></i>
                                            Crear Usuario
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Lista de Usuarios -->
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-ul me-2"></i>Lista de Usuarios
                                </h5>
                                <span class="badge bg-primary">Total: <?php echo count($usuarios); ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (empty($usuarios)): ?>
                                    <div class="alert alert-info">No hay usuarios registrados</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Usuario</th>
                                                    <th>Nombre Completo</th>
                                                    <th>Perfil</th>
                                                    <th>Estado</th>
                                                    <th>Fecha Creación</th>
                                                    <th class="table-actions">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($usuarios as $usuario): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($usuario['username']); ?></strong>
                                                            <?php if ($usuario['id'] == $_SESSION['user_id']): ?>
                                                                <span class="badge bg-info">Tú</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($usuario['nombre_completo']); ?></td>
                                                        <td>
                                                            <span class="badge badge-<?php echo $usuario['perfil'] === 'administrador' ? 'admin' : 'ejecutivo'; ?>">
                                                                <?php echo htmlspecialchars($usuario['perfil']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?php echo $usuario['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                                                <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $usuario['fecha_creacion']->format('d/m/Y'); ?></small>
                                                        </td>
                                                        <td class="table-actions">
                                                            <div class="btn-group btn-group-sm">
                                                                <a href="crud.php?action=editar&id=<?php echo $usuario['id']; ?>" 
                                                                   class="btn btn-outline-primary" title="Editar">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                
                                                                <a href="crud.php?action=cambiar_password&id=<?php echo $usuario['id']; ?>" 
                                                                   class="btn btn-outline-warning" title="Cambiar Contraseña">
                                                                    <i class="bi bi-key"></i>
                                                                </a>
                                                                
                                                                <?php if ($usuario['id'] != $_SESSION['user_id']): ?>
                                                                    <?php if ($usuario['activo']): ?>
                                                                        <a href="crud.php?action=eliminar&id=<?php echo $usuario['id']; ?>" 
                                                                           class="btn btn-outline-danger"
                                                                           onclick="return confirm('¿Estás seguro de desactivar este usuario?')"
                                                                           title="Desactivar">
                                                                            <i class="bi bi-trash"></i>
                                                                        </a>
                                                                    <?php else: ?>
                                                                        <a href="crud.php?action=reactivar&id=<?php echo $usuario['id']; ?>" 
                                                                           class="btn btn-outline-success"
                                                                           onclick="return confirm('¿Reactivar este usuario?')"
                                                                           title="Reactivar">
                                                                            <i class="bi bi-arrow-clockwise"></i>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                <?php endif; ?>
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
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirmación adicional para acciones importantes
        document.addEventListener('DOMContentLoaded', function() {
            const dangerousLinks = document.querySelectorAll('a[href*="eliminar"], a[href*="reactivar"]');
            dangerousLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('¿Estás seguro de realizar esta acción?')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>