---
name: Salidas impresas y Excel
description: Generación de .xlsx de Gantt con PhpSpreadsheet y tickets HTML de 80 mm y A4 con CSS de impresión. Cárgala al crear exportaciones, plantillas de impresión o cualquier archivo descargable.
---

# Salidas impresas y Excel

## Principio

Una salida es una **vista autorizada** de los datos, no una copia completa. Si el usuario no puede
ver un importe por API, ese importe no existe en el archivo: ni en una celda, ni en una hoja
oculta, ni en metadatos del documento.

## Excel: `proyecto_FOLIO_gantt.xlsx`

Dos hojas obligatorias: **Resumen** y **Gantt**.

### Resumen

Nombre, descripción, tipo, cliente (si aplica), líder, fechas previstas/reales y avance. Los datos
financieros solo aparecen si el solicitante tiene permiso (ADMIN o LIDER del proyecto).

### Gantt

Columnas mínimas: etapa, tarea, responsable, inicio, fin, duración en **días calendario**, estado,
avance. Después, una columna por día o por semana con la barra.

- Barras con **formato condicional** (`conditionalFormatting`), no rellenando celdas una por una: el
  archivo debe seguir siendo editable.
- Tareas sin fechas: texto "sin programar", sin duración y sin barra. No inventar fechas.
- Duración: `fin - inicio` en días calendario; si `inicio = fin`, es 1 día.
- Paneles inmovilizados, autofiltro, leyenda y encabezados repetidos al imprimir.
- Impresión horizontal, `fitToWidth`, escalado razonable.

### Límites y escala

- Columnas de Excel: máximo 16 384. Con escala diaria, un proyecto de más de ~3 años se acerca al
  límite. Regla: si el rango supera ~400 columnas de calendario, se cambia a escala semanal; si
  aún así no cabe, se divide por periodos (una hoja o un archivo por trimestre).
- Si hay que reducir, se informa al usuario (`meta: { truncated: true, range }`). Nunca truncar en
  silencio.
- Tiempo de ejecución acotado; exportaciones grandes no deben bloquear la petición web más allá de
  `max_execution_time`.

### Anti-inyección de fórmulas

Todo texto proveniente de usuarios se escribe como literal:

```php
$sheet->setCellValueExplicit($coord, $value, DataType::TYPE_STRING);
```

Un nombre de proyecto que empiece por `=`, `+`, `-` o `@` no debe ejecutarse al abrir el archivo.
Aplica a nombres, descripciones, conceptos y notas.

### Verificación obligatoria

1. Abrir en Excel: sin diálogo de reparación.
2. Abrir en LibreOffice: sin reparación ni celdas vacías inesperadas.
3. Comprobar que las barras coinciden con las fechas.
4. Comprobar que un proyecto sin permisos financieros no contiene importes.

## Tickets HTML

Formato: HTML servido por el backend, con CSS de impresión. Sin librería PDF; el usuario usa
"Imprimir → Guardar como PDF" si lo necesita.

### 80 mm (térmica)

```css
@page { size: 80mm auto; margin: 3mm; }
@media print {
  html, body { width: 80mm; margin: 0; }
  .no-print { display: none !important; }
  .ticket { font-size: 10pt; line-height: 1.25; }
}
```

- Una sola columna; sin tablas de ancho fijo que desborden.
- Importes alineados a la derecha con `display: flex; justify-content: space-between`.
- Texto largo: `overflow-wrap: anywhere`. El ticket crece en vertical, no se recorta.
- Encabezado con datos del estudio y folio; pie con "Comprobante interno; no es factura fiscal".

### A4

```css
@page { size: A4; margin: 15mm; }
```

- Se permite tabla a dos columnas y salto de página entre secciones.
- Misma información, distinta densidad. Un solo juego de datos.

### Contenido

**Proyecto:** folio, fecha, proyecto, descripción, cliente, líder, conceptos base y cargos, total,
historial/resumen de pagos, saldo y estado. Interno no cobrable → "valor interno estimado". Los
gastos internos **no** se imprimen.

**Venta:** folio, fecha, usuario, cliente, partidas con extras desglosados, cantidades, precios,
importes, total, pagos con medio y saldo. Reserva: además banda, espacio y horario.

### Reglas

- Reimprimir es una lectura: no crea pagos, ventas ni movimientos.
- El botón dice exactamente **"Imprimir proyecto"** y **"Imprimir ticket"**.
- Importes con formato MXN y dos decimales, sin recalcular en la plantilla.
- Los datos se escapan con `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
- La plantilla no consulta la base de datos: recibe un array ya autorizado y filtrado.
