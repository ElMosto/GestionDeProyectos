/**
 * Catálogos de presentación. Nada de reglas de negocio: solo etiquetas en español y
 * listas cerradas que el backend vuelve a validar (§5, §6, §7).
 */

export const PAGE_SIZE = 25;

/** Roles del sistema. El código es el que viaja en la API. */
export const ROLES = [
    { code: 'ADMINISTRADOR', label: 'Administrador' },
    { code: 'LIDER_PROYECTO', label: 'Líder de proyecto' },
    { code: 'COLABORADOR', label: 'Colaborador' },
];

/**
 * Zonas IANA razonables para un estudio musical de habla hispana.
 * El servidor acepta cualquier identificador IANA; esta lista es solo la sugerencia.
 */
export const TIMEZONES = [
    'America/Mexico_City',
    'America/Cancun',
    'America/Monterrey',
    'America/Tijuana',
    'America/Bogota',
    'America/Lima',
    'America/Santiago',
    'America/Argentina/Buenos_Aires',
    'America/Sao_Paulo',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'Europe/Madrid',
    'Europe/London',
    'Europe/Berlin',
    'UTC',
];

export const DEFAULT_TIMEZONE = 'America/Mexico_City';

export const CLIENT_TYPES = [
    { code: 'NORMAL', label: 'Normal' },
    { code: 'BANDA', label: 'Banda' },
];

/** Clave del checkbox de preferencias de notificación que guarda el perfil. */
export const NOTIFICATION_PREF_EMAIL = 'email_notifications';

const ROLE_LABELS = new Map(ROLES.map((role) => [role.code, role.label]));

/**
 * @param {string|null|undefined} code
 * @returns {string}
 */
export function roleLabel(code) {
    if (typeof code !== 'string' || code === '') {
        return 'Sin rol';
    }

    return ROLE_LABELS.get(code) ?? code;
}

/**
 * @param {string|null|undefined} type
 * @returns {string}
 */
export function clientTypeLabel(type) {
    return type === 'BANDA' ? 'Banda' : 'Normal';
}

/**
 * Motivos por los que el servidor advierte un posible duplicado (§7).
 *
 * @param {string[]} matchedBy
 * @returns {string}
 */
export function matchedByLabel(matchedBy) {
    const labels = {
        name: 'nombre',
        phone: 'teléfono',
        email: 'correo',
    };

    if (!Array.isArray(matchedBy) || matchedBy.length === 0) {
        return 'datos coincidentes';
    }

    return matchedBy.map((key) => labels[key] ?? key).join(', ');
}

/**
 * Lista de zonas para el `<select>`: incluye el valor actual aunque no esté en el catálogo.
 *
 * @param {string|null|undefined} current
 * @returns {{value: string, label: string}[]}
 */
export function timezoneOptions(current) {
    const values = TIMEZONES.slice();

    if (typeof current === 'string' && current !== '' && !values.includes(current)) {
        values.unshift(current);
    }

    return values.map((value) => ({ value, label: value }));
}
