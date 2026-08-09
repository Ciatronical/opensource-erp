import Swal from 'sweetalert2';

// config
const duration = 5000;
const progressBarEnabled = true;
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: duration,
    timerProgressBar: progressBarEnabled,
    customClass: { container: 'swal2-toast-above-overlay' }
});

export const info = (text) => Toast.fire({ icon: 'info', title: text });
export const success = (text) => Toast.fire({ icon: 'success', title: text });
export const warning = (text) => Toast.fire({ icon: 'warning', title: text });
export const error = (text) => Toast.fire({ icon: 'error', title: text });

/**
 * Einblendung mit Inhalt und Klickziel — der Text steht direkt drin (z.B. eine
 * eingehende Chatnachricht), ein Klick fuehrt an die passende Stelle.
 * Solange die Maus darauf steht, laeuft der Timer nicht weiter: laengere Texte
 * waeren sonst weg, bevor man sie zu Ende gelesen hat.
 */
export const clickable = (title, text, onClick, opts = {}) => Toast.fire({
    icon: 'info',
    title,
    text,
    // customClass ersetzt die des Mixins komplett — die Container-Klasse muss mit
    customClass: { container: 'swal2-toast-above-overlay', popup: 'swal2-toast-clickable' },
    ...opts,
    didOpen: (el) => {
        el.style.cursor = 'pointer';
        el.addEventListener('mouseenter', Swal.stopTimer);
        el.addEventListener('mouseleave', Swal.resumeTimer);
        el.addEventListener('click', () => { Swal.close(); onClick(); });
    }
});