// Aplica el tema guardado ANTES de pintar la página, para evitar el parpadeo
// del tema por defecto seguido de un cambio brusco al tema preferido.
// Vive en un archivo aparte (no inline) para poder tener una CSP estricta
// en script-src sin necesitar 'unsafe-inline'.
(function () {
  var saved = localStorage.getItem('arca-theme');
  if (saved === 'light' || saved === 'dark') {
    document.documentElement.setAttribute('data-theme', saved);
  }
})();
