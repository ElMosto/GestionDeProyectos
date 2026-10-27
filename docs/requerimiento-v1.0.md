# ESPECIFICACIÓN FUNCIONAL Y TÉCNICA
## SISTEMA DE GESTIÓN PARA ESTUDIO MUSICAL

Versión 1.0 — 27 de octubre de 2026
Estado: especificación para desarrollo; no implica software implementado.

---

## 1. OBJETIVO Y ALCANCE

Desarrollar una aplicación web privada para organizar proyectos, etapas, tareas, clientes, productos, ventas, reservas de espacios, reuniones, agenda personal y finanzas de un estudio musical.

El sistema se desplegará en un hosting con Apache y MariaDB administrado mediante un panel de hosting. Backend con APIs en PHP puro, sin Laravel, Symfony ni otro framework de backend. Frontend con HTML, JavaScript y CSS. Los clientes son registros comerciales y nunca cuentas de acceso.

Alcance inicial: una empresa, una moneda MXN, múltiples usuarios y posibilidad de configurar varios espacios. Interfaz en español, adaptable a escritorio, tablet y móvil. No necesita Node.js, procesos permanentes, WebSockets ni servicios externos para funcionar en producción.

## 2. DECISIONES Y SUPUESTOS EXPLÍCITOS

### 2.1 Roles
Se interpreta "roles de usuario, administrador, líder de proyecto, colaborador" como tres roles: ADMINISTRADOR, LIDER_PROYECTO y COLABORADOR. "Usuario" es el concepto general de cuenta. Si se necesita un cuarto rol denominado USUARIO, debe definirse su permiso antes de implementarlo; no crear un rol ambiguo.

### 2.2 Proyectos internos y cargos
Un proyecto externo exige cliente y precio. Un proyecto interno puede tener importe base cero y no requiere cliente. Para respetar el requisito de sumar extras a lo ganado, los extras del líder en proyectos internos se denominan CARGOS ADICIONALES y aumentan su importe comercial. Los GASTOS son salidas reales y se registran por separado; reducen el resultado.
Un cargo interno sin pagador no constituye dinero cobrado ni necesariamente una cuenta por cobrar: debe mostrarse como valor interno estimado. Para cobrarlo, se debe designar un cliente/pagador y habilitar su condición de cobrable. La clasificación interno/externo permanece independiente de esta condición. Esta regla permite valorar trabajo interno sin inflar ingresos reales. Es una propuesta que debe confirmarse con el responsable del estudio.

### 2.3 Pagos
La primera versión registra pagos manualmente: efectivo, transferencia, tarjeta u otro medio. No procesa tarjetas ni verifica transferencias bancarias. El estado de pago se calcula a partir del historial; no se permite marcar PAGADO sin registrar el importe correspondiente.

### 2.4 Reservas
Toda reserva corresponde a un cliente BANDA y genera exactamente una venta de tipo RESERVA. Una venta ordinaria no aparta espacio por sí sola. Se admiten reservas confirmadas sin pagar; el pago y el uso del espacio tienen estados independientes.

### 2.5 Impresión
"Imprimir proyecto" descarga un archivo Excel real .xlsx con Gantt editable. "Imprimir ticket" abre una vista HTML de impresión para impresora térmica de 80 mm y opción A4. El navegador permite guardar el ticket como PDF. No incluye facturación fiscal/CFDI ni impresión silenciosa directa.

## 3. TECNOLOGÍAS Y DEPENDENCIAS

**Backend:**
- PHP 8.4 como base propuesta, sujeto a disponibilidad del hosting; verificar soporte y compatibilidad al iniciar el desarrollo.
- Programación orientada a objetos y separación por capas, sin framework.
- PDO con driver pdo_mysql para MariaDB, consultas preparadas y transacciones.
- Sesiones PHP almacenadas en servidor; cookies seguras. No usar JWT ni guardar credenciales en localStorage.
- Composer para autoload PSR-4 y dependencias. Se puede ejecutar localmente y subir vendor al hosting si no hay SSH.
- PhpSpreadsheet para generar .xlsx. Fijar una versión compatible mediante composer.lock y validar sus requisitos reales de extensiones.
- BCMath para aritmética decimal exacta en PHP; si el hosting no lo ofrece, utilizar una implementación única de importes en centavos enteros, con reglas explícitas para cantidades fraccionarias.

**Frontend:**
- HTML5 semántico, CSS3 y JavaScript mediante módulos ES, sin framework obligatorio.
- Fetch API para consumir /api/v1; misma procedencia que el backend.
- FullCalendar Standard como propuesta para agenda, empaquetado localmente y con licencia de la versión elegida revisada. Alternativamente, calendario propio si se desea eliminar esa dependencia.
- Chart.js para dashboard financiero; alojar recursos localmente y fijar versiones.
- CSS @media print y @page para tickets. No depender de una biblioteca PDF para el alcance inicial.

**Base de datos y servidor:**
- MariaDB en una rama mantenida y disponible en el hosting; fijar versión exacta tras inventario del servidor.
- Motor InnoDB para todas las tablas, codificación utf8mb4, claves foráneas y restricciones.
- Apache 2.4 con HTTPS y mod_rewrite; alternativa de router mediante index.php si el proveedor no permite reescritura.
- Extensiones PHP: PDO, pdo_mysql, mbstring, fileinfo, session, openssl, bcmath y las exigidas por la versión fijada de PhpSpreadsheet, incluidas las relacionadas con ZIP/XML. Verificar con composer check-platform-reqs en un entorno equivalente al hosting.
- Git para control de código. PHPUnit para reglas del backend y pruebas de integración con MariaDB. Pruebas de navegador para flujos críticos.

**Compatibilidad de hosting:**
No confundir un constructor visual de páginas con un hosting ejecutable. El proveedor debe permitir PHP propio, MariaDB, archivos privados, sesiones, extensiones y carga de vendor. Un panel de páginas que solo acepte HTML estático no basta.

## 4. ARQUITECTURA Y ESTRUCTURA

Monolito modular con API REST JSON y frontend servido desde Apache.
Flujo: navegador -> router -> autenticación/CSRF/permisos -> controlador -> servicio de negocio -> repositorio PDO -> MariaDB.

Estructura sugerida:
- `/public/index.php`: entrada HTTP y router.
- `/public/assets/`: CSS, JS, iconos e imágenes públicas.
- `/src/Controllers/`: traducción de peticiones y respuestas.
- `/src/Services/`: reglas, cálculos, transacciones y estados.
- `/src/Repositories/`: acceso a datos.
- `/src/Policies/`: autorización por rol y por recurso.
- `/src/Validation/`: validación de campos.
- `/src/Exports/`: Excel Gantt y tickets.
- `/templates/`: plantillas de impresión.
- `/config/`: configuración privada.
- `/database/migrations/`: esquema versionado.
- `/database/seeds/`: datos iniciales y demostración.
- `/storage/private/`: archivos y respaldos privados.
- `/storage/logs/`: registros sin credenciales.
- `/tests/`: pruebas.
- `/vendor/`: dependencias.
- `/docs/`: instalación, API y manual.

Solo /public debe quedar expuesto por Apache. Si el hosting fuerza public_html, colocar código/configuración/vendor fuera de él y dejar la entrada y assets dentro. No confiar únicamente en nombres de carpetas para proteger secretos.

## 5. CUENTAS, ROLES Y PERMISOS

**Reglas generales:**
- No hay registro público. El administrador crea cuentas y asigna un rol.
- Cada cuenta tiene un único rol inicial. Su relación con proyectos se controla además mediante membresías.
- Toda autorización debe ejecutarse en backend; ocultar botones no protege APIs.
- Cuenta inactiva pierde acceso, incluidas sesiones ya abiertas.
- No desactivar ni quitar privilegios al último administrador activo.
- No eliminar físicamente usuarios referenciados por operaciones históricas.

**ADMINISTRADOR:**
Gestiona cuentas, roles, configuración, espacios, productos y extras. Gestiona todos los proyectos, etapas y tareas. Asigna líder y participantes. Crea tareas independientes para cualquier usuario activo. Gestiona clientes, ventas, reservas, pagos, anulaciones, gastos, finanzas y auditoría.

**LIDER_PROYECTO:**
Crea proyectos bajo su liderazgo, gestiona sus etapas/tareas y asigna colaboradores activos que pertenezcan al proyecto. Gestiona participantes de sus proyectos, sin conceder roles globales. Registra cargos adicionales de proyectos internos propios. Consulta detalle comercial y pagos de sus proyectos. Registra pagos en sus proyectos y en ventas/reservas propias. Crea clientes, ventas y reservas. No accede al dashboard financiero global ni modifica pagos de otros responsables.

**COLABORADOR:**
Consulta los proyectos de los que es miembro y gestiona avance/comentarios de sus tareas asignadas. No ve precios, pagos o gastos de proyectos salvo autorización administrativa expresa; el ticket comercial y la exportación deben respetar esto. Registra y consulta clientes, crea ventas y reservas, consulta las propias y registra sus pagos. No anula cobros ni cambia catálogos/precios base. No crea tareas independientes ni cambia asignaciones.

**TODOS LOS USUARIOS:**
Editan su perfil, consultan agenda propia, reciben tareas independientes, crean reservas para bandas y solicitan reuniones a otros usuarios activos. Pueden incluir administradores y líderes como invitados. Consultan ocupación de espacios con datos mínimos; no deben recibir información financiera ajena.

**Operaciones de alto impacto:**
Solo administrador anula pagos, procesa reembolsos, cancela ventas con pagos, cambia roles y consulta auditoría global. Las excepciones deben implementarse como permisos explícitos, no como accesos implícitos.

## 6. MI USUARIO

Nombre, correo único, teléfono opcional, avatar, cargo descriptivo, zona horaria y preferencias de notificación. Cambio de contraseña exige contraseña actual; hash con password_hash y verificación con password_verify. No permitir modificar rol, estado ni permisos mediante el formulario de perfil.
Avatar: formatos JPG/PNG/WebP, tamaño máximo propuesto 2 MB, validación real MIME y reprocesamiento; rechazar SVG y archivos ejecutables. Nombre de archivo generado por servidor. El administrador puede restablecer acceso mediante clave temporal de un solo uso con cambio obligatorio. Recuperación por correo es opcional y requiere SMTP configurado.

## 7. CLIENTES

Tipos: NORMAL y BANDA.
Campos: identificador, tipo, nombre comercial o nombre de banda, contacto principal, teléfono, correo opcional, dirección opcional, notas, estado activo, creador y fechas.
Para bandas, registrar nombre de representante y teléfono; integrantes como datos opcionales, sin cuentas de acceso.
Todos los usuarios activos pueden crear clientes y consultar el directorio mínimo necesario. Cambios generales y desactivación corresponden al administrador; el creador puede corregir datos de contacto de sus registros, con auditoría.
Advertir posibles duplicados por nombre/teléfono/correo sin asumir que varios clientes no pueden compartir teléfono. No convertir banda a normal si tiene reservas futuras activas sin resolverlas. Desactivar preserva ventas y proyectos históricos; impide nuevas operaciones.

## 8. PROYECTOS, ETAPAS Y TAREAS

**Proyecto:**
Folio único, nombre, descripción obligatoria, tipo INTERNO/EXTERNO, cliente opcional según tipo, condición cobrable, líder activo, miembros, inicio previsto, fin previsto, inicio real, fin real, prioridad, estado, importe base, moneda, notas y versión de concurrencia.
Estados: BORRADOR, PLANIFICADO, EN_CURSO, PAUSADO, FINALIZADO, CANCELADO.
Finalizar requiere tareas obligatorias terminadas o excepción administrativa documentada. Finalizar el trabajo no equivale a liquidar el pago; un proyecto finalizado puede conservar saldo.

**Etapa:**
Proyecto, nombre, descripción, orden, fechas previstas y estado. Se permite reordenar etapas sin alterar sus identificadores. Sus fechas deben quedar dentro del proyecto.

**Tarea de proyecto:**
Etapa obligatoria, título, descripción, un colaborador responsable, prioridad, inicio/fin previstos, fechas reales, estado, porcentaje de avance, creador y comentarios.
Estados: PENDIENTE, EN_CURSO, BLOQUEADA, COMPLETADA, CANCELADA. Completar fija avance al 100%; reabrir conserva historial. Solo el responsable, líder de ese proyecto o administrador modifica avance. Solo líder/administrador cambia alcance o fechas. Las tareas deben quedar dentro de su etapa.
La asignación requiere colaborador activo y miembro del proyecto. Un líder o administrador puede recibir tareas independientes; no se presume que puede recibir tareas de proyecto cuando el requisito exige colaborador.

**Tareas independientes:**
Solo administrador las crea. Sin proyecto ni etapa; un responsable de cualquier rol, fechas, prioridad, descripción, estado y comentarios. Aparecen en la agenda del asignado. La base de datos debe impedir una tarea con proyecto pero sin etapa o con etapa de otro proyecto.

**Avance:**
Proyecto = tareas completadas / tareas no canceladas x 100, con igual peso inicial. Sin tareas muestra 0% y "sin tareas". Etapas siguen la misma regla. No usar el porcentaje financiero como avance operativo.

**Cargos y gastos:**
Cargos adicionales internos con concepto, cantidad, precio unitario, importe, fecha y autor. Aumentan el precio comercial; no crean automáticamente un pago. Gastos con concepto, importe, fecha, responsable y estado pagado/pendiente; no modificar el precio al cliente. No permitir editar importes ya cobrados por debajo del saldo pagado sin ajuste y reembolso administrativo.

## 9. EXPORTACIÓN DE GANTT Y TICKET DE PROYECTO

Botón exacto "Imprimir proyecto":
Descarga proyecto_FOLIO_gantt.xlsx, con hojas Resumen y Gantt. Resumen contiene nombre, descripción, tipo, cliente cuando corresponda, líder, fechas y avance. Datos financieros solo para usuarios autorizados.
Gantt incluye etapa, tarea, responsable, inicio, fin, duración en días calendario, estado y avance; columnas de fechas con barras mediante formato condicional. Tareas sin fechas aparecen identificadas como "sin programar", sin inventar duración. Etapas resumidas, paneles inmovilizados, filtros, leyenda, ajuste de impresión horizontal y encabezados repetidos. Escala diaria o semanal elegida según rango; para rangos extensos dividir por periodos y respetar límites de Excel.
Debe abrir sin reparación en Excel y LibreOffice. No disfrazar CSV/HTML como .xlsx. Escribir nombres y descripciones como texto literal para evitar fórmulas inyectadas. La modificación del archivo no sincroniza con el sistema; importar cambios queda fuera del alcance.

Botón "Imprimir ticket":
Incluye estudio y datos configurados, folio, fecha, proyecto, descripción, cliente, líder, conceptos base y cargos, total, historial/resumen de pagos, saldo y estado. Para interno no cobrable rotular "valor interno estimado"; no mostrarlo como cobro realizado. Los gastos internos no deben exponerse al cliente. Solo líder responsable y administrador acceden al ticket financiero.
Reimprimir no cambia pagos ni genera otra operación. Mostrar "Comprobante interno; no es factura fiscal".

## 10. PRODUCTOS, EXTRAS Y VENTAS

**Productos:**
Folio/SKU opcional único, nombre, descripción, precio actual, tipo PRODUCTO/SERVICIO, unidad y estado activo. El administrador gestiona el catálogo. En la primera versión no se exige inventario; vender no descuenta existencias. Si se requiere control de stock debe añadirse un módulo de movimientos, no un contador aislado.

**Extras:**
Catálogo con nombre, precio y estado. Relaciones con productos para limitar extras permitidos. Ejemplo: licuado $50, extra chocolate $10. Un concepto libre puede registrarse con permiso del administrador; el colaborador selecciona extras de catálogo.

**Venta:**
Folio, tipo ORDINARIA/RESERVA, cliente opcional para ordinaria y obligatorio banda para reserva, usuario responsable, fecha, estado BORRADOR/EMITIDA/CANCELADA, moneda, observaciones y total.
Puede incluir múltiples productos y extras asociados a una partida concreta. Guardar copia histórica del nombre y precio: cambiar el catálogo no altera tickets existentes.
Cada partida tiene cantidad positiva, precio unitario histórico y extras con cantidad por unidad. Fórmula: importe de partida = cantidad de producto x (precio base + suma de cantidad de extra por unidad x precio de extra). Redondear cada partida a dos decimales; total = suma de partidas. Ejemplo: dos licuados con un chocolate en cada uno = 2 x (50 + 10) = $120. Si solo uno lleva chocolate, usar partidas separadas.
Los totales se calculan en backend a partir del catálogo y permisos; nunca confiar en total/precio enviado por el navegador.

**Venta pagada/no pagada:**
Emitir venta no registra dinero automáticamente. Acción "Marcar pagada" registra un pago por el saldo pendiente mediante una transacción. Se propone admitir abonos también en ventas, con estado PARCIAL, aprovechando el mismo módulo de pagos. Cancelar requiere administrador, motivo y resolución de pagos/reembolso; no borrar registros.

**Ticket de venta:**
Folio, fecha, usuario, cliente cuando exista, productos, cantidades, precios, extras desglosados, importes, total, pagos, medios y saldo. Ticket de reserva añade banda, espacio y horario. Mostrar importes MXN y etiqueta fiscal interna. Reimpresión no duplica venta ni pago.

## 11. PAGOS Y CONTROL FINANCIERO

Módulo único de pagos para ventas y proyectos cobrables. Cada pago corresponde exactamente a uno de esos documentos, nunca a ambos. Reservas se cobran a través de su venta, no por una segunda ruta.
Campos: folio, documento, importe positivo, fecha efectiva, medio, referencia opcional, notas, registrador, estado y clave de idempotencia. No almacenar números de tarjeta.
Estados calculados del documento: NO_PAGADO si neto cobrado = 0; PARCIAL si 0 < cobrado < total; PAGADO si cobrado = total. Rechazar sobrepago en esta versión. Total cero se muestra SIN_CARGO, sin asiento ficticio.
Correcciones: anulación de pago erróneo con motivo y auditoría; reembolso real vinculado al pago original, limitado al importe aún no reembolsado. Conservar historial. Pago neto = pagos válidos menos reembolsos efectivos. Un error de registro y una devolución real no son la misma operación.
Registrar pago bloquea documento, vuelve a calcular saldo y verifica idempotencia dentro de una transacción. Dos solicitudes simultáneas no deben producir sobrepago. Devolver el resultado original si se repite la misma clave con el mismo contenido; rechazar reutilización con contenido distinto.

**Dashboard global, solo administrador:**
- Importe comercial de ventas emitidas, proyectos externos/cobrables y valor interno estimado, este último por separado.
- Cobros reales netos por periodo, clasificados por venta ordinaria, reserva y proyecto.
- Saldo pendiente total y desglose por documento/cliente.
- Gastos efectivamente pagados y gastos pendientes.
- Resultado de caja = cobros reales netos - gastos pagados.
- Evolución mensual y distribución por origen.
- Filtros por fechas, cliente, responsable, tipo y estado; acceso al detalle que respalda cada cifra.

**Reglas contables del dashboard:**
Ventas y proyectos usan fecha de emisión para su importe comercial; cobros usan fecha efectiva; pendientes se calculan a una fecha de corte usando operaciones hasta esa fecha. No confundir "pagado durante octubre" con "venta creada durante octubre".
Cada fuente se suma una vez: una reserva cuenta dentro de ventas y no otra vez como ingreso de reserva adicional. No vincular una venta a un proyecto de modo que duplique el mismo cobro; el alcance inicial mantiene documentos separados.
La cancelación y los reembolsos deben afectar los indicadores con trazabilidad. No presentar el resultado de caja como utilidad contable formal: no incluye depreciación, nómina o costos no capturados.
Impuestos y descuentos no se presuponen. Base inicial con precios finales registrados; si se requieren IVA/descuentos, definir cálculo y campos antes de activarlos, sin inventar tasas.

## 12. ESPACIOS Y RESERVAS PARA BANDAS

**Espacio:** nombre, descripción, estado activo, horario disponible, tarifa por hora y bloqueos por mantenimiento. Configurar al menos un espacio inicial.
**Reserva:** banda, espacio, responsable, inicio y fin con hora, duración, tarifa histórica, total, notas y venta vinculada única. Permitir crear una banda desde el flujo sin convertirla en usuario.
Estados: CONFIRMADA, EN_CURSO, FINALIZADA, CANCELADA. Una reserva confirmada bloquea el horario aun sin pagar. Borradores no bloquean y deben advertir que no garantizan disponibilidad.
Duración y cobro propuestos: bloques configurables de 30 minutos; tarifa horaria x minutos / 60, con redondeo monetario al final. Fin posterior al inicio. Respetar disponibilidad y bloqueos. No admitir reservas retrospectivas salvo administrador con motivo.

**Prevención de doble reserva:**
Intervalos [inicio, fin): una reserva de 10:00 a 11:00 permite otra a las 11:00. Existe choque si inicio_existente < fin_nuevo y fin_existente > inicio_nuevo, para el mismo espacio y estados bloqueantes.
Dentro de una transacción bloquear la fila del espacio con SELECT ... FOR UPDATE, consultar reservas/bloqueos, validar y crear reserva+venta. Todas las rutas de creación, cambio de horario, mantenimiento y cancelación deben tomar el mismo bloqueo. Si se cambia de espacio, bloquear ambos en orden de ID para evitar interbloqueos. Una consulta de disponibilidad aislada no evita concurrencia.

**Reprogramación:**
Volver a validar y bloquear; recalcular tarifa según regla explícita. Si ya hay pagos, no reducir total por debajo de lo cobrado sin resolución administrativa. Cancelar libera espacio y aplica cancelación financiera coordinada; cualquier penalización requiere política configurada, no descuento implícito.

## 13. AGENDA Y REUNIONES

Agenda propia con vistas mensual, semanal y diaria. Mostrar tareas asignadas de proyecto, tareas independientes, reuniones aceptadas y reservas a cargo del usuario. Las fechas de proyecto pueden mostrarse como hitos a sus participantes. Tareas sin hora se muestran como eventos de día completo. Un calendario de espacios adicional muestra ocupación a todos con datos mínimos.
Consulta limitada al rango visible; filtros por tipo; colores con etiquetas accesibles. Arrastrar un evento solo si el usuario tiene permiso y la API valida el cambio. No cambiar una reserva mediante una ruta genérica que omita controles de disponibilidad.

**Reuniones:**
Cualquier usuario activo crea solicitud con título, descripción, inicio/fin, modalidad/lugar e invitados. Invitaciones con PENDIENTE/ACEPTADA/RECHAZADA. El organizador ve la reunión desde su creación; cada invitado solo la incorpora a su agenda al aceptar, y ve antes la solicitud en notificaciones.
Aceptar es individual: que una persona acepte no acepta por los demás. Rechazar no cancela toda la reunión. El organizador puede cancelar; cambios de fecha/hora restablecen invitaciones a PENDIENTE y eliminan temporalmente la versión anterior de la agenda de invitados.
Advertir cruces con otras actividades sin revelar detalles privados de terceros. Para reuniones personales se permite aceptar un conflicto con advertencia; si se usa un espacio del estudio se aplica la misma exclusión transaccional que a reservas, sin crear venta salvo que se facture.
No se envían invitaciones a clientes. No integrar Google Calendar/Outlook en la primera versión.

**Notificaciones dentro de la app:**
Asignación/reasignación, cambios importantes, invitaciones/respuestas, cancelaciones y reservas. Persistir leído/no leído. Consultar al abrir y mediante sondeo moderado mientras la pestaña esté activa. Correo y recordatorios automáticos son opcionales; si se añaden, usar cron del hosting con ejecución idempotente.

## 14. MODELO RELACIONAL PROPUESTO

Convenciones: PK BIGINT UNSIGNED; claves foráneas del mismo tipo; importes DECIMAL(12,2), cantidades DECIMAL(10,3) cuando aplique; fechas DATETIME en UTC, fechas sin hora DATE; VARCHAR para textos limitados y TEXT para descripciones. Guardar created_at, updated_at y created_by donde proceda. Usar versión entera en entidades editables para detectar sobrescrituras.

Tablas principales y campos relevantes:
- `roles(id, code UNIQUE, name)`.
- `users(id, role_id FK, name, email UNIQUE, password_hash VARCHAR(255), phone, avatar_path, timezone, active, must_change_password, version)`.
- `clients(id, type, name, contact_name, phone, email, address, notes, active, created_by FK)`.
- `projects(id, folio UNIQUE, type, client_id FK NULL, billable, leader_id FK, name, description, status, priority, planned_start, planned_end, actual_start, actual_end, base_amount, currency, version)`.
- `project_members(project_id FK, user_id FK, UNIQUE(project_id,user_id))`.
- `project_stages(id, project_id FK, name, description, sort_order, start_date, end_date, status)`.
- `tasks(id, stage_id FK NULL, title, description, assignee_id FK, priority, status, progress, start_date, end_date, actual_start, actual_end, version)`. Una tarea sin stage_id es independiente; el proyecto se deriva de la etapa para impedir inconsistencias.
- `task_comments(id, task_id FK, author_id FK, body, created_at)`.
- `project_charges(id, project_id FK, concept, quantity, unit_price, amount, status, author_id FK)`.
- `products(id, sku UNIQUE NULL, name, description, type, unit, price, active)`.
- `extras(id, name, price, active)`.
- `product_extras(product_id FK, extra_id FK, UNIQUE(product_id,extra_id))`.
- `sales(id, folio UNIQUE, type, client_id FK NULL, responsible_id FK, status, issued_at, currency, total, version)`.
- `sale_items(id, sale_id FK, product_id FK NULL, name_snapshot, quantity, unit_price, line_total)`.
- `sale_item_extras(id, sale_item_id FK, extra_id FK NULL, name_snapshot, quantity_per_unit, unit_price)`.
- `spaces(id, name, description, hourly_rate, active)`.
- `space_hours(id, space_id FK, weekday, local_start_time, local_end_time)`.
- `space_occupancies(id, space_id FK, start_at, end_at, kind, status)`. Única fuente para conflictos de reservas, mantenimiento y reuniones que usen espacio.
- `reservations(id, occupancy_id FK UNIQUE, band_client_id FK, sale_id FK UNIQUE, responsible_id FK, hourly_rate_snapshot, status, notes, version)`.
- `space_blocks(id, occupancy_id FK UNIQUE, reason, created_by FK)`.
- `meetings(id, organizer_id FK, title, description, start_at, end_at, location, occupancy_id FK NULL UNIQUE, status, version)`.
- `meeting_invitations(id, meeting_id FK, invitee_id FK, response, responded_at, UNIQUE(meeting_id,invitee_id))`.
- `payments(id, folio UNIQUE, project_id FK NULL, sale_id FK NULL, amount, effective_at, method, reference, status, registered_by FK, idempotency_key UNIQUE)`.
- `refunds(id, payment_id FK, amount, effective_at, reason, status, registered_by FK, idempotency_key UNIQUE)`.
- `expenses(id, project_id FK NULL, concept, amount, status, paid_at NULL, created_by FK)`.
- `notifications(id, user_id FK, type, entity_type, entity_id, payload, read_at NULL, created_at)`.
- `audit_logs(id, actor_id FK NULL, action, entity_type, entity_id, before_json, after_json, request_id, created_at)`.
- `settings(id, key UNIQUE, value_json)`.
- `idempotency_requests(id, actor_id FK, operation, key, request_hash, response_json, status, UNIQUE(actor_id,operation,key))`.

**Restricciones e índices:**
- `payments`: exactamente un `project_id` o `sale_id` no nulo; importe > 0.
- Fechas: fin > inicio con hora; para tareas/etapas se permite inicio = fin en el mismo día.
- `progress` entre 0 y 100; cantidades > 0; precios/cargos >= 0.
- Índices `tasks(assignee_id,status,end_date)`, `projects(leader_id,status)`, `sales(responsible_id,issued_at)`, `payments(effective_at,status)`, `payments(project_id)`, `payments(sale_id)`, `space_occupancies(space_id,status,start_at,end_at)`, `meeting_invitations(invitee_id,response)`, `notifications(user_id,read_at,created_at)`.
- Verificar CHECK y demás restricciones en la versión real de MariaDB; complementar con validación de servicio.
- ON DELETE RESTRICT en operaciones comerciales; borrado lógico de catálogos/cuentas. No usar cascada para destruir finanzas.
- Folios obtenidos de secuencia/identidad segura, nunca `MAX(id)+1`.
- Los vínculos polimórficos de notificaciones/auditoría son referencias informativas; no sustituir con ellos claves foráneas financieras.

## 15. API REST PROPUESTA

Prefijo `/api/v1`. JSON UTF-8 salvo descarga XLSX e impresión HTML. Autenticación y política de acceso en todos los endpoints privados.

**Sesión y perfil:**
`POST /auth/login`; `POST /auth/logout`; `GET /auth/me`; `GET /auth/csrf`.
`GET/PATCH /me`; `POST /me/password`; `POST /me/avatar`.

**Usuarios:**
`GET/POST /users`; `GET/PATCH /users/{id}`; `POST /users/{id}/deactivate`; `POST /users/{id}/reset-password`. Solo administrador salvo directorio mínimo de usuarios activos para asignaciones/invitaciones.

**Clientes:**
`GET/POST /clients`; `GET/PATCH /clients/{id}`; `POST /clients/{id}/deactivate`.

**Proyectos:**
`GET/POST /projects`; `GET/PATCH /projects/{id}`; `POST /projects/{id}/transitions`.
`GET/POST /projects/{id}/members`; `DELETE /projects/{id}/members/{userId}`.
`GET/POST /projects/{id}/stages`; `PATCH /stages/{id}`; `POST /projects/{id}/stages/reorder`.
`GET/POST /stages/{id}/tasks`; `GET/PATCH /tasks/{id}`; `POST /tasks/{id}/transitions`.
`POST /standalone-tasks`; `GET/POST /tasks/{id}/comments`.
`GET/POST /projects/{id}/charges`; `PATCH /project-charges/{id}` con control de cobros.
`GET /projects/{id}/gantt.xlsx`; `GET /projects/{id}/ticket`.

**Catálogos y ventas:**
`GET/POST /products`; `PATCH /products/{id}`; `GET/POST /extras`; `PATCH /extras/{id}`.
`PUT /products/{id}/extras`.
`GET/POST /sales`; `GET/PATCH /sales/{id}`; `POST /sales/{id}/issue`; `POST /sales/{id}/cancel`; `GET /sales/{id}/ticket`.
PATCH de venta solo modifica borradores; venta emitida exige operación explícita de ajuste administrativo.

**Cobros:**
`GET/POST /sales/{id}/payments`; `GET/POST /projects/{id}/payments`.
`POST /payments/{id}/void`; `POST /payments/{id}/refunds`.
`GET/POST /expenses`; `PATCH /expenses/{id}` con auditoría y política administrativa.

**Reservas y agenda:**
`GET/POST /spaces`; `PATCH /spaces/{id}`; `GET /spaces/{id}/availability`.
`GET/POST /space-blocks`; `POST /space-blocks/{id}/cancel`.
`GET/POST /reservations`; `GET/PATCH /reservations/{id}`; `POST /reservations/{id}/transitions`.
`GET /agenda?from=...&to=...`; `GET/POST /meetings`; `PATCH /meetings/{id}`; `POST /meetings/{id}/cancel`.
`POST /meetings/{id}/response` con ACCEPTED/DECLINED normalizados a los estados internos.
`GET /notifications`; `POST /notifications/{id}/read`.

**Finanzas y administración:**
`GET /finance/summary`; `GET /finance/cashflow`; `GET /finance/receivables`; `GET /finance/transactions`.
`GET /audit-logs`; `GET/PATCH /settings`.

**Contrato común:**
Listas paginadas con límite máximo 100, filtros permitidos y ordenamiento mediante lista blanca. Respuestas: `{data, meta}`; errores: `{error:{code,message,fields},request_id}`. HTTP 200/201, 400, 401, 403, 404, 409 para conflictos y 422 para validación. No devolver trazas internas.
Escrituras con versión esperada o `If-Match`; versión desactualizada -> 409, sin sobrescribir silenciosamente. Importes en JSON como cadenas decimales, por ejemplo `"150.00"`. Fechas/hora ISO 8601 con zona; transformar a UTC al guardar.
Usar `Idempotency-Key` en emisión de ventas, pagos, reservas y reembolsos. La misma transacción guarda efecto y resultado idempotente.
Documentar contratos, permisos, ejemplos, límites y errores en OpenAPI.

## 16. SEGURIDAD Y CONSISTENCIA

- HTTPS obligatorio. Cookies Secure, HttpOnly y SameSite; regenerar sesión al autenticar o cambiar privilegios. Caducidad por inactividad y absoluta configurables.
- Token CSRF en todas las operaciones mutables con sesión, incluido login; logout solo POST. Validar origen y mantener API en el mismo dominio.
- Rate limit de login por cuenta/IP, errores genéricos y registro de intentos.
- Contraseñas mínimo propuesto 12 caracteres; permitir frases largas; nunca guardar ni enviar contraseñas actuales.
- PDO preparado, sin concatenar entradas SQL. Validación de tipos/longitudes en servidor; listas blancas para campos editables para impedir cambio de rol o precio por asignación masiva.
- Codificar HTML según contexto; no insertar entradas como innerHTML. Validar URLs y limitar uploads. CSP y encabezados de seguridad compatibles con recursos locales.
- Verificar acceso al objeto: conocer el ID de otro proyecto/venta no da permiso. Filtrar campos sensibles también en exportaciones, calendario y errores.
- Credenciales fuera de raíz pública; usuario MariaDB con privilegios mínimos; nunca root. Logs sin claves, tokens ni datos completos de pago.
- Auditoría de permisos, importes, pagos, estados, horarios, cancelaciones y exportaciones sensibles. No guardar hashes de contraseña ni secretos en before/after.
- Transacciones para reserva+venta, pagos, reembolsos, modificaciones de precios y cancelaciones relacionadas. Rollback completo ante fallo; reintento controlado de deadlocks.
- Seguridad y respaldo también deben proteger datos personales de clientes y usuarios; política de acceso, retención y eliminación definida por el estudio.

## 17. EXPERIENCIA Y PANTALLAS

Menú: Inicio, Proyectos, Mis tareas, Agenda, Reservas, Clientes, Ventas, Productos/Extras según permiso, Finanzas solo administrador, Usuarios solo administrador, Mi usuario.
Inicio personalizado con tareas próximas/vencidas, invitaciones y reservas a cargo. Proyecto con pestañas Resumen, Etapas/Tareas, Integrantes y Finanzas para autorizados, y botones de impresión.
Ventas con selección de productos, extras por partida, resumen y pago; reservas con banda, espacio y horario antes de emitir la venta.
Estados de carga, vacío, error, reintento y éxito. Deshabilitar doble envío sin depender de ello para evitar duplicados. Confirmar anulaciones con resumen y motivo. Navegación por teclado, etiquetas de formularios, contraste y mensajes de validación junto a los campos. No depender solo del color para estados.

## 18. DESPLIEGUE, RESPALDO Y OPERACIÓN

Antes de desarrollar: obtener versiones reales de PHP/MariaDB, extensiones, límite de memoria/tiempo, HTTPS, document root, sesiones, acceso a cron, cuotas, SSH y método de respaldo. Ajustar dependencias a esa matriz sin abandonar el stack pedido.
Desarrollar y probar con entorno equivalente al hosting. Generar vendor con Composer y lockfile; no ejecutar npm en producción ni exigir Docker en el hosting.
Pasos: respaldo previo, crear base/usuario, configurar secretos, subir código y dependencias, aplicar migraciones versionadas, configurar Apache/HTTPS, crear primer administrador mediante instalación de un solo uso, desactivar instalador, ejecutar verificación funcional y registrar versión.
Permisos mínimos de archivos; escritura solo donde se necesita. Logs rotados, display_errors desactivado y registro privado de errores.
Respaldo diario propuesto de MariaDB y archivos privados, cifrado y con copia fuera del hosting. Retención inicial propuesta 30 días; restauración probada. Objetivos iniciales RPO 24 h y RTO 4 h, sujetos a capacidad del proveedor.
Sin cron, los flujos principales siguen funcionando; no prometer recordatorios programados ni respaldos propios automáticos si el panel no los soporta.

## 19. VALIDACIÓN Y CRITERIOS DE ACEPTACIÓN

**A. Acceso y permisos:**
El administrador crea los tres roles; no existe registro público; cliente no puede iniciar sesión. Colaborador no accede a APIs financieras de proyecto ajeno alterando IDs. Desactivar cuenta invalida acceso. Perfil no permite cambiar rol.

**B. Proyectos:**
Crear externo sin cliente/precio válido falla. Crear interno permite importe cero. Líder crea etapas y tareas, asigna colaboradores miembros; colaborador actualiza su tarea sin cambiar presupuesto. Administrador asigna tarea independiente a cualquier rol y aparece en su agenda.
Cargo adicional de $500 a interno de $1,000 produce valor de $1,500 sin inventar cobro; gasto de $200 no aumenta ese valor. Interno no cobrable no aumenta cuentas por cobrar ni cobros reales.

**C. Impresión:**
Excel abre sin reparación y muestra Gantt conforme a fechas. Exportación respeta permisos financieros. Ticket de proyecto y venta coincide con datos; texto largo no queda cortado y reimprimir no duplica movimientos.

**D. Ventas y pagos:**
Venta de dos licuados de $50 con chocolate de $10 cada uno totaliza $120. Cambiar catálogo después no altera ticket emitido. Venta de $1,000 con abono de $300 muestra PARCIAL y saldo $700; pago restante deja PAGADO. Repetir solicitud con igual clave no registra otro pago. Dos cobros simultáneos no sobrepagan.

**E. Reservas:**
Cliente NORMAL no puede reservar; BANDA sí. Dos reservas simultáneas del mismo espacio/horario producen un éxito y un conflicto. Se permiten espacios distintos y horarios adyacentes. Reprogramar vuelve a validar conflictos. Cancelar libera horario y conserva historial financiero. Crear reserva genera una sola venta, sin doble ingreso.

**F. Reuniones y agenda:**
Invitado pendiente no tiene reunión aceptada en agenda; al aceptar aparece solo para él. Rechazar no acepta por otros. Cambio de horario obliga a responder nuevamente. Usuarios no ven notas privadas de agendas ajenas.

**G. Finanzas:**
Dashboard separa importe comercial, cobros, pendientes, valor interno y gastos. Sus totales cuadran con registros de detalle. Pagos de una venta antigua cuentan en el periodo de cobro correcto. Anulaciones/reembolsos quedan rastreables y no se cuentan dos veces.

**H. Operación:**
Instalación limpia en hosting equivalente, migraciones y restauración de respaldo verificadas. Pruebas de CSRF, SQL injection, XSS, archivos inválidos y autorización por objeto. Verificación manual en escritorio/móvil y navegadores actuales.
Meta inicial propuesta: listas paginadas de 25 registros en menos de 2 segundos con 20 usuarios concurrentes en entorno pactado. Medir y acordar dataset y recursos del hosting antes de considerar esta cifra un compromiso. Exportación debe tener límites y mensaje claro cuando requiera reducir rango.

## 20. ORDEN DE IMPLEMENTACIÓN Y ENTREGABLES

**Fase 1:** inventario de hosting, esquema, autenticación, roles, perfiles y clientes.
**Fase 2:** proyectos, etapas, tareas, membresías y tareas independientes.
**Fase 3:** productos, extras, ventas, cobros y tickets.
**Fase 4:** espacios, reservas transaccionales y agenda.
**Fase 5:** reuniones, invitaciones y notificaciones.
**Fase 6:** dashboard, gastos, Gantt XLSX, auditoría, pruebas y despliegue.

Entregar código completo; composer.json/lock y dependencias de despliegue; SQL/migraciones/seeds; configuración de ejemplo sin secretos; contratos OpenAPI; pruebas críticas; manual de instalación/restauración; manual de roles y uso; datos de demostración claramente separados; reporte de validación y limitaciones conocidas.

No incluir inicialmente: portal de clientes, pagos bancarios automáticos, CFDI, nómina, contabilidad fiscal, inventario, app móvil nativa, múltiples empresas, recurrencias complejas de reservas/reuniones, sincronización externa de calendario ni importación del Gantt. Cualquiera puede añadirse mediante alcance explícito.

## 21. PUNTOS A CONFIRMAR ANTES DE CERRAR DESARROLLO

1. Confirmar tres roles o definir un cuarto rol USUARIO.
2. Confirmar que extras internos son cargos por cobrar y quién los paga; si son gastos, no deben sumarse a ingresos.
3. Confirmar permisos propuestos de cobro para colaboradores/líderes y visibilidad comercial.
4. Definir número de espacios, tarifas, horarios, bloques y política de cancelación.
5. Confirmar si ventas ordinarias admiten abonos y si se necesita inventario, IVA o descuentos.
6. Confirmar datos impresos, papel 80 mm/A4 y necesidad de PDF descargable directo.
7. Obtener capacidades reales del hosting y disponibilidad de respaldos/cron.

Estos puntos tienen valores propuestos para avanzar; no representan decisiones ya tomadas por el usuario.

## REFERENCIAS TÉCNICAS OFICIALES

- https://www.php.net/supported-versions.php
- https://www.php.net/manual/en/book.pdo.php
- https://www.php.net/manual/en/function.password-hash.php
- https://phpspreadsheet.readthedocs.io/en/latest/
- https://mariadb.com/docs/server/
- https://httpd.apache.org/docs/2.4/
