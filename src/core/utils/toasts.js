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