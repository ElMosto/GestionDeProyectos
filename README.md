# Gestión de Proyectos — Estudio Musical

Aplicación web privada para gestionar proyectos, clientes, ventas, reservas, agenda,
reuniones y finanzas de un estudio musical.

- **Stack:** PHP 8.4 (sin framework) · MariaDB · Apache · HTML/CSS/JS con módulos ES.
- **Estado:** fases 1–2 funcionales y verificadas localmente.
  Acceso, perfiles, usuarios, clientes, proyectos, etapas, tareas y agenda propia disponibles.
  La validación del hosting sigue pendiente; no es una certificación de producción.
- **Especificación:** [`docs/requerimiento-v1.0.md`](docs/requerimiento-v1.0.md).

## Puesta en marcha (desarrollo local)

Requisitos: PHP 8.4 con `pdo_mysql`, `mbstring`, `bcmath`, `zip`, `gd`, `xml`; Composer; MariaDB.

```bash
composer install
cp config/config.example.php config/config.php   # y rellenar credenciales locales
php bin/migrate.php
php bin/seed.php
php bin/setup.php --name="Administrador" --email="admin@tu-estudio.example"
php -S 127.0.0.1:8080 -t public
```

Crear previamente la base MariaDB y su usuario de aplicación (no usar root en la configuración).
El instalador muestra la clave temporal una sola vez y exige cambiarla al entrar; se niega
a crear otro administrador cuando ya existe uno activo.

Abrir `http://127.0.0.1:8080/app.html`. Para Apache, el `DocumentRoot` debe apuntar a `public/`.
Config, dependencias, almacenamiento privado y pruebas permanecen fuera de esa raíz.

## Pruebas

```bash
php vendor/bin/phpunit
php vendor/bin/phpunit --order-by=random --random-order-seed=20261027
```

Requieren MariaDB real y una base dedicada `gestion_test` con permisos para el usuario local.
**La suite recrea el esquema y elimina los datos de esa base de pruebas.** Nunca se debe
usar como base de trabajo. No ejecutar la suite simultáneamente con las pruebas de navegador.
Comandos de Chromium y evidencia por criterio en [`docs/validacion.md`](docs/validacion.md).

## Documentación

| Archivo | Contenido |
|---|---|
| `docs/requerimiento-v1.0.md` | Especificación funcional y técnica (vinculante) |
| `docs/decisiones.md` | Decisiones tomadas y puntos abiertos |
| `docs/fases.md` | Plan de implementación y estado por fase |
| `docs/api-movil.md` | Reglas del contrato para la futura app Android |
| `docs/openapi.yaml` | Operaciones y esquemas del contrato `/api/v1` |
| `docs/validacion.md` | Pruebas ejecutadas y limitaciones documentadas |

## Flujo de trabajo

El proyecto se desarrolla con agentes especializados definidos en `.opencode/agents/`, apoyados
en las skills de `.opencode/skills/`. Ver `AGENTS.md`.
