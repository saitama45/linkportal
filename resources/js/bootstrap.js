import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// This app's CSRF cookie is named per-app rather than the shared default
// "XSRF-TOKEN", so that another Laravel app on the same host cannot overwrite it
// (cookies are scoped by host, not port). Keep axios reading the same one.
// Inertia uses this same default axios instance, so setting it here covers it.
const csrfCookie = document.querySelector('meta[name="csrf-cookie"]')?.content;
if (csrfCookie) {
    window.axios.defaults.xsrfCookieName = csrfCookie;
}
