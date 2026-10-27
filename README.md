# Gestión de Proyectos — Estudio Musical

Aplicación web privada para gestionar proyectos, clientes, ventas, reservas, agenda,
reuniones y finanzas de un estudio musical.

- **Stack:** PHP 8.4 (sin framework) · MariaDB · Apache · HTML/CSS/JS con módulos ES.
- **Estado:** esqueleto inicial (fase 0). Sin funcionalidad de negocio todavía.
- **Especificación:** [`docs/requerimiento-v1.0.md`](docs/requerimiento-v1.0.md).

## Puesta en marcha (desarrollo local)

Requisitos: PHP 8.4 con `pdo_mysql`, `mbstring`, `bcmath`, `zip`, `gd`, `xml`; Composer; MariaDB.

```bash
composer install
cp config/config.example.php config/config.php   # y rellenar credenciales locales
mysql -u root -p < database/migrations/001_....sql
php -S localhost:8000 -t public
```

Para Apache, el `DocumentRoot` debe apuntar a `public/`.

## Documentación

| Archivo | Contenido |
|---|---|
| `docs/requerimiento-v1.0.md` | Especificación funcional y técnica (vinculante) |
| `docs/decisiones.md` | Decisiones tomadas y puntos abiertos |
| `docs/fases.md` | Plan de implementación y estado por fase |
| `docs/api-movil.md` | Reglas del contrato para la futura app Android |

## Flujo de trabajo

El proyecto se desarrolla con agentes especializados definidos en `.opencode/agents/`, apoyados
en las skills de `.opencode/skills/`. Ver `AGENTS.md`.
