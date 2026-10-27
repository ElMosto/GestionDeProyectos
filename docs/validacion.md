# Validación y criterios de aceptación

> Evidencia de que el sistema cumple el §19. Lo mantiene `qa-aceptacion`.
> Sin este documento al día, la fase no se cierra.

Estado actual: **fase 0 (esqueleto)** — no hay funcionalidad de negocio que validar.

| Criterio | Fase | Estado | Pruebas |
|---|---|---|---|
| A — Acceso y permisos | 1 | Pendiente | |
| B — Proyectos | 2 | Pendiente | |
| C — Impresión | 3 y 6 | Pendiente | |
| D — Ventas y pagos | 3 | Pendiente | |
| E — Reservas | 4 | Pendiente | |
| F — Reuniones y agenda | 5 | Pendiente | |
| G — Finanzas | 6 | Pendiente | |
| H — Operación y seguridad | 6 | Pendiente | |

## Comandos

```bash
composer test                 # suite completa
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
composer check-platform-reqs
```

## Limitaciones conocidas

- **PHP y Composer no están instalados en el equipo de desarrollo.** Hay que instalarlos (PHP 8.4
  con las extensiones del `composer.json`) antes de ejecutar la primera prueba. Mientras tanto, el
  esqueleto no se ha podido ejecutar localmente.
- Las pruebas de concurrencia requieren una MariaDB real; no se simulan.
