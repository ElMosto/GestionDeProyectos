# Matriz de permisos

Leyenda: **A** = ADMINISTRADOR · **L** = LIDER_PROYECTO · **C** = COLABORADOR · — = sin acceso.
"Propio" significa por relación de objeto (liderazgo, membresía, responsabilidad o creador), no por
rol. Toda celda con "Propio" exige comprobación de objeto además del rol.

## Cuentas y administración

| Acción | A | L | C | Notas |
|---|---|---|---|---|
| Crear usuario / asignar rol | ✔ | — | — | Sin registro público |
| Editar usuario (datos, estado) | ✔ | — | — | Último admin protegido |
| Desactivar cuenta | ✔ | — | — | Invalida sesiones abiertas |
| Restablecer contraseña (clave temporal) | ✔ | — | — | `must_change_password = 1` |
| Directorio mínimo de usuarios activos | ✔ | ✔ | ✔ | Solo id, nombre, cargo, para asignar/invitar |
| Ver auditoría global | ✔ | — | — | |
| Configuración (`settings`) | ✔ | — | — | |
| Editar perfil propio | ✔ | ✔ | ✔ | Nunca rol, estado ni permisos |

## Clientes

| Acción | A | L | C | Notas |
|---|---|---|---|---|
| Crear cliente | ✔ | ✔ | ✔ | Alerta de duplicado, no bloqueo |
| Ver directorio mínimo | ✔ | ✔ | ✔ | Nombre, tipo, contacto, teléfono, activo |
| Ver notas y dirección | ✔ | Creador | Creador | Campo sensible |
| Editar datos generales | ✔ | — | — | |
| Editar datos de contacto de los propios | ✔ | Creador | Creador | Con auditoría |
| Desactivar | ✔ | — | — | Preserva historia |
| Convertir BANDA → NORMAL | ✔ | — | — | Prohibido con reservas futuras activas |

## Proyectos, etapas y tareas

| Acción | A | L | C | Notas |
|---|---|---|---|---|
| Crear proyecto | ✔ | ✔ | — | L crea bajo su liderazgo |
| Ver proyecto | ✔ | Propio | Miembro | |
| Ver importe base, cargos y finanzas | ✔ | Propio | — | Salvo autorización expresa |
| Editar alcance, fechas, prioridad | ✔ | Propio | — | |
| Cambiar líder | ✔ | — | — | |
| Gestionar integrantes | ✔ | Propio | — | Solo usuarios activos |
| Transiciones de estado del proyecto | ✔ | Propio | — | Finalizar exige tareas obligatorias |
| Crear/editar etapas y reordenar | ✔ | Propio | — | |
| Crear tarea de proyecto | ✔ | Propio | — | Asignado = colaborador miembro |
| Cambiar asignación o fechas de tarea | ✔ | Propio | — | |
| Actualizar avance de tarea | ✔ | Propio | Asignada | Completar fija 100% |
| Comentar tarea | ✔ | ✔ | ✔ | Si puede ver la tarea |
| Crear tarea independiente | ✔ | — | — | Cualquier rol como responsable |
| Editar tarea independiente propia | ✔ | Responsable | Responsable | Avance y comentarios |
| Registrar cargos adicionales | ✔ | Propio (interno) | — | No crean pago |
| Editar cargo ya cobrado | ✔ | — | — | Requiere ajuste/reembolso |
| Registrar gastos | ✔ | — | — | Salida real, no precio |
| Ver gastos | ✔ | — | — | |
| Exportar Gantt | ✔ | Propio | Miembro sin finanzas | El archivo respeta permisos |
| Ticket financiero de proyecto | ✔ | Propio | — | |

## Ventas, productos y pagos

| Acción | A | L | C | Notas |
|---|---|---|---|---|
| Gestionar catálogo de productos/extras | ✔ | — | — | |
| Ver catálogo | ✔ | ✔ | ✔ | Necesario para vender |
| Crear venta | ✔ | ✔ | ✔ | Responsable = actor |
| Ver venta | ✔ | Propias | Propias | |
| Editar venta en BORRADOR | ✔ | Propias | Propias | Emitida exige ajuste admin |
| Emitir venta | ✔ | Propias | Propias | `Idempotency-Key` |
| Cancelar venta sin pagos | ✔ | Propias | — | Con motivo |
| Cancelar venta con pagos | ✔ | — | — | Resolución de reembolso |
| Registrar pago | ✔ | Proyectos propios + ventas/reservas propias | Ventas/reservas propias | |
| Anular pago | ✔ | — | — | Motivo + auditoría |
| Procesar reembolso | ✔ | — | — | Límite: no reembolsado |
| Marcar pagada (atajo) | ✔ | Propias | Propias | Equivale a registrar pago por el saldo |
| Ver ticket de venta | ✔ | Propias | Propias | |

## Espacios, reservas y agenda

| Acción | A | L | C | Notas |
|---|---|---|---|---|
| Configurar espacios, tarifas, horarios, bloqueos | ✔ | — | — | |
| Ver ocupación de espacios | ✔ | ✔ | ✔ | Datos mínimos, sin finanzas |
| Crear reserva | ✔ | ✔ | ✔ | Cliente BANDA obligatorio |
| Ver reserva | ✔ | Propias | Propias | |
| Reprogramar | ✔ | Propias | Propias | Revalida con bloqueo |
| Confirmar / iniciar / finalizar | ✔ | Propias | Propias | Según transición |
| Cancelar reserva | ✔ | Propias | — | Coordina cancelación de venta |
| Reserva retrospectiva | ✔ | — | — | Con motivo obligatorio |

## Reuniones y notificaciones

| Acción | A | L | C | Notas |
|---|---|---|---|---|
| Crear reunión e invitar | ✔ | ✔ | ✔ | Invitados = usuarios activos |
| Aceptar/rechazar invitación | ✔ | ✔ | ✔ | Individual, nunca por otros |
| Cancelar reunión propia | ✔ | ✔ | ✔ | Organizador |
| Ver reunión aceptada | ✔ | ✔ | ✔ | Solo si aceptó |
| Ver notas privadas ajenas | — | — | — | Nunca |
| Ver notificaciones propias | ✔ | ✔ | ✔ | |
| Ver dashboard financiero | ✔ | — | — | |

## Reglas transversales

1. Toda celda vacía se resuelve con `403`, no con "lista vacía" que simula éxito.
2. Un `404` solo se usa cuando el objeto no existe **y** el actor podría verlo; si no, `403`.
3. La autorización se evalúa **después** de autenticar y **antes** de ejecutar el servicio.
4. Los listados filtran por relación en la consulta SQL, no en PHP después de cargar todo.
5. Exportaciones, tickets, agenda y notificaciones usan **el mismo** filtro de campos que la API.
