<?php
// =============================================
// USAR TU SISTEMA DE AUTENTICACIÓN
// =============================================
require_once '../../includes/auth.php';
$auth = new Auth();
$auth->require_admin();

require_once '../../config/database.php';
$db = new Database();
$db->connect();

// Headers para JSON
header('Content-Type: application/json');

// =============================================
// CONFIGURACIÓN DE PhpSpreadsheet MODERNA
// =============================================
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// =============================================
// SISTEMA DE LOGGING
// =============================================
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/../../debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        if (is_array($data)) {
            $log_message .= " | Data: " . json_encode($data);
        } else {
            $log_message .= " | Data: " . (string)$data;
        }
    }
    
    $log_message .= "\n";
    
    if (!file_exists($log_file)) {
        file_put_contents($log_file, "=== DEBUG LOG INICIADO ===\n");
    }
    
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}

debug_log("🎯 PROCESAR_EXCEL.PHP INICIADO - Usuario: " . $_SESSION['username']);

// =============================================
// CONFIGURACIÓN DE TIPOS DE ARCHIVO
// =============================================
$TIPOS_ARCHIVO = [
    'ASIGNACION_STOCK' => [
        'nombre' => 'Asignación Stock Mensual',
        'tabla_temporal' => 'Carga_Temporal_AsignacionStock',
        'tabla_final' => 'Asignacion_Stock',
        'sp' => 'sp_CargarAsignacionStock',
        'columnas_requeridas' => [
            'PERIODO_PROCESO', 'RUT', 'DV', 'CONTRATO', 'NOMBRE', 
            'FECHA_CASTIGO', 'SALDO_GENERADO', 'CLASIFICACION_BIENES', 'CANAL'
        ]
    ]
];

// =============================================
// FUNCIONES AUXILIARES MEJORADAS
// =============================================
function registrarLog($tipo_archivo, $nombre_archivo, $nombre_original, $usuario, $estado, $registros = null, $error = null) {
    global $db;
    
    debug_log("📝 Registrando log en BD...");
    
    try {
        $sql = "INSERT INTO Logs_Carga_Excel (tipo_archivo, nombre_archivo, nombre_original, usuario_carga, estado, registros_procesados, error_mensaje) 
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $params = [$tipo_archivo, $nombre_archivo, $nombre_original, $usuario, $estado, $registros, $error];
        $stmt = $db->secure_query($sql, $params);
        
        if ($stmt && sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $log_id = $row['id'];
            debug_log("✅ Log registrado exitosamente - ID: " . $log_id);
            return $log_id;
        } else {
            debug_log("⚠️ No se pudo obtener el ID del log");
            return 1;
        }
        
    } catch (Exception $e) {
        debug_log("💥 Excepción en log: " . $e->getMessage());
        return 1;
    }
}

function actualizarLog($log_id, $estado, $registros = null, $error = null) {
    global $db;
    
    debug_log("🔄 Actualizando log ID $log_id - Estado: $estado");
    
    try {
        $sql = "UPDATE Logs_Carga_Excel SET estado = ?, registros_procesados = ?, error_mensaje = ? WHERE id = ?";
        $params = [$estado, $registros, $error, $log_id];
        $result = $db->secure_query($sql, $params);
        
        debug_log("✅ Log actualizado exitosamente");
        return true;
    } catch (Exception $e) {
        debug_log("💥 Error actualizando log: " . $e->getMessage());
        return false;
    }
}

function debugMapeoColumnas($headers, $fila_ejemplo) {
    debug_log("🔍 DEBUG MApeo de Columnas:");
    debug_log("📋 Headers originales:", $headers);
    
    $headers_limpios = array_map(function($header) {
        return strtoupper(trim($header));
    }, $headers);
    
    debug_log("📋 Headers limpios:", $headers_limpios);
    
    // Verificar columnas requeridas
    $columnas_requeridas = ['PERIODO_PROCESO', 'RUT', 'DV', 'CONTRATO', 'NOMBRE', 'FECHA_CASTIGO', 'SALDO_GENERADO', 'CLASIFICACION_BIENES', 'CANAL'];
    
    foreach ($columnas_requeridas as $columna) {
        $encontrado = in_array($columna, $headers_limpios);
        debug_log("🔍 Columna '$columna': " . ($encontrado ? 'ENCONTRADA' : 'NO ENCONTRADA'));
        
        if ($encontrado) {
            $indice = array_search($columna, $headers_limpios);
            $valor = $fila_ejemplo[$indice] ?? 'N/A';
            debug_log("   📍 Índice: $indice, Valor: '$valor'");
        }
    }
    
    // Mapeo detallado
    debug_log("🔍 Mapeo detallado por índice:");
    foreach ($headers as $indice => $header) {
        $header_limpio = strtoupper(trim($header));
        $columna_bd = mapearColumnaBD($header_limpio);
        $valor = $fila_ejemplo[$indice] ?? 'N/A';
        debug_log("   [$indice] '$header' -> '$header_limpio' -> '$columna_bd' = '$valor'");
    }
}

function mapearColumnaBD($columna_excel) {
    $mapeo = [
        'PERIODO_PROCESO' => 'periodo_proceso',
        'FECHA_PROCESO' => 'fecha_proceso',
        'PERIODO_CASTIGO' => 'periodo_castigo',
        'RUT' => 'rut',
        'DV' => 'dv',
        'CONTRATO' => 'contrato',
        'NOMBRE' => 'nombre',
        'PATERNO' => 'paterno',
        'MATERNO' => 'materno',
        'FECHA_CASTIGO' => 'fecha_castigo',
        'SALDO_GENERADO' => 'saldo_generado',
        'CLASIFICACION_BIENES' => 'clasificacion_bienes',
        'CANAL' => 'canal',
        'CLASIFICACION' => 'clasificacion',
        'DIRECCION' => 'direccion',
        'NUMERACION_DIR' => 'numeracion_dir',
        'RESTO' => 'resto',
        'REGION' => 'region',
        'COMUNA' => 'comuna',
        'CIUDAD' => 'ciudad',
        'ABOGADO' => 'abogado',
        'ZONA' => 'zona',
        'CORREO1' => 'correo1',
        'CORREO2' => 'correo2',
        'CORREO3' => 'correo3',
        'CORREO4' => 'correo4',
        'CORREO5' => 'correo5',
        'TELEFONO1' => 'telefono1',
        'TELEFONO2' => 'telefono2',
        'TELEFONO3' => 'telefono3',
        'TELEFONO4' => 'telefono4',
        'TELEFONO5' => 'telefono5',
        'FECHA_PAGO' => 'fecha_pago',
        'MONTO_PAGO' => 'monto_pago',
        'FECHA_VENCIMIENTO' => 'fecha_vencimiento',
        'DIAS_MORA' => 'dias_mora',
        'FECHA_SUSC' => 'fecha_susc',
        'TIPO_CAMPAÑA' => 'tipo_campana',
        'TIPO_CAMPANA' => 'tipo_campana',
        'DESCUENTO' => 'descuento',
        'MONTO_A_PAGAR' => 'monto_a_pagar',
        'SALDO_EN_CAMPAÑA' => 'saldo_en_campana',
        'SALDO_EN_CAMPANA' => 'saldo_en_campana',
        'FECHA_ASIGNACION' => 'fecha_asignacion',
        'TIPO_CARTERA' => 'tipo_cartera'
    ];
    
    return $mapeo[$columna_excel] ?? null;
}

function convertirFechaExcel($valor) {
    if (empty($valor)) return null;
    
    // Si es numérico (fecha Excel)
    if (is_numeric($valor)) {
        try {
            $fecha = Date::excelToDateTimeObject($valor);
            return $fecha->format('Y-m-d');
        } catch (Exception $e) {
            debug_log("⚠️ Error convirtiendo fecha Excel: " . $e->getMessage());
        }
    }
    
    // Si es string con formato fecha
    if (is_string($valor)) {
        // Formato mm/dd/yyyy (inglés)
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $valor)) {
            $fecha = DateTime::createFromFormat('m/d/Y', $valor);
            if ($fecha) return $fecha->format('Y-m-d');
        }
        
        // Formato dd-mm-yyyy
        if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $valor)) {
            $fecha = DateTime::createFromFormat('d-m-Y', $valor);
            if ($fecha) return $fecha->format('Y-m-d');
        }
    }
    
    return $valor; // Devolver original si no se puede convertir
}

function limpiarValor($valor, $tipo_campo = 'string') {
    if ($valor === null || $valor === '') {
        return null;
    }
    
    $valor = trim($valor);
    
    // Manejar números/monedas
    if ($tipo_campo === 'decimal' || $tipo_campo === 'money') {
        if (is_string($valor)) {
            // Remover símbolos de moneda y espacios
            $valor = str_replace(['$', ' ', ','], '', $valor);
            // Convertir a float
            if (is_numeric($valor)) {
                return (float)$valor;
            }
        }
        if (is_numeric($valor)) {
            return (float)$valor;
        }
    }
    
    // Manejar enteros
    if ($tipo_campo === 'int') {
        if (is_numeric($valor)) {
            return (int)$valor;
        }
    }
    
    return $valor;
}

function obtenerTipoCampo($columna_bd) {
    $tipos = [
        'periodo_proceso' => 'string',
        'fecha_proceso' => 'date',
        'periodo_castigo' => 'string',
        'rut' => 'string',
        'dv' => 'string',
        'contrato' => 'string',
        'nombre' => 'string',
        'paterno' => 'string',
        'materno' => 'string',
        'fecha_castigo' => 'date',
        'saldo_generado' => 'decimal',
        'clasificacion_bienes' => 'string',
        'canal' => 'string',
        'saldo_en_campana' => 'decimal',
        'monto_pago' => 'decimal',
        'monto_a_pagar' => 'decimal',
        'descuento' => 'decimal',
        'dias_mora' => 'int'
    ];
    
    return $tipos[$columna_bd] ?? 'string';
}

function procesarArchivoCompleto($tipo_archivo, $archivo_temporal, $nombre_archivo, $nombre_original, $usuario) {
    global $db, $TIPOS_ARCHIVO;
    
    debug_log("🚀 INICIANDO PROCESAMIENTO COMPLETO: " . $tipo_archivo);
    
    $log_id = registrarLog($tipo_archivo, $nombre_archivo, $nombre_original, $usuario, 'PROCESANDO');
    $registros_procesados = 0;
    $errores = [];
    
    try {
        debug_log("📦 CARGANDO ARCHIVO EXCEL...");
        
        // Cargar archivo Excel
        $spreadsheet = IOFactory::load($archivo_temporal);
        debug_log("✅ Excel cargado exitosamente");
        
        // Obtener primera hoja
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();
        
        debug_log("📊 Total de filas en Excel: " . count($data));
        
        if (count($data) < 2) {
            throw new Exception("El archivo Excel no contiene datos (solo headers o vacío)");
        }
        
        // Obtener headers (primera fila)
        $headers = $data[0];
        debug_log("📋 Headers encontrados:", $headers);
        
        // DEBUG: Analizar mapeo de columnas con la primera fila de datos
        if (count($data) > 1) {
            debugMapeoColumnas($headers, $data[1]);
        }
        
        // Validar headers requeridos
        $config = $TIPOS_ARCHIVO[$tipo_archivo];
        $headers_faltantes = [];
        foreach ($config['columnas_requeridas'] as $columna_requerida) {
            $encontrado = false;
            foreach ($headers as $header) {
                if (strtoupper(trim($header)) === $columna_requerida) {
                    $encontrado = true;
                    break;
                }
            }
            if (!$encontrado) {
                $headers_faltantes[] = $columna_requerida;
            }
        }
        
        if (!empty($headers_faltantes)) {
            throw new Exception("Faltan columnas requeridas en el Excel: " . implode(', ', $headers_faltantes));
        }
        
        // PASO 1: LIMPIAR TABLA TEMPORAL
        debug_log("🧹 Limpiando tabla temporal...");
        $sql_limpiar = "TRUNCATE TABLE " . $config['tabla_temporal'];
        $db->secure_query($sql_limpiar);
        debug_log("✅ Tabla temporal limpiada");
        
        // PASO 2: INSERTAR EN TABLA TEMPORAL
        debug_log("💾 Insertando datos en tabla temporal...");
        $filas_procesadas = 0;
        $filas_con_error = 0;
        $filas_omitidas = 0;
        
        for ($i = 1; $i < count($data); $i++) {
            $fila = $data[$i];
            
            // Saltar filas vacías
            if (empty(array_filter($fila, function($v) { return $v !== null && $v !== ''; }))) {
                $filas_omitidas++;
                continue;
            }
            
            // Procesar fila
            $fila_procesada = procesarFilaParaTemporal($fila, $headers, $nombre_original, $usuario);
            
            if ($fila_procesada) {
                if (insertarFilaTemporal($config['tabla_temporal'], $fila_procesada)) {
                    $filas_procesadas++;
                } else {
                    $filas_con_error++;
                }
            } else {
                $filas_con_error++;
            }
            
            // Log cada 500 filas
            if ($i % 500 === 0) {
                debug_log("📈 Progreso: $i filas procesadas de " . (count($data) - 1));
            }
        }
        
        // PASO 3: VERIFICAR DATOS EN TEMPORAL
        $sql_contar = "SELECT COUNT(*) as total FROM " . $config['tabla_temporal'];
        $stmt = $db->secure_query($sql_contar);
        if ($stmt && sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $total_temporal = $row['total'];
            debug_log("📊 Registros en tabla temporal: " . $total_temporal);
        }
        
        // PASO 4: EJECUTAR STORED PROCEDURE
        if ($total_temporal > 0) {
            debug_log("⚙️ Ejecutando stored procedure...");
            $periodo = extraerPeriodoDelNombre($nombre_original);
            
            $sql_sp = "EXEC " . $config['sp'] . " @PeriodoProceso = ?, @ArchivoOrigen = ?, @UsuarioCarga = ?";
            $params_sp = [$periodo, $nombre_original, $usuario];
            
            $result_sp = $db->secure_query($sql_sp, $params_sp);
            
            if ($result_sp) {
                debug_log("✅ Stored procedure ejecutado exitosamente");
                $registros_procesados = $total_temporal;
            } else {
                throw new Exception("Error ejecutando stored procedure");
            }
        } else {
            throw new Exception("No hay datos válidos para procesar en la tabla temporal");
        }
        
        debug_log("📈 RESUMEN FINAL: $filas_procesadas filas procesadas, $filas_con_error filas con error, $filas_omitidas filas omitidas");
        
        if ($registros_procesados > 0) {
            $estado = 'COMPLETADO';
            $mensaje = "Archivo procesado correctamente. Registros insertados: $registros_procesados";
            if ($filas_con_error > 0) {
                $mensaje .= " ($filas_con_error filas con error)";
            }
        } else {
            $estado = 'ERROR';
            $mensaje = "No se pudieron procesar registros.";
        }
        
        actualizarLog($log_id, $estado, $registros_procesados, implode('; ', $errores));
        
        return [
            'success' => $registros_procesados > 0,
            'registros_procesados' => $registros_procesados,
            'errores' => $errores,
            'log_id' => $log_id,
            'mensaje' => $mensaje,
            'filas_con_error' => $filas_con_error,
            'filas_omitidas' => $filas_omitidas
        ];
        
    } catch (Exception $e) {
        debug_log("💥 ERROR PROCESANDO ARCHIVO: " . $e->getMessage());
        actualizarLog($log_id, 'ERROR', 0, $e->getMessage());
        
        return [
            'success' => false,
            'error' => "Error procesando archivo: " . $e->getMessage(),
            'log_id' => $log_id
        ];
    }
}

function procesarFilaParaTemporal($fila, $headers, $archivo_origen, $usuario) {
    $valores = [];
    
    // Campos del sistema
    $valores['fecha_carga'] = date('Y-m-d H:i:s');
    $valores['archivo_origen'] = $archivo_origen;
    $valores['usuario_carga'] = $usuario;
    
    // DEBUG: Mostrar primera fila completa
    static $debug_fila = true;
    if ($debug_fila) {
        debug_log("🔍 DEBUG Primera fila completa:");
        foreach ($headers as $index => $header) {
            $header_limpio = strtoupper(trim($header));
            $valor = $fila[$index] ?? 'N/A';
            debug_log("   [$index] '$header' ('$header_limpio') = '$valor'");
        }
        $debug_fila = false;
    }
    
    // Mapear valores del Excel
    foreach ($headers as $index => $header) {
        if (isset($fila[$index])) {
            $columna_bd = mapearColumnaBD(strtoupper(trim($header)));
            if ($columna_bd) {
                $tipo_campo = obtenerTipoCampo($columna_bd);
                
                if ($tipo_campo === 'date') {
                    $valores[$columna_bd] = convertirFechaExcel($fila[$index]);
                } else {
                    $valores[$columna_bd] = limpiarValor($fila[$index], $tipo_campo);
                }
                
                // DEBUG para campos requeridos
                if (in_array($columna_bd, ['dv', 'contrato'])) {
                    debug_log("🔍 Campo $columna_bd: '" . $valores[$columna_bd] . "'");
                }
            }
        }
    }
    
    // Validar campos requeridos
    $campos_requeridos = ['periodo_proceso', 'rut', 'dv', 'contrato', 'nombre', 'fecha_castigo', 'saldo_generado', 'clasificacion_bienes', 'canal'];
    $campos_faltantes = [];
    
    foreach ($campos_requeridos as $campo) {
        if (empty($valores[$campo])) {
            $campos_faltantes[] = $campo;
        }
    }
    
    if (!empty($campos_faltantes)) {
        debug_log("❌ Fila omitida - Campos requeridos vacíos: " . implode(', ', $campos_faltantes) . " - Contrato: " . ($valores['contrato'] ?? 'N/A'));
        return false;
    }
    
    return $valores;
}

function insertarFilaTemporal($tabla_temporal, $fila_procesada) {
    global $db;
    
    try {
        $columnas = array_keys($fila_procesada);
        $placeholders = array_fill(0, count($columnas), '?');
        $params = array_values($fila_procesada);
        
        $sql = "INSERT INTO $tabla_temporal (" . implode(', ', $columnas) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $result = $db->secure_query($sql, $params);
        
        if ($result) {
            return true;
        } else {
            debug_log("❌ Error insertando fila en temporal");
            return false;
        }
        
    } catch (Exception $e) {
        debug_log("💥 Excepción insertando fila: " . $e->getMessage());
        return false;
    }
}

function extraerPeriodoDelNombre($nombre_archivo) {
    // Para archivos tipo "Asignacion 202510 - MAB.xlsx"
    if (preg_match('/(\d{6})/', $nombre_archivo, $matches)) {
        return $matches[1];
    }
    return date('Ym'); // Por defecto, mes actual
}

// =============================================
// PROCESAMIENTO DE LA SOLICITUD POST
// =============================================
debug_log("=================== NUEVA SOLICITUD ===================");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        debug_log("🔍 Verificando archivo subido...");
        
        if (!isset($_FILES['archivo_excel'])) {
            throw new Exception('No se recibió ningún archivo');
        }
        
        $file_error = $_FILES['archivo_excel']['error'];
        if ($file_error !== UPLOAD_ERR_OK) {
            throw new Exception('Error al subir el archivo. Código: ' . $file_error);
        }
        
        $tipo_archivo = $_POST['tipo_archivo'] ?? '';
        debug_log("🎯 Tipo de archivo: " . $tipo_archivo);
        
        if (!array_key_exists($tipo_archivo, $TIPOS_ARCHIVO)) {
            throw new Exception('Tipo de archivo no válido: ' . $tipo_archivo);
        }
        
        // Validaciones de archivo
        $nombre_original = $_FILES['archivo_excel']['name'];
        $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        debug_log("📄 Archivo: " . $nombre_original . " | Extensión: " . $extension);
        
        if (!in_array($extension, ['xlsx', 'xls'])) {
            throw new Exception('Solo se permiten archivos Excel (.xlsx, .xls)');
        }
        
        $tamaño = $_FILES['archivo_excel']['size'];
        debug_log("📏 Tamaño: " . $tamaño . " bytes");
        
        if ($tamaño > 50 * 1024 * 1024) {
            throw new Exception('El archivo es demasiado grande (máximo 50MB)');
        }
        
        // Guardar archivo
        $timestamp = date('Ymd_His');
        $nombre_archivo = $timestamp . '_' . uniqid() . '_' . $nombre_original;
        $ruta_destino = '../../files/uploads/' . $nombre_archivo;
        
        debug_log("💾 Guardando en: " . $ruta_destino);
        
        // Crear directorio si no existe
        $upload_dir = dirname($ruta_destino);
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        if (!move_uploaded_file($_FILES['archivo_excel']['tmp_name'], $ruta_destino)) {
            throw new Exception('Error al guardar el archivo en el servidor');
        }
        
        debug_log("✅ Archivo guardado exitosamente");
        
        // Procesar archivo con nuevo enfoque
        $resultado = procesarArchivoCompleto(
            $tipo_archivo,
            $ruta_destino,
            $nombre_archivo,
            $nombre_original,
            $_SESSION['username']
        );
        
        debug_log("📊 Resultado final del procesamiento:", $resultado);
        echo json_encode($resultado);
        
    } catch (Exception $e) {
        debug_log("💥 ERROR EN SOLICITUD: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    debug_log("❌ MÉTODO NO PERMITADO: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ]);
}

debug_log("=================== FIN SOLICITUD ===================\n");