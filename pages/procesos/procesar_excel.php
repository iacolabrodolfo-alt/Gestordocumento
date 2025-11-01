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
// SISTEMA DE LOGGING OPTIMIZADO
// =============================================
function debug_log($message, $data = null) {
    // Solo loguear errores y eventos importantes
    $log_important = [
        'ERROR' => true,
        'EXCEPCIÓN' => true,
        'INICIANDO' => true,
        'FINALIZANDO' => true,
        'PROCESAMIENTO' => true,
        'RESUMEN' => true,
        'STORED PROCEDURE' => true,
        'VALIDACIÓN' => true,
        'SOLICITUD' => true,
        'ARCHIVO' => true,
        'HEADERS' => true,
        'TEMPORAL' => true,
        'PROGRESO' => true,
        'REGISTROS' => true,
        'FALTANTES' => true,
        'OMITIDA' => true,
        'CONVERSIÓN FALLIDA' => true,
        'FECHA PROBLEMA' => true,
        'DV PROBLEMA' => true,
        'MATERNO PROBLEMA' => true
    ];
    
    $is_important = false;
    foreach (array_keys($log_important) as $keyword) {
        if (stripos($message, $keyword) !== false) {
            $is_important = true;
            break;
        }
    }
    
    // Solo loguear conversiones exitosas cada 500 filas
    static $conversion_counter = 0;
    $is_conversion_success = strpos($message, '✅ Valor convertido') !== false || 
                            strpos($message, '✅ Fecha convertida') !== false;
    
    if ($is_conversion_success) {
        $conversion_counter++;
        if ($conversion_counter % 500 !== 0) {
            return;
        }
    }
    
    $log_file = __DIR__ . '/../../debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] {$message}";
    
    if ($data !== null && $is_important) {
        if (is_array($data)) {
            $log_message .= " | Data: " . json_encode($data);
        } else {
            $log_message .= " | Data: " . (string)$data;
        }
    }
    
    $log_message .= "\n";
    
    // Limitar tamaño del archivo de log (1MB máximo)
    if (file_exists($log_file) && filesize($log_file) > 1024 * 1024) {
        $lines = file($log_file);
        $keep_lines = array_slice($lines, -1000); // Mantener últimas 1000 líneas
        file_put_contents($log_file, implode('', $keep_lines));
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

function convertirFechaExcel($valor, $campo = '') {
    if (empty($valor) || $valor === 'NULL' || $valor === 'null' || $valor === ' ') {
        debug_log("⚠️ Fecha vacía o nula en campo: $campo");
        return null;
    }
    
    // Si es numérico (fecha Excel - formato serial)
    if (is_numeric($valor)) {
        // Verificar si es una fecha Excel válida (generalmente > 25569 que es 01/01/1970)
        if ($valor > 25569) {
            try {
                $fecha = Date::excelToDateTimeObject($valor);
                $fecha_formateada = $fecha->format('Y-m-d');
                debug_log("✅ Fecha Excel convertida: $valor -> $fecha_formateada (Campo: $campo)");
                return $fecha_formateada;
            } catch (Exception $e) {
                debug_log("⚠️ Error convirtiendo fecha Excel '$valor' (Campo: $campo): " . $e->getMessage());
                return null;
            }
        } else {
            // Si es un número pequeño, probablemente no es una fecha válida
            debug_log("⚠️ Valor numérico pequeño, ignorando como fecha: $valor (Campo: $campo)");
            return null;
        }
    }
    
    // Si es string con formato fecha
    if (is_string($valor)) {
        $valor = trim($valor);
        
        // 🔥 NUEVO: Verificar si es una fecha inválida común
        if (in_array($valor, ['00/00/0000', '0000-00-00', '1900-01-00', 'NULL', 'N/A', 'NaN'])) {
            debug_log("⚠️ Fecha inválida detectada: '$valor' (Campo: $campo)");
            return null;
        }
        
        // Formato mm/dd/yyyy (inglés) - común en Excel
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $valor)) {
            $fecha = DateTime::createFromFormat('m/d/Y', $valor);
            if ($fecha !== false) {
                $fecha_formateada = $fecha->format('Y-m-d');
                debug_log("✅ Fecha string convertida: $valor -> $fecha_formateada (Campo: $campo)");
                return $fecha_formateada;
            } else {
                debug_log("❌ FECHA PROBLEMA - No se pudo convertir: '$valor' (Campo: $campo)");
                return null;
            }
        }
        
        // Formato dd-mm-yyyy
        if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $valor)) {
            $fecha = DateTime::createFromFormat('d-m-Y', $valor);
            if ($fecha !== false) {
                $fecha_formateada = $fecha->format('Y-m-d');
                debug_log("✅ Fecha string convertida: $valor -> $fecha_formateada (Campo: $campo)");
                return $fecha_formateada;
            } else {
                debug_log("❌ FECHA PROBLEMA - No se pudo convertir: '$valor' (Campo: $campo)");
                return null;
            }
        }
        
        // Formato yyyy-mm-dd (ya está bien)
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $valor)) {
            // Validar que sea una fecha real
            $fecha = DateTime::createFromFormat('Y-m-d', $valor);
            if ($fecha !== false) {
                debug_log("✅ Fecha ya en formato correcto: $valor (Campo: $campo)");
                return $valor;
            } else {
                debug_log("❌ FECHA PROBLEMA - Formato correcto pero fecha inválida: '$valor' (Campo: $campo)");
                return null;
            }
        }
        
        debug_log("❌ FECHA PROBLEMA - Formato de fecha no reconocido: '$valor' (Campo: $campo)");
    }
    
    return null; // Devolver null si no se puede convertir
}

function limpiarValor($valor, $tipo_campo = 'string') {
    if ($valor === null || $valor === '' || $valor === ' ') {
        return null;
    }
    
    $valor = trim($valor);
    
    // 🔥 CORRECCIÓN ESPECIAL PARA DV: '0' es un valor válido
    if ($tipo_campo === 'string' && $valor === '0') {
        debug_log("✅ DV con valor '0' - ES VÁLIDO");
        return '0';
    }
    
    // 🔥 CORRECCIÓN: Manejar campos numéricos con comas como separador de miles
    if ($tipo_campo === 'int' || $tipo_campo === 'decimal' || $tipo_campo === 'money') {
        // Si es string, remover comas, espacios y símbolos de moneda
        if (is_string($valor)) {
            $valor_limpio = str_replace([',', ' ', '$', 'CLP', 'USD'], '', $valor);
            
            // Si después de limpiar queda vacío, retornar null
            if ($valor_limpio === '') {
                debug_log("⚠️ Valor numérico vacío después de limpiar: '$valor'");
                return null;
            }
            
            // Verificar si es numérico después de limpiar
            if (is_numeric($valor_limpio)) {
                if ($tipo_campo === 'int') {
                    $valor_final = (int)$valor_limpio;
                    debug_log("✅ Valor convertido a INT: '$valor' -> $valor_final");
                    return $valor_final;
                } else {
                    $valor_final = (float)$valor_limpio;
                    debug_log("✅ Valor convertido a DECIMAL: '$valor' -> $valor_final");
                    return $valor_final;
                }
            } else {
                // 🔥 NUEVO: Log para debugging de valores problemáticos
                debug_log("❌ Valor no numérico después de limpiar: '$valor' -> '$valor_limpio' (Tipo: $tipo_campo)");
                return null;
            }
        }
        
        // Si ya es numérico
        if (is_numeric($valor)) {
            if ($tipo_campo === 'int') {
                return (int)$valor;
            } else {
                return (float)$valor;
            }
        }
        
        // Si no es numérico después de todos los intentos
        debug_log("❌ Valor no convertible a numérico: '$valor' (Tipo: $tipo_campo)");
        return null;
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
            if (empty(array_filter($fila, function($v) { return $v !== null && $v !== '' && $v !== ' '; }))) {
                $filas_omitidas++;
                continue;
            }
            
            // Procesar fila
            $fila_procesada = procesarFilaParaTemporal($fila, $headers, $nombre_original, $usuario);
            
            if ($fila_procesada) {
                $resultado = insertarFilaTemporal($config['tabla_temporal'], $fila_procesada, $log_id);
                
                if ($resultado === true) {
                    $filas_procesadas++;
                } else {
                    $filas_con_error++;
                    // Registrar error específico
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
            }
            
            // Log cada 500 filas
            if ($i % 500 === 0) {
                debug_log("📈 Progreso: $i filas procesadas de " . (count($data) - 1) . " | Exitosas: $filas_procesadas | Errores: $filas_con_error");
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
    
    // Mapear valores del Excel
    foreach ($headers as $index => $header) {
        if (isset($fila[$index])) {
            $columna_bd = mapearColumnaBD(strtoupper(trim($header)));
            if ($columna_bd) {
                $tipo_campo = obtenerTipoCampo($columna_bd);
                
                if ($tipo_campo === 'date') {
                    $valores[$columna_bd] = convertirFechaExcel($fila[$index], $columna_bd);
                } else {
                    $valores[$columna_bd] = limpiarValor($fila[$index], $tipo_campo);
                }
            }
        }
    }
    
    // 🔥 CORRECCIÓN ESPECIAL: Si materno está vacío, asignar string vacío en lugar de null
    if (isset($valores['materno']) && $valores['materno'] === null) {
        debug_log("⚠️ MATERNO PROBLEMA - Campo materno es NULL, asignando string vacío");
        $valores['materno'] = '';
    }
    
    // 🔥 NUEVO: Validar que todas las fechas sean válidas antes de insertar
    $campos_fecha = ['fecha_proceso', 'fecha_castigo', 'fecha_pago', 'fecha_vencimiento', 'fecha_susc', 'fecha_asignacion'];
    foreach ($campos_fecha as $campo_fecha) {
        if (isset($valores[$campo_fecha]) && $valores[$campo_fecha] !== null) {
            // Verificar que la fecha tenga el formato correcto YYYY-MM-DD
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valores[$campo_fecha])) {
                debug_log("❌ FECHA PROBLEMA - Formato incorrecto en $campo_fecha: " . $valores[$campo_fecha]);
                $valores[$campo_fecha] = null; // Forzar a null si el formato es incorrecto
            }
        }
    }
    
    // Validar campos requeridos - CORREGIDO: '0' es un DV válido
    $campos_requeridos = ['periodo_proceso', 'rut', 'dv', 'contrato', 'nombre', 'fecha_castigo', 'saldo_generado', 'clasificacion_bienes', 'canal'];
    $campos_faltantes = [];
    
    foreach ($campos_requeridos as $campo) {
        // 🔥 CORRECCIÓN: Para el campo 'dv', '0' es un valor válido
        if ($campo === 'dv') {
            if (!isset($valores[$campo]) || ($valores[$campo] !== '0' && empty($valores[$campo]))) {
                $campos_faltantes[] = $campo;
                debug_log("❌ DV PROBLEMA - Campo dv vacío o inválido: '" . ($valores[$campo] ?? 'NULL') . "' - Contrato: " . ($valores['contrato'] ?? 'N/A'));
            }
        } else {
            if (empty($valores[$campo])) {
                $campos_faltantes[] = $campo;
            }
        }
    }
    
    if (!empty($campos_faltantes)) {
        debug_log("❌ Fila omitida - Campos requeridos vacíos: " . implode(', ', $campos_faltantes) . " - Contrato: " . ($valores['contrato'] ?? 'N/A'));
        return false;
    }
    
    return $valores;
}

function insertarFilaTemporal($tabla_temporal, $fila_procesada, $log_id) {
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
            // OBTENER DETALLES DEL ERROR SQL
            $errors = sqlsrv_errors();
            $error_msg = "Error SQL desconocido";
            if ($errors) {
                $error_msg = $errors[0]['message'];
                
                // Detectar tipos específicos de errores
                if (strpos($error_msg, 'String or binary data would be truncated') !== false) {
                    $error_msg = "DATOS DEMASIADO LARGOS: " . $error_msg;
                } elseif (strpos($error_msg, 'Cannot insert the value NULL into column') !== false) {
                    // 🔥 NUEVO: Manejo específico para campos NOT NULL
                    if (strpos($error_msg, 'materno') !== false) {
                        $error_msg = "MATERNO PROBLEMA: Campo materno no permite NULL - " . $error_msg;
                        debug_log("🔍 MATERNO PROBLEMA - Forzando string vacío para materno");
                        // Intentar nuevamente con materno como string vacío
                        $fila_procesada['materno'] = '';
                        return insertarFilaTemporal($tabla_temporal, $fila_procesada, $log_id);
                    }
                    $error_msg = "CAMPO NOT NULL: " . $error_msg;
                } elseif (strpos($error_msg, 'Conversion failed when converting date') !== false) {
                    $error_msg = "ERROR DE FECHA: " . $error_msg;
                    // 🔥 NUEVO: Log detallado de las fechas problemáticas
                    debug_log("🔍 ANALIZANDO FECHAS PROBLEMA:");
                    foreach ($fila_procesada as $campo => $valor) {
                        if (strpos($campo, 'fecha') !== false) {
                            debug_log("   📅 $campo: " . ($valor ?? 'NULL'));
                        }
                    }
                } elseif (strpos($error_msg, 'Conversion failed') !== false) {
                    $error_msg = "ERROR DE CONVERSIÓN DE DATOS: " . $error_msg;
                } elseif (strpos($error_msg, 'Violation of PRIMARY KEY') !== false) {
                    $error_msg = "DUPLICADO: " . $error_msg;
                }
            }
            
            debug_log("❌ Error insertando fila en temporal: " . $error_msg);
            debug_log("   📋 Contrato: " . ($fila_procesada['contrato'] ?? 'N/A'));
            debug_log("   📋 RUT: " . ($fila_procesada['rut'] ?? 'N/A'));
            
            return $error_msg; // Devolver mensaje de error específico
        }
        
    } catch (Exception $e) {
        $error_msg = "EXCEPCIÓN: " . $e->getMessage();
        debug_log("💥 Excepción insertando fila: " . $error_msg);
        debug_log("   📋 Contrato: " . ($fila_procesada['contrato'] ?? 'N/A'));
        return $error_msg;
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