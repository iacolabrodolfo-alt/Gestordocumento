<?php
require_once '../includes/auth.php';

$auth = new Auth();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($auth->login($username, $password)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Credenciales incorrectas. Por favor, intente nuevamente.';
    }
}

// Si ya está logueado, redirigir al dashboard
if ($auth->is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor Documento - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(120, 119, 198, 0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }
        
        .login-card {
            background: rgba(33, 37, 41, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 2;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 35px 60px -12px rgba(0, 0, 0, 0.6);
        }
        
        .brand-section {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .brand-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .brand-logo i {
            font-size: 2.5rem;
            color: white;
        }
        
        .brand-title {
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }
        
        .brand-subtitle {
            color: #6c757d;
            font-size: 1rem;
            font-weight: 300;
        }
        
        .form-control {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 12px 20px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            color: white;
        }
        
        .form-label {
            color: #adb5bd;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 3;
        }
        
        .floating-particles {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 1;
        }
        
        .particle {
            position: absolute;
            background: rgba(102, 126, 234, 0.3);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }
        
        .demo-credentials {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            margin-top: 2rem;
            border-left: 4px solid #667eea;
        }
        
        .demo-title {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .demo-text {
            color: #adb5bd;
            font-size: 0.9rem;
            margin: 0;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            50% {
                transform: translateY(-20px) rotate(180deg);
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(102, 126, 234, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0);
            }
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .login-card {
                margin: 1rem;
                padding: 2rem 1.5rem !important;
            }
            
            .brand-logo {
                width: 60px;
                height: 60px;
            }
            
            .brand-logo i {
                font-size: 2rem;
            }
            
            .brand-title {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container d-flex align-items-center justify-content-center py-5">
        <!-- Floating Particles -->
        <div class="floating-particles">
            <div class="particle" style="width: 20px; height: 20px; top: 20%; left: 10%; animation-delay: 0s;"></div>
            <div class="particle" style="width: 15px; height: 15px; top: 60%; left: 80%; animation-delay: 1s;"></div>
            <div class="particle" style="width: 25px; height: 25px; top: 80%; left: 20%; animation-delay: 2s;"></div>
            <div class="particle" style="width: 18px; height: 18px; top: 30%; left: 70%; animation-delay: 3s;"></div>
            <div class="particle" style="width: 22px; height: 22px; top: 70%; left: 40%; animation-delay: 4s;"></div>
        </div>
        
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-4">
                    <div class="login-card p-5">
                        <!-- Brand Section -->
                        <div class="brand-section">
                            <div class="brand-logo pulse">
                                <i class="bi bi-files"></i>
                            </div>
                            <h1 class="brand-title">Gestor Documento</h1>
                            <p class="brand-subtitle">Sistema de Gestión Documental</p>
                        </div>
                        
                        <!-- Error Alert -->
                        <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <div><?php echo htmlspecialchars($error); ?></div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Login Form -->
                        <form method="POST" action="">
                            <div class="mb-4">
                                <label for="username" class="form-label">
                                    <i class="bi bi-person me-1"></i>Usuario
                                </label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="username" name="username" 
                                           placeholder="Ingrese su usuario" required autofocus>
                                    <span class="input-icon">
                                        <i class="bi bi-person-circle"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    <i class="bi bi-key me-1"></i>Contraseña
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="Ingrese su contraseña" required>
                                    <span class="input-icon">
                                        <i class="bi bi-shield-lock"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-login w-100 mb-4">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar Sesión
                            </button>
                        </form>
                        
                        <!-- Demo Credentials -->
                        <div class="demo-credentials">
                            <div class="demo-title">
                                <i class="bi bi-info-circle me-1"></i>Credenciales de Demo
                            </div>
                            <p class="demo-text">
                                Usuario: <strong>admin</strong><br>
                                Contraseña: <strong>password</strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Efecto de partículas dinámicas
        document.addEventListener('DOMContentLoaded', function() {
            const particlesContainer = document.querySelector('.floating-particles');
            const colors = ['rgba(102, 126, 234, 0.3)', 'rgba(118, 75, 162, 0.3)', 'rgba(79, 70, 229, 0.3)'];
            
            // Crear partículas adicionales
            for (let i = 0; i < 8; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
                const size = Math.random() * 15 + 10;
                const color = colors[Math.floor(Math.random() * colors.length)];
                const delay = Math.random() * 5;
                
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.background = color;
                particle.style.top = `${Math.random() * 100}%`;
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.animationDelay = `${delay}s`;
                
                particlesContainer.appendChild(particle);
            }
            
            // Efecto de focus en los inputs
            const inputs = document.querySelectorAll('.form-control');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.querySelector('.input-icon').style.color = '#667eea';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.querySelector('.input-icon').style.color = '#6c757d';
                });
            });
        });
    </script>
</body>
</html>