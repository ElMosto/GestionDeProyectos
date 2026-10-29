# Manual de roles y uso

Guía de §5–13 y §20. Interfaz en `app.html`; las rutas `#/...` siguientes corresponden al
router actual. El servidor decide permisos por rol **y por objeto**: conocer un ID o recibir
un aviso no autoriza a ver información ajena. No hay portal ni credenciales de cliente.

## Acceso y roles

Entrar con la cuenta creada por ADMIN. En primer acceso o tras restablecimiento se exige cambiar
la clave temporal. No compartirla; no guardar claves en capturas ni notas de clientes. En
**Mi usuario** (`#/mi-usuario`) revisar nombre, zona horaria, avatar y contraseña. Avatar:
JPEG/PNG/WebP real, máximo 2 MiB; SVG y archivos ejecutables se rechazan. Cambiar contraseña
revoca tokens móviles, pero **no promete cerrar otras sesiones web**. Cerrar sesión al terminar.

| Rol | Uso principal y límites |
|---|---|
| ADMINISTRADOR | Cuentas, configuración, catálogo, espacios, administración financiera y operaciones de alto impacto. No puede leer reuniones/avisos privados ajenos por ser ADMIN. |
| LIDER_PROYECTO | Crea y dirige proyectos; gestiona etapas, miembros y tareas de los que lidera, con finanzas autorizadas; ventas y reservas de su responsabilidad. No administra cuentas ni ejecuta anulaciones/reembolsos. |
| COLABORADOR | Consulta proyectos de los que es miembro sin campos financieros; actualiza su tarea, no presupuesto/fechas. Puede gestionar sus ventas/cobros autorizados y reservas, no los de otros. |

En **Usuarios** (`#/usuarios`), ADMIN crea cuentas, asigna rol y puede desactivar/restablecer.
La clave temporal se muestra una sola vez: entregarla individualmente por canal privado. No
existe registro público. Cuenta desactivada pierde acceso; el último ADMIN activo está protegido.
Un cliente NORMAL o BANDA es un registro comercial, no un cuarto rol.

## Clientes y proyectos

1. **Clientes** (`#/clientes`): buscar antes de crear; revisar advertencias de duplicado.
   NORMAL y BANDA son tipos distintos. BANDA necesita representante/teléfono; solo BANDA puede
   reservar. No convertir a NORMAL con reservas futuras sin resolverlas. Baja conserva historia.
2. **Proyectos** (`#/proyectos`): ADMIN/LÍDER crea indicando tipo, condición cobrable, líder,
   descripción y fechas. EXTERNO requiere cliente e importe positivo; INTERNO puede valer cero.
3. En el detalle, **Integrantes** determina quién puede participar. En **Etapas y tareas** crear
   etapas y asignar responsables entre miembros. Una tarea independiente la asigna ADMIN y se
   consulta en **Mis tareas** (`#/mis-tareas`) y **Agenda** (`#/agenda`).
4. El colaborador actualiza el avance de su tarea; no cambia fechas ni presupuesto. Comentar
   una tarea no concede permiso para reasignarla o actualizar avance de otro responsable.
5. **Resumen** muestra avance operativo: sin tareas es 0%; tareas canceladas no cuentan en el
   denominador. Finalizar trabajo no liquida saldos; puede haber pago pendiente en FINALIZADO.
6. En **Finanzas** del proyecto, solo si está autorizado, consultar cargos y cobros. Un cargo
   aumenta valor comercial, **no registra pago**. INTERNO no cobrable es valor interno estimado,
   no cuenta por cobrar ni ingreso. Un gasto no aumenta el precio del proyecto.

Si aparece conflicto de versión (409), recargar y revisar lo que cambió antes de reenviar;
no repetir a ciegas. Cancelaciones/excepciones administrativas requieren confirmación y motivo.

## Catálogo, ventas y cobros

- ADMIN mantiene **Productos y extras** (`#/productos`). No hay inventario, IVA ni descuentos
  automáticos inventados. Precio/extras quedan como copia histórica al emitir.
- En **Ventas** (`#/ventas`), crear borrador, revisar partidas/cantidades/extras y emitir.
  Emitir **no cobra**. Una venta de total cero queda SIN_CARGO sin pago ficticio.
- Registrar el importe realmente recibido, método y fecha efectiva. Un abono deja PARCIAL;
  cubrir el saldo deja PAGADO. No marcar PAGADO sin registrar el saldo como pago.
- Ante error de red, consultar historial antes de repetir. El cliente HTTP usa claves de
  idempotencia para no duplicar una misma escritura; no abrir múltiples operaciones nuevas
  para el mismo dinero recibido. El servidor rechaza sobrepagos y reuso de clave con otro cuerpo.
- Imprimir/reimprimir ticket no crea ventas ni pagos. Elegir 80 mm/A4 donde se ofrezca y revisar
  vista previa. Todos son **«Comprobante interno; no es factura fiscal»**; no emiten CFDI.

### Anular ≠ reembolsar ≠ cancelar

**Anular pago** corrige un registro erróneo y conserva historial; no significa devolución de
efectivo. **Reembolsar** registra una devolución real vinculada al pago. **Cancelar documento**
termina la operación comercial y no borra su historia ni devuelve dinero automáticamente.
Estas acciones de alto impacto corresponden a ADMIN. Resolver el neto cobrado antes de cancelar
venta/reserva; no usar cancelación como botón de devolución. No borrar documentos financieros.

## Espacios, reservas y agenda

ADMIN configura **Espacios** (`#/espacios`): espacios físicos, tarifas, horarios y bloqueos
**reales proporcionados por el estudio**. No se han sembrado salas ni precios ficticios para
operar. En **Reservas** (`#/reservas`), seleccionar BANDA, espacio, horario y responsable.
Conflicto significa que debe elegirse otro hueco; no saltarse el bloqueo cambiando IDs.
Intervalos adyacentes son válidos: terminar a las 11:00 no bloquea comenzar a las 11:00.

Cada reserva genera **una sola venta**; cobrar por esa venta, nunca como segundo ingreso de
reserva. Reprogramar vuelve a comprobar disponibilidad; cambios de importe tras cobro pueden
requerir ADMIN. Cancelar corresponde a ADMIN, libera horario y conserva historial; puede
requerir resolver pagos antes. El calendario mínimo de espacios no concede detalle ajeno.

**Agenda** reúne elementos permitidos de tareas, reservas y reuniones. Revisar zona horaria en
perfil; almacenamiento es UTC. No compartir capturas con precios/notas privadas.

## Reuniones y notificaciones

En **Mis reuniones** (`#/reuniones`), cada invitado acepta o rechaza **individualmente**. Una
invitación pendiente no es reunión aceptada en su agenda; responder no acepta por los demás.
Cambiar horario exige responder otra vez. Solo el organizador gestiona la reunión; ni ADMIN
tiene acceso global a reuniones privadas ajenas. Un espacio físico también exige disponibilidad.

**Mis notificaciones** (`#/notificaciones`) muestra avisos propios y permite marcarlos leídos.
Son avisos genéricos: el enlace vuelve a comprobar permisos. La actualización periódica solo
ocurre con la bandeja abierta y visible; no se promete correo, push ni recordatorios por cron.

## Salidas y administración de fase 6 — en integración

El código actual incorpora **Finanzas** (`#/finanzas`), **Gastos** (`#/gastos`), **Auditoría**
(`#/auditoria`) y **Configuración** (`#/configuracion`), solo ADMIN, así como **Imprimir proyecto**
(Gantt XLSX), **Imprimir ticket** y **Ticket A4** en el detalle. **Esta guía no da por validada
la integración completa de esos flujos**; consultar [validacion.md](validacion.md) para su cierre.

- Finanzas separa importe comercial, cobros netos, pendientes, valor interno, gastos y resultado
  de caja. **Resultado de caja no es utilidad contable.** El pago de una venta antigua cuenta
  en la fecha de cobro; una reserva no se suma además como segundo ingreso.
- Si se informa `HISTORY_INCOMPLETE`, faltan evidencias para el corte: no interpretar como cero
  ni reconstruir historia con precios actuales. Consultar ADMIN; no inventar fechas de emisión.
- Gastos pendientes pueden editarse/pagarse/cancelarse; un gasto pagado no se reescribe ni se
  borra para corregir historia. Capturar la fecha real de pago; no aumenta cuentas por cobrar.
- Auditoría permite revisar metadatos de operaciones sin revelar notas privadas de reuniones.
  Configuración no contiene credenciales; cambiar zona con ocupaciones futuras puede fallar.
- El XLSX debe abrir sin reparación en Excel y LibreOffice de escritorio; **esa prueba sigue
  pendiente porque no están instalados**. No se afirma impresión física validada. Si se excede
  un límite de exportación, pedir un rango acotado; no descargar reiteradamente sin corregirlo.

## Incidencias

401: volver a entrar; 403: revisar autorización/cambio obligatorio, no intentar otro ID;
409: recargar o resolver conflicto de espacio/versión; 422: corregir campos indicados;
429: esperar el plazo de reintento. En fallo persistente comunicar operación, hora y request ID
si aparece, **sin** contraseñas, cookies, tokens ni datos bancarios. No registrar dinero de nuevo
hasta comprobar el historial. La aplicación es gestión interna, no contabilidad fiscal.
