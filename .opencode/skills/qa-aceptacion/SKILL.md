---
name: Pruebas y criterios de aceptación
description: Mapa de los criterios A-H del §19 a casos de prueba concretos, estrategia de pruebas unitarias, de integración y de navegador, y qué exigir antes de cerrar una fase.
---

# Pruebas y criterios de aceptación

## Regla de cierre

Una fase no se cierra si algún criterio que le corresponde está en rojo o "pendiente sin motivo".
`docs/validacion.md` es la evidencia: criterio → pruebas → estado → comando para reproducir.

## Pirámide

| Nivel | Qué prueba | Cómo |
|---|---|---|
| Unitaria | Dinero, avance, transiciones, reglas puras | PHPUnit sin BD |
| Integración | Transacciones, bloqueos, CHECK, idempotencia | MariaDB real |
| API | Autorización, CSRF, validación, códigos | HTTP contra la app |
| Navegador | Flujos críticos de usuario | Manual guiado o Playwright si se aprueba |

No se simula la base para probar concurrencia: `FOR UPDATE` no se puede emular con mocks.

## Criterio A — Acceso y permisos

- [ ] ADMIN crea los tres roles; no existe registro público.
- [ ] Un cliente no puede autenticarse (ni BANDA).
- [ ] COLABORADOR no accede a `/projects/{id}/payments` de un proyecto ajeno cambiando el ID.
- [ ] Cuenta desactivada → `401` inmediato, incluso con sesión abierta.
- [ ] `PATCH /me` con `role_id` o `active` → `422`.
- [ ] Último administrador no puede desactivarse.
- [ ] Token móvil revocado → `401`.

## Criterio B — Proyectos

- [ ] Externo sin cliente o sin precio → `422`.
- [ ] Interno con importe `0.00` → válido.
- [ ] LIDER crea etapas/tareas y asigna colaboradores **miembros**; no miembros → `422`.
- [ ] Colaborador actualiza avance de su tarea y **no** puede cambiar fechas ni presupuesto.
- [ ] ADMIN crea tarea independiente para cualquier rol y aparece en su agenda.
- [ ] Interno `1000` + cargo `500` → valor `1500.00`, sin pago ni cuenta por cobrar.
- [ ] Gasto `200` no cambia el valor comercial del proyecto.
- [ ] Interno no cobrable no entra en cuentas por cobrar ni en cobros reales.
- [ ] Avance = completadas / no canceladas; sin tareas → 0% y "sin tareas".

## Criterio C — Impresión

- [ ] `.xlsx` abre sin reparación en Excel y LibreOffice.
- [ ] Las barras coinciden con las fechas; tareas sin fecha dicen "sin programar".
- [ ] Exportación sin permiso financiero no contiene importes.
- [ ] Ticket de proyecto y de venta coinciden con la API.
- [ ] Texto largo no se recorta (80 mm ni A4).
- [ ] Reimprimir no crea pagos, ventas ni movimientos.
- [ ] Aparece "Comprobante interno; no es factura fiscal".

## Criterio D — Ventas y pagos

- [ ] `2 × (50 + 10) = 120.00`.
- [ ] Cambiar catálogo después de emitir no altera el ticket.
- [ ] `1000` con abono `300` → PARCIAL, saldo `700.00`; luego `700` → PAGADO.
- [ ] Misma `Idempotency-Key` + mismo cuerpo → un solo pago.
- [ ] Misma clave + cuerpo distinto → `409`.
- [ ] Dos cobros concurrentes no sobrepagan.
- [ ] Total `0.00` → `SIN_CARGO`, sin fila en `payments`.
- [ ] Anulación conserva historial y baja el neto.

## Criterio E — Reservas

- [ ] Cliente NORMAL → `422`; BANDA → `201`.
- [ ] Dos reservas simultáneas del mismo hueco → `201` + `409`.
- [ ] Espacios distintos u horarios adyacentes → válidos.
- [ ] Reprogramar revalida conflictos y no deja cambios parciales.
- [ ] Cancelar libera el horario y conserva historial financiero.
- [ ] Una reserva = una venta = un ingreso en el dashboard.

## Criterio F — Reuniones y agenda

- [ ] Invitado PENDIENTE no ve la reunión como aceptada.
- [ ] Al aceptar, aparece solo para él.
- [ ] Rechazar no afecta a otros invitados.
- [ ] Cambio de horario restablece invitaciones a PENDIENTE.
- [ ] No se exponen notas privadas de agendas ajenas.

## Criterio G — Finanzas

- [ ] Dashboard separa importe comercial, cobros, pendientes, valor interno y gastos.
- [ ] Totales cuadran con el detalle de cada cifra.
- [ ] Pago de venta antigua cuenta en el periodo de **cobro**.
- [ ] Anulaciones y reembolsos trazables y sin doble conteo.
- [ ] `resultado de caja` no se presenta como utilidad.

## Criterio H — Operación y seguridad

- [ ] Instalación limpia en hosting equivalente; migraciones desde cero.
- [ ] Restauración de respaldo verificada.
- [ ] CSRF rechaza petición sin token.
- [ ] SQL injection: `' OR 1=1 --` no altera consultas.
- [ ] XSS: `<script>alert(1)</script>` se muestra como texto.
- [ ] Archivos: SVG, `.php` renombrado y > 2 MB rechazados en avatar.
- [ ] Autorización por objeto con IDs ajenos.
- [ ] Listas de 25 registros < 2 s con el dataset acordado (medir, no suponer).

## Cómo escribir una prueba

```php
public function test_D_venta_con_abono_queda_parcial_y_saldo_correcto(): void
{
    // §19.D — 1000 con abono de 300
    $sale = $this->createSale(total: '1000.00');
    $this->pay($sale, '300.00');

    $this->assertSame('PARCIAL', $sale->paymentStatus());
    $this->assertSame('700.00', $sale->balance());
}
```

- Nombre con criterio (`test_D_...`) y comentario con la sección.
- Un `assert` conceptual por prueba; nada de pruebas que verifican cinco cosas y fallan por la
  primera.
- Datos creados por la propia prueba; nunca depender de seeds de demostración.
- Sin `sleep()`; la concurrencia se prueba con transacciones explícitas.

## Deuda técnica aceptada

Se documenta en `docs/validacion.md` con motivo y fase de resolución. No se oculta una prueba en
rojo marcándola como `skip` sin explicación.
