<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="NovaFact. Documentación e integración de la API de facturación electrónica para Perú.">
    <title>@yield('title', 'NovaFact · API fiscal')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="nova-public">
<a class="skip-link" href="#contenido">Ir al contenido</a>
<header class="site-header"><div class="site-header-inner">
    <a class="brand" href="/" aria-label="NovaFact, inicio"><img src="{{ asset('logo-nova.svg') }}" alt="" width="42" height="28"><span>Nova<span class="brand-accent">Fact</span></span></a>
    <nav aria-label="Navegación principal"><a href="/" @if(request()->is('/')) aria-current="page" @endif>Inicio</a><a href="/docs" @if(request()->is('docs')) aria-current="page" @endif>Documentación</a><a class="header-download" href="/docs/postman">Colección Postman <span aria-hidden="true">↓</span></a></nav>
</div></header>
@yield('content')
<footer class="site-footer"><span>NovaFact · API fiscal para Perú</span><a href="/docs#operacion">Integración y operación</a><span>Validación local y beta</span></footer>
</body>
</html>
