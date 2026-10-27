---
name: Gestión de la especificación
description: Mapa de requisitos, invariantes y criterios de aceptación del sistema de gestión del estudio musical. Cárgala antes de trabajar en cualquier módulo para citar la sección correcta del requerimiento y no violar reglas no negociables.
---

# Gestión de la especificación

Fuente de verdad única: `docs/requerimiento-v1.0.md`. Este archivo no la reemplaza, la indexa.

## Cómo trabajar con la especificación

1. Identifica la sección que te aplica con la tabla de abajo y **léela completa** antes de codificar.
2. Cita la sección en el código y en las pruebas cuando la regla no sea obvia: `// §8: solo líder o
   admin cambia fechas`.
3. Si la especificación no cubre un caso: **no lo inventes**. Se registra en `docs/decisiones.md`
   con estado `Propuesta`, se aplica el valor más conservador y se sigue.
4. Si la especificación se contradice, gana la regla más restrictiva y se anota la contradicción.

## Índice temático

| Si trabajas en… | Lee |
|---|---|
| Alcance, impresión, supuestos | §1, §2 (2.1-2.5) |
| Stack, extensiones, hosting | §3, §18 |
| Carpetas, capas, exposición de archivos | §4 |
| Roles, permisos, operaciones de alto impacto | §5, §16 → skill `permisos-rbac` |
| Perfil, contraseña, avatar | §6 |
| Clientes, duplicados, desactivación | §7 |
| Proyectos, etapas, tareas, avance, cargos | §8 |
| Gantt y tickets | §9 → skill `salidas-impresas` |
| Productos, extras, ventas, tickets | §10 → skill `dinero-decimales` |
| Pagos, anulaciones, reembolsos, dashboard | §11 → skill `pagos-idempotencia` |
| Espacios, reservas, doble reserva | §12 → skill `reservas-concurrencia` |
| Agenda, reuniones, notificaciones | §13 |
| Tablas, columnas, índices | §14 → skill `esquema-mariadb` |
| Endpoints, contrato HTTP | §15 → skill `arquitectura-php` |
| Despliegue, respaldo | §18 → skill `despliegue-hosting` |
| Qué probar antes de cerrar | §19 → skill `qa-aceptacion` |
| Orden de trabajo | §20, `docs/fases.md` |
| Dudas pendientes | §21, `docs/decisiones.md` |

## Invariantes (violarlas es un fallo, no un estilo)

1. **Dinero exacto.** `DECIMAL(12,2)`, BCMath o centavos. Nunca `float`. String en JSON. Redondeo
   por partida.
2. **Autorización en backend**, por rol y por objeto. Ocultar UI no protege nada.
3. **Campos sensibles** (precios, pagos, gastos, ticket financiero) se filtran en el servidor,
   también en exportaciones, agenda y errores.
4. **Concurrencia:** reservas y pagos solo en transacción; `space_occupancies` es la única fuente
   de ocupación; intervalos `[inicio, fin)`.
5. **Idempotencia** en emisión de ventas, pagos, reembolsos y reservas.
6. **Nada se borra** si tiene historia financiera: anular ≠ reembolsar ≠ cancelar.
7. **Estados calculados**, no almacenados a mano: el estado de pago se deriva del historial.
8. **Folios por secuencia**, nunca `MAX(id)+1`.
9. **Salidas reales:** `.xlsx` de verdad, texto literal contra inyección de fórmulas.
10. **Contrato estable:** `/api/v1` también sirve a Android. Ver `docs/api-movil.md`.

## Criterios de aceptación (§19)

| Criterio | Cubre |
|---|---|
| A | Acceso, roles, aislamiento entre usuarios, perfil |
| B | Proyectos, tareas, cargos internos vs cobrables |
| C | Excel y tickets: abren, respetan permisos, no duplican |
| D | Ventas y pagos: fórmula, snapshots, PARCIAL/PAGADO, idempotencia |
| E | Reservas: solo BANDA, solapamiento, reprogramación, una sola venta |
| F | Reuniones: invitación individual, re-aceptación, privacidad |
| G | Finanzas: separación de conceptos, periodo correcto, trazabilidad |
| H | Operación: instalación, seguridad, rendimiento |

Antes de cerrar una fase, el criterio que le corresponda debe estar en verde en `docs/validacion.md`.

## Trampas conocidas de este proyecto

- Sumar cargos internos como si fueran cobros: **no** aumentan cuentas por cobrar.
- Contar una reserva dos veces (como venta y como ingreso de reserva).
- Usar la fecha de creación de la venta como fecha de cobro en el dashboard.
- Marcar PAGADO sin pago registrado, o permitir sobrescribir el total de un documento ya cobrado.
- Convertir banda a normal con reservas futuras: prohibido sin resolverlas.
- Mostrar al cliente los gastos internos del proyecto.
- Confundir "finalizar proyecto" con "liquidar pago".
