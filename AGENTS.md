# Sistema de Gestión para Estudio Musical

Aplicación web privada para administrar proyectos, clientes, ventas, reservas, agenda,
reuniones y finanzas de un estudio musical. La especificación completa y vinculante está en
`docs/requerimiento-v1.0.md`; este archivo solo resume el contexto operativo.

> Nota: el `AGENTS.md` global de `C:\Users\vgmos` describe otro proyecto ("Diario de Estudio",
> tres archivos vanilla JS). **No aplica aquí.** Este archivo tiene prioridad para todo lo
> relacionado con este repositorio.

## Stack (no negociable)

- **Backend:** PHP 8.4 puro, POO, sin Laravel/Symfony ni framework alguno. PDO + `pdo_mysql`.
- **Base de datos:** MariaDB, InnoDB, `utf8mb4`, claves foráneas, restricciones CHECK.
- **Frontend:** HTML5 semántico, CSS3, JavaScript con módulos ES. Sin framework JS obligatorio.
- **Servidor:** Apache 2.4 + HTTPS + `mod_rewrite`. Solo `/public` queda expuesto.
- **Dependencias:** Composer (autoload PSR-4). PhpSpreadsheet para `.xlsx`.
- **Prohibido:** JWT, `localStorage` para credenciales, Node.js o procesos permanentes en
  producción, frameworks de backend, Docker en el hosting, ORMs.

## Estructura

```
public/          entrada HTTP, assets públicos (único directorio expuesto)
src/             Controllers, Services, Repositories, Policies, Validation, Exports, Auth, Core
config/          configuración privada (config.php NO se versiona)
database/        migrations/ y seeds/ versionados
templates/       plantillas de impresión
storage/         private/ y logs/ — nunca versionados, nunca públicos
tests/           PHPUnit (unitarias + integración MariaDB)
docs/            especificación, decisiones, fases, contrato móvil
.opencode/       agentes y skills de este proyecto
```

## Invariantes que ningún agente puede romper

1. **Dinero:** `DECIMAL(12,2)` en BD, aritmética exacta con BCMath (o centavos enteros si el
   hosting no tiene `bcmath`). Nunca `float`. Importes viajan como **string** en JSON. El
   redondeo ocurre **por partida**, al final.
2. **Autorización:** siempre en backend, por rol **y** por objeto. Conocer un ID ajeno no otorga
   permiso. Los campos sensibles (precios, pagos, gastos, ticket financiero) se filtran también
   en exportaciones, agenda y mensajes de error. Ocultar un botón no es seguridad.
3. **Concurrencia:** reservas y pagos solo dentro de transacción. `space_occupancies` es la
   única fuente de verdad de ocupación. Intervalos `[inicio, fin)`.
4. **Idempotencia:** toda escritura que crea dinero o bloquea espacio exige `Idempotency-Key`.
5. **Historial:** no se borran documentos financieros. Anular ≠ reembolsar ≠ cancelar.
   Operaciones de alto impacto: solo administrador.
6. **Salidas:** el `.xlsx` debe abrir sin reparación en Excel y LibreOffice. Nada de CSV/HTML
   renombrado. Textos escritos como literal para evitar inyección de fórmulas.
7. **Contrato:** `/api/v1` es un contrato público también para la futura app Android. No se
   rompe sin versionar. Ver `docs/api-movil.md`.

Las reglas detalladas viven en las skills de `.opencode/skills/`; cárgalas antes de tocar su
área. `spec-gestion` es la puerta de entrada y contiene el mapa de requisitos.

## Convenciones de código

- `declare(strict_types=1);` en todo archivo PHP. PSR-12. Autoload `App\` → `src/`.
- Identificadores, tablas, columnas y clases **en inglés**; textos de interfaz **en español**.
- Una clase por archivo, `final` por defecto, dependencias por constructor (sin service locator).
- SQL solo en `Repositories`, siempre preparado. Cero concatenación de entradas.
- Errores de API con `code` estable en inglés (`VALIDATION_FAILED`) y `message` en español.
- Fechas en BD en UTC; conversión a zona del usuario solo en presentación.
- Sin dependencias nuevas sin justificarlo en `docs/decisiones.md`.

## Flujo de trabajo

- Fases 1-6 en `docs/fases.md`. No adelantar trabajo de una fase posterior sin aprobación.
- El usuario ha aprobado avanzar automáticamente por las fases 1-6: al terminar y verificar
  una etapa, continuar con la siguiente sin esperar otra orden ni pedir permiso para pruebas.
  Documentar los bloqueos reales (como el inventario de hosting), sin darlos por resueltos.
- **Al terminar cada fase:** actualizar `docs/fases.md`, ejecutar las pruebas, commit y
  `git push` a `https://github.com/ElMosto/GestionDeProyectos` (rama `main`).
- Los puntos abiertos de la especificación (§21) se registran en `docs/decisiones.md`. Si algo
  no está decidido, se elige el valor propuesto, se documenta como "propuesto" y se sigue.
- Nunca inventar tasas de impuesto, descuentos ni políticas de cancelación.
