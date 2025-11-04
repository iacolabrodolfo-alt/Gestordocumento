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

// =============================================
// CONFIGURACIÓN DE TIMEOUT PARA ARCHIVOS GRANDES
// =============================================
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');
ignore_user_abort(true);

// Headers para evitar timeouts en el navegador
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

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
    ],
    'JUDICIAL_BASE' => [
        'nombre' => 'Judicial - Hoja BASE',
        'tabla_temporal' => 'Carga_Temporal_JudicialBase',
        'tabla_final' => 'Judicial_Base',
        'sp' => 'sp_CargarJudicialBase',
        'columnas_requeridas' => [
            'periodo', 'juicio_id', 'contrato', 'rut', 'dv', 'nombre',
            'fecha_asignacion', 'saldo', 'cuantia'
        ]
    ]
];

// =============================================
// FUNCIONES PARA JUDICIAL (MANTENER LO QUE FUNCIONA)
// =============================================
function procesarJudicialSimple($archivo_temporal, $nombre_original, $usuario, $log_id) {
    global $db;
    
    debug_log("🚀 INICIANDO PROCESAMIENTO JUDICIAL");
    
    try {
        $spreadsheet = IOFactory::load($archivo_temporal);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();
        
        debug_log("📊 Total de filas en Excel: " . count($data));
        
        if (count($data) < 2) {
            throw new Exception("El archivo Excel no contiene datos");
        }
        
        $headers = $data[0];
        debug_log("📋 Headers encontrados: " . implode(', ', array_slice($headers, 0, 15)));
        
        // Limpiar tabla temporal
        debug_log("🧹 Limpiando tabla temporal...");
        $db->secure_query("TRUNCATE TABLE Carga_Temporal_JudicialBase");
        
        // Procesar filas
        $filas_procesadas = 0;
        $filas_con_error = 0;
        
        for ($i = 1; $i < count($data); $i++) {
            $fila = $data[$i];
            
            if (empty(array_filter($fila, function($v) { return $v !== null && $v !== ''; }))) {
                continue;
            }
            
            $fila_procesada = procesarFilaJudicial($fila, $headers, $nombre_original, $usuario);
            
            if ($fila_procesada && !empty($fila_procesada['periodo'])) {
                if (insertarFilaTemporalJudicial($fila_procesada)) {
                    $filas_procesadas++;
                } else {
                    $filas_con_error++;
                }
            } else {
                $filas_con_error++;
            }
            
            if ($i % 100 === 0) {
                debug_log("📈 Progreso Judicial: $i/" . (count($data)-1) . " - OK: $filas_procesadas - Error: $filas_con_error");
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        }
        
        debug_log("📊 RESUMEN JUDICIAL: $filas_procesadas filas procesadas, $filas_con_error filas con error");
        
        // Verificar datos en temporal
        $sql_contar = "SELECT COUNT(*) as total FROM Carga_Temporal_JudicialBase";
        $stmt = $db->secure_query($sql_contar);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $total_temporal = $row['total'];
        
        debug_log("📦 Registros en tabla temporal judicial: " . $total_temporal);
        
        if ($total_temporal === 0) {
            throw new Exception("No hay datos válidos en la tabla temporal judicial");
        }
        
        // Ejecutar SP Judicial
        $periodo = extraerPeriodoDelNombre($nombre_original);
        debug_log("⚙️ Ejecutando SP Judicial con periodo: $periodo");
        
        $sql_sp = "EXEC sp_CargarJudicialBase @PeriodoProceso = ?, @ArchivoOrigen = ?, @UsuarioCarga = ?";
        $params_sp = [$periodo, $nombre_original, $usuario];
        
        $result_sp = $db->secure_query($sql_sp, $params_sp);
        
        if (!$result_sp) {
            $errors = sqlsrv_errors();
            throw new Exception("Error ejecutando SP Judicial: " . ($errors[0]['message'] ?? 'Error desconocido'));
        }
        
        if (sqlsrv_has_rows($result_sp)) {
            $row_sp = sqlsrv_fetch_array($result_sp, SQLSRV_FETCH_ASSOC);
            debug_log("📊 Resultado SP Judicial: " . json_encode($row_sp));
            
            if ($row_sp['resultado'] === 'EXITO') {
                // 🔥 CORRECCIÓN CRÍTICA: AGREGAR REGISTRO EN CONTROL_CARGAS_MENSUALES PARA JUDICIAL
                $registro_ok = registrarControlCargaMensual(
                    'JUDICIAL_BASE', 
                    $periodo, 
                    $nombre_original, 
                    $usuario, 
                    $total_temporal, 
                    'COMPLETADO'
                );
                
                if ($registro_ok) {
                    debug_log("✅ CONTROL_CARGAS: Registro exitoso en Control_Cargas_Mensuales");
                } else {
                    debug_log("⚠️ CONTROL_CARGAS: No se pudo registrar en Control_Cargas_Mensuales, pero la carga fue exitosa");
                }
                
                return [
                    'success' => true,
                    'registros_procesados' => $total_temporal,
                    'mensaje' => $row_sp['mensaje'],
                    'filas_con_error' => $filas_con_error
                ];
            } else {
                throw new Exception("SP Judicial reportó error: " . $row_sp['mensaje']);
            }
        } else {
            throw new Exception("No se pudo obtener resultado del SP Judicial");
        }
        
    } catch (Exception $e) {
        debug_log("💥 ERROR EN PROCESAMIENTO JUDICIAL: " . $e->getMessage());
        
        // 🔥 REGISTRAR ERROR EN CONTROL_CARGAS_MENSUALES PARA JUDICIAL
        $periodo = extraerPeriodoDelNombre($nombre_original);
        registrarControlCargaMensual('JUDICIAL_BASE', $periodo, $nombre_original, $usuario, 0, 'ERROR');
        
        throw $e;
    }
}

// =============================================
// FUNCIONES ORIGINALES PARA ASIGNACION STOCK (QUE FUNCIONABAN)
// =============================================
function procesarAsignacionStock($archivo_temporal, $nombre_original, $usuario, $log_id) {
    global $db;
    
    debug_log("🚀 INICIANDO PROCESAMIENTO ASIGNACION STOCK");
    
    try {
        $spreadsheet = IOFactory::load($archivo_temporal);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();
        
        debug_log("📊 Total de filas en Excel: " . count($data));
        
        if (count($data) < 2) {
            throw new Exception("El archivo Excel no contiene datos");
        }
        
        $headers = $data[0];
        debug_log("📋 Headers stock encontrados: " . implode(', ', array_slice($headers, 0, 10)));
        
        // Validar headers requeridos para stock
        $headers_faltantes = [];
        $columnas_requeridas = ['PERIODO_PROCESO', 'RUT', 'DV', 'CONTRATO', 'NOMBRE', 'FECHA_CASTIGO', 'SALDO_GENERADO', 'CLASIFICACION_BIENES', 'CANAL'];
        
        foreach ($columnas_requeridas as $columna_requerida) {
            if (!in_array($columna_requerida, $headers)) {
                $headers_faltantes[] = $columna_requerida;
            }
        }

        if (!empty($headers_faltantes)) {
            throw new Exception("Faltan columnas requeridas en el Excel stock: " . implode(', ', $headers_faltantes));
        }
        
        // Limpiar tabla temporal
        debug_log("🧹 Limpiando tabla temporal stock...");
        $db->secure_query("TRUNCATE TABLE Carga_Temporal_AsignacionStock");
        
        // Procesar filas usando el método original que funcionaba
        $filas_procesadas = 0;
        $filas_con_error = 0;
        $filas_omitidas = 0;
        
        for ($i = 1; $i < count($data); $i++) {
            $fila = $data[$i];
            
            // Saltar filas vacías
            if (empty(array_filter($fila, function($v) { return $v !== null && $v !== '' && $v !== ' '; }))) {
                $filas_omitidas++;
                continue;
            }
            
            // Procesar fila con el método original
            $fila_procesada = procesarFilaStockOriginal($fila, $headers, $nombre_original, $usuario);
            
            if ($fila_procesada) {
                $resultado = insertarFilaTemporalStock($fila_procesada);
                
                if ($resultado === true) {
                    $filas_procesadas++;
                } else {
                    $filas_con_error++;
                    // 🔥 AGREGAR REGISTRO DE ERROR
                    registrarErrorCarga(
                        $log_id, 
                        $fila_procesada['contrato'] ?? 'N/A', 
                        $fila_procesada['rut'] ?? 'N/A', 
                        $resultado, // mensaje de error
                        $fila_procesada
                    );
                }
            } else {
                $filas_con_error++;
                // 🔥 AGREGAR REGISTRO DE ERROR PARA FILAS NO PROCESADAS
                registrarErrorCarga(
                    $log_id, 
                    'N/A', 
                    'N/A', 
                    'Fila no procesada - datos incompletos o inválidos',
                    $fila // datos originales de la fila
                );
            }
            
            if ($i % 100 === 0) {
                debug_log("📈 Progreso Stock: $i/" . (count($data)-1) . " - OK: $filas_procesadas - Error: $filas_con_error");
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        }
        
        debug_log("📊 RESUMEN STOCK: $filas_procesadas filas procesadas, $filas_con_error filas con error, $filas_omitidas omitidas");
        
        // Verificar datos en temporal
        $sql_contar = "SELECT COUNT(*) as total FROM Carga_Temporal_AsignacionStock";
        $stmt = $db->secure_query($sql_contar);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $total_temporal = $row['total'];
        
        debug_log("📦 Registros en tabla temporal stock: " . $total_temporal);
        
        if ($total_temporal === 0) {
            throw new Exception("No hay datos válidos en la tabla temporal de stock");
        }
        
        // Ejecutar SP Stock
        $periodo = extraerPeriodoDelNombreStock($nombre_original);
        debug_log("⚙️ Ejecutando SP Stock con periodo: $periodo");
        
        $sql_sp = "EXEC sp_CargarAsignacionStock @PeriodoProceso = ?, @ArchivoOrigen = ?, @UsuarioCarga = ?";
        $params_sp = [$periodo, $nombre_original, $usuario];
        
        $result_sp = $db->secure_query($sql_sp, $params_sp);
        
        if (!$result_sp) {
            $errors = sqlsrv_errors();
            throw new Exception("Error ejecutando SP Stock: " . ($errors[0]['message'] ?? 'Error desconocido'));
        }
        
        if (sqlsrv_has_rows($result_sp)) {
            $row_sp = sqlsrv_fetch_array($result_sp, SQLSRV_FETCH_ASSOC);
            debug_log("📊 Resultado SP Stock: " . json_encode($row_sp));
            
            if ($row_sp['resultado'] === 'EXITO') {
                // 🔥 AGREGAR REGISTRO EN CONTROL_CARGAS_MENSUALES
                $registro_ok = registrarControlCargaMensual(
                    'ASIGNACION_STOCK', 
                    $periodo, 
                    $nombre_original, 
                    $usuario, 
                    $total_temporal, 
                    'COMPLETADO'
                );
                
                if ($registro_ok) {
                    debug_log("✅ CONTROL_CARGAS: Registro exitoso en Control_Cargas_Mensuales");
                } else {
                    debug_log("⚠️ CONTROL_CARGAS: No se pudo registrar en Control_Cargas_Mensuales, pero la carga fue exitosa");
                }
                
                return [
                    'success' => true,
                    'registros_procesados' => $total_temporal,
                    'mensaje' => $row_sp['mensaje'],
                    'filas_con_error' => $filas_con_error
                ];
            
            } else {
                throw new Exception("SP Stock reportó error: " . $row_sp['mensaje']);
            }
        } else {
            throw new Exception("No se pudo obtener resultado del SP Stock");
        }
        
    } catch (Exception $e) {
        debug_log("💥 ERROR EN PROCESAMIENTO STOCK: " . $e->getMessage());
        
        // 🔥 REGISTRAR ERROR EN CONTROL_CARGAS_MENSUALES
        $periodo = extraerPeriodoDelNombreStock($nombre_original);
        registrarControlCargaMensual('ASIGNACION_STOCK', $periodo, $nombre_original, $usuario, 0, 'ERROR');
        
        throw $e;
    }
}

// =============================================
// FUNCIONES ORIGINALES DE MAPEO STOCK
// =============================================
function mapearColumnaBDStock($columna_excel) {
    $mapeo = [
        // ASIGNACION STOCK
        'PERIODO_PROCESO' => 'periodo_proceso',
        'periodo_proceso' => 'periodo_proceso',
        'FECHA_PROCESO' => 'fecha_proceso',
        'fecha_proceso' => 'fecha_proceso',
        'PERIODO_CASTIGO' => 'periodo_castigo',
        'periodo_castigo' => 'periodo_castigo',
        'RUT' => 'rut',
        'rut' => 'rut',
        'DV' => 'dv',
        'dv' => 'dv',
        'CONTRATO' => 'contrato',
        'contrato' => 'contrato',
        'NOMBRE' => 'nombre',
        'nombre' => 'nombre',
        'PATERNO' => 'paterno',
        'paterno' => 'paterno',
        'MATERNO' => 'materno',
        'materno' => 'materno',
        'FECHA_CASTIGO' => 'fecha_castigo',
        'fecha_castigo' => 'fecha_castigo',
        'SALDO_GENERADO' => 'saldo_generado',
        'saldo_generado' => 'saldo_generado',
        'CLASIFICACION_BIENES' => 'clasificacion_bienes',
        'clasificacion_bienes' => 'clasificacion_bienes',
        'CANAL' => 'canal',
        'canal' => 'canal',
        'CLASIFICACION' => 'clasificacion',
        'clasificacion' => 'clasificacion',
        'DIRECCION' => 'direccion',
        'direccion' => 'direccion',
        'NUMERACION_DIR' => 'numeracion_dir',
        'numeracion_dir' => 'numeracion_dir',
        'RESTO' => 'resto',
        'resto' => 'resto',
        'REGION' => 'region',
        'region' => 'region',
        'COMUNA' => 'comuna',
        'comuna' => 'comuna',
        'CIUDAD' => 'ciudad',
        'ciudad' => 'ciudad',
        'ABOGADO' => 'abogado',
        'abogado' => 'abogado',
        'ZONA' => 'zona',
        'zona' => 'zona',
        'CORREO1' => 'correo1',
        'correo1' => 'correo1',
        'CORREO2' => 'correo2',
        'correo2' => 'correo2',
        'CORREO3' => 'correo3',
        'correo3' => 'correo3',
        'CORREO4' => 'correo4',
        'correo4' => 'correo4',
        'CORREO5' => 'correo5',
        'correo5' => 'correo5',
        'TELEFONO1' => 'telefono1',
        'telefono1' => 'telefono1',
        'TELEFONO2' => 'telefono2',
        'telefono2' => 'telefono2',
        'TELEFONO3' => 'telefono3',
        'telefono3' => 'telefono3',
        'TELEFONO4' => 'telefono4',
        'telefono4' => 'telefono4',
        'TELEFONO5' => 'telefono5',
        'telefono5' => 'telefono5',
        'FECHA_PAGO' => 'fecha_pago',
        'fecha_pago' => 'fecha_pago',
        'MONTO_PAGO' => 'monto_pago',
        'monto_pago' => 'monto_pago',
        'FECHA_VENCIMIENTO' => 'fecha_vencimiento',
        'fecha_vencimiento' => 'fecha_vencimiento',
        'DIAS_MORA' => 'dias_mora',
        'dias_mora' => 'dias_mora',
        'FECHA_SUSC' => 'fecha_susc',
        'fecha_susc' => 'fecha_susc',
        'TIPO_CAMPAÑA' => 'tipo_campana',
        'TIPO_CAMPANA' => 'tipo_campana',
        'tipo_campana' => 'tipo_campana',
        'DESCUENTO' => 'descuento',
        'descuento' => 'descuento',
        'MONTO_A_PAGAR' => 'monto_a_pagar',
        'monto_a_pagar' => 'monto_a_pagar',
        'SALDO_EN_CAMPAÑA' => 'saldo_en_campana',
        'SALDO_EN_CAMPANA' => 'saldo_en_campana',
        'saldo_en_campana' => 'saldo_en_campana',
        'FECHA_ASIGNACION' => 'fecha_asignacion',
        'fecha_asignacion' => 'fecha_asignacion',
        'TIPO_CARTERA' => 'tipo_cartera',
        'tipo_cartera' => 'tipo_cartera'
    ];
    
    return $mapeo[$columna_excel] ?? null;
}

function obtenerTipoCampoStock($columna_bd) {
    $tipos = [
        // ASIGNACION STOCK
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
        'dias_mora' => 'int',
        'telefono1' => 'string',
        'telefono2' => 'string',
        'telefono3' => 'string',
        'telefono4' => 'string',
        'telefono5' => 'string',
        'correo1' => 'string',
        'correo2' => 'string',
        'correo3' => 'string',
        'correo4' => 'string',
        'correo5' => 'string',
        'direccion' => 'string',
        'numeracion_dir' => 'string',
        'resto' => 'string',
        'region' => 'string',
        'comuna' => 'string',
        'ciudad' => 'string',
        'abogado' => 'string',
        'zona' => 'string',
        'fecha_vencimiento' => 'date',
        'fecha_susc' => 'date',
        'fecha_asignacion' => 'date',
        'fecha_pago' => 'date',
        'tipo_cartera' => 'string',
        'tipo_campana' => 'string'
    ];
    
    return $tipos[$columna_bd] ?? 'string';
}

function registrarErrorCarga($log_carga_id, $contrato, $rut, $mensaje_error, $datos_fila = null) {
    global $db;
    try {
        $sql = "INSERT INTO Logs_Errores_Carga (log_carga_id, contrato, rut, mensaje_error, datos_fila) 
                VALUES (?, ?, ?, ?, ?)";
        $datos_json = $datos_fila ? json_encode($datos_fila) : null;
        $params = [$log_carga_id, $contrato, $rut, $mensaje_error, $datos_json];
        $result = $db->secure_query($sql, $params);
        return $result !== false;
    } catch (Exception $e) {
        debug_log("💥 Error registrando error en BD: " . $e->getMessage());
        return false;
    }
}

function procesarFilaStockOriginal($fila, $headers, $archivo_origen, $usuario) {
    $valores = [
        'fecha_carga' => date('Y-m-d H:i:s'),
        'archivo_origen' => $archivo_origen,
        'usuario_carga' => $usuario
    ];
    
    // Mapear valores del Excel - MÉTODO ORIGINAL
    foreach ($headers as $index => $header) {
        if (isset($fila[$index]) && $fila[$index] !== null && $fila[$index] !== '') {
            $columna_bd = mapearColumnaBDStock($header);
            
            if ($columna_bd) {
                $tipo_campo = obtenerTipoCampoStock($columna_bd);
                
                if ($tipo_campo === 'date') {
                    $valores[$columna_bd] = convertirFechaExcel($fila[$index], $columna_bd);
                } else {
                    $valores[$columna_bd] = limpiarValorStock($fila[$index], $tipo_campo);
                }
            }
        }
    }
    
    // 🔥 CORRECCIÓN CRÍTICA: Asegurar que 'materno' nunca sea NULL
    if (!isset($valores['materno']) || $valores['materno'] === null || $valores['materno'] === '') {
        $valores['materno'] = ''; // Asignar string vacío en lugar de NULL
        debug_log("🔧 STOCK - Campo 'materno' vacío, asignando string vacío");
    }
    
    // VALIDACIÓN CAMPOS OBLIGATORIOS STOCK
    $campos_obligatorios = ['periodo_proceso', 'contrato', 'rut', 'dv', 'nombre'];
    foreach ($campos_obligatorios as $campo) {
        if (!isset($valores[$campo]) || $valores[$campo] === null || $valores[$campo] === '') {
            debug_log("❌ STOCK - FALTA CAMPO OBLIGATORIO: $campo");
            return false;
        }
    }
    
    return $valores;
}

function registrarControlCargaMensual($tipo_archivo, $periodo, $archivo_origen, $usuario_carga, $registros_procesados, $estado) {
    global $db;
    
    debug_log("📝 CONTROL_CARGAS: Intentando registrar - $tipo_archivo - $periodo - $estado");
    
    try {
        $sql = "INSERT INTO Control_Cargas_Mensuales 
                (tipo_archivo, periodo, archivo_origen, usuario_carga, registros_procesados, estado, fecha_carga, fecha_creacion) 
                VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())";
        
        $params = [$tipo_archivo, $periodo, $archivo_origen, $usuario_carga, $registros_procesados, $estado];
        $result = $db->secure_query($sql, $params);
        
        if ($result) {
            debug_log("✅ CONTROL_CARGAS: REGISTRADO EXITOSAMENTE - $tipo_archivo - $periodo - $registros_procesados registros - $estado");
            return true;
        } else {
            $errors = sqlsrv_errors();
            $error_msg = $errors ? $errors[0]['message'] : 'Error desconocido';
            debug_log("❌ CONTROL_CARGAS: ERROR al insertar - " . $error_msg);
            return false;
        }
        
    } catch (Exception $e) {
        debug_log("💥 CONTROL_CARGAS: EXCEPCIÓN - " . $e->getMessage());
        return false;
    }
}

function limpiarValorStock($valor, $tipo_campo = 'string') {
    if ($valor === null || $valor === '' || $valor === ' ') {
        return null;
    }
    
    $valor = trim($valor);
    
    // Para DV: '0' es válido
    if ($tipo_campo === 'string' && $valor === '0') {
        return '0';
    }
    
    // Campos numéricos
    if ($tipo_campo === 'int' || $tipo_campo === 'decimal') {
        if (is_string($valor)) {
            $valor_limpio = str_replace([',', ' ', '$', 'CLP', 'USD'], '', $valor);
            
            if ($valor_limpio === '') {
                return null;
            }
            
            if (is_numeric($valor_limpio)) {
                if ($tipo_campo === 'int') {
                    return (int)$valor_limpio;
                } else {
                    return (float)$valor_limpio;
                }
            }
            return null;
        }
        
        if (is_numeric($valor)) {
            if ($tipo_campo === 'int') {
                return (int)$valor;
            } else {
                return (float)$valor;
            }
        }
        
        return null;
    }
    
    return $valor;
}

function insertarFilaTemporalStock($fila_procesada) {
    global $db;
    
    try {
        $columnas = array_keys($fila_procesada);
        $placeholders = array_fill(0, count($columnas), '?');
        $params = array_values($fila_procesada);
        
        $sql = "INSERT INTO Carga_Temporal_AsignacionStock (" . implode(', ', $columnas) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $result = $db->secure_query($sql, $params);
        
        if ($result) {
            return true;
        } else {
            // 🔥 DEVOLVER MENSAJE DE ERROR ESPECÍFICO
            $errors = sqlsrv_errors();
            $error_msg = "Error SQL desconocido";
            if ($errors) {
                $error_msg = $errors[0]['message'];
            }
            return $error_msg;
        }
        
    } catch (Exception $e) {
        $error_msg = "EXCEPCIÓN: " . $e->getMessage();
        debug_log("💥 Error insertando fila stock: " . $error_msg);
        return $error_msg;
    }
}

// =============================================
// FUNCIONES COMUNES
// =============================================
function registrarLog($tipo_archivo, $nombre_archivo, $nombre_original, $usuario, $estado, $registros = null, $error = null) {
    global $db;
    
    try {
        $sql = "INSERT INTO Logs_Carga_Excel (tipo_archivo, nombre_archivo, nombre_original, usuario_carga, estado, registros_procesados, error_mensaje) 
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $params = [$tipo_archivo, $nombre_archivo, $nombre_original, $usuario, $estado, $registros, $error];
        $stmt = $db->secure_query($sql, $params);
        
        if ($stmt && sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            return $row['id'];
        } else {
            return 1;
        }
        
    } catch (Exception $e) {
        debug_log("💥 Excepción en log: " . $e->getMessage());
        return 1;
    }
}

function actualizarLog($log_id, $estado, $registros = null, $error = null) {
    global $db;
    
    try {
        $sql = "UPDATE Logs_Carga_Excel SET estado = ?, registros_procesados = ?, error_mensaje = ? WHERE id = ?";
        $params = [$estado, $registros, $error, $log_id];
        $result = $db->secure_query($sql, $params);
        
        return true;
    } catch (Exception $e) {
        debug_log("💥 Error actualizando log: " . $e->getMessage());
        return false;
    }
}

function convertirFechaExcel($valor, $campo = '') {
    if (empty($valor) || $valor === 'NULL' || $valor === 'null' || $valor === ' ') {
        return null;
    }
    
    // Si es numérico (fecha Excel)
    if (is_numeric($valor)) {
        if ($valor > 25569) {
            try {
                $fecha = Date::excelToDateTimeObject($valor);
                return $fecha->format('Y-m-d');
            } catch (Exception $e) {
                return null;
            }
        }
        return null;
    }
    
    // Si es string con formato fecha
    if (is_string($valor)) {
        $valor = trim($valor);
        
        // Formato mm/dd/yyyy
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $valor)) {
            $fecha = DateTime::createFromFormat('m/d/Y', $valor);
            if ($fecha !== false) {
                return $fecha->format('Y-m-d');
            }
            return null;
        }
        
        // Formato yyyy-mm-dd
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $valor)) {
            $fecha = DateTime::createFromFormat('Y-m-d', $valor);
            if ($fecha !== false) {
                return $valor;
            }
            return null;
        }
    }
    
    return null;
}

function extraerPeriodoDelNombreStock($nombre_archivo) {
    // Para archivos tipo "Asignacion 202511 - MAB.xlsx"
    if (preg_match('/(\d{6})/', $nombre_archivo, $matches)) {
        return $matches[1];
    }
    
    return date('Ym');
}

function extraerPeriodoDelNombre($nombre_archivo) {
    // Para archivos judiciales tipo "MAB - TCJ CAR PAGARE APERTURA 02-10-2025.xlsx"
    if (preg_match('/(\d{2})-(\d{2})-(\d{4})/', $nombre_archivo, $matches)) {
        $mes = $matches[2];
        $año = $matches[3];
        return $año . $mes;
    }
    
    // Para archivos tipo "Asignacion 202510 - MAB.xlsx"
    if (preg_match('/(\d{6})/', $nombre_archivo, $matches)) {
        return $matches[1];
    }
    
    return date('Ym');
}

// =============================================
// FUNCIONES JUDICIAL (MANTENER)
// =============================================
function procesarFilaJudicial($fila, $headers, $archivo_origen, $usuario) {
    $valores = [
        'fecha_carga' => date('Y-m-d H:i:s'),
        'archivo_origen' => $archivo_origen,
        'usuario_carga' => $usuario
    ];
    
    // Mapeo directo de columnas judiciales
    $mapeo_directo = [
        'periodo' => 'periodo',
        'juicio_id' => 'juicio_id', 
        'contrato' => 'contrato',
        'rut' => 'rut',
        'dv' => 'dv',
        'nombre' => 'nombre',
        'fecha_asignacion' => 'fecha_asignacion',
        'saldo' => 'saldo',
        'cuantia' => 'cuantia',
        'sucursal' => 'sucursal',
        'region' => 'region',
        'comuna' => 'comuna',
        'zona' => 'zona',
        'tribunal' => 'tribunal',
        'rol' => 'rol',
        'año' => 'año',
        'ciudad_tribunal' => 'ciudad_tribunal',
        'homologacion' => 'homologacion',
        'motivo_de_estado' => 'motivo_de_estado',
        'etapa_procesal' => 'etapa_procesal',
        'sub_etapa_procesal' => 'sub_etapa_procesal',
        'fecha_sub_etapa_procesal' => 'fecha_sub_etapa_procesal',
        'ultima_gestion' => 'ultima_gestion',
        'observaciones' => 'observaciones',
        'abogado_externo' => 'abogado_externo',
        'vigente_castigo' => 'vigente_castigo',
        'stock_flujo' => 'stock_flujo',
        'aux' => 'aux',
        'canal' => 'canal',
        'nombre_cobrador' => 'nombre_cobrador',
        'tramo_saldo' => 'tramo_saldo',
        'tramo_antiguedad_castigo' => 'tramo_antiguedad_castigo',
        'recovery' => 'recovery',
        'pago_vigente' => 'pago_vigente',
        'tramo_probabilidad_de_pago' => 'tramo_probabilidad_de_pago',
        'probabilidad_de_pago' => 'probabilidad_de_pago',
        'campana' => 'campana',
        'ciudad' => 'ciudad',
        'marca_piloto' => 'marca_piloto',
        'tipo_contrato' => 'tipo_contrato'
    ];
    
    foreach ($headers as $index => $header) {
        if (!isset($fila[$index]) || $fila[$index] === null || $fila[$index] === '') {
            continue;
        }
        
        $header_limpio = trim($header);
        $header_normalizado = strtolower($header_limpio);
        $header_normalizado = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $header_normalizado
        );
        $header_normalizado = preg_replace('/[^a-z0-9_\s]/', '', $header_normalizado);
        $header_normalizado = str_replace(' ', '_', $header_normalizado);
        
        if (isset($mapeo_directo[$header_normalizado])) {
            $columna_bd = $mapeo_directo[$header_normalizado];
            $valor = $fila[$index];
            
            // Conversión especial para periodo
            if ($columna_bd === 'periodo') {
                $valores[$columna_bd] = convertirPeriodoAAAAMM($valor);
            } 
            // Conversión para fechas
            elseif (strpos($columna_bd, 'fecha') !== false) {
                $valores[$columna_bd] = convertirFechaExcel($valor);
            }
            // Para campos numéricos
            elseif (in_array($columna_bd, ['saldo', 'cuantia', 'recovery', 'pago_vigente', 'probabilidad_de_pago'])) {
                $valores[$columna_bd] = limpiarValorStock($valor, 'decimal');
            }
            // Para campos string
            else {
                $valores[$columna_bd] = $valor;
            }
        }
    }
    
    // VALIDACIÓN CAMPOS OBLIGATORIOS JUDICIAL
    $campos_obligatorios = ['periodo', 'contrato', 'rut', 'dv', 'nombre'];
    foreach ($campos_obligatorios as $campo) {
        if (!isset($valores[$campo]) || $valores[$campo] === null || $valores[$campo] === '') {
            debug_log("❌ JUDICIAL - FALTA CAMPO OBLIGATORIO: $campo");
            return false;
        }
    }
    
    return $valores;
}

function convertirPeriodoAAAAMM($valor_periodo) {
    if (empty($valor_periodo) || $valor_periodo === 'NULL' || $valor_periodo === 'null') {
        return null;
    }
    
    $valor_periodo = trim($valor_periodo);
    
    // Si ya está en formato AAAAMM (6 dígitos numéricos)
    if (preg_match('/^\d{6}$/', $valor_periodo)) {
        return $valor_periodo;
    }
    
    // Si viene como fecha string (10/1/2025)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $valor_periodo, $matches)) {
        $mes = intval($matches[1]);
        $dia = intval($matches[2]);
        $año = intval($matches[3]);
        
        if ($mes < 1 || $mes > 12) {
            return null;
        }
        
        return sprintf('%04d%02d', $año, $mes);
    }
    
    // Si es numérico (fecha Excel)
    if (is_numeric($valor_periodo)) {
        try {
            $fecha = Date::excelToDateTimeObject($valor_periodo);
            return $fecha->format('Ym');
        } catch (Exception $e) {
            return null;
        }
    }
    
    return null;
}

function insertarFilaTemporalJudicial($fila_procesada) {
    global $db;
    
    try {
        $columnas = array_keys($fila_procesada);
        $placeholders = array_fill(0, count($columnas), '?');
        $params = array_values($fila_procesada);
        
        $sql = "INSERT INTO Carga_Temporal_JudicialBase (" . implode(', ', $columnas) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $result = $db->secure_query($sql, $params);
        
        return $result !== false;
        
    } catch (Exception $e) {
        debug_log("💥 Error insertando fila judicial: " . $e->getMessage());
        return false;
    }
}

// =============================================
// PROCESAMIENTO PRINCIPAL
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        debug_log("=================== NUEVA SOLICITUD ===================");
        
        if (!isset($_FILES['archivo_excel'])) {
            throw new Exception('No se recibió ningún archivo');
        }
        
        $tipo_archivo = $_POST['tipo_archivo'] ?? '';
        debug_log("🎯 Tipo de archivo: " . $tipo_archivo);
        
        if (!array_key_exists($tipo_archivo, $TIPOS_ARCHIVO)) {
            throw new Exception('Tipo de archivo no válido: ' . $tipo_archivo);
        }
        
        // Validaciones básicas
        $nombre_original = $_FILES['archivo_excel']['name'];
        $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        
        if (!in_array($extension, ['xlsx', 'xls'])) {
            throw new Exception('Solo se permiten archivos Excel (.xlsx, .xls)');
        }
        
        // Guardar archivo
        $timestamp = date('Ymd_His');
        $nombre_archivo = $timestamp . '_' . uniqid() . '_' . $nombre_original;
        $ruta_destino = '../../files/uploads/' . $nombre_archivo;
        
        // Crear directorio si no existe
        $upload_dir = dirname($ruta_destino);
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        if (!move_uploaded_file($_FILES['archivo_excel']['tmp_name'], $ruta_destino)) {
            throw new Exception('Error al guardar el archivo en el servidor');
        }
        
        debug_log("✅ Archivo guardado: " . $ruta_destino);
        
        // Registrar log
        $log_id = registrarLog($tipo_archivo, $nombre_archivo, $nombre_original, $_SESSION['username'], 'PROCESANDO');
        
        // Procesar según el tipo de archivo
        if ($tipo_archivo === 'JUDICIAL_BASE') {
            $resultado = procesarJudicialSimple($ruta_destino, $nombre_original, $_SESSION['username'], $log_id);
        } elseif ($tipo_archivo === 'ASIGNACION_STOCK') {
            $resultado = procesarAsignacionStock($ruta_destino, $nombre_original, $_SESSION['username'], $log_id);
        } else {
            throw new Exception('Tipo de archivo no soportado: ' . $tipo_archivo);
        }
        
        // Actualizar log con resultado
        actualizarLog($log_id, 'COMPLETADO', $resultado['registros_procesados']);
        
        debug_log("✅ PROCESAMIENTO EXITOSO: " . $resultado['mensaje']);
        echo json_encode($resultado);
        
    } catch (Exception $e) {
        debug_log("💥 ERROR EN SOLICITUD: " . $e->getMessage());
        
        // Actualizar log con error
        if (isset($log_id)) {
            actualizarLog($log_id, 'ERROR', 0, $e->getMessage());
        }
        
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ]);
}

debug_log("=================== FIN SOLICITUD ===================\n");