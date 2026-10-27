---
name: Permisos y RBAC
description: Matriz de permisos por rol y por objeto, aplicación en políticas backend, filtrado de campos sensibles y pruebas de autorización. Cárgala al implementar o auditar cualquier control de acceso.
---

# Permisos y RBAC

Tres roles y nada más: `ADMINISTRADOR`, `LIDER_PROYECTO`, `COLABORADOR` (§2.1, D-01). "Usuario" es
la cuenta, no un rol. La matriz completa está en `references/matriz-permisos.md`; este archivo
explica cómo se aplica.

## Dos preguntas, siempre

```php
$policy->authorize($actor, 'sales.issue', $sale);   // lanza ForbiddenException
```

1. **Global:** ¿su rol puede ejecutar la acción? (`capability`)
2. **Objeto:** ¿puede sobre *este* registro? (membresía, liderazgo, propiedad, responsabilidad)

Si cualquiera de las dos falla → `403 FORBIDDEN`. Nunca se responde "existe pero no puedes".

## Modelo de relación

| Relación | Fuente |
|---|---|
| Líder de proyecto | `projects.leader_id` |
| Miembro | `project_members` |
| Responsable de tarea | `tasks.assignee_id` |
| Responsable de venta/reserva | `sales.responsible_id`, `reservations.responsible_id` |
| Registrador de pago | `payments.registered_by` |
| Creador de cliente | `clients.created_by` |
| Organizador / invitado | `meetings.organizer_id`, `meeting_invitations.invitee_id` |

Ser miembro da acceso a **ver**, no a editar alcance ni a ver finanzas.

## Campos sensibles

Se filtran en el serializador del servidor; no se envían y se ocultan en el cliente.

| Campo | Quién lo ve |
|---|---|
| `projects.base_amount`, cargos, total comercial | ADMIN; LIDER del proyecto |
| Pagos, saldos, historial de cobros | ADMIN; LIDER del proyecto; responsable en sus ventas/reservas |
| `expenses` (importe, estado) | ADMIN |
| Ticket financiero de proyecto | ADMIN; LIDER del proyecto |
| Ticket de venta | ADMIN; responsable de la venta |
| `clients.notes`, `clients.address` | ADMIN; creador del cliente |
| Directorio de clientes (nombre, tipo, contacto, teléfono, activo) | Todos los usuarios activos |
| Datos privados de agenda ajena | Nadie; solo ocupación con datos mínimos |
| `audit_logs` | ADMIN |

Regla: si el usuario no puede verlo por API, **tampoco** aparece en `.xlsx`, ticket, agenda,
notificación ni mensaje de error.

## Capacidades expuestas al cliente

`GET /auth/me` devuelve un mapa plano:

```json
"capabilities": {
  "projects.create": true,
  "projects.viewFinance": false,
  "sales.create": true,
  "finance.dashboard": false,
  "tasks.createStandalone": false
}
```

Es solo para la UI. El backend reautoriza siempre. Si `capabilities` y la policy discrepan, la
policy gana y hay un bug.

## Operaciones de alto impacto (§5)

Solo ADMIN, sin excepciones implícitas:

- anular pagos y procesar reembolsos;
- cancelar venta que ya tiene pagos;
- cambiar roles o desactivar cuentas;
- editar importes por debajo de lo cobrado;
- consultar auditoría global;
- ver el dashboard financiero.

Una excepción se implementa como permiso explícito y auditado, nunca como "porque es líder".

## Reglas de borde

- **Cuenta inactiva:** se comprueba en cada petición (sesión y token), no solo al login. Sesión
  abierta de cuenta desactivada → `401` inmediato.
- **Último administrador:** no se puede desactivar, ni cambiar su rol, ni quitar su membresía de
  administración.
- **Auto-perfil:** el formulario de perfil nunca acepta `role_id`, `active` ni permisos.
- **Cliente ≠ usuario:** un `client` no tiene credenciales y no puede autenticarse, ni siquiera con
  el tipo BANDA.
- **Reserva:** exige `client.type = BANDA`; un NORMAL → `422 VALIDATION_FAILED`.
- **Tarea de proyecto:** el asignado debe ser COLABORADOR activo y miembro del proyecto. Un líder
  solo recibe tareas independientes.
- **IDs ajenos:** probar siempre con un ID que existe pero no pertenece al actor. Es el fallo más
  común.

## Pruebas mínimas de autorización

1. COLABORADOR pide `/projects/{id}` de un proyecto del que no es miembro → 403/404.
2. COLABORADOR miembro pide `/projects/{id}/payments` → 403.
3. LIDER pide pagos de un proyecto que no lidera → 403.
4. COLABORADOR intenta `PATCH` de su propio `role_id` en `/me` → 422.
5. ADMIN desactiva su propia cuenta siendo el último admin → 409.
6. Sesión válida de cuenta desactivada → 401.
7. Token móvil revocado → 401.
8. Cliente BANDA no puede iniciar sesión.
9. Exportación de proyecto sin permiso financiero → el `.xlsx` no contiene importes.
10. Agenda de otro usuario → no expone títulos ni notas privadas.
