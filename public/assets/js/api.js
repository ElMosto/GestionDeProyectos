/**
 * Cliente HTTP único de `/api/v1` (§15, `docs/api-movil.md` §3-§5).
 *
 * Reglas que concentra este módulo:
 * - `credentials: 'same-origin'` y `Accept: application/json` en todas las peticiones.
 * - Token CSRF obtenido de `GET /auth/csrf` y enviado en `X-CSRF-Token` en cada método
 *   mutante. Ante `403 CSRF_INVALID` se refresca el token y se reintenta **una sola vez**.
 * - `If-Match: "version"` cuando la vista pasa `version`.
 * - `Idempotency-Key` (crypto.randomUUID) cuando la escritura se marca `idempotent`.
 *   La clave se reutiliza si se reintenta exactamente el mismo envío (misma ruta y cuerpo)
 *   dentro de la ventana de reintento; se descarta al recibir una respuesta correcta.
 * - Envelope `{data, meta}` y errores `{error:{code,message,fields},request_id}`.
 * - Los importes viajan como string y se muestran tal cual: aquí no se calcula dinero.
 *
 * Además expone los helpers de construcción de DOM (`el`, `text`, `append`, `safeUrl`)
 * para que ninguna vista use `innerHTML`.
 */

const API_BASE = '/api/v1';
const IDEMPOTENCY_TTL_MS = 10 * 60 * 1000;
const URL_ATTRIBUTES = new Set(['href', 'src', 'srcset', 'action', 'formaction']);
const BOOLEAN_ATTRIBUTES = new Set([
    'checked',
    'disabled',
    'hidden',
    'multiple',
    'novalidate',
    'open',
    'readonly',
    'required',
    'selected',
    'autofocus',
]);
const SAFE_DATA_IMAGE = /^data:image\/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$/;

/** Error de API con el contrato estable del envelope. */
export class ApiError extends Error {
    /**
     * @param {number} status
     * @param {{error?: {code?: string, message?: string, fields?: Record<string,string>}, request_id?: string}|null} payload
     * @param {string|null} requestId
     */
    constructor(status, payload, requestId = null) {
        const contract = payload && typeof payload === 'object' ? payload.error : null;
        const message = contract && typeof contract.message === 'string' && contract.message !== ''
            ? contract.message
            : 'No se pudo completar la operación.';

        super(message);

        this.name = 'ApiError';
        this.status = Number(status) || 0;
        this.code = contract && typeof contract.code === 'string' ? contract.code : 'UNKNOWN_ERROR';
        this.fields = contract && contract.fields && typeof contract.fields === 'object' ? contract.fields : {};
        this.requestId = (payload && typeof payload.request_id === 'string' ? payload.request_id : null) || requestId || null;
    }
}

/* ------------------------------------------------------------------------- *
 * DOM seguro
 * ------------------------------------------------------------------------- */

/**
 * Crea un nodo. `attrs` admite clases, texto, dataset, booleanos, manejadores `on*`
 * y atributos ARIA. Nunca interpreta HTML: los hijos se convierten en nodos de texto.
 *
 * @param {string} tag
 * @param {Record<string, unknown>} [attrs]
 * @param {unknown} [children]
 * @returns {HTMLElement}
 */
export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (key === 'class' || key === 'className') {
            node.className = String(value);
            continue;
        }

        if (key === 'text') {
            node.textContent = String(value);
            continue;
        }

        if (key === 'dataset') {
            for (const [dataKey, dataValue] of Object.entries(value || {})) {
                if (dataValue !== null && dataValue !== undefined) {
                    node.dataset[dataKey] = String(dataValue);
                }
            }
            continue;
        }

        if (key === 'style') {
            // §17: nada de estilos inline; el diseño vive en app.css.
            continue;
        }

        if (key.startsWith('on') && typeof value === 'function') {
            node.addEventListener(key.slice(2).toLowerCase(), value);
            continue;
        }

        if (URL_ATTRIBUTES.has(key)) {
            const safe = safeUrl(value);

            if (safe !== null) {
                node.setAttribute(key, safe);
            }
            continue;
        }

        if (BOOLEAN_ATTRIBUTES.has(key)) {
            if (value === true || value === 'true' || value === key) {
                node.setAttribute(key, '');
            }
            continue;
        }

        node.setAttribute(key, String(value));
    }

    append(node, children);

    if (attrs && attrs.value !== undefined && attrs.value !== null) {
        if (tag === 'select' || tag === 'textarea') {
            node.value = String(attrs.value);
        }
    }

    return node;
}

/**
 * Nodo de texto explícito.
 *
 * @param {unknown} value
 * @returns {Text}
 */
export function text(value) {
    return document.createTextNode(value === null || value === undefined ? '' : String(value));
}

/**
 * Añade hijos (nodos, cadenas, números o listas anidadas) sin interpretar HTML.
 *
 * @param {Node} parent
 * @param {unknown} children
 * @returns {Node}
 */
export function append(parent, children) {
    const list = Array.isArray(children) ? children : [children];

    for (const child of list) {
        if (child === null || child === undefined || child === false || child === '') {
            continue;
        }

        if (Array.isArray(child)) {
            append(parent, child);
            continue;
        }

        parent.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }

    return parent;
}

/**
 * Valida una URL antes de asignarla a `href`/`src`: mismo origen, fragmento o
 * data URL de imagen (vista previa del avatar). Cualquier otro esquema se rechaza.
 *
 * @param {unknown} value
 * @returns {string|null}
 */
export function safeUrl(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const raw = value.trim();

    if (raw === '' || raw.includes('\\') || /[\u0000-\u001F\u007F]/.test(raw)) {
        return null;
    }

    if (raw.startsWith('//')) {
        return null;
    }

    if (raw.startsWith('#') || raw.startsWith('/')) {
        return raw;
    }

    if (SAFE_DATA_IMAGE.test(raw)) {
        return raw;
    }

    try {
        const url = new URL(raw, window.location.origin);

        if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin === window.location.origin) {
            return url.href;
        }
    } catch {
        return null;
    }

    return null;
}

/* ------------------------------------------------------------------------- *
 * Cliente HTTP
 * ------------------------------------------------------------------------- */

/** @type {string|null} */
let csrfToken = null;
/** @type {Promise<string>|null} */
let csrfPending = null;
/** @type {Map<string, {key: string, createdAt: number}>} */
const idempotencyKeys = new Map();
/** @type {((error: ApiError) => void)|null} */
let unauthorizedHandler = null;

/**
 * Registra el manejador global de sesión caducada. Se invoca con 401 de cualquier
 * ruta salvo `/auth/login` (donde 401 significa credenciales incorrectas).
 *
 * @param {((error: ApiError) => void)|null} handler
 */
export function setUnauthorizedHandler(handler) {
    unauthorizedHandler = typeof handler === 'function' ? handler : null;
}

/** Descarta el token CSRF y las claves de idempotencia (logout o sesión nueva). */
export function clearClientState() {
    csrfToken = null;
    csrfPending = null;
    idempotencyKeys.clear();
}

/**
 * @param {Response} response
 * @returns {Promise<Record<string, unknown>|null>}
 */
async function readJson(response) {
    const raw = await response.text();

    if (raw === '') {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

/**
 * Pide el token CSRF. Una sola petición compartida por todos los llamadores.
 *
 * @returns {Promise<string>}
 */
function requestCsrf() {
    if (!csrfPending) {
        const pending = (async () => {
            const response = await fetch(`${API_BASE}/auth/csrf`, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const payload = await readJson(response);

            if (!response.ok) {
                throw new ApiError(response.status, payload, response.headers.get('x-request-id'));
            }

            const token = payload && payload.data ? payload.data.token : null;

            if (typeof token !== 'string' || token === '') {
                throw new ApiError(response.status, {
                    error: {
                        code: 'CSRF_UNAVAILABLE',
                        message: 'No se pudo preparar la protección de la sesión. Recarga la página.',
                    },
                });
            }

            csrfToken = token;

            return token;
        })();

        csrfPending = pending;
        pending.then(
            () => { if (csrfPending === pending) { csrfPending = null; } },
            () => { if (csrfPending === pending) { csrfPending = null; } },
        );
    }

    return csrfPending;
}

/**
 * @param {boolean} force
 * @returns {Promise<string>}
 */
async function ensureCsrf(force = false) {
    if (force) {
        csrfToken = null;
    }

    if (typeof csrfToken === 'string' && csrfToken !== '') {
        return csrfToken;
    }

    return requestCsrf();
}

/**
 * Serialización estable para comparar envíos (clave de idempotencia).
 *
 * @param {unknown} value
 * @returns {string}
 */
function stableStringify(value) {
    if (value === null || typeof value !== 'object') {
        return JSON.stringify(value);
    }

    if (Array.isArray(value)) {
        return `[${value.map(stableStringify).join(',')}]`;
    }

    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`;
}

/**
 * @returns {string}
 */
function createUuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * Clave de idempotencia por envío: se reutiliza mientras no haya éxito.
 *
 * @param {string} fingerprint
 * @returns {string}
 */
function idempotencyKeyFor(fingerprint) {
    const now = Date.now();
    const existing = idempotencyKeys.get(fingerprint);

    if (existing && now - existing.createdAt < IDEMPOTENCY_TTL_MS) {
        return existing.key;
    }

    const key = createUuid();
    idempotencyKeys.set(fingerprint, { key, createdAt: now });

    return key;
}

/**
 * Llama a la API y devuelve el envelope completo (`{data, meta}`).
 *
 * @param {string} path ruta relativa a `/api/v1`, por ejemplo `/clients?limit=25`
 * @param {{
 *   method?: string,
 *   body?: unknown,
 *   form?: FormData,
 *   version?: string|number|null,
 *   idempotent?: boolean,
 *   notifyUnauthorized?: boolean,
 * }} [options]
 * @returns {Promise<{data: any, meta: any}>}
 * @throws {ApiError}
 */
export async function api(path, options = {}) {
    const {
        method = 'GET',
        body,
        form = null,
        version = null,
        idempotent = false,
        notifyUnauthorized = true,
    } = options;

    const httpMethod = String(method).toUpperCase();
    const mutating = httpMethod !== 'GET' && httpMethod !== 'HEAD';
    const fingerprint = idempotent ? `${httpMethod} ${path} ${stableStringify(body ?? null)}` : null;
    const idempotencyKey = fingerprint ? idempotencyKeyFor(fingerprint) : null;

    /**
     * @param {boolean} refreshCsrf
     * @returns {Promise<{data: any, meta: any}>}
     */
    const send = async (refreshCsrf) => {
        /** @type {Record<string,string>} */
        const headers = { Accept: 'application/json' };

        if (mutating) {
            headers['X-CSRF-Token'] = await ensureCsrf(refreshCsrf);
        }

        if (form === null && body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        if (version !== null && version !== undefined && version !== '') {
            headers['If-Match'] = `"${version}"`;
        }

        if (idempotencyKey !== null) {
            headers['Idempotency-Key'] = idempotencyKey;
        }

        const response = await fetch(`${API_BASE}${path}`, {
            method: httpMethod,
            credentials: 'same-origin',
            cache: 'no-store',
            headers,
            body: form ?? (body === undefined ? undefined : JSON.stringify(body)),
        });

        const headerRequestId = response.headers.get('x-request-id');

        if (response.status === 204) {
            if (fingerprint) {
                idempotencyKeys.delete(fingerprint);
            }

            return { data: null, meta: {} };
        }

        const payload = await readJson(response);

        if (!response.ok) {
            const error = new ApiError(response.status, payload, headerRequestId);

            if (mutating && error.status === 403 && error.code === 'CSRF_INVALID' && !refreshCsrf) {
                csrfToken = null;

                return send(true);
            }

            if (error.status === 401 && notifyUnauthorized && path !== '/auth/login' && unauthorizedHandler) {
                unauthorizedHandler(error);
            }

            throw error;
        }

        if (fingerprint) {
            idempotencyKeys.delete(fingerprint);
        }

        if (payload === null || typeof payload !== 'object') {
            return { data: null, meta: {} };
        }

        return {
            data: Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : null,
            meta: payload.meta && typeof payload.meta === 'object' ? payload.meta : {},
        };
    };

    return send(false);
}

/** Descarga XLSX dentro del cliente único; los errores JSON no se guardan como archivos. */
export async function downloadFile(path) {
    if (!/^\/projects\/[1-9]\d*\/gantt\.xlsx(?:\?[^#]*)?$/.test(path)) {
        throw new Error('Ruta de exportación no válida.');
    }
    const mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    const response = await fetch(`${API_BASE}${path}`, {
        method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: mime },
    });
    if (!response.ok) {
        const error = new ApiError(response.status, await readJson(response), response.headers.get('x-request-id'));
        if (error.status === 401 && unauthorizedHandler) unauthorizedHandler(error);
        throw error;
    }
    if (response.headers.get('content-type')?.split(';')[0] !== mime) {
        throw new ApiError(response.status, { error: { code: 'INVALID_EXPORT', message: 'El servidor no devolvió un archivo XLSX. No se descargó contenido inesperado.' } });
    }
    const match = response.headers.get('content-disposition')?.match(/filename="([A-Za-z0-9_.-]+\.xlsx)"/);
    const blob = await response.blob();
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl; // URL generada localmente, nunca proviene del usuario/servidor.
    link.download = match?.[1] ?? 'proyecto_gantt.xlsx';
    link.hidden = true;
    document.body.append(link);
    try { link.click(); } finally {
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    }
}
