<?php
// Configuración de sesiones
$sessions_path = '/var/www/html/sessions';
if (is_dir($sessions_path)) {
    session_save_path($sessions_path);
}
session_start();

header('Content-Type: application/json; charset=utf-8');

// Comprobar autenticación
if (!isset($_SESSION['usuario_logueado'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$TMDB_API_KEY = '929738505228b9e8f2496db15a830421';
$action = $_GET['action'] ?? 'search';

function tmdbRequest($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PeliculasApp/2.0');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }
    return json_decode($response, true);
}

if ($action === 'search') {
    $query = trim($_GET['q'] ?? '');
    if (empty($query)) {
        echo json_encode([]);
        exit;
    }
    $type = $_GET['type'] ?? 'all';
    $qEncoded = urlencode($query);
    
    if ($type === 'pelicula' || $type === 'movie') {
        $url = "https://api.themoviedb.org/3/search/movie?api_key={$TMDB_API_KEY}&language=es-ES&region=ES&query={$qEncoded}&include_adult=false";
    } elseif ($type === 'serie' || $type === 'tv') {
        $url = "https://api.themoviedb.org/3/search/tv?api_key={$TMDB_API_KEY}&language=es-ES&query={$qEncoded}&include_adult=false";
    } else {
        $url = "https://api.themoviedb.org/3/search/multi?api_key={$TMDB_API_KEY}&language=es-ES&query={$qEncoded}&include_adult=false";
    }

    $data = tmdbRequest($url);
    $results = [];

    foreach (array_slice($data['results'] ?? [], 0, 8) as $item) {
        $mediaType = $item['media_type'] ?? ($type === 'serie' || $type === 'tv' ? 'tv' : 'movie');
        if (!in_array($mediaType, ['movie', 'tv'])) continue;

        $isMovie = ($mediaType === 'movie');
        $title = $isMovie ? ($item['title'] ?? $item['original_title'] ?? '') : ($item['name'] ?? $item['original_name'] ?? '');
        $date = $isMovie ? ($item['release_date'] ?? '') : ($item['first_air_date'] ?? '');
        $year = !empty($date) ? substr($date, 0, 4) : '';
        $poster = !empty($item['poster_path']) ? 'https://image.tmdb.org/t/p/w185' . $item['poster_path'] : null;

        $results[] = [
            'id' => $item['id'],
            'media_type' => $isMovie ? 'pelicula' : 'serie',
            'title' => $title,
            'original_title' => $isMovie ? ($item['original_title'] ?? '') : ($item['original_name'] ?? ''),
            'year' => $year,
            'poster' => $poster,
            'overview' => $item['overview'] ?? ''
        ];
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'details') {
    $id = intval($_GET['id'] ?? 0);
    $mediaType = $_GET['type'] ?? 'pelicula';
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }

    if ($mediaType === 'serie' || $mediaType === 'tv') {
        $url = "https://api.themoviedb.org/3/tv/{$id}?api_key={$TMDB_API_KEY}&language=es-ES&append_to_response=credits";
        $data = tmdbRequest($url);
        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => 'No encontrado']);
            exit;
        }

        $creators = [];
        foreach ($data['created_by'] ?? [] as $c) {
            if (!empty($c['name'])) $creators[] = $c['name'];
        }
        if (empty($creators)) {
            foreach ($data['credits']['crew'] ?? [] as $c) {
                if (in_array($c['job'] ?? '', ['Executive Producer', 'Director', 'Creator'])) {
                    if (!empty($c['name']) && !in_array($c['name'], $creators)) {
                        $creators[] = $c['name'];
                        if (count($creators) >= 3) break;
                    }
                }
            }
        }

        $year = !empty($data['first_air_date']) ? substr($data['first_air_date'], 0, 4) : '';
        $poster = !empty($data['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $data['poster_path'] : null;

        echo json_encode([
            'id' => $id,
            'tipo' => 'serie',
            'titulo' => $data['name'] ?? '',
            'original_title' => $data['original_name'] ?? '',
            'director' => implode(', ', $creators),
            'anio' => $year ? intval($year) : '',
            'temporadas' => $data['number_of_seasons'] ?? 1,
            'episodios' => $data['number_of_episodes'] ?? 0,
            'poster' => $poster,
            'sinopsis' => $data['overview'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        // Película: consultar credits y release_dates para estreno en España
        $url = "https://api.themoviedb.org/3/movie/{$id}?api_key={$TMDB_API_KEY}&language=es-ES&append_to_response=credits,release_dates";
        $data = tmdbRequest($url);
        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => 'No encontrado']);
            exit;
        }

        $esRelease = null;
        foreach ($data['release_dates']['results'] ?? [] as $country) {
            if (($country['iso_3166_1'] ?? '') === 'ES') {
                foreach ($country['release_dates'] ?? [] as $rd) {
                    if (!empty($rd['release_date'])) {
                        $esRelease = $rd['release_date'];
                        break 2;
                    }
                }
            }
        }

        $directors = [];
        foreach ($data['credits']['crew'] ?? [] as $c) {
            if (($c['job'] ?? '') === 'Director') {
                if (!empty($c['name'])) $directors[] = $c['name'];
            }
        }

        $yearRaw = $esRelease ?: ($data['release_date'] ?? '');
        $year = !empty($yearRaw) ? substr($yearRaw, 0, 4) : '';
        $poster = !empty($data['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $data['poster_path'] : null;

        echo json_encode([
            'id' => $id,
            'tipo' => 'pelicula',
            'titulo' => $data['title'] ?? '',
            'original_title' => $data['original_title'] ?? '',
            'director' => implode(', ', $directors),
            'anio' => $year ? intval($year) : '',
            'poster' => $poster,
            'sinopsis' => $data['overview'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Acción inválida']);
