<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Api extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        header('Content-Type: application/json');
    }

	// GET /api/version
	public function version() {
		$json = @file_get_contents('/var/www/html/captura/version.json');
		if (!$json) {
			echo json_encode(['success' => false]);
			return;
		}
		echo json_encode(['success' => true, 'data' => json_decode($json, true)]);
	}


    /**
     * Helper: obtiene el filtro de estructura (SC > Brocker > Gerencia)
     * a partir del id_gerencia del usuario logueado.
     * Regresa ['campo' => 'sc'|'brocker'|'gerencia', 'valor' => '...']
     */
    private function obtener_filtro_estructura($id_gerencia) {
        $estruc = $this->db->query("
            SELECT sc, brocker, gerencia FROM estruc_empleados WHERE id_gerencia = ? LIMIT 1
        ", [$id_gerencia])->row_array();

        if (!$estruc) {
            return ['campo' => 'gerencia', 'valor' => ''];
        }

        if (!empty($estruc['sc'])) {
            return ['campo' => 'sc', 'valor' => $estruc['sc']];
        }

        if (!empty($estruc['brocker'])) {
            return ['campo' => 'brocker', 'valor' => $estruc['brocker']];
        }

        return ['campo' => 'gerencia', 'valor' => $estruc['gerencia'] ?? ''];
    }

	// POST /api/login
	public function login() {
		$json = $this->input->raw_input_stream;
		$data = json_decode($json, true);

		$clave      = $data['clave']      ?? '';
		$contrasena = $data['contrasena'] ?? '';

		if (!$clave || !$contrasena) {
			echo json_encode([
				'success' => false,
				'message' => 'Clave y contraseña son requeridos'
			]);
			return;
		}

		$query = $this->db->query("
			SELECT u.clave, u.nombre, u.contrasena, eu.no_empleado, e.staff
			FROM usuarios u
			INNER JOIN empl_usuarios eu ON u.clave = eu.clave
			INNER JOIN carobra.empleados e ON u.clave = e.clave
			WHERE u.clave = ?
			AND u.contrasena = ?
			AND u.activo = 1
			LIMIT 1
		", [$clave, $contrasena]);

		$usuario = $query->row_array();

		if (!$usuario) {
			echo json_encode([
				'success' => false,
				'message' => 'Clave o contraseña incorrectos'
			]);
			return;
		}

		$es_staff = $usuario['staff'] == 1;

		if ($es_staff) {
			$id_nivel    = '12';
			$id_gerencia = null;
		} else {
			$nivel_row = $this->db->query("
				SELECT clave, id_nivel FROM (
					SELECT clave, MAX(id_nivel) as id_nivel
					FROM usuarios_nivel
					WHERE clave = ?
					AND id_nivel IN (1,6,12)
					UNION ALL
					SELECT clave, MAX(id_nivel) as id_nivel
					FROM usuarios_nivel_gerencia
					WHERE clave = ?
				) n
				WHERE id_nivel IS NOT NULL
				ORDER BY FIELD(id_nivel, 12,13,6,5,1,4,3)
				LIMIT 1
			", [$clave, $clave])->row_array();

			$id_nivel = $nivel_row['id_nivel'] ?? null;

			$gerencia_row = $this->db->query("
				SELECT id_gerencia FROM usuarios_gerencia WHERE clave = ? LIMIT 1
			", [$clave])->row_array();

			$id_gerencia = $gerencia_row['id_gerencia'] ?? null;
		}

		// ─── Verificar Spartan ────────────────────────────────────────────────
		$spartan_emp = $this->db->query("
			SELECT e.clave, eu.no_empleado
			FROM carobra.empleados e
			LEFT JOIN empl_usuarios eu ON eu.clave = e.clave
			WHERE e.clave = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			LIMIT 1
		", [$clave])->row_array();

		$es_spartan       = false;
		$es_super_spartan = false;

		if ($spartan_emp) {
			$es_spartan     = true;
			$no_emp_spartan = $spartan_emp['no_empleado'] ?? $usuario['no_empleado'];

			// Auto-insertar en spartan_config si no existe
			$config = $this->db->query("
				SELECT id, es_super FROM spartan_config WHERE clave = ? LIMIT 1
			", [$clave])->row_array();

			if (!$config) {
				$this->db->query("
					INSERT INTO spartan_config (no_empleado, clave, es_super, porcentaje, monto_fijo, activo)
					VALUES (?, ?, 0, 0.00, 0.00, 1)
				", [$no_emp_spartan, $clave]);
				$es_super_spartan = false;
			} else {
				$es_super_spartan = $config['es_super'] == 1;
			}
		}

		$tyc = $this->db->query("
			SELECT acepto_tyc FROM push_tokens WHERE no_empleado = ? LIMIT 1
		", [$usuario['no_empleado']])->row_array();

		$acepto_tyc = $tyc ? intval($tyc['acepto_tyc']) : 0;

		echo json_encode([
			'success' => true,
			'data'    => [
				'clave'            => $usuario['clave'],
				'nombre'           => $usuario['nombre'],
				'no_empleado'      => $usuario['no_empleado'],
				'id_gerencia'      => $id_gerencia,
				'id_nivel'         => $id_nivel,
				'es_staff'         => $es_staff,
				'es_spartan'       => $es_spartan,
				'es_super_spartan' => $es_super_spartan,
				'acepto_tyc' => $usuario['acepto_tyc'] ?? 0,
			]
		]);
	}

    // GET /api/buscar-folio?q=8080
	public function buscar_folio() {
		$q           = $this->input->get('q')           ?? '';
		$id_nivel    = $this->input->get('id_nivel')    ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$id_gerencia = $this->input->get('id_gerencia') ?? '';

		if (strlen(trim($q)) < 2) {
			echo json_encode([
				'success' => false,
				'message' => 'Ingresa al menos 2 caracteres',
				'data'    => []
			]);
			return;
		}

		$where_fecha = " STR_TO_DATE(p.fec_traspaso, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) ";

		if ($id_nivel == '12') {
			$sql = "
				SELECT
					pt.folio, pt.trabajador, pt.empresa, pt.telefono, pt.curp,
					pt.email_trab, pt.cp, p.nss, p.nap, p.fec_traspaso,
					p.no_empleado, p.afo_cedente, p.saldo_estimado,
					CONCAT(p.nap, ' | ', eu.nombre) COLLATE latin1_general_ci AS nap_nombre,
					eu_com.nombre COLLATE latin1_general_ci AS nombre_comisionista_actual,
					(SELECT costo FROM acompaniamiento WHERE folio = p.folio LIMIT 1) AS acompanamiento_actual
				FROM prod_trabajador pt
				LEFT JOIN produccion p ON pt.folio = p.folio
				LEFT JOIN naps n ON p.nap = n.nap
				LEFT JOIN empl_usuarios eu ON eu.no_empleado = n.no_empleado
				LEFT JOIN empl_usuarios eu_com ON eu_com.no_empleado = p.no_empleado
				WHERE pt.folio LIKE ?
				AND p.id_estatus_prod != 6
				AND {$where_fecha}
				LIMIT 20
			";
			$params = ['%' . $q . '%'];

		} elseif ($id_nivel == '3') {
			$sql = "
				SELECT
					pt.folio, pt.trabajador, pt.empresa, pt.telefono, pt.curp,
					pt.email_trab, pt.cp, p.nss, p.nap, p.fec_traspaso,
					p.no_empleado, p.afo_cedente, p.saldo_estimado,
					CONCAT(p.nap, ' | ', eu.nombre) COLLATE latin1_general_ci AS nap_nombre,
					eu_com.nombre COLLATE latin1_general_ci AS nombre_comisionista_actual,
					(SELECT costo FROM acompaniamiento WHERE folio = p.folio LIMIT 1) AS acompanamiento_actual
				FROM prod_trabajador pt
				LEFT JOIN produccion p ON pt.folio = p.folio
				LEFT JOIN naps n ON p.nap = n.nap
				LEFT JOIN empl_usuarios eu ON eu.no_empleado = n.no_empleado
				LEFT JOIN empl_usuarios eu_com ON eu_com.no_empleado = p.no_empleado
				WHERE pt.folio LIKE ?
				AND p.no_empleado = ?
				AND p.id_estatus_prod != 6
				AND {$where_fecha}
				LIMIT 20
			";
			$params = ['%' . $q . '%', $no_empleado];

		} else {
			$filtro = $this->obtener_filtro_estructura($id_gerencia);
			$campo  = $filtro['campo'];
			$valor  = $filtro['valor'];

			$sql = "
				SELECT
					pt.folio, pt.trabajador, pt.empresa, pt.telefono, pt.curp,
					pt.email_trab, pt.cp, p.nss, p.nap, p.fec_traspaso,
					p.no_empleado, p.afo_cedente, p.saldo_estimado,
					CONCAT(p.nap, ' | ', eu.nombre) COLLATE latin1_general_ci AS nap_nombre,
					eu_com.nombre COLLATE latin1_general_ci AS nombre_comisionista_actual,
					(SELECT costo FROM acompaniamiento WHERE folio = p.folio LIMIT 1) AS acompanamiento_actual
				FROM naps n
				LEFT JOIN empl_usuarios eu ON n.no_empleado = eu.no_empleado
				LEFT JOIN estruc_empleados ee ON eu.no_empleado = ee.no_empleado
				INNER JOIN produccion p ON n.nap = p.nap
				INNER JOIN prod_trabajador pt ON pt.folio = p.folio
				LEFT JOIN empl_usuarios eu_com ON eu_com.no_empleado = p.no_empleado
				WHERE pt.folio LIKE ?
				AND ee.{$campo} = ?
				AND n.baja = 0
				AND p.id_estatus_prod != 6
				AND {$where_fecha}
				LIMIT 20
			";
			$params = ['%' . $q . '%', $valor];
		}

		$query = $this->db->query($sql, $params);

		echo json_encode([
			'success' => true,
			'data'    => $query->result_array()
		]);
	}

	// GET /api/validar-folio?folio=8080397776
	public function validar_folio() {
		$folio = $this->input->get('folio') ?? '';

		if (!$folio) {
			echo json_encode([
				'success' => false,
				'message' => 'Folio requerido'
			]);
			return;
		}

		$query = $this->db->query("
			SELECT 1 FROM produccion WHERE folio = ?
			LIMIT 1
		", [$folio]);

		$existe = $query->num_rows() > 0;

		echo json_encode([
			'success' => true,
			'data'    => [
				'existe' => $existe,
				'folio'  => $folio,
			]
		]);
	}

	// GET /api/validar-nap-activo?folio=8080397776
	public function validar_nap_activo() {
		$folio = $this->input->get('folio') ?? '';

		if (!$folio) {
			echo json_encode(['success' => false, 'message' => 'Folio requerido']);
			return;
		}

		$query = $this->db->query("
			SELECT p.nap
			FROM (SELECT folio, nap FROM produccion WHERE folio = ?) p
			INNER JOIN naps n ON (p.nap = n.nap)
			WHERE baja = 1
		", [$folio]);

		if ($query->num_rows() > 0) {
			$nap = $query->row_array()['nap'];

			$this->db->query("
				UPDATE produccion SET id_estatus_prod = 6 WHERE folio = ?
			", [$folio]);

			$this->db->query("
				UPDATE prod_trabajador
				SET observa = CONCAT(observa, ' El NAP de este folio está inhabilitado')
				WHERE folio = ?
			", [$folio]);

			$existe = $this->db->query("
				SELECT * FROM prod_rechazos WHERE folio = ?
			", [$folio]);

			if ($existe->num_rows() == 0) {
				$this->db->query("
					INSERT INTO prod_rechazos VALUES (?, ?, 2, 2806, 0)
				", [$folio, date('d/m/Y')]);
			}

			echo json_encode([
				'success' => true,
				'data'    => [
					'activo'  => false,
					'nap'     => $nap,
					'message' => 'Folio con NAP inhabilitado. No puede ser capturado.',
				]
			]);
			return;
		}

		echo json_encode([
			'success' => true,
			'data'    => [
				'activo'  => true,
				'message' => 'NAP activo',
			]
		]);
	}

	// GET /api/validar-nap?nap=1112027171&fec_traspaso=08/05/2026
	// public function validar_nap() {
	// 	$nap          = $this->input->get('nap')          ?? '';
	// 	$fec_traspaso = $this->input->get('fec_traspaso') ?? '';

	// 	if (!$nap || !$fec_traspaso) {
	// 		echo json_encode(['success' => false, 'message' => 'NAP y fecha de traspaso requeridos']);
	// 		return;
	// 	}

	// 	// Convertir fec_traspaso dd/mm/yyyy → yyyy-mm-dd para comparar
	// 	$partes = explode('/', $fec_traspaso);
	// 	if (count($partes) !== 3) {
	// 		echo json_encode(['success' => false, 'message' => 'Fecha de traspaso inválida']);
	// 		return;
	// 	}
	// 	$fecha_iso = $partes[2] . '-' . $partes[1] . '-' . $partes[0]; // 2026-07-12

	// 	// ─── Buscar el periodo que CONTENGA la fecha del traspaso ───────────
	// 	// Las fechas en fechas_cierre_com son dd/mm/yyyy también
	// 	$periodo = $this->db->query("
	// 		SELECT anio, mes, nom_periodo, fec_ini, fec_fin, folxnap
	// 		FROM fechas_cierre_com
	// 		WHERE STR_TO_DATE(fec_ini, '%d/%m/%Y') <= ?
	// 		AND STR_TO_DATE(fec_fin, '%d/%m/%Y') >= ?
	// 		LIMIT 1
	// 	", [$fecha_iso, $fecha_iso])->row_array();

	// 	// Si no cae en ningún periodo, tomar el más reciente
	// 	if (!$periodo) {
	// 		$periodo = $this->db->query("
	// 			SELECT anio, mes, nom_periodo, fec_ini, fec_fin, folxnap
	// 			FROM fechas_cierre_com
	// 			ORDER BY anio DESC, mes DESC
	// 			LIMIT 1
	// 		")->row_array();
	// 	}

	// 	if (!$periodo) {
	// 		echo json_encode(['success' => false, 'message' => 'No se encontró periodo activo']);
	// 		return;
	// 	}

	// 	$mes  = (int) $periodo['mes'];
	// 	$anio = (int) $periodo['anio'];

	// 	// ─── Contar folios del NAP en ese periodo ───────────────────────────
	// 	$conteo = $this->db->query("
	// 		SELECT COUNT(*) as v
	// 		FROM (
	// 			SELECT folio, fec_traspaso, id_estatus_prod
	// 			FROM produccion
	// 			WHERE nap = ?
	// 		) p
	// 		INNER JOIN (
	// 			SELECT fec_ini, fec_fin
	// 			FROM fechas_cierre_com
	// 			WHERE mes = ? AND anio = ?
	// 		) cf ON (
	// 			DATEDIFF(
	// 				CONCAT(SUBSTR(fec_traspaso,-4),'-',SUBSTR(fec_traspaso,4,2),'-',SUBSTR(fec_traspaso,1,2)),
	// 				CONCAT(SUBSTR(fec_ini,-4),'-',SUBSTR(fec_ini,4,2),'-',SUBSTR(fec_ini,1,2))
	// 			) >= 0
	// 			AND DATEDIFF(
	// 				CONCAT(SUBSTR(fec_traspaso,-4),'-',SUBSTR(fec_traspaso,4,2),'-',SUBSTR(fec_traspaso,1,2)),
	// 				CONCAT(SUBSTR(fec_fin,-4),'-',SUBSTR(fec_fin,4,2),'-',SUBSTR(fec_fin,1,2))
	// 			) <= 0
	// 		)
	// 		LEFT JOIN movilidad m ON (p.folio = m.folio)
	// 		WHERE id_estatus_prod != 6
	// 		AND IFNULL(movilidad, 0) = 1
	// 	", [$nap, $mes, $anio]);

	// 	$total      = (int) $conteo->row_array()['v'];
	// 	$limite     = (int) $periodo['folxnap'];
	// 	$disponible = $limite - $total;
	// 	$advertencia_desde = $limite - 5;

	// 	$valido      = $disponible > 0;
	// 	$advertencia = $total >= $advertencia_desde && $valido;

	// 	echo json_encode([
	// 		'success' => true,
	// 		'data'    => [
	// 			'nap'         => $nap,
	// 			'periodo'     => $periodo['nom_periodo'],
	// 			'mes'         => $periodo['mes'],
	// 			'anio'        => $periodo['anio'],
	// 			'fec_ini'     => $periodo['fec_ini'],
	// 			'fec_fin'     => $periodo['fec_fin'],
	// 			'limite'      => $limite,
	// 			'usados'      => $total,
	// 			'disponible'  => $disponible,
	// 			'valido'      => $valido,
	// 			'advertencia' => $advertencia,
	// 		]
	// 	]);
	// }

	public function validar_nap() {
		$nap          = $this->input->get('nap')          ?? '';
		$fec_traspaso = $this->input->get('fec_traspaso') ?? '';

		if (!$nap || !$fec_traspaso) {
			echo json_encode(['success' => false, 'message' => 'NAP y fecha de traspaso requeridos']);
			return;
		}

		$partes = explode('/', $fec_traspaso);
		if (count($partes) !== 3) {
			echo json_encode(['success' => false, 'message' => 'Fecha de traspaso inválida']);
			return;
		}
		$fecha_iso = $partes[2] . '-' . $partes[1] . '-' . $partes[0];

		// ─── Buscar periodo que contenga la fecha del traspaso ───────────────
		$periodo = $this->db->query("
			SELECT anio, mes, nom_periodo, fec_ini, fec_fin, folxnap
			FROM fechas_cierre_com
			WHERE STR_TO_DATE(fec_ini, '%d/%m/%Y') <= ?
			AND STR_TO_DATE(fec_fin, '%d/%m/%Y') >= ?
			LIMIT 1
		", [$fecha_iso, $fecha_iso])->row_array();

		if (!$periodo) {
			$periodo = $this->db->query("
				SELECT anio, mes, nom_periodo, fec_ini, fec_fin, folxnap
				FROM fechas_cierre_com
				ORDER BY anio DESC, mes DESC
				LIMIT 1
			")->row_array();
		}

		if (!$periodo) {
			echo json_encode(['success' => false, 'message' => 'No se encontró periodo activo']);
			return;
		}

		$mes  = (int) $periodo['mes'];
		$anio = (int) $periodo['anio'];

		// ─── Contar folios del NAP en ese periodo ───────────────────────────
		$conteo = $this->db->query("
			SELECT COUNT(*) as v
			FROM (
				SELECT folio, fec_traspaso, id_estatus_prod
				FROM produccion
				WHERE nap = ?
			) p
			INNER JOIN (
				SELECT fec_ini, fec_fin
				FROM fechas_cierre_com
				WHERE mes = ? AND anio = ?
			) cf ON (
				DATEDIFF(
					CONCAT(SUBSTR(fec_traspaso,-4),'-',SUBSTR(fec_traspaso,4,2),'-',SUBSTR(fec_traspaso,1,2)),
					CONCAT(SUBSTR(fec_ini,-4),'-',SUBSTR(fec_ini,4,2),'-',SUBSTR(fec_ini,1,2))
				) >= 0
				AND DATEDIFF(
					CONCAT(SUBSTR(fec_traspaso,-4),'-',SUBSTR(fec_traspaso,4,2),'-',SUBSTR(fec_traspaso,1,2)),
					CONCAT(SUBSTR(fec_fin,-4),'-',SUBSTR(fec_fin,4,2),'-',SUBSTR(fec_fin,1,2))
				) <= 0
			)
			LEFT JOIN movilidad m ON (p.folio = m.folio)
			WHERE id_estatus_prod != 6
			AND IFNULL(movilidad, 0) = 1
		", [$nap, $mes, $anio]);

		$total = (int) $conteo->row_array()['v'];

		// ─── Límite real del NAP (considera excepciones de estructura) ───────
		$limite_row = $this->db->query("
			SELECT
				CASE
					WHEN n.mes IN (9,10,11) AND n.anio = 2026 THEN
						CASE
							WHEN n.sc = 'VALEAL CONSULTORES'
							OR n.sc = 'JORGE GUERRERO SOTO'
							OR n.brocker = 'CRISTIHAN ISRAEL NUNO GONZALEZ'
							THEN 45
							ELSE fcc.folxnap
						END
					ELSE fcc.folxnap
				END AS folxnap
			FROM (
				SELECT ? AS anio, ? AS mes, ee.sc, ee.brocker, n.nap
				FROM naps n
				INNER JOIN estruc_empleados ee ON n.no_empleado = ee.no_empleado
				WHERE n.baja = 0 AND n.nap = ?
			) n
			INNER JOIN fechas_cierre_com fcc ON (fcc.anio = n.anio AND fcc.mes = n.mes)
			LIMIT 1
		", [$anio, $mes, $nap])->row_array();

		$limite            = $limite_row ? (int) $limite_row['folxnap'] : (int) $periodo['folxnap'];
		$disponible        = $limite - $total;
		$advertencia_desde = $limite - 5;
		$valido            = $disponible > 0;
		$advertencia       = $total >= $advertencia_desde && $valido;

		echo json_encode([
			'success' => true,
			'data'    => [
				'nap'         => $nap,
				'periodo'     => $periodo['nom_periodo'],
				'mes'         => $periodo['mes'],
				'anio'        => $periodo['anio'],
				'fec_ini'     => $periodo['fec_ini'],
				'fec_fin'     => $periodo['fec_fin'],
				'limite'      => $limite,
				'usados'      => $total,
				'disponible'  => $disponible,
				'valido'      => $valido,
				'advertencia' => $advertencia,
			]
		]);
	}

    // GET /api/buscar-nap?q=1112
    public function buscar_nap() {
        $q = $this->input->get('q') ?? '';

        if (strlen(trim($q)) < 2) {
            echo json_encode([
                'success' => false,
                'message' => 'Ingresa al menos 2 caracteres',
                'data'    => []
            ]);
            return;
        }

        $query = $this->db->query("
            SELECT
                n.nap,
                eu.nombre
            FROM naps n
            LEFT JOIN empl_usuarios eu ON n.no_empleado = eu.no_empleado
            WHERE n.nap LIKE ?
               OR eu.nombre LIKE ?
            LIMIT 20
        ", ['%' . $q . '%', '%' . $q . '%']);

        echo json_encode([
            'success' => true,
            'data'    => $query->result_array()
        ]);
    }
	
	// GET /api/dueno-nap?nap=2505003729
	public function dueno_nap() {
		$nap = $this->input->get('nap') ?? '';

		if (!$nap) {
			echo json_encode(['success' => false, 'message' => 'NAP requerido']);
			return;
		}

		$query = $this->db->query("
			SELECT eu.no_empleado, eu.nombre
			FROM naps n
			INNER JOIN empl_usuarios eu ON n.no_empleado = eu.no_empleado
			WHERE n.nap = ?
			LIMIT 1
		", [$nap]);

		$dueno = $query->row_array();

		echo json_encode([
			'success' => true,
			'data'    => $dueno ?? null,
		]);
	}

	// POST /api/subir-evidencia
	public function subir_evidencia() {
		$folio = $this->input->post('folio') ?? '';

		if (!$folio) {
			echo json_encode(['success' => false, 'message' => 'Folio requerido']);
			return;
		}

		if (!isset($_FILES['archivo'])) {
			echo json_encode(['success' => false, 'message' => 'No se recibió ningún archivo']);
			return;
		}

		$archivo    = $_FILES['archivo'];
		$extension  = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
		$permitidas = ['pdf', 'jpg', 'jpeg', 'png'];

		if (!in_array($extension, $permitidas)) {
			echo json_encode(['success' => false, 'message' => 'Tipo de archivo no permitido']);
			return;
		}

		if ($archivo['size'] > 10 * 1024 * 1024) {
			echo json_encode(['success' => false, 'message' => 'El archivo excede 10 MB']);
			return;
		}

		$fecha        = date('Ymd_His');
		$tipo         = ($extension === 'pdf') ? 'documento' : 'foto';
		$nombre_final = "{$folio}_{$fecha}_{$tipo}.{$extension}";

		$carpeta_destino = '/var/www/html/afore/evidencias/' . $folio . '/';

		$clave_usuario = $this->input->post('clave_usuario') ?? '';


		if (!is_dir($carpeta_destino)) {
			mkdir($carpeta_destino, 0775, true);
		}

		$ruta_completa = $carpeta_destino . $nombre_final;

		if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
			echo json_encode(['success' => false, 'message' => 'Error al guardar el archivo']);
			return;
		}

		$ruta_relativa = 'evidencias/' . $folio . '/' . $nombre_final;

		$this->db->query("
			INSERT INTO evidencias (folio, nombre, tipo, ruta, tamanio, clave_usuario)
			VALUES (?, ?, ?, ?, ?, ?)
		", [$folio, $nombre_final, $extension, $ruta_relativa, $archivo['size'], $clave_usuario]);

		$id = $this->db->insert_id();

		echo json_encode([
			'success' => true,
			'data'    => [
				'id'      => $id,
				'nombre'  => $nombre_final,
				'tipo'    => $extension,
				'url'     => 'https://www.sis-ca.com.mx:7863/afore/' . $ruta_relativa,
				'tamanio' => $archivo['size'],
			]
		]);
	}

	// GET /api/evidencias?folio=8080397776
	public function evidencias() {
		$folio = $this->input->get('folio') ?? '';

		if (!$folio) {
			echo json_encode(['success' => false, 'message' => 'Folio requerido']);
			return;
		}

		$query = $this->db->query("
			SELECT id, nombre, tipo, ruta, tamanio, creado_en
			FROM evidencias
			WHERE folio = ?
			ORDER BY creado_en DESC
		", [$folio]);

		$resultados = $query->result_array();

		foreach ($resultados as &$r) {
			$r['url'] = 'https://www.sis-ca.com.mx:7863/afore/' . $r['ruta'];
		}
		unset($r);

		echo json_encode([
			'success' => true,
			'data'    => $resultados
		]);
	}

	// GET /api/validar-comisionista?clave=AAGARCIA
	public function validar_comisionista() {
		$clave = $this->input->get('clave') ?? '';

		if (!$clave) {
			echo json_encode(['success' => false, 'message' => 'Clave requerida']);
			return;
		}

		$query = $this->db->query("
			SELECT COUNT(*) as total
			FROM insuficiencias i
			INNER JOIN (
				SELECT * FROM usuarios_nivel_gerencia WHERE clave = ?
			) ung ON i.clave = ung.clave
			WHERE fec_pago = '' AND ajuste < -200
		", [$clave]);

		$total = (int) $query->row_array()['total'];

		echo json_encode([
			'success' => true,
			'data'    => [
				'tiene_insuficiencias' => $total > 0,
				'total'               => $total,
			]
		]);
	}

    // GET /api/afores
    public function afores() {
        $query = $this->db->query("SELECT clave, afore FROM afores ORDER BY afore ASC");

        echo json_encode([
            'success' => true,
            'data'    => $query->result_array()
        ]);
    }

	// GET /api/validar-bloqueo
	public function validar_bloqueo() {
		$query = $this->db->query("SELECT * FROM bloqueo_pago LIMIT 1");
		
		echo json_encode([
			'success' => true,
			'data'    => [
				'bloqueado' => $query->num_rows() > 0,
			]
		]);
	}

	// GET /api/buscar-comisionista?q=juan
	public function buscar_comisionista() {
		$q = $this->input->get('q') ?? '';

		if (strlen(trim($q)) < 2) {
			echo json_encode([
				'success' => false,
				'message' => 'Ingresa al menos 2 caracteres',
				'data'    => []
			]);
			return;
		}

		$query = $this->db->query("
			SELECT clave, no_empleado, nombre, activo
			FROM empl_usuarios
			WHERE clave LIKE ?
			OR no_empleado LIKE ?
			OR nombre LIKE ?
			LIMIT 20
		", ['%'.$q.'%', '%'.$q.'%', '%'.$q.'%']);

		echo json_encode([
			'success' => true,
			'data'    => $query->result_array()
		]);
	}

	// POST /api/guardar-traspaso
	public function guardar_traspaso() {
		$json = $this->input->raw_input_stream;
		$data = json_decode($json, true);

		$folio           = $data['folio']          ?? '';
		$no_empleado     = $data['no_empleado']    ?? '';
		$afo_cedente     = $data['afo_cedente']    ?? '';
		$acompanamiento  = $data['acompanamiento'] ?? '0.00';
		$en_temporales   = $data['en_temporales']  ?? false;
		$id_usuario_cap  = $data['id_usuario_cap'] ?? 0;
		$clave_usuario   = $data['clave_usuario']  ?? '';
		$empresa         = $data['empresa']        ?? '';
		$email           = $data['email']          ?? '';
		$celular         = $data['celular']        ?? '';
		$cp              = $data['cp']             ?? '';

		if (!$folio || !$no_empleado) {
			echo json_encode(['success' => false, 'message' => 'Folio y comisionista requeridos']);
			return;
		}

		$prod = $this->db->query("
			SELECT * FROM produccion WHERE folio = ? LIMIT 1
		", [$folio])->row_array();

		if (!$prod) {
			echo json_encode(['success' => false, 'message' => 'Folio no encontrado en producción']);
			return;
		}

		$trab = $this->db->query("
			SELECT * FROM prod_trabajador WHERE folio = ? LIMIT 1
		", [$folio])->row_array();

		$fecha_hoy = date('d/m/Y');

		$tabla_acomp     = $en_temporales ? 'cap_acompaniamiento_tem' : 'acompaniamiento';
		$puede_acompañar = false;

		if ((float)$acompanamiento > 0) {
			$reg_acomp = $this->db->query("
				SELECT folio, fec_pago, estatus FROM {$tabla_acomp} WHERE folio = ? LIMIT 1
			", [$folio])->row_array();

			if (!$reg_acomp) {
				$this->db->query("
					INSERT INTO {$tabla_acomp} (folio, fec_pago, costo, estatus)
					VALUES (?, '', ?, 0)
				", [$folio, $acompanamiento]);
				$puede_acompañar = true;

			} elseif ($reg_acomp['estatus'] == 0 && empty($reg_acomp['fec_pago'])) {
				$this->db->query("
					UPDATE {$tabla_acomp} SET costo = ? WHERE folio = ?
				", [$acompanamiento, $folio]);
				$puede_acompañar = true;

			} else {
				$puede_acompañar = false;
			}
		}

		if ($en_temporales) {
			$existe_temp = $this->db->query("
				SELECT folio FROM cap_prod_tem WHERE folio = ? LIMIT 1
			", [$folio])->num_rows();

			if ($existe_temp > 0) {
				$this->db->query("
					UPDATE cap_prod_tem SET
						no_empleado    = ?,
						afo_cedente    = ?,
						fec_captura    = ?,
						id_usuario_cap = ?
					WHERE folio = ?
				", [$no_empleado, $afo_cedente, $fecha_hoy, $id_usuario_cap, $folio]);
			} else {
				$this->db->query("
					INSERT INTO cap_prod_tem (
						folio, fec_captura, nss, id_usuario_cap, no_empleado,
						id_tipo_mov, fec_traspaso, fec_cierre, nap,
						saldo_estimado, afo_cedente, viable, id_estatus_anticipo,
						fec_anticipo, fec_pago_ant, monto_anticipo, monto_incentivo,
						id_estatus_prod, cierre_cap, cap_principal,
						estatus_confirma, fec_ult_estatus, no_asesor_av
					) VALUES (
						?, ?, ?, ?, ?,
						?, ?, ?, ?,
						?, ?, ?, ?,
						?, ?, ?, ?,
						?, ?, ?,
						?, ?, ?
					)
				", [
					$folio,
					$fecha_hoy,
					$prod['nss'],
					$id_usuario_cap,
					$no_empleado,
					1,
					$prod['fec_traspaso'],
					$prod['fec_cierre']          ?? '',
					$prod['nap'],
					$prod['saldo_estimado'],
					$afo_cedente,
					$prod['viable'],
					$prod['id_estatus_anticipo'],
					$prod['fec_anticipo']         ?? '',
					$prod['fec_pago_ant']         ?? '',
					$prod['monto_anticipo']       ?? 0,
					$prod['monto_incentivo']      ?? 0,
					$prod['id_estatus_prod'],
					$prod['cierre_cap'],
					$prod['cap_principal'],
					$prod['estatus_confirma']     ?? '',
					$fecha_hoy,
					$prod['no_asesor_av']         ?? 0,
				]);
			}

			$existe_trab_tem = $this->db->query("
				SELECT folio FROM cap_prod_trabajador_tem WHERE folio = ? LIMIT 1
			", [$folio])->num_rows();

			if ($existe_trab_tem > 0) {
				$this->db->query("
					UPDATE cap_prod_trabajador_tem SET
						empresa    = ?,
						email_trab = ?,
						celular    = ?,
						cp         = ?
					WHERE folio = ?
				", [$empresa ?: ($trab['empresa'] ?? ''), $email ?: ($trab['email_trab'] ?? ''), $celular ?: ($trab['celular'] ?? ''), $cp ?: ($trab['cp'] ?? ''), $folio]);
			} else {
				$this->db->query("
					INSERT INTO cap_prod_trabajador_tem (
						folio, no_oportunidad, trabajador, empresa,
						telefono, celular, observa, CURP,
						email_trab, sal_mes, fec_sello, vigencia, cp
					) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
				", [
					$folio,
					$trab['no_oportunidad']  ?? '',
					$trab['trabajador']      ?? '',
					$empresa ?: ($trab['empresa']   ?? ''),
					$trab['telefono']        ?? '',
					$celular ?: ($trab['celular']   ?? ''),
					$trab['observa']         ?? '',
					$trab['CURP']            ?? '',
					$email ?: ($trab['email_trab']  ?? ''),
					$trab['sal_mes']         ?? 0,
					$trab['fec_sello']       ?? '',
					$trab['vigencia']        ?? 0,
					$cp ?: ($trab['cp']             ?? ''),
				]);
			}

		} else {
			$this->db->query("
				UPDATE produccion SET
					no_empleado    = ?,
					afo_cedente    = ?,
					id_usuario_cap = ?
				WHERE folio = ?
			", [$no_empleado, $afo_cedente, $id_usuario_cap, $folio]);

			$this->db->query("
				UPDATE prod_trabajador SET
					empresa    = ?,
					email_trab = ?,
					celular    = ?,
					cp         = ?
				WHERE folio = ?
			", [
				$empresa ?: ($trab['empresa']    ?? ''),
				$email   ?: ($trab['email_trab'] ?? ''),
				$celular ?: ($trab['celular']    ?? ''),
				$cp      ?: ($trab['cp']         ?? ''),
				$folio,
			]);
		}

		$this->db->query("
			UPDATE produccion SET estatus_validacion = 0 WHERE folio = ?
		", [$folio]);

		$existe_captura = $this->db->query("
			SELECT id FROM capturas_app WHERE folio = ? LIMIT 1
		", [$folio])->row_array();

		$estatus_app = $en_temporales ? 'TEMPORALES' : 'CAPTURADO';

		if ($existe_captura) {
			$this->db->query("
				UPDATE capturas_app SET
					no_empleado     = ?,
					id_usuario_cap  = ?,
					clave_usuario   = ?,
					afo_cedente     = ?,
					acompanamiento  = ?,
					en_temporales   = ?,
					estatus         = ?,
					fecha_captura   = NOW()
				WHERE folio = ?
			", [$no_empleado, $id_usuario_cap, $clave_usuario, $afo_cedente, $acompanamiento, $en_temporales ? 1 : 0, $estatus_app, $folio]);
		} else {
			$this->db->query("
				INSERT INTO capturas_app (
					folio, no_empleado, id_usuario_cap, clave_usuario,
					afo_cedente, acompanamiento, en_temporales, estatus
				) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			", [$folio, $no_empleado, $id_usuario_cap, $clave_usuario, $afo_cedente, $acompanamiento, $en_temporales ? 1 : 0, $estatus_app]);
		}

		// ─── Notificación push al usuario capturista ──────────────────────────
		// require_once FCPATH . 'send_push.php';

		// $token_row = $this->db->query("
		// 	SELECT token FROM push_tokens WHERE no_empleado = ? LIMIT 1
		// ", [$id_usuario_cap])->row_array();

		// if ($token_row && !empty($token_row['token'])) {
		// 	$msg = $en_temporales
		// 		? "Folio {$folio} guardado en temporales"
		// 		: "Folio {$folio} capturado correctamente";

		// 	enviar_push($token_row['token'], 'Carobra HelpiA', $msg, [
		// 		'folio' => $folio,
		// 	]);
		// }

		echo json_encode([
			'success'          => true,
			'en_temporales'    => $en_temporales,
			'acomp_bloqueado'  => (float)$acompanamiento > 0 && !$puede_acompañar,
			'message'          => $en_temporales
				? 'Captura guardada en temporales correctamente'
				: 'Traspaso guardado correctamente',
		]);
	}

	// GET /api/dashboard-stats
	public function dashboard_stats() {
		$id_nivel    = $this->input->get('id_nivel')    ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$id_gerencia = $this->input->get('id_gerencia') ?? '';

		$hoy = date('Y-m-d');

		if ($id_nivel == '12') {
			$sql = "
				SELECT COUNT(*) as total
				FROM capturas_app ca
				INNER JOIN produccion p ON ca.folio = p.folio
				WHERE DATE(ca.fecha_captura) = ?
				AND STR_TO_DATE(p.fec_traspaso, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
			";
			$params = [$hoy];

		} elseif ($id_nivel == '3') {
			$sql = "
				SELECT COUNT(*) as total
				FROM capturas_app ca
				INNER JOIN produccion p ON ca.folio = p.folio
				WHERE DATE(ca.fecha_captura) = ?
				AND ca.id_usuario_cap = ?
				AND STR_TO_DATE(p.fec_traspaso, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
			";
			$params = [$hoy, $no_empleado];

		} else {
			$filtro = $this->obtener_filtro_estructura($id_gerencia);
			$campo  = $filtro['campo'];
			$valor  = $filtro['valor'];

			$sql = "
				SELECT COUNT(*) as total
				FROM capturas_app ca
				INNER JOIN produccion p ON ca.folio = p.folio
				WHERE DATE(ca.fecha_captura) = ?
				AND ca.id_usuario_cap IN (
					SELECT no_empleado FROM estruc_empleados WHERE {$campo} = ?
				)
				AND STR_TO_DATE(p.fec_traspaso, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
			";
			$params = [$hoy, $valor];
		}

		$total = (int) $this->db->query($sql, $params)->row_array()['total'];

		echo json_encode([
			'success' => true,
			'data'    => [
				'traspasos_hoy' => $total
			]
		]);
	}

	// GET /api/historial-traspasos?q=&fecha=&id_nivel=&no_empleado=&id_gerencia=
	public function historial_traspasos() {
		$q           = $this->input->get('q')           ?? '';
		$fecha       = $this->input->get('fecha')       ?? '';
		$id_nivel    = $this->input->get('id_nivel')    ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$id_gerencia = $this->input->get('id_gerencia') ?? '';
		$fecha_inicio = $this->input->get('fecha_inicio') ?? '';
		$fecha_fin    = $this->input->get('fecha_fin')    ?? '';


		$sql = "
			SELECT
				ca.folio,
				ca.no_empleado,
				ca.afo_cedente,
				ca.acompanamiento,
				ca.en_temporales,
				ca.estatus,
				ca.clave_usuario,
				p.estatus_validacion,
				p.nss,
				p.nap,
				p.fec_traspaso,
				ca.fecha_captura,
				pt.trabajador,
				pt.empresa,
				pt.email_trab,
				pt.celular,
				pt.cp,
				eu.nombre AS nombre_comisionista,
				af.afore  AS nombre_afore_cedente,
				(SELECT COUNT(*) FROM evidencias e WHERE e.folio = ca.folio) AS total_evidencias
			FROM capturas_app ca
			INNER JOIN produccion p ON ca.folio = p.folio
			LEFT JOIN prod_trabajador pt ON ca.folio = pt.folio
			LEFT JOIN empl_usuarios eu ON eu.no_empleado = ca.no_empleado
			LEFT JOIN afores af ON af.clave = ca.afo_cedente
			WHERE STR_TO_DATE(p.fec_traspaso, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
			AND p.id_estatus_prod != 6
		";
		$params = [];

		if ($id_nivel == '12') {
			// sin filtro extra
		} elseif ($id_nivel == '3') {
			$sql .= " AND ca.id_usuario_cap = ? ";
			$params[] = $no_empleado;
		} else {
			$filtro = $this->obtener_filtro_estructura($id_gerencia);
			$campo  = $filtro['campo'];
			$valor  = $filtro['valor'];

			$sql .= " AND ca.id_usuario_cap IN (
				SELECT no_empleado FROM estruc_empleados WHERE {$campo} = ?
			) ";
			$params[] = $valor;
		}

		if ($fecha_inicio && $fecha_fin) {
			$sql .= " AND DATE(ca.fecha_captura) >= ? AND DATE(ca.fecha_captura) <= ? ";
			$params[] = $fecha_inicio;
			$params[] = $fecha_fin;
		} elseif ($fecha_inicio) {
			$sql .= " AND DATE(ca.fecha_captura) >= ? ";
			$params[] = $fecha_inicio;
		} elseif ($fecha_fin) {
			$sql .= " AND DATE(ca.fecha_captura) <= ? ";
			$params[] = $fecha_fin;
		}

		if ($q) {
			$sql .= " AND (ca.folio LIKE ? OR pt.trabajador LIKE ?) ";
			$params[] = '%' . $q . '%';
			$params[] = '%' . $q . '%';
		}

		if ($fecha) {
			$sql .= " AND DATE(ca.fecha_captura) = ? ";
			$params[] = $fecha;
		}

		$sql .= " ORDER BY ca.fecha_captura DESC LIMIT 50";

		$query = $this->db->query($sql, $params);

		echo json_encode([
			'success' => true,
			'data'    => $query->result_array()
		]);
	}

	// GET /api/pendientes
	public function pendientes() {
		$id_nivel    = $this->input->get('id_nivel')    ?? '';
		$id_gerencia = $this->input->get('id_gerencia') ?? '';

		if ($id_nivel == '3') {
			echo json_encode(['success' => true, 'data' => []]);
			return;
		}

		$base = "
			SELECT pt.folio, pt.trabajador, p.nss, p.nap, p.fec_traspaso,
				CONCAT(p.nap, ' | ', eu.nombre) AS nap_nombre
			FROM naps n
			LEFT JOIN empl_usuarios eu ON n.no_empleado = eu.no_empleado
			LEFT JOIN estruc_empleados ee ON eu.no_empleado = ee.no_empleado
			INNER JOIN produccion p ON n.nap = p.nap
			INNER JOIN prod_trabajador pt ON pt.folio = p.folio
			WHERE p.no_empleado = 0
			AND n.baja = 0
			AND p.cierre_cap = 0
			AND p.id_estatus_prod != 6
			AND STR_TO_DATE(p.fec_traspaso, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
		";

		if ($id_nivel == '12') {
			$sql = $base . " ORDER BY p.fec_traspaso DESC LIMIT 50 ";
			$params = [];

		} else {
			$filtro = $this->obtener_filtro_estructura($id_gerencia);
			$campo  = $filtro['campo'];
			$valor  = $filtro['valor'];

			$sql = $base . " AND ee.{$campo} = ? ORDER BY p.fec_traspaso DESC LIMIT 50 ";
			$params = [$valor];
		}

		$query = $this->db->query($sql, $params);

		echo json_encode([
			'success' => true,
			'data'    => $query->result_array()
		]);
	}

	// GET /api/folios-incompletos
	public function folios_incompletos() {
		$id_nivel    = $this->input->get('id_nivel')    ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$id_gerencia = $this->input->get('id_gerencia') ?? '';

		$base = "
			SELECT
				p.folio,
				pt.trabajador,
				p.no_empleado,
				p.afo_cedente,
				p.estatus_validacion,
				(SELECT COUNT(*) FROM evidencias e WHERE e.folio = p.folio) AS total_evidencias
			FROM produccion p
			INNER JOIN prod_trabajador pt ON pt.folio = p.folio
			WHERE p.id_estatus_prod != 6
			AND STR_TO_DATE(p.fec_traspaso, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
			AND (
				p.no_empleado = 0
				OR p.afo_cedente IS NULL
				OR p.afo_cedente = ''
				OR (SELECT COUNT(*) FROM evidencias e WHERE e.folio = p.folio) = 0
			)
		";

		if ($id_nivel == '12') {
			$sql = $base . " ORDER BY p.fec_traspaso DESC LIMIT 50 ";
			$params = [];

		} elseif ($id_nivel == '3') {
			$sql = $base . " AND p.no_empleado = ? ORDER BY p.fec_traspaso DESC LIMIT 50 ";
			$params = [$no_empleado];

		} else {
			$filtro = $this->obtener_filtro_estructura($id_gerencia);
			$campo  = $filtro['campo'];
			$valor  = $filtro['valor'];

			$sql = $base . " AND (
				p.no_empleado = 0
				OR p.no_empleado IN (SELECT no_empleado FROM estruc_empleados WHERE {$campo} = ?)
			) ORDER BY p.fec_traspaso DESC LIMIT 50 ";
			$params = [$valor];
		}

		$query = $this->db->query($sql, $params);

		echo json_encode([
			'success' => true,
			'data'    => $query->result_array()
		]);
	}


	// POST /api/guardar-token-push
	public function guardar_token_push() {
		$json = $this->input->raw_input_stream;
		$data = json_decode($json, true);

		$token       = $data['token']       ?? '';
		$clave       = $data['clave']       ?? '';
		$no_empleado = $data['no_empleado'] ?? '';

		if (!$token || !$no_empleado) {
			echo json_encode(['success' => false]);
			return;
		}

		$this->db->query("
			INSERT INTO push_tokens (no_empleado, clave, token)
			VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE token = ?, actualizado_en = NOW()
		", [$no_empleado, $clave, $token, $token]);

		

		echo json_encode(['success' => true]);
	}

	// POST /api/spartan-recuperar
	public function spartan_recuperar() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$folio       = $data['folio']       ?? '';
		$no_empleado = $data['no_empleado'] ?? '';

		// Verificar Spartan
		$spartan_query = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			LIMIT 1
		", [$no_empleado]);
		if ($spartan_query === false) {
			$err = $this->db->error();
			echo json_encode(['success' => false, 'message' => 'Error auth query', 'debug' => $err]);
			return;
		}
		$spartan = $spartan_query->row_array();

		if (!$spartan) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		if (!$folio) {
			echo json_encode(['success' => false, 'message' => 'Folio requerido']);
			return;
		}

		// Verificar que el folio existe en clawback
		$cb = $this->db->query("
			SELECT folio FROM clawback WHERE folio = ? LIMIT 1
		", [$folio])->row_array();

		if (!$cb) {
			echo json_encode(['success' => false, 'message' => 'Folio no encontrado en clawback']);
			return;
		}

		// Verificar que existe en produccion
		$prod = $this->db->query("
			SELECT folio, id_estatus_prod FROM produccion WHERE folio = ? LIMIT 1
		", [$folio])->row_array();

		if (!$prod) {
			echo json_encode(['success' => false, 'message' => 'Folio no encontrado en producción']);
			return;
		}

		// Recuperar: cambiar estatus a 1
		$this->db->query("
			UPDATE produccion SET id_estatus_prod = 1 WHERE folio = ?
		", [$folio]);

		// Limpiar rechazos si existen
		$this->db->query("
			DELETE FROM prod_rechazos WHERE folio = ?
		", [$folio]);

		echo json_encode([
			'success' => true,
			'message' => 'Folio recuperado correctamente'
		]);
	}

	// POST /api/spartan-reasignar
	public function spartan_reasignar() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$folio          = $data['folio']          ?? '';
		$no_empleado    = $data['no_empleado']    ?? '';
		$nuevo_empleado = $data['nuevo_empleado'] ?? '';

		// Verificar Spartan
		$spartan_query = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			LIMIT 1
		", [$no_empleado]);
		if ($spartan_query === false) {
			$err = $this->db->error();
			echo json_encode(['success' => false, 'message' => 'Error auth query', 'debug' => $err]);
			return;
		}
		$spartan = $spartan_query->row_array();

		if (!$spartan) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		if (!$folio || !$nuevo_empleado) {
			echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
			return;
		}

		$this->db->query("
			UPDATE produccion SET no_empleado = ? WHERE folio = ?
		", [$nuevo_empleado, $folio]);

		echo json_encode(['success' => true, 'message' => 'Comisionista reasignado correctamente']);
	}

	// GET /api/spartan-folios?q=
	public function spartan_folios() {
		$q           = $this->input->get('q')           ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';

		// Verificar Spartan
		$spartan_query = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			LIMIT 1
		", [$no_empleado]);
		if ($spartan_query === false) {
			$err = $this->db->error();
			echo json_encode(['success' => false, 'message' => 'Error auth query', 'debug' => $err]);
			return;
		}
		$spartan = $spartan_query->row_array();

		if (!$spartan) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		$tiene_busqueda = strlen(trim($q)) >= 2;

		$sql = "
			SELECT
				cb.folio,
				cb.nombre_Completo  AS trabajador,
				cb.nap,
				cb.fec_traspaso,
				cb.fec_tramite,
				cb.saldo,
				cb.afo_receptora,
				cb.afo_cedente,
				cb.estado,
				cb.antiguedad,
				cb.semana,
				cb.mes_procesar,
				cb.cancelacion,
				cb.fec_alta,
				cb.sol_traspaso,
				cb.pplus,
				cb.ley,
				pt.empresa,
				pt.celular,
				pt.email_trab,
				pt.cp,
				p.nss,
				p.no_empleado,
				p.id_estatus_prod,
				af_ced.afore  AS nombre_afo_cedente,
				af_rec.afore  AS nombre_afo_receptora,
				eu.nombre     AS nombre_comisionista,
				eu.clave      AS clave_comisionista,
				ee.sc,
				ee.brocker,
				ee.gerencia,
				ee.id_gerencia,
				CASE
					WHEN p.id_estatus_prod = 1 THEN 'registro'
					ELSE 'clawback'
				END AS estatus
			FROM clawback cb
			LEFT JOIN produccion p ON p.folio = cb.folio
			LEFT JOIN prod_trabajador pt ON pt.folio = cb.folio
			LEFT JOIN empl_usuarios eu ON eu.no_empleado = p.no_empleado
			LEFT JOIN estruc_empleados ee ON ee.no_empleado = p.no_empleado
			LEFT JOIN afores af_ced ON af_ced.clave = cb.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = cb.afo_receptora
			WHERE cb.fec_tramite IS NOT NULL
			AND cb.fec_tramite != ''
		";
		$params = [];

		if ($tiene_busqueda) {
			// Búsqueda directa por solicitud de traspaso únicamente (folio es solo informativo)
			$sql .= " AND cb.sol_traspaso LIKE ? ";
			$params[] = '%' . $q . '%';
		} else {
			// Listado: solo folios pendientes (sin retención, últimos 7 días)
			$sql .= " AND NOT EXISTS (SELECT 1 FROM retenciones_captura rc WHERE rc.folio = cb.folio) ";
			$sql .= " AND STR_TO_DATE(cb.fec_tramite, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ";
		}

		$sql .= " ORDER BY STR_TO_DATE(cb.fec_tramite, '%d/%m/%Y') DESC";

		$query = $this->db->query($sql, $params);
		if ($query === false) {
			$err = $this->db->error();
			echo json_encode(['success' => false, 'message' => 'Error de base de datos', 'debug' => $err]);
			return;
		}
		$rows = $query->result_array();
		$resultado = [];

		foreach ($rows as $r) {
			if (empty($r['fec_tramite'])) continue;
			if ($tiene_busqueda || $this->fecha_dentro_ventana_habil($r['fec_tramite'])) {
				$r['fecha_limite'] = $this->sumar_dias_habiles($r['fec_tramite'], 3);
				$resultado[] = $r;
			}
		}

		echo json_encode([
			'success' => true,
			'data'    => $resultado
		]);
	}

	// POST /api/spartan-capturar
	public function spartan_capturar() {
		$json         = $this->input->raw_input_stream;
		$data         = json_decode($json, true);
		$folio        = $data['folio']             ?? '';
		$sol_traspaso = $data['sol_traspaso']      ?? '';
		$no_empleado  = $data['no_empleado']       ?? '';
		$medio        = $data['medio_cancelacion'] ?? '';

		// Verificar Spartan
		$spartan_query = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			LIMIT 1
		", [$no_empleado]);
		if ($spartan_query === false) {
			$err = $this->db->error();
			echo json_encode(['success' => false, 'message' => 'Error auth query', 'debug' => $err]);
			return;
		}
		$spartan = $spartan_query->row_array();

		if (!$spartan) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		if (!$folio) {
			echo json_encode(['success' => false, 'message' => 'Folio requerido']);
			return;
		}
		if (!$medio) {
			echo json_encode(['success' => false, 'message' => 'Medio requerido']);
			return;
		}

		// Verificar que no fue ya capturada esta sol_traspaso
		$ya_capturado = $this->db->query("
			SELECT folio FROM retenciones_captura
			WHERE folio = ? AND sol_traspaso = ?
			LIMIT 1
		", [$folio, $sol_traspaso])->row_array();

		if ($ya_capturado) {
			echo json_encode(['success' => false, 'message' => 'Esta solicitud ya fue capturada por otro Spartan']);
			return;
		}

		// Registrar captura
		$this->db->query("
			INSERT INTO retenciones_captura (folio, sol_traspaso, clave, medio_cancelacion)
			VALUES (?, ?, ?, ?)
		", [$folio, $sol_traspaso, $spartan['clave'], $medio]);

		// Asignar comisionista en produccion
		$this->db->query("
			UPDATE produccion SET no_empleado = ? WHERE folio = ?
		", [$no_empleado, $folio]);

		echo json_encode([
			'success' => true,
			'message' => 'Folio capturado y asignado correctamente'
		]);
	}

	// GET /api/spartan-historial
	public function spartan_historial() {
		$no_empleado  = $this->input->get('no_empleado')  ?? '';
		$filtro       = $this->input->get('filtro')        ?? 'recientes';
		$fecha_inicio = $this->input->get('fecha_inicio')  ?? '';
		$fecha_fin    = $this->input->get('fecha_fin')     ?? '';
		$q            = $this->input->get('q')             ?? '';

		$spartan = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			LIMIT 1
		", [$no_empleado])->row_array();

		if (!$spartan) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		$clave = $spartan['clave'];

		if ($filtro === 'certificados') {
			$semanas = $this->db->query("
				SELECT DISTINCT c.semana
				FROM retenciones_captura sc
				INNER JOIN clawback c ON sc.sol_traspaso = c.sol_traspaso
				WHERE sc.clave = ?
				AND c.semana IS NOT NULL AND c.semana != ''
				AND c.cancelacion = '1'
				ORDER BY c.semana DESC
				LIMIT 12
			", [$clave])->result_array();
		} else {
			$semanas = $this->db->query("
				SELECT DISTINCT c.semana
				FROM retenciones_captura sc
				INNER JOIN clawback c ON sc.sol_traspaso = c.sol_traspaso
				WHERE sc.clave = ?
				AND c.semana IS NOT NULL AND c.semana != ''
				ORDER BY c.semana DESC
				LIMIT 3
			", [$clave])->result_array();
		}

		if (empty($semanas)) {
			echo json_encode(['success' => true, 'data' => []]);
			return;
		}

		$semanasArr   = array_column($semanas, 'semana');
		$placeholders = implode(',', array_fill(0, count($semanasArr), '?'));

		$where_extra  = '';
		$params_extra = [];

		if ($filtro === 'certificados') {
			$where_extra .= " AND c.cancelacion = '1' ";

			if ($fecha_inicio && $fecha_fin) {
				$where_extra .= "
					AND STR_TO_DATE(c.fec_tramite, '%d/%m/%Y') >= STR_TO_DATE(?, '%Y-%m-%d')
					AND STR_TO_DATE(c.fec_tramite, '%d/%m/%Y') <= STR_TO_DATE(?, '%Y-%m-%d')
				";
				$params_extra[] = $fecha_inicio;
				$params_extra[] = $fecha_fin;
			}
		}

		if (strlen(trim($q)) >= 2) {
			$where_extra .= " AND c.sol_traspaso LIKE ? ";
			$params_extra[] = '%' . $q . '%';
		}

		$params = array_merge([$clave], $semanasArr, $params_extra);

		$this->db->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

		$query = $this->db->query("
			SELECT
				sc.id,
				sc.folio,
				sc.clave,
				sc.fecha_captura,
				sc.medio_cancelacion,
				c.nombre_Completo AS trabajador,
				c.nap,
				c.fec_traspaso,
				c.saldo,
				c.semana,
				c.mes_procesar,
				c.sol_traspaso,
				c.fec_tramite,
				c.cancelacion,
				c.pplus,
				c.email_trab      AS email_clawback,
				af_ced.afore AS nombre_afo_cedente,
				af_rec.afore AS nombre_afo_receptora,
				eu.nombre    AS nombre_spartan,
				MAX(se.ruta) AS evidencia_ruta,
				pt.email_trab AS email_prod,
				DATE_FORMAT(
					DATE_ADD(STR_TO_DATE(c.fec_tramite, '%d/%m/%Y'), INTERVAL 3 DAY),
					'%d/%m/%Y'
				) AS fecha_limite
			FROM retenciones_captura sc
			INNER JOIN clawback c ON sc.sol_traspaso = c.sol_traspaso
			LEFT JOIN prod_trabajador pt ON pt.folio = sc.folio
			LEFT JOIN afores af_ced ON af_ced.clave = c.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = c.afo_receptora
			LEFT JOIN empl_usuarios eu ON eu.clave = sc.clave
			LEFT JOIN spartan_evidencias se ON se.folio = sc.folio
			WHERE sc.clave = ?
			AND c.semana IN ({$placeholders})
			{$where_extra}
			GROUP BY sc.id
			ORDER BY c.semana DESC, sc.fecha_captura DESC
		", $params);

		$rows = $query->result_array();

		foreach ($rows as &$r) {
			$r['evidencia_url']       = !empty($r['evidencia_ruta'])
				? 'https://www.sis-ca.com.mx:7863/afore/' . $r['evidencia_ruta']
				: null;
			$r['estatus_pago']        = $r['cancelacion'] == '1' ? 'A pago'      : 'Sin pago';
			$r['estatus_certificado'] = $r['pplus'] == 1         ? 'Certificado' : 'Sin certificar';
		}
		unset($r);

		$agrupado = [];
		foreach ($rows as $r) {
			$semana = $r['semana'] ?? 'Sin semana';
			if (!isset($agrupado[$semana])) {
				$agrupado[$semana] = [];
			}
			$agrupado[$semana][] = $r;
		}

		echo json_encode([
			'success' => true,
			'data'    => $agrupado
		]);
	}

	// ─── Helper verificar Super Spartan ──────────────────────────────────
	private function verificar_super_spartan($no_empleado) {
		return $this->db->query("
			SELECT sc.no_empleado FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			INNER JOIN spartan_config sc ON eu.no_empleado = sc.no_empleado
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			AND sc.es_super = 1
			AND sc.activo = 1
			LIMIT 1
		", [$no_empleado])->row_array();
	}

	// GET /api/super-capturas — ver todas las capturas de todos los Spartans
	public function super_capturas() {
		$no_empleado = $this->input->get('no_empleado') ?? '';

		if (!$this->verificar_super_spartan($no_empleado)) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		$semanas = $this->db->query("
			SELECT DISTINCT c.semana
			FROM retenciones_captura sc
			INNER JOIN clawback c ON sc.sol_traspaso = c.sol_traspaso
			WHERE c.semana IS NOT NULL AND c.semana != ''
			ORDER BY c.semana DESC
			LIMIT 3
		")->result_array();

		if (empty($semanas)) {
			echo json_encode(['success' => true, 'data' => []]);
			return;
		}

		$semanasArr   = array_column($semanas, 'semana');
		$placeholders = implode(',', array_fill(0, count($semanasArr), '?'));

		$query = $this->db->query("
			SELECT
				sc.folio,
				sc.clave,
				sc.fecha_captura,
				sc.medio_cancelacion,
				c.nombre_Completo AS trabajador,
				c.saldo,
				c.semana,
				c.sol_traspaso,
				c.fec_tramite,
				c.cancelacion,
				c.pplus,
				af_ced.afore AS nombre_afo_cedente,
				af_rec.afore AS nombre_afo_receptora,
				eu.nombre    AS nombre_spartan,
				cfg.porcentaje,
				cfg.monto_fijo,
				CASE
					WHEN c.pplus = 1 THEN 'Certificado'
					ELSE 'Sin certificar'
				END AS estatus_certificado,
				CASE
					WHEN c.cancelacion = '1' THEN 'A pago'
					ELSE 'Sin pago'
				END AS estatus_cancelacion,
				DATE_FORMAT(
					DATE_ADD(STR_TO_DATE(c.fec_tramite, '%d/%m/%Y'), INTERVAL 3 DAY),
					'%d/%m/%Y'
				) AS fecha_limite
			FROM retenciones_captura sc
			INNER JOIN clawback c ON sc.sol_traspaso = c.sol_traspaso
			LEFT JOIN afores af_ced ON af_ced.clave = c.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = c.afo_receptora
			LEFT JOIN empl_usuarios eu ON eu.clave = sc.clave
			LEFT JOIN spartan_config cfg ON cfg.clave = sc.clave
			WHERE c.semana IN ({$placeholders})
			ORDER BY eu.nombre ASC, c.semana DESC
		", $semanasArr);

		$rows = $query->result_array();

		$agrupado = [];
		foreach ($rows as $r) {
			$spartan = $r['nombre_spartan'] ?? 'Sin nombre';
			if (!isset($agrupado[$spartan])) {
				$agrupado[$spartan] = [
					'nombre'     => $spartan,
					'clave'      => $r['clave'],
					'porcentaje' => $r['porcentaje'],
					'monto_fijo' => $r['monto_fijo'],
					'folios'     => [],
				];
			}
			$agrupado[$spartan]['folios'][] = $r;
		}

		echo json_encode(['success' => true, 'data' => array_values($agrupado)]);
	}

	// GET /api/super-usuarios — lista de todos los Spartans
	public function super_usuarios() {
		$no_empleado = $this->input->get('no_empleado') ?? '';

		if (!$this->verificar_super_spartan($no_empleado)) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		$query = $this->db->query("
			SELECT
				sc.id,
				sc.no_empleado,
				sc.clave,
				sc.es_super,
				sc.porcentaje,
				sc.monto_fijo,
				sc.activo,
				eu.nombre
			FROM spartan_config sc
			LEFT JOIN empl_usuarios eu ON eu.no_empleado = sc.no_empleado
			ORDER BY sc.es_super DESC, eu.nombre ASC
		");

		echo json_encode(['success' => true, 'data' => $query->result_array()]);
	}

	// POST /api/super-guardar-usuario — crear o actualizar config de un Spartan
	public function super_guardar_usuario() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$no_empleado = $data['no_empleado']        ?? '';
		$req_emp     = $data['req_no_empleado']    ?? '';
		$es_super    = intval($data['es_super']    ?? 0);
		$porcentaje  = floatval($data['porcentaje'] ?? 0);
		$monto_fijo  = floatval($data['monto_fijo'] ?? 0);
		$activo      = intval($data['activo']      ?? 1);

		if (!$this->verificar_super_spartan($no_empleado)) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		// Obtener clave del empleado a guardar
		$emp = $this->db->query("
			SELECT clave FROM empl_usuarios WHERE no_empleado = ? LIMIT 1
		", [$req_emp])->row_array();

		if (!$emp) {
			echo json_encode(['success' => false, 'message' => 'Empleado no encontrado']);
			return;
		}

		$this->db->query("
			INSERT INTO spartan_config (no_empleado, clave, es_super, porcentaje, monto_fijo, activo)
			VALUES (?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE
				es_super   = VALUES(es_super),
				porcentaje = VALUES(porcentaje),
				monto_fijo = VALUES(monto_fijo),
				activo     = VALUES(activo)
		", [$req_emp, $emp['clave'], $es_super, $porcentaje, $monto_fijo, $activo]);

		echo json_encode(['success' => true, 'message' => 'Usuario guardado correctamente']);
	}


	// POST /api/spartan-subir-evidencia
	public function spartan_subir_evidencia() {
		$folio       = $this->input->post('folio')       ?? '';
		$no_empleado = $this->input->post('no_empleado') ?? '';

		// Verificar Spartan — obtener clave
		$spartan = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			INNER JOIN spartan_config sc ON eu.clave = sc.clave
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			AND sc.activo = 1
			LIMIT 1
		", [$no_empleado])->row_array();

		if (!$spartan) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		if (!$folio) {
			echo json_encode(['success' => false, 'message' => 'Folio requerido']);
			return;
		}

		if (!isset($_FILES['archivo'])) {
			echo json_encode(['success' => false, 'message' => 'No se recibió archivo']);
			return;
		}

		$archivo    = $_FILES['archivo'];
		$extension  = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
		$permitidas = ['jpg', 'jpeg', 'png'];

		if (!in_array($extension, $permitidas)) {
			echo json_encode(['success' => false, 'message' => 'Solo imágenes JPG/PNG']);
			return;
		}

		$carpeta = '/var/www/html/afore/evidencias/spartan/' . $folio . '/';
		if (!is_dir($carpeta)) {
			mkdir($carpeta, 0775, true);
		}

		$nombre_final  = $folio . '_' . date('Ymd_His') . '.' . $extension;
		$ruta_completa = $carpeta . $nombre_final;

		if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
			echo json_encode(['success' => false, 'message' => 'Error al guardar la imagen']);
			return;
		}

		$ruta_relativa = 'evidencias/spartan/' . $folio . '/' . $nombre_final;
		$url_publica   = 'https://www.sis-ca.com.mx:7863/afore/' . $ruta_relativa;

		$this->db->query("
			INSERT INTO spartan_evidencias (folio, ruta)
			VALUES (?, ?)
			ON DUPLICATE KEY UPDATE ruta = VALUES(ruta)
		", [$folio, $ruta_relativa]);

		echo json_encode([
			'success' => true,
			'url'     => $url_publica,
			'ruta'    => $ruta_relativa,
		]);
	}
	

	// GET /api/super-capturas-usuario
	public function super_capturas_usuario() {
		$no_empleado      = $this->input->get('no_empleado') ?? '';
		$spartan_clave    = $this->input->get('spartan')     ?? '';

		if (!$this->verificar_super_spartan($no_empleado)) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		$query = $this->db->query("
			SELECT
				sc.folio,
				sc.fecha_captura,
				sc.medio_cancelacion,
				sc.clave,
				c.nombre_Completo AS trabajador,
				c.saldo,
				c.semana,
				c.sol_traspaso,
				c.fec_tramite,
				c.cancelacion,
				c.pplus,
				af_ced.afore AS nombre_afo_cedente,
				af_rec.afore AS nombre_afo_receptora,
				se.ruta AS evidencia_ruta,
				DATE_FORMAT(
					DATE_ADD(STR_TO_DATE(c.fec_tramite, '%d/%m/%Y'), INTERVAL 3 DAY),
					'%d/%m/%Y'
				) AS fecha_limite
			FROM retenciones_captura sc
			INNER JOIN clawback c ON sc.sol_traspaso = c.sol_traspaso
			LEFT JOIN afores af_ced ON af_ced.clave = c.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = c.afo_receptora
			LEFT JOIN spartan_evidencias se ON se.folio = sc.folio
			WHERE sc.clave = ?
			ORDER BY sc.fecha_captura DESC
		", [$spartan_clave]);

		$rows = $query->result_array();
		foreach ($rows as &$r) {
			$r['evidencia_url'] = !empty($r['evidencia_ruta'])
				? 'https://www.sis-ca.com.mx:7863/afore/' . $r['evidencia_ruta']
				: null;
		}
		unset($r);

		echo json_encode(['success' => true, 'data' => $rows]);
	}

	// GET /api/super-buscar-spartan?q=
	public function super_buscar_spartan() {
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$q           = $this->input->get('q')           ?? '';

		if (!$this->verificar_super_spartan($no_empleado)) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		$sql = "
			SELECT e.clave, eu.nombre, eu.no_empleado,
				sc.id AS config_id, sc.es_super, sc.porcentaje, sc.monto_fijo, sc.activo
			FROM carobra.empleados e
			INNER JOIN carobra_2014.empl_usuarios eu ON eu.clave = e.clave
			LEFT JOIN carobra_2014.spartan_config sc ON sc.no_empleado = eu.no_empleado
			WHERE e.deportenis = 1 AND e.no_prom_dep = 'ESPARTANO'
		";
		$params = [];

		if (strlen(trim($q)) >= 2) {
			$sql .= " AND (e.clave LIKE ? OR eu.nombre LIKE ?) ";
			$params[] = '%' . $q . '%';
			$params[] = '%' . $q . '%';
		}

		$sql .= " ORDER BY eu.nombre ASC LIMIT 20";

		$query  = $this->db->query($sql, $params);
		$rows   = $query->result_array();

		foreach ($rows as &$r) {
			$r['es_super']   = $r['es_super']   ?? '0';
			$r['porcentaje'] = $r['porcentaje'] ?? '0.00';
			$r['monto_fijo'] = $r['monto_fijo'] ?? '0.00';
			$r['activo']     = $r['activo']     ?? '1';
			$r['en_config']  = !empty($r['config_id']);
		}
		unset($r);

		echo json_encode(['success' => true, 'data' => $rows]);
	}

	// GET /api/cancelaciones-estructura
	public function cancelaciones_estructura() {
		$id_nivel    = $this->input->get('id_nivel')    ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$id_gerencia = $this->input->get('id_gerencia') ?? '';
		$q           = $this->input->get('q')           ?? '';

		$filtro = $this->obtener_filtro_estructura($id_gerencia);
		$campo  = $filtro['campo'];
		$valor  = $filtro['valor'];

		$hoy    = date('d/m/Y');
		$inicio = date('d/m/Y', strtotime('-3 days'));

		$sql = "
			SELECT
				cb.folio,
				cb.nombre_Completo  AS trabajador,
				cb.nap,
				cb.fec_traspaso,
				cb.fec_tramite,
				cb.saldo,
				cb.afo_cedente,
				cb.afo_receptora,
				cb.semana,
				cb.sol_traspaso,
				cb.cancelacion,
				cb.pplus,
				cb.email_trab       AS email_clawback,
				af_ced.afore        AS nombre_afo_cedente,
				af_rec.afore        AS nombre_afo_receptora,
				p.no_empleado,
				pt.email_trab       AS email_prod,
				eu_com.nombre       AS nombre_comisionista,
				DATE_FORMAT(
					DATE_ADD(STR_TO_DATE(cb.fec_tramite, '%d/%m/%Y'), INTERVAL 3 DAY),
					'%d/%m/%Y'
				) AS fecha_limite,
				sc.folio AS ya_capturado
			FROM clawback cb
			INNER JOIN produccion p ON p.folio = cb.folio
			INNER JOIN naps n ON n.nap = cb.nap
			INNER JOIN empl_usuarios eu_nap ON eu_nap.no_empleado = n.no_empleado
			INNER JOIN estruc_empleados ee ON ee.no_empleado = eu_nap.no_empleado
			LEFT JOIN prod_trabajador pt ON pt.folio = cb.folio
			LEFT JOIN empl_usuarios eu_com ON eu_com.no_empleado = p.no_empleado
			LEFT JOIN afores af_ced ON af_ced.clave = cb.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = cb.afo_receptora
			LEFT JOIN retenciones_captura sc ON sc.folio = cb.folio
			WHERE cb.fec_tramite IS NOT NULL
			AND cb.fec_tramite != ''
			AND STR_TO_DATE(cb.fec_tramite, '%d/%m/%Y') >= STR_TO_DATE(?, '%d/%m/%Y')
			AND STR_TO_DATE(cb.fec_tramite, '%d/%m/%Y') <= STR_TO_DATE(?, '%d/%m/%Y')
			AND ee.{$campo} = ?
		";
		$params = [$inicio, $hoy, $valor];

		if (strlen(trim($q)) >= 2) {
			$sql .= " AND (cb.folio LIKE ? OR cb.sol_traspaso LIKE ? OR cb.nombre_Completo LIKE ?) ";
			$params[] = '%' . $q . '%';
			$params[] = '%' . $q . '%';
			$params[] = '%' . $q . '%';
		}

		$sql .= " ORDER BY cb.fec_tramite ASC LIMIT 100";

		$query = $this->db->query($sql, $params);
		$rows  = $query->result_array();

		foreach ($rows as &$r) {
			$r['ya_capturado'] = !empty($r['ya_capturado']);
		}
		unset($r);

		echo json_encode(['success' => true, 'data' => $rows]);
	}

	// GET /api/detalle-folio?folio=8080425629
	public function detalle_folio() {
		$folio = $this->input->get('folio') ?? '';

		if (!$folio) {
			echo json_encode(['success' => false, 'message' => 'Folio requerido']);
			return;
		}

		$query = $this->db->query("
			SELECT
				p.folio, p.nss, p.nap, p.fec_traspaso, p.no_empleado,
				p.afo_cedente, p.saldo_estimado, p.id_estatus_prod,
				p.estatus_validacion,
				pt.trabajador, pt.empresa, pt.email_trab, pt.celular, pt.cp, pt.CURP,
				eu.nombre AS nombre_comisionista,
				ca.acompanamiento, ca.estatus, ca.clave_usuario, ca.fecha_captura,
				ca.en_temporales,
				(SELECT costo FROM acompaniamiento WHERE folio = p.folio LIMIT 1) AS acompanamiento_actual,
				CONCAT(p.nap, ' | ', eu_nap.nombre) AS nap_nombre
			FROM produccion p
			LEFT JOIN prod_trabajador pt ON pt.folio = p.folio
			LEFT JOIN empl_usuarios eu ON eu.no_empleado = p.no_empleado
			LEFT JOIN capturas_app ca ON ca.folio = p.folio
			LEFT JOIN naps n ON n.nap = p.nap
			LEFT JOIN empl_usuarios eu_nap ON eu_nap.no_empleado = n.no_empleado
			WHERE p.folio = ?
			LIMIT 1
		", [$folio])->row_array();

		if (!$query) {
			echo json_encode(['success' => false, 'message' => 'Folio no encontrado']);
			return;
		}

		echo json_encode(['success' => true, 'data' => $query]);
	}


	// POST /api/expediente-crear
	public function expediente_crear() {
		$json = $this->input->raw_input_stream;
		$data = json_decode($json, true);

		$tipo_persona = $data['tipo_persona'] ?? '';
		$nombre       = $data['nombre']       ?? '';
		$email        = $data['email']        ?? '';
		$celular      = $data['celular']      ?? '';
		$factura      = $data['factura']      ?? 0;
		$rfc          = $data['rfc']          ?? '';
		$no_empleado  = $data['no_empleado']  ?? '';
		$clave        = $data['clave']        ?? '';

		if (!$tipo_persona || !$nombre) {
			echo json_encode(['success' => false, 'message' => 'Tipo y nombre requeridos']);
			return;
		}

		$uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);

		$this->db->query("
			INSERT INTO expedientes (uuid, tipo_persona, nombre, email, celular, factura, rfc, creado_por, clave_creador)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
		", [$uuid, $tipo_persona, $nombre, $email, $celular, $factura, $rfc, $no_empleado, $clave]);

		$id = $this->db->insert_id();

		echo json_encode(['success' => true, 'data' => ['id' => $id, 'uuid' => $uuid]]);
	}

	// GET /api/expedientes-lista
	public function expedientes_lista() {
		$no_empleado = $this->input->get('no_empleado') ?? '';

		$query = $this->db->query("
			SELECT
				e.id, e.uuid, e.tipo_persona, e.nombre, e.email,
				e.celular, e.factura, e.rfc, e.estatus, e.creado_en,
				COUNT(ed.id) AS total_docs
			FROM expedientes e
			LEFT JOIN expediente_docs ed ON ed.expediente_id = e.id
			WHERE e.creado_por = ?
			GROUP BY e.id
			ORDER BY e.creado_en DESC
		", [$no_empleado]);

		echo json_encode(['success' => true, 'data' => $query->result_array()]);
	}

	// GET /api/expediente-detalle?id=1
	public function expediente_detalle() {
		$id          = $this->input->get('id')          ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';

		$exp = $this->db->query("
			SELECT * FROM expedientes WHERE id = ? AND creado_por = ? LIMIT 1
		", [$id, $no_empleado])->row_array();

		if (!$exp) {
			echo json_encode(['success' => false, 'message' => 'Expediente no encontrado']);
			return;
		}

		$docs = $this->db->query("
			SELECT id, tipo_doc, nombre, ruta, tamanio, creado_en FROM expediente_docs
			WHERE expediente_id = ?
			ORDER BY creado_en ASC
		", [$id])->result_array();

		foreach ($docs as &$d) {
			$d['url'] = 'https://www.sis-ca.com.mx:7863/afore/expedientes/' . $d['ruta'];
		}
		unset($d);

		$exp['docs'] = $docs;
		echo json_encode(['success' => true, 'data' => $exp]);
	}

	// POST /api/expediente-subir-doc (multipart)
	public function expediente_subir_doc() {
		$expediente_id = $this->input->post('expediente_id') ?? '';
		$tipo_doc      = $this->input->post('tipo_doc')      ?? '';
		$no_empleado   = $this->input->post('no_empleado')   ?? '';

		if (!$expediente_id || !$tipo_doc) {
			echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
			return;
		}

		// Verificar que el expediente pertenece al usuario
		$exp = $this->db->query("
			SELECT id FROM expedientes WHERE id = ? AND creado_por = ? LIMIT 1
		", [$expediente_id, $no_empleado])->row_array();

		if (!$exp) {
			echo json_encode(['success' => false, 'message' => 'Expediente no encontrado']);
			return;
		}

		if (!isset($_FILES['archivo'])) {
			echo json_encode(['success' => false, 'message' => 'No se recibió archivo']);
			return;
		}

		$archivo    = $_FILES['archivo'];
		$extension  = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
		$permitidas = ['pdf', 'jpg', 'jpeg', 'png'];

		if (!in_array($extension, $permitidas)) {
			echo json_encode(['success' => false, 'message' => 'Tipo no permitido']);
			return;
		}

		$carpeta = '/var/www/html/afore/expedientes/' . $expediente_id . '/';
		if (!is_dir($carpeta)) mkdir($carpeta, 0775, true);

		$nombre_final  = $expediente_id . '_' . preg_replace('/[^a-z0-9]/', '_', strtolower($tipo_doc)) . '_' . date('Ymd_His') . '.' . $extension;
		$ruta_completa = $carpeta . $nombre_final;

		if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
			echo json_encode(['success' => false, 'message' => 'Error al guardar']);
			return;
		}

		// Eliminar doc anterior del mismo tipo si existe
		$anterior = $this->db->query("
			SELECT id, ruta FROM expediente_docs WHERE expediente_id = ? AND tipo_doc = ? LIMIT 1
		", [$expediente_id, $tipo_doc])->row_array();

		if ($anterior) {
			@unlink('/var/www/html/afore/expedientes/' . $anterior['ruta']);
			$this->db->query("DELETE FROM expediente_docs WHERE id = ?", [$anterior['id']]);
		}

		$this->db->query("
			INSERT INTO expediente_docs (expediente_id, tipo_doc, nombre, ruta, tamanio)
			VALUES (?, ?, ?, ?, ?)
		", [$expediente_id, $tipo_doc, $nombre_final, $expediente_id . '/' . $nombre_final, $archivo['size']]);

		// Verificar si el expediente está completo
		$this->verificarCompletitud($expediente_id);

		echo json_encode([
			'success' => true,
			'data'    => [
				'url' => 'https://www.sis-ca.com.mx:7863/afore/expedientes/' . $expediente_id . '/' . $nombre_final
			]
		]);
	}

	private function verificarCompletitud($expediente_id) {
		$exp = $this->db->query("
			SELECT tipo_persona FROM expedientes WHERE id = ? LIMIT 1
		", [$expediente_id])->row_array();

		if (!$exp) return;

		$docs_fisica  = ['ine_frente', 'ine_reverso', 'curp', 'situacion_fiscal', 'comprobante_domicilio', 'estado_cuenta'];
		$docs_moral   = ['ine_representante', 'comprobante_domicilio_fiscal', 'estado_cuenta_moral', 'acta_constitutiva', 'rfc_empresa'];
		$requeridos   = $exp['tipo_persona'] === 'moral' ? $docs_moral : $docs_fisica;

		$docs_subidos = $this->db->query("
			SELECT tipo_doc FROM expediente_docs WHERE expediente_id = ?
		", [$expediente_id])->result_array();

		$subidos = array_column($docs_subidos, 'tipo_doc');
		$completo = count(array_intersect($requeridos, $subidos)) === count($requeridos);

		$this->db->query("
			UPDATE expedientes SET estatus = ? WHERE id = ?
		", [$completo ? 'completo' : 'incompleto', $expediente_id]);
	}

	// POST /api/expediente-generar-token
	public function expediente_generar_token() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$id          = $data['id']          ?? '';
		$no_empleado = $data['no_empleado'] ?? '';

		$exp = $this->db->query("
			SELECT id FROM expedientes WHERE id = ? AND creado_por = ? LIMIT 1
		", [$id, $no_empleado])->row_array();

		if (!$exp) {
			echo json_encode(['success' => false, 'message' => 'Expediente no encontrado']);
			return;
		}

		$token   = bin2hex(random_bytes(32));
		$expira  = date('Y-m-d H:i:s', strtotime('+72 hours'));

		$this->db->query("
			UPDATE expedientes SET token_acceso = ?, token_expira = ?, token_usado = 0 WHERE id = ?
		", [$token, $expira, $id]);

		echo json_encode(['success' => true, 'data' => ['token' => $token, 'expira' => $expira]]);
	}

	// GET /api/expediente-token?token=xxx
	public function expediente_token() {
		$token = $this->input->get('token') ?? '';

		if (!$token) {
			echo json_encode(['success' => false, 'message' => 'Token requerido']);
			return;
		}

		$exp = $this->db->query("
			SELECT e.*, COUNT(ed.id) AS total_docs
			FROM expedientes e
			LEFT JOIN expediente_docs ed ON ed.expediente_id = e.id
			WHERE e.token_acceso = ?
			AND e.token_expira > NOW()
			GROUP BY e.id
			LIMIT 1
		", [$token])->row_array();

		if (!$exp) {
			echo json_encode(['success' => false, 'message' => 'Token inválido o expirado']);
			return;
		}

		$docs = $this->db->query("
			SELECT id, tipo_doc, nombre, ruta, tamanio FROM expediente_docs
			WHERE expediente_id = ?
		", [$exp['id']])->result_array();

		foreach ($docs as &$d) {
			$d['url'] = 'https://www.sis-ca.com.mx:7863/afore/expedientes/' . $d['ruta'];
		}
		unset($d);

		$exp['docs'] = $docs;

		echo json_encode(['success' => true, 'data' => $exp]);
	}

	// POST /api/expediente-subir-doc-token (sin login)
	public function expediente_subir_doc_token() {
		$token    = $this->input->post('token')    ?? '';
		$tipo_doc = $this->input->post('tipo_doc') ?? '';

		if (!$token || !$tipo_doc) {
			echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
			return;
		}

		$exp = $this->db->query("
			SELECT id, tipo_persona FROM expedientes
			WHERE token_acceso = ? AND token_expira > NOW()
			LIMIT 1
		", [$token])->row_array();

		if (!$exp) {
			echo json_encode(['success' => false, 'message' => 'Token inválido o expirado']);
			return;
		}

		if (!isset($_FILES['archivo'])) {
			echo json_encode(['success' => false, 'message' => 'No se recibió archivo']);
			return;
		}

		$archivo   = $_FILES['archivo'];
		$extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
		$permitidas = ['pdf', 'jpg', 'jpeg', 'png'];

		if (!in_array($extension, $permitidas)) {
			echo json_encode(['success' => false, 'message' => 'Tipo no permitido']);
			return;
		}

		$carpeta = '/var/www/html/afore/expedientes/' . $exp['id'] . '/';
		if (!is_dir($carpeta)) mkdir($carpeta, 0775, true);

		$nombre_final  = $exp['id'] . '_' . preg_replace('/[^a-z0-9]/', '_', strtolower($tipo_doc)) . '_' . date('Ymd_His') . '.' . $extension;
		$ruta_completa = $carpeta . $nombre_final;

		if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
			echo json_encode(['success' => false, 'message' => 'Error al guardar']);
			return;
		}

		// Eliminar anterior del mismo tipo
		$anterior = $this->db->query("
			SELECT id, ruta FROM expediente_docs
			WHERE expediente_id = ? AND tipo_doc = ? LIMIT 1
		", [$exp['id'], $tipo_doc])->row_array();

		if ($anterior) {
			@unlink('/var/www/html/afore/expedientes/' . $anterior['ruta']);
			$this->db->query("DELETE FROM expediente_docs WHERE id = ?", [$anterior['id']]);
		}

		$this->db->query("
			INSERT INTO expediente_docs (expediente_id, tipo_doc, nombre, ruta, tamanio)
			VALUES (?, ?, ?, ?, ?)
		", [$exp['id'], $tipo_doc, $nombre_final, $exp['id'] . '/' . $nombre_final, $archivo['size']]);

		$this->verificarCompletitud($exp['id']);

		echo json_encode(['success' => true]);
	}

	// POST /api/expediente-actualizar-datos-token
	public function expediente_actualizar_datos_token() {
		$json     = $this->input->raw_input_stream;
		$data     = json_decode($json, true);
		$token    = $data['token']   ?? '';
		$nombre   = $data['nombre']  ?? '';
		$email    = $data['email']   ?? '';
		$celular  = $data['celular'] ?? '';
		$rfc      = $data['rfc']     ?? '';
		$factura  = $data['factura'] ?? 0;

		$exp = $this->db->query("
			SELECT id FROM expedientes WHERE token_acceso = ? AND token_expira > NOW() LIMIT 1
		", [$token])->row_array();

		if (!$exp) {
			echo json_encode(['success' => false, 'message' => 'Token inválido o expirado']);
			return;
		}

		$this->db->query("
			UPDATE expedientes SET nombre = ?, email = ?, celular = ?, rfc = ?, factura = ?
			WHERE id = ?
		", [$nombre, $email, $celular, $rfc, $factura, $exp['id']]);

		echo json_encode(['success' => true]);
	}


	private function get_feriados_mexico($anio) {
		$feriados = [
			// Fijos
			"{$anio}-01-01", // Año nuevo
			"{$anio}-02-05", // Constitución (primer lunes de febrero — ajuste)
			"{$anio}-03-21", // Natalicio Juárez (tercer lunes de marzo — ajuste)
			"{$anio}-05-01", // Día del trabajo
			"{$anio}-09-16", // Independencia
			"{$anio}-11-20", // Revolución (tercer lunes de noviembre — ajuste)
			"{$anio}-12-25", // Navidad
		];

		// Ajuste LFT: algunos feriados se mueven al lunes más cercano
		// Constitución — primer lunes de febrero
		$feriados[] = $this->primer_lunes_de($anio, 2);
		// Natalicio Juárez — tercer lunes de marzo
		$feriados[] = $this->tercer_lunes_de($anio, 3);
		// Revolución — tercer lunes de noviembre
		$feriados[] = $this->tercer_lunes_de($anio, 11);

		// Elecciones (cada 3 y 6 años) — agregar manualmente si aplica
		// 2027-06-06 es elección intermedia
		if ($anio == 2027) $feriados[] = '2027-06-06';

		return array_unique($feriados);
	}

	private function primer_lunes_de($anio, $mes) {
		$fecha = new DateTime("{$anio}-{$mes}-01");
		$dia   = (int)$fecha->format('N'); // 1=lun
		$diff  = ($dia == 1) ? 0 : (8 - $dia);
		$fecha->modify("+{$diff} days");
		return $fecha->format('Y-m-d');
	}

	private function tercer_lunes_de($anio, $mes) {
		$primer_lunes = new DateTime($this->primer_lunes_de($anio, $mes));
		$primer_lunes->modify('+14 days');
		return $primer_lunes->format('Y-m-d');
	}

	private function sumar_dias_habiles($fecha_str, $dias = 3) {
		$fecha = DateTime::createFromFormat('d/m/Y', $fecha_str);
		if (!$fecha) return $fecha_str;

		$anios_cache = [];
		$contados    = 0;

		while ($contados < $dias) {
			$fecha->modify('+1 day');
			$dia_semana = (int)$fecha->format('N');

			// Saltar fines de semana
			if ($dia_semana >= 6) continue;

			$anio     = (int)$fecha->format('Y');
			$fecha_iso = $fecha->format('Y-m-d');

			// Cargar feriados del año si no están cacheados
			if (!isset($anios_cache[$anio])) {
				$anios_cache[$anio] = $this->get_feriados_mexico($anio);
			}

			// Saltar feriados
			if (in_array($fecha_iso, $anios_cache[$anio])) continue;

			$contados++;
		}

		return $fecha->format('d/m/Y');
	}

	private function fecha_dentro_ventana_habil($fec_tramite_str) {
		$hoy         = new DateTime();
		$hoy->setTime(23, 59, 59); // fin del día de hoy

		$fec_tramite = DateTime::createFromFormat('d/m/Y', $fec_tramite_str);
		if (!$fec_tramite) return false;
		$fec_tramite->setTime(0, 0, 0); // inicio del día del trámite

		$fecha_limite_str = $this->sumar_dias_habiles($fec_tramite_str, 3);
		$fecha_limite     = DateTime::createFromFormat('d/m/Y', $fecha_limite_str);
		$fecha_limite->setTime(23, 59, 59); // fin del último día hábil

		return $hoy >= $fec_tramite && $hoy <= $fecha_limite;
	}

	// GET /api/notificaciones
	public function notificaciones() {
		$no_empleado = $this->input->get('no_empleado') ?? '';

		$query = $this->db->query("
			SELECT
				nd.id,
				n.titulo,
				n.mensaje           AS cuerpo,
				n.tipo_destinatario AS tipo,
				n.referencia,
				n.tipo_ref,
				nd.leida,
				nd.enviado_en       AS creado_en
			FROM notificaciones_detalle nd
			INNER JOIN notificaciones n ON n.id = nd.id_notificacion
			WHERE nd.no_empleado = ?
			AND nd.estatus = 'enviado'
			AND nd.eliminada = 0
			ORDER BY nd.enviado_en DESC
			LIMIT 30
		", [$no_empleado])->result_array();

		$no_leidas = count(array_filter($query, fn($n) => $n['leida'] == 0));

		echo json_encode([
			'success'   => true,
			'data'      => $query,
			'no_leidas' => $no_leidas,
		]);
	}

	// POST /api/notificaciones-leer
	public function notificaciones_leer() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$no_empleado = $data['no_empleado'] ?? '';
		$id          = $data['id']          ?? null;

		if ($id) {
			$this->db->query("
				UPDATE notificaciones_detalle SET leida = 1
				WHERE id = ? AND no_empleado = ?
			", [$id, $no_empleado]);
		} else {
			$this->db->query("
				UPDATE notificaciones_detalle SET leida = 1
				WHERE no_empleado = ? AND estatus = 'enviado'
			", [$no_empleado]);
		}

		echo json_encode(['success' => true]);
	}

	// GET /api/comentarios?tipo=traspaso&referencia=8080416819
	public function comentarios() {
		$tipo       = $this->input->get('tipo')       ?? '';
		$referencia = $this->input->get('referencia') ?? '';

		if (!$tipo || !$referencia) {
			echo json_encode(['success' => false, 'message' => 'Datos requeridos']);
			return;
		}

		$query = $this->db->query("
			SELECT id, tipo, referencia, clave_autor, nombre_autor,
				origen, mensaje, creado_en
			FROM comentarios
			WHERE tipo = ? AND referencia = ?
			ORDER BY creado_en ASC
		", [$tipo, $referencia]);

		echo json_encode(['success' => true, 'data' => $query->result_array()]);
	}

	public function aceptar_tyc() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$no_empleado = $data['no_empleado'] ?? '';

		if (!$no_empleado) {
			echo json_encode(['success' => false]);
			return;
		}

		$this->db->query("
			UPDATE push_tokens SET acepto_tyc = 1, fecha_acepto_tyc = NOW()
			WHERE no_empleado = ?
		", [$no_empleado]);

		echo json_encode(['success' => true]);
	}

	public function comentarios_agregar() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$tipo        = $data['tipo']        ?? '';
		$referencia  = $data['referencia']  ?? '';
		$mensaje     = $data['mensaje']     ?? '';
		$no_empleado = $data['no_empleado'] ?? '';

		if (!$tipo || !$referencia || !$mensaje) {
			echo json_encode(['success' => false, 'message' => 'Datos requeridos']);
			return;
		}

		$usuario = $this->db->query("
			SELECT clave, nombre FROM empl_usuarios WHERE no_empleado = ? LIMIT 1
		", [$no_empleado])->row_array();

		if (!$usuario) {
			echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
			return;
		}

		$this->db->query("
			INSERT INTO notificaciones 
				(titulo, mensaje, tipo_destinatario, fecha_programada, estatus, creado_por, referencia, tipo_ref)
			VALUES (?, ?, 'personal', NOW(), 'completada', 'sistema', ?, ?)
		", [$titulo, $cuerpo, $referencia, $tipo]);

		// Mandar push a analistas SISCA
		$this->notificar_comentario_sisca($tipo, $referencia, $usuario['nombre'], $mensaje);

		echo json_encode(['success' => true]);
	}

	private function notificar_comentario_sisca($tipo, $referencia, $nombre_autor, $mensaje) {
		$titulo = "💬 Nuevo comentario en " . ($tipo === 'traspaso' ? "folio {$referencia}" : "expediente");
		$cuerpo = "{$nombre_autor}: " . substr($mensaje, 0, 80);

		$this->db->query("
			INSERT INTO notificaciones 
				(titulo, mensaje, tipo_destinatario, fecha_programada, estatus, creado_por)
			VALUES (?, ?, 'personal', NOW(), 'completada', 'sistema')
		", [$titulo, $cuerpo]);

		$id_notif = $this->db->insert_id();

		// Insertar para TODOS los usuarios aunque no tengan token
		$usuarios = $this->db->query("
			SELECT eu.no_empleado, pt.token, pt.plataforma
			FROM empl_usuarios eu
			LEFT JOIN push_tokens pt ON pt.no_empleado = eu.no_empleado
		")->result_array();

		foreach ($usuarios as $u) {
			$this->db->query("
				INSERT INTO notificaciones_detalle 
					(id_notificacion, no_empleado, token, estatus, enviado_en, leida)
				VALUES (?, ?, ?, 'enviado', NOW(), 0)
			", [$id_notif, $u['no_empleado'], $u['token'] ?? '']);

			// Solo mandar push si tiene token
			if (!empty($u['token'])) {
				$this->enviar_push_individual($u['token'], $titulo, $cuerpo, $u['plataforma']);
			}
		}
	}

	private function enviar_push_individual($token, $titulo, $cuerpo, $plataforma) {
		$credentials = json_decode(
			file_get_contents('/var/www/html/carobra-api/storage/firebase-credentials.json'), true
		);

		$url = 'https://fcm.googleapis.com/v1/projects/' . $credentials['project_id'] . '/messages:send';

		$mensaje = [
			'message' => [
				'token'        => $token,
				'notification' => ['title' => $titulo, 'body' => $cuerpo],
				'data'         => ['tipo' => 'comentario'],
			]
		];

		// Obtener access token
		$access_token = $this->get_firebase_access_token($credentials);
		if (!$access_token) return;

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $access_token,
				'Content-Type: application/json',
			],
			CURLOPT_POSTFIELDS => json_encode($mensaje),
		]);
		curl_exec($ch);
		curl_close($ch);
	}

	// POST /api/notificaciones-limpiar
	public function notificaciones_limpiar() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$no_empleado = $data['no_empleado'] ?? '';

		if (!$no_empleado) {
			echo json_encode(['success' => false]);
			return;
		}

		$this->db->query("
			UPDATE notificaciones_detalle 
			SET eliminada = 1
			WHERE no_empleado = ?
			AND leida = 1
		", [$no_empleado]);

		echo json_encode(['success' => true]);
	}


	// GET /api/super-buscar-folio?q=
	public function super_buscar_folio() {
		$q           = $this->input->get('q')           ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';

		// Verificar Super Spartan
		$super = $this->db->query("
			SELECT sc.id FROM spartan_config sc
			INNER JOIN empl_usuarios eu ON eu.no_empleado = sc.no_empleado
			WHERE sc.no_empleado = ? AND sc.es_super = 1
			LIMIT 1
		", [$no_empleado])->row_array();

		if (!$super) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		if (strlen(trim($q)) < 2) {
			echo json_encode(['success' => true, 'data' => []]);
			return;
		}

		$query = $this->db->query("
			SELECT
				cb.folio,
				cb.nombre_Completo  AS trabajador,
				cb.fec_traspaso,
				cb.fec_tramite,
				cb.saldo,
				cb.afo_cedente,
				cb.afo_receptora,
				cb.sol_traspaso,
				cb.semana,
				cb.antiguedad,
				cb.cancelacion,
				cb.pplus,
				af_ced.afore  AS nombre_afo_cedente,
				af_rec.afore  AS nombre_afo_receptora,
				COALESCE(eu2.nombre, eu.nombre) AS nombre_comisionista,
				rc.folio      AS ya_capturado,
				rc.medio_cancelacion,
				rc.pagado,
				rc.clave      AS clave_capturista,
				eu2.nombre    AS nombre_capturista,
				DATE_FORMAT(
					DATE_ADD(STR_TO_DATE(cb.fec_tramite, '%d/%m/%Y'), INTERVAL 3 DAY),
					'%d/%m/%Y'
				) AS fecha_limite
			FROM clawback cb
			LEFT JOIN afores af_ced ON af_ced.clave = cb.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = cb.afo_receptora
			LEFT JOIN produccion p ON p.folio = cb.folio
			LEFT JOIN empl_usuarios eu ON eu.no_empleado = p.no_empleado
			LEFT JOIN retenciones_captura rc ON rc.folio = cb.folio AND rc.activo = 1
			LEFT JOIN empl_usuarios eu2 ON eu2.clave = rc.clave
			WHERE cb.sol_traspaso LIKE ?
			LIMIT 5
		", ['%' . $q . '%']);

		$rows = $query->result_array();
		foreach ($rows as &$r) {
			$r['ya_capturado'] = !empty($r['ya_capturado']);
		}
		unset($r);

		echo json_encode(['success' => true, 'data' => $rows]);
	}
	
	// GET /api/super-reporte-spartan?semana=295S.08...
	public function super_reporte_spartan() {
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$semana      = $this->input->get('semana')      ?? '';

		$super = $this->db->query("
			SELECT id FROM spartan_config WHERE no_empleado = ? AND es_super = 1 LIMIT 1
		", [$no_empleado])->row_array();

		if (!$super) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		// Semanas disponibles
		if (!$semana) {
			$semanas = $this->db->query("
				SELECT DISTINCT semana FROM clawback
				WHERE semana IS NOT NULL AND semana != ''
				ORDER BY semana DESC
			")->result_array();

			echo json_encode(['success' => true, 'semanas' => array_column($semanas, 'semana')]);
			return;
		}

		$query = $this->db->query("
			SELECT
				c.folio,
				c.sol_traspaso,
				c.fec_traspaso,
				c.saldo,
				c.semana,
				c.cancelacion,
				c.fec_tramite,
				c.afo_cedente,
				c.afo_receptora,
				af_ced.afore AS nombre_afo_cedente,
				af_rec.afore AS nombre_afo_receptora,
				sc.clave,
				sc.fecha_captura,
				sc.medio_cancelacion,
				eu.nombre AS nombre_spartan
			FROM clawback c
			INNER JOIN retenciones_captura sc ON c.sol_traspaso = sc.sol_traspaso
			INNER JOIN empl_usuarios eu ON sc.clave = eu.clave
			LEFT JOIN afores af_ced ON af_ced.clave = c.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = c.afo_receptora
			WHERE c.semana = ?
			ORDER BY sc.fecha_captura DESC
		", [$semana]);

		echo json_encode(['success' => true, 'data' => $query->result_array()]);
	}

	// GET /api/spartan-buscar-folio?q=&no_empleado=
	public function spartan_buscar_folio() {
		$q           = $this->input->get('q')           ?? '';
		$no_empleado = $this->input->get('no_empleado') ?? '';

		// Verificar Spartan
		$spartan_query = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			INNER JOIN carobra.empleados e ON eu.clave = e.clave
			WHERE eu.no_empleado = ?
			AND e.deportenis = 1
			AND e.no_prom_dep = 'ESPARTANO'
			LIMIT 1
		", [$no_empleado]);
		if ($spartan_query === false) {
			$err = $this->db->error();
			echo json_encode(['success' => false, 'message' => 'Error auth query', 'debug' => $err]);
			return;
		}
		$spartan = $spartan_query->row_array();

		if (!$spartan) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		if (strlen(trim($q)) < 2) {
			echo json_encode(['success' => true, 'data' => []]);
			return;
		}

		$query = $this->db->query("
			SELECT
				cb.folio,
				cb.nombre_Completo  AS trabajador,
				cb.fec_traspaso,
				cb.fec_tramite,
				cb.saldo,
				cb.afo_cedente,
				cb.afo_receptora,
				cb.sol_traspaso,
				cb.semana,
				cb.antiguedad,
				cb.cancelacion,
				cb.pplus,
				af_ced.afore  AS nombre_afo_cedente,
				af_rec.afore  AS nombre_afo_receptora,
				eu.nombre     AS nombre_comisionista,
				rc.folio      AS ya_capturado,
				DATE_FORMAT(
					DATE_ADD(STR_TO_DATE(cb.fec_tramite, '%d/%m/%Y'), INTERVAL 3 DAY),
					'%d/%m/%Y'
				) AS fecha_limite
			FROM clawback cb
			LEFT JOIN afores af_ced ON af_ced.clave = cb.afo_cedente
			LEFT JOIN afores af_rec ON af_rec.clave = cb.afo_receptora
			LEFT JOIN produccion p ON p.folio = cb.folio
			LEFT JOIN empl_usuarios eu ON eu.no_empleado = p.no_empleado
			LEFT JOIN retenciones_captura rc ON rc.sol_traspaso = cb.sol_traspaso
			WHERE cb.sol_traspaso LIKE ?
			LIMIT 10
		", ['%' . $q . '%']);

		$rows = $query->result_array();
		foreach ($rows as &$r) {
			$r['ya_capturado'] = !empty($r['ya_capturado']);
		}
		unset($r);

		echo json_encode(['success' => true, 'data' => $rows]);
	}

	// GET /api/sepomex?cp=45030
	public function sepomex() {
		$cp = $this->input->get('cp') ?? '';

		if (!$cp || strlen(trim($cp)) !== 5) {
			echo json_encode(['success' => false, 'message' => 'CP de 5 dígitos requerido']);
			return;
		}

		$query = $this->db->query("
			SELECT
				s.d_codigo,
				s.d_asenta,
				s.d_mnpio,
				s.d_estado
			FROM sepomex.sepomex s
			WHERE s.d_codigo = ?
			ORDER BY s.d_asenta ASC
		", [$cp]);

		$data = $query->result_array();

		if (empty($data)) {
			echo json_encode(['success' => false, 'message' => 'CP no encontrado']);
			return;
		}

		echo json_encode([
			'success'   => true,
			'cp'        => $cp,
			'municipio' => $data[0]['d_mnpio'],
			'estado'    => $data[0]['d_estado'],
			'colonias'  => array_column($data, 'd_asenta'),
		]);
	}


	// GET /api/super-admin-capturas?q=
	public function super_admin_capturas() {
		$no_empleado = $this->input->get('no_empleado') ?? '';
		$q           = $this->input->get('q')           ?? '';
		$semana = $this->input->get('semana') ?? '';


		// Solo JOCATORA y ANKAHEHE
		$admin = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			WHERE eu.no_empleado = ?
			AND eu.clave IN ('JOCATORA', 'ANKAHEHE', 'GAGORO')
			LIMIT 1
		", [$no_empleado])->row_array();

		if (!$admin) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		$where = "WHERE rc.activo = 1";
		$params = [];

		if ($semana) {
			$where .= " AND c.semana = ?";
			$params[] = $semana;
		}

		if (strlen(trim($q)) >= 2) {
			$where .= " AND (rc.folio LIKE ? OR rc.sol_traspaso LIKE ? OR eu.nombre LIKE ?)";
			$params[] = '%' . $q . '%';
			$params[] = '%' . $q . '%';
			$params[] = '%' . $q . '%';
		}

		$query = $this->db->query("
			SELECT
				rc.id,
				rc.folio,
				rc.sol_traspaso,
				rc.clave,
				rc.medio_cancelacion,
				rc.fecha_captura,
				rc.pagado,
				eu.nombre AS nombre_spartan,
				c.nombre_Completo AS trabajador,
				c.semana,
				c.saldo,
				c.cancelacion,
				c.fec_tramite,
				af.afore AS nombre_afo_cedente
			FROM retenciones_captura rc
			INNER JOIN clawback c ON c.sol_traspaso = rc.sol_traspaso
			LEFT JOIN empl_usuarios eu ON eu.clave = rc.clave
			LEFT JOIN afores af ON af.clave = c.afo_cedente
			{$where}
			ORDER BY rc.fecha_captura DESC
			LIMIT 50
		", $params);

		echo json_encode(['success' => true, 'data' => $query->result_array()]);
	}

	// POST /api/super-admin-reasignar
	public function super_admin_reasignar() {
		$json        = $this->input->raw_input_stream;
		$data        = json_decode($json, true);
		$no_empleado = $data['no_empleado']  ?? '';
		$id          = $data['id']           ?? 0;
		$accion      = $data['accion']       ?? ''; // quitada | reasignada
		$clave_nueva = $data['clave_nueva']  ?? '';
		$motivo      = $data['motivo']       ?? '';

		

		// Solo JOCATORA y ANKAHEHE
		$admin = $this->db->query("
			SELECT eu.clave FROM empl_usuarios eu
			WHERE eu.no_empleado = ?
			AND eu.clave IN ('JOCATORA', 'ANKAHEHE', 'GAGORO')
			LIMIT 1
		", [$no_empleado])->row_array();

		if (!$admin) {
			echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
			return;
		}

		// Obtener captura actual
		$captura = $this->db->query("
			SELECT * FROM retenciones_captura WHERE id = ? AND activo = 1 LIMIT 1
		", [$id])->row_array();

		// Verificar que no esté pagado
		if ($captura['pagado'] == 1 && !empty($captura['fec_pago'])) {
			echo json_encode([
				'success' => false,
				'message' => 'No se puede modificar — esta captura ya fue pagada el ' . $captura['fec_pago']
			]);
			return;
		}

		if (!$captura) {
			echo json_encode(['success' => false, 'message' => 'Captura no encontrada']);
			return;
		}

		// Guardar historial
		$this->db->query("
			INSERT INTO retenciones_historial
				(folio, sol_traspaso, clave_anterior, clave_nueva, accion, motivo, realizado_por)
			VALUES (?, ?, ?, ?, ?, ?, ?)
		", [
			$captura['folio'],
			$captura['sol_traspaso'],
			$captura['clave'],
			$accion === 'reasignada' ? $clave_nueva : null,
			$accion,
			$motivo,
			$admin['clave'],
		]);

		if ($accion === 'quitada') {
			// Borrado lógico
			$this->db->query("
				UPDATE retenciones_captura
				SET activo = 0, reasignado_por = ?, reasignado_en = NOW(), motivo = ?
				WHERE id = ?
			", [$admin['clave'], $motivo, $id]);

		} elseif ($accion === 'reasignada') {
			if (!$clave_nueva) {
				echo json_encode(['success' => false, 'message' => 'Clave del nuevo Spartan requerida']);
				return;
			}
			// Borrado lógico del actual
			$this->db->query("
				UPDATE retenciones_captura
				SET activo = 0, reasignado_por = ?, reasignado_en = NOW(), motivo = ?
				WHERE id = ?
			", [$admin['clave'], $motivo, $id]);

			// Insertar nueva asignación
			$this->db->query("
				INSERT INTO retenciones_captura
					(folio, sol_traspaso, clave, medio_cancelacion, fecha_captura)
				VALUES (?, ?, ?, ?, NOW())
			", [
				$captura['folio'],
				$captura['sol_traspaso'],
				$clave_nueva,
				$captura['medio_cancelacion'],
			]);
		}

		echo json_encode(['success' => true, 'message' => 'Operación realizada correctamente']);
	}
}
