---
name: Frontend vanilla con módulos ES
description: Cliente HTTP, estructura de vistas, estados de UI, accesibilidad y reglas de seguridad para el frontend con HTML, CSS y JavaScript sin framework ni build.
---

# Frontend vanilla con módulos ES

## Sin build

- `public/assets/js/` con módulos ES nativos (`<script type="module" src="/assets/js/app.js">`).
- Sin bundler, sin `npm`, sin transpilación. Si una función no está en los navegadores objetivo, se
  escribe de otra forma, no se compila.
- Dependencias de terceros: copiadas localmente a `public/assets/vendor/`, versión fija y licencia
  revisada y anotada en `docs/decisiones.md`.

## Cliente HTTP único

Un solo módulo (`assets/js/api.js`) concentra el contrato. Nada de `fetch` suelto en las vistas.

```js
export async function api(path, { method = 'GET', body, idempotent = false, version } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET') headers['X-CSRF-Token'] = getCsrfToken();
  if (version !== undefined) headers['If-Match'] = `"${version}"`;
  if (idempotent) headers['Idempotency-Key'] = currentKey(path);

  const res = await fetch(`/api/v1${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const payload = await res.json().catch(() => null);
  if (!res.ok) throw new ApiError(res.status, payload);
  return payload;
}
```

- `currentKey(path)` **reutiliza** la clave si el usuario reintenta el mismo envío, y genera una
  nueva cuando cambian los datos. Sin esto, el reintento duplica pagos.
- `ApiError` expone `code`, `message`, `fields` y `requestId`.
- Los importes se tratan como **string**; nunca `parseFloat` para sumar.

## Estados de pantalla

Cada vista implementa explícitamente: `loading`, `empty`, `error` (con botón de reintento), `success`.
Sin pantallas en blanco ni spinners infinitos.

- Error de validación → mensaje junto al campo (`error.fields`).
- Error 403 → mensaje claro sin revelar datos del objeto.
- Error 409 → explicar el conflicto (versión desactualizada, horario ocupado, sobrepago) y ofrecer
  recargar.
- Doble envío: botón deshabilitado **y** `Idempotency-Key`; la protección real es del servidor.

## Seguridad de cliente

- `textContent` siempre; `innerHTML` prohibido con datos del servidor. Para listas, `<template>` y
  clonado de nodos.
- Nada de credenciales en `localStorage`/`sessionStorage`. La sesión es una cookie `HttpOnly` que el
  JS no lee.
- Enlaces externos: `rel="noopener noreferrer"`; validar `href` antes de asignarlo.
- Sin `eval`, `new Function`, `document.write`.
- CSP con recursos locales; sin scripts inline.

## Accesibilidad

- Un `<form>` con `<label for>` por campo; errores con `aria-describedby` y `aria-invalid`.
- Foco visible, orden de tabulación lógico, `Esc` cierra modales, foco atrapado en diálogos.
- Estados con texto o icono, nunca solo color (los estados del sistema tienen etiqueta).
- Contraste AA (4.5:1); objetivos táctiles ≥ 44 px; `prefers-reduced-motion`.
- Tablas con `<th scope>`; listas paginadas anuncian el rango con `aria-live="polite"`.

## Responsive

- Móvil primero, breakpoints en 640 / 1024 / 1280.
- Formularios de una columna en móvil; tablas con scroll horizontal contenido o tarjetas.
- La impresión se resuelve en `print.css`, no con una vista distinta.

## Estructura sugerida

```
public/assets/
├── css/  base.css, components.css, print.css
├── js/   app.js, api.js, router.js, ui.js, views/*.js
└── vendor/  (FullCalendar/Chart.js si se aprueban)
```

## Reglas de negocio

Ninguna. El frontend presenta, valida forma y navega. Permisos, importes, estados y disponibilidad
los decide el backend. Si una regla solo existe aquí, es un bug de arquitectura.
