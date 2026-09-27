<?php
// Sesiones en volumen compartido
$sessions_path = '/var/www/html/sessions';
if (!is_dir($sessions_path)) @mkdir($sessions_path, 0777, true);
session_save_path($sessions_path);
session_start();

// Verificar logueo
if (!isset($_SESSION['usuario_logueado'])) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$usuario_actual = preg_replace('/[^a-zA-Z0-9_]/', '_', $_SESSION['usuario_logueado']);

// --- LÓGICA DE PERMISOS (LISTA BLANCA) ---
$editores = ['jandro', 'admin', 'alex']; 
$puede_editar = in_array($usuario_actual, $editores);

// Todos cargan el archivo centralizado
$archivo = '/var/www/html/peliculas_jandro.txt'; 

if (!file_exists($archivo) && $puede_editar) {
    file_put_contents($archivo, '[]');
}

function leerPeliculas($archivo) {
    if (!file_exists($archivo) || filesize($archivo) == 0) return [];
    $contenido = file_get_contents($archivo);
    return json_decode($contenido, true) ?? [];
}

function guardarPeliculas($archivo, $datos) {
    $tempFile = $archivo . '.tmp.' . uniqid();
    if (file_put_contents($tempFile, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
        rename($tempFile, $archivo);
        return true;
    }
    return false;
}

$peliculas = leerPeliculas($archivo);
$mensaje = "";

// Cargar mensajes flash desde la redirección
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'ok') $mensaje = "✅ Registro guardado con éxito.";
    if ($_GET['msg'] === 'borrado') $mensaje = "🗑️ Registro eliminado.";
    if ($_GET['msg'] === 'visto') $mensaje = "👀 Marcado como visto.";
    if ($_GET['msg'] === 'ep') $mensaje = "📺 ¡Episodio sumado!";
}

$editando = false;
$id_editar = '';
$pelicula_editar = [
    'titulo' => '', 'director' => '', 'anio' => '', 
    'visto' => 'No', 'link' => '', 'tipo' => 'pelicula',
    'temporada' => 1, 'episodio' => 0,
    'poster' => '', 'sinopsis' => ''
];

// --- PROCESAR ACCIONES (SOLO EDITORES) ---
if ($puede_editar) {
    // 1. Guardar Formulario
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_guardar'])) {
        $id = $_POST['id'] ?? '';
        $tipo = $_POST['tipo'] ?? 'pelicula';
        
        $nuevo = [
            'titulo' => htmlspecialchars(trim($_POST['titulo'])),
            'director' => htmlspecialchars(trim($_POST['director'])),
            'anio' => intval($_POST['anio']),
            'link' => htmlspecialchars(trim($_POST['link'] ?? '')),
            'tipo' => $tipo,
            'visto' => ($tipo === 'pelicula') ? (isset($_POST['visto']) ? 'Sí' : 'No') : 'No',
            'temporada' => ($tipo === 'serie') ? intval($_POST['temporada'] ?? 1) : 1,
            'episodio' => ($tipo === 'serie') ? intval($_POST['episodio'] ?? 0) : 0,
            'poster' => htmlspecialchars(trim($_POST['poster'] ?? '')),
            'sinopsis' => htmlspecialchars(trim($_POST['sinopsis'] ?? ''))
        ];

        // Mantenemos enlaces_temporada intacto si estamos editando
        if ($id !== '' && isset($peliculas[(int)$id]['enlaces_temporada'])) {
            $nuevo['enlaces_temporada'] = $peliculas[(int)$id]['enlaces_temporada'];
        }

        // Si estamos editando y no se pasó póster o sinopsis nueva, conservar las anteriores
        if ($id !== '' && isset($peliculas[(int)$id])) {
            if (empty($nuevo['poster']) && !empty($peliculas[(int)$id]['poster'])) {
                $nuevo['poster'] = $peliculas[(int)$id]['poster'];
            }
            if (empty($nuevo['sinopsis']) && !empty($peliculas[(int)$id]['sinopsis'])) {
                $nuevo['sinopsis'] = $peliculas[(int)$id]['sinopsis'];
            }
        }

        // Si el póster sigue vacío (ej: el usuario escribió el título y pulsó Guardar directamente)
        if (empty($nuevo['poster']) && !empty($nuevo['titulo'])) {
            $apiKey = '929738505228b9e8f2496db15a830421';
            $endpoint = ($tipo === 'serie') ? 'tv' : 'movie';
            $url = "https://api.themoviedb.org/3/search/{$endpoint}?api_key={$apiKey}&language=es-ES&region=ES&query=" . urlencode($nuevo['titulo']);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (!empty($res['results'][0]['poster_path'])) {
                $match = $res['results'][0];
                $nuevo['poster'] = 'https://image.tmdb.org/t/p/w500' . $match['poster_path'];
                if (empty($nuevo['sinopsis']) && !empty($match['overview'])) {
                    $nuevo['sinopsis'] = $match['overview'];
                }
                if (empty($nuevo['anio'])) {
                    $date = ($tipo === 'serie') ? ($match['first_air_date'] ?? '') : ($match['release_date'] ?? '');
                    if ($date) $nuevo['anio'] = intval(substr($date, 0, 4));
                }
            }
        }

        if ($id !== '') {
            if (isset($peliculas[(int)$id])) {
                $peliculas[(int)$id] = $nuevo;
            }
        } else {
            $peliculas[] = $nuevo;
        }
        guardarPeliculas($archivo, $peliculas);
        header('Location: index.php?msg=ok');
        exit;
    }

    // 2. Eliminar (Casteando ID a entero)
    if (isset($_GET['eliminar'])) {
        $id_eliminar = (int)$_GET['eliminar'];
        if (isset($peliculas[$id_eliminar])) {
            unset($peliculas[$id_eliminar]);
            $peliculas = array_values($peliculas); 
            guardarPeliculas($archivo, $peliculas);
            header('Location: index.php?msg=borrado');
            exit;
        }
    }

    // 3. Marcar Vista (Casteando ID a entero)
    if (isset($_GET['marcar_vista'])) {
        $id_marcar = (int)$_GET['marcar_vista'];
        if (isset($peliculas[$id_marcar])) {
            $peliculas[$id_marcar]['visto'] = 'Sí';
            guardarPeliculas($archivo, $peliculas);
            header('Location: index.php?msg=visto');
            exit;
        }
    }

    // 4. Sumar Episodio (Casteando ID a entero)
    if (isset($_GET['sumar_ep'])) {
        $id_marcar = (int)$_GET['sumar_ep'];
        if (isset($peliculas[$id_marcar]) && $peliculas[$id_marcar]['tipo'] === 'serie') {
            $peliculas[$id_marcar]['episodio']++;
            guardarPeliculas($archivo, $peliculas);
            header('Location: index.php?msg=ep');
            exit;
        }
    }

    // 5. Cargar edición (Casteando ID a entero)
    if (isset($_GET['editar'])) {
        $id_editar = (int)$_GET['editar'];
        if (isset($peliculas[$id_editar])) {
            $editando = true;
            $pelicula_editar = $peliculas[$id_editar];
            if (!isset($pelicula_editar['link'])) $pelicula_editar['link'] = '';
            if (!isset($pelicula_editar['tipo'])) $pelicula_editar['tipo'] = 'pelicula';
            if (!isset($pelicula_editar['temporada'])) $pelicula_editar['temporada'] = 1;
            if (!isset($pelicula_editar['episodio'])) $pelicula_editar['episodio'] = 0;
            if (!isset($pelicula_editar['poster'])) $pelicula_editar['poster'] = '';
            if (!isset($pelicula_editar['sinopsis'])) $pelicula_editar['sinopsis'] = '';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colección Audiovisual</title>
    <style>
        :root { 
            --primary: #4f46e5; 
            --primary-hover: #4338ca; 
            --serie-color: #9333ea; 
            --serie-hover: #7e22ce; 
            --success: #16a34a; 
            --warning: #d97706;
            --danger: #dc2626; 
            --bg-color: #f1f5f9; 
            --card-bg: #ffffff; 
            --text-main: #1e293b; 
            --text-muted: #64748b; 
            --border-color: #e2e8f0;
        }
        body { 
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; 
            background-color: var(--bg-color); 
            color: var(--text-main); 
            margin: 0; 
            padding: 20px; 
            line-height: 1.5;
        }
        .container { max-width: 1240px; margin: 0 auto; }
        
        /* HEADER */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: var(--card-bg); 
            padding: 18px 26px; 
            border-radius: 14px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.04); 
            margin-bottom: 25px; 
            flex-wrap: wrap; 
            gap: 15px;
            border: 1px solid var(--border-color);
        }
        h1 { margin: 0; font-size: 22px; display: flex; align-items: center; gap: 10px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { 
            background-color: #fee2e2; 
            color: var(--danger); 
            padding: 7px 15px; 
            border-radius: 8px; 
            text-decoration: none; 
            font-size: 13px; 
            font-weight: 700; 
            transition: all 0.2s;
        }
        .btn-logout:hover { background-color: var(--danger); color: white; }
        .alert { background-color: #dcfce7; color: #15803d; padding: 12px 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); border: 1px solid #bbf7d0; font-size: 14px; }
        .alert-info { background-color: #e0f2fe; color: #0369a1; border-color: #bae6fd; }

        /* FORMULARIO */
        .form-panel { 
            background: var(--card-bg); 
            padding: 25px; 
            border-radius: 14px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.04); 
            margin-bottom: 25px; 
            border: 1px solid var(--border-color);
            border-top: 4px solid var(--primary); 
        }
        .form-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 16px; 
            margin-bottom: 16px; 
        }
        .form-group label { display: block; margin-bottom: 7px; font-weight: 600; font-size: 13px; color: var(--text-main); }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 10px 12px; 
            border: 1px solid var(--border-color); 
            border-radius: 8px; 
            box-sizing: border-box; 
            font-family: inherit; 
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* AUTOCOMPLETE STYLES */
        .autocomplete-wrapper { position: relative; width: 100%; }
        .badge-tmdb {
            font-size: 11px;
            font-weight: 700;
            background: linear-gradient(135deg, #01b4e4 0%, #0d253f 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            letter-spacing: 0.3px;
        }
        .autocomplete-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: white;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
            max-height: 340px;
            overflow-y: auto;
            z-index: 1000;
        }
        .ac-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s;
        }
        .ac-item:last-child { border-bottom: none; }
        .ac-item:hover, .ac-item.ac-selected {
            background-color: #f8faff;
        }
        .ac-poster {
            width: 40px;
            height: 58px;
            border-radius: 5px;
            object-fit: cover;
            background: #e2e8f0;
            flex-shrink: 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .ac-info { flex: 1; min-width: 0; }
        .ac-title { font-weight: 700; font-size: 14px; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ac-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .ac-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            color: white;
            margin-left: 6px;
        }
        .ac-spinner {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border: 2px solid #cbd5e1;
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: translateY(-50%) rotate(360deg); } }

        /* POSTER PREVIEW IN FORM */
        .poster-preview-box {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .poster-preview-box img {
            width: 52px;
            height: 76px;
            border-radius: 6px;
            object-fit: cover;
            box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        }
        .btn-remove-poster {
            background: none;
            border: none;
            color: var(--danger);
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .btn-remove-poster:hover { background: #fee2e2; }

        /* BOTONES */
        .btn { padding: 9px 18px; border: none; border-radius: 8px; cursor: pointer; color: white; text-decoration: none; font-size: 13px; font-weight: 700; display: inline-block; transition: all 0.2s; }
        .btn-primary { background-color: var(--primary); }
        .btn-primary:hover { background-color: var(--primary-hover); transform: translateY(-1px); }
        .btn-outline { background: transparent; color: var(--text-main); border: 1px solid var(--border-color); }
        .btn-outline:hover { background: #f1f5f9; }

        /* CONTROLES DE BÚSQUEDA Y FILTRO */
        .controls-wrapper { 
            background: var(--card-bg); 
            padding: 16px 22px; 
            border-radius: 14px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
            margin-bottom: 25px; 
            display: flex; 
            flex-direction: column; 
            gap: 14px; 
            border: 1px solid var(--border-color);
        }
        .search-bar { 
            width: 100%; 
            padding: 12px 16px; 
            font-size: 15px; 
            border: 1px solid var(--border-color); 
            border-radius: 10px; 
            box-sizing: border-box; 
            outline: none; 
            background: #f8fafc; 
            transition: all 0.2s;
        }
        .search-bar:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12); }
        
        .filters { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-chip { 
            padding: 7px 15px; 
            border-radius: 20px; 
            font-size: 13px; 
            font-weight: 700; 
            cursor: pointer; 
            border: 1px solid var(--border-color); 
            background: white; 
            color: var(--text-muted); 
            transition: all 0.2s;
        }
        .filter-chip:hover { border-color: #cbd5e1; color: var(--text-main); }
        .filter-chip.active-all { background: var(--text-main); color: white; border-color: var(--text-main); }
        .filter-chip.active-pelicula { background: var(--primary); color: white; border-color: var(--primary); }
        .filter-chip.active-serie { background: var(--serie-color); color: white; border-color: var(--serie-color); }
        .filter-chip.active-visto { background: var(--success); color: white; border-color: var(--success); }
        .filter-chip.active-pendiente { background: var(--warning); color: white; border-color: var(--warning); }

        /* GRID DE TARJETAS */
        .movies-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .card { 
            background: var(--card-bg); 
            border-radius: 14px; 
            padding: 18px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.04); 
            border: 1px solid var(--border-color); 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
            border-left: 4px solid transparent; 
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 25px rgba(0,0,0,0.08); 
        }
        .card.type-pelicula { border-left-color: var(--primary); }
        .card.type-serie { border-left-color: var(--serie-color); }
        
        .card-top-layout { display: flex; gap: 14px; margin-bottom: 12px; }
        .card-poster {
            width: 78px;
            min-width: 78px;
            height: 114px;
            border-radius: 8px;
            object-fit: cover;
            background: #f1f5f9;
            box-shadow: 0 3px 8px rgba(0,0,0,0.12);
            flex-shrink: 0;
        }
        .card-poster-placeholder {
            width: 78px;
            min-width: 78px;
            height: 114px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: #94a3b8;
            flex-shrink: 0;
        }
        .card-content-main { flex: 1; min-width: 0; }
        
        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; gap: 8px; }
        .card-title { 
            font-size: 16px; 
            font-weight: 700; 
            margin: 0; 
            color: var(--text-main); 
            line-height: 1.25;
            word-break: break-word;
        }
        
        .badges-container { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; flex-shrink: 0; }
        .card-badge { padding: 3px 8px; border-radius: 20px; font-size: 10px; font-weight: 700; color: white; text-transform: uppercase; letter-spacing: 0.3px; }
        .badge-type-pelicula { background-color: var(--primary); }
        .badge-type-serie { background-color: var(--serie-color); }
        .badge-vista { background-color: var(--success); }
        .badge-pendiente { background-color: var(--warning); }
        .badge-progreso { background-color: #334155; }
        
        .card-meta { font-size: 13px; color: var(--text-muted); margin-bottom: 6px; line-height: 1.4; }
        .card-sinopsis {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-top: 4px;
        }
        
        /* Enlaces */
        .card-link a { 
            display: inline-block; 
            margin-bottom: 14px; 
            color: var(--primary); 
            text-decoration: none; 
            font-size: 13px; 
            font-weight: 700; 
            background: #eef2ff; 
            padding: 8px 12px; 
            border-radius: 8px; 
            width: 100%; 
            box-sizing: border-box; 
            text-align: center; 
            transition: all 0.2s;
        }
        .card-link a:hover { background: #e0e7ff; color: var(--primary-hover); }
        .episode-dropdown { 
            display: block; 
            margin-bottom: 14px; 
            width: 100%; 
            padding: 8px 12px; 
            border-radius: 8px; 
            border: 1px solid #c7d2fe; 
            background: #eef2ff; 
            color: var(--primary); 
            font-weight: 700; 
            font-size: 13px; 
            cursor: pointer; 
            text-align: center; 
            outline: none; 
            appearance: auto; 
        }
        .episode-dropdown:hover { background: #e0e7ff; }

        .card-actions { display: flex; gap: 8px; border-top: 1px solid #f1f5f9; padding-top: 14px; }
        .btn-small { padding: 6px 10px; font-size: 12px; flex: 1; text-align: center; border-radius: 6px; }
        .btn-success { background-color: var(--success); }
        .btn-serie { background-color: var(--serie-color); }
        .btn-danger { background-color: var(--danger); }
        .no-results { grid-column: 1 / -1; text-align: center; padding: 50px 20px; color: var(--text-muted); font-size: 16px; background: white; border-radius: 14px; border: 1px dashed var(--border-color); }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🍿 Colección de Jandro <?php echo !$puede_editar ? '(Modo Lectura)' : ''; ?></h1>
        <div class="user-info">
            <span>👤 Conectado: <strong><?php echo htmlspecialchars($_SESSION['usuario_logueado']); ?></strong></span>
            <a href="?logout" class="btn-logout">Salir</a>
        </div>
    </div>

    <?php if (!$puede_editar): ?>
        <div class="alert alert-info">👁️ <strong>Modo Visita:</strong> Estás viendo la colección. No tienes permisos de edición.</div>
    <?php endif; ?>
    <?php if ($mensaje): ?> <div class="alert"><?php echo $mensaje; ?></div> <?php endif; ?>

    <?php if ($puede_editar): ?>
    <div class="form-panel">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin:0; font-size: 17px; font-weight: 700;">
                <?php echo $editando ? '📝 Editando registro' : '➕ Añadir a la colección'; ?>
            </h2>
            <span class="badge-tmdb">✨ Autocompletado TMDb Activo</span>
        </div>
        
        <form action="index.php" method="POST" id="formColeccion">
            <input type="hidden" name="id" value="<?php echo $editando ? $id_editar : ''; ?>">
            <input type="hidden" name="poster" id="inputPoster" value="<?php echo htmlspecialchars($pelicula_editar['poster'] ?? ''); ?>">
            <input type="hidden" name="sinopsis" id="inputSinopsis" value="<?php echo htmlspecialchars($pelicula_editar['sinopsis'] ?? ''); ?>">

            <!-- PREVIEW DE PÓSTER SELECCIONADO -->
            <div id="posterPreviewContainer" class="poster-preview-box" style="display: <?php echo !empty($pelicula_editar['poster']) ? 'flex' : 'none'; ?>;">
                <img id="posterPreviewImg" src="<?php echo htmlspecialchars($pelicula_editar['poster'] ?? ''); ?>" alt="Carátula oficial">
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 700; color: var(--primary); font-size: 13px; margin-bottom: 3px;">
                        Carátula y datos oficiales cargados
                    </div>
                    <div id="sinopsisPreviewText" style="font-size: 12px; color: var(--text-muted); line-height: 1.35; max-height: 48px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                        <?php echo htmlspecialchars($pelicula_editar['sinopsis'] ?? ''); ?>
                    </div>
                </div>
                <button type="button" class="btn-remove-poster" onclick="quitarPoster()">✕ Quitar</button>
            </div>
            
            <div class="form-grid">
                <div class="form-group" style="max-width: 170px;">
                    <label>Tipo:</label>
                    <select name="tipo" id="selectorTipo" onchange="toggleCamposTipo()">
                        <option value="pelicula" <?php echo ($pelicula_editar['tipo'] === 'pelicula') ? 'selected' : ''; ?>>🎬 Película</option>
                        <option value="serie" <?php echo ($pelicula_editar['tipo'] === 'serie') ? 'selected' : ''; ?>>📺 Serie</option>
                    </select>
                </div>

                <!-- TÍTULO CON AUTOCOMPLETE INTELIGENTE -->
                <div class="form-group autocomplete-wrapper" style="grid-column: span 2;">
                    <label for="inputTitulo">
                        Título: <span style="font-weight: 400; color: var(--text-muted); font-size: 12px;">(escribe 2 letras para autorellenar con TMDb)</span>
                    </label>
                    <input type="text" id="inputTitulo" name="titulo" value="<?php echo htmlspecialchars($pelicula_editar['titulo']); ?>" placeholder="Ej: Spider-Man, Torrente, Fallout..." autocomplete="off" required>
                    <div id="acSpinner" class="ac-spinner" style="display: none;"></div>
                    <div id="acDropdown" class="autocomplete-dropdown" style="display: none;"></div>
                </div>

                <div class="form-group">
                    <label>Director / Creador:</label>
                    <input type="text" id="inputDirector" name="director" value="<?php echo htmlspecialchars($pelicula_editar['director']); ?>" placeholder="Nombre del director">
                </div>

                <div class="form-group">
                    <label>Año de estreno (España):</label>
                    <input type="number" id="inputAnio" name="anio" min="1880" max="<?php echo date('Y')+6; ?>" value="<?php echo $pelicula_editar['anio']; ?>" placeholder="Ej: 2026">
                </div>
            </div>
            
            <!-- CAMPOS DE PELÍCULA -->
            <div class="form-group" id="camposPelicula" style="margin-bottom: 16px;">
                <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="visto" <?php echo ($pelicula_editar['visto'] === 'Sí') ? 'checked' : ''; ?> style="width: auto;"> 
                    ¿Ya la has visto?
                </label>
            </div>

            <!-- CAMPOS DE SERIE -->
            <div class="form-grid" id="camposSerie" style="display: none; background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px dashed #cbd5e1;">
                <div class="form-group">
                    <label>Temporada actual:</label>
                    <input type="number" id="inputTemporada" name="temporada" min="1" value="<?php echo $pelicula_editar['temporada']; ?>">
                </div>
                <div class="form-group">
                    <label>Episodio actual:</label>
                    <input type="number" id="inputEpisodio" name="episodio" min="0" value="<?php echo $pelicula_editar['episodio']; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>Enlace General (Drive, Mega, etc.):</label>
                <input type="url" name="link" value="<?php echo htmlspecialchars($pelicula_editar['link'] ?? ''); ?>" placeholder="https://...">
            </div>
            
            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button type="submit" name="accion_guardar" class="btn btn-primary">
                    <?php echo $editando ? 'Actualizar Registro' : 'Guardar en Colección'; ?>
                </button>
                <?php if ($editando): ?>
                    <a href="index.php" class="btn btn-outline">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- CONTROLES Y FILTROS -->
    <div class="controls-wrapper">
        <input type="text" id="buscador" class="search-bar" placeholder="🔍 Buscar por título o creador..." onkeyup="aplicarFiltros()">
        <div class="filters">
            <button class="filter-chip active-all" id="btn-all" onclick="setFiltroTipo('all')">Todo</button>
            <button class="filter-chip" id="btn-pelicula" onclick="setFiltroTipo('pelicula')">🎬 Películas</button>
            <button class="filter-chip" id="btn-serie" onclick="setFiltroTipo('serie')">📺 Series</button>
            <button class="filter-chip" id="btn-visto" onclick="setFiltroTipo('visto')">👀 Vistas</button>
            <button class="filter-chip" id="btn-pendiente" onclick="setFiltroTipo('pendiente')">⏳ Pendientes</button>
        </div>
    </div>

    <!-- LISTADO DE PELÍCULAS -->
    <div class="movies-grid" id="listaPeliculas">
        <?php if (empty($peliculas)): ?>
            <div class="no-results">Aún no hay registros en la colección.</div>
        <?php else: ?>
            <?php foreach (array_reverse($peliculas, true) as $id => $peli): 
                $tipo = $peli['tipo'] ?? 'pelicula';
                $temp = $peli['temporada'] ?? 1;
                $ep = $peli['episodio'] ?? 0;
                $visto = ($tipo === 'pelicula') ? ($peli['visto'] === 'Sí' ? 'si' : 'no') : 'no';
            ?>
                <div class="card peli-card type-<?php echo $tipo; ?>" 
                     data-titulo="<?php echo htmlspecialchars(strtolower($peli['titulo'])); ?>" 
                     data-director="<?php echo htmlspecialchars(strtolower($peli['director'])); ?>" 
                     data-tipo="<?php echo $tipo; ?>"
                     data-visto="<?php echo $visto; ?>">
                    
                    <div>
                        <div class="card-top-layout">
                            <?php if (!empty($peli['poster'])): ?>
                                <img src="<?php echo htmlspecialchars($peli['poster']); ?>" alt="Póster de <?php echo htmlspecialchars($peli['titulo']); ?>" class="card-poster" loading="lazy">
                            <?php else: ?>
                                <div class="card-poster-placeholder">
                                    <?php echo $tipo === 'serie' ? '📺' : '🎬'; ?>
                                </div>
                            <?php endif; ?>

                            <div class="card-content-main">
                                <div class="card-header">
                                    <h3 class="card-title"><?php echo htmlspecialchars($peli['titulo']); ?></h3>
                                    <div class="badges-container">
                                        <span class="card-badge badge-type-<?php echo $tipo; ?>">
                                            <?php echo $tipo === 'serie' ? '📺 Serie' : '🎬 Peli'; ?>
                                        </span>
                                        
                                        <?php if ($tipo === 'pelicula'): ?>
                                            <?php if ($peli['visto'] === 'Sí'): ?>
                                                <span class="card-badge badge-vista">Vista</span>
                                            <?php else: ?>
                                                <span class="card-badge badge-pendiente">Pendiente</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="card-badge badge-progreso">T<?php echo $temp; ?> - Ep <?php echo $ep; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="card-meta">
                                    👤 <?php echo htmlspecialchars($peli['director']) ?: 'Desconocido'; ?><br>
                                    📅 <?php echo htmlspecialchars($peli['anio']) ?: 'N/A'; ?>
                                </div>

                                <?php if (!empty($peli['sinopsis'])): ?>
                                    <div class="card-sinopsis" title="<?php echo htmlspecialchars($peli['sinopsis']); ?>">
                                        <?php echo htmlspecialchars($peli['sinopsis']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- LÓGICA MULTI-ENLACE PARA SERIES -->
                        <?php if (!empty($peli['enlaces_temporada']) && is_array($peli['enlaces_temporada'])): ?>
                            <select onchange="if(this.value) window.open(this.value, '_blank'); this.selectedIndex=0;" class="episode-dropdown">
                                <option value="">▶️ Seleccionar Capítulo...</option>
                                <?php 
                                $caps = $peli['enlaces_temporada'];
                                ksort($caps);
                                foreach($caps as $num_ep => $enlace): ?>
                                    <option value="<?php echo htmlspecialchars($enlace); ?>">Capítulo <?php echo htmlspecialchars($num_ep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif (!empty($peli['link'])): ?>
                            <div class="card-link"><a href="<?php echo htmlspecialchars($peli['link']); ?>" target="_blank">🔗 Ver / Descargar</a></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($puede_editar): ?>
                    <div class="card-actions">
                        <?php if ($tipo === 'pelicula' && $peli['visto'] === 'No'): ?>
                            <a href="index.php?marcar_vista=<?php echo $id; ?>" class="btn btn-small btn-success">Marcar Vista</a>
                        <?php endif; ?>
                        
                        <?php if ($tipo === 'serie'): ?>
                            <a href="index.php?sumar_ep=<?php echo $id; ?>" class="btn btn-small btn-serie">+1 Ep</a>
                        <?php endif; ?>
                        
                        <a href="index.php?editar=<?php echo $id; ?>" class="btn btn-small btn-outline">Editar</a>
                        <a href="index.php?eliminar=<?php echo $id; ?>" class="btn btn-small btn-danger" onclick="return confirm('¿Seguro que quieres borrar este registro?');">Borrar</a>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div id="no-results-msg" class="no-results" style="display:none;">No se han encontrado resultados con ese criterio.</div>
    </div>
</div>

<script>
    function toggleCamposTipo() {
        let selector = document.getElementById('selectorTipo');
        let divPelicula = document.getElementById('camposPelicula');
        let divSerie = document.getElementById('camposSerie');
        
        if (!selector) return;

        if (selector.value === 'serie') {
            divPelicula.style.display = 'none';
            divSerie.style.display = 'grid';
        } else {
            divPelicula.style.display = 'block';
            divSerie.style.display = 'none';
        }
    }
    window.onload = toggleCamposTipo;

    let filtroActual = 'all';

    function setFiltroTipo(tipo) {
        filtroActual = tipo;
        document.querySelectorAll('.filter-chip').forEach(btn => {
            btn.className = 'filter-chip';
        });
        let activeBtn = document.getElementById('btn-' + tipo);
        if (activeBtn) activeBtn.className = 'filter-chip active-' + tipo;
        aplicarFiltros();
    }

    function aplicarFiltros() {
        let inputBuscador = document.getElementById("buscador").value.toLowerCase();
        let cards = document.getElementsByClassName("peli-card");
        let visibles = 0;

        for (let i = 0; i < cards.length; i++) {
            let titulo = cards[i].getAttribute("data-titulo") || "";
            let director = cards[i].getAttribute("data-director") || "";
            let tipoTarjeta = cards[i].getAttribute("data-tipo") || "";
            let estadoVisto = cards[i].getAttribute("data-visto") || "no";
            
            let coincideTexto = titulo.includes(inputBuscador) || director.includes(inputBuscador);
            let coincideTipo = true;

            if (filtroActual === 'all') {
                coincideTipo = true;
            } else if (filtroActual === 'pelicula' || filtroActual === 'serie') {
                coincideTipo = (tipoTarjeta === filtroActual);
            } else if (filtroActual === 'visto') {
                coincideTipo = (tipoTarjeta === 'pelicula' && estadoVisto === 'si');
            } else if (filtroActual === 'pendiente') {
                coincideTipo = (tipoTarjeta === 'pelicula' && estadoVisto === 'no');
            }
            
            if (coincideTexto && coincideTipo) {
                cards[i].style.display = "flex";
                visibles++;
            } else {
                cards[i].style.display = "none";
            }
        }
        document.getElementById("no-results-msg").style.display = (visibles === 0 && cards.length > 0) ? "block" : "none";
    }

    // --- AUTOCOMPLETADO INTELIGENTE CON TMDB ---
    const inputTitulo = document.getElementById('inputTitulo');
    const acDropdown = document.getElementById('acDropdown');
    const acSpinner = document.getElementById('acSpinner');
    let debounceTimer = null;

    if (inputTitulo && acDropdown) {
        inputTitulo.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const query = this.value.trim();

            if (query.length < 2) {
                acDropdown.style.display = 'none';
                acDropdown.innerHTML = '';
                return;
            }

            debounceTimer = setTimeout(() => {
                buscarEnTmdb(query);
            }, 300);
        });

        // Cerrar dropdown si se hace clic fuera
        document.addEventListener('click', function(e) {
            if (!inputTitulo.contains(e.target) && !acDropdown.contains(e.target)) {
                acDropdown.style.display = 'none';
            }
        });
    }

    function buscarEnTmdb(query) {
        const tipoActual = document.getElementById('selectorTipo').value;
        if (acSpinner) acSpinner.style.display = 'block';

        fetch(`api_tmdb.php?action=search&q=${encodeURIComponent(query)}&type=${tipoActual}`)
            .then(res => res.json())
            .then(data => {
                if (acSpinner) acSpinner.style.display = 'none';
                if (!Array.isArray(data) || data.length === 0) {
                    acDropdown.innerHTML = '<div style="padding:12px; font-size:13px; color:#64748b; text-align:center;">Sin resultados en TMDb</div>';
                    acDropdown.style.display = 'block';
                    return;
                }

                let html = '';
                data.forEach(item => {
                    const posterSrc = item.poster || 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="40" height="58"><rect width="100%" height="100%" fill="%23e2e8f0"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="16">🎬</text></svg>';
                    const isSerie = (item.media_type === 'serie');
                    const badgeClass = isSerie ? 'badge-type-serie' : 'badge-type-pelicula';
                    const badgeText = isSerie ? 'Serie' : 'Peli';

                    html += `
                        <div class="ac-item" onclick="seleccionarItemTmdb(${item.id}, '${item.media_type}')">
                            <img src="${posterSrc}" class="ac-poster" alt="">
                            <div class="ac-info">
                                <div class="ac-title">
                                    ${escapeHtml(item.title)}
                                    <span class="ac-badge ${badgeClass}">${badgeText}</span>
                                </div>
                                <div class="ac-meta">
                                    ${item.year ? '📅 ' + item.year : ''} ${item.original_title && item.original_title !== item.title ? '• ' + escapeHtml(item.original_title) : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });

                acDropdown.innerHTML = html;
                acDropdown.style.display = 'block';
            })
            .catch(err => {
                if (acSpinner) acSpinner.style.display = 'none';
                console.error("Error buscando en TMDb:", err);
            });
    }

    function seleccionarItemTmdb(id, type) {
        if (acSpinner) acSpinner.style.display = 'block';
        acDropdown.style.display = 'none';

        fetch(`api_tmdb.php?action=details&id=${id}&type=${type}`)
            .then(res => res.json())
            .then(details => {
                if (acSpinner) acSpinner.style.display = 'none';
                if (!details || details.error) {
                    alert("No se pudieron cargar los detalles.");
                    return;
                }

                // Autorellenar Título
                if (details.titulo) document.getElementById('inputTitulo').value = details.titulo;

                // Autorellenar Director / Creador
                if (details.director) document.getElementById('inputDirector').value = details.director;

                // Autorellenar Año de estreno en España
                if (details.anio) document.getElementById('inputAnio').value = details.anio;

                // Autorellenar Tipo
                if (details.tipo) {
                    document.getElementById('selectorTipo').value = details.tipo;
                    toggleCamposTipo();
                }

                // Autorellenar Temporadas si es serie
                if (details.tipo === 'serie') {
                    if (details.temporadas && document.getElementById('inputTemporada').value <= 1) {
                        document.getElementById('inputTemporada').value = 1;
                    }
                }

                // Póster y Sinopsis
                const inputPoster = document.getElementById('inputPoster');
                const inputSinopsis = document.getElementById('inputSinopsis');
                const previewContainer = document.getElementById('posterPreviewContainer');
                const previewImg = document.getElementById('posterPreviewImg');
                const previewSinopsis = document.getElementById('sinopsisPreviewText');

                if (details.poster) {
                    inputPoster.value = details.poster;
                    previewImg.src = details.poster;
                    previewContainer.style.display = 'flex';
                } else {
                    inputPoster.value = '';
                    previewContainer.style.display = 'none';
                }

                if (details.sinopsis) {
                    inputSinopsis.value = details.sinopsis;
                    if (previewSinopsis) previewSinopsis.textContent = details.sinopsis;
                } else {
                    inputSinopsis.value = '';
                    if (previewSinopsis) previewSinopsis.textContent = '';
                }
            })
            .catch(err => {
                if (acSpinner) acSpinner.style.display = 'none';
                console.error("Error al obtener detalles:", err);
            });
    }

    function quitarPoster() {
        document.getElementById('inputPoster').value = '';
        document.getElementById('inputSinopsis').value = '';
        document.getElementById('posterPreviewContainer').style.display = 'none';
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }
</script>
</body>
</html>
