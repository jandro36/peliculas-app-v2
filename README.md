# 🎬 Gestor de Colección Audiovisual (Películas y Series)

Aplicación web autoalojable en PHP y Docker para catalogar, gestionar y hacer seguimiento de tu colección personal de películas y series, con integración en tiempo real con la API de **The Movie Database (TMDb)** y despliegue de alta disponibilidad balanceado con **Nginx**.

---

## ✨ Características Principales

* **🔍 Autocompletado inteligente con TMDb**:
  * Búsqueda en tiempo real mientras escribes (con *debounce* y spinner de carga).
  * Extracción automática del **año de estreno oficial en España** (`release_dates` con región `ES`).
  * Autorelleno de **Director(es)** o creadores/showrunners.
  * Descarga y previsualización de **carátulas oficiales en alta definición** y sinopsis.
  * Asignación automática por servidor en caso de guardar directamente sin pulsar el desplegable.
* **📺 Gestión avanzada de Series y Películas**:
  * Contador rápido de episodios (`+1 Ep`) y control de temporadas.
  * Desplegables multicapítulo para series con enlaces individuales.
  * Estado de visionado (*Vista* / *Pendiente*).
* **🎨 Interfaz moderna y responsive**:
  * Diseño tipo catálogo de streaming con carátulas, badges de colores y filtros rápidos (*Todo*, *Películas*, *Series*, *Vistas*, *Pendientes*).
  * Buscador instantáneo por título o creador.
* **🔐 Seguridad y Hardening**:
  * Autenticación con *bcrypt* (`password_hash`).
  * Control de acceso basado en roles/usuarios (editores vs visitas).
  * Bloqueo en Nginx de ficheros sensibles (`.json`, `.txt`, `.log`, `.sqlite`, `/sessions/`) para evitar filtración de contraseñas y enlaces.
  * Escritura atómica transaccional (`LOCK_EX`) para prevenir condiciones de carrera entre réplicas.
* **🐳 Arquitectura Docker de Alta Disponibilidad**:
  * Balanceador de carga inverso Nginx con SSL/TLS (`least_conn`).
  * 3 réplicas Apache/PHP 8.2 con healthchecks automáticos.

---

## 🚀 Despliegue con Docker Compose

### 1. Clonar el repositorio
```bash
git clone https://github.com/<tu-usuario>/<tu-repositorio>.git
cd <tu-repositorio>
```

### 2. Estructura de directorios y datos
Crea la carpeta de datos en tu servidor o usa el volumen configurado en `docker-compose.yml`:
```bash
mkdir -p /data/webapp/sessions
cp usuarios.example.json /data/webapp/usuarios.json
cp peliculas.example.json /data/webapp/peliculas_jandro.txt
```

### 3. Configurar Certificados SSL
Coloca tus certificados en `./certs/global.crt` y `./certs/global.key`.

### 4. Iniciar los contenedores
```bash
docker compose up -d --build
```

La aplicación quedará disponible en el puerto `3000` (HTTPS) o el configurado en tu `docker-compose.yml`.

---

## 🛠️ Tecnologías Utilizadas

* **Backend / Frontend**: PHP 8.2, JavaScript Vanilla (sin dependencias pesadas), CSS3 Moderno.
* **API de Metadatos**: [The Movie Database (TMDb)](https://www.themoviedb.org/).
* **Contenedores**: Docker, Docker Compose, Nginx (Reverse Proxy & Load Balancer), Apache.
